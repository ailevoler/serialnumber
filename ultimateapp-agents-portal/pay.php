<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/qr_pay.php';
$user = require_auth();
header('Cache-Control: no-store');
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
if (is_string($input['to'] ?? null) && preg_match('/^UM-[A-F0-9]{10}$/D', strtoupper(trim($input['to'])))) {
    redirect('pay-merchant.php?m=' . rawurlencode(strtoupper(trim($input['to']))));
}
$code = qr_pay_normalize_code(is_string($input['to'] ?? null) ? $input['to'] : '');
$error = '';
$payee = null;
if ($code === null) {
    $error = 'This is not a valid Ultimate App payment QR.';
} else {
    rate_limit_enforce('qr_lookup', 'user:' . $user['id'], 60, 600);
    $payee = qr_pay_find_user($pdo, $code);
    if (!$payee) $error = 'We could not find an Ultimate App account for this QR code.';
    elseif ((int) $payee['id'] === (int) $user['id']) { $error = 'This is your own QR code. Ask the person you are paying for theirs.'; $payee = null; }
}

// Amount requested inside the QR (optional). Invalid values are ignored so the payer can type one.
$requestedAmount = '';
if (is_string($_GET['amount'] ?? null)) {
    try { $requestedAmount = number_format(qr_pay_amount($_GET['amount']) / 100, 2, '.', ''); } catch (InvalidArgumentException) {}
}
$amountValue = is_string($_POST['amount'] ?? null) ? $_POST['amount'] : $requestedAmount;
$noteValue = is_string($_POST['note'] ?? null) ? $_POST['note'] : '';
$fixedAmount = is_string($input['fixed'] ?? null) && $input['fixed'] === '1' && $requestedAmount !== '';

if ($payee && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('qr_pay', 'user:' . $user['id'], 20, 3600);
    $key = is_string($_POST['request_key'] ?? null) ? $_POST['request_key'] : '';
    if (preg_match('/^[a-f0-9]{64}$/D', $key)) {
        $prior = $pdo->prepare('SELECT reference FROM qr_payments WHERE payer_id = ? AND request_key = ?');
        $prior->execute([$user['id'], $key]);
        if ($row = $prior->fetch()) redirect('pay-receipt.php?reference=' . rawurlencode($row['reference']));
    }
    if (!$key || !hash_equals($_SESSION['qr_pay_key'] ?? '', $key)) {
        $error = 'This payment was already submitted. Check your transactions before trying again.';
    } else {
        try {
            if ($fixedAmount && $amountValue !== $requestedAmount) throw new InvalidArgumentException('The amount in this QR cannot be changed.');
            $centavos = qr_pay_amount($amountValue);
            $reference = qr_pay_transfer($pdo, (int) $user['id'], (int) $payee['id'], $centavos, $key, qr_pay_note($noteValue));
            unset($_SESSION['qr_pay_key']);
            redirect('pay-receipt.php?reference=' . rawurlencode($reference));
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('QR payment failed: ' . get_class($exception) . ' ' . $exception->getMessage());
            $error = 'Could not complete the payment. No Credits were moved. Please try again.';
        }
    }
    $user = current_user();
}
if (empty($_SESSION['qr_pay_key'])) $_SESSION['qr_pay_key'] = bin2hex(random_bytes(32));
$pageTitle = 'Pay QR';
require __DIR__ . '/includes/header.php';
?>
<section class="screen pay-screen">
    <header class="page-head"><a href="qr.php" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>Pay QR</h1><span></span></header>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
    <?php if ($payee): ?>
    <div class="pay-to">
        <span class="pay-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(qr_display_name($payee['full_name']), 0, 1))) ?></span>
        <div><small>Paying</small><strong><?= e(qr_display_name($payee['full_name'])) ?></strong><span><?= e($payee['qr_code']) ?><?= $payee['role'] === 'mctc' ? ' · Merchant' : '' ?></span></div>
    </div>
    <form method="post" class="pay-form" data-pay-form>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="request_key" value="<?= e($_SESSION['qr_pay_key']) ?>">
        <input type="hidden" name="to" value="<?= e($payee['qr_code']) ?>">
        <?php if ($fixedAmount): ?><input type="hidden" name="fixed" value="1"><?php endif; ?>
        <label for="pay-amount">Amount (Credits)</label>
        <div class="pay-amount"><input id="pay-amount" name="amount" type="number" inputmode="decimal" min="1" max="10000" step="0.01" required placeholder="0.00" value="<?= e($amountValue) ?>"<?= $fixedAmount ? ' readonly' : '' ?>><span>Credits</span></div>
        <p class="pay-balance">Available: <strong><?= format_credits((float) $user['credits']) ?> Credits</strong><?= $fixedAmount ? ' · Amount set by the QR' : '' ?></p>
        <label for="pay-note">Note <small>(optional)</small></label>
        <input id="pay-note" class="pay-note" name="note" type="text" maxlength="140" placeholder="e.g. Lunch, tour fee" value="<?= e($noteValue) ?>">
        <p class="pay-fine">Credits move instantly and cannot be reversed from the app. Check the name before you confirm. No fee.</p>
        <button class="btn dark" type="submit" data-pay-submit>Pay now</button>
    </form>
    <?php else: ?>
    <a class="btn dark" href="qr.php">Scan another QR</a>
    <?php endif; ?>
</section>
<script defer src="assets/js/qr.js?v=4"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
