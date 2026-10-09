<?php
declare(strict_types=1);
// CLI only. Run against a COPY of the database after importing agents_migration.sql and agents_team_migration.sql:
//   DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tests/agents_team_test.php
// Master Agent / Sub-Agent commissions and overrides. Deletes everything it creates.
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
if (session_status() === PHP_SESSION_NONE) $_SESSION = [];
require __DIR__ . '/../includes/rate_limit.php';
require __DIR__ . '/../includes/uride.php';
require __DIR__ . '/../includes/ueat.php';
require_once __DIR__ . '/../includes/agents.php';

$saved = [];
$set = static function (string $k, string $v) use ($pdo, &$saved): void {
    if (!array_key_exists($k, $saved)) $saved[$k] = $pdo->query('SELECT setting_value FROM app_settings WHERE setting_key = ' . $pdo->quote($k))->fetchColumn();
    setting_save($pdo, $k, $v, null);
};
foreach (['agents.enabled' => '1', 'agents.earn_days' => '365', 'agents.hold_days' => '3',
    'agents.uride.percent_bp' => '200', 'agents.uride.fixed_centavos' => '0', 'agents.sub.uride.percent_bp' => '300', 'agents.sub.uride.fixed_centavos' => '0', 'agents.master.uride.percent_bp' => '50', 'agents.master.uride.fixed_centavos' => '0',
    'agents.ueat.percent_bp' => '300', 'agents.ueat.fixed_centavos' => '0', 'agents.sub.ueat.percent_bp' => '300', 'agents.sub.ueat.fixed_centavos' => '0', 'agents.master.ueat.percent_bp' => '100', 'agents.master.ueat.fixed_centavos' => '0',
    'agents.sub.ugo.percent_bp' => '500', 'agents.sub.ugo.fixed_centavos' => '0', 'agents.master.ugo.percent_bp' => '100', 'agents.master.ugo.fixed_centavos' => '500',
    'uride.commission_type' => 'percent', 'uride.commission_percent_bp' => '1000', 'uride.min_wallet_centavos' => '0'] as $k => $v) $set($k, $v);

$agents = []; $users = []; $rides = []; $orders = []; $driver = 0;
$mob = static fn(string $p): string => $p . substr((string) random_int(10000000, 99999999), 0, 7);
$mkAgent = static function (string $status, ?int $parent = null) use ($pdo, &$agents, $mob): array {
    $code = agent_new_code($pdo);
    $pdo->prepare("INSERT INTO agents (code, parent_agent_id, full_name, email, mobile, password_hash, status) VALUES (?, ?, 'Team Test', ?, ?, 'x', ?)")
        ->execute([$code, $parent, strtolower($code) . '@test.local', $mob('0917'), $status]);
    $agents[] = $id = (int) $pdo->lastInsertId();
    return ['id' => $id, 'code' => $code];
};
$mkUser = static function () use ($pdo, &$users, $mob): int {
    $pdo->prepare("INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES ('Team Customer', ?, ?, 'x', ?, 5000)")
        ->execute([$mob('0919'), 'c' . bin2hex(random_bytes(4)) . '@test.local', 'UA-TT' . strtoupper(bin2hex(random_bytes(4)))]);
    return $users[] = (int) $pdo->lastInsertId();
};
$rows = static function (string $type, int $id) use ($pdo): array {
    $s = $pdo->prepare('SELECT kind, agent_id, from_agent_id, amount_centavos, status FROM agent_commissions WHERE source_type = ? AND source_id = ? ORDER BY kind');
    $s->execute([$type, $id]);
    return $s->fetchAll(PDO::FETCH_UNIQUE);
};
$bal = static function (int $id) use ($pdo): int { return (int) $pdo->query('SELECT balance_centavos FROM agents WHERE id = ' . $id)->fetchColumn(); };

