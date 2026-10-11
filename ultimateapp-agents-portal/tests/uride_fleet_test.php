<?php
declare(strict_types=1);
// CLI only. Run against a COPY of the database after importing database/uride_rental_migration.sql and
// database/uride_selfdrive_migration.sql and database/uride_fleet_migration.sql:  DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tests/uride_selfdrive_test.php
// Checks E-Bike and Van, rental fleets (many units per owner) and the available-units counts.
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
require_once __DIR__ . '/../includes/kyc.php';

$saved = [];
$set = static function (string $k, string $v) use ($pdo, &$saved): void {
    if (!array_key_exists($k, $saved)) $saved[$k] = $pdo->query('SELECT setting_value FROM app_settings WHERE setting_key = ' . $pdo->quote($k))->fetchColumn();
    setting_save($pdo, $k, $v, null);
};
foreach (['uride.enabled' => '1', 'uride.credits_enabled' => '1', 'uride.cash_enabled' => '1', 'uride.rental_enabled' => '1', 'uride.rental_day_hours' => '8', 'uride.rental_max_hours' => '12', 'uride.rental_schedule_days' => '7',
    'uride.self_drive_enabled' => '1', 'uride.self_drive_kyc' => '0', 'uride.self_drive_grace_min' => '15',
    'uride.e_bike.enabled' => '0', 'uride.e_bike.self_enabled' => '1', 'uride.e_bike.self_hour_centavos' => '10000', 'uride.e_bike.self_min_hours' => '1', 'uride.e_bike.self_day_centavos' => '60000', 'uride.e_bike.self_deposit_centavos' => '100000', 'uride.e_bike_license' => '0',
    'uride.van.enabled' => '1', 'uride.van.rental_enabled' => '1', 'uride.van.rental_hour_centavos' => '150000', 'uride.van.rental_min_hours' => '3', 'uride.van.rental_day_centavos' => '900000',
    'uride.van.self_enabled' => '1', 'uride.van.self_hour_centavos' => '120000', 'uride.van.self_min_hours' => '4', 'uride.van.self_day_centavos' => '700000', 'uride.van.self_deposit_centavos' => '1000000',
    'uride.e_trike.self_enabled' => '1', 'uride.e_trike.self_hour_centavos' => '35000', 'uride.e_trike.self_min_hours' => '2', 'uride.e_trike.self_day_centavos' => '200000', 'uride.e_trike.self_deposit_centavos' => '200000',
    'uride.car.self_enabled' => '1', 'uride.car.self_hour_centavos' => '60000', 'uride.car.self_min_hours' => '4', 'uride.car.self_day_centavos' => '350000', 'uride.car.self_deposit_centavos' => '500000',
    'uride.commission_type' => 'percent', 'uride.commission_percent_bp' => '1000', 'uride.min_wallet_centavos' => '5000', 'uride.dispatch_radius_km' => '3', 'uride.request_timeout_min' => '5', 'kyc.required_user' => '0'] as $k => $v) $set($k, $v);
