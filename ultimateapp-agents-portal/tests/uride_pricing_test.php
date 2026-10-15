<?php
declare(strict_types=1);
// CLI only. Run against a COPY of the database after importing database/uride_pricing_2026_10_migration.sql:
//   DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tests/uride_pricing_test.php
// Checks the October 2026 URide pricing: Motorcycle/E-Trike PHP 50 for the first 2 km then PHP 25 per km
// (4 km = PHP 100), PHP 10 deducted from the driver per trip, PHP 50 minimum top-up and wallet to go online.
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
require __DIR__ . '/../includes/rate_limit.php';
require __DIR__ . '/../includes/uride.php';

$saved = [];
$set = static function (string $k, string $v) use ($pdo, &$saved): void {
    if (!array_key_exists($k, $saved)) $saved[$k] = $pdo->query('SELECT setting_value FROM app_settings WHERE setting_key = ' . $pdo->quote($k))->fetchColumn();
    setting_save($pdo, $k, $v, null);
};
// The values from the migration, plus a neutral environment for the trip.
foreach (['uride.motorcycle.enabled' => '1', 'uride.motorcycle.base_centavos' => '5000', 'uride.motorcycle.included_km' => '2', 'uride.motorcycle.per_km_centavos' => '2500', 'uride.motorcycle.minimum_centavos' => '5000',
    'uride.e_trike.enabled' => '1', 'uride.e_trike.base_centavos' => '5000', 'uride.e_trike.included_km' => '2', 'uride.e_trike.per_km_centavos' => '2500', 'uride.e_trike.minimum_centavos' => '5000',
    'uride.commission_type' => 'fixed', 'uride.commission_fixed_centavos' => '1000', 'uride.topup_min_centavos' => '5000', 'uride.min_wallet_centavos' => '5000',
    'uride.enabled' => '1', 'uride.cash_enabled' => '1', 'pay.cash_enabled' => '1', 'uride.road_factor' => '1.3', 'uride.night_surcharge_bp' => '0', 'uride.dispatch_radius_km' => '3', 'uride.request_timeout_min' => '5'] as $k => $v) $set($k, $v);
