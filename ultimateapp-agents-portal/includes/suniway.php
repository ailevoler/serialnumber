<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/ledger.php';

/**
 * UBills (Bills Pay), ULoad (E-Load) and UCash In (cash-in to GCash, Maya and other e-wallets) through the SUNIWAY
 * Partner API: GET providers -> POST transactions/preview -> POST transactions -> GET transactions/{id}.
 * The customer pays with Credits. This is not the Credits top-up ("Buy Credits"): UCash In sends money OUT of the
 * app into another e-wallet.
 *
 * Money safety:
 *  - Credits are taken (and booked) BEFORE the transaction is sent to SUNIWAY, inside one database transaction.
 *  - SUNIWAY says no (HTTP 4xx) or later reports FAILED/CANCELLED -> Credits are refunded automatically.
 *  - No answer (timeout, 5xx, unreadable reply) -> status "unknown": nothing is refunded automatically because the
 *    payment may have gone through. Admin checks it in the SUNIWAY dashboard, then refunds or marks it successful.
 * The API key is stored encrypted (Admin > Settings) and never sent to the browser.
 */

final class SuniwayError extends RuntimeException
{
    /** $definitive = SUNIWAY clearly rejected the request (safe to refund); false = outcome unknown. */
    public function __construct(string $message, public readonly bool $definitive = false, public readonly int $http = 0)
    {
        parent::__construct($message);
    }
}

function suniway_services(): array
{
    return [
        'bills_pay' => ['code' => 'UBills', 'label' => 'UBills', 'title' => 'Pay bills', 'page' => 'ubills.php', 'prefix' => 'UB', 'image' => 'ubills.png',
            'account' => 'Account / reference number', 'placeholder' => 'As printed on your bill', 'intro' => 'Pay electricity, water, internet, cable, government and more with your Credits.'],
        'eload' => ['code' => 'ULoad', 'label' => 'ULoad', 'title' => 'Buy load', 'page' => 'uload.php', 'prefix' => 'UL', 'image' => 'uload.png',
            'account' => 'Mobile number', 'placeholder' => '09XXXXXXXXX', 'intro' => 'Load any Globe, TM, Smart, TNT or DITO number with your Credits.'],
        'ecash' => ['code' => 'UCash In', 'label' => 'UCash In', 'title' => 'Cash in to an e-wallet', 'page' => 'ucashin.php', 'prefix' => 'UC', 'image' => 'ucashin.png',
            'account' => 'E-wallet mobile / account number', 'placeholder' => '09XXXXXXXXX', 'intro' => 'Send Credits to GCash, Maya and other e-wallets. (Different from Buy Credits.)'],
    ];
}

/** Popular billers shown as shortcuts, matched to the live SUNIWAY list by keywords (shown only when offered). */
function suniway_quick_picks(): array
{
    return [
        'bills_pay' => ['AKELCO' => 'akelco,aklan electric', 'Boracay Water' => 'boracay island water,boracay water', 'PLDT' => 'pldt', 'Converge' => 'converge',
            'Globe At Home' => 'globe at home,globe broadband,globe postpaid', 'Cignal' => 'cignal', 'SSS' => 'sss,social security', 'Pag-IBIG' => 'pag-ibig,pagibig,hdmf', 'PhilHealth' => 'philhealth'],
        'eload' => ['Globe' => 'globe', 'TM' => 'tm,touch mobile', 'Smart' => 'smart', 'TNT' => 'tnt,talk n text', 'DITO' => 'dito'],
        'ecash' => ['GCash' => 'gcash', 'Maya' => 'maya,paymaya', 'ShopeePay' => 'shopeepay,shopee pay', 'Coins.ph' => 'coins.ph,coins ph', 'PalawanPay' => 'palawanpay,palawan pay'],
    ];
}

function suniway_config(): array
{
    return [
        'enabled' => setting('suniway.enabled', '0') === '1',
        'base_url' => rtrim((string) setting('suniway.base_url', 'https://api-sunikiosk.suniway.ph/api/partner-api'), '/'),
        'api_key' => (string) setting('suniway.api_key', getenv('SUNIWAY_API_KEY') ?: ''),
        'payment_method' => (string) setting('suniway.payment_method', 'CASH'),
    ];
}

/** Customers see UBills / ULoad / UCash In only when enabled and a key is saved. */
function suniway_enabled(): bool
{
    $c = suniway_config();
    return $c['enabled'] && $c['api_key'] !== '';
}

