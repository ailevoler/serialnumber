<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/paymongo.php';
$driver = driver_require();
$id = (int) $driver['id'];
$s = uride_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('driver_wallet', 'driver:' . $id, 30, 600);
    $action = dp('action');
    try {
        if ($action === 'topup') {
            $amount = (int) round((float) preg_replace('/[^\d.]/', '', dp('amount')) * 100);
            if ($driver['status'] === 'rejected') throw new InvalidArgumentException('Top-ups are not available for this account.');
            $url = uride_topup_start($pdo, $driver, $amount, pm_config(), dp('method') ?: 'qrph');
            redirect($url);
        } elseif ($action === 'cancel_topup') {
            uride_topup_cancel($pdo, $id, dp('reference'));
            dflash('success', 'Top-up request cancelled.');
        } elseif ($action === 'payout') {
            $amount = (int) round((float) preg_replace('/[^\d.]/', '', dp('amount')) * 100);
            $ref = uride_payout_request($pdo, $id, $amount);
            dflash('success', 'Payout ' . $ref . ' requested. Ultimate App Finance will transfer it within 1–2 working days.');
        } elseif ($action === 'payout_account') {
            $method = dp('payout_method');
            $name = mb_substr(trim(dp('payout_account_name')), 0, 120);
            $no = preg_replace('/[^0-9A-Za-z-]/', '', dp('payout_account_no'));
            if (!in_array($method, uride_payout_methods(), true)) throw new InvalidArgumentException('Choose your bank or e-wallet.');
            if (mb_strlen($name) < 4) throw new InvalidArgumentException('Enter the account name.');
            if (strlen((string) $no) < 8 || strlen((string) $no) > 30) throw new InvalidArgumentException('Enter a valid account / mobile number.');
            if (!password_verify(dp('password'), $driver['password_hash'])) throw new InvalidArgumentException('Your password is incorrect.');
            $pdo->prepare('UPDATE uride_drivers SET payout_method = ?, payout_account_name = ?, payout_account_no = ? WHERE id = ?')->execute([$method, $name, $no, $id]);
            dflash('success', 'Payout account saved.');
        }
    } catch (InvalidArgumentException $ex) {
        dflash('error', $ex->getMessage());
    } catch (RuntimeException $ex) {
        error_log('Driver wallet action failed: ' . get_class($ex) . ' ' . $ex->getMessage());
        dflash('error', 'This top-up method is not available right now. Please try again in a few minutes.');
    }
    redirect('wallet.php');
}

// Returning from the hosted checkout page: check the payment right away.
if (preg_match('/^DT-[a-f0-9]{32}$/D', dq('topup'))) {
    $stmt = $pdo->prepare('SELECT * FROM uride_driver_topups WHERE reference = ? AND driver_id = ?');
    $stmt->execute([dq('topup'), $id]);
    if ($order = $stmt->fetch()) {
        try { if (uride_topup_reconcile($pdo, $order) === 'paid') dflash('success', 'Top-up received. Your wallet has been credited.'); } catch (Throwable $e) { error_log('Driver top-up reconcile: ' . $e->getMessage()); }
        redirect('wallet.php');
    }
}

$driver = driver_current(true);
$stmt = $pdo->prepare('SELECT * FROM uride_wallet_tx WHERE driver_id = ? ORDER BY id DESC LIMIT 60');
$stmt->execute([$id]);
$txs = $stmt->fetchAll();
$stmt = $pdo->prepare('SELECT * FROM uride_payouts WHERE driver_id = ? ORDER BY id DESC LIMIT 10');
$stmt->execute([$id]);
$payouts = $stmt->fetchAll();
$stmt = $pdo->prepare("SELECT * FROM uride_driver_topups WHERE driver_id = ? AND method <> 'qrph' AND status = 'pending' AND expires_at > NOW() ORDER BY id DESC");
$stmt->execute([$id]);
$openRequests = $stmt->fetchAll();
$pending = array_values(array_filter($payouts, static fn($p) => $p['status'] === 'requested'));
$types = uride_wallet_types();
driver_header('Wallet', 'wallet');
?>
<section class="d-balance">
    <small>Wallet balance</small><b>₱<?= peso((int) $driver['wallet_centavos']) ?></b>
    <span>Commission is deducted from here after every trip. Keep at least ₱<?= peso($s['min_wallet']) ?> to stay online.</span>
</section>

<?php $methods = uride_topup_methods(); $enabled = $s['topup_methods']; $sel = in_array(dq('method'), $enabled, true) ? dq('method') : ($enabled[0] ?? ''); ?>
<form class="d-card" method="post" data-topup-form>
    <h2>Top up wallet</h2>
    <?php if (!$enabled): ?><p class="d-empty">Top-ups are not available right now. Contact Ultimate App support.</p><?php else: ?>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="topup">
    <div class="d-methods" role="radiogroup" aria-label="Top-up method">
        <?php foreach ($enabled as $m): [$label, $sub] = $methods[$m]; $fee = uride_topup_fee($m); ?>
            <label class="d-method"><input type="radio" name="method" value="<?= e($m) ?>"<?= $m === $sel ? ' checked' : '' ?>><span class="d-method-ic <?= e($m) ?>"><?= $m === 'qrph' ? 'QR' : ($m === 'mctc' ? 'MC' : 'BC') ?></span><span><b><?= e($label) ?></b><small><?= e($sub) ?><?= $fee > 0 ? ' · ₱' . peso($fee) . ' fee' : ' · no fee' ?></small></span></label>
        <?php endforeach; ?>
    </div>
    <div class="d-chips"><?php foreach ([100, 200, 500, 1000] as $a): if ($a * 100 < $s['topup_min'] || $a * 100 > $s['topup_max']) continue; ?><button type="button" class="chip" data-amount="<?= $a ?>">₱<?= number_format($a) ?></button><?php endforeach; ?></div>
    <div class="field"><label for="amount">Amount (PHP)</label><input id="amount" name="amount" type="number" inputmode="numeric" min="<?= intdiv($s['topup_min'], 100) ?>" max="<?= intdiv($s['topup_max'], 100) ?>" step="1" required placeholder="<?= intdiv($s['topup_min'], 100) ?>"></div>
    <p class="table-meta" data-method-help></p>
    <button class="btn grad" type="submit" data-method-submit>Continue</button>
    <?php endif; ?>
