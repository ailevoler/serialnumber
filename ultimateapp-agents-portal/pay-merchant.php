<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/merchants.php';
require_once __DIR__ . '/includes/p2m.php';
$user = require_auth();
header('Cache-Control: no-store');
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$code = merchant_normalize_code(is_string($input['m'] ?? null) ? $input['m'] : '');
$merchant = null; $error = '';
if ($code === null) {
    $error = 'This is not a valid Ultimate App merchant QR.';
} else {
    rate_limit_enforce('qr_lookup', 'user:' . $user['id'], 60, 600);
    $merchant = merchant_find_by_code($pdo, $code);
    if (!$merchant) $error = 'We could not find this merchant.';
    elseif ($merchant['status'] !== 'approved') { $error = $merchant['business_name'] . ' cannot accept Ultimate App payments right now.'; $merchant = null; }
}
$requested = '';
if (is_string($_GET['amount'] ?? null) && $_GET['amount'] !== '') {
    try { $c = p2m_amount($_GET['amount']); if ($c > 0) $requested = centavos_to_decimal($c); } catch (InvalidArgumentException) {}
}
$fixed = $requested !== '' || (($_POST['fixed'] ?? '') === '1' && is_string($_POST['requested'] ?? null));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['fixed'] ?? '') === '1') $requested = is_string($_POST['requested'] ?? null) ? $_POST['requested'] : '';
$amountValue = is_string($_POST['amount'] ?? null) ? $_POST['amount'] : $requested;
$source = ($_POST['source'] ?? 'credits') === 'boracay_cash' ? 'boracay_cash' : 'credits';
$note = is_string($_POST['note'] ?? null) ? $_POST['note'] : '';

