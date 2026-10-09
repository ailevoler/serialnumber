<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';

/**
 * KYC (identity verification) shared by customers, merchants, URide drivers and agents.
 * One submission = valid ID front + back, and a selfie liveness check done on the phone: the face landmark model
 * (MediaPipe, self-hosted in assets/vendor/mediapipe) asks the person to look left, right, up, down and blink, and a
 * frame is captured at each step. Detection runs on the device, so it can be tampered with: the frames are always
 * shown to a reviewer, who approves or rejects the submission in Admin > KYC verification.
 * Photos are re-encoded with GD (strips EXIF/GPS) and stored outside the web root (storage/kyc).
 */

const KYC_STEPS = ['center', 'left', 'right', 'up', 'down', 'blink'];

function kyc_subject_types(): array
{
    return ['user' => 'Customer', 'merchant' => 'Merchant', 'driver' => 'URide driver', 'agent' => 'Agent'];
}

function kyc_id_types(): array
{
    return [
        'philsys' => 'PhilSys National ID / ePhilID', 'passport' => 'Passport', 'drivers_license' => "Driver's License", 'umid' => 'UMID',
        'sss' => 'SSS ID', 'prc' => 'PRC ID', 'postal' => 'Postal ID', 'voters' => "Voter's ID / Certification", 'philhealth' => 'PhilHealth ID',
        'tin' => 'TIN ID', 'senior' => 'Senior Citizen ID', 'pwd' => 'PWD ID', 'foreign_passport' => 'Foreign passport (tourists)',
    ];
}

function kyc_statuses(): array
{
    return ['none' => 'Not verified', 'pending' => 'Under review', 'approved' => 'Verified', 'rejected' => 'Not approved', 'needs_info' => 'Please resubmit'];
}

function kyc_step_labels(): array
{
    return ['center' => 'Look straight', 'left' => 'Turn left', 'right' => 'Turn right', 'up' => 'Look up', 'down' => 'Look down', 'blink' => 'Blink'];
}

/** Whether Admin requires an approved KYC for this kind of account (before approval / money-out). */
function kyc_required(string $type): bool
{
    return setting('kyc.required_' . $type, $type === 'user' ? '0' : '1') === '1';
}

function kyc_table_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    global $pdo;
    try { $pdo->query('SELECT 1 FROM kyc_submissions LIMIT 1'); return $ready = true; } catch (Throwable $e) { return $ready = false; }
}

function kyc_latest(PDO $pdo, string $type, int $id): ?array
{
    if (!kyc_table_ready()) return null;
    $s = $pdo->prepare('SELECT * FROM kyc_submissions WHERE subject_type = ? AND subject_id = ? ORDER BY id DESC LIMIT 1');
    $s->execute([$type, $id]);
    return $s->fetch() ?: null;
}

/** none | pending | approved | rejected | needs_info. An approved submission stays valid even if a later one is open. */
function kyc_status(PDO $pdo, string $type, int $id): string
{
    if (!kyc_table_ready()) return 'none';
    $s = $pdo->prepare("SELECT 1 FROM kyc_submissions WHERE subject_type = ? AND subject_id = ? AND status = 'approved' LIMIT 1");
    $s->execute([$type, $id]);
    if ($s->fetchColumn()) return 'approved';
    $latest = kyc_latest($pdo, $type, $id);
    return $latest['status'] ?? 'none';
}

function kyc_is_verified(PDO $pdo, string $type, int $id): bool
{
    return kyc_status($pdo, $type, $id) === 'approved';
}

/** Throws a friendly error when Admin requires KYC for this account and it is not verified yet. */
function kyc_require_verified(PDO $pdo, string $type, int $id, string $what): void
{
    if (kyc_table_ready() && kyc_required($type) && !kyc_is_verified($pdo, $type, $id)) {
        throw new InvalidArgumentException('Please verify your identity (KYC) before ' . $what . '. Open "Verify ID" in your account.');
    }
}

function kyc_storage_dir(): string
{
    return app_storage_dir('kyc');
}

/**
 * Saves one photo (data URL from the camera / resized upload) as a clean JPEG. Returns the stored file name.
 * Re-encoding drops EXIF and GPS data and anything that is not a real image.
 */