function suniway_app_fee(string $service): int
{
    return max(0, setting_int("suniway.fee_{$service}_centavos", 0));
}

function suniway_request(string $method, string $path, ?array $body = null): array
{
    $c = suniway_config();
    if ($c['api_key'] === '') throw new SuniwayError('SUNIWAY is not set up yet.', true);
    if (!function_exists('curl_init')) throw new SuniwayError('PHP cURL is required for SUNIWAY.', true);
    $ch = curl_init($c['base_url'] . '/' . ltrim($path, '/'));
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $c['api_key']];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 40,
        CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno) throw new SuniwayError('Could not reach SUNIWAY (network error ' . $errno . ').', false);
    $json = json_decode((string) $raw, true);
    if ($status < 200 || $status >= 300) {
        $msg = is_array($json) ? (string) ($json['message'] ?? $json['error'] ?? '') : '';
        if (is_array($json['message'] ?? null)) $msg = implode(' ', array_map('strval', $json['message']));
        // 4xx (except timeout / rate limit) = SUNIWAY refused it; 5xx = we cannot tell whether it went through.
        $definitive = $status >= 400 && $status < 500 && !in_array($status, [408, 429], true);
        throw new SuniwayError(trim(($msg !== '' ? mb_substr($msg, 0, 200) : 'SUNIWAY request failed.') . ' (HTTP ' . $status . ')'), $definitive, $status);
    }
    if (!is_array($json)) throw new SuniwayError('SUNIWAY sent an unreadable reply.', false, $status);
    return $json;
}

/** Live provider list for one service (cached 10 minutes). Each row: id, name, type, min, max (centavos or null). */
function suniway_providers(string $service, bool $fresh = false): array
{
    $groups = ['bills_pay' => 'bills', 'eload' => 'eload', 'ecash' => 'ecCash'];
    if (!isset($groups[$service])) throw new InvalidArgumentException('Unknown service.');
    $file = null;
    try { $file = app_storage_dir('cache') . '/suniway-providers.json'; } catch (Throwable $e) {}
    $all = null;
    if (!$fresh && $file && is_file($file) && filemtime($file) > time() - 600) $all = json_decode((string) file_get_contents($file), true);
    if (!is_array($all)) {
        $all = suniway_request('GET', 'providers');
        if (isset($all['data']) && is_array($all['data']) && !isset($all['bills'])) $all = $all['data'];
        if ($file) @file_put_contents($file, json_encode($all), LOCK_EX);
    }
    $out = [];
    foreach ((array) ($all[$groups[$service]] ?? []) as $row) {
        if (!is_array($row)) continue;
        $id = (string) ($row['billerId'] ?? $row['providerId'] ?? $row['id'] ?? $row['_id'] ?? $row['code'] ?? '');
        $type = (string) ($row['providerType'] ?? '');
        if ($id === '' || $type === '' || isset($out[$id])) continue;
        $peso = static fn($v): ?int => is_numeric($v) && (float) $v > 0 ? (int) round((float) $v * 100) : null;
        $out[$id] = ['id' => $id, 'name' => mb_substr((string) ($row['name'] ?? $row['providerName'] ?? $row['displayName'] ?? $id), 0, 160), 'type' => mb_substr($type, 0, 60),
            'min' => $peso($row['minAmount'] ?? null), 'max' => $peso($row['maxAmount'] ?? null)];
    }
    uasort($out, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}

/** Shortcut chips: label => provider id, for the popular billers SUNIWAY currently offers. */
function suniway_match_quick_picks(string $service, array $providers): array
{
    $picks = [];
    foreach (suniway_quick_picks()[$service] ?? [] as $label => $keywords) {
        foreach ($providers as $p) {
            foreach (explode(',', $keywords) as $kw) {
                if (preg_match('/(?<![a-z0-9])' . preg_quote(trim($kw), '/') . '(?![a-z0-9])/i', $p['name'])) { $picks[$label] = $p['id']; continue 3; }
            }
        }
    }
    return $picks;
}

function suniway_peso_to_centavos(string $value): int
{
    $value = trim(str_replace([',', '₱', ' '], '', $value));
    if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $value)) throw new InvalidArgumentException('Enter an amount like 100 or 100.50.');
    [$w, $f] = array_pad(explode('.', $value, 2), 2, '');
    return (int) $w * 100 + (int) str_pad($f, 2, '0');
}

