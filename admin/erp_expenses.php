<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

$expAccounts = q_all("SELECT * FROM gl_accounts WHERE type = 'expense' AND status = 'active' ORDER BY code");
$payCodes = array_map(fn($k) => (string)setting($k), ['acct_cash', 'acct_bank', 'acct_ewallet', 'acct_paymongo']);
$payCodes[] = '2100'; // Accounts Payable (unpaid bill)
$payAccounts = q_all('SELECT * FROM gl_accounts WHERE code IN (' . implode(',', array_fill(0, count($payCodes), '?')) . ") AND status = 'active' ORDER BY code", $payCodes);

if (is_post()) {
    csrf_check();
    try {
        $amount = round(input_float('amount'), 2);
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', input('expense_date')) ? input('expense_date') : date('Y-m-d');
        $acc = q_row("SELECT * FROM gl_accounts WHERE id = ? AND type = 'expense'", [input_int('account_id')]);
        $paid = q_row('SELECT * FROM gl_accounts WHERE id = ? AND code IN (' . implode(',', array_fill(0, count($payCodes), '?')) . ')', array_merge([input_int('paid_from')], $payCodes));
        if (!$acc || !$paid || $amount <= 0 || input('vendor') === '') {
            throw new RuntimeException('Vendor, expense account, payment account and amount are required.');
        }
        tx(function () use ($amount, $date, $acc, $paid, $me) {
            $memo = 'Expense: ' . input('vendor') . (input('description') ? ' – ' . input('description') : '') . (input('reference') ? ' (Ref ' . input('reference') . ')' : '');
            $entry = erp_post('KR', $memo, [[$acc['code'], $amount, 0], [$paid['code'], 0, $amount]], 'expense', null, $date, true);
            q('INSERT INTO expenses (expense_date, vendor, account_id, paid_from_account_id, amount, reference, description, entry_id, created_by) VALUES (?,?,?,?,?,?,?,?,?)',
                [$date, input('vendor'), $acc['id'], $paid['id'], $amount, input('reference'), input('description'), $entry, $me['id']]);
            q('UPDATE journal_entries SET ref_id = ? WHERE id = ?', [(int)db()->lastInsertId(), $entry]);
        });
        log_activity((int)$me['id'], 'Recorded expense ' . $amount . ' – ' . input('vendor'));
        flash('success', 'Expense recorded and posted to the ledger.');
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('admin/erp_expenses.php');
}

$from = input('from', date('Y-m-01'));
$to = input('to', date('Y-m-d'));
$p = paginate('SELECT COUNT(*) FROM expenses WHERE expense_date BETWEEN ? AND ?', [$from, $to]);
$rows = q_all("SELECT x.*, a.code, a.name account_name, pa.name paid_name, j.doc_no, j.status je_status FROM expenses x JOIN gl_accounts a ON a.id = x.account_id
               JOIN gl_accounts pa ON pa.id = x.paid_from_account_id LEFT JOIN journal_entries j ON j.id = x.entry_id
               WHERE x.expense_date BETWEEN ? AND ? ORDER BY x.expense_date DESC, x.id DESC LIMIT {$p['limit']} OFFSET {$p['offset']}", [$from, $to]);
$total = (float)q_val('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ? AND ?', [$from, $to]);

page_header('Expenses', $me, 'Operating expenses (posted as KR documents)');
?>
<div class="grid grid-main">
  <div class="card">
    <div class="card-header">
      <h3><?= icon('cash') ?> Expenses · <?= money($total) ?></h3>
      <div class="actions"><form class="filters" method="get"><input class="input" type="date" name="from" value="<?= e($from) ?>" aria-label="From"><input class="input" type="date" name="to" value="<?= e($to) ?>" aria-label="To"><button class="btn btn-primary btn-sm">Filter</button></form></div>
    </div>
    <div class="card-body p-0 table-wrap">
      <table class="table stack">
        <thead><tr><th>Date</th><th>Vendor / Description</th><th>Expense Account</th><th>Paid From</th><th>Doc No.</th><th class="text-right">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="nowrap"><?= fdate($r['expense_date']) ?></td>
            <td><strong><?= e($r['vendor']) ?></strong><br><small class="text-muted"><?= e($r['description']) ?><?= $r['reference'] ? ' · Ref ' . e($r['reference']) : '' ?></small></td>
            <td><span class="mono text-muted"><?= e($r['code']) ?></span> <?= e($r['account_name']) ?></td>
            <td><?= e($r['paid_name']) ?></td>
            <td><a class="mono" href="<?= e(url('admin/erp_journal.php?view=' . $r['entry_id'])) ?>"><?= e($r['doc_no']) ?></a><?= $r['je_status'] === 'reversed' ? ' ' . badge('cancelled') : '' ?></td>
            <td class="text-right amount-minus"><?= money($r['amount']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows) echo empty_row(6, 'No expenses in this period.'); ?>
        </tbody>
      </table>
    </div>
    <?= pagination_links($p) ?>
  </div>
  <div class="card">
    <div class="card-header"><h3><?= icon('plus') ?> Record Expense</h3></div>
    <div class="card-body">
      <form method="post"><?= csrf_field() ?>
        <div class="form-group"><label>Date</label><input class="input" type="date" name="expense_date" value="<?= date('Y-m-d') ?>" required></div>
        <div class="form-group"><label>Vendor / Payee</label><input class="input" name="vendor" required></div>
        <div class="form-group"><label>Expense account</label><select class="input" name="account_id" required><?php foreach ($expAccounts as $a): if (str_starts_with($a['name'], 'Commission')) continue; ?><option value="<?= (int)$a['id'] ?>"><?= e($a['code'] . ' · ' . $a['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Paid from</label><select class="input" name="paid_from" required>
          <?php foreach ($payAccounts as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $a['code'] === setting('acct_bank') ? 'selected' : '' ?>><?= e($a['code'] . ' · ' . $a['name']) ?><?= $a['type'] === 'liability' ? ' (unpaid bill)' : '' ?></option><?php endforeach; ?>
        </select></div>
        <div class="form-group"><label>Amount</label><input class="input" type="number" step="0.01" min="0.01" name="amount" required></div>
        <div class="form-group"><label>Reference / OR No.</label><input class="input" name="reference"></div>
        <div class="form-group"><label>Description</label><input class="input" name="description"></div>
        <button class="btn btn-primary btn-block" type="submit">Post Expense</button>
      </form>
    </div>
  </div>
</div>
<?php page_footer();
