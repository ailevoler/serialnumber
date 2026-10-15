<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

$types = ['asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Equity', 'revenue' => 'Revenue', 'expense' => 'Expense'];
$mapping = [
    'acct_cash'            => 'Cash on hand (cash payments)',
    'acct_bank'            => 'Bank (bank deposits)',
    'acct_paymongo'        => 'PayMongo clearing',
    'acct_ewallet'         => 'GCash / Maya (manual e-wallet payments)',
    'acct_wallet_payable'  => "Members' wallet payable",
    'acct_payouts_payable' => 'Payouts payable',
    'acct_payout_source'   => 'Payouts are released from',
    'acct_package_sales'   => 'Package / activation sales',
    'acct_product_sales'   => 'Product sales',
    'acct_payout_fees'     => 'Payout fee income',
    'acct_other_income'    => 'Other income (wallet debits)',
    'acct_comm_direct'     => 'Commission – Direct Referral',
    'acct_comm_indirect'   => 'Commission – Indirect',
    'acct_comm_passup'     => 'Commission – Pass Up',
    'acct_comm_pairing'    => 'Commission – Pairing / Matching',
    'acct_comm_unilevel'   => 'Commission – Unilevel',
    'acct_wallet_adjust'   => 'Wallet adjustments (admin credits)',
];

