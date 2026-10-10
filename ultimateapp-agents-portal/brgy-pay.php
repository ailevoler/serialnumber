<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ubarangay.php';
$user = require_auth();
if (!ubarangay_enabled()) redirect('ubarangay.php');
header('Cache-Control: no-store');
$ref = (string) ($_POST['ref'] ?? $_GET['ref'] ?? '');
$stmt = $pdo->prepare('SELECT a.*, t.name type_name FROM brgy_applications a JOIN brgy_permit_types t ON t.id = a.permit_type_id WHERE a.reference = ? AND a.user_id = ?');
$stmt->execute([$ref, $user['id']]);
$app = $stmt->fetch();
if (!$app) { http_response_code(404); exit('Application not found.'); }
if ($app['status'] !== 'pending_payment') redirect('brgy-application.php?ref=' . rawurlencode($ref));
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('brgy_pay', 'user:' . $user['id'], 20, 3600);
    $method = (string) ($_POST['method'] ?? '');
    try {
        if ($method === 'qrph') {
            redirect(brgy_start_qrph($pdo, $app, $app['type_name']));
        }
        brgy_pay_wallet($pdo, (int) $user['id'], $ref, $method);
        redirect('brgy-application.php?ref=' . rawurlencode($ref) . '&paid=1');
    } catch (InvalidArgumentException $ex) {
        $error = $ex->getMessage();
    } catch (Throwable $ex) {
        error_log('UBarangay payment failed: ' . get_class($ex) . ' ' . $ex->getMessage());
        require_once __DIR__ . '/includes/paymongo.php';
        $error = $method === 'qrph' ? pm_checkout_user_message($ex, paymongo_settings()['mode']) : 'Payment could not be completed. Nothing was deducted.';
    }
}
$cash = $pdo->prepare('SELECT balance_centavos FROM boracay_cash_wallets WHERE user_id = ?');
$cash->execute([$user['id']]); $cash = (int) ($cash->fetchColumn() ?: 0);
$credits = (int) round((float) $user['credits'] * 100);
$fee = (int) $app['fee_centavos'];
$pageTitle = 'Pay barangay fee';
require __DIR__ . '/includes/header.php';
?>
<section class="screen pay-screen ub-screen">
    <header class="page-head"><a href="ubarangay.php" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>Pay fee</h1><span></span></header>
    <ol class="ub-steps"><li class="done">Form</li><li class="done">Selfie</li><li class="done">Requirements</li><li class="on">Pay</li></ol>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
    <div class="p2m-summary">
        <div><span><?= e($app['type_name']) ?></span><b>₱<?= peso($fee) ?></b></div>
        <div><span>Barangay</span><b><?= e($app['barangay']) ?></b></div>
        <div><span>Reference</span><b><?= e($app['reference']) ?></b></div>
        <div class="total"><span>Total</span><b>₱<?= peso($fee) ?></b></div>
    </div>
    <form method="post" class="pay-form" data-pay-form>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="ref" value="<?= e($app['reference']) ?>">
        <p class="p2m-label">Pay with</p>
        <div class="p2m-sources">
            <?php foreach ([['credits', 'Credits', 'Balance ' . peso($credits) . ' · 1 Credit = PHP 1', $credits >= $fee], ['boracay_cash', 'BCash', 'Balance PHP ' . peso($cash), $cash >= $fee], ['qrph', 'QR Ph', 'GCash, Maya or any bank app via PayMongo', true]] as [$k, $label, $hint, $ok]): ?>
            <label class="p2m-source<?= $ok ? '' : ' disabled' ?>"><input type="radio" name="method" value="<?= $k ?>"<?= $ok ? '' : ' disabled' ?><?= $k === ($credits >= $fee ? 'credits' : ($cash >= $fee ? 'boracay_cash' : 'qrph')) ? ' checked' : '' ?>><span><strong><?= e($label) ?></strong><small><?= e($ok ? $hint : 'Not enough balance') ?></small></span><em><?= $k === 'qrph' ? 'Scan to pay' : 'Instant' ?></em></label>
            <?php endforeach; ?>
        </div>
        <p class="pay-fine">After payment your application goes to the Barangay <?= e($app['barangay']) ?> office for review. If it is rejected, Credits and BCash payments are refunded automatically.</p>
        <button class="btn dark" type="submit" data-pay-submit>Pay ₱<?= peso($fee) ?></button>
    </form>
</section>
<script defer src="assets/js/qr.js?v=2"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
