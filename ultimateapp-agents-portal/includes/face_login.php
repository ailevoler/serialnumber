<?php
declare(strict_types=1);

/**
 * Face Login (customers): log in on a phone you set up before, with a liveness check + face recognition.
 *
 * - Set up (logged in, approved KYC): the person does a short liveness check; their face must match the face from
 *   their approved KYC selfie. The phone gets a long random device key (cookie, only its SHA-256 is stored) and the
 *   enrolled face (128 numbers) is saved for that device.
 * - Log in: the server picks random steps (look straight, then two random turns, then blink, in random order) and
 *   a one-time code that is valid for 2 minutes. The phone runs the liveness check in that order and sends the face
 *   numbers of the "look straight" photo. The server checks the code, the steps and their timing, and the distance
 *   to the enrolled face. 5 failed tries lock Face Login on that phone until the person logs in with the password.
 * - Face recognition runs on the phone, so this is a convenience login for the person's own phone, like a
 *   fingerprint unlock. The password always works, and money limits / PINs stay as they are.
 */

require_once __DIR__ . '/kyc.php';

const FACE_COOKIE = 'ua_face';
const FACE_TURNS = ['left', 'right', 'up', 'down'];
const FACE_MAX_FAILS = 5;
const FACE_CHALLENGE_TTL = 120;

function face_login_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $GLOBALS['pdo']->query('SELECT id FROM face_login_devices LIMIT 0'); $ready = kyc_face_ready(); } catch (Throwable) { $ready = false; }
    }
    return $ready;
}

function face_login_enabled(): bool
{
    return face_login_ready() && (string) setting('face.login_enabled', '1') === '1';
}

/** Max face distance for Face Login (lower = stricter). Admin › KYC › Rules. */
function face_login_max_distance(): float
{
    $v = setting('face.login_max_distance', '0.45');
    return is_numeric($v) ? max(0.3, min(0.6, (float) $v)) : 0.45;
}

/** The person's latest approved KYC (customer account), with its face numbers when the phone made them. */
function face_login_kyc(PDO $pdo, int $userId): ?array
{
    $s = $pdo->prepare("SELECT id, selfie, face_descriptor FROM kyc_submissions WHERE subject_type = 'user' AND subject_id = ? AND status = 'approved' ORDER BY id DESC LIMIT 1");
    $s->execute([$userId]);
    return $s->fetch() ?: null;
}

function face_cookie_path(): string
{
    $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    return rtrim($dir, '/') . '/';
}

function face_set_cookie(string $value, int $expires): void
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    if (!headers_sent()) setcookie(FACE_COOKIE, $value, ['expires' => $expires, 'path' => face_cookie_path(), 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    if ($value === '') unset($_COOKIE[FACE_COOKIE]); else $_COOKIE[FACE_COOKIE] = $value;
}

/** The Face Login device of this phone (from its cookie), with the account; null when none, revoked or locked out. */
function face_login_device(PDO $pdo, bool $includeLocked = false): ?array
{
    $token = $_COOKIE[FACE_COOKIE] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token) || !face_login_ready()) return null;
    $s = $pdo->prepare('SELECT d.*, u.full_name, u.email, u.status user_status FROM face_login_devices d JOIN users u ON u.id = d.user_id WHERE d.token_hash = ? AND d.revoked_at IS NULL LIMIT 1');
    $s->execute([hash('sha256', $token)]);
    $d = $s->fetch();
    if (!$d || (!$includeLocked && $d['locked_at'] !== null)) return null;
    return $d;
}