if (is_post()) {
    csrf_check();
    $action = input('action');
    try {
        if ($action === 'save') {
            $id = input_int('id');
            $code = preg_replace('/[^0-9A-Za-z-]/', '', input('code'));
            $type = array_key_exists(input('type'), $types) ? input('type') : null;
            if ($code === '' || input('name') === '' || !$type) {
                throw new RuntimeException('Code, name and type are required.');
            }
            if (q_val('SELECT id FROM gl_accounts WHERE code = ? AND id <> ?', [$code, $id])) {
                throw new RuntimeException('Account code already exists.');
            }
            $status = input('status') === 'inactive' ? 'inactive' : 'active';
            if ($id) {
                $acc = q_row('SELECT * FROM gl_accounts WHERE id = ?', [$id]);
                $used = (int)q_val('SELECT COUNT(*) FROM journal_lines WHERE account_id = ?', [$id]);
                if ($acc['is_system'] && ($acc['code'] !== $code || $acc['type'] !== $type)) {
                    throw new RuntimeException('System accounts keep their code and type. You can rename them.');
                }
                if ($used && $acc['type'] !== $type) {
                    throw new RuntimeException('This account already has postings; its type cannot change.');
                }
                q('UPDATE gl_accounts SET code = ?, name = ?, type = ?, description = ?, status = ? WHERE id = ?', [$code, input('name'), $type, input('description'), $status, $id]);
            } else {
                q('INSERT INTO gl_accounts (code, name, type, description, status) VALUES (?,?,?,?,?)', [$code, input('name'), $type, input('description'), $status]);
            }
            flash('success', 'Account saved.');
        } elseif ($action === 'mapping') {
            foreach ($mapping as $k => $label) {
                $c = input($k);
                if ($c !== '' && q_val('SELECT id FROM gl_accounts WHERE code = ?', [$c])) {
                    save_setting($k, $c);
                }
            }
            save_setting('erp_enabled', isset($_POST['erp_enabled']) ? '1' : '0');
            $lock = input('erp_lock_date');
            save_setting('erp_lock_date', preg_match('/^\d{4}-\d{2}-\d{2}$/', $lock) ? $lock : '');
            flash('success', 'ERP settings and account mapping saved.');
        }
        log_activity((int)$me['id'], "ERP accounts: {$action}");
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('admin/erp_accounts.php');
}

$accounts = gl_accounts_with_balances();
$edit = input_int('edit') ? q_row('SELECT * FROM gl_accounts WHERE id = ?', [input_int('edit')]) : null;

page_header('Chart of Accounts', $me, 'G/L accounts, balances and posting rules');
?>
<div class="grid grid-main">
  <div class="card">
    <div class="card-header"><h3><?= icon('settings') ?> G/L Accounts</h3></div>
    <div class="card-body p-0 table-wrap">
      <table class="table stack">
        <thead><tr><th>Code</th><th>Account</th><th>Type</th><th class="text-right">Balance</th><th></th></tr></thead>
        <tbody>
        <?php $lastType = ''; foreach ($accounts as $a): if ($a['type'] !== $lastType): $lastType = $a['type']; ?>
          <tr class="group-row"><td colspan="5"><?= e($types[$a['type']]) ?>s</td></tr>
        <?php endif; ?>
          <tr class="<?= $a['status'] === 'inactive' ? 'text-muted' : '' ?>">
            <td class="mono"><?= e($a['code']) ?></td>
            <td><a href="<?= e(url('admin/erp_ledger.php?account=' . $a['id'])) ?>"><?= e($a['name']) ?></a><?= $a['is_system'] ? ' <span class="badge badge-gold">system</span>' : '' ?><?= $a['status'] === 'inactive' ? ' ' . badge('inactive') : '' ?></td>
            <td><?= e($types[$a['type']]) ?></td>
            <td class="text-right"><?= money($a['balance']) ?></td>
            <td><a class="btn btn-xs btn-light" href="?edit=<?= (int)$a['id'] ?>">Edit</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div>
    <div class="card">
      <div class="card-header"><h3><?= icon($edit ? 'settings' : 'plus') ?> <?= $edit ? 'Edit Account' : 'New Account' ?></h3></div>
      <div class="card-body">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
          <div class="form-grid">
            <div class="form-group"><label>Code</label><input class="input mono" name="code" value="<?= e($edit['code'] ?? '') ?>" required></div>
            <div class="form-group"><label>Type</label><select class="input" name="type"><?php foreach ($types as $k => $l): ?><option value="<?= $k ?>" <?= ($edit['type'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="form-group full"><label>Name</label><input class="input" name="name" value="<?= e($edit['name'] ?? '') ?>" required></div>
            <div class="form-group full"><label>Description</label><input class="input" name="description" value="<?= e($edit['description'] ?? '') ?>"></div>
            <div class="form-group"><label>Status</label><select class="input" name="status"><option>active</option><option <?= ($edit['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>inactive</option></select></div>
          </div>
          <button class="btn btn-primary mt-2" type="submit">Save Account</button>
          <?php if ($edit): ?><a class="btn btn-light mt-2" href="?">Cancel</a><?php endif; ?>
        </form>
      </div>
    </div>
    <form method="post" class="card">
      <?= csrf_field() ?><input type="hidden" name="action" value="mapping">
      <div class="card-header"><h3><?= icon('layers') ?> ERP Settings &amp; Account Mapping</h3></div>
      <div class="card-body">
        <label class="toggle-card mb-2"><input type="checkbox" name="erp_enabled" value="1" <?= erp_on() ? 'checked' : '' ?>><span class="toggle-ui"></span><span><strong>Automatic posting</strong><small>Post sales, commissions and payouts to the ledger</small></span></label>
        <div class="form-group"><label>Period closed up to (lock date)</label><input class="input" type="date" name="erp_lock_date" value="<?= e(setting('erp_lock_date')) ?>"><div class="help">No entries can be posted on or before this date.</div></div>
        <?php foreach ($mapping as $k => $label): ?>
          <div class="form-group"><label><?= e($label) ?></label>
            <select class="input" name="<?= $k ?>"><?php foreach ($accounts as $a): ?><option value="<?= e($a['code']) ?>" <?= setting($k) === $a['code'] ? 'selected' : '' ?>><?= e($a['code'] . ' · ' . $a['name']) ?></option><?php endforeach; ?></select></div>
        <?php endforeach; ?>
        <button class="btn btn-primary" type="submit">Save Settings</button>
      </div>
    </form>
  </div>
</div>
<?php page_footer();
