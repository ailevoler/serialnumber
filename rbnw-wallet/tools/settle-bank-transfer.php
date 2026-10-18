<?php
/**
 * Mark a bank transfer as sent or failed (failed = auto refund to wallet).
 * Run from SSH / Hostinger terminal after processing it in the bank's
 * InstaPay / PESONet channel:
 *
 *   php tools/settle-bank-transfer.php list
 *   php tools/settle-bank-transfer.php complete BT20261019ABCDEF12
 *   php tools/settle-bank-transfer.php fail BT20261019ABCDEF12 "Account closed"
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/bank.php';

[$cmd, $ref, $reason] = array_pad(array_slice($argv, 1), 3, null);

if ($cmd === 'list') {
    $rows = db()->query(
        "SELECT reference_no, created_at, bank_name, account_name, account_number_masked, channel, amount
         FROM rbnw_bank_transfers WHERE status = 'processing' ORDER BY id"
    )->fetchAll();
    foreach ($rows as $r) {
        echo implode("\t", $r), "\n";
    }
    echo count($rows), " processing\n";
    exit;
}
if (!in_array($cmd, ['complete', 'fail'], true) || !$ref) {
    fwrite(STDERR, "Usage: php tools/settle-bank-transfer.php list | complete <REF> | fail <REF> \"reason\"\n");
    exit(1);
}
$ok = bank_transfer_settle($ref, $cmd === 'fail' ? 'failed' : 'completed', $reason);
echo $ok ? "OK: {$ref} marked " . ($cmd === 'fail' ? 'failed (refunded)' : 'completed') . "\n" : "Not found or not processing: {$ref}\n";
exit($ok ? 0 : 1);
