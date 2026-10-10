<?php
declare(strict_types=1);
// CLI only. Run against a COPY of the database after importing database/uride_places_migration.sql:
//   DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tests/uride_places_test.php
// Checks URide place search (landmarks, merchants, recent places) and nearest-place naming.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$check = static function (bool $ok, string $msg): void { if (!$ok) throw new RuntimeException('FAILED: ' . $msg); echo "PASS: $msg\n"; };
$pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4', getenv('DB_USER') ?: '', getenv('DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$GLOBALS['pdo'] = $pdo;
require __DIR__ . '/../includes/rate_limit.php';
require __DIR__ . '/../includes/settings.php';
require __DIR__ . '/../includes/uride.php';
require __DIR__ . '/../includes/uride_places.php';
$names = static fn(array $r): array => array_column($r, 'name');
$users = []; $places = []; $merchants = []; $rides = [];
try {
    $check(uride_places_ready($pdo), 'uride_places table is there');
    $check(uride_place_score('dmall', "D'Mall Boracay") > 0 && uride_place_score('d mall', "D'Mall Boracay", 'dmall, d mall') > 0, 'D\'Mall matches "dmall" and "d mall"');
    $check(uride_place_score('station 2', 'Station 2 Beachfront') > uride_place_score('station 2', "D'Mall Boracay", 'station 2'), 'name match ranks above a keyword match');
    $check(uride_place_score('puka xyz', 'Puka Shell Beach') === 0, 'every typed word must match');

    $pdo->prepare("INSERT INTO uride_places (name, area, keywords, latitude, longitude, verified, sort_order) VALUES ('Zz Test Lighthouse', 'Yapak', 'parola', 11.99, 121.92, 1, 5)")->execute();
    $places[] = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES ('Places Test', ?, ?, 'x', ?, 0)")->execute(['0918' . random_int(1000000, 9999999), 'pl' . bin2hex(random_bytes(4)) . '@test.local', 'UA-' . strtoupper(bin2hex(random_bytes(5)))]);
    $users[] = $u = (int) $pdo->lastInsertId();
    $code = 'TM' . strtoupper(bin2hex(random_bytes(3)));
    $pdo->prepare("INSERT INTO merchants (code, business_name, owner_name, email, mobile, category, barangay, address, status, latitude, longitude, password_hash) VALUES (?, 'Zz Mango Shake Hut', 'Owner', ?, '09170000000', 'food', 'Balabag', 'Station 2 road', 'approved', 11.9661234, 121.9251234, 'x')")
        ->execute(['UM-' . strtoupper(bin2hex(random_bytes(5))), strtolower($code) . '@test.local']);
    $merchants[] = (int) $pdo->lastInsertId();

    $r = uride_place_search($pdo, $u, 'parola');
    $check(($r[0]['name'] ?? '') === 'Zz Test Lighthouse' && $r[0]['kind'] === 'landmark' && abs($r[0]['lat'] - 11.99) < 1e-6, 'landmark found by its other name, with coordinates');
    $r = uride_place_search($pdo, $u, 'mango shake');
    $check(($r[0]['name'] ?? '') === 'Zz Mango Shake Hut' && $r[0]['kind'] === 'merchant' && abs($r[0]['lng'] - 121.9251234) < 1e-7, 'approved merchant with a map pin is found (7-decimal pin)');
    $r = uride_place_search($pdo, $u, '');
    $check(in_array("D'Mall Boracay", $names($r), true) && ($r[0]['name'] ?? '') === 'Zz Test Lighthouse', 'empty field: landmarks by Admin order');
    $pdo->prepare("INSERT INTO uride_requests (user_id, request_key, code, vehicle_type, pickup_address, pickup_lat, pickup_lng, dropoff_address, dropoff_lat, dropoff_lng, distance_km, payment_method, status, expires_at)
        VALUES (?, ?, ?, 'e_trike', 'Zz My Hotel Gate', 11.9701, 121.9222, 'Pinned location (11.9, 121.9)', 11.95, 121.93, 2, 'cash', 'cancelled', NOW())")->execute([$u, bin2hex(random_bytes(32)), 'URT' . strtoupper(bin2hex(random_bytes(3)))]);
    $rides[] = (int) $pdo->lastInsertId();
    $r = uride_place_search($pdo, $u, '');
    $check(($r[0]['name'] ?? '') === 'Zz My Hotel Gate' && $r[0]['kind'] === 'recent' && !in_array('Pinned location (11.9, 121.9)', $names($r), true), 'recent named places come first; bare pins are skipped');
    $check(!in_array('Zz My Hotel Gate', $names(uride_place_search($pdo, $u + 999999, '')), true), 'recent places are private to each customer');
    $n = uride_place_nearest($pdo, 11.9901, 121.9201);
    $check(($n['name'] ?? '') === 'Zz Test Lighthouse', 'a pin 20 m away is named after the nearest place');
    $check(uride_place_nearest($pdo, 12.5, 122.5) === null, 'no name when nothing is within 400 m');
    $pdo->exec('UPDATE uride_places SET active = 0 WHERE id = ' . $places[0]);
    $check(!in_array('Zz Test Lighthouse', $names(uride_place_search($pdo, $u, 'parola')), true), 'hidden places are not suggested');
    echo "\nAll URide place tests passed.\n";
} finally {
    if ($rides) $pdo->exec('DELETE FROM uride_requests WHERE id IN (' . implode(',', $rides) . ')');
    if ($merchants) $pdo->exec('DELETE FROM merchants WHERE id IN (' . implode(',', $merchants) . ')');
    if ($places) $pdo->exec('DELETE FROM uride_places WHERE id IN (' . implode(',', $places) . ')');
    if ($users) $pdo->exec('DELETE FROM users WHERE id IN (' . implode(',', $users) . ')');
}
