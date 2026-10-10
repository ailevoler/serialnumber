<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/conversion.php';
$user = require_auth();
header('Cache-Control: no-store');
$config = paymongo_settings();
$fee = conversion_fee_credits();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('conversion_create', 'user:' . $user['id'], 12, 3600);
    $token = is_string($_POST['conversion_token'] ?? null) ? $_POST['conversion_token'] : '';
    $credits = filter_var($_POST['credits'] ?? null, FILTER_VALIDATE_INT);
    if (($_POST['method'] ?? '') !== 'mctc') {
        $error = 'This payout method is not active yet. Choose MCTC.';
    } elseif (!$token || !hash_equals($_SESSION['conversion_token'] ?? '', $token)) {
        $error = 'This conversion was already submitted. Check your request history.';
    } elseif ($credits === false || $credits < 1 || $credits > 10000) {
        $error = 'Enter between 1 and 10,000 Credits.';
    } elseif ((float) $user['credits'] < $credits + $fee) {
        $error = 'Your balance must cover the conversion and PHP ' . number_format($fee, 2) . ' fee.';
    } else {
        try {
            $active = $pdo->prepare("SELECT id FROM credit_conversions WHERE user_id = ? AND method = 'mctc' AND status = 'pending' AND expires_at > CURRENT_TIMESTAMP LIMIT 1");
            $active->execute([$user['id']]);
            if ($active->fetch()) {
                throw new InvalidArgumentException('You already have an active conversion request. Complete or cancel it first.');
            }
            $reference = 'UC-' . bin2hex(random_bytes(16));
            $qrToken = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare("INSERT INTO credit_conversions (reference, user_id, method, mode, credits, fee_credits, total_debit_credits, cash_amount_centavos, qr_token, expires_at) VALUES (?, ?, 'mctc', ?, ?, ?, ?, ?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 20 MINUTE))");
            $stmt->execute([$reference, $user['id'], $config['mode'], $credits, $fee, $credits + $fee, $credits * 100, $qrToken]);
            unset($_SESSION['conversion_token']);
            redirect('convert-status.php?reference=' . rawurlencode($reference));
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Conversion request failed: ' . get_class($exception));
            $error = 'Could not create the request. Please try again.';
        }
    }
}
if (empty($_SESSION['conversion_token'])) $_SESSION['conversion_token'] = bin2hex(random_bytes(32));
$history = $pdo->prepare('SELECT reference, credits, status, created_at FROM credit_conversions WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 5');
$history->execute([$user['id']]);
$pageTitle = 'Convert Credits';
require __DIR__ . '/includes/header.php';
?>
<section class="screen convert-screen">
    <header class="page-head"><a href="dashboard.php" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>Convert Credits</h1><span></span></header>
    <div class="convert-balance"><small>Available Credits</small><strong><?= format_credits((float) $user['credits']) ?></strong></div>
    <?php if ($config['mode'] === 'test'): ?><p class="alert">Test mode: do not give or collect real cash.</p><?php endif; ?>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" class="convert-form" id="convert-form" data-fee="<?= $fee ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="conversion_token" value="<?= e($_SESSION['conversion_token']) ?>">
        <label for="convert-credits">Credits to convert</label><div class="convert-amount"><input id="convert-credits" name="credits" type="number" inputmode="numeric" min="1" max="10000" step="1" placeholder="0" required><span>Credits</span></div>
        <div class="convert-breakdown"><div><span>Cash to receive</span><strong id="convert-cash">PHP 0.00</strong></div><div><span>Conversion fee</span><strong>PHP <?= number_format($fee, 2) ?></strong></div><div class="total"><span>Total Credits to deduct</span><strong id="convert-total"><?= number_format($fee, 2) ?> Credits</strong></div></div>
        <h2>Receive your cash</h2>
        <label class="convert-method"><input type="radio" name="method" value="mctc" checked><span data-icon="qr-code"></span><span><strong>MCTC Merchant</strong><small>Show your QR to an MCTC merchant for cash</small></span></label>
        <a class="convert-method convert-link" href="boracay-cash.php"><span data-icon="wallet"></span><span><strong>BCash</strong><small>Convert between BCash and Credits</small></span><span data-icon="chevron-right"></span></a>
        <a class="convert-method convert-link fx-link" href="bcash-exchange.php"><span data-icon="banknote"></span><span><strong>USD ⇄ PHP</strong><small>Buy or sell USD with BCash at the live rate</small></span><span data-icon="chevron-right"></span></a>
        <label class="convert-method unavailable"><input type="radio" name="method" value="qrph" disabled><span data-icon="qr-code"></span><span><strong>QR Ph payout</strong><small>To your own bank or e-wallet QR · Coming soon</small></span></label>
        <label class="convert-method unavailable"><input type="radio" name="method" value="bank" disabled><span data-icon="credit-card"></span><span><strong>Bank / e-wallet account</strong><small>InstaPay transfer to your account · Coming soon</small></span></label>
        <p class="convert-hint">No Credits are deducted until the merchant approves the transfer. Collect cash only after the successful receipt appears.</p>
        <button class="btn dark" type="submit">Generate MCTC QR</button>
    </form>
    <section class="convert-history"><h2>Recent conversions</h2><?php foreach ($history->fetchAll() as $item): ?><a href="convert-status.php?reference=<?= e($item['reference']) ?>"><span><strong><?= e($item['reference']) ?></strong><small><?= e($item['created_at']) ?> · <?= e(ucfirst($item['status'])) ?></small></span><b><?= number_format($item['credits']) ?> Credits</b></a><?php endforeach; ?></section>
</section>
<script defer src="assets/js/convert.js?v=1"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
