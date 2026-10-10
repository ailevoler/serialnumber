<?php
declare(strict_types=1);

require_once __DIR__ . '/ledger.php';
require_once __DIR__ . '/currencies.php';

/**
 * Currency exchange against BCash (PHP). The main pair is USD/PHP; Admin can offer more currencies.
 * Customers buy a currency with BCash and sell it back to BCash. Mid-market rates come from free,
 * open-source APIs (Frankfurter, fawazahmed0 currency-api) or, for USD, a manual rate set in Admin.
 * The fee (Admin, basis points; one fee for USD, one for other currencies) is shown in PHP:
 * added on top when buying, deducted when selling.
 * All amounts are integers: PHP in centavos, other currencies in their minor unit (cents, yen, fils),
 * rates as PHP per 1 unit scaled by 10^10 (58.25 = 582500000000), so small currencies stay exact.
 */
final class FxRateChanged extends InvalidArgumentException {}

const FX_SIDES = ['buy' => 'Buy', 'sell' => 'Sell'];
const FX_SCALE = 10000000000;  // rate decimals: 10
const FX_USD_SANE_MIN = 20;    // PHP per USD; an automatic USD rate outside 20..200 is bad data
const FX_USD_SANE_MAX = 200;
const FX_MAX_JUMP = 0.10;      // an automatic rate more than 10% away from the last one is refused

function fx_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            foreach (['fx_trades', 'fx_wallets', 'fx_rates'] as $t) $pdo->query("SELECT 1 FROM $t LIMIT 0");
            $pdo->query('SELECT currency, amount_minor FROM fx_trades LIMIT 0');
            $ready = true;
        } catch (Throwable) { $ready = false; }
    }
    return $ready;
}

/** Enabled currencies, USD first, then the popular ones, then A-Z. */
function fx_parse_currency_list(string $list): array
{
    $catalog = fx_currency_catalog();
    $codes = array_values(array_unique(array_filter(array_map('trim', explode(',', strtoupper($list))), static fn($c) => isset($catalog[$c]))));
    if (!in_array(FX_MAIN, $codes, true)) array_unshift($codes, FX_MAIN);
    $rank = array_flip(FX_POPULAR);
    usort($codes, static fn($a, $b) => [$rank[$a] ?? 999, $a] <=> [$rank[$b] ?? 999, $b]);
    return $codes;
}

function fx_config(): array
{
    return [
        'enabled' => (string) setting('fx.enabled', '0') === '1',
        'provider' => (string) setting('fx.provider', 'auto'),
        'manual_rate' => (string) setting('fx.manual_rate', ''),
        'buy_fee_bp' => (int) setting('fx.buy_fee_bp', 50),
        'sell_fee_bp' => (int) setting('fx.sell_fee_bp', 50),
        'other_buy_fee_bp' => (int) setting('fx.other_buy_fee_bp', 100),
        'other_sell_fee_bp' => (int) setting('fx.other_sell_fee_bp', 100),
        'currencies' => fx_parse_currency_list((string) setting('fx.currencies', implode(',', FX_POPULAR))),
        'min_usd_cents' => (int) setting('fx.min_usd_cents', 100),
        'max_usd_cents' => (int) setting('fx.max_usd_cents', 100000),
        'refresh_minutes' => (int) setting('fx.refresh_minutes', 60),
        'max_age_hours' => (int) setting('fx.max_age_hours', 24),
    ];
}

function fx_fee_bp(array $cfg, string $ccy, string $side): int
{
    $main = $ccy === FX_MAIN;
    return $side === 'buy' ? ($main ? $cfg['buy_fee_bp'] : $cfg['other_buy_fee_bp']) : ($main ? $cfg['sell_fee_bp'] : $cfg['other_sell_fee_bp']);
}

// ---------------------------------------------------------------- exact integer math

