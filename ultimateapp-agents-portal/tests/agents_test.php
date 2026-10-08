<?php
declare(strict_types=1);
// CLI only. Run against a COPY of the database after importing database/agents_migration.sql:
//   DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tests/agents_test.php
// Creates a test agent, customers, a driver, rides, UEat orders and service requests, checks the agent commission
// rules and the ledger, then deletes everything it created.
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
foreach (['agents.enabled' => '1', 'agents.uride.percent_bp' => '200', 'agents.uride.fixed_centavos' => '0', 'agents.ueat.percent_bp' => '300', 'agents.ueat.fixed_centavos' => '0',
    'agents.ugo.percent_bp' => '500', 'agents.ugo.fixed_centavos' => '1000', 'agents.earn_days' => '365', 'agents.hold_days' => '3', 'agents.payout_min_centavos' => '1000',
    'uride.commission_type' => 'percent', 'uride.commission_percent_bp' => '1000', 'uride.min_wallet_centavos' => '0'] as $k => $v) $set($k, $v);

$tag = strtoupper(bin2hex(random_bytes(3)));
$agents = []; $users = []; $driver = 0;
$mkUser = static function (string $name, string $email, string $mobile) use ($pdo, &$users): int {
    $pdo->prepare("INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES (?, ?, ?, 'x', ?, 5000)")->execute([$name, $mobile, $email, 'UA-T' . strtoupper(bin2hex(random_bytes(4)))]);
    return $users[] = (int) $pdo->lastInsertId();
};
$mkAgent = static function (string $status, string $email, string $mobile) use ($pdo, &$agents): array {
    $code = agent_new_code($pdo);
    $pdo->prepare("INSERT INTO agents (code, full_name, email, mobile, password_hash, status, payout_method, payout_account_name, payout_account_no) VALUES (?, 'Test Agent', ?, ?, 'x', ?, 'GCash', 'Test Agent', '09170000000')")->execute([$code, $email, $mobile, $status]);
    $agents[] = $id = (int) $pdo->lastInsertId();
    return ['id' => $id, 'code' => $code];
};
$balance = static function (int $agentId) use ($pdo): int { $s = $pdo->prepare('SELECT balance_centavos FROM agents WHERE id = ?'); $s->execute([$agentId]); return (int) $s->fetchColumn(); };
$commission = static function (string $type, int $id) use ($pdo): ?array { $s = $pdo->prepare('SELECT * FROM agent_commissions WHERE source_type = ? AND source_id = ?'); $s->execute([$type, $id]); return $s->fetch() ?: null; };
$rides = []; $orders = []; $requests = []; $payoutRefs = [];

