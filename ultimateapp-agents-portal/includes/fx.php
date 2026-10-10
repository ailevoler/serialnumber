<?php
declare(strict_types=1);

require_once __DIR__ . '/ledger.php';

/**
 * USD ⇄ PHP exchange. Customers buy USD with BCash and sell USD back to BCash.
 * The mid-market USD/PHP rate comes from free, open-source rate APIs (Frankfurter, fawazahmed0
 * currency-api) or a manual rate set in Admin. The fee (Admin, in basis points) is shown in PHP:
 * added on top when buying USD, deducted when selling USD. All amounts are integers:
 * PHP in centavos, USD in cents, rates in micro-units (58.123456 PHP per USD = 58123456).
 */
final class FxRateChanged extends InvalidArgumentException {}

const FX_SIDES = ['buy_usd' => 'Buy USD', 'sell_usd' => 'Sell USD'];
const FX_SANE_MIN = 20;    // PHP per USD; anything outside 20..200 is treated as bad data
const FX_SANE_MAX = 200;
const FX_MAX_JUMP = 0.10;  // a new automatic rate more than 10% away from the last one is refused

function fx_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $pdo->query('SELECT 1 FROM fx_trades LIMIT 0'); $pdo->query('SELECT 1 FROM usd_wallets LIMIT 0'); $pdo->query('SELECT 1 FROM fx_rates LIMIT 0'); $ready = true; } catch (Throwable) { $ready = false; }
    }
    return $ready;
}

function fx_config(): array
{
    return [
        'enabled' => (string) setting('fx.enabled', '0') === '1',
        'provider' => (string) setting('fx.provider', 'auto'),
        'manual_rate' => (string) setting('fx.manual_rate', ''),
        'buy_fee_bp' => (int) setting('fx.buy_fee_bp', 50),
        'sell_fee_bp' => (int) setting('fx.sell_fee_bp', 50),
        'min_usd_cents' => (int) setting('fx.min_usd_cents', 100),
        'max_usd_cents' => (int) setting('fx.max_usd_cents', 100000),
        'refresh_minutes' => (int) setting('fx.refresh_minutes', 60),
        'max_age_hours' => (int) setting('fx.max_age_hours', 24),
    ];
}

/** "58.1234" -> 58123400. Accepts only plain positive decimals. */
function fx_rate_micro(string $rate): int
{
    if (!preg_match('/^(\d{1,4})(?:\.(\d{1,8}))?$/D', trim($rate), $m)) throw new InvalidArgumentException('Invalid exchange rate.');
    return (int) $m[1] * 1000000 + (int) substr(str_pad($m[2] ?? '', 6, '0'), 0, 6);
}

function fx_micro_to_rate(int $micro): string
{
    return intdiv($micro, 1000000) . '.' . str_pad((string) ($micro % 1000000), 6, '0', STR_PAD_LEFT);
}

function fx_format_rate(int $micro, int $decimals = 4): string
{
    return number_format($micro / 1000000, $decimals);
}

function fx_usd(int $cents): string
{
    return ($cents < 0 ? '-' : '') . number_format(abs($cents) / 100, 2);
}

function fx_percent(int $bp): string
{
    return rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.') . '%';
}

/** Parses a USD amount typed by the customer ("12.5") into cents within the Admin limits. */
function fx_usd_amount(string $input, ?array $cfg = null): int
{
    $cfg ??= fx_config();
    $input = trim(str_replace(',', '', $input));
    if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $input)) throw new InvalidArgumentException('Enter a USD amount with up to two decimal places.');
    [$whole, $fraction] = array_pad(explode('.', $input, 2), 2, '');
    $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    if ($cents < $cfg['min_usd_cents'] || $cents > $cfg['max_usd_cents']) {
        throw new InvalidArgumentException('Enter from USD ' . fx_usd($cfg['min_usd_cents']) . ' to USD ' . fx_usd($cfg['max_usd_cents']) . ' per exchange.');
    }
    return $cents;
}

// ---------------------------------------------------------------- rate providers

function fx_http_json(string $url): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL is required for exchange rates.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'UltimateApp-FX/1.0']);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $status !== 200) throw new RuntimeException('Rate request failed (' . ($status ?: $error) . ').');
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) throw new RuntimeException('Rate reply is not JSON.');
    return $data;
}