$users = []; $drivers = []; $photos = [];
$key = static fn(): string => bin2hex(random_bytes(32));
$jpeg = static function (): array {
    $im = imagecreatetruecolor(900, 560); imagefill($im, 0, 0, imagecolorallocate($im, 220, 230, 240));
    $tmp = tempnam(sys_get_temp_dir(), 'lic'); imagejpeg($im, $tmp, 80); imagedestroy($im);
    return ['name' => 'license.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
};
$base = ['pickup_address' => "D'Mall Boracay", 'pickup_lat' => '11.9667000', 'pickup_lng' => '121.9246000', 'vehicle_type' => 'e_bike', 'payment_method' => 'credits', 'rental_hours' => '4', 'start_mode' => 'now', 'rental_mode' => 'self', 'self_terms' => '1'];
try {
    $check(array_keys(uride_vehicles()) === ['e_bike', 'motorcycle', 'e_trike', 'car', 'van', 'boat'], 'vehicles: E-Bike, Motorcycle, E-Trike, Car, Van, Boat');
    $check(uride_vehicles()['van']['seats'] === '10–12 passengers', 'Van seats 10-12');
    $check(!uride_quote('e_bike', 2)['enabled'] && uride_quote('van', 2)['enabled'], 'E-Bike is rental only; Van can be booked for rides');
    $check(uride_quote('van', 2)['fare'] >= 30000, 'Van ride fare from ₱300');
    $q = uride_rental_quote('e_bike', '2', 'self');
    $check($q['fare'] === 20000 && $q['deposit'] === 100000, 'E-Bike self-drive 2 h × ₱100 = ₱200 + ₱1,000 deposit');
    $check(uride_rental_quote('van', '1')['fare'] === 450000, 'Van with driver: minimum 3 hours (₱4,500)');
    $check(uride_license_class('e_bike') === 'none' && uride_license_class('van') === 'car', 'E-Bike needs no license (ID only); Van needs code B');

    $pdo->prepare("INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES ('Fleet Test', ?, ?, 'x', ?, 20000)")->execute(['0918' . random_int(1000000, 9999999), 'fl' . bin2hex(random_bytes(4)) . '@test.local', 'UA-' . strtoupper(bin2hex(random_bytes(5)))]);
    $users[] = $u = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES ('Fleet Test 2', ?, ?, 'x', ?, 20000)")->execute(['0918' . random_int(1000000, 9999999), 'fl' . bin2hex(random_bytes(4)) . '@test.local', 'UA-' . strtoupper(bin2hex(random_bytes(5)))]);
    $users[] = $u2 = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES ('Fleet Test 3', ?, ?, 'x', ?, 20000)")->execute(['0918' . random_int(1000000, 9999999), 'fl' . bin2hex(random_bytes(4)) . '@test.local', 'UA-' . strtoupper(bin2hex(random_bytes(5)))]);
    $users[] = $u3 = (int) $pdo->lastInsertId();

    $before = uride_rental_counts($pdo);
    $mk = static function (string $vehicle) use ($pdo, &$drivers): int {
        $code = uride_driver_new_code($pdo);
        $pdo->prepare("INSERT INTO uride_drivers (code, full_name, email, mobile, password_hash, birthdate, address, barangay, license_no, license_expiry, vehicle_type, plate_no, vehicle_model, vehicle_color, status)
            VALUES (?, 'Fleet Owner', ?, ?, 'x', '1990-01-01', 'Station 2', 'Balabag', ?, '2030-01-01', ?, ?, 'Hiace', 'White', 'approved')")
            ->execute([$code, strtolower($code) . '@test.local', '0917' . random_int(1000000, 9999999), 'N01-' . random_int(10000, 99999), $vehicle, 'F' . random_int(10000, 99999)]);
        $id = (int) $pdo->lastInsertId();
        $drivers[] = $id;
        uride_tx($pdo, fn() => uride_wallet_change($pdo, $id, 100000, 'adjustment', 'TEST-' . $id, null, 'test float'));
        uride_set_online($pdo, $id, true);
        uride_heartbeat($pdo, $id, 11.9660, 121.9250);
        return $id;
    };
    $owner = $mk('van');
    $throws(fn() => uride_fleet_save($pdo, $owner, ['e_bike' => 999]), '0 to 200', 'units limit');
    uride_fleet_save($pdo, $owner, ['e_bike' => 2, 'van' => 1], ['e_bike' => 'Yadea E3']);
    $check(uride_fleet_units($pdo, $owner) === ['e_bike' => 2, 'van' => 1] && (int) $pdo->query("SELECT self_drive_ok FROM uride_drivers WHERE id = $owner")->fetchColumn() === 1, 'fleet saved: 2 E-Bikes + 1 Van');
    $c = uride_rental_counts($pdo);
    $check($c['e_bike']['self'] - $before['e_bike']['self'] === 2 && $c['van']['self'] - $before['van']['self'] === 1, 'customers see 2 E-Bikes and 1 Van available for self-drive');
    $check($c['van']['driver'] - $before['van']['driver'] === 1, 'and 1 Van with driver (the owner is online and free)');

    // Two E-Bikes rented at the same time by one owner.
    $c1 = uride_book_rental($pdo, $u, $base + ['rental_hours' => '2'], $key());
    $check((bool) $c1, 'E-Bike self-drive booked without a driver\'s license (ID only)');
    $r1 = $pdo->query('SELECT * FROM uride_requests WHERE code = ' . $pdo->quote($c1))->fetch();
    $check(in_array($c1, array_column(uride_feed($pdo, $pdo->query("SELECT * FROM uride_drivers WHERE id = $owner")->fetch()), 'code'), true), 'owner sees the E-Bike request (fleet type, not their own vehicle type)');
    uride_accept($pdo, $owner, (int) $r1['id']);
    uride_driver_action($pdo, $owner, (int) $r1['id'], 'start', 'license_checked');
    $check(uride_driver_active_ride($pdo, $owner) === null, 'a handed-over self-drive vehicle does not keep the owner busy');
    $check(uride_fleet_free($pdo, $owner)['e_bike'] === 1, '1 E-Bike still free');
    $c2 = uride_book_rental($pdo, $u2, $base + ['rental_hours' => '2'], $key());
    $r2 = $pdo->query('SELECT * FROM uride_requests WHERE code = ' . $pdo->quote($c2))->fetch();
    uride_accept($pdo, $owner, (int) $r2['id']);
    $check(uride_fleet_free($pdo, $owner)['e_bike'] === 0 && count(uride_fleet_in_use($pdo, $owner)) === 1, 'second E-Bike rented out: 0 free');
    $check(uride_rental_counts($pdo)['e_bike']['self'] === $before['e_bike']['self'], 'customers see no free E-Bike from this owner');
    if ($before['e_bike']['self'] === 0) $throws(fn() => uride_book_rental($pdo, $u3, $base + ['rental_hours' => '2'], $key()), 'No E-Bike for self-drive is available right now', 'booking a third E-Bike now is refused');
    $throws(fn() => uride_fleet_save($pdo, $owner, ['e_bike' => 1, 'van' => 1]), 'rented out now', 'cannot cut units below the ones out on rent');
    $later = date('Y-m-d\TH:i', time() + 5 * 3600);
    $c3 = uride_book_rental($pdo, $u3, ['start_mode' => 'later', 'scheduled_at' => $later] + $base + ['rental_hours' => '2'], $key());
    $check((bool) $c3, 'booking an E-Bike for later works (units are back by then)');
    $check(uride_rental_available($pdo, 'e_bike', 'self', date('Y-m-d H:i:00', strtotime($later)), 2) === max(0, $before['e_bike']['fleet'] + 2 - 1), 'later: fleet units minus the overlapping booking');
    $check(count(uride_driver_rentals_out($pdo, $owner)) === 1, 'dashboard lists the E-Bike that is out');

    // E-Bike chosen under "With driver" is booked as self-drive.
    $c4 = uride_book_rental($pdo, $u2, ['rental_mode' => 'driver', 'start_mode' => 'later', 'scheduled_at' => date('Y-m-d\\TH:i', time() + 8 * 3600)] + $base + ['rental_hours' => '2'], $key());
    $check($pdo->query('SELECT rental_mode FROM uride_requests WHERE code = ' . $pdo->quote($c4))->fetchColumn() === 'self', 'E-Bike rental with "With driver" selected becomes self-drive');
    uride_cancel_by_passenger($pdo, $u2, (int) $pdo->query('SELECT id FROM uride_requests WHERE code = ' . $pdo->quote($c4))->fetchColumn(), 'test');
    // Van self-drive needs a car license (code B).
    $base2 = ['vehicle_type' => 'van', 'rental_hours' => '4'] + $base;
    $throws(fn() => uride_book_rental($pdo, $u3, $base2, $key()), 'license', 'Van self-drive needs a driver\'s license');
    uride_save_license($pdo, $u3, ['license_no' => 'N01-12-777777', 'expiry' => date('Y-m-d', time() + 400 * 86400), 'can_motorcycle' => '1'], $jpeg());
    $throws(fn() => uride_book_rental($pdo, $u3, $base2, $key()), 'code B or B1', 'motorcycle license cannot drive a Van');
    echo "\nAll fleet tests passed.\n";
} finally {
    if ($users) {
        $ids = implode(',', $users);
        $codes = $pdo->query("SELECT code FROM uride_requests WHERE user_id IN ($ids)")->fetchAll(PDO::FETCH_COLUMN);
        if ($codes) {
            $g = [];
            foreach ($codes as $c) { $g[] = $pdo->quote($c); foreach (['RF-', 'DP-', 'DR-', 'LF-', 'DC-'] as $p) $g[] = $pdo->quote($p . substr($c, 3)); }
            $pdo->exec('DELETE FROM ledger_entries WHERE entry_group IN (' . implode(',', $g) . ')');
        }
        $pdo->exec("DELETE FROM uride_requests WHERE user_id IN ($ids)");
        foreach ($pdo->query("SELECT photo_file FROM user_driver_licenses WHERE user_id IN ($ids)")->fetchAll(PDO::FETCH_COLUMN) as $f) @unlink(app_storage_dir('licenses') . '/' . $f);
        $pdo->exec("DELETE FROM user_driver_licenses WHERE user_id IN ($ids)");
        $pdo->exec("DELETE FROM transactions WHERE user_id IN ($ids)");
        $pdo->exec("DELETE FROM users WHERE id IN ($ids)");
    }
    foreach ($drivers as $d) {
        $pdo->exec("DELETE FROM uride_fleet WHERE driver_id = $d");
        $pdo->exec("UPDATE uride_drivers SET is_online = 0, status = 'rejected', self_drive_ok = 0, review_note = 'Automated test driver' WHERE id = $d");
    }
    foreach ($saved as $k => $v) { if ($v === false) setting_clear($pdo, $k); else setting_save($pdo, $k, (string) $v, null); }
}
