<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/fx.php';
$user = require_auth();
header('Cache-Control: no-store');
$uid = (int) $user['id'];
$error = '';
$side = ($_POST['side'] ?? $_GET['side'] ?? '') === 'sell_usd' ? 'sell_usd' : 'buy_usd';
$amountValue = is_string($_POST['usd'] ?? null) ? $_POST['usd'] : '';
$ready = fx_ready($pdo);
$cfg = fx_config();

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('fx_trade', 'user:' . $uid, 30, 3600);
    $key = is_string($_POST['request_key'] ?? null) ? $_POST['request_key'] : '';
    if (preg_match('/^[a-f0-9]{64}$/D', $key)) {
        $prior = $pdo->prepare('SELECT reference FROM fx_trades WHERE user_id = ? AND request_key = ?');
        $prior->execute([$uid, $key]);
        if ($row = $prior->fetch()) redirect('bcash-exchange.php?ref=' . rawurlencode($row['reference']));
    }
    if (!$key || !hash_equals($_SESSION['fx_key'] ?? '', $key)) {
        $error = 'This exchange was already submitted. Check your history below before trying again.';
    } else {
        try {
            $ref = fx_trade($pdo, $uid, $side, fx_usd_amount($amountValue, $cfg), (int) ($_POST['rate_id'] ?? 0), $key);
            unset($_SESSION['fx_key']);
            redirect('bcash-exchange.php?ref=' . rawurlencode($ref));
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('FX trade failed: ' . get_class($e) . ' ' . $e->getMessage());
            $error = 'Could not complete the exchange. Nothing was moved. Please try again.';
        }
    }
}
if (empty($_SESSION['fx_key'])) $_SESSION['fx_key'] = bin2hex(random_bytes(32));

