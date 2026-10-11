<?php
declare(strict_types=1);
// CLI only. Run against a COPY of the database after importing database/pay_methods_migration.sql:
//   DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tests/pay_methods_test.php
// Checks BCash payments (URide, UPass/UGo/ULocal, UEat) and the app-wide "Pay Cash" switch.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$check = static function (bool $ok, string $msg): void { if (!$ok) throw new RuntimeException('FAILED: ' . $msg); echo "PASS: $msg\n"; };
$throws = static function (callable $fn, string $needle, string $msg) use ($check): void {
    try { $fn(); } catch (InvalidArgumentException $e) { $check(str_contains($e->getMessage(), $needle), $msg . ' (' . $e->getMessage() . ')'); return; }
    $check(false, $msg . ' — expected an error');
};
$pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4', getenv('DB_USER') ?: '', getenv('DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("SET time_zone = '+08:00'");
date_default_timezone_set('Asia/Manila');
$GLOBALS['pdo'] = $pdo;
$_SESSION = [];
require __DIR__ . '/../includes/rate_limit.php';
require __DIR__ . '/../includes/merchants.php';
require __DIR__ . '/../includes/uride.php';
require __DIR__ . '/../includes/marketplace.php';

$saved = [];
$set = static function (string $k, string $v) use ($pdo, &$saved): void {
    if (!array_key_exists($k, $saved)) $saved[$k] = $pdo->query('SELECT setting_value FROM app_settings WHERE setting_key = ' . $pdo->quote($k))->fetchColumn();
    setting_save($pdo, $k, $v, null);
    settings_all(true);
};
$key = static fn(): string => bin2hex(random_bytes(32));
$users = []; $merchants = []; $listings = []; $drivers = [];
$bc = static fn(int $u): int => (int) $pdo->query("SELECT COALESCE((SELECT balance_centavos FROM boracay_cash_wallets WHERE user_id = $u), 0)")->fetchColumn();
$cr = static fn(int $u): int => (int) round((float) $pdo->query("SELECT credits FROM users WHERE id = $u")->fetchColumn() * 100);
try {
    foreach (['pay.cash_enabled' => '1', 'pay.bcash_enabled' => '1', 'uride.enabled' => '1', 'uride.credits_enabled' => '1', 'uride.cash_enabled' => '1', 'uride.bcash_enabled' => '1',
        'uride.e_trike.enabled' => '1', 'uride.request_timeout_min' => '5', 'p2m.credits.enabled' => '1', 'p2m.boracay_cash.enabled' => '1', 'p2m.boracay_cash.percent_bp' => '0', 'p2m.boracay_cash.fixed_centavos' => '0',
        'p2m.credits.percent_bp' => '0', 'p2m.credits.fixed_centavos' => '0', 'market.credits_checkout' => '1'] as $k => $v) $set($k, $v);
    $pdo->prepare("INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES ('Pay Test', ?, ?, 'x', ?, 1000)")->execute(['0918' . random_int(1000000, 9999999), 'pm' . bin2hex(random_bytes(3)) . '@test.local', 'UA-PM' . strtoupper(bin2hex(random_bytes(4)))]);
    $users[] = $u = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO boracay_cash_wallets (user_id, balance_centavos) VALUES (?, 500000)')->execute([$u]);

    // ---------- URide with BCash
    $trip = ['pickup_address' => "D'Mall Boracay", 'pickup_lat' => '11.9667', 'pickup_lng' => '121.9246', 'dropoff_address' => 'Station 1', 'dropoff_lat' => '11.9797', 'dropoff_lng' => '121.9183', 'vehicle_type' => 'e_trike', 'payment_method' => 'bcash'];
    $b0 = $bc($u); $c0 = $cr($u);
    $code = uride_book($pdo, $u, $trip, $key());
    $r = $pdo->query('SELECT * FROM uride_requests WHERE code = ' . $pdo->quote($code))->fetch();
    $check($r['payment_method'] === 'bcash' && $bc($u) === $b0 - (int) $r['fare_centavos'] && $cr($u) === $c0, 'URide ride paid with BCash: fare held from BCash, Credits untouched');
    $check((int) $pdo->query("SELECT debit_centavos FROM ledger_entries WHERE entry_group = " . $pdo->quote($code) . " AND account = 'user_cash:$u'")->fetchColumn() === (int) $r['fare_centavos'], 'ledger: from the customer BCash account');
    uride_cancel_by_passenger($pdo, $u, (int) $r['id'], 'test');
    $check($bc($u) === $b0, 'cancel returns the fare to BCash');
    $set('pay.bcash_enabled', '0');
    $throws(fn() => uride_book($pdo, $u, $trip, $key()), 'BCash payment', 'BCash switched off: refused');
    $set('pay.bcash_enabled', '1');
    $set('pay.cash_enabled', '0');
    $throws(fn() => uride_book($pdo, $u, ['payment_method' => 'cash'] + $trip, $key()), 'Cash payment', 'Pay Cash switched off: URide cash refused');
    $check(!uride_settings()['cash_enabled'], 'URide settings follow the app-wide switch');
    $set('pay.cash_enabled', '1');
    $pdo->exec("UPDATE boracay_cash_wallets SET balance_centavos = 100 WHERE user_id = $u");
    $throws(fn() => uride_book($pdo, $u, $trip, $key()), 'Not enough BCash', 'not enough BCash');
    $pdo->exec("UPDATE boracay_cash_wallets SET balance_centavos = 500000 WHERE user_id = $u");

    // ---------- marketplace
    $code = merchant_new_code($pdo);
    $pdo->prepare("INSERT INTO merchants (code, business_name, category, owner_name, email, mobile, password_hash, address, barangay, status, bank_account_no) VALUES (?, 'Pay Tours', 'Tours & Activities', 'Owner', ?, '09170000000', 'x', 'Station 2', 'Balabag', 'approved', '1234567890')")->execute([$code, strtolower($code) . '@test.local']);
    $merchants[] = $m = (int) $pdo->lastInsertId();
    $listings[] = $tour = mk_listing_save($pdo, null, mk_validate_listing(['title' => 'Pay Tour', 'category' => 'Land Tour', 'price' => '500', 'time_slots' => '09:00'], 'ugo'), $m, 1, null, 'active');
    $listings[] = $ua = mk_listing_save($pdo, null, mk_validate_listing(['title' => 'UA Pass', 'category' => 'Day Pass', 'price' => '300', 'time_slots' => '09:00'], 'upass'), null, 1, null, 'active');
    $check(mk_pay_methods(mk_listing($pdo, $tour)) === ['credits', 'bcash', 'on_site'], 'methods: Credits, BCash, pay cash on the day');
    $now = new DateTimeImmutable('2026-10-11 08:00:00');
    $in = ['date' => '2026-10-13', 'time' => '09:00', 'quantity' => 2, 'contact_mobile' => '09171234567', 'pay_method' => 'bcash'];
    $b1 = $bc($u); $m1 = (int) $pdo->query("SELECT balance_centavos FROM merchants WHERE id = $m")->fetchColumn();
    $ref = mk_book($pdo, $u, $tour, $in, $key(), $now);
    $bk = mk_booking($pdo, $ref);
    $check($bk['pay_method'] === 'bcash' && $bk['payment_status'] === 'paid' && $bc($u) === $b1 - 100000, 'UGo booking paid with BCash (₱1,000)');
    $check((int) $pdo->query("SELECT balance_centavos FROM merchants WHERE id = $m")->fetchColumn() === $m1 + 100000 && $pdo->query('SELECT source FROM merchant_payments WHERE reference = ' . $pdo->quote($bk['payment_ref']))->fetchColumn() === 'boracay_cash', 'merchant paid like a BCash QR payment');
    mk_booking_update($pdo, $ref, 'cancel', 'customer', $u, 'Change of plans', $now);
    $check($bc($u) === $b1, 'cancelled: BCash refunded');
    $ref2 = mk_book($pdo, $u, $ua, $in, $key(), $now);
    $bk2 = mk_booking($pdo, $ref2);
    $check($bk2['pay_method'] === 'bcash' && str_starts_with((string) $bk2['payment_ref'], 'PL-') && $bc($u) === $b1 - 60000, 'Ultimate App pass paid with BCash');
    mk_booking_update($pdo, $ref2, 'cancel', 'admin', 1);
    $check($bc($u) === $b1, 'Admin cancel: BCash refunded from Ultimate App');
    $set('pay.cash_enabled', '0');
    $check(!in_array('on_site', mk_pay_methods(mk_listing($pdo, $tour)), true), 'Pay Cash off: no "pay on the day"');
    $throws(fn() => mk_book($pdo, $u, $tour, ['pay_method' => 'on_site'] + $in, $key(), $now), 'turned off', 'pay on the day refused');
    $set('pay.cash_enabled', '1');
    $set('pay.bcash_enabled', '0');
    $check(mk_pay_methods(mk_listing($pdo, $tour)) === ['credits', 'on_site'], 'BCash off: not offered');
    $set('pay.bcash_enabled', '1');

    // ---------- UEat with BCash
    mk_store_save($pdo, $m, ['display_name' => 'Pay Kitchen', 'cuisine' => 'Filipino', 'prep_minutes' => '20', 'delivery_fee' => '0', 'min_order' => '0', 'offers_delivery' => '1', 'offers_pickup' => '1', 'opens_at' => '00:00', 'closes_at' => '23:59'], null, 1, 'active');
    $listings[] = $dish = mk_listing_save($pdo, null, mk_validate_listing(['title' => 'Adobo', 'price' => '150', 'category' => 'Mains'], 'ueat'), $m, 1, null, 'active');
    $cart = mk_cart_set($pdo, $u, $dish, 2, true);
    $b2 = $bc($u);
    $o = mk_place_order($pdo, $u, $cart, ['fulfillment' => 'pickup', 'contact_mobile' => '09171234567', 'pay_source' => 'bcash'], $key());
    $row = $pdo->query('SELECT * FROM ueat_orders WHERE reference = ' . $pdo->quote($o))->fetch();
    $check($row['pay_source'] === 'bcash' && $bc($u) === $b2 - 30000, 'UEat order paid with BCash');
    mk_order_update($pdo, $o, 'declined', 'merchant', $m, 'Sold out');
    $check($bc($u) === $b2, 'declined: BCash refunded');
    echo "\nAll payment method tests passed.\n";
} finally {
    $ids = $users ? implode(',', $users) : '0'; $mids = $merchants ? implode(',', $merchants) : '0';
    $groups = array_merge($pdo->query("SELECT code FROM uride_requests WHERE user_id IN ($ids)")->fetchAll(PDO::FETCH_COLUMN), $pdo->query("SELECT reference FROM merchant_payments WHERE user_id IN ($ids)")->fetchAll(PDO::FETCH_COLUMN), $pdo->query("SELECT payment_ref FROM mk_bookings WHERE user_id IN ($ids) AND payment_ref IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN));
    foreach ($groups as $g) $pdo->prepare('DELETE FROM ledger_entries WHERE entry_group IN (?, ?)')->execute([$g, 'RF-' . substr($g, 3)]);
    $pdo->exec("DELETE FROM agent_commissions WHERE source_type IN ('mk_booking','ueat','uride') AND source_id IN (SELECT id FROM mk_bookings WHERE user_id IN ($ids))");
    $pdo->exec("DELETE FROM uride_requests WHERE user_id IN ($ids)");
    $pdo->exec("DELETE FROM mk_bookings WHERE user_id IN ($ids)");
    $pdo->exec("DELETE FROM ueat_orders WHERE user_id IN ($ids)");
    $pdo->exec("DELETE FROM merchant_payments WHERE user_id IN ($ids)");
    if ($listings) $pdo->exec('DELETE FROM mk_listings WHERE id IN (' . implode(',', $listings) . ')');
    $pdo->exec("DELETE FROM mk_stores WHERE merchant_id IN ($mids)");
    $pdo->exec("DELETE FROM merchants WHERE id IN ($mids)");
    $pdo->exec("DELETE FROM boracay_cash_wallets WHERE user_id IN ($ids)");
    $pdo->exec("DELETE FROM transactions WHERE user_id IN ($ids)");
    $pdo->exec("DELETE FROM users WHERE id IN ($ids)");
    foreach ($saved as $k => $v) { if ($v === false) setting_clear($pdo, $k); else setting_save($pdo, $k, (string) $v, null); }
}