function suniway_clean_account(string $service, string $account): string
{
    $account = trim(preg_replace('/\s+/', '', $account) ?? '');
    if (in_array($service, ['eload', 'ecash'], true)) {
        $account = preg_replace('/^\+?63(?=9\d{9}$)/', '0', $account) ?? $account;
        if ($service === 'eload' && !preg_match('/^09\d{9}$/D', $account)) throw new InvalidArgumentException('Enter an 11-digit mobile number starting with 09.');
    }
    if ($account === '' || strlen($account) > 160 || !preg_match('/^[A-Za-z0-9@._-]+$/D', $account)) throw new InvalidArgumentException('Enter a valid account or mobile number.');
    return $account;
}

/**
 * Step 1: validate and ask SUNIWAY for the fees. Returns a quote stored in the session until the customer confirms.
 */
function suniway_quote(string $service, string $providerId, string $account, string $amountText): array
{
    if (!isset(suniway_services()[$service])) throw new InvalidArgumentException('Unknown service.');
    $providers = suniway_providers($service);
    $p = $providers[$providerId] ?? null;
    if (!$p) throw new InvalidArgumentException('Choose a biller / provider from the list.');
    $account = suniway_clean_account($service, $account);
    $amount = suniway_peso_to_centavos($amountText);
    if ($amount < 100) throw new InvalidArgumentException('Minimum amount is PHP 1.00.');
    if ($p['min'] !== null && $amount < $p['min']) throw new InvalidArgumentException('Minimum for ' . $p['name'] . ' is PHP ' . peso($p['min']) . '.');
    if ($p['max'] !== null && $amount > $p['max']) throw new InvalidArgumentException('Maximum for ' . $p['name'] . ' is PHP ' . peso($p['max']) . '.');
    $payload = suniway_payload($p, $account, $amount);
    try {
        $r = suniway_request('POST', 'transactions/preview', $payload);
    } catch (SuniwayError $e) {
        throw new InvalidArgumentException('SUNIWAY could not prepare this payment: ' . $e->getMessage());
    }
    $d = (array) ($r['data'] ?? $r);
    $fee = static fn($v): int => is_numeric($v) ? max(0, (int) round((float) $v * 100)) : 0;
    $serviceFee = $fee($d['serviceFee'] ?? 0);
    $providerFee = $fee($d['providerFee'] ?? 0);
    $suniwayTotal = isset($d['total']) && is_numeric($d['total']) ? $fee($d['total']) : $amount + $serviceFee + $providerFee;
    $suniwayTotal = max($suniwayTotal, $amount);
    $appFee = suniway_app_fee($service);
    return ['service' => $service, 'provider' => $p, 'account' => $account, 'amount' => $amount, 'service_fee' => $serviceFee, 'provider_fee' => $providerFee,
        'suniway_total' => $suniwayTotal, 'app_fee' => $appFee, 'total' => $suniwayTotal + $appFee, 'expires' => time() + 600];
}

function suniway_payload(array $provider, string $account, int $amount): array
{
    return ['amount' => round($amount / 100, 2), 'providerType' => $provider['type'], 'billerId' => $provider['id'],
        'paymentMethod' => suniway_config()['payment_method'], 'accountNumber' => $account, 'clientMetadata' => ['accountNumber' => $account]];
}

