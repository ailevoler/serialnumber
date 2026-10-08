<?php
declare(strict_types=1);
// CLI only. Run against a COPY of the database after importing database/suniway_migration.sql, with the fake API running:
//   php -S 127.0.0.1:8098 tests/suniway_fake_api.php &
//   DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tests/suniway_test.php
// Never point this at the real SUNIWAY API: it creates transactions.
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
require __DIR__ . '/../includes/suniway.php';
@unlink(sys_get_temp_dir() . '/suniway-fake.json');
try { @unlink(app_storage_dir('cache') . '/suniway-providers.json'); } catch (Throwable $e) {}

$saved = $pdo->query("SELECT setting_key, setting_value, is_secret FROM app_settings WHERE setting_key LIKE 'suniway.%'")->fetchAll(PDO::FETCH_UNIQUE);
$raw = static function (string $k, string $v) use ($pdo): void {
    $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value, is_secret) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_secret = 0')->execute([$k, $v]);
};
$raw('suniway.enabled', '1');
$raw('suniway.base_url', getenv('SUNIWAY_FAKE_URL') ?: 'http://127.0.0.1:8098/api/partner-api'); // test server only (Admin requires https)
setting_save($pdo, 'suniway.api_key', 'test-key-123', null);
setting_save($pdo, 'suniway.fee_bills_pay_centavos', '500', null);
setting_save($pdo, 'suniway.fee_eload_centavos', '0', null);
setting_save($pdo, 'suniway.fee_ecash_centavos', '0', null);
settings_all(true);

$pdo->prepare("INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES ('Sw Tester', ?, ?, 'x', ?, 5000.00)")
    ->execute(['0917' . random_int(1000000, 9999999), 'sw' . bin2hex(random_bytes(3)) . '@test.local', 'UA-SW' . strtoupper(bin2hex(random_bytes(4)))]);