try {
    // ---------- Maths
    $check(agent_commission_for('URide', 5400) === 108, 'URide 2% of PHP 54.00 = PHP 1.08');
    $check(agent_commission_for('UGo', 140000) === 8000, 'UGo 5% of PHP 1,400 + PHP 10 fixed = PHP 80.00');
    $check(agent_commission_for('UGo', 500) === 500, 'commission never above the activity amount');
    $check(agent_normalize_code(' ag-k7m 2pq ') === 'AGK7M2PQ' && agent_normalize_code('AG1O0000') === null && agent_normalize_code('UM-12345') === null, 'code normalisation (spaces/dashes ok, look-alike characters rejected)');

    // ---------- Referral tagging
    $a = $mkAgent('approved', "agent$tag@test.local", '0917' . substr((string) random_int(1000000, 9999999), 0, 7));
    $pending = $mkAgent('pending', "pending$tag@test.local", '0918' . substr((string) random_int(1000000, 9999999), 0, 7));
    $u1 = $mkUser('Juan Dela Cruz', "u1$tag@test.local", '0919' . substr((string) random_int(1000000, 9999999), 0, 7));
    $u2 = $mkUser('Maria Santos', "u2$tag@test.local", '0920' . substr((string) random_int(1000000, 9999999), 0, 7));
    $self = $mkUser('Self Agent', "agent$tag@test.local.x", '0921' . substr((string) random_int(1000000, 9999999), 0, 7));
    $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute(["AGENT$tag@test.local", $self]);
    $check(agent_attach_referral($pdo, $u1, strtolower($a['code'])) !== null, 'customer tagged with a valid code (any case)');
    $check(agent_attach_referral($pdo, $u1, $a['code']) === null, 'customer is tagged only once');
    $check(agent_attach_referral($pdo, $u2, $pending['code']) === null, 'pending agent code does not tag');
    $check(agent_attach_referral($pdo, $u2, 'AGZZZZZZ') === null, 'unknown code does not tag');
    $check(agent_attach_referral($pdo, $self, $a['code']) === null, 'agent cannot refer their own email');
    $check(agent_for_user($pdo, $u2) === null && (int) agent_for_user($pdo, $u1)['id'] === $a['id'], 'agent_for_user finds the right agent');

    // ---------- URide: completed trip approves immediately
    $code = uride_driver_new_code($pdo);
    $pdo->prepare("INSERT INTO uride_drivers (code, full_name, email, mobile, password_hash, birthdate, address, barangay, license_no, license_expiry, vehicle_type, plate_no, vehicle_model, vehicle_color, status, wallet_centavos)
        VALUES (?, 'Test Driver', ?, ?, 'x', '1990-01-01', 'Station 2', 'Balabag', ?, '2030-01-01', 'e_trike', ?, 'Bemac', 'Green', 'approved', 10000)")
        ->execute([$code, "drv$tag@test.local", '0922' . substr((string) random_int(1000000, 9999999), 0, 7), 'LIC' . $tag, 'PL' . $tag]);
    $driver = (int) $pdo->lastInsertId();
    $mkRide = static function (int $userId) use ($pdo, $driver, &$rides): int {
        $code = 'UR-T' . strtoupper(bin2hex(random_bytes(4)));
        $pdo->prepare("INSERT INTO uride_requests (user_id, driver_id, request_key, code, vehicle_type, pickup_address, pickup_lat, pickup_lng, dropoff_address, dropoff_lat, dropoff_lng, distance_km, fare_centavos, payment_method, commission_centavos, status)
            VALUES (?, ?, ?, ?, 'e_trike', 'A', 11.96, 121.92, 'B', 11.97, 121.93, 2, 5400, 'cash', 540, 'in_progress')")->execute([$userId, $driver, bin2hex(random_bytes(32)), $code]);
        return $rides[] = (int) $pdo->lastInsertId();
    };
    $r1 = $mkRide($u1);
    uride_driver_action($pdo, $driver, $r1, 'complete');
    $c = $commission('uride', $r1);
    $check($c && $c['status'] === 'approved' && (int) $c['amount_centavos'] === 108 && $balance($a['id']) === 108, 'URide trip completed: PHP 1.08 earned and added to balance');
    $s = $pdo->prepare('SELECT wallet_centavos FROM uride_drivers WHERE id = ?'); $s->execute([$driver]);
    $check((int) $s->fetchColumn() === 10000 - 540, 'driver pays only the normal URide commission (agent is paid by Ultimate App)');
    $r2 = $mkRide($u2);
    uride_driver_action($pdo, $driver, $r2, 'complete');
    $check($commission('uride', $r2) === null, 'no commission for a customer without an agent');

    // ---------- UEat: pending, cancelled -> reversed, matured -> approved
    $summary = ['restaurant_id' => 'cafe', 'lines' => [['id' => 'iced-latte', 'name' => 'Iced Latte', 'quantity' => 2, 'unit' => 175, 'total' => 350]], 'count' => 2, 'total' => 350];
    $ref = ueat_place_order($pdo, $u1, $summary, bin2hex(random_bytes(32)), 'pickup', '', '', true);
    $orders[] = $o1 = (int) $pdo->query('SELECT id FROM ueat_orders WHERE reference = ' . $pdo->quote($ref))->fetchColumn();
    $c = $commission('ueat', $o1);
    $check($c && $c['status'] === 'pending' && (int) $c['amount_centavos'] === 1050, 'UEat paid order: PHP 10.50 pending (3% of 350)');
    ueat_cancel_order($pdo, $u1, $ref);
    $check($commission('ueat', $o1)['status'] === 'reversed' && $balance($a['id']) === 108, 'UEat cancelled: commission reversed, balance unchanged');
    $ref2 = ueat_place_order($pdo, $u1, $summary, bin2hex(random_bytes(32)), 'pickup', '', '', true);
    $orders[] = $o2 = (int) $pdo->query('SELECT id FROM ueat_orders WHERE reference = ' . $pdo->quote($ref2))->fetchColumn();
    $check(agent_mature_pending($pdo, $a['id']) === 0, 'UEat not approved before the holding period');
    $pdo->prepare('UPDATE agent_commissions SET created_at = NOW() - INTERVAL 4 DAY WHERE source_type = ? AND source_id = ?')->execute(['ueat', $o2]);
    $check(agent_mature_pending($pdo, $a['id']) === 1 && $commission('ueat', $o2)['status'] === 'approved' && $balance($a['id']) === 108 + 1050, 'UEat approved after 3 days without cancellation');
    ueat_cancel_order($pdo, $u1, $ref2);
    $check($commission('ueat', $o2)['status'] === 'reversed' && $balance($a['id']) === 108, 'cancelling after approval takes the commission back');
    $ref3 = ueat_place_order($pdo, $u1, $summary, bin2hex(random_bytes(32)), 'pickup', '', '', false);
    $orders[] = $o3 = (int) $pdo->query('SELECT id FROM ueat_orders WHERE reference = ' . $pdo->quote($ref3))->fetchColumn();
    $check($commission('ueat', $o3) === null, 'unpaid (demo) UEat order earns nothing');

    // ---------- UGo request: pending, approved when completed
    $pdo->prepare("INSERT INTO service_requests (reference, request_key, user_id, service_code, item_id, intent, quantity) VALUES (?, ?, ?, 'UGo', 'island-hop', 'booking', 2)")->execute(['SR-T' . $tag, bin2hex(random_bytes(32)), $u1]);
    $requests[] = $sr = (int) $pdo->lastInsertId();
    agent_record_commission($pdo, $u1, 'UGo', 'service_request', $sr, 'SR-T' . $tag, 1400 * 100 * 2, false);
    agent_record_commission($pdo, $u1, 'UGo', 'service_request', $sr, 'SR-T' . $tag, 1400 * 100 * 2, false);
    $s = $pdo->prepare("SELECT COUNT(*) FROM agent_commissions WHERE source_type = 'service_request' AND source_id = ?"); $s->execute([$sr]);
    $check((int) $s->fetchColumn() === 1 && $commission('service_request', $sr)['status'] === 'pending' && (int) $commission('service_request', $sr)['amount_centavos'] === 15000, 'UGo request: PHP 150 pending (5% of 2,800 + 10), recorded once');
    agent_tx($pdo, fn() => agent_approve_source($pdo, 'service_request', $sr, 'Request completed'));
    $check($commission('service_request', $sr)['status'] === 'approved' && $balance($a['id']) === 108 + 15000, 'UGo completed: commission approved');

    // ---------- Payouts
    $throws(fn() => agent_payout_request($pdo, $a['id'], 999999), 'more than your available balance', 'payout above balance refused');
    $throws(fn() => agent_payout_request($pdo, $a['id'], 500), 'Minimum payout', 'payout below minimum refused');
    $payoutRefs[] = $p1 = agent_payout_request($pdo, $a['id'], 10000);
    $check($balance($a['id']) === 5108, 'payout request moves PHP 100 out of the balance');
    $throws(fn() => agent_payout_request($pdo, $a['id'], 1000), 'already have a payout', 'only one open payout at a time');
    $pid = (int) $pdo->query('SELECT id FROM agent_payouts WHERE reference = ' . $pdo->quote($p1))->fetchColumn();
    agent_payout_decide($pdo, $pid, 'rejected', '', 'Wrong account', 1);
    $check($balance($a['id']) === 15108, 'returned payout goes back to the balance');
    $payoutRefs[] = $p2 = agent_payout_request($pdo, $a['id'], 15108);
    $pid = (int) $pdo->query('SELECT id FROM agent_payouts WHERE reference = ' . $pdo->quote($p2))->fetchColumn();
    agent_payout_decide($pdo, $pid, 'paid', 'GC-123456', '', 1);
    $check($balance($a['id']) === 0, 'paid payout settles the balance');

    // ---------- Suspension and earning window
    $pdo->prepare("UPDATE agents SET status = 'suspended' WHERE id = ?")->execute([$a['id']]);
    $r3 = $mkRide($u1); uride_driver_action($pdo, $driver, $r3, 'complete');
    $check($commission('uride', $r3) === null, 'suspended agent earns nothing new');
    $pdo->prepare("UPDATE agents SET status = 'approved' WHERE id = ?")->execute([$a['id']]);
    $pdo->prepare('UPDATE users SET referred_at = NOW() - INTERVAL 400 DAY WHERE id = ?')->execute([$u1]);
    $r4 = $mkRide($u1); uride_driver_action($pdo, $driver, $r4, 'complete');
    $check($commission('uride', $r4) === null, 'no commission after the 365-day earning window');
    $set('agents.earn_days', '0');
    $r5 = $mkRide($u1); uride_driver_action($pdo, $driver, $r5, 'complete');
    $check($commission('uride', $r5) !== null, 'earn_days = 0 means lifetime');

    // ---------- Failure inside the hook never breaks the outer transaction
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE users SET credits = credits + 1 WHERE id = ?')->execute([$u2]);
    agent_guarded($pdo, function () use ($pdo) { $pdo->exec("INSERT INTO ledger_entries (entry_group, event, account) VALUES ('X-TEST', 'x', 'x')"); throw new RuntimeException('boom'); });
    $pdo->commit();
    $check((int) $pdo->query("SELECT COUNT(*) FROM ledger_entries WHERE entry_group = 'X-TEST'")->fetchColumn() === 0, 'failed commission is rolled back to its savepoint; the payment still commits');

    // ---------- Books balance
    $groups = array_merge(array_map(static fn($r) => $r, $pdo->query("SELECT CONCAT('AC-', id) FROM agent_commissions WHERE agent_id = " . (int) $a['id'])->fetchAll(PDO::FETCH_COLUMN)), $payoutRefs);
    $in = implode(',', array_map([$pdo, 'quote'], $groups));
    $t = $pdo->query("SELECT SUM(debit_centavos) d, SUM(credit_centavos) c FROM ledger_entries WHERE entry_group IN ($in)")->fetch();
    $check((int) $t['d'] === (int) $t['c'] && (int) $t['d'] > 0, 'agent ledger entries balance (debits = credits)');
    $s = $pdo->prepare("SELECT COALESCE(SUM(credit_centavos - debit_centavos),0) FROM ledger_entries WHERE account = ?"); $s->execute(['agent_payable:' . $a['id']]);
    $check((int) $s->fetchColumn() === $balance($a['id']), 'ledger agent_payable equals the agent balance');
    echo "\nAll agent tests passed.\n";
} finally {
    $ids = static fn(array $a): string => $a ? implode(',', array_map('intval', $a)) : '0';
    $groups = $pdo->query('SELECT CONCAT(\'AC-\', id) FROM agent_commissions WHERE agent_id IN (' . $ids($agents) . ')')->fetchAll(PDO::FETCH_COLUMN);
    $groups = array_merge($groups, array_map(static fn($g) => 'ACR-' . substr($g, 3), $groups), $payoutRefs, array_map(static fn($r) => 'RV-' . substr($r, 3), $payoutRefs));
    $rideCodes = $rides ? $pdo->query('SELECT code FROM uride_requests WHERE id IN (' . $ids($rides) . ')')->fetchAll(PDO::FETCH_COLUMN) : [];
    $all = array_merge($groups, $rideCodes);
    if ($all) $pdo->exec('DELETE FROM ledger_entries WHERE entry_group IN (' . implode(',', array_map([$pdo, 'quote'], $all)) . ')');
    $pdo->exec('DELETE FROM agent_commissions WHERE agent_id IN (' . $ids($agents) . ')');
    $pdo->exec('DELETE FROM agent_payouts WHERE agent_id IN (' . $ids($agents) . ')');
    $pdo->exec('DELETE FROM ueat_order_items WHERE order_id IN (' . $ids($orders) . ')');
    $pdo->exec('DELETE FROM ueat_orders WHERE id IN (' . $ids($orders) . ')');
    $pdo->exec('DELETE FROM service_requests WHERE id IN (' . $ids($requests) . ')');
    $pdo->exec('DELETE FROM uride_wallet_tx WHERE driver_id = ' . (int) $driver);
    $pdo->exec('DELETE FROM uride_requests WHERE id IN (' . $ids($rides) . ')');
    $pdo->exec('DELETE FROM uride_drivers WHERE id = ' . (int) $driver);
    $pdo->exec('DELETE FROM transactions WHERE user_id IN (' . $ids($users) . ')');
    $pdo->exec('DELETE FROM users WHERE id IN (' . $ids($users) . ')');
    $pdo->exec('DELETE FROM agents WHERE id IN (' . $ids($agents) . ')');
    foreach ($saved as $k => $v) {
        if ($v === false) setting_clear($pdo, $k); else setting_save($pdo, $k, (string) $v, null);
    }
}
