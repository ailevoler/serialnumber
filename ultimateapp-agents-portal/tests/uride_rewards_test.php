<?php
declare(strict_types=1);
// CLI only. Run against a COPY of the database after importing database/uride_rewards_migration.sql:
//   DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tests/uride_rewards_test.php
// Checks driver trip rewards: milestone parsing, the bonus at the exact trip count, paid once, ledger,
// progress for the dashboard, and rewards switched off. Deletes everything it creates.
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
foreach (['uride.rewards_enabled' => '1', 'uride.reward_milestones' => '50:100, 100:200, 200:500, 500:1500, 1000:3000',
    'uride.e_trike.enabled' => '1', 'uride.e_trike.base_centavos' => '5000', 'uride.e_trike.included_km' => '2', 'uride.e_trike.per_km_centavos' => '2500', 'uride.e_trike.minimum_centavos' => '5000',
    'uride.commission_type' => 'fixed', 'uride.commission_fixed_centavos' => '1000', 'uride.min_wallet_centavos' => '5000',
    'uride.enabled' => '1', 'uride.cash_enabled' => '1', 'pay.cash_enabled' => '1', 'uride.night_surcharge_bp' => '0', 'uride.dispatch_radius_km' => '3', 'uride.request_timeout_min' => '5'] as $k => $v) $set($k, $v);