/** floor or ceil of a*b/d for non-negative ints, exact even when a*b does not fit in 64 bits. */
function fx_muldiv(int $a, int $b, int $d, bool $ceil = false): int
{
    if ($a < 0 || $b < 0 || $d <= 0) throw new InvalidArgumentException('fx_muldiv expects non-negative values.');
    // Product in base 10^4 limbs (little-endian), then long division by $d (< 9.2e14).
    $al = []; for ($x = $a; $x > 0; $x = intdiv($x, 10000)) $al[] = $x % 10000;
    $bl = []; for ($x = $b; $x > 0; $x = intdiv($x, 10000)) $bl[] = $x % 10000;
    if (!$al || !$bl) return 0;
    $p = array_fill(0, count($al) + count($bl) + 1, 0);
    foreach ($al as $i => $x) {
        $carry = 0;
        foreach ($bl as $j => $y) { $t = $p[$i + $j] + $x * $y + $carry; $p[$i + $j] = $t % 10000; $carry = intdiv($t, 10000); }
        for ($k = $i + count($bl); $carry > 0; $k++) { $t = $p[$k] + $carry; $p[$k] = $t % 10000; $carry = intdiv($t, 10000); }
    }
    if ($d > 900000000000000) throw new InvalidArgumentException('fx_muldiv divisor too large.');
    $q = 0; $r = 0;
    for ($i = count($p) - 1; $i >= 0; $i--) {
        $cur = $r * 10000 + $p[$i];
        $digit = intdiv($cur, $d);
        $r = $cur % $d;
        if ($digit > 0 && $q > intdiv(PHP_INT_MAX - $digit, 10000)) throw new OverflowException('Amount too large.');
        $q = $q * 10000 + $digit;
    }
    return $ceil && $r > 0 ? $q + 1 : $q;
}

/** "58.25" -> 582500000000 (PHP per unit x 10^10). */
function fx_rate_scaled(string $rate): int
{
    if (!preg_match('/^(\d{1,6})(?:\.(\d{1,14}))?$/D', trim($rate), $m)) throw new InvalidArgumentException('Invalid exchange rate.');
    return (int) $m[1] * FX_SCALE + (int) substr(str_pad($m[2] ?? '', 10, '0'), 0, 10);
}

function fx_scaled_to_rate(int $scaled): string
{
    return intdiv($scaled, FX_SCALE) . '.' . str_pad((string) ($scaled % FX_SCALE), 10, '0', STR_PAD_LEFT);
}

/** Readable rate: 4 decimals normally, more for currencies worth under PHP 1 (KRW, IDR, VND). */
function fx_format_rate(int $scaled, ?int $decimals = null): string
{
    $decimals ??= $scaled >= FX_SCALE ? 4 : ($scaled >= FX_SCALE / 100 ? 6 : 8);
    $s = fx_scaled_to_rate($scaled);
    [$w, $f] = explode('.', $s);
    $f = substr($f, 0, $decimals);
    return number_format((int) $w) . ($decimals > 0 ? '.' . $f : '');
}

/** A float from a rate API -> scaled integer (via a fixed 10-decimal string, never float math downstream). */
function fx_float_to_scaled(float $rate): int
{
    return fx_rate_scaled(sprintf('%.10f', $rate));
}

/** 123456 minor units of JPY -> "123,456"; of USD -> "1,234.56". */
function fx_amount(int $minor, string $ccy): string
{
    $dec = fx_currency_decimals($ccy);
    $sign = $minor < 0 ? '-' : '';
    $minor = abs($minor);
    if ($dec === 0) return $sign . number_format($minor);
    $p = 10 ** $dec;
    return $sign . number_format(intdiv($minor, $p)) . '.' . str_pad((string) ($minor % $p), $dec, '0', STR_PAD_LEFT);
}

function fx_usd(int $cents): string
{
    return fx_amount($cents, 'USD');
}

function fx_percent(int $bp): string
{
    return rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.') . '%';
}

/** Parses an amount typed by the customer into minor units, respecting the currency's decimals. */
function fx_parse_amount(string $input, string $ccy): int
{
    $dec = fx_currency_decimals($ccy);
    $input = trim(str_replace(',', '', $input));
    $pattern = $dec === 0 ? '/^\d{1,12}$/D' : '/^\d{1,12}(?:\.\d{1,' . $dec . '})?$/D';
    if (!preg_match($pattern, $input)) {
        throw new InvalidArgumentException($dec === 0 ? 'Enter a whole ' . $ccy . ' amount (no decimals).' : 'Enter a ' . $ccy . ' amount with up to ' . $dec . ' decimal places.');
    }
    [$whole, $fraction] = array_pad(explode('.', $input, 2), 2, '');
    return (int) $whole * 10 ** $dec + (int) str_pad($fraction, $dec, '0');
}