/** Frankfurter (open source, European Central Bank reference rates, no key). */
function fx_fetch_frankfurter(): array
{
    $url = getenv('FX_FRANKFURTER_URL') ?: 'https://api.frankfurter.dev/v1/latest?base=USD&symbols=PHP';
    $d = fx_http_json($url);
    $rate = $d['rates']['PHP'] ?? null;
    if (!is_numeric($rate) || ($d['base'] ?? 'USD') !== 'USD') throw new RuntimeException('Frankfurter reply has no USD/PHP rate.');
    return ['rate' => sprintf('%.6f', (float) $rate), 'date' => is_string($d['date'] ?? null) ? $d['date'] : null, 'source' => 'frankfurter'];
}

/** fawazahmed0/exchange-api (open source, daily, no key), with its mirror. */
function fx_fetch_currency_api(): array
{
    $urls = getenv('FX_CURRENCYAPI_URL') ? [getenv('FX_CURRENCYAPI_URL')] : [
        'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.min.json',
        'https://latest.currency-api.pages.dev/v1/currencies/usd.min.json',
    ];
    $last = null;
    foreach ($urls as $url) {
        try {
            $d = fx_http_json($url);
            $rate = $d['usd']['php'] ?? null;
            if (!is_numeric($rate)) throw new RuntimeException('currency-api reply has no USD/PHP rate.');
            return ['rate' => sprintf('%.6f', (float) $rate), 'date' => is_string($d['date'] ?? null) ? $d['date'] : null, 'source' => 'currency-api'];
        } catch (RuntimeException $e) {
            $last = $e;
        }
    }
    throw $last ?? new RuntimeException('currency-api unavailable.');
}

function fx_latest_row(PDO $pdo): ?array
{
    $row = $pdo->query("SELECT * FROM fx_rates WHERE pair = 'USDPHP' ORDER BY id DESC LIMIT 1")->fetch();
    return $row ?: null;
}

/** Saves a rate. A repeat of the latest rate only refreshes its time, so the rate id stays the same. */
function fx_store_rate(PDO $pdo, string $rate, string $source, ?string $date): array
{
    $latest = fx_latest_row($pdo);
    if ($latest && fx_rate_micro((string) $latest['rate']) === fx_rate_micro($rate) && $latest['source'] === $source && ($latest['rate_date'] ?? null) === $date) {
        $pdo->prepare('UPDATE fx_rates SET fetched_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$latest['id']]);
    } else {
        $pdo->prepare("INSERT INTO fx_rates (pair, rate, source, rate_date) VALUES ('USDPHP', ?, ?, ?)")
            ->execute([fx_micro_to_rate(fx_rate_micro($rate)), $source, $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) ? $date : null]);
    }
    return fx_latest_row($pdo);
}

/**
 * Fetches a fresh rate from the configured source (auto = Frankfurter, then currency-api).
 * Refuses impossible values and sudden jumps, so one bad reply cannot reprice every exchange.
 */
function fx_refresh(PDO $pdo, ?array $cfg = null): array
{
    $cfg ??= fx_config();
    if ($cfg['provider'] === 'manual') {
        if ($cfg['manual_rate'] === '') throw new RuntimeException('Enter the manual USD/PHP rate in Admin.');
        return fx_store_rate($pdo, $cfg['manual_rate'], 'manual', date('Y-m-d'));
    }
    $fetchers = ['frankfurter' => 'fx_fetch_frankfurter', 'currency_api' => 'fx_fetch_currency_api'];
    $order = $cfg['provider'] === 'auto' ? array_keys($fetchers) : [$cfg['provider']];
    $errors = [];
    $previous = fx_latest_row($pdo);
    foreach ($order as $name) {
        try {
            $r = ($fetchers[$name])();
            $micro = fx_rate_micro($r['rate']);
            if ($micro < FX_SANE_MIN * 1000000 || $micro > FX_SANE_MAX * 1000000) throw new RuntimeException('rate ' . $r['rate'] . ' is outside ' . FX_SANE_MIN . '-' . FX_SANE_MAX);
            if ($previous && $previous['source'] !== 'manual') {
                $prev = fx_rate_micro((string) $previous['rate']);
                if (abs($micro - $prev) > $prev * FX_MAX_JUMP) throw new RuntimeException('rate ' . $r['rate'] . ' moved more than ' . (FX_MAX_JUMP * 100) . '% from ' . $previous['rate']);
            }
            return fx_store_rate($pdo, $r['rate'], $r['source'], $r['date']);
        } catch (Throwable $e) {
            $errors[] = $name . ': ' . $e->getMessage();
        }
    }
    throw new RuntimeException('Could not get a USD/PHP rate. ' . implode(' · ', $errors));
}