</form>
<?php if ($openRequests): ?>
<section class="d-card"><h2>Open top-up requests</h2>
    <div class="d-mini-list"><?php foreach ($openRequests as $o): ?>
        <div><span><?= e($methods[$o['method']][0]) ?> · ₱<?= peso((int) $o['amount_centavos'] + (int) $o['fee_centavos']) ?><small>Valid until <?= e(date('M j, g:i A', strtotime((string) $o['expires_at']))) ?></small></span><a class="btn small" href="topup-request.php?reference=<?= e($o['reference']) ?>">Show QR</a></div>
    <?php endforeach; ?></div>
</section>
<?php endif; ?>

<section class="d-card">
    <h2>Withdraw</h2>
    <?php if ($pending): ?>
        <div class="alert info">Payout <?= e($pending[0]['reference']) ?> for ₱<?= peso((int) $pending[0]['amount_centavos']) ?> is being processed.</div>
    <?php elseif ($driver['payout_account_no'] && in_array($driver['status'], ['approved', 'suspended'], true)): ?>
        <form method="post" class="d-inline-form" data-confirm="Request this payout to your <?= e($driver['payout_method']) ?> account?">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="payout">
            <div class="field"><label for="payout_amount">Amount (min ₱<?= peso($s['payout_min']) ?>)</label><input id="payout_amount" name="amount" type="number" min="<?= intdiv($s['payout_min'], 100) ?>" max="<?= intdiv((int) $driver['wallet_centavos'], 100) ?>" step="1" required></div>
            <button class="btn" type="submit">Request payout</button>
        </form>
        <p class="table-meta">To <?= e($driver['payout_method']) ?> · <?= e($driver['payout_account_name']) ?> · ••••<?= e(substr((string) $driver['payout_account_no'], -4)) ?></p>
    <?php else: ?>
        <p class="table-meta">Add your payout account below. Payouts are available to approved drivers.</p>
    <?php endif; ?>
    <details class="mt"<?= $driver['payout_account_no'] ? '' : ' open' ?>><summary>Payout account</summary>
        <form method="post" class="mt"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="payout_account">
            <div class="form-grid">
                <div class="field"><label for="payout_method">Bank / e-wallet</label><select id="payout_method" name="payout_method" required><option value="">Choose…</option><?php foreach (uride_payout_methods() as $m): ?><option<?= $driver['payout_method'] === $m ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label for="payout_account_name">Account name</label><input type="text" id="payout_account_name" name="payout_account_name" required maxlength="120" value="<?= e($driver['payout_account_name'] ?? $driver['full_name']) ?>"></div>
                <div class="field"><label for="payout_account_no">Account / mobile number</label><input type="text" id="payout_account_no" name="payout_account_no" required inputmode="numeric" value="<?= e($driver['payout_account_no'] ?? '') ?>"></div>
                <div class="field"><label for="pw">Your password</label><input id="pw" name="password" type="password" required autocomplete="current-password"><small>Required to change where money is sent.</small></div>
            </div>
            <button class="btn" type="submit">Save payout account</button>
        </form>
    </details>
    <?php if ($payouts): ?><div class="d-mini-list mt"><?php foreach ($payouts as $p): ?><div><span><?= e($p['reference']) ?><small><?= e(date('M j, Y', strtotime($p['created_at']))) ?><?= $p['bank_reference'] ? ' · Ref ' . e($p['bank_reference']) : '' ?><?= $p['status'] === 'rejected' && $p['note'] ? ' · ' . e($p['note']) : '' ?></small></span><b>₱<?= peso((int) $p['amount_centavos']) ?></b><?= driver_badge($p['status'] === 'requested' ? 'pending' : $p['status']) ?></div><?php endforeach; ?></div><?php endif; ?>
</section>

<section class="d-card">
    <h2>Wallet history</h2>
    <?php if (!$txs): ?><p class="d-empty">No wallet activity yet.</p><?php endif; ?>
    <div class="d-mini-list"><?php foreach ($txs as $t): $amt = (int) $t['amount_centavos']; ?>
        <div><span><?= e($types[$t['type']] ?? $t['type']) ?><small><?= e(date('M j, g:i A', strtotime($t['created_at']))) ?> · <?= e($t['reference']) ?><?= $t['memo'] ? ' · ' . e($t['memo']) : '' ?></small></span><b class="<?= $amt < 0 ? 'neg' : 'pos' ?>"><?= $amt < 0 ? '−' : '+' ?>₱<?= peso(abs($amt)) ?></b></div>
    <?php endforeach; ?></div>
</section>
<?php driver_footer(['assets/driver.js?v=5']);
