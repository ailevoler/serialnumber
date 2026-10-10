<?php
// MCTC / BCash top-up request: a QR the MCTC agent (or an Ultimate App user paying with BCash) scans.
require_once __DIR__ . '/_bootstrap.php';
$driver = driver_require();
$stmt = $pdo->prepare("SELECT * FROM uride_driver_topups WHERE reference = ? AND driver_id = ? AND method <> 'qrph'");
$stmt->execute([dq('reference'), $driver['id']]);
$order = $stmt->fetch();
if (!$order) redirect('wallet.php');
if ($order['status'] === 'paid') { dflash('success', 'Top-up received. Your wallet has been credited.'); redirect('wallet.php'); }
$method = $order['method'];
$expired = uride_topup_is_expired($order) || $order['status'] !== 'pending';
$base = rtrim((string) (paymongo_settings()['app_url'] ?? ''), '/');
$page = $method === 'mctc' ? 'mctc-approve.php' : 'driver-topup-pay.php';
$link = ($base !== '' ? $base : '..') . '/' . $page . '?token=' . rawurlencode((string) $order['request_token']);
$total = (int) $order['amount_centavos'] + (int) $order['fee_centavos'];
[$label] = uride_topup_methods()[$method];
driver_header($label . ' top-up', 'wallet');
?>
<section class="d-qr" data-topup-request data-reference="<?= e($order['reference']) ?>" data-status="<?= $expired ? 'expired' : 'pending' ?>">
    <div class="tq-card<?= $expired ? ' expired' : '' ?>">
        <div class="tq-brand"><span class="<?= e($method) ?>"><?= e($label) ?></span><small><?= $method === 'mctc' ? 'Show this to an MCTC top-up center' : 'Scan with the Ultimate App to pay with BCash' ?></small></div>
        <div class="tq-code"><div class="d-qr-box" data-qr-text="<?= e($link) ?>" aria-label="Top-up request QR code"></div><div class="tq-void" data-tq-void<?= $expired ? '' : ' hidden' ?>><?= $order['status'] === 'cancelled' ? 'Cancelled' : 'Expired' ?></div></div>
        <strong class="tq-amount">PHP <?= peso($total) ?></strong>
        <p class="tq-sub">Driver wallet top-up ₱<?= peso((int) $order['amount_centavos']) ?><?= (int) $order['fee_centavos'] > 0 ? ' + ₱' . peso((int) $order['fee_centavos']) . ' fee' : '' ?> · <?= e($driver['code']) ?></p>
        <p class="tq-timer">Valid until <?= e(date('M j, g:i A', strtotime((string) $order['expires_at']))) ?></p>
        <p class="tq-status" data-tq-status role="status"><?= $expired ? 'This request can no longer be paid.' : '<span class="tq-dot"></span> Waiting for payment…' ?></p>
    </div>
    <?php if (!$expired): ?>
    <div class="tq-actions">
        <?php if ($method === 'boracay_cash'): ?><a class="btn grad" href="<?= e($link) ?>">Pay with my Ultimate App account</a><?php endif; ?>
        <form method="post" action="wallet.php" data-confirm="Cancel this top-up request?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="cancel_topup"><input type="hidden" name="reference" value="<?= e($order['reference']) ?>"><button class="btn" type="submit">Cancel request</button></form>
    </div>
    <?php else: ?>
    <div class="tq-actions"><a class="btn grad" href="wallet.php?method=<?= e($method) ?>">Create a new request</a></div>
    <?php endif; ?>
    <ol class="tq-steps">
        <?php if ($method === 'mctc'): ?>
            <li>Go to any Ultimate App <b>MCTC</b> top-up center and pay <b>PHP <?= peso($total) ?></b> in cash.</li>
            <li>The MCTC merchant scans this QR (Merchant Portal › MCTC Top-up) and enters their receipt number.</li>
            <li>Your wallet is credited right away — this page updates automatically. Keep your receipt.</li>
        <?php else: ?>
            <li>On this phone: tap <b>Pay with my Ultimate App account</b> and sign in if asked.</li>
            <li>On another phone: open the Ultimate App › Scan QR (or the phone camera) and scan this code.</li>
            <li>Confirm <b>PHP <?= peso($total) ?></b> from BCash. Your wallet updates here automatically. The request expires after 30 minutes.</li>
        <?php endif; ?>
    </ol>
    <details class="d-card"><summary>Can't scan? Use the request link</summary><input class="d-link" type="text" readonly value="<?= e($link) ?>" aria-label="Request link"></details>
    <?php if ($order['mode'] === 'test'): ?><p class="table-meta">Test environment — no real money moves.</p><?php endif; ?>
    <p class="table-meta">Reference <?= e($order['reference']) ?></p>
</section>
<?php driver_footer(['../assets/js/qrcode.min.js', 'assets/driver.js?v=4']);