function suniway_status_from_remote(string $remote): string
{
    $remote = strtoupper(trim($remote));
    if (in_array($remote, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED', 'COMPLETE', 'PAID', 'POSTED', 'APPROVED', 'DONE'], true)) return 'success';
    if (in_array($remote, ['FAILED', 'FAIL', 'CANCELLED', 'CANCELED', 'REJECTED', 'DECLINED', 'EXPIRED', 'REVERSED', 'REFUNDED', 'VOID', 'VOIDED', 'ERROR'], true)) return 'failed';
    return 'pending';
}

function suniway_lock(PDO $pdo, int $id): array
{
    $s = $pdo->prepare('SELECT * FROM suniway_transactions WHERE id = ? FOR UPDATE');
    $s->execute([$id]);
    $t = $s->fetch();
    if (!$t) throw new InvalidArgumentException('Transaction not found.');
    return $t;
}

/**
 * Step 2: take the Credits, then send the transaction to SUNIWAY. Idempotent per (user, request key).
 * Returns our reference (UB-/UL-/UC-...).
 */
function suniway_pay(PDO $pdo, int $userId, array $q, string $requestKey): string
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $requestKey)) throw new InvalidArgumentException('Invalid request. Please start again.');
    if (($q['expires'] ?? 0) < time()) throw new InvalidArgumentException('This quote expired. Please review the payment again.');
    $svc = suniway_services()[$q['service']];
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT id, credits, status FROM users WHERE id = ? FOR UPDATE');
        $s->execute([$userId]);
        $u = $s->fetch();
        if (!$u || $u['status'] !== 'active') throw new InvalidArgumentException('Account not available.');
        $s = $pdo->prepare('SELECT reference FROM suniway_transactions WHERE user_id = ? AND request_key = ?');
        $s->execute([$userId, $requestKey]);
        if ($prior = $s->fetchColumn()) { $pdo->commit(); return (string) $prior; }
        $total = (int) $q['total'];
        $s = $pdo->prepare('UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?');
        $s->execute([centavos_to_decimal($total), $userId, centavos_to_decimal($total)]);
        if ($s->rowCount() !== 1) throw new InvalidArgumentException('Not enough Credits. You need ' . peso($total) . ' Credits for this payment.');
        $reference = $svc['prefix'] . '-' . strtoupper(bin2hex(random_bytes(6)));
        $pdo->prepare('INSERT INTO suniway_transactions (reference, request_key, user_id, service, provider_id, provider_name, provider_type, account_number, amount_centavos, service_fee_centavos, provider_fee_centavos, app_fee_centavos, total_centavos)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$reference, $requestKey, $userId, $q['service'], $q['provider']['id'], $q['provider']['name'], $q['provider']['type'], $q['account'], $q['amount'], $q['service_fee'], $q['provider_fee'], $q['app_fee'], $total]);
        $lines = [['user_credits:' . $userId, $total, 0, $svc['label'] . ' ' . $q['provider']['name']], ['suniway_payable', 0, (int) $q['suniway_total'], 'Sent to SUNIWAY']];
        if ($q['app_fee'] > 0) $lines[] = ['fee_revenue:' . $q['service'], 0, (int) $q['app_fee'], $svc['label'] . ' convenience fee'];
        ledger_post($pdo, $reference, 'suniway_payment', $lines);
        $pdo->prepare("INSERT INTO transactions (user_id, type, title, amount, status) VALUES (?, 'payment', ?, ?, 'Completed')")
            ->execute([$userId, $svc['label'] . ' · ' . $q['provider']['name'] . ' · ' . $reference, '-' . centavos_to_decimal($total)]);
        $s = $pdo->prepare('SELECT id FROM suniway_transactions WHERE reference = ?');
        $s->execute([$reference]);
        $id = (int) $s->fetchColumn();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Credits are safely held. Now send it.
    try {
        $r = suniway_request('POST', 'transactions', suniway_payload($q['provider'], $q['account'], (int) $q['amount']));
        $d = (array) ($r['data'] ?? $r);
        $remote = strtoupper((string) ($d['status'] ?? 'PENDING'));
        $pdo->prepare("UPDATE suniway_transactions SET status = 'pending', remote_id = ?, remote_reference = ?, remote_status = ?, response_json = ?, last_checked_at = NOW() WHERE id = ?")
            ->execute([mb_substr((string) ($d['id'] ?? $d['transactionId'] ?? $d['_id'] ?? ''), 0, 120) ?: null, mb_substr((string) ($d['referenceNumber'] ?? $d['reference'] ?? ''), 0, 120) ?: null, mb_substr($remote, 0, 40), json_encode($d), $id]);
        suniway_apply_status($pdo, $id, $remote, 'SUNIWAY: ' . $remote);
    } catch (SuniwayError $e) {
        if ($e->definitive) {
            suniway_refund($pdo, $id, 'Not accepted by SUNIWAY: ' . $e->getMessage());
        } else {
            $pdo->prepare("UPDATE suniway_transactions SET status = 'unknown', error_message = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 255), $id]);
            error_log('SUNIWAY outcome unknown for ' . $reference . ': ' . $e->getMessage());
        }
    }
    return $reference;
}

/** Applies a SUNIWAY status: success -> completed; failed -> refund; anything else stays pending. */
function suniway_apply_status(PDO $pdo, int $id, string $remote, string $note): string
{
    $state = suniway_status_from_remote($remote);
    if ($state === 'failed') { suniway_refund($pdo, $id, $note); return 'failed'; }
    if ($state === 'success') {
        $pdo->prepare("UPDATE suniway_transactions SET status = 'success', completed_at = COALESCE(completed_at, NOW()), error_message = NULL WHERE id = ? AND status IN ('submitting','pending','unknown')")->execute([$id]);
    }
    return $state;
}

/** Gives the Credits back (once). Never for a transaction already marked successful. */
function suniway_refund(PDO $pdo, int $id, string $reason): bool
{
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $t = suniway_lock($pdo, $id);
        if ($t['refunded_at'] !== null) { if ($own) $pdo->commit(); return false; }
        if ($t['status'] === 'success') throw new InvalidArgumentException('This transaction was successful and cannot be refunded here.');
        $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$t['user_id']]);
        $total = (int) $t['total_centavos'];
        $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')->execute([centavos_to_decimal($total), $t['user_id']]);
        $pdo->prepare("UPDATE suniway_transactions SET status = 'failed', refunded_at = NOW(), error_message = ? WHERE id = ?")->execute([mb_substr($reason, 0, 255), $id]);
        $lines = [['suniway_payable', $total - (int) $t['app_fee_centavos'], 0, 'Not sent / failed'], ['user_credits:' . $t['user_id'], 0, $total, 'Refund ' . $t['reference']]];
        if ((int) $t['app_fee_centavos'] > 0) $lines[] = ['fee_revenue:' . $t['service'], (int) $t['app_fee_centavos'], 0, 'Fee refunded'];
        ledger_post($pdo, 'RF-' . $t['reference'], 'suniway_refund', $lines);
        $label = suniway_services()[$t['service']]['label'];
        $pdo->prepare("INSERT INTO transactions (user_id, type, title, amount, status) VALUES (?, 'payment', ?, ?, 'Completed')")
            ->execute([$t['user_id'], $label . ' refund · ' . $t['reference'], centavos_to_decimal($total)]);
        if ($own) $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Asks SUNIWAY for the latest status. Returns the local status. */
