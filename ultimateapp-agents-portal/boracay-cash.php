<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/boracay_cash.php';
$user = require_auth();
header('Cache-Control: no-store');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('cash_convert', 'user:' . $user['id'], 30, 3600);
    $key = is_string($_POST['request_key'] ?? null) ? $_POST['request_key'] : '';
    if (preg_match('/^[a-f0-9]{64}$/D', $key)) {
        $existing = $pdo->prepare('SELECT reference FROM boracay_cash_conversions WHERE request_key = ? AND user_id = ?');
        $existing->execute([$key, $user['id']]);
        $prior = $existing->fetch();
        if ($prior) redirect('boracay-cash-receipt.php?reference=' . rawurlencode($prior['reference']));
    }
    if (!$key || !hash_equals($_SESSION['boracay_cash_key'] ?? '', $key)) {
        $error = 'This request was already submitted. Check your conversion history.';
    } else {
        try {
            $input = is_string($_POST['amount'] ?? null) ? $_POST['amount'] : '';
            $direction = is_string($_POST['direction'] ?? null) ? $_POST['direction'] : '';
            $centavos = boracay_cash_amount($input);
            $reference = boracay_cash_convert($pdo, (int) $user['id'], $centavos, $key, $direction);
            unset($_SESSION['boracay_cash_key']);
            redirect('boracay-cash-receipt.php?reference=' . rawurlencode($reference));
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('BCash conversion failed: ' . get_class($exception));
            $error = 'Could not complete the conversion. No balance was changed. Please try again.';
        }
    }
}
if (empty($_SESSION['boracay_cash_key'])) $_SESSION['boracay_cash_key'] = bin2hex(random_bytes(32));
$walletStmt = $pdo->prepare('SELECT balance_centavos FROM boracay_cash_wallets WHERE user_id = ?');
$walletStmt->execute([$user['id']]);
$balanceCentavos = (int) ($walletStmt->fetch()['balance_centavos'] ?? 0);
$historyStmt = $pdo->prepare('SELECT reference, direction, credits, cash_centavos, created_at FROM boracay_cash_conversions WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 10');
$historyStmt->execute([$user['id']]);
$history = $historyStmt->fetchAll();
$pageTitle = 'BCash';
require __DIR__ . '/includes/header.php';
?>
<section class="screen cash-screen">
    <header class="page-head"><a href="dashboard.php" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>BCash</h1><span></span></header>
    <div class="cash-summary"><small>Available BCash</small><strong>PHP <?= number_format($balanceCentavos / 100, 2) ?></strong><p>PHP balance · USD is display-only on the wallet card</p></div>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
    <div class="cash-heading"><h2>Convert between wallets</h2><p>1 Credit = PHP1.00 BCash in either direction. No conversion fee.</p></div>
    <form method="post" class="cash-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="request_key" value="<?= e($_SESSION['boracay_cash_key']) ?>">
        <input type="hidden" name="direction" id="cash-direction" value="credits_to_cash">
        <div class="cash-direction" role="group" aria-label="Conversion direction"><button type="button" class="active" data-convert-direction="credits_to_cash" aria-pressed="true">Credits to BCash</button><button type="button" data-convert-direction="cash_to_credits" aria-pressed="false">BCash to Credits</button></div>
        <label for="cash-credits" id="cash-amount-label">Credits to convert</label><input id="cash-credits" name="amount" type="number" inputmode="decimal" min="1" max="10000" step="0.01" required placeholder="0.00">
        <div class="cash-breakdown"><div><span>Available Credits</span><strong><?= format_credits((float) $user['credits']) ?></strong></div><div><span>Available BCash</span><strong>PHP <?= number_format($balanceCentavos / 100, 2) ?></strong></div><div><span id="cash-debit-label">Credits deducted</span><strong id="cash-debit">0.00 Credits</strong></div><div><span id="cash-receive-label">BCash added</span><strong id="cash-receive">PHP 0.00</strong></div></div>
        <p class="cash-note">This transfer stays inside Ultimate App. It does not send PHP or USD to a bank, e-wallet, or merchant.</p>
        <button class="btn dark" type="submit">Confirm Conversion</button>
    </form>
    <section class="cash-history" id="history"><h2>Recent conversions</h2><?php if (!$history): ?><p>No conversions yet.</p><?php endif; ?><?php foreach ($history as $item): ?><a href="boracay-cash-receipt.php?reference=<?= e($item['reference']) ?>"><span><strong><?= $item['direction'] === 'cash_to_credits' ? 'BCash to Credits' : 'Credits to BCash' ?></strong><small><?= e($item['reference']) ?> / <?= e($item['created_at']) ?></small></span><b><?= $item['direction'] === 'cash_to_credits' ? '+' . number_format((float) $item['credits'], 2) . ' Credits' : '+PHP ' . number_format($item['cash_centavos'] / 100, 2) ?></b></a><?php endforeach; ?></section>
</section>
<script defer src="assets/js/boracay-cash.js?v=2"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