/** Kept for the USD screens: parses a USD amount within the Admin limits. */
function fx_usd_amount(string $input, ?array $cfg = null): int
{
    $cfg ??= fx_config();
    $cents = fx_parse_amount($input, 'USD');
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
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'UltimateApp-FX/1.1']);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $status !== 200) throw new RuntimeException('Rate request failed (' . ($status ?: $error) . ').');
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) throw new RuntimeException('Rate reply is not JSON.');
    return $data;
}

/** Frankfurter (open source, European Central Bank reference rates, ~30 currencies, no key). Units per 1 USD. */
function fx_fetch_frankfurter(): array
{
    $url = getenv('FX_FRANKFURTER_URL') ?: 'https://api.frankfurter.dev/v1/latest?base=USD';
    $d = fx_http_json($url);
    if (($d['base'] ?? 'USD') !== 'USD' || !is_array($d['rates'] ?? null)) throw new RuntimeException('Frankfurter reply has no rates.');
    $per = ['USD' => 1.0];
    foreach ($d['rates'] as $code => $v) if (is_string($code) && preg_match('/^[A-Z]{3}$/', $code) && is_numeric($v) && $v > 0) $per[$code] = (float) $v;
    if (!isset($per['PHP'])) throw new RuntimeException('Frankfurter reply has no PHP rate.');
    return ['per_usd' => $per, 'date' => is_string($d['date'] ?? null) ? $d['date'] : null, 'source' => 'frankfurter'];
}

/** fawazahmed0/exchange-api (open source, 150+ currencies, daily, no key), with its mirror. Units per 1 USD. */
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
            if (!is_array($d['usd'] ?? null) || !is_numeric($d['usd']['php'] ?? null)) throw new RuntimeException('currency-api reply has no USD/PHP rate.');
            $per = ['USD' => 1.0];
            foreach ($d['usd'] as $code => $v) if (is_string($code) && preg_match('/^[a-z]{3}$/', $code) && is_numeric($v) && $v > 0) $per[strtoupper($code)] = (float) $v;
            return ['per_usd' => $per, 'date' => is_string($d['date'] ?? null) ? $d['date'] : null, 'source' => 'currency-api'];
        } catch (RuntimeException $e) {
            $last = $e;
        }
    }
    throw $last ?? new RuntimeException('currency-api unavailable.');
}

function fx_pair(string $ccy): string
{
    return $ccy . 'PHP';
}

function fx_latest_row(PDO $pdo, string $ccy = FX_MAIN): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM fx_rates WHERE pair = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([fx_pair($ccy)]);
    return $stmt->fetch() ?: null;
}

/** Latest row of every pair, keyed by currency. */
function fx_latest_rows(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT r.* FROM fx_rates r JOIN (SELECT MAX(id) id FROM fx_rates GROUP BY pair) m ON m.id = r.id') as $r) {
        $out[substr((string) $r['pair'], 0, 3)] = $r;
    }
    return $out;
}

/** Saves a rate. A repeat of the latest rate only refreshes its time, so the rate id (quote lock) stays the same. */
function fx_store_rate(PDO $pdo, string $ccy, int $scaled, string $source, ?string $date): array
{
    $date = $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) ? $date : null;
    $latest = fx_latest_row($pdo, $ccy);
    if ($latest && fx_rate_scaled((string) $latest['rate']) === $scaled && $latest['source'] === $source && ($latest['rate_date'] ?? null) === $date) {
        $pdo->prepare('UPDATE fx_rates SET fetched_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$latest['id']]);
    } else {
        $pdo->prepare('INSERT INTO fx_rates (pair, rate, source, rate_date) VALUES (?, ?, ?, ?)')->execute([fx_pair($ccy), fx_scaled_to_rate($scaled), $source, $date]);
    }
    return fx_latest_row($pdo, $ccy);
}

/**
 * Fetches fresh rates for every offered currency in one go (auto = Frankfurter, then currency-api for
 * anything Frankfurter does not cover or when it is down). Each cross rate (PHP per unit) comes from a
 * single source. Impossible values and sudden jumps are refused per currency, keeping the last good rate.
 * Returns ['rates' => [ccy => row], 'errors' => [string]]. Throws when no rate at all could be saved.
 */
