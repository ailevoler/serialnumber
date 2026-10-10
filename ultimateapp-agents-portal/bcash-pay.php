<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/bcash.php';
$user = require_auth();
header('Cache-Control: no-store');
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$code = qr_pay_normalize_code(is_string($input['to'] ?? null) ? $input['to'] : '');
$error = '';
$payee = null;
if (!bcash_transfers_ready($pdo)) {
    $error = 'BCash Send is not set up yet. Please try again later.';
} elseif ($code === null) {
    $error = 'This is not a valid BCash QR.';
} else {
    rate_limit_enforce('qr_lookup', 'user:' . $user['id'], 60, 600);
    $payee = qr_pay_find_user($pdo, $code);
    if (!$payee) $error = 'We could not find an Ultimate App account for this BCash QR.';
    elseif ((int) $payee['id'] === (int) $user['id']) { $error = 'This is your own BCash QR. Ask the person you are sending to for theirs.'; $payee = null; }
}

// Amount requested inside the QR (optional). Invalid values are ignored so the sender can type one.
$requestedAmount = '';
if (is_string($_GET['amount'] ?? null)) {
    try { $requestedAmount = number_format(boracay_cash_amount($_GET['amount']) / 100, 2, '.', ''); } catch (InvalidArgumentException) {}
}
$amountValue = is_string($_POST['amount'] ?? null) ? $_POST['amount'] : $requestedAmount;
$noteValue = is_string($_POST['note'] ?? null) ? $_POST['note'] : '';
$fixedAmount = is_string($input['fixed'] ?? null) && $input['fixed'] === '1' && $requestedAmount !== '';

if ($payee && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('bcash_send', 'user:' . $user['id'], 20, 3600);
    $key = is_string($_POST['request_key'] ?? null) ? $_POST['request_key'] : '';
    if (preg_match('/^[a-f0-9]{64}$/D', $key)) {
        $prior = $pdo->prepare('SELECT reference FROM bcash_transfers WHERE payer_id = ? AND request_key = ?');
        $prior->execute([$user['id'], $key]);
        if ($row = $prior->fetch()) redirect('bcash-pay-receipt.php?reference=' . rawurlencode($row['reference']));
    }
    if (!$key || !hash_equals($_SESSION['bcash_send_key'] ?? '', $key)) {
        $error = 'This transfer was already submitted. Check your transactions before trying again.';
    } else {
        try {
            if ($fixedAmount && $amountValue !== $requestedAmount) throw new InvalidArgumentException('The amount in this QR cannot be changed.');
            $centavos = boracay_cash_amount($amountValue);
            $reference = bcash_transfer($pdo, (int) $user['id'], (int) $payee['id'], $centavos, $key, qr_pay_note($noteValue));
            unset($_SESSION['bcash_send_key']);
            redirect('bcash-pay-receipt.php?reference=' . rawurlencode($reference));
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('BCash transfer failed: ' . get_class($exception) . ' ' . $exception->getMessage());
            $error = 'Could not send BCash. No BCash was moved. Please try again.';
        }
    }
}
if (empty($_SESSION['bcash_send_key'])) $_SESSION['bcash_send_key'] = bin2hex(random_bytes(32));
$balance = bcash_transfers_ready($pdo) ? bcash_balance($pdo, (int) $user['id']) : 0;
$pageTitle = 'Send BCash';
require __DIR__ . '/includes/header.php';
?>
<section class="screen pay-screen bcash-page">
    <header class="page-head"><a href="bcash-qr.php" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>Send BCash</h1><span></span></header>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
    <?php if ($payee): ?>
    <div class="pay-to">
        <span class="pay-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(qr_display_name($payee['full_name']), 0, 1))) ?></span>
        <div><small>Sending BCash to</small><strong><?= e(qr_display_name($payee['full_name'])) ?></strong><span><?= e($payee['qr_code']) ?></span></div>
    </div>
    <form method="post" class="pay-form" data-pay-form>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="request_key" value="<?= e($_SESSION['bcash_send_key']) ?>">
        <input type="hidden" name="to" value="<?= e($payee['qr_code']) ?>">
        <?php if ($fixedAmount): ?><input type="hidden" name="fixed" value="1"><?php endif; ?>
        <label for="pay-amount">Amount (PHP)</label>
        <div class="pay-amount"><input id="pay-amount" name="amount" type="number" inputmode="decimal" min="1" max="10000" step="0.01" required placeholder="0.00" value="<?= e($amountValue) ?>"<?= $fixedAmount ? ' readonly' : '' ?>><span>PHP</span></div>
        <p class="pay-balance">Available: <strong>PHP <?= peso($balance) ?> BCash</strong><?= $fixedAmount ? ' · Amount set by the QR' : '' ?></p>
        <label for="pay-note">Note <small>(optional)</small></label>
        <input id="pay-note" class="pay-note" name="note" type="text" maxlength="140" placeholder="e.g. Lunch, tour fee" value="<?= e($noteValue) ?>">
        <p class="pay-fine">BCash moves instantly and cannot be reversed from the app. Check the name before you confirm. No fee.</p>
        <button class="btn dark" type="submit" data-pay-submit>Send BCash</button>
    </form>
    <?php if ($balance < 100): ?><p class="pay-fine">No BCash yet? <a href="boracay-cash.php">Convert Credits to BCash</a> first.</p><?php endif; ?>
    <?php else: ?>
    <a class="btn dark" href="bcash-qr.php">Scan another BCash QR</a>
    <?php endif; ?>
</section>
<script defer src="assets/js/qr.js?v=4"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
