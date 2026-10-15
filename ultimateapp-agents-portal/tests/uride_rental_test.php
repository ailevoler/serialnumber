<?php
declare(strict_types=1);
// CLI only. Run against a COPY of the database after importing database/uride_rental_migration.sql:
//   DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tests/uride_rental_test.php
// Checks Rent a Ride: pricing, booking and Credits hold, scheduling, driver dispatch and settlement, refunds.
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
foreach (['uride.enabled' => '1', 'uride.credits_enabled' => '1', 'uride.cash_enabled' => '1', 'uride.rental_enabled' => '1', 'uride.rental_day_hours' => '8', 'uride.rental_max_hours' => '12',
    'uride.rental_schedule_days' => '7', 'uride.e_trike.rental_enabled' => '1', 'uride.e_trike.rental_hour_centavos' => '50000', 'uride.e_trike.rental_min_hours' => '1',
    'uride.e_trike.rental_day_centavos' => '300000', 'uride.car.rental_enabled' => '1', 'uride.car.rental_hour_centavos' => '80000', 'uride.car.rental_min_hours' => '2', 'uride.car.rental_day_centavos' => '500000',
    'uride.commission_type' => 'percent', 'uride.commission_percent_bp' => '1000', 'uride.rental_commission_bp' => '1500', 'uride.min_wallet_centavos' => '5000', 'uride.dispatch_radius_km' => '3', 'uride.request_timeout_min' => '5', 'uride.e_trike.enabled' => '1'] as $k => $v) $set($k, $v);