function fx_refresh(PDO $pdo, ?array $cfg = null): array
{
    $cfg ??= fx_config();
    $fetchers = ['frankfurter' => 'fx_fetch_frankfurter', 'currency_api' => 'fx_fetch_currency_api'];
    $order = in_array($cfg['provider'], ['auto', 'manual'], true) ? array_keys($fetchers) : [$cfg['provider']];
    $feeds = []; $errors = []; $saved = [];
    // Offered currencies, plus any a customer still holds (so they can always sell it back).
    $held = $pdo->query('SELECT DISTINCT currency FROM fx_wallets WHERE balance_minor > 0')->fetchAll(PDO::FETCH_COLUMN);
    $want = array_values(array_unique(array_merge($cfg['currencies'], array_filter($held, static fn($c) => isset(fx_currency_catalog()[$c])))));
    if ($cfg['provider'] === 'manual') {
        if ($cfg['manual_rate'] === '') $errors[] = 'USD: enter the manual USD/PHP rate in Admin.';
        else $saved['USD'] = fx_store_rate($pdo, 'USD', fx_rate_scaled($cfg['manual_rate']), 'manual', date('Y-m-d'));
        $want = array_values(array_diff($want, ['USD']));
    }
    foreach ($order as $name) {
        $missing = array_filter($want, static function ($c) use ($feeds) { foreach ($feeds as $f) if (isset($f['per_usd'][$c])) return false; return true; });
        if (!$missing) break;
        try { $feeds[] = ($fetchers[$name])(); } catch (Throwable $e) { $errors[] = $name . ': ' . $e->getMessage(); }
    }
    foreach ($want as $ccy) {
        $feed = null;
        foreach ($feeds as $f) if (isset($f['per_usd'][$ccy], $f['per_usd']['PHP'])) { $feed = $f; break; }
        if (!$feed) { if ($feeds) $errors[] = $ccy . ': no source has this currency.'; continue; }
        try {
            $scaled = fx_float_to_scaled($feed['per_usd']['PHP'] / $feed['per_usd'][$ccy]);
            if ($scaled <= 0) throw new RuntimeException('rate is zero');
            if ($ccy === 'USD' && ($scaled < FX_USD_SANE_MIN * FX_SCALE || $scaled > FX_USD_SANE_MAX * FX_SCALE)) {
                throw new RuntimeException('rate ' . fx_format_rate($scaled) . ' is outside ' . FX_USD_SANE_MIN . '-' . FX_USD_SANE_MAX);
            }
            $previous = fx_latest_row($pdo, $ccy);
            if ($previous && $previous['source'] !== 'manual') {
                $prev = fx_rate_scaled((string) $previous['rate']);
                if (abs($scaled - $prev) > $prev * FX_MAX_JUMP) throw new RuntimeException('rate ' . fx_format_rate($scaled) . ' moved more than ' . (FX_MAX_JUMP * 100) . '% from ' . fx_format_rate($prev));
            }
            $saved[$ccy] = fx_store_rate($pdo, $ccy, $scaled, $feed['source'], $feed['date']);
        } catch (Throwable $e) {
            $errors[] = $ccy . ': ' . $e->getMessage();
        }
    }
    if (!$saved) throw new RuntimeException('Could not get exchange rates. ' . implode(' · ', $errors));
    if ($errors) error_log('FX refresh: ' . implode(' · ', $errors));
    return ['rates' => $saved, 'errors' => $errors];
}

