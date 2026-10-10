<?php
declare(strict_types=1);
// CLI only. Run against a COPY of the database after importing database/fx_migration.sql and
// database/fx_currencies_migration.sql, with the fake rate server running:
//   php -S 127.0.0.1:8097 tests/fx_fake_api.php
//   DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tests/fx_test.php
// Checks exact math, multi-currency rates and fallback, bad-rate protection, fees, limits, buy/sell,
// per-currency wallets, books and replay safety. Deletes everything it creates.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$check = static function (bool $ok, string $msg): void { if (!$ok) throw new RuntimeException('FAILED: ' . $msg); echo "PASS: $msg\n"; };
$throws = static function (callable $fn, string $needle, string $msg, string $class = InvalidArgumentException::class) use ($check): void {
    try { $fn(); } catch (Throwable $e) { $check($e instanceof $class && str_contains($e->getMessage(), $needle), $msg . ' (' . $e->getMessage() . ')'); return; }
    $check(false, $msg . ' — expected an error');
};
$pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4', getenv('DB_USER') ?: '', getenv('DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("SET time_zone = '+08:00'");
date_default_timezone_set('Asia/Manila');
$GLOBALS['pdo'] = $pdo;
require __DIR__ . '/../includes/rate_limit.php';
require __DIR__ . '/../includes/settings.php';
require __DIR__ . '/../includes/fx.php';
$fake = getenv('FX_FAKE') ?: 'http://127.0.0.1:8097';
$keys = ['fx.enabled', 'fx.provider', 'fx.manual_rate', 'fx.buy_fee_bp', 'fx.sell_fee_bp', 'fx.other_buy_fee_bp', 'fx.other_sell_fee_bp', 'fx.currencies', 'fx.min_usd_cents', 'fx.max_usd_cents', 'fx.refresh_minutes', 'fx.max_age_hours', 'kyc.required_user'];
$saved = [];
foreach ($keys as $k) $saved[$k] = $pdo->query('SELECT setting_value FROM app_settings WHERE setting_key = ' . $pdo->quote($k))->fetchColumn();
$rateIds = (int) $pdo->query('SELECT COALESCE(MAX(id),0) FROM fx_rates')->fetchColumn();
$users = [];
$key = static fn(): string => bin2hex(random_bytes(32));
$src = static function (string $frank, string $curr) use ($fake): void { putenv('FX_FRANKFURTER_URL=' . $fake . $frank); putenv('FX_CURRENCYAPI_URL=' . $fake . $curr); };
$view = static fn(string $c): array => fx_current($pdo, $c, false);
try {
    foreach (['fx.enabled' => '1', 'fx.provider' => 'auto', 'fx.buy_fee_bp' => '50', 'fx.sell_fee_bp' => '100', 'fx.other_buy_fee_bp' => '100', 'fx.other_sell_fee_bp' => '100',
        'fx.currencies' => 'USD,EUR,JPY,KWD,VND,AED,XOF', 'fx.min_usd_cents' => '100', 'fx.max_usd_cents' => '100000', 'fx.refresh_minutes' => '60', 'fx.max_age_hours' => '24', 'kyc.required_user' => '0'] as $k => $v) setting_save($pdo, $k, $v, null);
    $pdo->exec("DELETE FROM fx_rates WHERE id > $rateIds");

    // ---------- exact math
    $check(fx_muldiv(7, 3, 2) === 10 && fx_muldiv(7, 3, 2, true) === 11 && fx_muldiv(0, 5, 3) === 0, 'muldiv floor/ceil on small numbers');
    $check(fx_muldiv(9000000000000000, 9000000000, 100000000000) === 810000000000000 && fx_muldiv(123456789012345, 987654321098, 1000000000, true) === 121932631136926627, 'muldiv exact when the product exceeds 64 bits');
    $check(fx_rate_scaled('58.25') === 582500000000 && fx_scaled_to_rate(23300000) === '0.0023300000' && fx_rate_scaled('0.0022933071') === 22933071, 'rates are 10-decimal integers');
    $check(fx_amount(123456, 'JPY') === '123,456' && fx_amount(123456, 'USD') === '1,234.56' && fx_amount(1500, 'KWD') === '1.500', 'amounts follow each currency\'s decimals');
    $check(fx_parse_amount('1,000', 'JPY') === 1000 && fx_parse_amount('2.5', 'KWD') === 2500 && fx_parse_amount('10.05', 'EUR') === 1005, 'amount parsing per currency');
    $throws(fn() => fx_parse_amount('100.5', 'JPY'), 'whole JPY', 'decimals refused for a zero-decimal currency');
    $check(fx_parse_currency_list('eur,BTC,XXX,jpy')[0] === 'USD' && !in_array('BTC', fx_parse_currency_list('BTC'), true), 'USD always on and first; unknown codes and crypto ignored');

    // ---------- rates
    $src('/frankfurter/58.25', '/currency/58.25');
    $res = fx_refresh($pdo);
    $r = $res['rates'];
    $check(($r['USD']['source'] ?? '') === 'frankfurter' && ($r['EUR']['source'] ?? '') === 'frankfurter' && ($r['JPY']['source'] ?? '') === 'frankfurter', 'major currencies from Frankfurter');
    $check(($r['KWD']['source'] ?? '') === 'currency-api' && ($r['VND']['source'] ?? '') === 'currency-api' && ($r['AED']['source'] ?? '') === 'currency-api', 'currencies Frankfurter lacks come from currency-api');
    $check(fx_rate_scaled($r['EUR']['rate']) === fx_rate_scaled('72.8125') && fx_rate_scaled($r['KWD']['rate']) === fx_rate_scaled('186.4') && fx_rate_scaled($r['VND']['rate']) === fx_rate_scaled('0.00233'), 'cross rates via USD: EUR 72.8125, KWD 186.40, VND 0.00233');
    $check(!isset($r['XOF']) && str_contains(implode(' ', $res['errors']), 'XOF'), 'a currency no source has is skipped, others still saved');
    $again = fx_refresh($pdo)['rates'];
    $check((int) $again['EUR']['id'] === (int) $r['EUR']['id'], 'same rate again keeps the same rate id');
    $src('/down', '/currency/58.40');
    $r2 = fx_refresh($pdo)['rates'];
    $check($r2['USD']['source'] === 'currency-api' && $r2['EUR']['source'] === 'currency-api' && fx_rate_scaled($r2['USD']['rate']) === fx_rate_scaled('58.4'), 'Frankfurter down: everything from currency-api');
    $src('/frankfurter/58.40/eur/0.5', '/currency/58.40');
    $res3 = fx_refresh($pdo);
    $check(!isset($res3['rates']['EUR']) && isset($res3['rates']['USD']) && str_contains(implode(' ', $res3['errors']), 'EUR: rate'), 'one currency jumping 60% is refused; the others update');
    $src('/frankfurter/5.84', '/currency/5.84');
    $throws(fn() => fx_refresh($pdo), 'Could not get', 'impossible USD rate refused and nothing else saved', RuntimeException::class);

    // ---------- quotes (USD 58.40 buy 0.5% / sell 1%; others 1%)
    $src('/frankfurter/58.40', '/currency/58.40');
    fx_refresh($pdo);
    $usd = $view('USD');
    $q = fx_quote($usd, 'buy', 1000);
    $check($q['gross'] === 58400 && $q['fee'] === 292 && $q['total'] === 58692, 'buy USD 10.00: PHP 584.00 + 0.5% = 586.92');
    $q = fx_quote($usd, 'sell', 1000);
    $check($q['total'] === 57816, 'sell USD 10.00: PHP 584.00 - 1% = 578.16');
    $eur = $view('EUR');
    $q = fx_quote($eur, 'buy', 1000);
    $check($q['gross'] === 73000 && $q['fee'] === 730 && $q['total'] === 73730, 'buy EUR 10.00 at 73.00: PHP 730.00 + 1% = 737.30');
    $kwd = $view('KWD');
    $q = fx_quote($kwd, 'sell', 1500);
    $check($q['gross'] === 28032 && $q['fee'] === 281 && $q['total'] === 27751, 'sell KWD 1.500 at 186.88: PHP 280.32 - 1% (2.81) = 277.51');
    $vnd = $view('VND');
    $q = fx_quote($vnd, 'buy', 100000);
    $check($q['gross'] === 23360 && $q['total'] === 23594, 'buy VND 100,000 at 0.002336: PHP 233.60 + 1% = 235.94');
    $jpy = $view('JPY');
    [$jmin, $jmax] = fx_limits($pdo, $jpy);
    $check($jmin === 146 && $jmax === 146000, 'limits follow USD 1 - 1,000: JPY 146 - 146,000');
    [$emin, $emax] = fx_limits($pdo, $eur);
    $check($emin === 80 && $emax === 80000, 'limits for EUR: 0.80 - 800.00');

    // ---------- trades
    $pdo->prepare("INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES ('Fx Test', ?, ?, 'x', ?, 0)")->execute(['0918' . random_int(1000000, 9999999), 'fx' . bin2hex(random_bytes(4)) . '@test.local', 'UA-' . strtoupper(bin2hex(random_bytes(5)))]);
    $users[] = $u = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO boracay_cash_wallets (user_id, balance_centavos) VALUES (?, 200000)')->execute([$u]);
    $cash = static fn(): int => (int) $pdo->query("SELECT balance_centavos FROM boracay_cash_wallets WHERE user_id = $u")->fetchColumn();
    $k = $key();
    $ref = fx_trade($pdo, $u, 'EUR', 'buy', 1000, $eur['id'], $k);
    $check($cash() === 200000 - 73730 && fx_wallet_balance($pdo, $u, 'EUR') === 1000, 'bought EUR 10.00 for PHP 737.30 BCash');
    $check(fx_trade($pdo, $u, 'EUR', 'buy', 1000, $eur['id'], $k) === $ref && fx_wallet_balance($pdo, $u, 'EUR') === 1000, 'same request key: no double exchange');
    $sum = $pdo->prepare("SELECT SUM(debit_centavos) d, SUM(credit_centavos) c, SUM(CASE WHEN account = 'fx_book:eur' THEN credit_centavos END) book, SUM(CASE WHEN account = 'fx_fee_revenue' THEN credit_centavos END) fee FROM ledger_entries WHERE entry_group = ?");
    $sum->execute([$ref]); $l = $sum->fetch();
    $check((int) $l['d'] === 73730 && (int) $l['c'] === 73730 && (int) $l['book'] === 73000 && (int) $l['fee'] === 730, 'books balanced; EUR held in fx_book:eur, fee as revenue');
    fx_trade($pdo, $u, 'USD', 'buy', 2000, $usd['id'], $key());
    $check(fx_wallets($pdo, $u) === ['USD' => 2000, 'EUR' => 1000], 'separate wallet per currency, USD listed first');
    $throws(fn() => fx_trade($pdo, $u, 'EUR', 'sell', 1001, $eur['id'], $key()), 'Not enough EUR', 'selling more EUR than held is refused');
    $throws(fn() => fx_trade($pdo, $u, 'JPY', 'buy', 100, $jpy['id'], $key()), 'Enter from JPY 146', 'below the converted minimum refused');
    $throws(fn() => fx_trade($pdo, $u, 'EUR', 'sell', 500, $usd['id'], $key()), 'rate just changed', 'a rate id from another currency is refused', FxRateChanged::class);
    $ref2 = fx_trade($pdo, $u, 'EUR', 'sell', 500, $eur['id'], $key());
    $t = $pdo->query('SELECT * FROM fx_trades WHERE reference = ' . $pdo->quote($ref2))->fetch();
    $check($t['currency'] === 'EUR' && $t['side'] === 'sell' && (int) $t['amount_minor'] === 500 && (int) $t['total_php_centavos'] === 36135 && fx_wallet_balance($pdo, $u, 'EUR') === 500, 'sold EUR 5.00: got PHP 361.35; trade row records currency');

    // ---------- offered list / closed / stale / manual / KYC
    setting_save($pdo, 'fx.currencies', 'USD,JPY', null);
    $check(!$view('EUR')['tradable'] && str_contains($view('EUR')['reason'], 'no longer offered'), 'currency removed in Admin: no longer tradable');
    $check($view('EUR')['sell_only'], 'removed currency held by customers: sell only');
    $throws(fn() => fx_trade($pdo, $u, 'EUR', 'buy', 100, $eur['id'], $key()), 'can only be sold', 'buying a removed currency is refused');
    fx_trade($pdo, $u, 'EUR', 'sell', 100, $eur['id'], $key());
    $check(fx_wallet_balance($pdo, $u, 'EUR') === 400, 'customers can still sell a removed currency they hold');
    $check(isset(fx_refresh($pdo)['rates']['EUR']), 'rates keep updating for a removed currency while someone holds it');
    setting_save($pdo, 'fx.currencies', 'USD,EUR,JPY,KWD,VND,AED', null);
    setting_save($pdo, 'fx.enabled', '0', null);
    $throws(fn() => fx_trade($pdo, $u, 'USD', 'sell', 100, $usd['id'], $key()), 'closed', 'exchange closed in Admin: refused');
    setting_save($pdo, 'fx.enabled', '1', null);
    $pdo->exec('UPDATE fx_rates SET fetched_at = NOW() - INTERVAL 30 HOUR WHERE id = ' . (int) $usd['id']);
    $check(!$view('USD')['tradable'], 'rate older than 24 h: exchanges pause');
    $pdo->exec('UPDATE fx_rates SET fetched_at = NOW() WHERE id = ' . (int) $usd['id']);
    setting_save($pdo, 'fx.provider', 'manual', null);
    setting_save($pdo, 'fx.manual_rate', '59.10', null);
    $m = fx_current($pdo, 'USD');
    $check($m['source'] === 'manual' && $m['scaled'] === fx_rate_scaled('59.10') && $m['tradable'] && $view('EUR')['source'] !== 'manual', 'manual USD rate from Admin; other currencies stay automatic');
    setting_save($pdo, 'fx.provider', 'auto', null);
    setting_save($pdo, 'kyc.required_user', '1', null);
    require_once __DIR__ . '/../includes/kyc.php';
    $throws(fn() => fx_trade($pdo, $u, 'USD', 'sell', 100, $m['id'], $key()), 'verify your identity', 'customer KYC rule on: unverified cannot exchange');
    echo "\nAll currency exchange tests passed.\n";
} finally {
    foreach ($saved as $k => $v) { if ($v === false) setting_clear($pdo, $k); else setting_save($pdo, $k, (string) $v, null); }
    $ids = $users ? implode(',', $users) : '0';
    $pdo->exec("DELETE FROM ledger_entries WHERE entry_group IN (SELECT reference FROM fx_trades WHERE user_id IN ($ids))");
    $pdo->exec("DELETE FROM fx_trades WHERE user_id IN ($ids)");
    $pdo->exec("DELETE FROM fx_wallets WHERE user_id IN ($ids)");
    $pdo->exec("DELETE FROM boracay_cash_wallets WHERE user_id IN ($ids)");
    $pdo->exec("DELETE FROM users WHERE id IN ($ids)");
    $pdo->exec("DELETE FROM fx_rates WHERE id > $rateIds");
}