function kyc_store_image(string $dataUrl, string $label, int $minSide = 320): string
{
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#D', $dataUrl, $m)) throw new InvalidArgumentException($label . ' is missing. Please take or choose the photo again.');
    $bytes = base64_decode($m[2], true);
    if ($bytes === false || strlen($bytes) > 8 * 1024 * 1024) throw new InvalidArgumentException($label . ' is too large (8 MB max).');
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) throw new InvalidArgumentException($label . ' is not a valid photo.');
    if (min($info[0], $info[1]) < $minSide) throw new InvalidArgumentException($label . ' is too small. Use a clearer photo.');
    $name = bin2hex(random_bytes(16)) . '.jpg';
    $path = kyc_storage_dir() . '/' . $name;
    if (function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring($bytes))) {
        $w = imagesx($img); $h = imagesy($img); $max = 1600;
        if (max($w, $h) > $max) {
            $scale = $max / max($w, $h);
            $dst = imagecreatetruecolor((int) round($w * $scale), (int) round($h * $scale));
            imagecopyresampled($dst, $img, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
            imagedestroy($img); $img = $dst;
        }
        $ok = imagejpeg($img, $path, 85);
        imagedestroy($img);
        if (!$ok) throw new RuntimeException('Could not save the photo.');
    } else {
        if ($info['mime'] !== 'image/jpeg') throw new InvalidArgumentException($label . ' must be a JPG photo on this server.');
        if (file_put_contents($path, $bytes, LOCK_EX) === false) throw new RuntimeException('Could not save the photo.');
    }
    return $name;
}

/** Checks the liveness log sent by the phone: every step, in order, within a believable time. */
function kyc_validate_liveness_log(string $json, string $mode): array
{
    $log = json_decode($json, true);
    if (!is_array($log) || !isset($log['steps']) || !is_array($log['steps'])) throw new InvalidArgumentException('The liveness check did not finish. Please do it again.');
    $prev = 0;
    foreach (KYC_STEPS as $step) {
        $t = $log['steps'][$step]['t'] ?? null;
        if (!is_numeric($t) || (float) $t < $prev) throw new InvalidArgumentException('The liveness check did not finish. Please do it again.');
        $prev = (float) $t;
    }
    if ($prev > 300000) throw new InvalidArgumentException('The liveness check took too long. Please do it again.');
    $clean = ['mode' => $mode, 'model' => mb_substr((string) ($log['model'] ?? ''), 0, 60), 'steps' => []];
    foreach (KYC_STEPS as $step) {
        $clean['steps'][$step] = array_map(static fn($v) => is_numeric($v) ? round((float) $v, 3) : null, array_intersect_key((array) $log['steps'][$step], array_flip(['t', 'yaw', 'pitch', 'blink'])));
    }
    return $clean;
}

/**
 * Creates a submission. $in: id_type, id_number, full_name, birthdate, id_front, id_back (data URLs),
 * frame_<step> for each liveness step (data URLs), liveness_log (JSON), liveness_mode (auto|manual).
 */
function kyc_submit(PDO $pdo, string $type, int $subjectId, array $in): string
{
    if (!isset(kyc_subject_types()[$type])) throw new InvalidArgumentException('Unknown account type.');
    if (!kyc_table_ready()) throw new InvalidArgumentException('Identity verification is not available yet.');
    $current = kyc_status($pdo, $type, $subjectId);
    if ($current === 'approved') throw new InvalidArgumentException('Your identity is already verified.');
    if ($current === 'pending') throw new InvalidArgumentException('Your verification is already under review.');
    $str = static fn(string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
    $idType = $str('id_type');
    if (!isset(kyc_id_types()[$idType])) throw new InvalidArgumentException('Choose your ID type.');
    $name = preg_replace('/\s+/u', ' ', $str('full_name')) ?? '';
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) throw new InvalidArgumentException('Enter your full name exactly as printed on the ID.');
    $idNumber = mb_substr(preg_replace('/[^A-Za-z0-9 -]/', '', $str('id_number')) ?? '', 0, 60);
    $birth = $str('birthdate');
    if ($birth !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $birth);
        if (!$d || $d->format('Y-m-d') !== $birth || $d > new DateTimeImmutable('-13 years') || $d < new DateTimeImmutable('-120 years')) throw new InvalidArgumentException('Enter a valid birthdate.');
    }
    $mode = $str('liveness_mode') === 'manual' ? 'manual' : 'auto';
    $log = kyc_validate_liveness_log($str('liveness_log'), $mode);
    $saved = [];
    try {
        $saved['id_front'] = kyc_store_image($str('id_front'), 'The front of your ID', 400);
        $saved['id_back'] = kyc_store_image($str('id_back'), 'The back of your ID', 400);
        $frames = [];
        foreach (KYC_STEPS as $step) $saved['frame_' . $step] = $frames[$step] = kyc_store_image($str('frame_' . $step), 'The "' . kyc_step_labels()[$step] . '" photo', 160);
        $reference = 'KYC-' . strtoupper(bin2hex(random_bytes(5)));
        $pdo->prepare('INSERT INTO kyc_submissions (reference, subject_type, subject_id, full_name, birthdate, id_type, id_number, id_front, id_back, selfie, liveness_frames, liveness_log, liveness_mode, ip, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$reference, $type, $subjectId, $name, $birth ?: null, $idType, $idNumber ?: null, $saved['id_front'], $saved['id_back'], $frames['center'],
                json_encode($frames), json_encode($log), $mode, function_exists('rate_limit_client_ip') ? rate_limit_client_ip() : null, mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
        return $reference;
    } catch (Throwable $e) {
        foreach ($saved as $file) @unlink(kyc_storage_dir() . '/' . $file);
        throw $e;
    }
}

