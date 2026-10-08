<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/suniway.php';
$user = require_auth();
header('Cache-Control: no-store');
$ref = is_string($_GET['ref'] ?? null) ? $_GET['ref'] : '';
$load = static function () use ($pdo, $ref, $user): ?array {
    $s = $pdo->prepare('SELECT * FROM suniway_transactions WHERE reference = ? AND user_id = ?');
    $s->execute([$ref, $user['id']]);
    return $s->fetch() ?: null;
};
$t = $load();
if (!$t) { http_response_code(404); exit('Transaction not found.'); }
$notice = '';
$stale = !$t['last_checked_at'] || strtotime((string) $t['last_checked_at']) < time() - 20;
if (($_SERVER['REQUEST_METHOD'] === 'POST' || $stale) && $t['status'] === 'pending' && $t['remote_id']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { verify_csrf(); rate_limit_enforce('suniway_refresh', 'user:' . $user['id'], 60, 3600); }
    try { suniway_refresh($pdo, (int) $t['id']); } catch (InvalidArgumentException $ex) { $notice = $ex->getMessage(); }
    $t = $load();
}
$svc = suniway_services()[$t['service']];
[$label, $tone] = suniway_status_label($t['status']);
$pageTitle = $svc['label'] . ' receipt';
require __DIR__ . '/includes/header.php';
?>
<section class="screen seed-screen upay-screen">
    <header class="page-head"><a href="<?= e($svc['page']) ?>" aria-label="Back"><span data-icon="arrow-left"></span></a><h1><?= e($svc['label']) ?></h1><span></span></header>
    <div class="upay-status <?= e($tone) ?>"><span data-icon="<?= $t['status'] === 'success' ? 'check-circle' : 'receipt' ?>"></span><h2><?= e($label) ?></h2><p>Reference <?= e($t['reference']) ?></p></div>
    <?php if ($notice): ?><p class="alert" role="status"><?= e($notice) ?></p><?php endif; ?>
    <div class="upay-review"><dl>
        <div><dt><?= $t['service'] === 'bills_pay' ? 'Biller' : ($t['service'] === 'eload' ? 'Network' : 'E-wallet') ?></dt><dd><?= e($t['provider_name']) ?></dd></div>
        <div><dt><?= e($svc['account']) ?></dt><dd><?= e($t['account_number']) ?></dd></div>
        <div><dt>Amount</dt><dd>₱<?= peso((int) $t['amount_centavos']) ?></dd></div>
        <?php $fees = (int) $t['total_centavos'] - (int) $t['amount_centavos']; if ($fees > 0): ?><div><dt>Fees</dt><dd>₱<?= peso($fees) ?></dd></div><?php endif; ?>
        <div class="total"><dt>Total (Credits)</dt><dd><?= peso((int) $t['total_centavos']) ?></dd></div>
        <?php if ($t['remote_reference']): ?><div><dt>SUNIWAY reference</dt><dd><?= e($t['remote_reference']) ?></dd></div><?php endif; ?>
        <div><dt>Date</dt><dd><?= e(date('M j, Y g:i A', strtotime($t['created_at']))) ?></dd></div>
    </dl></div>
    <?php if ($t['status'] === 'pending'): ?>
        <p class="seed-notice">The provider is processing this. It usually takes a few seconds to a few minutes. If it fails, your Credits are returned automatically.</p>
        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="btn dark" type="submit">Refresh status</button></form>
    <?php elseif ($t['status'] === 'unknown' || $t['status'] === 'submitting'): ?>
        <p class="seed-notice">We did not get a reply from the provider in time. Our team will confirm this payment. If it did not go through, your <?= peso((int) $t['total_centavos']) ?> Credits will be returned. No need to pay again.</p>
    <?php elseif ($t['status'] === 'failed'): ?>
        <p class="seed-notice">This payment did not go through<?= $t['refunded_at'] ? ' and ' . peso((int) $t['total_centavos']) . ' Credits were returned to your balance' : '' ?>.</p>
    <?php endif; ?>
    <a class="btn light" href="<?= e($svc['page']) ?>">New <?= e($svc['label']) ?></a>
</section>
<?php require __DIR__ . '/includes/nav.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