function face_login_log(PDO $pdo, int $userId, ?int $deviceId, string $purpose, bool $ok, ?float $distance, string $reason): void
{
    $pdo->prepare('INSERT INTO face_login_attempts (user_id, device_id, purpose, ok, distance, reason, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$userId, $deviceId, $purpose, $ok ? 1 : 0, $distance === null ? null : round($distance, 4), mb_substr($reason, 0, 120),
            mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
}

/** Short name for the phone, from the browser ("Android · Chrome"). */
function face_device_label(string $ua): string
{
    $os = preg_match('/iPhone|iPad/i', $ua) ? 'iPhone' : (preg_match('/Android/i', $ua) ? 'Android' : (preg_match('/Windows/i', $ua) ? 'Windows' : (preg_match('/Mac OS/i', $ua) ? 'Mac' : 'Phone')));
    $br = preg_match('/EdgA?\//', $ua) ? 'Edge' : (preg_match('/SamsungBrowser/', $ua) ? 'Samsung Internet' : (preg_match('/CriOS|Chrome/', $ua) ? 'Chrome' : (preg_match('/FxiOS|Firefox/', $ua) ? 'Firefox' : (preg_match('/Safari/', $ua) ? 'Safari' : 'Browser'))));
    return $os . ' · ' . $br;
}

/**
 * Checks a liveness log from the phone against the steps that were asked, in that order, automatic (no manual photos),
 * with believable timing. Returns the cleaned log.
 */
function face_check_log(string $json, array $steps, int $maxMs = 120000): array
{
    $log = json_decode($json, true);
    if (!is_array($log) || !is_array($log['steps'] ?? null)) throw new InvalidArgumentException('The face check did not finish. Please try again.');
    if (array_keys($log['steps']) !== $steps) throw new InvalidArgumentException('The steps were not done in the order asked. Please try again.');
    $prev = -1.0;
    foreach ($steps as $s) {
        $m = $log['steps'][$s];
        if (!is_array($m) || !empty($m['manual'])) throw new InvalidArgumentException('Face Login needs the automatic face check. Please use your password on this phone.');
        $t = $m['t'] ?? null;
        if (!is_numeric($t) || (float) $t <= $prev) throw new InvalidArgumentException('The face check did not finish. Please try again.');
        $prev = (float) $t;
    }
    if ($prev < 300 * count($steps)) throw new InvalidArgumentException('The face check was too fast. Please try again.');
    if ($prev > $maxMs) throw new InvalidArgumentException('The face check took too long. Please try again.');
    return ['steps' => $steps, 'ms' => (int) $prev, 'wrong' => is_numeric($log['wrong'] ?? null) ? (int) $log['wrong'] : null];
}

/** Random steps for one login: look straight first, then two different turns and a blink in random order. */
function face_random_steps(): array
{
    $turns = FACE_TURNS;
    for ($i = count($turns) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$turns[$i], $turns[$j]] = [$turns[$j], $turns[$i]]; }
    $rest = [$turns[0], $turns[1], 'blink'];
    for ($i = 2; $i > 0; $i--) { $j = random_int(0, $i); [$rest[$i], $rest[$j]] = [$rest[$j], $rest[$i]]; }
    return ['center', ...$rest];
}

/** Starts a login on this phone: a one-time code + random steps, kept in the session for 2 minutes. */
function face_login_challenge(PDO $pdo): array
{
    if (!face_login_enabled()) throw new InvalidArgumentException('Face Login is turned off. Please log in with your password.');
    $d = face_login_device($pdo, true);
    if (!$d) throw new InvalidArgumentException('Face Login is not set up on this phone. Log in with your password, then turn on Face Login in Profile.');
    if ($d['locked_at'] !== null) throw new InvalidArgumentException('Face Login is locked on this phone after too many tries. Please log in with your password.');
    $ch = ['nonce' => bin2hex(random_bytes(16)), 'steps' => face_random_steps(), 'device_id' => (int) $d['id'], 'exp' => time() + FACE_CHALLENGE_TTL, 'started' => microtime(true)];
    $_SESSION['face_challenge'] = $ch;
    return ['nonce' => $ch['nonce'], 'steps' => $ch['steps'], 'expires_in' => FACE_CHALLENGE_TTL];
}

/**
 * Finishes a login. $in: nonce, descriptor (JSON 128 numbers of the "look straight" photo), log (liveness log JSON).
 * Returns the user row on success. Throws InvalidArgumentException with a message for the person otherwise.
 */
function face_login_verify(PDO $pdo, array $in): array
{
    $ch = $_SESSION['face_challenge'] ?? null;
    unset($_SESSION['face_challenge']); // one try per code
    $nonce = is_string($in['nonce'] ?? null) ? $in['nonce'] : '';
    if (!is_array($ch) || $nonce === '' || !hash_equals((string) $ch['nonce'], $nonce)) throw new InvalidArgumentException('This face check expired. Please try again.');
    if (time() > (int) $ch['exp']) throw new InvalidArgumentException('This face check expired. Please try again.');
    $d = face_login_device($pdo, true);
    if (!$d || (int) $d['id'] !== (int) $ch['device_id']) throw new InvalidArgumentException('Face Login is not set up on this phone. Please log in with your password.');
    if ($d['locked_at'] !== null) throw new InvalidArgumentException('Face Login is locked on this phone after too many tries. Please log in with your password.');
    if ($d['user_status'] !== 'active') throw new InvalidArgumentException('This account is suspended. Please contact Ultimate App support.');
    $uid = (int) $d['user_id']; $did = (int) $d['id'];

    $reason = ''; $dist = null;
    try {
        $elapsedMs = (int) ((microtime(true) - (float) $ch['started']) * 1000) + 2000;
        face_check_log(is_string($in['log'] ?? null) ? $in['log'] : '', $ch['steps'], min(FACE_CHALLENGE_TTL * 1000, $elapsedMs));
        $live = kyc_parse_descriptor(is_string($in['descriptor'] ?? null) ? $in['descriptor'] : '');
        if (!$live) throw new InvalidArgumentException('We could not see your face clearly. Try again in better light.');
        $ref = kyc_parse_descriptor((string) $d['descriptor']);
        $dist = face_distance($live, $ref);
        if ($dist > face_login_max_distance()) { $reason = 'face does not match'; throw new InvalidArgumentException('Your face does not match the face set up on this phone. Try again in better light, or use your password.'); }
    } catch (InvalidArgumentException $ex) {
        $fails = (int) $d['fails'] + 1;
        $pdo->prepare('UPDATE face_login_devices SET fails = ?, locked_at = IF(? >= ?, NOW(), locked_at) WHERE id = ?')->execute([$fails, $fails, FACE_MAX_FAILS, $did]);
        face_login_log($pdo, $uid, $did, 'login', false, $dist, $reason ?: $ex->getMessage());
        $left = FACE_MAX_FAILS - $fails;
        throw new InvalidArgumentException($ex->getMessage() . ($left <= 0 ? ' Face Login is now locked on this phone: please log in with your password.' : ($left <= 2 ? ' ' . $left . ' tr' . ($left === 1 ? 'y' : 'ies') . ' left.' : '')));
    }
    $pdo->prepare('UPDATE face_login_devices SET fails = 0, last_used_at = NOW() WHERE id = ?')->execute([$did]);
    face_login_log($pdo, $uid, $did, 'login', true, $dist, 'ok');
    $u = $pdo->prepare('SELECT * FROM users WHERE id = ?'); $u->execute([$uid]);
    return $u->fetch();
}