$users = []; $drivers = [];
$road = static fn(float $km): float => $km / 1.3; // straight-line km that gives this many road km
try {
    foreach (['motorcycle', 'e_trike'] as $v) {
        $label = uride_vehicles()[$v]['label'];
        $check(uride_quote($v, $road(0.8), 12)['fare'] === 5000, "$label: short trip pays the PHP 50 flag-down");
        $check(uride_quote($v, $road(2.0), 12)['fare'] === 5000, "$label: 2 km = PHP 50");
        $check(uride_quote($v, $road(3.0), 12)['fare'] === 7500, "$label: 3 km = PHP 75");
        $check(uride_quote($v, $road(4.0), 12)['fare'] === 10000, "$label: 4 km = PHP 100");
        $check(uride_quote($v, $road(6.0), 12)['fare'] === 15000, "$label: 6 km = PHP 150");
        $check(uride_quote($v, $road(2.5), 12)['fare'] === 6300, "$label: 2.5 km = PHP 62.50, rounded up to PHP 63");
    }
    $check(uride_commission_for(5000) === 1000 && uride_commission_for(15000) === 1000, 'PHP 10 per trip, whatever the fare');
    $s = uride_settings();
    $check($s['topup_min'] === 5000 && $s['min_wallet'] === 5000, 'minimum top-up PHP 50 and PHP 50 to go online');

    $code = uride_driver_new_code($pdo);
    $pdo->prepare("INSERT INTO uride_drivers (code, full_name, email, mobile, password_hash, birthdate, address, barangay, license_no, license_expiry, vehicle_type, plate_no, vehicle_model, vehicle_color, status)
        VALUES (?, 'Pricing Driver', ?, ?, 'x', '1990-01-01', 'Station 2', 'Balabag', ?, '2030-01-01', 'e_trike', ?, 'Bemac', 'Green', 'approved')")
        ->execute([$code, strtolower($code) . '@test.local', '0917' . random_int(1000000, 9999999), 'N01-' . random_int(10000, 99999), 'PT' . random_int(10000, 99999)]);
    $drivers[] = $d = (int) $pdo->lastInsertId();
    $drv = static fn(): array => $pdo->query("SELECT * FROM uride_drivers WHERE id = $d")->fetch();
    $throws(fn() => uride_topup_start($pdo, $drv(), 4900, []), 'Top up from PHP 50.00', 'a PHP 49 top-up is refused');
    uride_tx($pdo, fn() => uride_wallet_change($pdo, $d, 4900, 'adjustment', 'TEST-' . $d, null, 'test float'));
    $throws(fn() => uride_set_online($pdo, $d, true), 'at least PHP 50.00', 'PHP 49 in the wallet cannot go online');
    uride_tx($pdo, fn() => uride_wallet_change($pdo, $d, 100, 'adjustment', 'TEST2-' . $d, null, 'test float'));
    uride_set_online($pdo, $d, true);
    uride_heartbeat($pdo, $d, 11.9660, 121.9250);
    $check((int) $drv()['is_online'] === 1, 'PHP 50 in the wallet can go online');

    $pdo->prepare("INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES ('Pricing Rider', ?, ?, 'x', ?, 1000)")->execute(['0918' . random_int(1000000, 9999999), 'pr' . bin2hex(random_bytes(4)) . '@test.local', 'UA-' . strtoupper(bin2hex(random_bytes(5)))]);
    $users[] = $u = (int) $pdo->lastInsertId();
    foreach (['credits' => 'Credits', 'cash' => 'cash'] as $pay => $what) {
        $rideCode = uride_book($pdo, $u, ['pickup_address' => "D'Mall Boracay", 'pickup_lat' => '11.9667000', 'pickup_lng' => '121.9246000', 'dropoff_address' => 'Bulabog Beach', 'dropoff_lat' => '11.9720000', 'dropoff_lng' => '121.9330000', 'vehicle_type' => 'e_trike', 'payment_method' => $pay], bin2hex(random_bytes(32)));
        $ride = $pdo->query('SELECT * FROM uride_requests WHERE code = ' . $pdo->quote($rideCode))->fetch();
        $before = uride_wallet_balance($pdo, $d);
        uride_accept($pdo, $d, (int) $ride['id']);
        uride_driver_action($pdo, $d, (int) $ride['id'], 'start');
        uride_driver_action($pdo, $d, (int) $ride['id'], 'complete');
        $fare = (int) $ride['fare_centavos'];
        $delta = uride_wallet_balance($pdo, $d) - $before;
        $check($pay === 'cash' ? $delta === -1000 : $delta === $fare - 1000, "$what trip: PHP 10 deducted from the driver (fare PHP " . peso($fare) . ')');
    }
    echo "\nAll URide pricing tests passed.\n";
} finally {
    if ($users) {
        $ids = implode(',', $users);
        foreach ($pdo->query("SELECT code FROM uride_requests WHERE user_id IN ($ids)")->fetchAll(PDO::FETCH_COLUMN) as $c) $pdo->exec('DELETE FROM ledger_entries WHERE entry_group LIKE ' . $pdo->quote('%-' . substr($c, 3)));
        $pdo->exec("DELETE FROM uride_requests WHERE user_id IN ($ids)");
        $pdo->exec("DELETE FROM transactions WHERE user_id IN ($ids)");
        $pdo->exec("DELETE FROM users WHERE id IN ($ids)");
    }
    foreach ($drivers as $d) $pdo->exec("UPDATE uride_drivers SET is_online = 0, status = 'rejected', review_note = 'Automated test driver' WHERE id = $d");
    foreach ($saved as $k => $v) { if ($v === false) setting_clear($pdo, $k); else setting_save($pdo, $k, (string) $v, null); }
}