/** Turns a stored rate row into what screens and trades use: fees, buy/sell rates and whether it can be traded. */
function fx_rate_view(array $row, array $cfg, string $ccy): array
{
    $scaled = fx_rate_scaled((string) $row['rate']);
    $age = max(0, intdiv(time() - strtotime((string) $row['fetched_at']), 60));
    $manualWanted = $ccy === FX_MAIN && $cfg['provider'] === 'manual';
    $reason = '';
    $offered = in_array($ccy, $cfg['currencies'], true);
    if (!$cfg['enabled']) $reason = 'Currency exchange is closed right now.';
    elseif ($manualWanted ? $row['source'] !== 'manual' : ($row['source'] === 'manual' || $age > $cfg['max_age_hours'] * 60)) $reason = 'The ' . $ccy . ' rate is being updated. Please try again in a few minutes.';
    $buyBp = fx_fee_bp($cfg, $ccy, 'buy'); $sellBp = fx_fee_bp($cfg, $ccy, 'sell');
    return [
        'id' => (int) $row['id'], 'ccy' => $ccy, 'decimals' => fx_currency_decimals($ccy), 'rate' => (string) $row['rate'], 'scaled' => $scaled,
        'source' => (string) $row['source'], 'rate_date' => $row['rate_date'], 'fetched_at' => (string) $row['fetched_at'], 'age_minutes' => $age,
        'buy_fee_bp' => $buyBp, 'sell_fee_bp' => $sellBp,
        'buy_scaled' => fx_muldiv($scaled, 10000 + $buyBp, 10000, true), 'sell_scaled' => fx_muldiv($scaled, 10000 - $sellBp, 10000),
        // A currency Admin stopped offering can still be sold back by customers who hold it, never bought.
        'tradable' => $reason === '' && $offered, 'sell_only' => $reason === '' && !$offered,
        'reason' => $reason !== '' ? $reason : ($offered ? '' : $ccy . ' is no longer offered. You can still sell what you hold.'),
    ];
}

/**
 * The rate customers trade $ccy at. Refreshes all rates when the USD rate (or this currency's) is older
 * than the Admin interval, unless $allowRefresh is false (e.g. on the home screen).
 */
function fx_current(PDO $pdo, string $ccy = FX_MAIN, bool $allowRefresh = true): ?array
{
    if (!fx_ready($pdo) || !isset(fx_currency_catalog()[$ccy])) return null;
    $cfg = fx_config();
    $row = fx_latest_row($pdo, $ccy);
    $isStale = static function (?array $r, string $c) use ($cfg): bool {
        if (!$r) return true;
        if ((time() - strtotime((string) $r['fetched_at'])) > $cfg['refresh_minutes'] * 60) return true;
        $manual = $c === FX_MAIN && $cfg['provider'] === 'manual';
        if ($manual) return $r['source'] !== 'manual' || ($cfg['manual_rate'] !== '' && fx_rate_scaled((string) $r['rate']) !== fx_rate_scaled($cfg['manual_rate']));
        return $r['source'] === 'manual';
    };
    if ($allowRefresh && ($isStale($row, $ccy) || ($ccy !== FX_MAIN && $isStale(fx_latest_row($pdo, FX_MAIN), FX_MAIN)))) {
        $locked = (int) $pdo->query("SELECT GET_LOCK('ua_fx_refresh', 0)")->fetchColumn() === 1;
        if ($locked) {
            try { fx_refresh($pdo, $cfg); } catch (Throwable $e) { error_log('FX refresh failed: ' . $e->getMessage()); }
            finally { $pdo->query("SELECT RELEASE_LOCK('ua_fx_refresh')"); }
            $row = fx_latest_row($pdo, $ccy);
        }
    }
    return $row ? fx_rate_view($row, $cfg, $ccy) : null;
}

/** Current view of every offered currency that has a rate, in display order. */
function fx_board(PDO $pdo, bool $allowRefresh = false): array
{
    if (!fx_ready($pdo)) return [];
    if ($allowRefresh) fx_current($pdo, FX_MAIN, true);
    $cfg = fx_config();
    $rows = fx_latest_rows($pdo);
    $out = [];
    foreach ($cfg['currencies'] as $ccy) if (isset($rows[$ccy])) $out[$ccy] = fx_rate_view($rows[$ccy], $cfg, $ccy);
    return $out;
}

function fx_source_label(string $source): string
{
    return ['frankfurter' => 'Frankfurter (ECB reference rate)', 'currency-api' => 'currency-api (fawazahmed0)', 'manual' => 'Manual rate (Admin)'][$source] ?? $source;
}

/** PHP value of an amount at the mid rate. Buying rounds up, selling rounds down (house favour, at most one centavo). */
function fx_php_value(int $amountMinor, int $scaled, string $ccy, bool $ceil): int
{
    return fx_muldiv($amountMinor, $scaled, 10 ** fx_currency_decimals($ccy) * (FX_SCALE / 100), $ceil);
}

