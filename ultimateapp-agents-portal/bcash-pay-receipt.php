<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/bcash.php';
$user = require_auth();
header('Cache-Control: no-store');
$reference = is_string($_GET['reference'] ?? null) ? $_GET['reference'] : '';
$stmt = $pdo->prepare('SELECT t.*, payer.full_name AS payer_name, payer.qr_code AS payer_code, payee.full_name AS payee_name, payee.qr_code AS payee_code
    FROM bcash_transfers t JOIN users payer ON payer.id = t.payer_id JOIN users payee ON payee.id = t.payee_id
    WHERE t.reference = ? AND (t.payer_id = ? OR t.payee_id = ?)');
$stmt->execute([$reference, $user['id'], $user['id']]);
$transfer = $stmt->fetch();
if (!$transfer) { http_response_code(404); exit('Transfer not found.'); }
$sent = (int) $transfer['payer_id'] === (int) $user['id'];
$pageTitle = 'BCash receipt';
require __DIR__ . '/includes/header.php';
?>
<section class="screen pay-screen bcash-page">
    <header class="page-head"><a href="<?= $sent ? 'bcash-qr.php' : 'transactions.php' ?>" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>Receipt</h1><span></span></header>
    <div class="pay-receipt">
        <img class="bcash-receipt-logo" src="assets/images/bcash/bcash-icon-96.png?v=1" alt="BCash" width="48" height="48">
        <p><?= $sent ? 'BCash sent' : 'BCash received' ?></p>
        <h2><?= $sent ? '-' : '+' ?>PHP <?= peso((int) $transfer['amount_centavos']) ?> <small>BCash</small></h2>
        <dl>
            <div><dt><?= $sent ? 'Sent to' : 'From' ?></dt><dd><?= e(qr_display_name($sent ? $transfer['payee_name'] : $transfer['payer_name'])) ?><small><?= e($sent ? $transfer['payee_code'] : $transfer['payer_code']) ?></small></dd></div>
            <?php if ($transfer['note'] !== null): ?><div><dt>Note</dt><dd><?= e($transfer['note']) ?></dd></div><?php endif; ?>
            <div><dt>Reference</dt><dd><?= e($transfer['reference']) ?></dd></div>
            <div><dt>Date</dt><dd><?= e($transfer['created_at']) ?></dd></div>
            <div><dt>Status</dt><dd>Completed</dd></div>
        </dl>
    </div>
    <?php if ($sent): ?><a class="btn dark" href="dashboard.php?wallet=cash">Done</a><?php endif; ?>
    <a class="btn light" href="transactions.php">View transactions</a>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
