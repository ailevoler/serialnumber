<?php
declare(strict_types=1);
// CLI only. Run against a COPY of the database after importing database/kyc_migration.sql and database/face_migration.sql:
//   DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tests/face_test.php
// Checks the KYC selfie-vs-ID face match and Face Login (set up, one-time steps, face distance, lock, turn off).
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
$_SESSION = []; $_COOKIE = []; $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 14) Chrome/128 Mobile'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require __DIR__ . '/../includes/rate_limit.php';
require __DIR__ . '/../includes/face_login.php';

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
// Face numbers: a random "person", and the same person with a little noise (distance ~ $noise * sqrt(128/3)).
$person = static function (): array { $v = []; for ($i = 0; $i < 128; $i++) $v[] = round(mt_rand(-200000, 200000) / 1e6, 6); return $v; };
$like = static function (array $p, float $noise): array { return array_map(static fn($x) => round($x + mt_rand(-1000000, 1000000) / 1e6 * $noise, 6), $p); };
$kycIn = static function (?array $face, ?array $idFace) use ($jpeg): array {
    $in = ['id_type' => 'philsys', 'full_name' => 'Face Tester', 'id_front' => $jpeg(1000, 640), 'id_back' => $jpeg(1000, 640, [90, 90, 90]), 'liveness_mode' => 'auto'];
    $steps = [];
    foreach (KYC_STEPS as $i => $s) { $in['frame_' . $s] = $jpeg(480, 640, [120 + $i * 10, 100, 90]); $steps[$s] = ['t' => 1000 + $i * 1500, 'yaw' => 0.5, 'pitch' => 0.55, 'blink' => 0.1]; }
    $in['liveness_log'] = json_encode(['model' => 'test', 'steps' => $steps, 'wrong' => 2]);
    if ($face) $in['face_descriptor'] = json_encode($face);
    if ($idFace) $in['id_face_descriptor'] = json_encode($idFace);
    return $in;
};
$log = static function (array $steps, int $gap = 400, bool $manual = false): string {
    $out = [];
    foreach ($steps as $i => $s) $out[$s] = ['t' => 300 + $i * $gap, 'yaw' => 0.5, 'pitch' => 0.55, 'blink' => 0.1, 'manual' => $manual];
    return json_encode(['engine' => 'ua-face-1', 'steps' => $out, 'wrong' => 0]);
};
$users = [];
try {
    $check(face_login_ready(), 'face tables are installed');
    $set('face.kyc_max_distance', '0.60'); $set('face.login_max_distance', '0.45'); $set('face.login_enabled', '1');
    $mk = static function (string $n) use ($pdo, &$users): int {
        $pdo->prepare("INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES (?, ?, ?, 'x', ?, 0)")->execute([$n, '0918' . random_int(1000000, 9999999), 'face' . bin2hex(random_bytes(3)) . '@test.local', 'UA-FC' . strtoupper(bin2hex(random_bytes(4)))]);
        return $users[] = (int) $pdo->lastInsertId();
    };
    $uid = $mk('Face Tester'); $other = $mk('Face Other');
    $me = $person(); $stranger = $person();

    // ---------- descriptors
    $check(kyc_parse_descriptor('') === null, 'empty face numbers = not measured');
    $throws(fn() => kyc_parse_descriptor(json_encode(array_fill(0, 127, 0.1))), 'invalid', '127 numbers refused');
    $throws(fn() => kyc_parse_descriptor(json_encode(array_fill(0, 128, 5))), 'invalid', 'out-of-range numbers refused');
    $throws(fn() => kyc_parse_descriptor(json_encode(['a' => 1] + array_fill(0, 127, 0.1))), 'invalid', 'object instead of list refused');
    $check(face_distance($me, $me) == 0.0 && face_distance($me, $stranger) > 0.9, 'distance: 0 for the same numbers, large for strangers');
    $check(face_score(0.3) === 100 && face_score(0.6) === 50 && face_score(0.9) === 0, 'match score 100 / 50 / 0');

    // ---------- KYC face match
    $throws(fn() => kyc_submit($pdo, 'user', $uid, $kycIn($me, $stranger)), 'does not match', 'selfie vs a different face on the ID: refused');
    $throws(fn() => kyc_submit($pdo, 'user', $uid, $kycIn($me, null)), 'did not finish', 'only one face sent: refused');
    $check((int) $pdo->query("SELECT COUNT(*) FROM kyc_submissions WHERE subject_type = 'user' AND subject_id = $uid")->fetchColumn() === 0, 'nothing saved for refused face checks');
    $idFace = $like($me, 0.06);
    $ref = kyc_submit($pdo, 'user', $uid, $kycIn($me, $idFace));
    $k = $pdo->query('SELECT * FROM kyc_submissions WHERE reference = ' . $pdo->quote($ref))->fetch();
    $check($k['face_match_distance'] !== null && (float) $k['face_match_distance'] < 0.6 && count(json_decode($k['face_descriptor'], true)) === 128 && $k['face_model'] === 'face-api-1.7.15', 'matching selfie + ID saved with distance ' . $k['face_match_distance']);
    $check(json_decode($k['liveness_log'], true)['wrong_turns'] === 2, 'wrong turns kept in the liveness log');

    // ---------- Face Login: set up
    $enroll = ['center', 'left', 'right', 'blink'];
    $throws(fn() => face_login_enroll($pdo, $uid, ['descriptor' => json_encode($me), 'log' => $log($enroll)]), 'Verify your identity', 'set up needs an approved KYC');
    kyc_review($pdo, (int) $k['id'], 'approved', '', 1);
    $throws(fn() => face_login_enroll($pdo, $uid, ['descriptor' => json_encode($stranger), 'log' => $log($enroll)]), 'does not match', 'set up with another face: refused');
    $throws(fn() => face_login_enroll($pdo, $uid, ['descriptor' => json_encode($me), 'log' => $log(['center', 'right', 'left', 'blink'])]), 'order', 'set up with steps in the wrong order: refused');
    $throws(fn() => face_login_enroll($pdo, $uid, ['descriptor' => json_encode($me), 'log' => $log($enroll, 400, true)]), 'automatic', 'set up with manual photos: refused');
    $check(face_login_device($pdo) === null, 'no device key before set up');
    $dev = face_login_enroll($pdo, $uid, ['descriptor' => json_encode($like($me, 0.02)), 'log' => $log($enroll)]);
    $cookie = $_COOKIE[FACE_COOKIE] ?? '';
    $row = $pdo->query("SELECT * FROM face_login_devices WHERE id = $dev")->fetch();
    $check(preg_match('/^[a-f0-9]{64}$/', $cookie) === 1 && $row['token_hash'] === hash('sha256', $cookie) && $row['label'] === 'Android · Chrome', 'device key saved on the phone; only its SHA-256 stored');
    $check((int) face_login_device($pdo)['user_id'] === $uid, 'this phone is linked to the account');

    // ---------- Face Login: log in
    $ch = face_login_challenge($pdo);
    $check($ch['steps'][0] === 'center' && count($ch['steps']) === 4 && in_array('blink', $ch['steps'], true) && count(array_unique($ch['steps'])) === 4, 'challenge: look straight first, two turns and a blink (' . implode(', ', $ch['steps']) . ')');
    $seen = [];
    for ($i = 0; $i < 30; $i++) { $seen[implode(',', face_random_steps())] = 1; }
    $check(count($seen) > 5, 'steps change every time (' . count($seen) . ' different orders in 30 tries)');
    $throws(fn() => face_login_verify($pdo, ['nonce' => 'nope', 'descriptor' => json_encode($me), 'log' => $log($ch['steps'])]), 'expired', 'wrong one-time code: refused');
    $throws(fn() => face_login_verify($pdo, ['nonce' => $ch['nonce'], 'descriptor' => json_encode($me), 'log' => $log($ch['steps'])]), 'expired', 'a code works only once (even after a wrong try)');
    $ch = face_login_challenge($pdo);
    $wrongOrder = $ch['steps']; [$wrongOrder[1], $wrongOrder[2]] = [$wrongOrder[2], $wrongOrder[1]];
    $throws(fn() => face_login_verify($pdo, ['nonce' => $ch['nonce'], 'descriptor' => json_encode($me), 'log' => $log($wrongOrder)]), 'order', 'steps in another order: refused');
    $ch = face_login_challenge($pdo);
    $throws(fn() => face_login_verify($pdo, ['nonce' => $ch['nonce'], 'descriptor' => json_encode($me), 'log' => $log($ch['steps'], 10)]), 'too fast', 'impossibly fast steps: refused');
    $ch = face_login_challenge($pdo);
    $throws(fn() => face_login_verify($pdo, ['nonce' => $ch['nonce'], 'descriptor' => json_encode($stranger), 'log' => $log($ch['steps'])]), 'tries left', 'another face: refused, tries left shown');
    $check((int) $pdo->query("SELECT fails FROM face_login_devices WHERE id = $dev")->fetchColumn() === 3, 'three failed tries counted (wrong order, too fast, other face)');
    $ch = face_login_challenge($pdo);
    $u = face_login_verify($pdo, ['nonce' => $ch['nonce'], 'descriptor' => json_encode($like($me, 0.02)), 'log' => $log($ch['steps'])]);
    $row = $pdo->query("SELECT * FROM face_login_devices WHERE id = $dev")->fetch();
    $check((int) $u['id'] === $uid && (int) $row['fails'] === 0 && $row['last_used_at'] !== null, 'same face + right steps: logged in, tries reset');
    $check((int) $pdo->query("SELECT COUNT(*) FROM face_login_attempts WHERE device_id = $dev AND purpose = 'login' AND ok = 1")->fetchColumn() === 1, 'login attempt recorded');

    // ---------- lock after 5 tries, unlock with password
    for ($i = 0; $i < FACE_MAX_FAILS; $i++) {
        $ch = face_login_challenge($pdo);
        try { face_login_verify($pdo, ['nonce' => $ch['nonce'], 'descriptor' => json_encode($stranger), 'log' => $log($ch['steps'])]); } catch (InvalidArgumentException) {}
    }
    $throws(fn() => face_login_challenge($pdo), 'locked', 'locked after 5 wrong faces');
    $check(face_login_device($pdo) === null && face_login_device($pdo, true) !== null, 'locked device is not offered for login');
    face_login_unlock_after_password($pdo, $other);
    $throws(fn() => face_login_challenge($pdo), 'locked', 'another account\'s password does not unlock it');
    face_login_unlock_after_password($pdo, $uid);
    $check(face_login_challenge($pdo)['nonce'] !== '', 'own password login unlocks Face Login');

    // ---------- suspended account, turned off, setting off
    $pdo->exec("UPDATE users SET status = 'suspended' WHERE id = $uid");
    $ch = face_login_challenge($pdo);
    $throws(fn() => face_login_verify($pdo, ['nonce' => $ch['nonce'], 'descriptor' => json_encode($me), 'log' => $log($ch['steps'])]), 'suspended', 'suspended account cannot log in with face');
    $pdo->exec("UPDATE users SET status = 'active' WHERE id = $uid");
    $set('face.login_enabled', '0'); settings_all(true);
    $throws(fn() => face_login_challenge($pdo), 'turned off', 'Admin switch off: Face Login refused');
    $set('face.login_enabled', '1'); settings_all(true);
    $check(face_login_revoke($pdo, $other, $dev) === 0, 'another account cannot turn off my phone');
    $check(face_login_revoke($pdo, $uid, $dev) === 1 && !isset($_COOKIE[FACE_COOKIE]), 'turn off: device revoked and key removed from the phone');
    $throws(fn() => face_login_challenge($pdo), 'not set up', 'turned off: Face Login refused');
    $_COOKIE[FACE_COOKIE] = $cookie;
    $throws(fn() => face_login_challenge($pdo), 'not set up', 'old device key no longer works');
    echo "\nAll face tests passed.\n";
} finally {
    $ids = $users ? implode(',', $users) : '0';
    foreach ($pdo->query("SELECT id_front, id_back, liveness_frames FROM kyc_submissions WHERE subject_type = 'user' AND subject_id IN ($ids)") as $r) {
        @unlink(kyc_storage_dir() . '/' . $r['id_front']); @unlink(kyc_storage_dir() . '/' . $r['id_back']);
        foreach ((array) json_decode($r['liveness_frames'], true) as $f) @unlink(kyc_storage_dir() . '/' . $f);
    }
    $pdo->exec("DELETE FROM face_login_attempts WHERE user_id IN ($ids)");
    $pdo->exec("DELETE FROM face_login_devices WHERE user_id IN ($ids)");
    $pdo->exec("DELETE FROM kyc_submissions WHERE subject_type = 'user' AND subject_id IN ($ids)");
    $pdo->exec("DELETE FROM users WHERE id IN ($ids)");
    foreach ($saved as $k => $v) { if ($v === false) setting_clear($pdo, $k); else setting_save($pdo, $k, (string) $v, null); }
}