$uid = (int) $pdo->lastInsertId();
$credits = static fn(): int => (int) round((float) $pdo->query('SELECT credits FROM users WHERE id = ' . $uid)->fetchColumn() * 100);
$row = static function (string $ref) use ($pdo): array { $s = $pdo->prepare('SELECT * FROM suniway_transactions WHERE reference = ?'); $s->execute([$ref]); return $s->fetch(); };
$key = static fn(): string => bin2hex(random_bytes(32));
$refs = [];
try {
    $check(suniway_enabled(), 'enabled once a key is saved');
    $p = suniway_providers('bills_pay');
    $check(count($p) === 2 && $p['AKE01']['min'] === 5000 && $p['AKE01']['max'] === 5000000, 'bills providers parsed with min/max');
    $check(suniway_match_quick_picks('bills_pay', $p) === ['AKELCO' => 'AKE01', 'PLDT' => 'PLDT1'], 'quick picks matched to live billers');
    $check(count(suniway_providers('eload')) === 2 && count(suniway_providers('ecash')) === 2, 'eload and e-cash providers parsed');
    $throws(fn() => suniway_quote('bills_pay', 'AKE01', '123456', '20'), 'Minimum for AKELCO', 'biller minimum enforced');
    $throws(fn() => suniway_quote('eload', 'GLB', '12345', '50'), '11-digit mobile', 'mobile number validated for ULoad');
    $throws(fn() => suniway_quote('ecash', 'GC', '09171234567', '20000'), 'Maximum for GCash', 'e-wallet maximum enforced');
    $throws(fn() => suniway_quote('bills_pay', 'NOPE', '123456', '100'), 'Choose a biller', 'unknown biller refused');

    // UBills: pending -> success on refresh
    $q = suniway_quote('bills_pay', 'AKE01', '123456789', '1,250.50');
    $check($q['amount'] === 125050 && $q['provider_fee'] === 1500 && $q['app_fee'] === 500 && $q['total'] === 127050, 'quote: 1,250.50 + 15 provider fee + 5 convenience fee = 1,270.50');
    $k1 = $key();
    $refs[] = $r1 = suniway_pay($pdo, $uid, $q, $k1);
    $check(suniway_pay($pdo, $uid, $q, $k1) === $r1, 'same request key never pays twice');
    $t = $row($r1);
    $check($t['status'] === 'pending' && $t['remote_id'] && $t['remote_reference'] && $credits() === 500000 - 127050, 'Credits taken, sent to SUNIWAY, processing');
    $check(suniway_refresh($pdo, (int) $t['id']) === 'success' && $row($r1)['completed_at'] !== null, 'status check -> successful');
    $throws(fn() => suniway_refund($pdo, (int) $t['id'], 'x'), 'cannot be refunded', 'successful payment cannot be refunded');

    // ULoad: rejected at once by SUNIWAY (HTTP 400) -> refund
    $before = $credits();
    $refs[] = $r2 = suniway_pay($pdo, $uid, suniway_quote('eload', 'GLB', '09170000000', '50'), $key());
    $check($row($r2)['status'] === 'pending', 'ULoad normal number accepted');
    $q0 = suniway_quote('eload', 'GLB', '09170000000', '50'); $q0['account'] = '0000';
    $refs[] = $r3 = suniway_pay($pdo, $uid, $q0, $key());
    $t3 = $row($r3);
    $check($t3['status'] === 'failed' && $t3['refunded_at'] && str_contains((string) $t3['error_message'], 'Invalid account'), 'rejected by SUNIWAY -> failed and refunded');
    $check($credits() === $before - 5000, 'only the accepted load was charged');

    // UCash In: FAILED right away -> refund; FAILED later -> refund on status check
    $before = $credits();
    $q1 = suniway_quote('ecash', 'GC', '09171234567', '500'); $q1['account'] = '1111';
    $refs[] = $r4 = suniway_pay($pdo, $uid, $q1, $key());
    $check($row($r4)['status'] === 'failed' && $credits() === $before, 'FAILED reply -> Credits back');
    $q2 = suniway_quote('ecash', 'GC', '09171234567', '500'); $q2['account'] = '2222';
    $refs[] = $r5 = suniway_pay($pdo, $uid, $q2, $key());
    $check($credits() === $before - 51000, 'cash-in 500 + 10 fee taken');
    $check(suniway_refresh($pdo, (int) $row($r5)['id']) === 'failed' && $credits() === $before, 'failed on status check -> Credits back');
    $check(suniway_refund($pdo, (int) $row($r5)['id'], 'again') === false && $credits() === $before, 'refund happens only once');

    // No reply (HTTP 500): keep Credits held, admin resolves
    $q3 = suniway_quote('bills_pay', 'PLDT1', '5555', '100');
    $refs[] = $r6 = suniway_pay($pdo, $uid, $q3, $key());
    $check($row($r6)['status'] === 'unknown' && $credits() === $before - 12000, 'no reply -> "checking", Credits stay held (no double refund risk)');
    suniway_refund($pdo, (int) $row($r6)['id'], 'Refunded by admin: not in dashboard');
    $check($credits() === $before && $row($r6)['status'] === 'failed', 'admin refund returns the Credits');
    $refs[] = $r7 = suniway_pay($pdo, $uid, suniway_quote('bills_pay', 'PLDT1', '5555', '100'), $key());
    suniway_mark_success($pdo, (int) $row($r7)['id'], 'SW-CONFIRMED');
    $check($row($r7)['status'] === 'success' && $row($r7)['remote_reference'] === 'SW-CONFIRMED', 'admin can confirm an unknown one as successful');

    // Limits
    $pdo->exec('UPDATE users SET credits = 10 WHERE id = ' . $uid);
    $throws(fn() => suniway_pay($pdo, $uid, suniway_quote('eload', 'SMT', '09181234567', '50'), $key()), 'Not enough Credits', 'not enough Credits refused before sending');
    $old = suniway_quote('eload', 'SMT', '09181234567', '5'); $old['expires'] = time() - 1;
    $throws(fn() => suniway_pay($pdo, $uid, $old, $key()), 'expired', 'expired quote refused');

    // Books
    $in = implode(',', array_map([$pdo, 'quote'], array_merge($refs, array_map(static fn($r) => 'RF-' . $r, $refs))));
    $t = $pdo->query("SELECT SUM(debit_centavos) d, SUM(credit_centavos) c FROM ledger_entries WHERE entry_group IN ($in)")->fetch();
    $check((int) $t['d'] === (int) $t['c'], 'ledger balances');
    $s = $pdo->query("SELECT COALESCE(SUM(credit_centavos - debit_centavos),0) FROM ledger_entries WHERE account = 'suniway_payable' AND entry_group IN ($in)")->fetchColumn();
    $check((int) $s === 126550 + 5000 + 11500, 'owed to SUNIWAY = successful/processing payments only (bills 1,265.50 + load 50 + PLDT 115)');
    echo "\nAll SUNIWAY tests passed.\n";
} finally {
    if ($refs) {
        $in = implode(',', array_map([$pdo, 'quote'], array_merge($refs, array_map(static fn($r) => 'RF-' . $r, $refs))));
        $pdo->exec("DELETE FROM ledger_entries WHERE entry_group IN ($in)");
    }
    $pdo->exec('DELETE FROM suniway_transactions WHERE user_id = ' . $uid);
    $pdo->exec('DELETE FROM transactions WHERE user_id = ' . $uid);
    $pdo->exec('DELETE FROM users WHERE id = ' . $uid);
    $pdo->exec("DELETE FROM app_settings WHERE setting_key LIKE 'suniway.%'");
    $ins = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value, is_secret) VALUES (?, ?, ?)');
    foreach ($saved as $k => $v) $ins->execute([$k, $v['setting_value'], $v['is_secret']]);
    try { @unlink(app_storage_dir('cache') . '/suniway-providers.json'); } catch (Throwable $e) {}
}