$users = []; $drivers = [];
$key = static fn(): string => bin2hex(random_bytes(32));
$base = ['pickup_address' => "D'Mall Boracay", 'pickup_lat' => '11.9667000', 'pickup_lng' => '121.9246000', 'vehicle_type' => 'e_trike', 'payment_method' => 'credits', 'rental_hours' => '3', 'start_mode' => 'now', 'passenger_note' => 'Puka then Mt. Luho'];
try {
    // ---------- pricing
    $q = uride_rental_quote('e_trike', '3');
    $check($q['fare'] === 150000 && $q['hours'] === 3 && !$q['capped'], 'E-Trike 3 hours x PHP 500 = PHP 1,500');
    $q = uride_rental_quote('e_trike', '8');
    $check($q['fare'] === 300000 && $q['capped'], '8 hours (PHP 4,000) is capped at the whole-day price PHP 3,000');
    $q = uride_rental_quote('e_trike', 'day');
    $check($q['fare'] === 300000 && $q['hours'] === 8, 'whole day = 8 hours for PHP 3,000');
    $q = uride_rental_quote('car', '1');
    $check($q['fare'] === 160000 && $q['billed_hours'] === 2, 'car minimum 2 hours: 1 hour is billed as 2 (PHP 1,600)');
    $q = uride_rental_quote('e_trike', '12');
    $check($q['fare'] === 600000 && !$q['capped'], '12 hours is longer than a whole day, so it is not capped (PHP 6,000)');
    $throws(fn() => uride_rental_quote('e_trike', '5'), 'how long', 'only offered durations are accepted');
    $set('uride.rental_max_hours', '6');
    $check(!isset(uride_rental_packages()['8']) && isset(uride_rental_packages()['6'], uride_rental_packages()['day']), 'Admin maximum hides longer hourly packages');
    $set('uride.rental_max_hours', '12');

    // An E-Trike driver online nearby: rentals "now" need at least one free driver.
    $mk = static function () use ($pdo, &$drivers): int {
        $code = uride_driver_new_code($pdo);
        $pdo->prepare("INSERT INTO uride_drivers (code, full_name, email, mobile, password_hash, birthdate, address, barangay, license_no, license_expiry, vehicle_type, plate_no, vehicle_model, vehicle_color, status)
            VALUES (?, 'Rental Driver', ?, ?, 'x', '1990-01-01', 'Station 2', 'Balabag', ?, '2030-01-01', 'e_trike', ?, 'Bemac', 'Green', 'approved')")
            ->execute([$code, strtolower($code) . '@test.local', '0917' . random_int(1000000, 9999999), 'N01-' . random_int(10000, 99999), 'T' . random_int(10000, 99999)]);
        $id = (int) $pdo->lastInsertId();
        $drivers[] = $id;
        uride_tx($pdo, fn() => uride_wallet_change($pdo, $id, 100000, 'adjustment', 'TEST-' . $id, null, 'test float'));
        uride_set_online($pdo, $id, true);
        uride_heartbeat($pdo, $id, 11.9660, 121.9250);
        return $id;
    };
    $early = $mk();
    // ---------- booking
    $pdo->prepare("INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES ('Rental Test', ?, ?, 'x', ?, 5000)")->execute(['0918' . random_int(1000000, 9999999), 'rt' . bin2hex(random_bytes(4)) . '@test.local', 'UA-' . strtoupper(bin2hex(random_bytes(5)))]);
    $users[] = $u = (int) $pdo->lastInsertId();
    $credits = static fn(): float => (float) $pdo->query("SELECT credits FROM users WHERE id = $u")->fetchColumn();
    $k = $key();
    $code = uride_book_rental($pdo, $u, $base, $k);
    $r = $pdo->query('SELECT * FROM uride_requests WHERE code = ' . $pdo->quote($code))->fetch();
    $check($r['trip_type'] === 'rental' && (int) $r['rental_hours'] === 3 && (int) $r['fare_centavos'] === 150000 && (int) $r['held_centavos'] === 150000, 'rental booked; PHP 1,500 Credits held');
    $check(abs($credits() - 3500) < 0.001 && str_contains($r['dropoff_address'], '3-hour rental') && $r['dropoff_lat'] === $r['pickup_lat'], 'Credits deducted; rental ends back at the pick-up');
    $check(uride_book_rental($pdo, $u, $base, $k) === $code, 'same request key: no double booking');
    $throws(fn() => uride_book($pdo, $u, $base + ['dropoff_address' => 'Station 1', 'dropoff_lat' => '11.98', 'dropoff_lng' => '121.918'], $key()), 'active ride', 'cannot book a ride during an active rental');
    uride_cancel_by_passenger($pdo, $u, (int) $r['id'], 'test');
    $check(abs($credits() - 5000) < 0.001, 'cancelling returns the held Credits');
    $pdo->exec("UPDATE users SET credits = 2000 WHERE id = $u");
    $throws(fn() => uride_book_rental($pdo, $u, ['rental_hours' => 'day'] + $base, $key()), 'Not enough Credits', 'whole day (PHP 3,000) needs enough Credits');
    $pdo->exec("UPDATE users SET credits = 5000 WHERE id = $u");

    // ---------- scheduling
    $later = date('Y-m-d\TH:i', time() + 3 * 3600);
    $throws(fn() => uride_book_rental($pdo, $u, ['start_mode' => 'later', 'scheduled_at' => date('Y-m-d\TH:i', time() + 600)] + $base, $key()), '30 minutes', 'schedule must be at least 30 minutes ahead');
    $throws(fn() => uride_book_rental($pdo, $u, ['start_mode' => 'later', 'scheduled_at' => date('Y-m-d\TH:i', time() + 9 * 86400)] + $base, $key()), '7 days', 'schedule within the Admin limit');
    $sc = uride_book_rental($pdo, $u, ['start_mode' => 'later', 'scheduled_at' => $later, 'payment_method' => 'cash'] + $base, $key());
    $s = $pdo->query('SELECT * FROM uride_requests WHERE code = ' . $pdo->quote($sc))->fetch();
    $check(uride_is_upcoming($s) && strtotime($s['expires_at']) === strtotime($s['scheduled_at']) + 900, 'scheduled rental is upcoming; open until 15 min after the start');
    $throws(fn() => uride_book_rental($pdo, $u, ['start_mode' => 'later', 'scheduled_at' => date('Y-m-d\TH:i', time() + 5 * 3600)] + $base, $key()), 'upcoming rental', 'one upcoming rental at a time');
    $rc = uride_book($pdo, $u, ['dropoff_address' => 'Station 1', 'dropoff_lat' => '11.9797', 'dropoff_lng' => '121.9183', 'payment_method' => 'cash'] + $base, $key());
    $check(str_starts_with($rc, 'UR-'), 'an upcoming rental does not block booking a normal ride now');
    $ride = $pdo->query('SELECT id FROM uride_requests WHERE code = ' . $pdo->quote($rc))->fetchColumn();
    uride_cancel_by_passenger($pdo, $u, (int) $ride, 'test');

    // ---------- drivers
    $d = $mk();
    $driver = $pdo->query("SELECT * FROM uride_drivers WHERE id = $d")->fetch();
    $feedCodes = static fn(): array => array_column(uride_feed($pdo, $pdo->query("SELECT * FROM uride_drivers WHERE id = $d")->fetch()), 'code');
    $check(!in_array($sc, $feedCodes(), true), 'drivers do not see a rental starting in 3 hours');
    $throws(fn() => uride_accept($pdo, $d, (int) $s['id']), 'starts later', 'a driver cannot accept it early');
    $pdo->exec("UPDATE uride_requests SET scheduled_at = NOW() + INTERVAL 40 MINUTE, expires_at = NOW() + INTERVAL 55 MINUTE WHERE id = " . (int) $s['id']);
    $feed = uride_feed($pdo, $pdo->query("SELECT * FROM uride_drivers WHERE id = $d")->fetch());
    $item = array_values(array_filter($feed, static fn($x) => $x['code'] === $sc))[0] ?? null;
    $check($item && $item['trip_type'] === 'rental' && $item['trip_label'] === '3-hour rental' && $item['starts'] !== null, 'within 60 minutes it appears in the feed as "3-hour rental · starts …"');
    uride_accept($pdo, $d, (int) $s['id']);
    uride_driver_action($pdo, $d, (int) $s['id'], 'arrived');
    uride_driver_action($pdo, $d, (int) $s['id'], 'start');
    $w0 = uride_wallet_balance($pdo, $d);
    uride_driver_action($pdo, $d, (int) $s['id'], 'complete');
    $done = $pdo->query('SELECT * FROM uride_requests WHERE id = ' . (int) $s['id'])->fetch();
    $check($done['status'] === 'completed' && (int) $done['commission_centavos'] === 22500 && uride_wallet_balance($pdo, $d) === $w0 - 22500, 'cash rental completed: 15% rental commission (PHP 225) taken from the driver wallet');

    // Credits rental settles to the driver like a ride.
    $pdo->exec("UPDATE users SET credits = 5000 WHERE id = $u");
    $cc = uride_book_rental($pdo, $u, $base, $key());
    $c = $pdo->query('SELECT * FROM uride_requests WHERE code = ' . $pdo->quote($cc))->fetch();
    uride_accept($pdo, $d, (int) $c['id']);
    uride_driver_action($pdo, $d, (int) $c['id'], 'start');
    $w1 = uride_wallet_balance($pdo, $d);
    uride_driver_action($pdo, $d, (int) $c['id'], 'complete');
    $check(uride_wallet_balance($pdo, $d) === $w1 + 150000 - 22500, 'Credits rental: driver wallet gets PHP 1,500 less PHP 225 (15%) commission');
    $sum = $pdo->query('SELECT SUM(debit_centavos) d, SUM(credit_centavos) c FROM ledger_entries WHERE entry_group = ' . $pdo->quote($cc))->fetch();
    $check((int) $sum['d'] === (int) $sum['c'] && (int) $sum['d'] > 0, 'rental books balance');
    echo "\nAll Rent a Ride tests passed.\n";
} finally {
    foreach ($drivers as $d) {
        $pdo->exec("UPDATE uride_drivers SET is_online = 0, status = 'rejected', review_note = 'Automated test driver' WHERE id = $d");
    }
    if ($users) {
        $ids = implode(',', $users);
        $codes = $pdo->query("SELECT code FROM uride_requests WHERE user_id IN ($ids)")->fetchAll(PDO::FETCH_COLUMN);
        if ($codes) {
            $in = implode(',', array_map([$pdo, 'quote'], $codes));
            $pdo->exec("DELETE FROM ledger_entries WHERE entry_group IN ($in) OR entry_group IN (" . implode(',', array_map(static fn($c) => $pdo->quote('RF-' . substr($c, 3)), $codes)) . ')');
        }
        foreach (['uride_driver_wallet_tx', 'uride_ride_skips'] as $t) {
            try { $pdo->exec("DELETE FROM $t WHERE ride_id IN (SELECT id FROM uride_requests WHERE user_id IN ($ids))"); } catch (Throwable) {}
        }
        try { $pdo->exec("DELETE FROM agent_commissions WHERE source_type = 'uride' AND source_id IN (SELECT id FROM uride_requests WHERE user_id IN ($ids))"); } catch (Throwable) {}
        $pdo->exec("DELETE FROM uride_requests WHERE user_id IN ($ids)");
        $pdo->exec("DELETE FROM transactions WHERE user_id IN ($ids)");
        $pdo->exec("DELETE FROM users WHERE id IN ($ids)");
    }
    foreach ($saved as $k => $v) { if ($v === false) setting_clear($pdo, $k); else setting_save($pdo, $k, (string) $v, null); }
}