/** Per-exchange limits, set in USD in Admin, expressed in this currency's minor units. */
function fx_limits(PDO $pdo, array $rate, ?array $cfg = null): array
{
    $cfg ??= fx_config();
    if ($rate['ccy'] === FX_MAIN) return [$cfg['min_usd_cents'], $cfg['max_usd_cents']];
    $usd = fx_latest_row($pdo, FX_MAIN);
    if (!$usd) throw new InvalidArgumentException('The USD rate is being updated. Please try again in a few minutes.');
    $usdScaled = fx_rate_scaled((string) $usd['rate']);
    $toMinor = static fn(int $usdCents, bool $ceil): int => fx_muldiv(fx_php_value($usdCents, $usdScaled, 'USD', $ceil), 10 ** $rate['decimals'] * (FX_SCALE / 100), $rate['scaled'], $ceil);
    return [max(1, $toMinor($cfg['min_usd_cents'], true)), max(1, $toMinor($cfg['max_usd_cents'], false))];
}

/**
 * Exact amounts for an exchange. buy: customer pays total = gross + fee in BCash.
 * sell: customer gets total = gross - fee in BCash.
 */
function fx_quote(array $rate, string $side, int $amountMinor): array
{
    if (!isset(FX_SIDES[$side])) throw new InvalidArgumentException('Choose Buy or Sell.');
    if ($amountMinor <= 0) throw new InvalidArgumentException('Enter an amount.');
    $bp = $side === 'buy' ? (int) $rate['buy_fee_bp'] : (int) $rate['sell_fee_bp'];
    $gross = fx_php_value($amountMinor, (int) $rate['scaled'], $rate['ccy'], $side === 'buy');
    $fee = fx_muldiv($gross, $bp, 10000, true);
    $total = $side === 'buy' ? $gross + $fee : $gross - $fee;
    if ($total <= 0) throw new InvalidArgumentException('That amount is too small to exchange.');
    return ['ccy' => $rate['ccy'], 'side' => $side, 'amount_minor' => $amountMinor, 'gross' => $gross, 'fee' => $fee, 'total' => $total, 'fee_bp' => $bp, 'rate' => fx_scaled_to_rate((int) $rate['scaled']), 'rate_id' => (int) $rate['id']];
}

