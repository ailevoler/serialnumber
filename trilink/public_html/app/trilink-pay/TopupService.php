<?php
// ============================================================
// TRILINK PAY – TopupService.php
// One place for every wallet top-up (passenger + driver):
//   • qrph   – live QR Ph through PayMongo. Wallet is credited ONLY
//              after PayMongo confirms the payment (webhook or poll).
//   • teller – cash at a TriLink Load Center / teller. Stays pending
//              until the teller scans the code and confirms payment.
// Nothing is ever auto-credited on request.
// ============================================================
require_once __DIR__ . '/config.php';

class TopupException extends Exception {}

class Topup {
    const PASSENGER = 'passenger';
    const DRIVER    = 'driver';
    const QRPH      = 'qrph';
    const TELLER    = 'teller';

    private static $pdo = null;

    // ── DB (own connection so every app can share this file) ────
    public static function db(): PDO {
        if (self::$pdo === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }
    private static function run(string $sql, array $p = []): PDOStatement {
        $s = self::db()->prepare($sql);
        $s->execute($p);
        return $s;
    }
    private static function row(string $sql, array $p = []): ?array {
        $r = self::run($sql, $p)->fetch();
        return $r ?: null;
    }
    private static function val(string $sql, array $p = []) {
        return self::run($sql, $p)->fetchColumn();
    }

    public static function setting(string $key, $default = null) {
        $v = self::val("SELECT `value` FROM tl_settings WHERE `key`=?", [$key]);
        return $v !== false ? $v : $default;
    }

    public static function limits(): array {
        return [
            'min' => max(20.0, (float)self::setting('wallet_topup_min', 50)),
            'max' => (float)self::setting('wallet_topup_max', 50000),
        ];
    }

    public static function qrphEnabled(): bool {
        return strpos(PAYMONGO_SECRET_KEY, 'sk_') === 0;
    }

    // ── Create ──────────────────────────────────────────────────
    /** Creates a pending top-up. Throws TopupException with a user-facing message. */
    public static function create(string $acct, int $accountId, $amount, string $channel): array {
        if (!in_array($acct, [self::PASSENGER, self::DRIVER], true)) throw new TopupException('Invalid account.');
        if (!in_array($channel, [self::QRPH, self::TELLER], true)) throw new TopupException('Pumili ng paraan ng pagbabayad.');

        $amount = round((float)$amount, 2);
        $lim = self::limits();
        if ($amount < $lim['min']) throw new TopupException('Minimum top-up is ₱' . number_format($lim['min'], 2) . '.');
        if ($amount > $lim['max']) throw new TopupException('Maximum top-up is ₱' . number_format($lim['max'], 2) . '.');

        $account = self::account($acct, $accountId);
        if (!$account) throw new TopupException('Account not found.');
        if (in_array($account['status'], ['banned', 'suspended'], true)) throw new TopupException('Hindi pwedeng mag-top up ang account na ito.');

        if ($channel === self::QRPH && !self::qrphEnabled()) {
            throw new TopupException('Hindi pa available ang QR Ph ngayon. Gamitin muna ang Teller.');
        }

        $maxPending = max(1, (int)self::setting('topup_max_pending', 3));
        $open = (int)self::val(
            "SELECT COUNT(*) FROM tl_topups
             WHERE account_type=? AND account_id=? AND status='pending'
               AND (expires_at IS NULL OR expires_at > NOW())",
            [$acct, $accountId]
        );
        if ($open >= $maxPending) {
            throw new TopupException("May {$open} ka pang pending na top-up. Tapusin o i-cancel muna bago gumawa ng bago.");
        }

        $code = self::newCode();
        if ($channel === self::TELLER) {
            $hours = max(1, (int)self::setting('topup_teller_expiry_hours', 24));
            self::run(
                "INSERT INTO tl_topups (topup_code, account_type, account_id, amount, channel, status, expires_at)
                 VALUES (?,?,?,?, 'teller', 'pending', NOW() + INTERVAL {$hours} HOUR)",
                [$code, $acct, $accountId, $amount]
            );
            return self::findByCode($code);
        }

        // QR Ph via PayMongo
        $mins = TOPUP_QRPH_EXPIRY_MINUTES;
        self::run(
            "INSERT INTO tl_topups (topup_code, account_type, account_id, amount, channel, status, provider, expires_at)
             VALUES (?,?,?,?, 'qrph', 'pending', 'paymongo', NOW() + INTERVAL {$mins} MINUTE)",
            [$code, $acct, $accountId, $amount]
        );
        $id = (int)self::db()->lastInsertId();

        try {
            $intent = self::paymongo('POST', '/payment_intents', ['data' => ['attributes' => [
                'amount'                 => (int)round($amount * 100),
                'currency'               => 'PHP',
                'payment_method_allowed' => ['qrph'],
                'capture_type'           => 'automatic',
                'description'            => "TriLink wallet top-up {$code}",
                'statement_descriptor'   => 'TriLink',
                'metadata'               => [
                    'topup_code'   => $code,
                    'account_type' => $acct,
                    'account_id'   => (string)$accountId,
                ],
            ]]]);
            $intentId = $intent['data']['id'] ?? '';
            if (!$intentId) throw new TopupException('PayMongo did not return a payment intent.');
            self::run("UPDATE tl_topups SET provider_intent_id=? WHERE id=?", [$intentId, $id]);

            $billing = ['name' => $account['name'] ?: 'TriLink Customer'];
            $billing['email'] = filter_var($account['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $account['email'] : PAYMONGO_FALLBACK_EMAIL;
            if (!empty($account['phone'])) $billing['phone'] = $account['phone'];

            $pm = self::paymongo('POST', '/payment_methods', ['data' => ['attributes' => [
                'type'    => 'qrph',
                'billing' => $billing,
            ]]]);
            $pmId = $pm['data']['id'] ?? '';
            if (!$pmId) throw new TopupException('PayMongo did not return a payment method.');

            $attached = self::paymongo('POST', "/payment_intents/{$intentId}/attach", ['data' => ['attributes' => [
                'payment_method' => $pmId,
            ]]]);
            $img = $attached['data']['attributes']['next_action']['code']['image_url'] ?? '';
            if (!$img) throw new TopupException('PayMongo did not return a QR code.');
            if (strpos($img, 'data:') !== 0) $img = 'data:image/png;base64,' . $img;

            self::run("UPDATE tl_topups SET qr_image=? WHERE id=?", [$img, $id]);
        } catch (Exception $e) {
            error_log('[TriLink Topup] QR Ph create failed for ' . $code . ': ' . $e->getMessage());
            self::run(
                "UPDATE tl_topups SET status='failed', failure_reason=? WHERE id=?",
                [mb_substr($e->getMessage(), 0, 250), $id]
            );
            throw new TopupException('Hindi makagawa ng QR Ph ngayon. Subukan ulit mamaya o gamitin ang Teller.');
        }

        return self::findByCode($code);
    }

    // ── Lookups ─────────────────────────────────────────────────
    private static function select(string $where, array $p): ?array {
        return self::row(
            "SELECT t.*,
                    TIMESTAMPDIFF(SECOND, NOW(), t.expires_at)      AS seconds_left,
                    TIMESTAMPDIFF(SECOND, t.last_checked_at, NOW()) AS checked_ago
             FROM tl_topups t WHERE {$where} LIMIT 1",
            $p
        );
    }
    public static function findById(int $id): ?array      { return self::select('t.id=?', [$id]); }
    public static function findByCode(string $code): ?array { return self::select('t.topup_code=?', [trim($code)]); }
    public static function findForOwner(string $code, string $acct, int $accountId): ?array {
        return self::select('t.topup_code=? AND t.account_type=? AND t.account_id=?', [trim($code), $acct, $accountId]);
    }

    /** Name / phone / balance of the wallet owner. */
    public static function account(string $acct, int $id): ?array {
        if ($acct === self::DRIVER) {
            return self::row("SELECT id, driver_code AS code, full_name AS name, phone, email, status, wallet_balance FROM tl_drivers WHERE id=?", [$id]);
        }
        return self::row("SELECT id, user_code AS code, full_name AS name, phone, email, status, wallet_balance FROM tl_users WHERE id=?", [$id]);
    }

    // ── Status sync ─────────────────────────────────────────────
    /**
     * Brings a pending top-up up to date: asks PayMongo whether a QR Ph
     * payment went through (throttled) and expires stale requests.
     */
    public static function refresh(array $t): array {
        if ($t['status'] !== 'pending') return $t;

        $expired = $t['expires_at'] !== null && (int)$t['seconds_left'] <= 0;
        if ($t['channel'] === self::QRPH && $t['provider_intent_id']) {
            $stale = $t['checked_ago'] === null || (int)$t['checked_ago'] >= 5;
            if ($stale || $expired) {
                $t = self::syncWithProvider($t);
                if ($t['status'] !== 'pending') return $t;
            }
        }
        if ($expired) {
            self::run("UPDATE tl_topups SET status='expired' WHERE id=? AND status='pending'", [$t['id']]);
            return self::findById((int)$t['id']);
        }
        return $t;
    }

    /** Asks PayMongo for the real state of a QR Ph payment; credits the wallet if paid. */
    public static function syncWithProvider(array $t): array {
        if ($t['channel'] !== self::QRPH || !$t['provider_intent_id'] || $t['status'] === 'paid') return $t;
        try {
            $intent = self::paymongo('GET', '/payment_intents/' . rawurlencode($t['provider_intent_id']));
            self::run("UPDATE tl_topups SET last_checked_at=NOW() WHERE id=?", [$t['id']]);
            $a = $intent['data']['attributes'] ?? [];
            if (($a['status'] ?? '') === 'succeeded' && (int)($a['amount'] ?? 0) === (int)round((float)$t['amount'] * 100)) {
                $paymentId = $a['payments'][0]['id'] ?? null;
                return self::markPaid((int)$t['id'], ['payment_id' => $paymentId])['topup'];
            }
        } catch (Exception $e) {
            error_log('[TriLink Topup] PayMongo sync failed for ' . $t['topup_code'] . ': ' . $e->getMessage());
        }
        return self::findById((int)$t['id']);
    }

    // ── Credit ──────────────────────────────────────────────────
    /**
     * Credits the wallet exactly once and marks the top-up paid.
     * opts: payment_id (QR Ph), load_center_id + load_center_name (teller).
     * Returns ['credited' => bool (false if it was already paid), 'topup' => row].
     */
    public static function markPaid(int $topupId, array $opts = []): array {
        $pdo = self::db();
        $pdo->beginTransaction();
        try {
            $t = self::row(
                "SELECT t.*, (t.expires_at IS NULL OR t.expires_at > NOW()) AS is_live
                 FROM tl_topups t WHERE t.id=? FOR UPDATE",
                [$topupId]
            );
            if (!$t) throw new TopupException('Top-up not found.');
            if ($t['status'] === 'paid') {
                $pdo->commit();
                return ['credited' => false, 'topup' => self::findById($topupId)];
            }

            $lcId = isset($opts['load_center_id']) ? (int)$opts['load_center_id'] : null;
            if ($t['channel'] === self::TELLER) {
                // Cash top-ups are only valid while pending and not expired.
                if (!$lcId) throw new TopupException('Teller confirmation required.');
                if ($t['status'] !== 'pending' || !(int)$t['is_live']) {
                    throw new TopupException('Hindi na pending o expired na ang top-up na ito.');
                }
                $desc = 'Top-up sa Teller' . (!empty($opts['load_center_name']) ? ' – ' . $opts['load_center_name'] : '') . " (#{$t['topup_code']})";
            } else {
                // QR Ph: PayMongo already has the money, so credit even if the
                // request was locally expired/cancelled in the meantime.
                if ($lcId) throw new TopupException('QR Ph top-ups cannot be confirmed by a teller.');
                $desc = "Top-up via QR Ph (#{$t['topup_code']})";
            }

            if ($t['account_type'] === self::DRIVER) {
                $tbl = 'tl_drivers'; $txnTbl = 'tl_driver_wallet_transactions'; $col = 'driver_id'; $prefix = 'DTXN-';
            } else {
                $tbl = 'tl_users';   $txnTbl = 'tl_wallet_transactions';        $col = 'user_id';   $prefix = 'TXN-';
            }

            $acc = self::row("SELECT wallet_balance FROM {$tbl} WHERE id=? FOR UPDATE", [$t['account_id']]);
            if (!$acc) throw new TopupException('Account not found.');
            $amount = round((float)$t['amount'], 2);
            $before = round((float)$acc['wallet_balance'], 2);
            $after  = round($before + $amount, 2);
            $txnCode = $prefix . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

            self::run("UPDATE {$tbl} SET wallet_balance=? WHERE id=?", [$after, $t['account_id']]);
            self::run(
                "INSERT INTO {$txnTbl}
                 (txn_code, {$col}, type, amount, direction, balance_before, balance_after, reference_type, reference_id, description, status)
                 VALUES (?,?, 'topup', ?, 'credit', ?, ?, 'topup', ?, ?, 'completed')",
                [$txnCode, $t['account_id'], $amount, $before, $after, $t['id'], $desc]
            );
            self::run(
                "UPDATE tl_topups
                 SET status='paid', paid_at=NOW(), credited_txn_code=?, failure_reason=NULL,
                     provider_payment_id=COALESCE(?, provider_payment_id),
                     load_center_id=COALESCE(?, load_center_id)
                 WHERE id=?",
                [$txnCode, $opts['payment_id'] ?? null, $lcId, $t['id']]
            );
            if ($lcId) {
                self::run("UPDATE tl_load_centers SET total_processed = total_processed + ? WHERE id=?", [$amount, $lcId]);
            }

            $pdo->commit();
            return ['credited' => true, 'topup' => self::findById($topupId)];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    // ── Cancel ──────────────────────────────────────────────────
    public static function cancel(array $t): array {
        if ($t['status'] !== 'pending') return $t;
        if ($t['channel'] === self::QRPH) {
            // Never cancel something the customer already paid.
            $t = self::syncWithProvider($t);
            if ($t['status'] !== 'pending') return $t;
        }
        self::run("UPDATE tl_topups SET status='cancelled' WHERE id=? AND status='pending'", [$t['id']]);
        return self::findById((int)$t['id']);
    }

    // ── Webhook ─────────────────────────────────────────────────
    public static function verifySignature(string $raw, string $header): bool {
        if (PAYMONGO_WEBHOOK_SECRET === '' || $header === '') return false;
        $parts = [];
        foreach (explode(',', $header) as $kv) {
            $kv = explode('=', trim($kv), 2);
            if (count($kv) === 2) $parts[$kv[0]] = $kv[1];
        }
        if (empty($parts['t'])) return false;
        $expected = hash_hmac('sha256', $parts['t'] . '.' . $raw, PAYMONGO_WEBHOOK_SECRET);
        $live = strpos(PAYMONGO_SECRET_KEY, 'sk_live_') === 0;
        $sig  = $live ? ($parts['li'] ?? '') : ($parts['te'] ?? '');
        return $sig !== '' && hash_equals($expected, $sig);
    }

    /** Handles a verified PayMongo event. Returns a short status string for logging. */
    public static function handleWebhook(array $event): string {
        $type = $event['data']['attributes']['type'] ?? '';
        $intentId = self::findIntentId($event['data']['attributes']['data'] ?? []);
        if (!$intentId) return "ignored {$type}: no payment intent";

        $t = self::row("SELECT id FROM tl_topups WHERE provider_intent_id=?", [$intentId]);
        if (!$t) return "ignored {$type}: unknown intent {$intentId}";

        $t = self::findById((int)$t['id']);
        // Never trust the webhook body for money: re-read the intent from PayMongo.
        $t = self::syncWithProvider($t);
        if ($t['status'] === 'pending' && $type === 'qrph.expired') {
            self::run("UPDATE tl_topups SET status='expired' WHERE id=? AND status='pending'", [$t['id']]);
        }
        return "{$type}: {$t['topup_code']} is " . (self::findById((int)$t['id'])['status'] ?? '?');
    }

    private static function findIntentId($data): ?string {
        if (!is_array($data)) return null;
        if (!empty($data['attributes']['payment_intent_id'])) return (string)$data['attributes']['payment_intent_id'];
        if (!empty($data['id']) && is_string($data['id']) && strpos($data['id'], 'pi_') === 0) return $data['id'];
        foreach ($data as $v) {
            if (is_string($v) && preg_match('/^pi_[A-Za-z0-9]+$/', $v)) return $v;
            if (is_array($v) && ($found = self::findIntentId($v))) return $found;
        }
        return null;
    }

    // ── Helpers ─────────────────────────────────────────────────
    private static function newCode(): string {
        do {
            $code = 'TU' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        } while (self::val("SELECT COUNT(*) FROM tl_topups WHERE topup_code=?", [$code]) > 0);
        return $code;
    }

    private static function paymongo(string $method, string $path, ?array $body = null): array {
        if (!self::qrphEnabled()) throw new TopupException('PayMongo is not configured.');
        $ch = curl_init(PAYMONGO_API_BASE . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_USERPWD        => PAYMONGO_SECRET_KEY . ':',
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 25,
        ];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) throw new TopupException('PayMongo connection error: ' . $err);
        $json = json_decode($resp, true);
        if ($http >= 400 || !is_array($json)) {
            $detail = $json['errors'][0]['detail'] ?? ('HTTP ' . $http);
            throw new TopupException('PayMongo error: ' . $detail);
        }
        return $json;
    }
}
