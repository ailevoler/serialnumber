<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/paymongo.php';
$driver = driver_require();
$reference = (string) ($_POST['reference'] ?? $_GET['reference'] ?? '');
$stmt = $pdo->prepare('SELECT * FROM uride_driver_topups WHERE reference = ? AND driver_id = ?');
$stmt->execute([$reference, $driver['id']]);
$order = $stmt->fetch();
if (!$order || !$order['payment_intent_id'] || $order['status'] === 'paid') redirect('wallet.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && dp('action') === 'renew') {
    verify_csrf();
    rate_limit_enforce('driver_wallet', 'driver:' . $driver['id'], 30, 600);
    try {
        redirect(uride_topup_start($pdo, $driver, (int) $order['amount_centavos'], pm_config()));
    } catch (Throwable $ex) {
        error_log('Driver QR renew failed: ' . $ex->getMessage());
        dflash('error', 'Could not create a new QR right now. Please try again.');
        redirect('wallet.php');
    }
}
$expiresTs = strtotime((string) $order['qr_expires_at']);
$expired = $expiresTs <= time();
$image = (string) $order['qr_image'];
$total = (int) $order['amount_centavos'] + (int) $order['fee_centavos'];
driver_header('Scan to pay', 'wallet');
?>
<section class="d-qr" data-topup-qr data-reference="<?= e($order['reference']) ?>" data-expires="<?= $expiresTs ?>" data-now="<?= time() ?>" data-done-url="wallet.php" data-paid-text="Payment received! Adding it to your wallet…">
    <div class="tq-card<?= $expired ? ' expired' : '' ?>">
        <div class="tq-brand"><span>QR Ph</span><small>Pay with GCash, Maya or any bank app</small></div>
        <div class="tq-code"><img src="<?= e($image) ?>" alt="QR Ph code for PHP <?= peso($total) ?>"><div class="tq-void" data-tq-void<?= $expired ? '' : ' hidden' ?>>QR expired</div></div>
        <strong class="tq-amount">PHP <?= peso($total) ?></strong>
        <p class="tq-sub">Driver wallet top-up ₱<?= peso((int) $order['amount_centavos']) ?><?= (int) $order['fee_centavos'] > 0 ? ' + ₱' . peso((int) $order['fee_centavos']) . ' fee' : '' ?></p>
        <p class="tq-timer" data-tq-timer role="timer"><?= $expired ? 'Expired' : 'Expires in 30:00' ?></p>
        <p class="tq-status" data-tq-status role="status"><span class="tq-dot"></span> Waiting for payment…</p>
    </div>
    <div class="tq-actions"<?= $expired ? ' hidden' : '' ?> data-tq-live>
        <a class="btn" href="<?= e($image) ?>" download="uride-topup-<?= e(substr($order['reference'], 3, 8)) ?>.png">Save QR to photos</a>
        <a class="btn" href="wallet.php">Back to wallet</a>
    </div>
    <form method="post" class="tq-actions" data-tq-renew<?= $expired ? '' : ' hidden' ?>><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="reference" value="<?= e($order['reference']) ?>"><input type="hidden" name="action" value="renew"><button class="btn grad" type="submit">Generate a new QR</button></form>
    <ol class="tq-steps">
        <li>Open GCash, Maya or your bank app and choose <b>Scan QR</b>.</li>
        <li>On the same phone? Tap <b>Save QR to photos</b> and upload it from your gallery.</li>
        <li>Pay exactly <b>PHP <?= peso($total) ?></b>. Your wallet updates here automatically.</li>
    </ol>
    <?php if ($order['mode'] === 'test'): ?><p class="table-meta">Test environment — no real money moves.</p><?php endif; ?>
    <p class="table-meta">Reference <?= e($order['reference']) ?>. Do not pay the same QR twice.</p>
</section>
<?php driver_footer(['../assets/js/topup-qr.js?v=3']);