$users = []; $drivers = [];
try {
    $check(uride_rewards_ready($pdo), 'rewards table is installed');
    $check(uride_parse_milestones('100:200, 50:100') === [50 => 10000, 100 => 20000], 'milestones parse and sort (trips:pesos)');
    $throws(fn() => uride_parse_milestones('fifty:100'), 'trips:pesos', 'bad milestone text is refused');
    $throws(fn() => uride_parse_milestones('50:100, 50:200'), 'once', 'a milestone cannot repeat');

    $mk = static function (int $trips) use ($pdo, &$drivers): int {
        $code = uride_driver_new_code($pdo);
        $pdo->prepare("INSERT INTO uride_drivers (code, full_name, email, mobile, password_hash, birthdate, address, barangay, license_no, license_expiry, vehicle_type, plate_no, vehicle_model, vehicle_color, status, trips_completed)
            VALUES (?, 'Reward Driver', ?, ?, 'x', '1990-01-01', 'Station 2', 'Balabag', ?, '2030-01-01', 'e_trike', ?, 'Bemac', 'Green', 'approved', ?)")
            ->execute([$code, strtolower($code) . '@test.local', '0917' . random_int(1000000, 9999999), 'N01-' . random_int(10000, 99999), 'RW' . random_int(10000, 99999), $trips]);
        $id = (int) $pdo->lastInsertId();
        $drivers[] = $id;
        uride_tx($pdo, fn() => uride_wallet_change($pdo, $id, 10000, 'adjustment', 'TEST-' . $id, null, 'test float'));
        uride_set_online($pdo, $id, true);
        uride_heartbeat($pdo, $id, 11.9660, 121.9250);
        return $id;
    };
    $drv = static fn(int $id): array => $pdo->query("SELECT * FROM uride_drivers WHERE id = $id")->fetch();
    $pdo->prepare("INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES ('Reward Rider', ?, ?, 'x', ?, 2000)")->execute(['0918' . random_int(1000000, 9999999), 'rw' . bin2hex(random_bytes(4)) . '@test.local', 'UA-' . strtoupper(bin2hex(random_bytes(5)))]);
    $users[] = $u = (int) $pdo->lastInsertId();
    $trip = static function (int $d) use ($pdo, $u): void {
        $code = uride_book($pdo, $u, ['pickup_address' => "D'Mall Boracay", 'pickup_lat' => '11.9667000', 'pickup_lng' => '121.9246000', 'dropoff_address' => 'Bulabog Beach', 'dropoff_lat' => '11.9720000', 'dropoff_lng' => '121.9330000', 'vehicle_type' => 'e_trike', 'payment_method' => 'cash'], bin2hex(random_bytes(32)));
        $id = (int) $pdo->query('SELECT id FROM uride_requests WHERE code = ' . $pdo->quote($code))->fetchColumn();
        uride_accept($pdo, $d, $id);
        uride_driver_action($pdo, $d, $id, 'start');
        uride_driver_action($pdo, $d, $id, 'complete');
    };

    $d = $mk(48);
    $p = uride_reward_progress($pdo, $drv($d));
    $check($p['next'] === ['trips' => 50, 'amount' => 10000] && $p['pct'] === 96, 'dashboard: 48 / 50 trips, next bonus PHP 100');
    $w0 = uride_wallet_balance($pdo, $d);
    $trip($d);
    $check(uride_wallet_balance($pdo, $d) - $w0 === -1000, 'trip 49: only the PHP 10 fee, no bonus yet');
    $trip($d);
    $check(uride_wallet_balance($pdo, $d) - $w0 === -2000 + 10000, 'trip 50: PHP 100 bonus added to the wallet');
    $row = $pdo->query("SELECT * FROM uride_driver_rewards WHERE driver_id = $d")->fetchAll();
    $check(count($row) === 1 && (int) $row[0]['milestone'] === 50 && (int) $row[0]['amount_centavos'] === 10000, 'reward recorded once for 50 trips');
    $tx = $pdo->query("SELECT type, amount_centavos FROM uride_wallet_tx WHERE driver_id = $d AND type = 'trip_reward'")->fetchAll();
    $check(count($tx) === 1 && (int) $tx[0]['amount_centavos'] === 10000, 'wallet history shows "Trip reward"');
    $led = $pdo->query("SELECT account, debit_centavos, credit_centavos FROM ledger_entries WHERE entry_group = 'RW-$d-50' ORDER BY id")->fetchAll();
    $check(count($led) === 2 && $led[0]['account'] === 'driver_reward_expense' && (int) $led[0]['debit_centavos'] === 10000 && $led[1]['account'] === "driver_wallet:$d" && (int) $led[1]['credit_centavos'] === 10000, 'ledger: driver_reward_expense → driver wallet');
    $check(uride_tx($pdo, fn() => uride_reward_check($pdo, $d, null)) === null && (int) $pdo->query("SELECT COUNT(*) FROM uride_driver_rewards WHERE driver_id = $d")->fetchColumn() === 1, 'checking again never pays twice');
    $p = uride_reward_progress($pdo, $drv($d));
    $check($p['trips'] === 50 && $p['next'] === ['trips' => 100, 'amount' => 20000] && $p['pct'] === 0 && count($p['paid']) === 1, 'next milestone: 100 trips for PHP 200');

    $old = $mk(120);
    $p = uride_reward_progress($pdo, $drv($old));
    $check($p['next']['trips'] === 200, 'a driver already past 100 trips aims for 200 (earlier milestones are not paid back)');
    $w1 = uride_wallet_balance($pdo, $old);
    $trip($old);
    $check(uride_wallet_balance($pdo, $old) - $w1 === -1000 && (int) $pdo->query("SELECT COUNT(*) FROM uride_driver_rewards WHERE driver_id = $old")->fetchColumn() === 0, 'trip 121 pays no back-dated bonus');

    $set('uride.rewards_enabled', '0');
    $off = $mk(49);
    $w2 = uride_wallet_balance($pdo, $off);
    $trip($off);
    $check(uride_wallet_balance($pdo, $off) - $w2 === -1000 && uride_reward_progress($pdo, $drv($off))['milestones'] === [], 'rewards off: no bonus and no card');
    echo "\nAll trip reward tests passed.\n";
} finally {
    if ($users) {
        $ids = implode(',', $users);
        foreach ($pdo->query("SELECT code FROM uride_requests WHERE user_id IN ($ids)")->fetchAll(PDO::FETCH_COLUMN) as $c) $pdo->exec('DELETE FROM ledger_entries WHERE entry_group LIKE ' . $pdo->quote('%-' . substr($c, 3)) . ' OR entry_group = ' . $pdo->quote($c));
        $pdo->exec("DELETE FROM uride_requests WHERE user_id IN ($ids)");
        $pdo->exec("DELETE FROM transactions WHERE user_id IN ($ids)");
        $pdo->exec("DELETE FROM users WHERE id IN ($ids)");
    }
    foreach ($drivers as $d) {
        $pdo->exec("DELETE FROM ledger_entries WHERE entry_group LIKE 'RW-$d-%'");
        $pdo->exec("DELETE FROM uride_driver_rewards WHERE driver_id = $d");
        $pdo->exec("UPDATE uride_drivers SET is_online = 0, status = 'rejected', review_note = 'Automated test driver' WHERE id = $d");
    }
    foreach ($saved as $k => $v) { if ($v === false) setting_clear($pdo, $k); else setting_save($pdo, $k, (string) $v, null); }
}
