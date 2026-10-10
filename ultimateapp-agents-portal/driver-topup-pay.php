<?php
// Pay a URide driver's wallet top-up request from this account's BCash.
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/uride.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$token = is_string($_REQUEST['token'] ?? null) ? $_REQUEST['token'] : '';
if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { http_response_code(404); exit('Invalid top-up request.'); }
if (!current_user()) {
    $_SESSION['mctc_return'] = 'driver-topup-pay.php?token=' . $token;
    redirect('login.php');
}
$user = require_auth();
$load = static function () use ($pdo, $token) {
    $s = $pdo->prepare("SELECT t.*, d.full_name, d.code, d.plate_no, d.vehicle_type FROM uride_driver_topups t JOIN uride_drivers d ON d.id = t.driver_id WHERE t.request_token = ? AND t.method = 'boracay_cash'");
    $s->execute([$token]);
    return $s->fetch();
};
$order = $load();
if (!$order) { http_response_code(404); exit('Top-up request not found.'); }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('driver_topup_pay', 'user:' . $user['id'], 20, 600);
    try {
        if (!isset($_POST['confirm'])) throw new InvalidArgumentException('Tick the box to confirm the payment.');
        uride_topup_bcash_pay($pdo, (int) $user['id'], $token);
        redirect('driver-topup-pay.php?token=' . $token . '&done=1');
    } catch (InvalidArgumentException $ex) {
        $error = $ex->getMessage();
    } catch (Throwable $ex) {
        error_log('Driver top-up BCash payment failed: ' . get_class($ex) . ' ' . $ex->getMessage());
        $error = 'Payment failed. No BCash was deducted. Please try again.';
    }
    $order = $load();
}
$bal = $pdo->prepare('SELECT balance_centavos FROM boracay_cash_wallets WHERE user_id = ?');
$bal->execute([$user['id']]);
$balance = (int) $bal->fetchColumn();
$total = (int) $order['amount_centavos'] + (int) $order['fee_centavos'];
$paidByMe = $order['status'] === 'paid' && (int) $order['payer_user_id'] === (int) $user['id'];
$open = $order['status'] === 'pending' && !uride_topup_is_expired($order);
$pageTitle = 'Driver top-up';
require __DIR__ . '/includes/header.php';
?>
<section class="screen pay-screen">
    <header class="page-head"><a href="qr.php" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>Driver top-up</h1><span></span></header>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
    <div class="pay-to">
        <span class="pay-avatar merchant" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($order['full_name'], 0, 1))) ?></span>
        <div><small>URide driver wallet</small><strong><?= e(uride_short_name($order['full_name'])) ?></strong><span><?= e($order['code']) ?> · <?= e(uride_vehicles()[$order['vehicle_type']]['label'] ?? '') ?> · <?= e($order['plate_no']) ?></span></div>
    </div>
    <?php if ($paidByMe): ?>
        <div class="dt-done"><span>✓</span><h2>Paid PHP <?= peso($total) ?></h2><p>From your BCash to <?= e(uride_short_name($order['full_name'])) ?>'s driver wallet.</p><small>Reference <?= e($order['reference']) ?> · <?= e(date('M j, Y g:i A', strtotime((string) $order['paid_at']))) ?></small></div>
        <a class="btn dark" href="dashboard.php">Done</a>
    <?php elseif (!$open): ?>
        <p class="alert" role="status">This top-up request is <?= $order['status'] === 'paid' ? 'already paid' : ($order['status'] === 'cancelled' ? 'cancelled' : 'expired') ?>. Ask the driver for a new QR if needed.</p>
        <a class="btn dark" href="qr.php">Scan another QR</a>
    <?php else: ?>
        <form method="post" class="pay-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="token" value="<?= e($token) ?>">
            <div class="p2m-summary">
                <div><span>Driver wallet top-up</span><b>PHP <?= peso((int) $order['amount_centavos']) ?></b></div>
                <div class="total"><span>BCash to deduct</span><b>PHP <?= peso($total) ?></b></div>
                <div><span>Your BCash</span><b class="<?= $balance < $total ? 'neg' : '' ?>">PHP <?= peso($balance) ?></b></div>
            </div>
            <?php if ($order['mode'] === 'test'): ?><p class="pay-fine">Test environment — no real money moves.</p><?php endif; ?>
            <label class="check-row"><input type="checkbox" name="confirm" value="1" required><span>I want to pay PHP <?= peso($total) ?> from my BCash into this driver's URide wallet. This cannot be reversed from the app.</span></label>
            <button class="btn dark" type="submit"<?= $balance < $total ? ' disabled' : '' ?>><?= $balance < $total ? 'Not enough BCash' : 'Pay PHP ' . peso($total) ?></button>
            <?php if ($balance < $total): ?><p class="pay-fine">Convert Credits to BCash first in <a href="boracay-cash.php">BCash</a>.</p><?php endif; ?>
        </form>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
