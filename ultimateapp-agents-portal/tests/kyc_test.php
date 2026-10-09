<?php
declare(strict_types=1);
// CLI only. Run against a COPY of the database after importing database/kyc_migration.sql:
//   DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tests/kyc_test.php
// Checks KYC submission rules, review, and the "verified before…" gates. Deletes everything it creates.
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
require __DIR__ . '/../includes/kyc.php';
require __DIR__ . '/../includes/qr_pay.php';
require_once __DIR__ . '/../includes/agents.php';

$saved = [];
$set = static function (string $k, string $v) use ($pdo, &$saved): void {
    if (!array_key_exists($k, $saved)) $saved[$k] = $pdo->query('SELECT setting_value FROM app_settings WHERE setting_key = ' . $pdo->quote($k))->fetchColumn();
    setting_save($pdo, $k, $v, null);
};
$jpeg = static function (int $w, int $h, array $rgb = [200, 180, 160]): string {
    $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb));
    ob_start(); imagejpeg($im, null, 80); $b = (string) ob_get_clean(); imagedestroy($im);
    return 'data:image/jpeg;base64,' . base64_encode($b);
};
$good = static function () use ($jpeg): array {
    $in = ['id_type' => 'philsys', 'full_name' => 'Juan Dela Cruz', 'id_number' => '1234-5678-9012', 'birthdate' => '1990-05-01',
        'id_front' => $jpeg(1000, 640), 'id_back' => $jpeg(1000, 640, [90, 90, 90]), 'liveness_mode' => 'auto'];
    $steps = [];
    foreach (KYC_STEPS as $i => $s) { $in['frame_' . $s] = $jpeg(480, 640, [120 + $i * 10, 100, 90]); $steps[$s] = ['t' => 1000 + $i * 1500, 'yaw' => 0.5, 'pitch' => 0.55, 'blink' => 0.1]; }
    $in['liveness_log'] = json_encode(['model' => 'test', 'steps' => $steps]);
    return $in;
};
$files = static function (PDO $pdo, array $ids): array {
    if (!$ids) return [];
    $out = [];
    foreach ($pdo->query('SELECT id_front, id_back, liveness_frames FROM kyc_submissions WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')') as $r) {
        $out[] = $r['id_front']; $out[] = $r['id_back'];
        foreach ((array) json_decode($r['liveness_frames'], true) as $f) $out[] = $f;
    }
    return $out;
};
$users = []; $agents = []; $subs = [];
try {
    $pdo->prepare("INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES ('Kyc Test', ?, ?, 'x', ?, 1000)")->execute(['0918' . random_int(1000000, 9999999), 'kyc' . bin2hex(random_bytes(3)) . '@test.local', 'UA-KY' . strtoupper(bin2hex(random_bytes(4)))]);
    $users[] = $uid = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES ('Kyc Payee', ?, ?, 'x', ?, 0)")->execute(['0918' . random_int(1000000, 9999999), 'kyp' . bin2hex(random_bytes(3)) . '@test.local', 'UA-KP' . strtoupper(bin2hex(random_bytes(4)))]);
    $users[] = $payee = (int) $pdo->lastInsertId();

    // ---------- validation
    $check(kyc_status($pdo, 'user', $uid) === 'none', 'new account: not verified');
    $throws(fn() => kyc_submit($pdo, 'user', $uid, ['id_type' => 'x'] + $good()), 'ID type', 'unknown ID type refused');
    $bad = $good(); $bad['id_front'] = $jpeg(200, 150);
    $throws(fn() => kyc_submit($pdo, 'user', $uid, $bad), 'too small', 'tiny ID photo refused');
    $bad = $good(); unset($bad['frame_blink']);
    $throws(fn() => kyc_submit($pdo, 'user', $uid, $bad), 'Blink', 'missing blink photo refused');
    $bad = $good(); $log = json_decode($bad['liveness_log'], true); unset($log['steps']['down']); $bad['liveness_log'] = json_encode($log);
    $throws(fn() => kyc_submit($pdo, 'user', $uid, $bad), 'did not finish', 'liveness log missing a step refused');
    $bad = $good(); $log = json_decode($bad['liveness_log'], true); $log['steps']['up']['t'] = 1; $bad['liveness_log'] = json_encode($log);
    $throws(fn() => kyc_submit($pdo, 'user', $uid, $bad), 'did not finish', 'steps out of order refused');
    $bad = $good(); $bad['id_back'] = 'data:image/jpeg;base64,' . base64_encode('<?php echo 1; ?>');
    $throws(fn() => kyc_submit($pdo, 'user', $uid, $bad), 'not a valid photo', 'non-image disguised as JPEG refused');
    $check((int) $pdo->query("SELECT COUNT(*) FROM kyc_submissions WHERE subject_type = 'user' AND subject_id = $uid")->fetchColumn() === 0, 'nothing saved for refused submissions');

    // ---------- submit, re-encode, review
    $ref = kyc_submit($pdo, 'user', $uid, $good());
    $k = $pdo->query("SELECT * FROM kyc_submissions WHERE reference = " . $pdo->quote($ref))->fetch(); $subs[] = (int) $k['id'];
    $check($k['status'] === 'pending' && kyc_status($pdo, 'user', $uid) === 'pending', 'submission saved as pending');
    $frames = json_decode($k['liveness_frames'], true);
    $check(count($frames) === 6 && $k['selfie'] === $frames['center'], 'six liveness photos stored; selfie = look-straight photo');
    $path = kyc_storage_dir() . '/' . $k['id_front'];
    $check(is_file($path) && getimagesize($path)['mime'] === 'image/jpeg' && !str_contains((string) file_get_contents($path), 'Exif'), 'ID photo stored outside the web root as a clean JPEG');
    $throws(fn() => kyc_submit($pdo, 'user', $uid, $good()), 'already under review', 'only one open submission at a time');
    $throws(fn() => kyc_review($pdo, (int) $k['id'], 'needs_info', '', 1), 'what to fix', 'asking to resubmit needs a message');
    kyc_review($pdo, (int) $k['id'], 'needs_info', 'ID photo is blurry, please retake', 1);
    $check(kyc_status($pdo, 'user', $uid) === 'needs_info', 'asked to resubmit');
    $ref2 = kyc_submit($pdo, 'user', $uid, $good());
    $k2 = $pdo->query("SELECT * FROM kyc_submissions WHERE reference = " . $pdo->quote($ref2))->fetch(); $subs[] = (int) $k2['id'];
    kyc_review($pdo, (int) $k2['id'], 'approved', '', 1);
    $check(kyc_is_verified($pdo, 'user', $uid), 'resubmitted and approved: verified');
    $throws(fn() => kyc_review($pdo, (int) $k2['id'], 'rejected', 'changed my mind', 1), 'already reviewed', 'a decision cannot be changed silently');
    $throws(fn() => kyc_submit($pdo, 'user', $uid, $good()), 'already verified', 'no new submission once verified');

    // ---------- gates
    $set('kyc.required_user', '1');
    $pdo->prepare("INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES ('Kyc None', ?, ?, 'x', ?, 1000)")->execute(['0918' . random_int(1000000, 9999999), 'kyn' . bin2hex(random_bytes(3)) . '@test.local', 'UA-KN' . strtoupper(bin2hex(random_bytes(4)))]);
    $users[] = $unverified = (int) $pdo->lastInsertId();
    $throws(fn() => qr_pay_transfer($pdo, $unverified, $payee, 10000, bin2hex(random_bytes(32)), null), 'verify your identity', 'customer rule on: unverified cannot send Credits');
    $refP = qr_pay_transfer($pdo, $uid, $payee, 10000, bin2hex(random_bytes(32)), null);
    $check(str_starts_with($refP, 'QP-'), 'verified customer can send Credits');
    $set('kyc.required_user', '0');
    $set('kyc.required_agent', '1');
    $code = agent_new_code($pdo);
    $pdo->prepare("INSERT INTO agents (code, full_name, email, mobile, password_hash, status, balance_centavos, payout_method, payout_account_name, payout_account_no) VALUES (?, 'Kyc Agent', ?, '09170001111', 'x', 'approved', 100000, 'GCash', 'Kyc Agent', '09170001111')")->execute([$code, strtolower($code) . '@test.local']);
    $agents[] = $aid = (int) $pdo->lastInsertId();
    $throws(fn() => agent_payout_request($pdo, $aid, 60000), 'verify your identity', 'agent rule on: unverified agent cannot cash out');
    $check(kyc_required('merchant') === (setting('kyc.required_merchant', '1') === '1'), 'merchant rule follows the setting');
    echo "\nAll KYC tests passed.\n";
} finally {
    $ids = static fn(array $a): string => $a ? implode(',', array_map('intval', $a)) : '0';
    foreach ($files($pdo, $pdo->query("SELECT id FROM kyc_submissions WHERE (subject_type = 'user' AND subject_id IN (" . $ids($users) . ")) OR (subject_type = 'agent' AND subject_id IN (" . $ids($agents) . "))")->fetchAll(PDO::FETCH_COLUMN)) as $f) @unlink(kyc_storage_dir() . '/' . $f);
    $pdo->exec("DELETE FROM kyc_submissions WHERE subject_type = 'user' AND subject_id IN (" . $ids($users) . ')');
    $pdo->exec("DELETE FROM ledger_entries WHERE entry_group IN (SELECT reference FROM qr_payments WHERE payer_id IN (" . $ids($users) . '))');
    $pdo->exec('DELETE FROM qr_payments WHERE payer_id IN (' . $ids($users) . ')');
    $pdo->exec('DELETE FROM transactions WHERE user_id IN (' . $ids($users) . ')');
    $pdo->exec('DELETE FROM users WHERE id IN (' . $ids($users) . ')');
    $pdo->exec('DELETE FROM agents WHERE id IN (' . $ids($agents) . ')');
    foreach ($saved as $k => $v) { if ($v === false) setting_clear($pdo, $k); else setting_save($pdo, $k, (string) $v, null); }
}
