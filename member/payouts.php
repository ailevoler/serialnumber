<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_member();
$uid = (int)$me['id'];
$methods = setting_list('payout_methods');
$min = (float)setting('payout_min', 0);

if (is_post()) {
    csrf_check();
    $amount = round(input_float('amount'), 2);
    $method = input('method');
    $accName = input('account_name');
    $accNo = input('account_number');
    try {
        if ($amount < $min) {
            throw new RuntimeException('Minimum payout is ' . money($min) . '.');
        }
        if (!in_array($method, $methods, true) || $accName === '' || $accNo === '') {
            throw new RuntimeException('Please complete the payout method and account details.');
        }
        if (q_val("SELECT id FROM payouts WHERE user_id = ? AND status = 'pending'", [$uid])) {
            throw new RuntimeException('You still have a pending payout request.');
        }
        $fee = payout_fee($amount);
        if ($fee >= $amount) {
            throw new RuntimeException('Amount is too small after fees.');
        }
        tx(function () use ($uid, $amount, $fee, $method, $accName, $accNo) {
            wallet_debit($uid, 'payout', $amount, "Payout request via {$method}");
            q('INSERT INTO payouts (user_id, amount, fee, net_amount, method, account_name, account_number) VALUES (?,?,?,?,?,?,?)',
                [$uid, $amount, $fee, $amount - $fee, $method, $accName, $accNo]);
            q('UPDATE users SET payout_method = ?, payout_account_name = ?, payout_account_number = ? WHERE id = ?', [$method, $accName, $accNo, $uid]);
        });
        log_activity($uid, "Requested payout {$amount}");
        flash('success', 'Payout request submitted. Please wait for admin approval.');
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('member/payouts.php');
}

$p = paginate('SELECT COUNT(*) FROM payouts WHERE user_id = ?', [$uid]);
$rows = q_all("SELECT * FROM payouts WHERE user_id = ? ORDER BY id DESC LIMIT {$p['limit']} OFFSET {$p['offset']}", [$uid]);

page_header('Payouts', $me, 'Withdraw your earnings');
?>
<div class="grid grid-main">
  <div class="card">
    <div class="card-header"><h3><?= icon('list') ?> Payout History</h3></div>
    <div class="card-body p-0 table-wrap">
      <table class="table stack">
        <thead><tr><th>Date</th><th>Method</th><th>Amount</th><th>Fee</th><th>Net</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="nowrap"><?= fdatetime($r['created_at']) ?></td>
            <td><?= e($r['method']) ?><br><small class="text-muted"><?= e($r['account_number']) ?></small></td>
            <td><?= money($r['amount']) ?></td>
            <td><?= money($r['fee']) ?></td>
            <td><strong><?= money($r['net_amount']) ?></strong></td>
            <td><?= badge($r['status']) ?><?= $r['admin_note'] ? '<br><small class="text-muted">' . e($r['admin_note']) . '</small>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows) echo empty_row(6, 'No payout requests yet.'); ?>
        </tbody>
      </table>
    </div>
    <?= pagination_links($p) ?>
  </div>
  <div class="card">
    <div class="card-header"><h3><?= icon('cash') ?> Request Payout</h3></div>
    <div class="card-body">
      <div class="summary-box mb-2"><div class="row"><span>Available</span><strong><?= money($me['wallet_balance']) ?></strong></div></div>
      <form method="post">
        <?= csrf_field() ?>
        <div class="form-group">
          <label for="amount">Amount</label>
          <input class="input" id="amount" type="number" step="0.01" min="<?= e($min) ?>" max="<?= e($me['wallet_balance']) ?>" name="amount" required
                 data-payout-amount data-fee-pct="<?= e(setting('payout_fee_percent', 0)) ?>" data-fee-fixed="<?= e(setting('payout_fee_fixed', 0)) ?>">
          <div class="help">Minimum <?= money($min) ?> · Fee <?= e(setting('payout_fee_percent', 0)) ?>%<?= (float)setting('payout_fee_fixed') ? ' + ' . money(setting('payout_fee_fixed')) : '' ?></div>
        </div>
        <div class="summary-box mb-2 small">
          <div class="row"><span>Fee</span><span><?= e(setting('currency', '₱')) ?><span id="fee-preview">0.00</span></span></div>
          <div class="row total"><span>You receive</span><span><?= e(setting('currency', '₱')) ?><span id="net-preview">0.00</span></span></div>
        </div>
        <div class="form-group">
          <label for="method">Payout Method</label>
          <select class="input" id="method" name="method" required>
            <?php foreach ($methods as $m): ?><option <?= $me['payout_method'] === $m ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label for="account_name">Account Name</label><input class="input" id="account_name" name="account_name" value="<?= e($me['payout_account_name'] ?: full_name($me)) ?>" required></div>
        <div class="form-group"><label for="account_number">Account / Mobile Number</label><input class="input" id="account_number" name="account_number" value="<?= e($me['payout_account_number'] ?: $me['mobile']) ?>" required></div>
        <button class="btn btn-gold btn-block" type="submit" <?= $me['wallet_balance'] < $min ? 'disabled' : '' ?>>Submit Request</button>
      </form>
    </div>
  </div>
</div>
<?php page_footer();
