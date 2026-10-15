<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();
$id = input_int('id');
$m = q_row("SELECT u.*, p.name package_name FROM users u LEFT JOIN packages p ON p.id = u.package_id WHERE u.id = ? AND u.role = 'member'", [$id]);
if (!$m) {
    flash('error', 'Member not found.');
    redirect('admin/members.php');
}

if (is_post()) {
    csrf_check();
    $action = input('action');
    try {
        if ($action === 'profile') {
            $email = input('email');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email.');
            }
            $status = in_array(input('status'), ['active', 'inactive', 'blocked'], true) ? input('status') : $m['status'];
            q('UPDATE users SET first_name = ?, last_name = ?, email = ?, mobile = ?, address = ?, status = ? WHERE id = ?',
                [input('first_name'), input('last_name'), $email, input('mobile'), input('address'), $status, $id]);
            log_activity((int)$me['id'], "Updated member {$m['username']} (status: {$status})");
            flash('success', 'Member updated.');
        } elseif ($action === 'password') {
            $pw = (string)($_POST['new_password'] ?? '');
            if ($err = validate_password($pw)) {
                throw new RuntimeException($err);
            }
            q('UPDATE users SET password = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
            log_activity((int)$me['id'], "Reset password of {$m['username']}");
            flash('success', 'Password has been reset.');
        } elseif ($action === 'crm') {
            crm_add_activity(null, $id, (int)$me['id'], input('type'), input('body'), input('due_date') ?: null);
            flash('success', 'CRM activity saved.');
        } elseif ($action === 'wallet') {
            $amount = round(input_float('amount'), 2);
            $dir = input('direction') === 'debit' ? 'debit' : 'credit';
            $note = input('note') ?: 'Admin adjustment';
            tx(function () use ($id, $dir, $amount, $note, $me) {
                wallet_post($id, $dir, 'adjustment', $amount, $note, 'ADMIN#' . $me['id']);
                erp_post('WA', 'Wallet adjustment: ' . $note, $dir === 'credit'
                    ? [['acct_wallet_adjust', $amount, 0, null, $id], ['acct_wallet_payable', 0, $amount, null, $id]]
                    : [['acct_wallet_payable', $amount, 0, null, $id], ['acct_other_income', 0, $amount, null, $id]], 'user', $id);
            });
            log_activity((int)$me['id'], "Wallet {$dir} {$amount} for {$m['username']}: {$note}");
            flash('success', 'Wallet ' . $dir . 'ed ' . money($amount) . '.');
        }
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('admin/member_view.php?id=' . $id);
}

$sponsor = $m['sponsor_id'] ? q_row('SELECT id, username FROM users WHERE id = ?', [$m['sponsor_id']]) : null;
$placement = $m['placement_id'] ? q_row('SELECT id, username FROM users WHERE id = ?', [$m['placement_id']]) : null;
$passup = $m['passup_upline_id'] ? q_row('SELECT id, username FROM users WHERE id = ?', [$m['passup_upline_id']]) : null;
$summary = earnings_summary($id);
$tx = q_all('SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 10', [$id]);
$codes = (int)q_val("SELECT COUNT(*) FROM codes WHERE owner_id = ? AND status = 'unused'", [$id]);
$logs = q_all('SELECT * FROM activity_logs WHERE user_id = ? ORDER BY id DESC LIMIT 8', [$id]);

page_header('Member: @' . $m['username'], $me, full_name($m));
?>
<div class="stats">
  <?= stat_card('Wallet', money($m['wallet_balance']), 'wallet', 'dark') ?>
  <?= stat_card('Total Earnings', money($m['total_earnings']), 'trending', 'gold') ?>
  <?= stat_card('Directs', num($m['direct_count']), 'user-plus') ?>
  <?= stat_card('Left / Right', num($m['left_count']) . ' / ' . num($m['right_count']), 'git-merge', 'gold', num($m['left_points']) . ' / ' . num($m['right_points']) . ' PV') ?>
</div>

<div class="card">
  <div class="card-header"><h3><?= icon('chart') ?> Earnings Breakdown</h3>
    <div class="actions">
      <a class="btn btn-light btn-sm" href="<?= e(url('admin/genealogy.php?root=' . $id)) ?>"><?= icon('tree', 14) ?> Genealogy</a>
      <a class="btn btn-light btn-sm" href="<?= e(url('admin/earnings.php?user=' . urlencode($m['username']))) ?>">Earnings</a>
      <a class="btn btn-light btn-sm" href="<?= e(url('admin/wallet.php?user=' . urlencode($m['username']))) ?>">Ledger</a>
    </div>
  </div>
  <div class="card-body"><div class="earn-grid">
    <?php foreach ($summary as $s): ?><div class="earn-item"><div class="earn-ic"><?= icon($s['icon']) ?></div><span><?= e($s['label']) ?></span><strong><?= money($s['total']) ?></strong></div><?php endforeach; ?>
  </div></div>
</div>

<div class="grid grid-main">
  <div>
    <div class="card">
      <div class="card-header"><h3><?= icon('user') ?> Profile &amp; Status</h3></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="profile">
          <div class="form-grid">
            <div class="form-group"><label>First Name</label><input class="input" name="first_name" value="<?= e($m['first_name']) ?>" required></div>
            <div class="form-group"><label>Last Name</label><input class="input" name="last_name" value="<?= e($m['last_name']) ?>" required></div>
            <div class="form-group"><label>Email</label><input class="input" type="email" name="email" value="<?= e($m['email']) ?>" required></div>
            <div class="form-group"><label>Mobile</label><input class="input" name="mobile" value="<?= e($m['mobile']) ?>"></div>
            <div class="form-group"><label>Address</label><input class="input" name="address" value="<?= e($m['address']) ?>"></div>
            <div class="form-group"><label>Status</label>
              <select class="input" name="status"><?php foreach (['active', 'inactive', 'blocked'] as $s): ?><option <?= $m['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
              <div class="help">Inactive members do not receive bonuses. Blocked members cannot log in.</div></div>
          </div>
          <button class="btn btn-primary mt-2" type="submit">Save</button>
        </form>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h3><?= icon('briefcase') ?> CRM — Notes &amp; Activities</h3>
        <div class="actions"><a class="btn btn-light btn-sm" href="<?= e(url('admin/support.php?status=open')) ?>"><?= icon('message', 14) ?> Live chat</a></div></div>
      <div class="card-body">
        <form method="post" class="mb-2"><?= csrf_field() ?><input type="hidden" name="action" value="crm">
          <div class="form-grid">
            <div class="form-group"><label>Type</label><select class="input" name="type"><?php foreach (crm_activity_types() as $k => $t): ?><option value="<?= $k ?>"><?= e($t) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Due date (tasks)</label><input class="input" type="date" name="due_date"></div>
            <div class="form-group full"><textarea class="input" name="body" rows="2" placeholder="Call notes, concerns, agreements…" required></textarea></div>
          </div>
          <button class="btn btn-outline btn-sm mt-1" type="submit">Save Activity</button>
        </form>
        <?= crm_timeline(crm_activities('member_id', $id, 20)) ?>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h3><?= icon('wallet') ?> Recent Wallet Transactions</h3></div>
      <div class="card-body p-0 table-wrap">
        <table class="table stack">
          <thead><tr><th>Date</th><th>Description</th><th class="text-right">Amount</th><th class="text-right">Balance</th></tr></thead>
          <tbody>
          <?php foreach ($tx as $t): ?>
            <tr><td class="nowrap"><?= fdatetime($t['created_at']) ?></td><td><?= e($t['description']) ?></td>
              <td class="text-right <?= $t['direction'] === 'credit' ? 'amount-plus' : 'amount-minus' ?>"><?= $t['direction'] === 'credit' ? '+' : '−' ?><?= money($t['amount']) ?></td><td class="text-right"><?= money($t['balance_after']) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$tx) echo empty_row(4); ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div>
    <div class="card">
      <div class="card-header"><h3><?= icon('shield') ?> Network Info</h3></div>
      <div class="card-body">
        <dl class="kv">
          <dt>Package</dt><dd><span class="badge badge-gold"><?= e($m['package_name'] ?? '—') ?></span></dd>
          <dt>Sponsor</dt><dd><?= $sponsor ? '<a href="?id=' . $sponsor['id'] . '">@' . e($sponsor['username']) . '</a>' : '—' ?></dd>
          <dt>Placement</dt><dd><?= $placement ? '<a href="?id=' . $placement['id'] . '">@' . e($placement['username']) . '</a> (' . ($m['position'] === 'L' ? 'Left' : 'Right') . ')' : '— (root)' ?></dd>
          <dt>Pass-up upline</dt><dd><?= $passup ? '@' . e($passup['username']) : '—' ?></dd>
          <dt>Unused codes</dt><dd><?= $codes ?></dd>
          <dt>Payout acct</dt><dd><?= e($m['payout_method'] ?: '—') ?> <?= e($m['payout_account_number']) ?></dd>
          <dt>Last login</dt><dd><?= fdatetime($m['last_login']) ?></dd>
          <dt>Joined</dt><dd><?= fdatetime($m['created_at']) ?></dd>
        </dl>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h3><?= icon('cash') ?> Wallet Adjustment</h3></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="wallet">
          <div class="radio-row mb-2">
            <label class="radio-pill"><input type="radio" name="direction" value="credit" checked><span>Credit (+)</span></label>
            <label class="radio-pill"><input type="radio" name="direction" value="debit"><span>Debit (−)</span></label>
          </div>
          <div class="form-group"><label>Amount</label><input class="input" type="number" step="0.01" min="0.01" name="amount" required></div>
          <div class="form-group"><label>Note</label><input class="input" name="note" placeholder="Reason for adjustment" required></div>
          <button class="btn btn-gold btn-block" type="submit" data-confirm="Apply this wallet adjustment?">Apply</button>
        </form>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h3><?= icon('key') ?> Reset Password</h3></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="password">
          <div class="form-group"><label>New Password</label><input class="input" type="text" name="new_password" minlength="8" required></div>
          <button class="btn btn-outline btn-block" type="submit" data-confirm="Reset this member's password?">Reset Password</button>
        </form>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h3><?= icon('list') ?> Activity</h3></div>
      <div class="card-body"><ul class="list-plain small">
        <?php foreach ($logs as $l): ?><li><span><?= e($l['action']) ?></span><span class="text-muted" style="margin-left:auto" title="<?= e($l['ip']) ?>"><?= fdatetime($l['created_at']) ?></span></li><?php endforeach; ?>
        <?php if (!$logs): ?><li class="text-muted">No activity.</li><?php endif; ?>
      </ul></div>
    </div>
  </div>
</div>
<?php page_footer();
