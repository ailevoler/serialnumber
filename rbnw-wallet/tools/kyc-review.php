<?php
/**
 * Review KYC submissions from the Hostinger terminal / SSH.
 * The photos are in KYC_STORAGE_DIR/<user_id>/ (open them via File Manager).
 *
 *   php tools/kyc-review.php list
 *   php tools/kyc-review.php approve <submission_id> [wallet_level, default 2]
 *   php tools/kyc-review.php reject <submission_id> "Blurry ID photo"
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/kyc.php';

[$cmd, $id, $extra] = array_pad(array_slice($argv, 1), 3, null);

if ($cmd === 'list') {
    $rows = db()->query(
        "SELECT s.id, s.submitted_at, u.first_name, u.last_name, u.mobile_number, s.id_type, s.id_number,
                s.id_front_path, s.id_back_path, s.selfie_path, s.liveness
         FROM rbnw_kyc_submissions s JOIN rbnw_users u ON u.id = s.user_id
         WHERE s.status = 'pending' ORDER BY s.id"
    )->fetchAll();
    foreach ($rows as $r) {
        $live = json_decode((string)$r['liveness'], true) ?: [];
        echo "#{$r['id']}  {$r['submitted_at']}  {$r['first_name']} {$r['last_name']} ({$r['mobile_number']})\n",
             "     {$r['id_type']} {$r['id_number']}\n",
             "     front:  " . KYC_STORAGE_DIR . "/{$r['id_front_path']}\n",
             $r['id_back_path'] ? "     back:   " . KYC_STORAGE_DIR . "/{$r['id_back_path']}\n" : '',
             "     selfie: " . KYC_STORAGE_DIR . "/{$r['selfie_path']}\n",
             "     liveness: " . implode(', ', $live['passed'] ?? []) . "\n";
    }
    echo count($rows), " pending\n";
    exit;
}
if (!in_array($cmd, ['approve', 'reject'], true) || !ctype_digit((string)$id)) {
    fwrite(STDERR, "Usage: php tools/kyc-review.php list | approve <id> [level] | reject <id> \"reason\"\n");
    exit(1);
}
$ok = $cmd === 'approve'
    ? kyc_review((int)$id, 'approve', null, $extra !== null ? max(1, (int)$extra) : 2)
    : kyc_review((int)$id, 'reject', $extra ?: 'Please retake clear photos of your ID and selfie');
echo $ok ? "OK: submission #{$id} {$cmd}d\n" : "Not found or already reviewed: #{$id}\n";
exit($ok ? 0 : 1);