function suniway_refresh(PDO $pdo, int $id): string
{
    $s = $pdo->prepare('SELECT * FROM suniway_transactions WHERE id = ?');
    $s->execute([$id]);
    $t = $s->fetch();
    if (!$t) throw new InvalidArgumentException('Transaction not found.');
    if (in_array($t['status'], ['success', 'failed'], true) || !$t['remote_id']) return $t['status'];
    try {
        $r = suniway_request('GET', 'transactions/' . rawurlencode((string) $t['remote_id']));
    } catch (SuniwayError $e) {
        $pdo->prepare('UPDATE suniway_transactions SET last_checked_at = NOW() WHERE id = ?')->execute([$id]);
        throw new InvalidArgumentException('Could not get the status from SUNIWAY right now. Please try again later.');
    }
    $d = (array) ($r['data'] ?? $r);
    $remote = strtoupper((string) ($d['status'] ?? $t['remote_status'] ?? 'PENDING'));
    $pdo->prepare('UPDATE suniway_transactions SET remote_status = ?, remote_reference = COALESCE(?, remote_reference), response_json = ?, last_checked_at = NOW() WHERE id = ?')
        ->execute([mb_substr($remote, 0, 40), mb_substr((string) ($d['referenceNumber'] ?? ''), 0, 120) ?: null, json_encode($d), $id]);
    return suniway_apply_status($pdo, $id, $remote, 'SUNIWAY: ' . $remote);
}

/** Admin: confirmed successful in the SUNIWAY dashboard (for "unknown" transactions). */
function suniway_mark_success(PDO $pdo, int $id, string $remoteRef): void
{
    $pdo->beginTransaction();
    try {
        $t = suniway_lock($pdo, $id);
        if ($t['refunded_at'] !== null || $t['status'] === 'failed') throw new InvalidArgumentException('This transaction was already refunded.');
        if ($t['status'] === 'success') { $pdo->commit(); return; }
        $pdo->prepare("UPDATE suniway_transactions SET status = 'success', completed_at = NOW(), remote_reference = COALESCE(NULLIF(?, ''), remote_reference), error_message = 'Confirmed by admin' WHERE id = ?")
            ->execute([mb_substr(trim($remoteRef), 0, 120), $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function suniway_status_label(string $status): array
{
    return [
        'submitting' => ['Sending', 'info'], 'pending' => ['Processing', 'info'], 'success' => ['Successful', 'good'],
        'failed' => ['Failed · refunded', 'bad'], 'unknown' => ['Checking', 'warn'],
    ][$status] ?? [ucfirst($status), 'muted'];
}