/**
 * Sets up Face Login on this phone for a logged-in customer. $in: descriptor (live face), log (liveness log),
 * kyc_descriptor (only when the approved KYC has no face numbers yet: made on the phone from the KYC selfie).
 * The live face must match the face from the approved KYC. Returns the new device id.
 */
function face_login_enroll(PDO $pdo, int $userId, array $in): int
{
    if (!face_login_enabled()) throw new InvalidArgumentException('Face Login is turned off.');
    $kyc = face_login_kyc($pdo, $userId);
    if (!$kyc) throw new InvalidArgumentException('Verify your identity first (Profile › Verify my identity). Face Login uses the face from your approved ID check.');
    $reason = ''; $dist = null;
    try {
        face_check_log(is_string($in['log'] ?? null) ? $in['log'] : '', ['center', 'left', 'right', 'blink'], 180000);
        $live = kyc_parse_descriptor(is_string($in['descriptor'] ?? null) ? $in['descriptor'] : '');
        if (!$live) throw new InvalidArgumentException('We could not see your face clearly. Try again in better light.');
        $ref = $kyc['face_descriptor'] ? kyc_parse_descriptor((string) $kyc['face_descriptor']) : kyc_parse_descriptor(is_string($in['kyc_descriptor'] ?? null) ? $in['kyc_descriptor'] : '');
        if (!$ref) throw new InvalidArgumentException('We could not read the face on your ID check. Please verify your identity again.');
        $dist = face_distance($live, $ref);
        if ($dist > kyc_face_max_distance()) { $reason = 'face does not match KYC'; throw new InvalidArgumentException('Your face does not match the face on your approved ID check (match ' . face_score($dist) . '%). Try again in better light.'); }
    } catch (InvalidArgumentException $ex) {
        face_login_log($pdo, $userId, null, 'enroll', false, $dist, $reason ?: $ex->getMessage());
        throw $ex;
    }
    // One device key per phone: setting up again replaces this phone's old key.
    $old = face_login_device($pdo, true);
    if ($old) $pdo->prepare('UPDATE face_login_devices SET revoked_at = NOW() WHERE id = ?')->execute([(int) $old['id']]);
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO face_login_devices (user_id, token_hash, label, descriptor, kyc_id) VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, hash('sha256', $token), face_device_label((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), json_encode($live), (int) $kyc['id']]);
    $id = (int) $pdo->lastInsertId();
    face_set_cookie($token, time() + 86400 * 365);
    face_login_log($pdo, $userId, $id, 'enroll', true, $dist, 'ok');
    return $id;
}

/** Face Login devices of an account (newest first). */
function face_login_devices(PDO $pdo, int $userId): array
{
    if (!face_login_ready()) return [];
    $s = $pdo->prepare('SELECT id, label, fails, locked_at, created_at, last_used_at, token_hash FROM face_login_devices WHERE user_id = ? AND revoked_at IS NULL ORDER BY id DESC');
    $s->execute([$userId]);
    return $s->fetchAll();
}

/** Turns Face Login off for one device (or all devices when $deviceId is null) of an account. */
function face_login_revoke(PDO $pdo, int $userId, ?int $deviceId): int
{
    $sql = 'UPDATE face_login_devices SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL' . ($deviceId ? ' AND id = ?' : '');
    $s = $pdo->prepare($sql);
    $s->execute($deviceId ? [$userId, $deviceId] : [$userId]);
    $mine = face_login_device($pdo, true);
    if (!$mine || (int) $mine['user_id'] === $userId && ($deviceId === null || (int) $mine['id'] === $deviceId)) face_set_cookie('', time() - 3600);
    return $s->rowCount();
}

/** After a password login: unlock Face Login on this phone (if it belongs to the same account). */
function face_login_unlock_after_password(PDO $pdo, int $userId): void
{
    $d = face_login_device($pdo, true);
    if ($d && (int) $d['user_id'] === $userId && ((int) $d['fails'] > 0 || $d['locked_at'] !== null)) {
        $pdo->prepare('UPDATE face_login_devices SET fails = 0, locked_at = NULL WHERE id = ?')->execute([(int) $d['id']]);
    }
}
