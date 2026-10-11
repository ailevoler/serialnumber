<?php
declare(strict_types=1);
// CLI only. Run against a COPY of the database after importing database/uride_boat_migration.sql:
//   DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tests/uride_boat_test.php
// Checks the Boat vehicle: point-to-point rides only (no hourly rental, no self-drive), fares, dispatch to boat
// operators only, and payment to the boat operator's wallet. Deletes everything it creates.
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
foreach (['uride.enabled' => '1', 'uride.credits_enabled' => '1', 'uride.cash_enabled' => '1', 'uride.rental_enabled' => '1', 'uride.self_drive_enabled' => '1', 'uride.road_factor' => '1.3',
    'uride.night_surcharge_bp' => '0', 'uride.boat.enabled' => '1', 'uride.boat.base_centavos' => '50000', 'uride.boat.included_km' => '1', 'uride.boat.per_km_centavos' => '10000', 'uride.boat.minimum_centavos' => '50000',
    'uride.boat.rental_enabled' => '1', 'uride.boat.self_enabled' => '1', // even if someone turns these on, Boat stays ride-only
    'uride.commission_type' => 'percent', 'uride.commission_percent_bp' => '1000', 'uride.min_wallet_centavos' => '5000', 'uride.dispatch_radius_km' => '3', 'uride.request_timeout_min' => '5', 'pay.cash_enabled' => '1'] as $k => $v) $set($k, $v);
