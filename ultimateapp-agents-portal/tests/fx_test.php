<?php
declare(strict_types=1);
// CLI only. Run against a COPY of the database after importing database/fx_migration.sql,
// with the fake rate server running:  php -S 127.0.0.1:8097 tests/fx_fake_api.php
//   DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tests/fx_test.php
// Checks rate fetching and fallback, bad-rate protection, fee math, buy/sell, books and replay safety.
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
$keys = ['fx.enabled', 'fx.provider', 'fx.manual_rate', 'fx.buy_fee_bp', 'fx.sell_fee_bp', 'fx.min_usd_cents', 'fx.max_usd_cents', 'fx.refresh_minutes', 'fx.max_age_hours', 'kyc.required_user'];
$saved = [];
foreach ($keys as $k) $saved[$k] = $pdo->query('SELECT setting_value FROM app_settings WHERE setting_key = ' . $pdo->quote($k))->fetchColumn();
$rateIds = $pdo->query('SELECT COALESCE(MAX(id),0) FROM fx_rates')->fetchColumn();
$users = [];
$key = static fn(): string => bin2hex(random_bytes(32));
$src = static function (string $frank, string $curr) use ($fake): void { putenv('FX_FRANKFURTER_URL=' . $fake . $frank); putenv('FX_CURRENCYAPI_URL=' . $fake . $curr); };
try {
    foreach (['fx.enabled' => '1', 'fx.provider' => 'auto', 'fx.buy_fee_bp' => '50', 'fx.sell_fee_bp' => '100', 'fx.min_usd_cents' => '100', 'fx.max_usd_cents' => '100000', 'fx.refresh_minutes' => '60', 'fx.max_age_hours' => '24', 'kyc.required_user' => '0'] as $k => $v) setting_save($pdo, $k, $v, null);
    $pdo->exec("DELETE FROM fx_rates WHERE id > $rateIds");

    // ---------- rates
    $check(fx_rate_micro('58.25') === 58250000 && fx_micro_to_rate(58250000) === '58.250000' && fx_rate_micro('57.1234567') === 57123456, 'rate parsing is exact (no floats)');
    $src('/frankfurter/58.25', '/currency/58.30');
    $row = fx_refresh($pdo);
    $check($row['source'] === 'frankfurter' && $row['rate'] === '58.250000' && $row['rate_date'] === '2026-10-09', 'Frankfurter rate stored');
    $again = fx_refresh($pdo);
    $check((int) $again['id'] === (int) $row['id'], 'same rate again keeps the same rate id');
    $src('/down', '/currency/58.40');
    $row2 = fx_refresh($pdo);
    $check($row2['source'] === 'currency-api' && $row2['rate'] === '58.400000', 'Frankfurter down: falls back to currency-api');
    $src('/frankfurter/5.84', '/currency/5.84');
    $throws(fn() => fx_refresh($pdo), 'outside', 'impossible rate refused', RuntimeException::class);
    $src('/frankfurter/70', '/down');
    $throws(fn() => fx_refresh($pdo), 'moved more than', 'a sudden 20% jump is refused', RuntimeException::class);
    $check((int) fx_latest_row($pdo)['id'] === (int) $row2['id'], 'refused rates change nothing');

    // ---------- quotes (rate 58.40, buy fee 0.50%, sell fee 1.00%)
    $rate = fx_current($pdo, false);
    $check($rate['tradable'] && $rate['buy_micro'] === 58692000 && $rate['sell_micro'] === 57816000, 'buy/sell rates include the Admin fees');
    $q = fx_quote($rate, 'buy_usd', 1000);
    $check($q['gross'] === 58400 && $q['fee'] === 292 && $q['total'] === 58692, 'buy USD 10.00: PHP 584.00 + 0.5% fee 2.92 = 586.92');
    $q = fx_quote($rate, 'sell_usd', 1000);
    $check($q['gross'] === 58400 && $q['fee'] === 584 && $q['total'] === 57816, 'sell USD 10.00: PHP 584.00 - 1% fee 5.84 = 578.16');
    $q = fx_quote($rate, 'buy_usd', 1);
    $check($q['gross'] === 59 && $q['fee'] === 1 && $q['total'] === 60, 'tiny amounts round in the house\'s favour by at most one centavo');
    $throws(fn() => fx_usd_amount('0.50'), 'Enter from USD 1.00', 'below the minimum refused');
    $throws(fn() => fx_usd_amount('1000.01'), 'to USD 1,000.00', 'above the maximum refused');
    $throws(fn() => fx_usd_amount('1e3'), 'two decimal', 'bad amount format refused');

    // ---------- trades
    $pdo->prepare("INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES ('Fx Test', ?, ?, 'x', ?, 0)")->execute(['0918' . random_int(1000000, 9999999), 'fx' . bin2hex(random_bytes(4)) . '@test.local', 'UA-' . strtoupper(bin2hex(random_bytes(5)))]);
    $users[] = $u = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO boracay_cash_wallets (user_id, balance_centavos) VALUES (?, 100000)')->execute([$u]);
    $bal = static fn(): array => [(int) $pdo->query("SELECT balance_centavos FROM boracay_cash_wallets WHERE user_id = $u")->fetchColumn(), fx_usd_balance($pdo, $u)];
    $k = $key();
    $ref = fx_trade($pdo, $u, 'buy_usd', 1000, $rate['id'], $k);
    $check($bal() === [100000 - 58692, 1000], 'bought USD 10.00 for PHP 586.92 BCash');
    $check(fx_trade($pdo, $u, 'buy_usd', 1000, $rate['id'], $k) === $ref && $bal() === [100000 - 58692, 1000], 'same request key: no double exchange');
    $sum = $pdo->prepare("SELECT SUM(debit_centavos) d, SUM(credit_centavos) c, SUM(CASE WHEN account = 'fx_fee_revenue' THEN credit_centavos END) fee FROM ledger_entries WHERE entry_group = ?");
    $sum->execute([$ref]); $l = $sum->fetch();
    $check((int) $l['d'] === 58692 && (int) $l['c'] === 58692 && (int) $l['fee'] === 292, 'books balanced; fee PHP 2.92 booked as revenue');
    $throws(fn() => fx_trade($pdo, $u, 'buy_usd', 100000, $rate['id'], $key()), 'Not enough BCash', 'buying more than the BCash balance is refused');
    $throws(fn() => fx_trade($pdo, $u, 'sell_usd', 1001, $rate['id'], $key()), 'Not enough USD', 'selling more USD than held is refused');

    $src('/frankfurter/58.60', '/down');
    fx_refresh($pdo);
    $throws(fn() => fx_trade($pdo, $u, 'sell_usd', 500, $rate['id'], $key()), 'rate just changed', 'old rate id after a rate change: nothing moves', FxRateChanged::class);
    $check($bal() === [100000 - 58692, 1000], 'balances unchanged after the rate change');
    $rate = fx_current($pdo, false);
    $ref2 = fx_trade($pdo, $u, 'sell_usd', 500, $rate['id'], $key());
    $check($bal() === [100000 - 58692 + 29007, 500], 'sold USD 5.00 at 58.60 less 1%: got PHP 290.07');
    $t = $pdo->query('SELECT * FROM fx_trades WHERE reference = ' . $pdo->quote($ref2))->fetch();
    $check((int) $t['gross_php_centavos'] === 29300 && (int) $t['fee_php_centavos'] === 293 && $t['rate'] === '58.600000', 'trade row records rate, fee and amounts');

    // ---------- closed / stale / manual / KYC
    setting_save($pdo, 'fx.enabled', '0', null);
    $throws(fn() => fx_trade($pdo, $u, 'sell_usd', 100, $rate['id'], $key()), 'closed', 'exchange closed in Admin: refused');
    setting_save($pdo, 'fx.enabled', '1', null);
    $pdo->exec('UPDATE fx_rates SET fetched_at = NOW() - INTERVAL 30 HOUR WHERE id = ' . (int) $rate['id']);
    $check(!fx_current($pdo, false)['tradable'], 'rate older than 24 h: exchanges pause');
    $pdo->exec('UPDATE fx_rates SET fetched_at = NOW() WHERE id = ' . (int) $rate['id']);
    setting_save($pdo, 'fx.provider', 'manual', null);
    setting_save($pdo, 'fx.manual_rate', '59.10', null);
    $m = fx_current($pdo);
    $check($m['source'] === 'manual' && $m['micro'] === 59100000 && $m['tradable'], 'manual rate from Admin is used');
    setting_save($pdo, 'fx.provider', 'auto', null);
    setting_save($pdo, 'kyc.required_user', '1', null);
    require_once __DIR__ . '/../includes/kyc.php';
    $throws(fn() => fx_trade($pdo, $u, 'sell_usd', 100, $m['id'], $key()), 'verify your identity', 'customer KYC rule on: unverified cannot exchange');
    echo "\nAll USD exchange tests passed.\n";
} finally {
    foreach ($saved as $k => $v) { if ($v === false) setting_clear($pdo, $k); else setting_save($pdo, $k, (string) $v, null); }
    $ids = $users ? implode(',', $users) : '0';
    $pdo->exec("DELETE FROM ledger_entries WHERE entry_group IN (SELECT reference FROM fx_trades WHERE user_id IN ($ids))");
    $pdo->exec("DELETE FROM fx_trades WHERE user_id IN ($ids)");
    $pdo->exec("DELETE FROM usd_wallets WHERE user_id IN ($ids)");
    $pdo->exec("DELETE FROM boracay_cash_wallets WHERE user_id IN ($ids)");
    $pdo->exec("DELETE FROM users WHERE id IN ($ids)");
    $pdo->exec("DELETE FROM fx_rates WHERE id > " . (int) $rateIds);
}
