<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_member();
$uid = (int)$me['id'];
$transferOn = setting('wallet_transfer_enabled', '1') === '1';

if (is_post() && input('action') === 'transfer' && $transferOn) {
    csrf_check();
    $amount = round(input_float('amount'), 2);
    $to = find_member_by_username(strtolower(input('to_username')));
    try {
        if (!password_verify((string)($_POST['password'] ?? ''), $me['password'])) {
            throw new RuntimeException('Incorrect password.');
        }
        if (!$to || (int)$to['id'] === $uid || $to['status'] !== 'active') {
            throw new RuntimeException('Recipient not found or invalid.');
        }
        if ($amount < (float)setting('wallet_transfer_min', 1)) {
            throw new RuntimeException('Minimum transfer is ' . money(setting('wallet_transfer_min', 1)) . '.');
        }
        $ref = 'WT' . date('ymdHis') . random_string(3, '0123456789');
        tx(function () use ($uid, $to, $amount, $ref, $me) {
            wallet_debit($uid, 'transfer_out', $amount, "Transfer to @{$to['username']}", $ref);
            wallet_credit((int)$to['id'], 'transfer_in', $amount, "Transfer from @{$me['username']}", $ref);
        });
        log_activity($uid, "Wallet transfer {$amount} to {$to['username']}");
        flash('success', 'Transferred ' . money($amount) . " to @{$to['username']}.");
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('member/wallet.php');
}

$p = paginate('SELECT COUNT(*) FROM wallet_transactions WHERE user_id = ?', [$uid]);
$rows = q_all("SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY id DESC LIMIT {$p['limit']} OFFSET {$p['offset']}", [$uid]);
$in = (float)q_val("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE user_id = ? AND direction = 'credit'", [$uid]);
$out = (float)q_val("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE user_id = ? AND direction = 'debit'", [$uid]);
$withdrawn = (float)q_val("SELECT COALESCE(SUM(amount),0) FROM payouts WHERE user_id = ? AND status = 'approved'", [$uid]);

page_header('E-Wallet', $me, 'Your balance and transactions');
?>
<div class="stats">
  <?= stat_card('Available Balance', money($me['wallet_balance']), 'wallet', 'dark') ?>
  <?= stat_card('Total Credits', money($in), 'arrow-up') ?>
  <?= stat_card('Total Debits', money($out), 'cash', 'gold') ?>
  <?= stat_card('Total Withdrawn', money($withdrawn), 'download') ?>
</div>
<div class="grid <?= $transferOn ? 'grid-main' : '' ?>">
  <div class="card">
    <div class="card-header"><h3><?= icon('list') ?> Transactions</h3><div class="actions"><a class="btn btn-gold btn-sm" href="<?= e(url('member/payouts.php')) ?>"><?= icon('cash', 14) ?> Request Payout</a></div></div>
    <div class="card-body p-0 table-wrap">
      <table class="table stack">
        <thead><tr><th>Date</th><th>Description</th><th>Category</th><th class="text-right">Amount</th><th class="text-right">Balance</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="nowrap"><?= fdatetime($r['created_at']) ?></td>
            <td><?= e($r['description']) ?></td>
            <td><span class="badge badge-muted"><?= e(str_replace('_', ' ', $r['category'])) ?></span></td>
            <td class="text-right <?= $r['direction'] === 'credit' ? 'amount-plus' : 'amount-minus' ?>"><?= $r['direction'] === 'credit' ? '+' : '−' ?><?= money($r['amount']) ?></td>
            <td class="text-right"><?= money($r['balance_after']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows) echo empty_row(5, 'No transactions yet.'); ?>
        </tbody>
      </table>
    </div>
    <?= pagination_links($p) ?>
  </div>
  <?php if ($transferOn): ?>
  <div class="card">
    <div class="card-header"><h3><?= icon('send') ?> Transfer to Member</h3></div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="transfer">
        <div class="form-group"><label for="to_username">Recipient Username</label><input class="input" id="to_username" name="to_username" required></div>
        <div class="form-group"><label for="amount">Amount</label><input class="input" id="amount" type="number" step="0.01" min="<?= e(setting('wallet_transfer_min', 1)) ?>" max="<?= e($me['wallet_balance']) ?>" name="amount" required></div>
        <div class="form-group"><label for="password">Your Password</label><input class="input" id="password" type="password" name="password" required></div>
        <button class="btn btn-primary btn-block" type="submit" data-confirm="Send this transfer? This cannot be undone.">Send Transfer</button>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php page_footer();