function fx_wallet_balance(PDO $pdo, int $userId, string $ccy): int
{
    if (!fx_ready($pdo)) return 0;
    $stmt = $pdo->prepare('SELECT balance_minor FROM fx_wallets WHERE user_id = ? AND currency = ?');
    $stmt->execute([$userId, $ccy]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

function fx_usd_balance(PDO $pdo, int $userId): int
{
    return fx_wallet_balance($pdo, $userId, 'USD');
}

/** The customer's foreign-currency wallets with a balance, keyed by currency. */
function fx_wallets(PDO $pdo, int $userId): array
{
    if (!fx_ready($pdo)) return [];
    $stmt = $pdo->prepare('SELECT currency, balance_minor FROM fx_wallets WHERE user_id = ? AND balance_minor > 0');
    $stmt->execute([$userId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) $out[$r['currency']] = (int) $r['balance_minor'];
    $rank = array_flip(FX_POPULAR);
    uksort($out, static fn($a, $b) => [$rank[$a] ?? 999, $a] <=> [$rank[$b] ?? 999, $b]);
    return $out;
}

function fx_book_account(string $ccy): string
{
    return $ccy === 'USD' ? 'fx_usd_book' : 'fx_book:' . strtolower($ccy);
}

/**
 * Buys or sells $ccy against the customer's BCash at the rate they saw ($rateId).
 * If the rate changed since, nothing moves and FxRateChanged asks them to check the new amount.
 * Idempotent per (customer, request key).
 */
function fx_trade(PDO $pdo, int $userId, string $ccy, string $side, int $amountMinor, int $rateId, string $requestKey): string
{
    if (!isset(FX_SIDES[$side]) || !isset(fx_currency_catalog()[$ccy]) || !preg_match('/^[a-f0-9]{64}$/D', $requestKey)) throw new InvalidArgumentException('Invalid exchange request.');
    require_once __DIR__ . '/kyc.php';
    kyc_require_verified($pdo, 'user', $userId, 'exchanging currency');
    $cfg = fx_config();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, status FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || ($user['status'] ?? 'active') !== 'active') throw new InvalidArgumentException('Your account cannot exchange right now.');

        $stmt = $pdo->prepare('SELECT reference FROM fx_trades WHERE user_id = ? AND request_key = ?');
        $stmt->execute([$userId, $requestKey]);
        if ($existing = $stmt->fetch()) { $pdo->commit(); return $existing['reference']; }

        $rate = fx_current($pdo, $ccy, false);
        if (!$rate || !($rate['tradable'] || ($rate['sell_only'] && $side === 'sell'))) {
            throw new InvalidArgumentException($rate && $rate['sell_only'] ? $ccy . ' is no longer offered, so it can only be sold.' : ($rate['reason'] ?? 'Exchange for ' . $ccy . ' is not available right now.'));
        }
        if ($rate['id'] !== $rateId) throw new FxRateChanged('The ' . $ccy . ' rate just changed. Check the new amount, then confirm again.');
        [$min, $max] = fx_limits($pdo, $rate, $cfg);
        if ($amountMinor < $min || $amountMinor > $max) throw new InvalidArgumentException('Enter from ' . $ccy . ' ' . fx_amount($min, $ccy) . ' to ' . $ccy . ' ' . fx_amount($max, $ccy) . ' per exchange.');
        $q = fx_quote($rate, $side, $amountMinor);

        $pdo->prepare('INSERT IGNORE INTO boracay_cash_wallets (user_id) VALUES (?)')->execute([$userId]);
        $pdo->prepare('INSERT IGNORE INTO fx_wallets (user_id, currency) VALUES (?, ?)')->execute([$userId, $ccy]);
        if ($side === 'buy') {
            $stmt = $pdo->prepare('UPDATE boracay_cash_wallets SET balance_centavos = balance_centavos - ? WHERE user_id = ? AND balance_centavos >= ?');
            $stmt->execute([$q['total'], $userId, $q['total']]);
            if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Not enough BCash. You need PHP ' . peso($q['total']) . ' including the fee.');
            $pdo->prepare('UPDATE fx_wallets SET balance_minor = balance_minor + ? WHERE user_id = ? AND currency = ?')->execute([$amountMinor, $userId, $ccy]);
        } else {
            $stmt = $pdo->prepare('UPDATE fx_wallets SET balance_minor = balance_minor - ? WHERE user_id = ? AND currency = ? AND balance_minor >= ?');
            $stmt->execute([$amountMinor, $userId, $ccy, $amountMinor]);
            if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Not enough ' . $ccy . ' in your wallet.');
            $pdo->prepare('UPDATE boracay_cash_wallets SET balance_centavos = balance_centavos + ? WHERE user_id = ?')->execute([$q['total'], $userId]);
        }

        $reference = 'FX-' . strtoupper(bin2hex(random_bytes(8)));
        $pdo->prepare('INSERT INTO fx_trades (reference, request_key, user_id, side, currency, amount_minor, gross_php_centavos, fee_php_centavos, total_php_centavos, fee_bp, rate, rate_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$reference, $requestKey, $userId, $side, $ccy, $amountMinor, $q['gross'], $q['fee'], $q['total'], $q['fee_bp'], $q['rate'], $q['rate_id']]);
        // Books are in PHP. The fx book account holds the PHP value of customers' currency at the trade rate.
        $memo = $ccy . ' ' . fx_amount($amountMinor, $ccy) . ' @ ' . fx_format_rate((int) $rate['scaled']);
        $book = fx_book_account($ccy);
        ledger_post($pdo, $reference, $side === 'buy' ? 'fx_buy' : 'fx_sell', $side === 'buy' ? [
            ['user_cash:' . $userId, $q['total'], 0, 'Bought ' . $memo],
            [$book, 0, $q['gross'], $memo],
            ['fx_fee_revenue', 0, $q['fee'], 'Buy ' . $ccy . ' fee ' . fx_percent($q['fee_bp'])],
        ] : [
            [$book, $q['gross'], 0, $memo],
            ['user_cash:' . $userId, 0, $q['total'], 'Sold ' . $memo],
            ['fx_fee_revenue', 0, $q['fee'], 'Sell ' . $ccy . ' fee ' . fx_percent($q['fee_bp'])],
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