$users = []; $drivers = [];
$key = static fn(): string => bin2hex(random_bytes(32));
try {
    $veh = uride_vehicles()['boat'] ?? null;
    $check($veh !== null && $veh['label'] === 'Boat' && $veh['ride'] && !$veh['rent'], 'Boat is a vehicle type for rides, not rentals');
    $check(!isset(uride_rentable_vehicles()['boat']) && isset(uride_rentable_vehicles()['van']), 'Boat is left out of Rent a Ride and Self-drive lists');
    $check(!uride_rental_rule('boat')['enabled'] && !uride_self_rule('boat')['enabled'], 'Boat rental and self-drive stay off even if the settings say on');
    $check(!uride_rental_quote('boat', '3')['enabled'] && !uride_rental_quote('boat', '3', 'self')['enabled'], 'no hourly price for Boat');
    $q = uride_quote('boat', 2.0, 12);
    // 2 km × 1.3 = 2.6 road km; 1 km in base; 1.6 km × ₱100 = ₱160; ₱500 + ₱160 = ₱660
    $check($q['enabled'] && $q['fare'] === 66000, 'Boat ride fare: ₱500 base + 1.6 km × ₱100 = ₱660');
    $check(uride_quote('boat', 0.2, 12)['fare'] === 50000, 'Boat minimum fare ₱500');
    $check(uride_license_class('boat') === 'none', 'Boat never asks for a customer driver\'s license');

    $pdo->prepare("INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES ('Boat Test', ?, ?, 'x', ?, 5000)")->execute(['0918' . random_int(1000000, 9999999), 'bt' . bin2hex(random_bytes(4)) . '@test.local', 'UA-' . strtoupper(bin2hex(random_bytes(5)))]);
    $users[] = $u = (int) $pdo->lastInsertId();
    $mk = static function (string $vehicle) use ($pdo, &$drivers): int {
        $code = uride_driver_new_code($pdo);
        $pdo->prepare("INSERT INTO uride_drivers (code, full_name, email, mobile, password_hash, birthdate, address, barangay, license_no, license_expiry, vehicle_type, plate_no, vehicle_model, vehicle_color, status)
            VALUES (?, 'Boat Operator', ?, ?, 'x', '1985-01-01', 'Station 1', 'Balabag', ?, '2030-01-01', ?, ?, 'Bangka', 'Blue', 'approved')")
            ->execute([$code, strtolower($code) . '@test.local', '0917' . random_int(1000000, 9999999), 'MRN-' . random_int(10000, 99999), $vehicle, 'BT' . random_int(10000, 99999)]);
        $id = (int) $pdo->lastInsertId();
        $drivers[] = $id;
        uride_tx($pdo, fn() => uride_wallet_change($pdo, $id, 100000, 'adjustment', 'TEST-' . $id, null, 'test float'));
        uride_set_online($pdo, $id, true);
        uride_heartbeat($pdo, $id, 11.9590, 121.9310);
        return $id;
    };
    $boat = $mk('boat');
    $car = $mk('car');
    $drv = static fn(int $id): array => $pdo->query("SELECT * FROM uride_drivers WHERE id = $id")->fetch();

    $rent = ['pickup_address' => 'Station 1 beach', 'pickup_lat' => '11.9590000', 'pickup_lng' => '121.9310000', 'vehicle_type' => 'boat', 'payment_method' => 'credits', 'rental_hours' => '3', 'start_mode' => 'now'];
    $throws(fn() => uride_book_rental($pdo, $u, $rent + ['rental_mode' => 'driver'], $key()), 'point-to-point rides only', 'cannot rent a Boat by the hour');
    $throws(fn() => uride_book_rental($pdo, $u, $rent + ['rental_mode' => 'self', 'self_terms' => '1'], $key()), 'point-to-point rides only', 'cannot self-drive a Boat');
    $check((int) $pdo->query("SELECT COUNT(*) FROM uride_requests WHERE user_id = $u")->fetchColumn() === 0, 'no rental request was saved');
    uride_fleet_save($pdo, $boat, ['boat' => 3]);
    $check(!isset(uride_fleet_units($pdo, $boat)['boat']), 'Boat cannot be added as a self-drive unit');

    // Station 1 to Caticlan jetty side (about 2.5 km straight line)
    $trip = ['pickup_address' => 'Station 1 beach', 'pickup_lat' => '11.9590000', 'pickup_lng' => '121.9310000', 'dropoff_address' => 'Caticlan Jetty Port', 'dropoff_lat' => '11.9300000', 'dropoff_lng' => '121.9400000', 'vehicle_type' => 'boat', 'payment_method' => 'credits'];
    $code = uride_book($pdo, $u, $trip, $key());
    $ride = $pdo->query('SELECT * FROM uride_requests WHERE code = ' . $pdo->quote($code))->fetch();
    $check($ride['vehicle_type'] === 'boat' && ($ride['trip_type'] ?? 'ride') === 'ride' && (int) $ride['held_centavos'] === (int) $ride['fare_centavos'], 'Boat ride booked point to point; fare held from Credits');
    $feedBoat = array_column(uride_feed($pdo, $drv($boat)), 'code');
    $feedCar = array_column(uride_feed($pdo, $drv($car)), 'code');
    $check(in_array($code, $feedBoat, true) && !in_array($code, $feedCar, true), 'only boat operators get the Boat request');
    $throws(fn() => uride_accept($pdo, $car, (int) $ride['id']), 'different vehicle type', 'a car driver cannot take a Boat ride');
    uride_accept($pdo, $boat, (int) $ride['id']);
    $before = uride_wallet_balance($pdo, $boat);
    uride_driver_action($pdo, $boat, (int) $ride['id'], 'start');
    uride_driver_action($pdo, $boat, (int) $ride['id'], 'complete');
    $done = $pdo->query('SELECT * FROM uride_requests WHERE id = ' . (int) $ride['id'])->fetch();
    $fare = (int) $done['fare_centavos'];
    $check($done['status'] === 'completed' && uride_wallet_balance($pdo, $boat) - $before === $fare - intdiv($fare * 1000 + 5000, 10000), 'boat operator is paid the fare minus 10% commission');
    echo "\nAll Boat tests passed.\n";
} finally {
    if ($users) {
        $ids = implode(',', $users);
        $codes = $pdo->query("SELECT code FROM uride_requests WHERE user_id IN ($ids)")->fetchAll(PDO::FETCH_COLUMN);
        if ($codes) {
            $g = [];
            foreach ($codes as $c) $pdo->exec('DELETE FROM ledger_entries WHERE entry_group LIKE ' . $pdo->quote('%-' . substr($c, 3)));
        }
        $pdo->exec("DELETE FROM uride_requests WHERE user_id IN ($ids)");
        $pdo->exec("DELETE FROM transactions WHERE user_id IN ($ids)");
        $pdo->exec("DELETE FROM users WHERE id IN ($ids)");
    }
    foreach ($drivers as $d) {
        $pdo->exec("DELETE FROM uride_fleet WHERE driver_id = $d");
        $pdo->exec("UPDATE uride_drivers SET is_online = 0, status = 'rejected', self_drive_ok = 0, review_note = 'Automated test driver' WHERE id = $d");
    }
    foreach ($saved as $k => $v) { if ($v === false) setting_clear($pdo, $k); else setting_save($pdo, $k, (string) $v, null); }
}
