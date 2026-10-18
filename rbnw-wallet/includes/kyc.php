<?php
/**
 * RBNW KYC — valid ID (front/back) + selfie with on-device liveness.
 * Images are stored under KYC_STORAGE_DIR/<user_id>/ (set it outside
 * public_html; the in-app default is blocked by storage/.htaccess). RBNW
 * staff review submissions with tools/kyc-review.php.
 */

require_once __DIR__ . '/functions.php';

const KYC_ID_TYPES = [
    'PhilID (National ID)'        => true,   // true = has a back side to capture
    "Driver's License"            => true,
    'UMID'                        => true,
    'Passport'                    => false,
    'SSS ID'                      => true,
    'PRC ID'                      => true,
    'Postal ID'                   => true,
    "Voter's ID"                  => true,
    'PhilHealth ID'               => true,
    'TIN ID'                      => true,
];

const KYC_MAX_UPLOAD_BYTES = 6 * 1024 * 1024;

function kyc_storage_dir(int $userId): string
{
    $dir = KYC_STORAGE_DIR . '/' . $userId;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not prepare secure storage.');
    }
    return $dir;
}

/**
 * Validate an uploaded image and re-encode it as JPEG (max 1600px). The
 * re-encode drops EXIF/GPS metadata and anything that isn't pixel data.
 * Returns the path relative to storage/kyc.
 */
function kyc_store_image(array $file, int $userId, string $label): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Please add the {$label} photo.");
    }
    if ($file['size'] > KYC_MAX_UPLOAD_BYTES) {
        throw new RuntimeException("The {$label} photo is too large (max 6 MB).");
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException("The {$label} must be a JPG, PNG or WEBP photo.");
    }
    $src = @imagecreatefromstring(file_get_contents($file['tmp_name']));
    if (!$src) {
        throw new RuntimeException("We couldn't read the {$label} photo. Please retake it.");
    }

    $w = imagesx($src);
    $h = imagesy($src);
    if (min($w, $h) < 320) {
        imagedestroy($src);
        throw new RuntimeException("The {$label} photo is too small. Please retake it closer.");
    }
    $scale = min(1, 1600 / max($w, $h));
    $dst = imagecreatetruecolor((int)round($w * $scale), (int)round($h * $scale));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
    imagedestroy($src);

    $name = $label === 'selfie' ? 'selfie' : str_replace(' ', '-', $label);
    $rel  = $userId . '/' . date('Ymd-His') . '-' . $name . '-' . bin2hex(random_bytes(12)) . '.jpg';
    kyc_storage_dir($userId);
    imagejpeg($dst, KYC_STORAGE_DIR . '/' . $rel, 85);
    imagedestroy($dst);
    return $rel;
}

function kyc_latest(int $userId): ?array
{
    $stmt = db()->prepare("SELECT * FROM rbnw_kyc_submissions WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

/** Approve or reject a pending submission and update the user's KYC status. */
function kyc_review(int $submissionId, string $decision, ?string $note = null, int $level = 2): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT * FROM rbnw_kyc_submissions WHERE id = ? FOR UPDATE");
    $stmt->execute([$submissionId]);
    $sub = $stmt->fetch();
    if (!$sub || $sub['status'] !== 'pending') {
        $pdo->rollBack();
        return false;
    }
    $approved = $decision === 'approve';
    $pdo->prepare("UPDATE rbnw_kyc_submissions SET status = ?, review_note = ?, reviewed_at = NOW() WHERE id = ?")
        ->execute([$approved ? 'approved' : 'rejected', $note, $submissionId]);
    if ($approved) {
        $pdo->prepare("UPDATE rbnw_users SET kyc_status = 'verified', wallet_level = GREATEST(wallet_level, ?) WHERE id = ?")
            ->execute([$level, $sub['user_id']]);
    } else {
        $pdo->prepare("UPDATE rbnw_users SET kyc_status = 'rejected' WHERE id = ?")->execute([$sub['user_id']]);
    }
    $pdo->commit();

    try {
        push_notification((int)$sub['user_id'],
            $approved ? 'Account verified' : 'Verification needs attention',
            $approved ? 'Your ID was verified. Higher limits, Loans and more are now unlocked.'
                      : 'We could not verify your ID' . ($note ? ": {$note}" : '') . '. Please submit again.',
            'kyc');
    } catch (Throwable $e) {
        error_log('[RBNW] kyc notification failed: ' . $e->getMessage());
    }
    return true;
}
