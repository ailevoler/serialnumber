<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/fx.php';
$user = require_auth();
header('Cache-Control: no-store');
$uid = (int) $user['id'];
$error = '';
$ready = fx_ready($pdo);
$cfg = fx_config();
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$ccy = strtoupper(is_string($input['ccy'] ?? null) ? $input['ccy'] : FX_MAIN);
// Offered currencies, or one the customer still holds after Admin stopped offering it (sell only).
if (!in_array($ccy, $cfg['currencies'], true) && !($ready && isset(fx_currency_catalog()[$ccy]) && fx_wallet_balance($pdo, $uid, $ccy) > 0)) $ccy = FX_MAIN;
$sideIn = is_string($input['side'] ?? null) ? $input['side'] : '';
$side = in_array($sideIn, ['sell', 'sell_usd'], true) || !in_array($ccy, $cfg['currencies'], true) ? 'sell' : 'buy';
$amountValue = is_string($_POST['amount'] ?? null) ? $_POST['amount'] : '';

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
            $ref = fx_trade($pdo, $uid, $ccy, $side, fx_parse_amount($amountValue, $ccy), (int) ($_POST['rate_id'] ?? 0), $key);
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

$done = null;
if ($ready && is_string($_GET['ref'] ?? null)) {
    $s = $pdo->prepare('SELECT * FROM fx_trades WHERE reference = ? AND user_id = ?');
    $s->execute([$_GET['ref'], $uid]);
    if ($done = $s->fetch() ?: null) $ccy = in_array($done['currency'], $cfg['currencies'], true) ? $done['currency'] : $ccy;
}
$rate = $ready ? fx_current($pdo, $ccy) : null;
$board = $ready ? fx_board($pdo) : [];
$limits = null;
if ($rate) { try { $limits = fx_limits($pdo, $rate, $cfg); } catch (InvalidArgumentException) {} }
$cashStmt = $pdo->prepare('SELECT balance_centavos FROM boracay_cash_wallets WHERE user_id = ?');
$cashStmt->execute([$uid]);
$php = (int) ($cashStmt->fetchColumn() ?: 0);
$wallets = fx_wallets($pdo, $uid);
$held = $wallets[$ccy] ?? 0;
$history = fx_history($pdo, $uid, 15);
$sellOnly = $rate && $rate['sell_only'];
$open = $rate && ($rate['tradable'] || $sellOnly) && $limits;
$dec = fx_currency_decimals($ccy);
$unit = static fn(string $c): string => (fx_currency_flag($c) !== '' ? fx_currency_flag($c) . ' ' : '') . $c;
$pageTitle = 'Currency Exchange';
require __DIR__ . '/includes/header.php';
?>
<section class="screen with-nav bcash-page fx-screen">
    <header class="page-head"><a href="dashboard.php?wallet=cash" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>Currency Exchange</h1><span></span></header>
    <?php if (!$ready): ?>
        <p class="alert" role="alert">Currency exchange is not set up yet. Please check again later.</p>
    <?php else: ?>
    <?php if ($done): $dc = $done['currency']; ?>
        <div class="fx-done" role="status">
            <span data-icon="check-circle"></span>
            <div><strong><?= $done['side'] === 'buy' ? 'Bought' : 'Sold' ?> <?= e($dc) ?> <?= fx_amount((int) $done['amount_minor'], $dc) ?></strong>
            <p><?= $done['side'] === 'buy' ? 'Paid PHP ' . peso((int) $done['total_php_centavos']) . ' BCash' : 'Received PHP ' . peso((int) $done['total_php_centavos']) . ' BCash' ?> · fee PHP <?= peso((int) $done['fee_php_centavos']) ?> · <?= e($done['reference']) ?></p></div>
        </div>
    <?php endif; ?>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>

    <form class="fx-picker" method="get" data-fx-picker>
        <label for="fx-ccy">Currency</label>
        <div>
            <select id="fx-ccy" name="ccy">
                <optgroup label="Main pair"><option value="USD"<?= $ccy === 'USD' ? ' selected' : '' ?>><?= e($unit('USD')) ?> · US Dollar (USD/PHP)</option></optgroup>
                <?php $popular = array_values(array_intersect(FX_POPULAR, $cfg['currencies'])); $more = array_values(array_diff($cfg['currencies'], FX_POPULAR)); ?>
                <?php if (count($popular) > 1): ?><optgroup label="Popular"><?php foreach ($popular as $c): if ($c === FX_MAIN) continue; ?><option value="<?= e($c) ?>"<?= $ccy === $c ? ' selected' : '' ?>><?= e($unit($c)) ?> · <?= e(fx_currency_name($c)) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
                <?php if ($more): ?><optgroup label="More currencies"><?php foreach ($more as $c): ?><option value="<?= e($c) ?>"<?= $ccy === $c ? ' selected' : '' ?>><?= e($unit($c)) ?> · <?= e(fx_currency_name($c)) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
            </select>
            <button class="btn light" type="submit" data-fx-go>Go</button>
        </div>
        <nav class="fx-chips" aria-label="Quick currencies">
            <?php foreach (array_unique(array_merge([FX_MAIN], array_keys($wallets), array_slice($popular, 0, 6))) as $c): if (!in_array($c, $cfg['currencies'], true)) continue; ?>
                <a href="?ccy=<?= e($c) ?>"<?= $c === $ccy ? ' class="active" aria-current="true"' : '' ?>><?= e($unit($c)) ?></a>
            <?php endforeach; ?>
        </nav>
    </form>

    <div class="fx-rate">
        <?php if ($rate): ?>
            <small><?= $ccy === FX_MAIN ? 'Main pair · ' : '' ?>1 <?= e($ccy) ?> · <?= e(fx_currency_name($ccy)) ?></small>
            <strong>PHP <?= fx_format_rate($rate['scaled']) ?></strong>
            <div class="fx-rate-sides">
                <span><em>You buy <?= e($ccy) ?> at</em><b>PHP <?= fx_format_rate($rate['buy_scaled']) ?></b></span>
                <span><em>You sell <?= e($ccy) ?> at</em><b>PHP <?= fx_format_rate($rate['sell_scaled']) ?></b></span>
            </div>
            <p>Mid-market rate from <?= e(fx_source_label($rate['source'])) ?><?= $rate['rate_date'] ? ', published ' . e(date('M j, Y', strtotime((string) $rate['rate_date']))) : '' ?> · updated <?= $rate['age_minutes'] < 1 ? 'just now' : $rate['age_minutes'] . ' min ago' ?></p>
        <?php else: ?>
            <small>1 <?= e($ccy) ?></small><strong>PHP —</strong><p>Getting the latest <?= e($ccy) ?> rate…</p>
        <?php endif; ?>
    </div>

    <div class="fx-balances">
        <div><small>BCash (PHP)</small><strong>PHP <?= peso($php) ?></strong></div>
        <div><small><?= e($ccy) ?> wallet</small><strong><?= e($ccy) ?> <?= fx_amount($held, $ccy) ?></strong></div>
    </div>

    <?php if (!$open || $sellOnly): ?><p class="alert" role="alert"><?= e($rate['reason'] ?? 'Exchange for ' . $ccy . ' is not available right now. Please try again later.') ?></p><?php endif; ?>

    <form method="post" class="fx-form" data-fx-form
          data-scaled="<?= (int) ($rate['scaled'] ?? 0) ?>" data-dec="<?= $dec ?>" data-ccy="<?= e($ccy) ?>"
          data-buy-bp="<?= (int) ($rate['buy_fee_bp'] ?? 0) ?>" data-sell-bp="<?= (int) ($rate['sell_fee_bp'] ?? 0) ?>"
          data-php="<?= $php ?>" data-held="<?= $held ?>" data-min="<?= (int) ($limits[0] ?? 0) ?>" data-max="<?= (int) ($limits[1] ?? 0) ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="request_key" value="<?= e($_SESSION['fx_key']) ?>">
        <input type="hidden" name="rate_id" value="<?= (int) ($rate['id'] ?? 0) ?>">
        <input type="hidden" name="ccy" value="<?= e($ccy) ?>">
        <div class="cash-direction fx-side" role="radiogroup" aria-label="Buy or sell <?= e($ccy) ?>">
            <label class="<?= $side === 'buy' ? 'active' : '' ?>"><input type="radio" name="side" value="buy"<?= $side === 'buy' ? ' checked' : '' ?><?= $sellOnly ? ' disabled' : '' ?>> Buy <?= e($ccy) ?></label>
            <label class="<?= $side === 'sell' ? 'active' : '' ?>"><input type="radio" name="side" value="sell"<?= $side === 'sell' ? ' checked' : '' ?>> Sell <?= e($ccy) ?></label>
        </div>
        <label for="fx-amount"><?= e($ccy) ?> amount<?= $limits ? ' <small>(' . e(fx_amount($limits[0], $ccy)) . ' – ' . e(fx_amount($limits[1], $ccy)) . ')</small>' : '' ?></label>
        <div class="pay-amount"><input id="fx-amount" name="amount" type="text" inputmode="<?= $dec ? 'decimal' : 'numeric' ?>" autocomplete="off" required placeholder="<?= $dec ? '0.' . str_repeat('0', $dec) : '0' ?>" value="<?= e($amountValue) ?>"><span><?= e($ccy) ?></span></div>
        <div class="cash-breakdown fx-breakdown" aria-live="polite">
            <div><span><?= e($ccy) ?> at mid rate</span><strong data-fx="gross">PHP 0.00</strong></div>
            <div><span data-fx="fee-label">Fee (<?= fx_percent($side === 'buy' ? (int) ($rate['buy_fee_bp'] ?? 0) : (int) ($rate['sell_fee_bp'] ?? 0)) ?>)</span><strong data-fx="fee">PHP 0.00</strong></div>
            <div class="fx-total"><span data-fx="total-label"><?= $side === 'buy' ? 'You pay from BCash' : 'You get in BCash' ?></span><strong data-fx="total">PHP 0.00</strong></div>
        </div>
        <p class="pay-fine" data-fx="note"></p>
        <button class="btn dark" type="submit" data-pay-submit<?= $open ? '' : ' disabled' ?>><?= ($side === 'buy' ? 'Buy ' : 'Sell ') . e($ccy) ?></button>
        <p class="pay-fine">The amount is locked to the rate shown. If the rate changes before you confirm, nothing moves and you see the new amount. Your <?= e($ccy) ?> stays in your Ultimate App wallet; sell it back to BCash any time.</p>
    </form>

    <?php if ($wallets): ?>
    <section class="fx-wallets"><h2>My currencies</h2>
        <?php foreach ($wallets as $c => $minor): $r = $board[$c] ?? null; ?>
            <a href="?ccy=<?= e($c) ?>&amp;side=sell"><span class="fx-flag" aria-hidden="true"><?= fx_currency_flag($c) ?></span><span><strong><?= e($c) ?> <?= fx_amount($minor, $c) ?></strong><small><?= e(fx_currency_name($c)) ?></small></span><b><?= $r ? '≈ PHP ' . peso(fx_php_value($minor, $r['sell_scaled'], $c, false)) : '' ?></b></a>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <section class="fx-board" aria-label="All currencies">
        <h2>All rates <small>PHP per 1 unit · mid-market</small></h2>
        <?php foreach ($board as $c => $r): ?>
            <a href="?ccy=<?= e($c) ?>"<?= $c === $ccy ? ' class="active"' : '' ?>><span class="fx-flag" aria-hidden="true"><?= fx_currency_flag($c) ?></span><span><strong><?= e($c) ?><?= $c === FX_MAIN ? ' <em>Main</em>' : '' ?></strong><small><?= e(fx_currency_name($c)) ?></small></span><b>PHP <?= fx_format_rate($r['scaled']) ?></b></a>
        <?php endforeach; ?>
        <?php if (!$board): ?><p class="muted">Rates are loading. Please check again in a minute.</p><?php endif; ?>
    </section>

    <section class="cash-history"><h2>Recent exchanges</h2>
        <?php if (!$history): ?><p>No exchanges yet.</p><?php endif; ?>
        <?php foreach ($history as $t): $tc = $t['currency']; ?>
            <a><span><strong><?= $t['side'] === 'buy' ? 'Bought' : 'Sold' ?> <?= e($tc) ?> <?= fx_amount((int) $t['amount_minor'], $tc) ?></strong><small><?= e($t['reference']) ?> · @ <?= e(fx_format_rate(fx_rate_scaled((string) $t['rate']))) ?> · fee PHP <?= peso((int) $t['fee_php_centavos']) ?> · <?= e(date('M j, g:i A', strtotime((string) $t['created_at']))) ?></small></span><b><?= $t['side'] === 'buy' ? '-' : '+' ?>PHP <?= peso((int) $t['total_php_centavos']) ?></b></a>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
    <?php require __DIR__ . '/includes/nav.php'; ?>
</section>
<script defer src="assets/js/fx.js?v=2"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