/**
 * The rate customers trade at. Refreshes it when older than the Admin interval (unless $allowRefresh
 * is false, e.g. on the home screen). 'tradable' is false when the exchange is off or the rate is too old.
 */
function fx_current(PDO $pdo, bool $allowRefresh = true): ?array
{
    if (!fx_ready($pdo)) return null;
    $cfg = fx_config();
    $row = fx_latest_row($pdo);
    $wantManual = $cfg['provider'] === 'manual';
    $stale = !$row || (time() - strtotime((string) $row['fetched_at'])) > $cfg['refresh_minutes'] * 60
        || ($wantManual && ($row['source'] !== 'manual' || ($cfg['manual_rate'] !== '' && fx_rate_micro((string) $row['rate']) !== fx_rate_micro($cfg['manual_rate']))))
        || (!$wantManual && $row['source'] === 'manual');
    if ($stale && $allowRefresh) {
        $locked = (int) $pdo->query("SELECT GET_LOCK('ua_fx_refresh', 0)")->fetchColumn() === 1;
        if ($locked) {
            try { $row = fx_refresh($pdo, $cfg); }
            catch (Throwable $e) { error_log('FX refresh failed: ' . $e->getMessage()); }
            finally { $pdo->query("SELECT RELEASE_LOCK('ua_fx_refresh')"); }
        }
    }
    if (!$row) return null;
    $age = max(0, intdiv(time() - strtotime((string) $row['fetched_at']), 60));
    $reason = '';
    if (!$cfg['enabled']) $reason = 'The USD exchange is closed right now.';
    elseif ($wantManual ? $row['source'] !== 'manual' : ($row['source'] === 'manual' || $age > $cfg['max_age_hours'] * 60)) $reason = 'The USD rate is being updated. Please try again in a few minutes.';
    $micro = fx_rate_micro((string) $row['rate']);
    return [
        'id' => (int) $row['id'], 'rate' => (string) $row['rate'], 'micro' => $micro, 'source' => (string) $row['source'],
        'rate_date' => $row['rate_date'], 'fetched_at' => (string) $row['fetched_at'], 'age_minutes' => $age,
        'buy_fee_bp' => $cfg['buy_fee_bp'], 'sell_fee_bp' => $cfg['sell_fee_bp'],
        'buy_micro' => intdiv($micro * (10000 + $cfg['buy_fee_bp']) + 9999, 10000),
        'sell_micro' => intdiv($micro * (10000 - $cfg['sell_fee_bp']), 10000),
        'tradable' => $reason === '', 'reason' => $reason,
    ];
}

function fx_source_label(string $source): string
{
    return ['frankfurter' => 'Frankfurter (ECB reference rate)', 'currency-api' => 'currency-api (fawazahmed0)', 'manual' => 'Manual rate (Admin)'][$source] ?? $source;
}

/**
 * Exact amounts for an exchange. Rounding always favours the house by at most one centavo.
 * buy_usd: customer pays total = gross + fee in BCash. sell_usd: customer gets total = gross - fee in BCash.
 */
function fx_quote(array $rate, string $side, int $usdCents): array
{
    if (!isset(FX_SIDES[$side])) throw new InvalidArgumentException('Choose Buy USD or Sell USD.');
    $micro = (int) $rate['micro'];
    $bp = $side === 'buy_usd' ? (int) $rate['buy_fee_bp'] : (int) $rate['sell_fee_bp'];
    $gross = $side === 'buy_usd' ? intdiv($usdCents * $micro + 999999, 1000000) : intdiv($usdCents * $micro, 1000000);
    $fee = intdiv($gross * $bp + 9999, 10000);
    $total = $side === 'buy_usd' ? $gross + $fee : $gross - $fee;
    if ($total <= 0) throw new InvalidArgumentException('That amount is too small to exchange.');
    return ['side' => $side, 'usd_cents' => $usdCents, 'gross' => $gross, 'fee' => $fee, 'total' => $total, 'fee_bp' => $bp, 'rate' => fx_micro_to_rate($micro), 'rate_id' => (int) $rate['id']];
}