/** Admin decision. $action: approved | rejected | needs_info. */
function kyc_review(PDO $pdo, int $id, string $action, string $note, int $adminId): array
{
    if (!in_array($action, ['approved', 'rejected', 'needs_info'], true)) throw new InvalidArgumentException('Choose a decision.');
    $note = trim($note);
    if ($action !== 'approved' && mb_strlen($note) < 5) throw new InvalidArgumentException('Tell the person what to fix (at least a few words).');
    $s = $pdo->prepare('SELECT * FROM kyc_submissions WHERE id = ?');
    $s->execute([$id]);
    $k = $s->fetch();
    if (!$k) throw new InvalidArgumentException('Submission not found.');
    if ($k['status'] !== 'pending') throw new InvalidArgumentException('This submission was already reviewed.');
    $pdo->prepare('UPDATE kyc_submissions SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = ?')
        ->execute([$action, $note !== '' ? mb_substr($note, 0, 255) : null, $adminId, $id, 'pending']);
    return $k;
}

/** Name and contact of the account a submission belongs to (for Admin). */
function kyc_subject_info(PDO $pdo, string $type, int $id): array
{
    $q = [
        'user' => ['SELECT full_name name, email, mobile FROM users WHERE id = ?', 'customer.php?id='],
        'merchant' => ['SELECT business_name name, email, mobile, owner_name FROM merchants WHERE id = ?', 'merchant.php?id='],
        'driver' => ['SELECT full_name name, email, mobile FROM uride_drivers WHERE id = ?', 'driver.php?id='],
        'agent' => ['SELECT full_name name, email, mobile FROM agents WHERE id = ?', 'agent.php?id='],
    ][$type] ?? null;
    if (!$q) return ['name' => '?', 'email' => '', 'mobile' => '', 'link' => '#'];
    $s = $pdo->prepare($q[0]);
    $s->execute([$id]);
    $r = $s->fetch() ?: ['name' => '(deleted)', 'email' => '', 'mobile' => ''];
    $r['link'] = $q[1] . $id;
    return $r;
}

function kyc_send_file(string $stored): never
{
    $path = kyc_storage_dir() . '/' . basename($stored);
    if (!preg_match('/^[a-f0-9]{32}\.jpg$/D', basename($stored)) || !is_file($path)) { http_response_code(404); exit('Not found.'); }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox");
    readfile($path);
    exit;
}

/** Status pill for portals and Admin. */
function kyc_badge(string $status): string
{
    $tone = ['approved' => 'good', 'pending' => 'info', 'needs_info' => 'warn', 'rejected' => 'bad'][$status] ?? 'muted';
    return '<span class="badge ' . $tone . '">' . htmlspecialchars(kyc_statuses()[$status] ?? $status, ENT_QUOTES) . '</span>';
}

/** CSP for pages that run the liveness check (WebAssembly needs 'wasm-unsafe-eval'). */
function kyc_send_csp(): void
{
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'self' 'wasm-unsafe-eval'; worker-src 'self' blob:; connect-src 'self'; frame-ancestors 'none'; form-action 'self'");
    header('Permissions-Policy: camera=(self)');
}