$rate = $ready ? fx_current($pdo) : null;
$cashStmt = $pdo->prepare('SELECT balance_centavos FROM boracay_cash_wallets WHERE user_id = ?');
$cashStmt->execute([$uid]);
$php = (int) ($cashStmt->fetchColumn() ?: 0);
$usd = fx_usd_balance($pdo, $uid);
$done = null;
if ($ready && is_string($_GET['ref'] ?? null)) {
    $s = $pdo->prepare('SELECT * FROM fx_trades WHERE reference = ? AND user_id = ?');
    $s->execute([$_GET['ref'], $uid]);
    $done = $s->fetch() ?: null;
}
$history = fx_history($pdo, $uid, 15);
$open = $rate && $rate['tradable'];
$pageTitle = 'USD ⇄ PHP';
require __DIR__ . '/includes/header.php';
?>
<section class="screen with-nav bcash-page fx-screen">
    <header class="page-head"><a href="dashboard.php?wallet=cash" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>USD ⇄ PHP</h1><span></span></header>
    <?php if (!$ready): ?>
        <p class="alert" role="alert">The USD exchange is not set up yet. Please check again later.</p>
    <?php else: ?>
    <?php if ($done): ?>
        <div class="fx-done" role="status">
            <span data-icon="check-circle"></span>
            <div><strong><?= $done['side'] === 'buy_usd' ? 'Bought USD ' . fx_usd((int) $done['usd_cents']) : 'Sold USD ' . fx_usd((int) $done['usd_cents']) ?></strong>
            <p><?= $done['side'] === 'buy_usd' ? 'Paid PHP ' . peso((int) $done['total_php_centavos']) . ' BCash' : 'Received PHP ' . peso((int) $done['total_php_centavos']) . ' BCash' ?> · fee PHP <?= peso((int) $done['fee_php_centavos']) ?> · <?= e($done['reference']) ?></p></div>
        </div>
    <?php endif; ?>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>

    <div class="fx-rate">
        <?php if ($rate): ?>
            <small>1 USD</small>
            <strong>PHP <?= fx_format_rate($rate['micro']) ?></strong>
            <div class="fx-rate-sides">
                <span><em>You buy USD at</em><b>PHP <?= fx_format_rate($rate['buy_micro']) ?></b></span>
                <span><em>You sell USD at</em><b>PHP <?= fx_format_rate($rate['sell_micro']) ?></b></span>
            </div>
            <p>Mid-market rate from <?= e(fx_source_label($rate['source'])) ?><?= $rate['rate_date'] ? ', published ' . e(date('M j, Y', strtotime((string) $rate['rate_date']))) : '' ?> · updated <?= $rate['age_minutes'] < 1 ? 'just now' : $rate['age_minutes'] . ' min ago' ?></p>
        <?php else: ?>
            <small>1 USD</small><strong>PHP —</strong><p>Getting the latest USD rate…</p>
        <?php endif; ?>
    </div>

    <div class="fx-balances">
        <div><small>BCash (PHP)</small><strong>PHP <?= peso($php) ?></strong></div>
        <div><small>USD wallet</small><strong>USD <?= fx_usd($usd) ?></strong></div>
    </div>

    <?php if (!$open): ?><p class="alert" role="alert"><?= e($rate['reason'] ?? 'The USD exchange is not available right now. Please try again later.') ?></p><?php endif; ?>

    <form method="post" class="fx-form" data-fx-form
          data-micro="<?= (int) ($rate['micro'] ?? 0) ?>" data-buy-bp="<?= (int) $cfg['buy_fee_bp'] ?>" data-sell-bp="<?= (int) $cfg['sell_fee_bp'] ?>"
          data-php="<?= $php ?>" data-usd="<?= $usd ?>" data-min="<?= (int) $cfg['min_usd_cents'] ?>" data-max="<?= (int) $cfg['max_usd_cents'] ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="request_key" value="<?= e($_SESSION['fx_key']) ?>">
        <input type="hidden" name="rate_id" value="<?= (int) ($rate['id'] ?? 0) ?>">
        <div class="cash-direction fx-side" role="radiogroup" aria-label="Buy or sell USD">
            <label class="<?= $side === 'buy_usd' ? 'active' : '' ?>"><input type="radio" name="side" value="buy_usd"<?= $side === 'buy_usd' ? ' checked' : '' ?>> Buy USD</label>
            <label class="<?= $side === 'sell_usd' ? 'active' : '' ?>"><input type="radio" name="side" value="sell_usd"<?= $side === 'sell_usd' ? ' checked' : '' ?>> Sell USD</label>
        </div>
        <label for="fx-usd">USD amount</label>
        <div class="pay-amount"><input id="fx-usd" name="usd" type="text" inputmode="decimal" autocomplete="off" required placeholder="0.00" value="<?= e($amountValue) ?>"><span>USD</span></div>
        <div class="cash-breakdown fx-breakdown" aria-live="polite">
            <div><span>USD at mid rate</span><strong data-fx="gross">PHP 0.00</strong></div>
            <div><span data-fx="fee-label">Fee (<?= fx_percent($side === 'buy_usd' ? $cfg['buy_fee_bp'] : $cfg['sell_fee_bp']) ?>)</span><strong data-fx="fee">PHP 0.00</strong></div>
            <div class="fx-total"><span data-fx="total-label"><?= $side === 'buy_usd' ? 'You pay from BCash' : 'You get in BCash' ?></span><strong data-fx="total">PHP 0.00</strong></div>
        </div>
        <p class="pay-fine" data-fx="note"></p>
        <button class="btn dark" type="submit" data-pay-submit<?= $open ? '' : ' disabled' ?>><?= $side === 'buy_usd' ? 'Buy USD' : 'Sell USD' ?></button>
        <p class="pay-fine">The amount is locked to the rate shown. If the rate changes before you confirm, nothing moves and you see the new amount. USD stays in your Ultimate App USD wallet; sell it back to BCash any time.</p>
    </form>

    <section class="cash-history"><h2>Recent exchanges</h2>
        <?php if (!$history): ?><p>No exchanges yet.</p><?php endif; ?>
        <?php foreach ($history as $t): ?>
            <a><span><strong><?= $t['side'] === 'buy_usd' ? 'Bought' : 'Sold' ?> USD <?= fx_usd((int) $t['usd_cents']) ?></strong><small><?= e($t['reference']) ?> · @ <?= e(number_format((float) $t['rate'], 4)) ?> · fee PHP <?= peso((int) $t['fee_php_centavos']) ?> · <?= e(date('M j, g:i A', strtotime((string) $t['created_at']))) ?></small></span><b><?= $t['side'] === 'buy_usd' ? '-' : '+' ?>PHP <?= peso((int) $t['total_php_centavos']) ?></b></a>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
    <?php require __DIR__ . '/includes/nav.php'; ?>
</section>
<script defer src="assets/js/fx.js?v=1"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