function fx_usd_balance(PDO $pdo, int $userId): int
{
    if (!fx_ready($pdo)) return 0;
    $stmt = $pdo->prepare('SELECT balance_cents FROM usd_wallets WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * Buys or sells USD against the customer's BCash at the rate they saw ($rateId).
 * If the rate changed since, nothing moves and FxRateChanged asks them to check the new amount.
 * Idempotent per (customer, request key).
 */
function fx_trade(PDO $pdo, int $userId, string $side, int $usdCents, int $rateId, string $requestKey): string
{
    if (!isset(FX_SIDES[$side]) || !preg_match('/^[a-f0-9]{64}$/D', $requestKey)) throw new InvalidArgumentException('Invalid exchange request.');
    require_once __DIR__ . '/kyc.php';
    kyc_require_verified($pdo, 'user', $userId, 'exchanging USD and PHP');
    $cfg = fx_config();
    if ($usdCents < $cfg['min_usd_cents'] || $usdCents > $cfg['max_usd_cents']) throw new InvalidArgumentException('Enter from USD ' . fx_usd($cfg['min_usd_cents']) . ' to USD ' . fx_usd($cfg['max_usd_cents']) . ' per exchange.');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, status FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || ($user['status'] ?? 'active') !== 'active') throw new InvalidArgumentException('Your account cannot exchange right now.');

        $stmt = $pdo->prepare('SELECT reference FROM fx_trades WHERE user_id = ? AND request_key = ?');
        $stmt->execute([$userId, $requestKey]);
        if ($existing = $stmt->fetch()) { $pdo->commit(); return $existing['reference']; }

        $rate = fx_current($pdo, false);
        if (!$rate || !$rate['tradable']) throw new InvalidArgumentException($rate['reason'] ?? 'The USD exchange is not available right now.');
        if ($rate['id'] !== $rateId) throw new FxRateChanged('The USD rate just changed. Check the new amount, then confirm again.');
        $q = fx_quote($rate, $side, $usdCents);

        $pdo->prepare('INSERT IGNORE INTO boracay_cash_wallets (user_id) VALUES (?)')->execute([$userId]);
        $pdo->prepare('INSERT IGNORE INTO usd_wallets (user_id) VALUES (?)')->execute([$userId]);
        if ($side === 'buy_usd') {
            $stmt = $pdo->prepare('UPDATE boracay_cash_wallets SET balance_centavos = balance_centavos - ? WHERE user_id = ? AND balance_centavos >= ?');
            $stmt->execute([$q['total'], $userId, $q['total']]);
            if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Not enough BCash. You need PHP ' . peso($q['total']) . ' including the fee.');
            $pdo->prepare('UPDATE usd_wallets SET balance_cents = balance_cents + ? WHERE user_id = ?')->execute([$usdCents, $userId]);
        } else {
            $stmt = $pdo->prepare('UPDATE usd_wallets SET balance_cents = balance_cents - ? WHERE user_id = ? AND balance_cents >= ?');
            $stmt->execute([$usdCents, $userId, $usdCents]);
            if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Not enough USD in your wallet.');
            $pdo->prepare('UPDATE boracay_cash_wallets SET balance_centavos = balance_centavos + ? WHERE user_id = ?')->execute([$q['total'], $userId]);
        }

        $reference = 'FX-' . strtoupper(bin2hex(random_bytes(8)));
        $pdo->prepare('INSERT INTO fx_trades (reference, request_key, user_id, side, usd_cents, gross_php_centavos, fee_php_centavos, total_php_centavos, fee_bp, rate, rate_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$reference, $requestKey, $userId, $side, $usdCents, $q['gross'], $q['fee'], $q['total'], $q['fee_bp'], $q['rate'], $q['rate_id']]);
        // Books are in PHP. fx_usd_book holds the PHP value of customers' USD at the trade rate.
        $memo = 'USD ' . fx_usd($usdCents) . ' @ ' . $q['rate'];
        ledger_post($pdo, $reference, $side === 'buy_usd' ? 'fx_buy_usd' : 'fx_sell_usd', $side === 'buy_usd' ? [
            ['user_cash:' . $userId, $q['total'], 0, 'Bought ' . $memo],
            ['fx_usd_book', 0, $q['gross'], $memo],
            ['fx_fee_revenue', 0, $q['fee'], 'Buy USD fee ' . fx_percent($q['fee_bp'])],
        ] : [
            ['fx_usd_book', $q['gross'], 0, $memo],
            ['user_cash:' . $userId, 0, $q['total'], 'Sold ' . $memo],
            ['fx_fee_revenue', 0, $q['fee'], 'Sell USD fee ' . fx_percent($q['fee_bp'])],
        ]);
        $pdo->commit();
        return $reference;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function fx_history(PDO $pdo, int $userId, int $limit = 20): array
{
    if (!fx_ready($pdo)) return [];
    $stmt = $pdo->prepare('SELECT * FROM fx_trades WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}
