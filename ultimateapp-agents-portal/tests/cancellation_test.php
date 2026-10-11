<?php
declare(strict_types=1);
// CLI only. Run against a COPY of the database after importing database/cancellation_migration.sql:
//   DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tests/cancellation_test.php
// Checks cancellation fees (% set by Admin) and reasons for URide and UPass/UGo/ULocal bookings.
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
$cr = static fn(int $u): int => (int) round((float) $pdo->query("SELECT credits FROM users WHERE id = $u")->fetchColumn() * 100);
$wal = static fn(int $d): int => (int) $pdo->query("SELECT wallet_centavos FROM uride_drivers WHERE id = $d")->fetchColumn();
try {
    foreach (['cancel.uride_pct' => '20', 'cancel.uride_free_min' => '2', 'cancel.booking_pct' => '25', 'cancel.ueat_pct' => '30', 'pay.cash_enabled' => '1', 'pay.bcash_enabled' => '1',
        'uride.enabled' => '1', 'uride.credits_enabled' => '1', 'uride.cash_enabled' => '1', 'uride.e_trike.enabled' => '1', 'uride.min_wallet_centavos' => '5000', 'uride.dispatch_radius_km' => '3', 'uride.request_timeout_min' => '5',
        'p2m.credits.enabled' => '1', 'p2m.credits.percent_bp' => '0', 'p2m.credits.fixed_centavos' => '0', 'market.credits_checkout' => '1'] as $k => $v) $set($k, $v);
    // ---------- reasons
    $throws(fn() => cancel_reason('uride', ''), 'reason', 'a reason is required');
    $throws(fn() => cancel_reason('uride', 'Other', ''), 'tell us', '"Other" needs the customer\'s words');
    $check(cancel_reason('uride', 'Other', 'Flat tire  on my side') === 'Other: Flat tire on my side' && cancel_reason('booking', 'Weather') === 'Weather', 'preset and "Other: …" reasons');
    $check(cancel_fee_amount(12345, 'uride') === 2469 && cancel_fee_amount(10000, 'booking') === 2500, 'fee = % of what was paid (rounded to the centavo)');

    $pdo->prepare("INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES ('Cancel Test', ?, ?, 'x', ?, 5000)")->execute(['0918' . random_int(1000000, 9999999), 'cx' . bin2hex(random_bytes(3)) . '@test.local', 'UA-CX' . strtoupper(bin2hex(random_bytes(4)))]);
    $users[] = $u = (int) $pdo->lastInsertId();
    $code = uride_driver_new_code($pdo);
    $pdo->prepare("INSERT INTO uride_drivers (code, full_name, email, mobile, password_hash, birthdate, address, barangay, license_no, license_expiry, vehicle_type, plate_no, vehicle_model, vehicle_color, status) VALUES (?, 'Cancel Driver', ?, ?, 'x', '1990-01-01', 'Station 2', 'Balabag', ?, '2030-01-01', 'e_trike', ?, 'Bemac', 'Green', 'approved')")
        ->execute([$code, strtolower($code) . '@test.local', '0917' . random_int(1000000, 9999999), 'N01-' . random_int(10000, 99999), 'C' . random_int(10000, 99999)]);
    $drivers[] = $d = (int) $pdo->lastInsertId();
    uride_tx($pdo, fn() => uride_wallet_change($pdo, $d, 100000, 'adjustment', 'TEST-' . $d, null, 'test float'));
    uride_set_online($pdo, $d, true); uride_heartbeat($pdo, $d, 11.9660, 121.9250);
    $trip = ['pickup_address' => "D'Mall Boracay", 'pickup_lat' => '11.9667', 'pickup_lng' => '121.9246', 'dropoff_address' => 'Station 1', 'dropoff_lat' => '11.9797', 'dropoff_lng' => '121.9183', 'vehicle_type' => 'e_trike', 'payment_method' => 'credits'];
    $ride = static fn(string $c): array => $pdo->query('SELECT * FROM uride_requests WHERE code = ' . $pdo->quote($c))->fetch();

    // before a driver accepts: free
    $c0 = $cr($u); $r = $ride(uride_book($pdo, $u, $trip, $key()));
    $throws(fn() => uride_cancel_by_passenger($pdo, $u, (int) $r['id'], ''), 'reason', 'passenger must give a reason');
    $res = uride_cancel_by_passenger($pdo, $u, (int) $r['id'], 'Changed my plans');
    $check($res['fee'] === 0 && $cr($u) === $c0, 'cancel while looking for a driver: free');
    // accepted, within the free minutes: free
    $r = $ride(uride_book($pdo, $u, $trip, $key())); uride_accept($pdo, $d, (int) $r['id']);
    $res = uride_cancel_by_passenger($pdo, $u, (int) $r['id'], 'Booked by mistake');
    $check($res['fee'] === 0 && $cr($u) === $c0, 'cancel within 2 minutes after the driver accepts: free');
    // accepted 5 minutes ago: 20% to the driver
    $r = $ride(uride_book($pdo, $u, $trip, $key())); uride_accept($pdo, $d, (int) $r['id']);
    $pdo->exec('UPDATE uride_requests SET accepted_at = NOW() - INTERVAL 5 MINUTE WHERE id = ' . (int) $r['id']);
    $fare = (int) $r['fare_centavos']; $w0 = $wal($d);
    $check(uride_cancel_fee($ride($r['code'])) === (int) round($fare * 0.2), 'preview: 20% fee after the free minutes');
    $res = uride_cancel_by_passenger($pdo, $u, (int) $r['id'], 'Other', 'Took a tricycle instead');
    $fee = (int) round($fare * 0.2);
    $after = $ride($r['code']);
    $check($res['fee'] === $fee && $cr($u) === $c0 - $fee && $wal($d) === $w0 + $fee, '20% fee kept from the refund and added to the driver wallet');
    $check($after['cancel_reason'] === 'Other: Took a tricycle instead' && (int) $after['cancel_fee_centavos'] === $fee, 'reason and fee saved on the ride');
    $check((int) $pdo->query("SELECT credit_centavos FROM ledger_entries WHERE entry_group = 'RF-" . substr($r['code'], 3) . "' AND account = 'driver_wallet:$d'")->fetchColumn() === $fee, 'ledger: fee to the driver wallet');
    // arrived: fee even right away
    $r = $ride(uride_book($pdo, $u, $trip, $key())); uride_accept($pdo, $d, (int) $r['id']); uride_driver_action($pdo, $d, (int) $r['id'], 'arrived');
    $check(uride_cancel_fee($ride($r['code'])) > 0, 'driver already arrived: fee applies');
    uride_cancel_by_passenger($pdo, $u, (int) $r['id'], 'Driver is taking too long');
    // cash ride: no fee
    $r = $ride(uride_book($pdo, $u, ['payment_method' => 'cash'] + $trip, $key())); uride_accept($pdo, $d, (int) $r['id']); uride_driver_action($pdo, $d, (int) $r['id'], 'arrived');
    $check(uride_cancel_fee($ride($r['code'])) === 0, 'cash ride: no fee (nothing paid in the app)');
    uride_cancel_by_passenger($pdo, $u, (int) $r['id'], 'Changed my plans');
    $set('cancel.uride_pct', '0');
    $r = $ride(uride_book($pdo, $u, $trip, $key())); uride_accept($pdo, $d, (int) $r['id']); uride_driver_action($pdo, $d, (int) $r['id'], 'arrived');
    $check(uride_cancel_fee($ride($r['code'])) === 0, 'Admin sets 0%: free');
    uride_cancel_by_passenger($pdo, $u, (int) $r['id'], 'Changed my plans');
    $set('cancel.uride_pct', '20');

    // ---------- bookings: late cancellation
    $code = merchant_new_code($pdo);
    $pdo->prepare("INSERT INTO merchants (code, business_name, category, owner_name, email, mobile, password_hash, address, barangay, status, bank_account_no) VALUES (?, 'Cancel Tours', 'Tours & Activities', 'Owner', ?, '09170000000', 'x', 'Station 2', 'Balabag', 'approved', '1234567890')")->execute([$code, strtolower($code) . '@test.local']);
    $merchants[] = $m = (int) $pdo->lastInsertId();
    $listings[] = $tour = mk_listing_save($pdo, null, mk_validate_listing(['title' => 'Cancel Tour', 'category' => 'Land Tour', 'price' => '1000', 'time_slots' => '09:00', 'cancel_hours' => '24'], 'ugo'), $m, 1, null, 'active');
    $listings[] = $ua = mk_listing_save($pdo, null, mk_validate_listing(['title' => 'UA Pass', 'category' => 'Day Pass', 'price' => '400', 'time_slots' => '09:00', 'cancel_hours' => '24'], 'upass'), null, 1, null, 'active');
    $now = new DateTimeImmutable('2026-10-11 08:00:00');
    $in = ['date' => '2026-10-13', 'time' => '09:00', 'quantity' => 1, 'contact_mobile' => '09171234567', 'pay_method' => 'credits'];
    $mb = static fn(): int => (int) $pdo->query("SELECT balance_centavos FROM merchants WHERE id = $m")->fetchColumn();
    $ref = mk_book($pdo, $u, $tour, $in, $key(), $now); mk_booking_update($pdo, $ref, 'confirm', 'merchant', $m);
    $check(mk_booking_cancel_terms(mk_booking($pdo, $ref), $now)['fee'] === 0, 'confirmed, 2 days before (cut-off 24 h): free');
    $lateNow = new DateTimeImmutable('2026-10-12 18:00:00');
    $c1 = $cr($u); $m1 = $mb();
    $res = mk_booking_update($pdo, $ref, 'cancel', 'customer', $u, 'Weather', $lateNow);
    $check($res['fee'] === 25000 && $cr($u) === $c1 + 75000 && $mb() === $m1 - 100000 + 25000, 'late cancel (15 h before): 25% fee to the provider, ₱750 back to the customer');
    $bk = mk_booking($pdo, $ref);
    $check($bk['cancel_reason'] === 'Weather' && (int) $bk['cancel_fee_centavos'] === 25000, 'booking keeps the reason and fee');
    $ref2 = mk_book($pdo, $u, $ua, $in, $key(), $now); mk_booking_update($pdo, $ref2, 'confirm', 'admin', 1);
    $c2 = $cr($u);
    $res = mk_booking_update($pdo, $ref2, 'cancel', 'customer', $u, 'Health or emergency', $lateNow);
    $check($res['fee'] === 10000 && $cr($u) === $c2 + 30000, 'Ultimate App pass: late fee kept by Ultimate App, rest refunded');
    $ref3 = mk_book($pdo, $u, $tour, ['pay_method' => 'on_site'] + $in, $key(), $now); mk_booking_update($pdo, $ref3, 'confirm', 'merchant', $m);
    $check(mk_booking_cancel_terms(mk_booking($pdo, $ref3), $lateNow)['fee'] === 0, 'pay-on-the-day booking: no fee');
    $ref4 = mk_book($pdo, $u, $tour, $in, $key(), $now); mk_booking_update($pdo, $ref4, 'confirm', 'merchant', $m);
    $c4 = $cr($u);
    mk_booking_update($pdo, $ref4, 'decline', 'admin', 1, 'Typhoon');
    $check($cr($u) === $c4 + 100000, 'declined by Ultimate App: full refund, no fee');
    echo "\nAll cancellation tests passed.\n";
} finally {
    $ids = $users ? implode(',', $users) : '0'; $mids = $merchants ? implode(',', $merchants) : '0';
    $groups = array_merge($pdo->query("SELECT code FROM uride_requests WHERE user_id IN ($ids)")->fetchAll(PDO::FETCH_COLUMN), $pdo->query("SELECT reference FROM merchant_payments WHERE user_id IN ($ids)")->fetchAll(PDO::FETCH_COLUMN), $pdo->query("SELECT payment_ref FROM mk_bookings WHERE user_id IN ($ids) AND payment_ref IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN), $pdo->query("SELECT reference FROM mk_bookings WHERE user_id IN ($ids)")->fetchAll(PDO::FETCH_COLUMN));
    foreach ($groups as $g) $pdo->prepare('DELETE FROM ledger_entries WHERE entry_group IN (?, ?, ?)')->execute([$g, 'RF-' . substr($g, 3), 'CF-' . substr($g, 3)]);
    $pdo->exec("DELETE FROM agent_commissions WHERE source_type = 'mk_booking' AND source_id IN (SELECT id FROM mk_bookings WHERE user_id IN ($ids))");
    $pdo->exec("DELETE FROM uride_requests WHERE user_id IN ($ids)");
    $pdo->exec("DELETE FROM mk_bookings WHERE user_id IN ($ids)");
    $pdo->exec("DELETE FROM merchant_payments WHERE user_id IN ($ids)");
    if ($listings) $pdo->exec('DELETE FROM mk_listings WHERE id IN (' . implode(',', $listings) . ')');
    $pdo->exec("DELETE FROM merchants WHERE id IN ($mids)");
    $pdo->exec("DELETE FROM transactions WHERE user_id IN ($ids)");
    $pdo->exec("DELETE FROM users WHERE id IN ($ids)");
    foreach ($drivers as $d) $pdo->exec("UPDATE uride_drivers SET is_online = 0, status = 'rejected', review_note = 'Automated test driver' WHERE id = $d");
    foreach ($saved as $k => $v) { if ($v === false) setting_clear($pdo, $k); else setting_save($pdo, $k, (string) $v, null); }
}