if ($merchant && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('merchant_pay', 'user:' . $user['id'], 30, 3600);
    $key = is_string($_POST['request_key'] ?? null) ? $_POST['request_key'] : '';
    if (preg_match('/^[a-f0-9]{64}$/D', $key)) {
        $prior = $pdo->prepare('SELECT reference FROM merchant_payments WHERE user_id = ? AND request_key = ?');
        $prior->execute([$user['id'], $key]);
        if ($row = $prior->fetch()) redirect('pay-merchant-receipt.php?reference=' . rawurlencode($row['reference']));
    }
    if (!$key || !hash_equals($_SESSION['p2m_key'] ?? '', $key)) {
        $error = 'This payment was already submitted. Check your transactions before trying again.';
    } else {
        try {
            if ($fixed && $amountValue !== $requested) throw new InvalidArgumentException('The amount in this QR cannot be changed.');
            $cleanNote = trim(preg_replace('/\s+/u', ' ', $note) ?? '');
            if (mb_strlen($cleanNote) > 140) throw new InvalidArgumentException('Keep the note under 140 characters.');
            $reference = p2m_pay($pdo, (int) $user['id'], (int) $merchant['id'], $source, p2m_amount($amountValue), $key, $cleanNote === '' ? null : $cleanNote);
            unset($_SESSION['p2m_key']);
            redirect('pay-merchant-receipt.php?reference=' . rawurlencode($reference));
        } catch (InvalidArgumentException $ex) {
            $error = $ex->getMessage();
        } catch (Throwable $ex) {
            error_log('P2M payment failed: ' . get_class($ex) . ' ' . $ex->getMessage());
            $error = 'Could not complete the payment. Nothing was deducted. Please try again.';
        }
    }
    $user = current_user();
}
if (empty($_SESSION['p2m_key'])) $_SESSION['p2m_key'] = bin2hex(random_bytes(32));
$cashStmt = $pdo->prepare('SELECT balance_centavos FROM boracay_cash_wallets WHERE user_id = ?');
$cashStmt->execute([$user['id']]);
$cash = (int) ($cashStmt->fetchColumn() ?: 0);
$credits = (int) round((float) $user['credits'] * 100);
[$min, $max] = p2m_limits();
$rules = ['credits' => p2m_fee_rule('credits'), 'boracay_cash' => p2m_fee_rule('boracay_cash')];
if (!$rules[$source]['enabled']) $source = $rules['credits']['enabled'] ? 'credits' : 'boracay_cash';
$pageTitle = 'Pay merchant';
require __DIR__ . '/includes/header.php';
?>
<section class="screen pay-screen">
    <header class="page-head"><a href="qr.php" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>Pay merchant</h1><span></span></header>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
    <?php if ($merchant): ?>
    <div class="pay-to">
        <span class="pay-avatar merchant" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($merchant['business_name'], 0, 1))) ?></span>
        <div><small>Paying merchant</small><strong><?= e($merchant['business_name']) ?></strong><span><?= e($merchant['category']) ?> · <?= e($merchant['barangay']) ?> <span class="verified">✓ Verified</span></span><?php if ($merchant['latitude'] !== null): ?><a class="mm-dir" href="https://www.google.com/maps/dir/?api=1&amp;destination=<?= e($merchant['latitude'] . ',' . $merchant['longitude']) ?>" target="_blank" rel="noopener">Directions</a><?php endif; ?></div>
    </div>
    <form method="post" class="pay-form" data-pay-form data-p2m data-min="<?= $min ?>" data-max="<?= $max ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="request_key" value="<?= e($_SESSION['p2m_key']) ?>">
        <input type="hidden" name="m" value="<?= e($merchant['code']) ?>">
        <?php if ($fixed): ?><input type="hidden" name="fixed" value="1"><input type="hidden" name="requested" value="<?= e($requested) ?>"><?php endif; ?>
        <label for="pay-amount">Amount (PHP)</label>
        <div class="pay-amount"><input id="pay-amount" name="amount" type="text" inputmode="decimal" required placeholder="0.00" value="<?= e($amountValue) ?>"<?= $fixed ? ' readonly' : '' ?> autocomplete="off"><span>PHP</span></div>
        <?php if ($fixed): ?><p class="pay-balance">Amount set by the merchant's QR.</p><?php endif; ?>
        <p class="p2m-label">Pay with</p>
        <div class="p2m-sources" role="radiogroup">
        <?php foreach (['credits' => ['Credits', $credits, '1 Credit = PHP 1'], 'boracay_cash' => ['BCash', $cash, 'PHP wallet']] as $key => [$label, $bal, $hint]): $r = $rules[$key]; ?>
            <label class="p2m-source<?= $r['enabled'] ? '' : ' disabled' ?>" data-source="<?= $key ?>" data-balance="<?= $bal ?>" data-bp="<?= (int) $r['percent_bp'] ?>" data-fixed="<?= (int) $r['fixed_centavos'] ?>" data-bearer="<?= e($r['bearer']) ?>">
                <input type="radio" name="source" value="<?= $key ?>"<?= $source === $key ? ' checked' : '' ?><?= $r['enabled'] ? '' : ' disabled' ?>>
                <span><strong><?= e($label) ?></strong><small><?= $r['enabled'] ? 'Balance ' . peso($bal) . ' · ' . e($hint) : 'Not available right now' ?></small></span>
                <em><?= $r['enabled'] ? ($r['percent_bp'] || $r['fixed_centavos'] ? e(rtrim(rtrim(number_format($r['percent_bp'] / 100, 2), '0'), '.')) . '%' . ($r['fixed_centavos'] ? ' + ' . peso($r['fixed_centavos']) : '') . ($r['bearer'] === 'merchant' ? ' (merchant pays)' : ' charge') : 'No charge') : '' ?></em>
            </label>
        <?php endforeach; ?>
        </div>
        <div class="p2m-summary" aria-live="polite">
            <div><span>Amount</span><b data-sum-amount>0.00</b></div>
            <div><span>Charge</span><b data-sum-fee>0.00</b></div>
            <div class="total"><span>Total deducted</span><b data-sum-total>0.00</b></div>
            <p data-sum-note></p>
        </div>
        <label for="pay-note">Note <small>(optional)</small></label>
        <input id="pay-note" class="pay-note" name="note" type="text" maxlength="140" placeholder="e.g. Table 4, room 12" value="<?= e($note) ?>">
        <p class="pay-fine">Payments go straight to the merchant and cannot be reversed from the app. For a refund, ask the merchant or contact support.</p>
        <button class="btn dark" type="submit" data-pay-submit>Pay now</button>
    </form>
    <?php else: ?>
    <a class="btn dark" href="qr.php">Scan another QR</a>
    <?php endif; ?>
</section>
<script defer src="assets/js/qr.js?v=2"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