try {
    // ---------- Rates
    $check(agent_commission_for('URide', 10000, 'direct') === 200 && agent_commission_for('URide', 10000, 'sub') === 300 && agent_commission_for('URide', 10000, 'override') === 50, 'three rate tiers: Agent 2%, Sub-Agent 3%, Master override 0.5%');
    $check(agent_commission_for('UGo', 100000, 'override') === 1500, 'override can be % + fixed (1% of 1,000 + PHP 5)');

    // ---------- Team set-up rules
    $m = $mkAgent('approved');
    $s = $mkAgent('approved', $m['id']);
    $check(agent_find_master_by_code($pdo, $m['code'])['id'] === $m['id'], 'Master Agent code accepted for joining a team');
    $check(agent_find_master_by_code($pdo, $s['code']) === null, 'a Sub-Agent code cannot be used as a Master code (one level only)');
    $throws(fn() => agent_set_master($pdo, $m['id'], $m['id']), 'own Master', 'an agent cannot be their own Master');
    $other = $mkAgent('approved');
    $throws(fn() => agent_set_master($pdo, $other['id'], $s['id']), 'is a Sub-Agent', 'cannot join a Sub-Agent');
    $throws(fn() => agent_set_master($pdo, $m['id'], $other['id']), 'own Sub-Agents', 'a Master with a team cannot become a Sub-Agent');
    agent_set_master($pdo, $other['id'], $m['id']); agent_set_master($pdo, $other['id'], null);
    $check($pdo->query('SELECT parent_agent_id FROM agents WHERE id = ' . $other['id'])->fetchColumn() === null, 'Admin can move an agent into a team and back out');

    // ---------- Sub-Agent's customer: URide completed -> Sub-Agent rate + Master override, both earned
    $cs = $mkUser(); agent_attach_referral($pdo, $cs, $s['code']);
    $cm = $mkUser(); agent_attach_referral($pdo, $cm, $m['code']);
    $code = uride_driver_new_code($pdo);
    $pdo->prepare("INSERT INTO uride_drivers (code, full_name, email, mobile, password_hash, birthdate, address, barangay, license_no, license_expiry, vehicle_type, plate_no, vehicle_model, vehicle_color, status, wallet_centavos)
        VALUES (?, 'Team Driver', ?, ?, 'x', '1990-01-01', 'Station 2', 'Balabag', ?, '2030-01-01', 'e_trike', ?, 'Bemac', 'Green', 'approved', 10000)")
        ->execute([$code, strtolower($code) . '@test.local', $mob('0922'), 'L' . $code, 'P' . $code]);
    $driver = (int) $pdo->lastInsertId();
    $ride = static function (int $userId) use ($pdo, $driver, &$rides): int {
        $pdo->prepare("INSERT INTO uride_requests (user_id, driver_id, request_key, code, vehicle_type, pickup_address, pickup_lat, pickup_lng, dropoff_address, dropoff_lat, dropoff_lng, distance_km, fare_centavos, payment_method, commission_centavos, status)
            VALUES (?, ?, ?, ?, 'e_trike', 'A', 11.96, 121.92, 'B', 11.97, 121.93, 2, 10000, 'cash', 1000, 'in_progress')")->execute([$userId, $driver, bin2hex(random_bytes(32)), 'UR-T' . strtoupper(bin2hex(random_bytes(4)))]);
        $rides[] = $id = (int) $pdo->lastInsertId();
        uride_driver_action($pdo, $driver, $id, 'complete');
        return $id;
    };
    $r1 = $rows('uride', $ride($cs));
    $check(count($r1) === 2 && (int) $r1['direct']['agent_id'] === $s['id'] && (int) $r1['direct']['amount_centavos'] === 300 && $r1['direct']['status'] === 'approved', 'Sub-Agent earns the Sub-Agent rate (3% of PHP 100 = PHP 3)');
    $check((int) $r1['override']['agent_id'] === $m['id'] && (int) $r1['override']['from_agent_id'] === $s['id'] && (int) $r1['override']['amount_centavos'] === 50 && $r1['override']['status'] === 'approved', 'Master earns the override on top (0.5% = PHP 0.50), linked to the Sub-Agent');
    $check($bal($s['id']) === 300 && $bal($m['id']) === 50, 'both balances credited; Sub-Agent not reduced by the override');

    // ---------- Master's own customer: Agent rate, no override
    $r2 = $rows('uride', $ride($cm));
    $check(count($r2) === 1 && (int) $r2['direct']['amount_centavos'] === 200 && $bal($m['id']) === 250, "Master's own customer: Agent rate 2%, no override row");

    // ---------- UEat: both pending, cancel reverses both
    $summary = ['restaurant_id' => 'cafe', 'lines' => [['id' => 'iced-latte', 'name' => 'Iced Latte', 'quantity' => 2, 'unit' => 175, 'total' => 350]], 'count' => 2, 'total' => 350];
    $ref = ueat_place_order($pdo, $cs, $summary, bin2hex(random_bytes(32)), 'pickup', '', '', true);
    $orders[] = $oid = (int) $pdo->query('SELECT id FROM ueat_orders WHERE reference = ' . $pdo->quote($ref))->fetchColumn();
    $r3 = $rows('ueat', $oid);
    $check($r3['direct']['status'] === 'pending' && $r3['override']['status'] === 'pending' && (int) $r3['override']['amount_centavos'] === 350, 'UEat: Sub-Agent and Master both pending (override 1% = PHP 3.50)');
    ueat_cancel_order($pdo, $cs, $ref);
    $r3 = $rows('ueat', $oid);
    $check($r3['direct']['status'] === 'reversed' && $r3['override']['status'] === 'reversed' && $bal($m['id']) === 250, 'cancelling reverses both together');

    // ---------- UGo request completed: both approved via the source
    $pdo->prepare("INSERT INTO service_requests (reference, request_key, user_id, service_code, item_id, intent, quantity) VALUES (?, ?, ?, 'UGo', 'island-hop', 'booking', 1)")->execute(['SR-TT' . strtoupper(bin2hex(random_bytes(4))), bin2hex(random_bytes(32)), $cs]);
    $sr = (int) $pdo->lastInsertId();
    agent_record_commission($pdo, $cs, 'UGo', 'service_request', $sr, 'SR-TT', 140000, false);
    agent_tx($pdo, fn() => agent_approve_source($pdo, 'service_request', $sr, 'done'));
    $r4 = $rows('service_request', $sr);
    $check($r4['direct']['status'] === 'approved' && $r4['override']['status'] === 'approved' && (int) $r4['override']['amount_centavos'] === 1900, 'UGo completed: both earned (override 1% of 1,400 + PHP 5 = PHP 19)');
    $pdo->exec('DELETE FROM service_requests WHERE id = ' . $sr);

    // ---------- Suspended Master: Sub-Agent still earns, no override
    $pdo->exec("UPDATE agents SET status = 'suspended' WHERE id = " . $m['id']);
    $r5 = $rows('uride', $ride($cs));
    $check(count($r5) === 1 && (int) $r5['direct']['amount_centavos'] === 300, 'suspended Master: Sub-Agent still paid, no override');
    $pdo->exec("UPDATE agents SET status = 'approved' WHERE id = " . $m['id']);

    // ---------- Books
    foreach ([$m['id'], $s['id']] as $aid) {
        $l = $pdo->prepare('SELECT COALESCE(SUM(credit_centavos - debit_centavos),0) FROM ledger_entries WHERE account = ?');
        $l->execute(['agent_payable:' . $aid]);
        $check((int) $l->fetchColumn() === $bal($aid), "ledger agent_payable:$aid equals balance");
    }
    echo "\nAll Master / Sub-Agent tests passed.\n";
} finally {
    $ids = static fn(array $a): string => $a ? implode(',', array_map('intval', $a)) : '0';
    $groups = $pdo->query('SELECT id FROM agent_commissions WHERE agent_id IN (' . $ids($agents) . ')')->fetchAll(PDO::FETCH_COLUMN);
    $g = array_merge(array_map(static fn($i) => 'AC-' . $i, $groups), array_map(static fn($i) => 'ACR-' . $i, $groups));
    $codes = $rides ? $pdo->query('SELECT code FROM uride_requests WHERE id IN (' . $ids($rides) . ')')->fetchAll(PDO::FETCH_COLUMN) : [];
    $all = array_merge($g, $codes);
    if ($all) $pdo->exec('DELETE FROM ledger_entries WHERE entry_group IN (' . implode(',', array_map([$pdo, 'quote'], $all)) . ')');
    $pdo->exec('DELETE FROM agent_commissions WHERE agent_id IN (' . $ids($agents) . ')');
    $pdo->exec('DELETE FROM ueat_order_items WHERE order_id IN (' . $ids($orders) . ')');
    $pdo->exec('DELETE FROM ueat_orders WHERE id IN (' . $ids($orders) . ')');
    $pdo->exec('DELETE FROM uride_wallet_tx WHERE driver_id = ' . (int) $driver);
    $pdo->exec('DELETE FROM uride_requests WHERE id IN (' . $ids($rides) . ')');
    $pdo->exec('DELETE FROM uride_drivers WHERE id = ' . (int) $driver);
    $pdo->exec('DELETE FROM transactions WHERE user_id IN (' . $ids($users) . ')');
    $pdo->exec('DELETE FROM users WHERE id IN (' . $ids($users) . ')');
    $pdo->exec('UPDATE agents SET parent_agent_id = NULL WHERE id IN (' . $ids($agents) . ')');
    $pdo->exec('DELETE FROM agents WHERE id IN (' . $ids($agents) . ')');
    foreach ($saved as $k => $v) { if ($v === false) setting_clear($pdo, $k); else setting_save($pdo, $k, (string) $v, null); }
}
