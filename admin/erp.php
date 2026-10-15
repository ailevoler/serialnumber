<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

$monthStart = date('Y-m-01');
$today = date('Y-m-d');
$all = gl_accounts_with_balances();
$mtd = gl_accounts_with_balances($monthStart, $today);
$byCode = array_column($all, null, 'code');

$sumType = fn(array $rows, string $type) => array_sum(array_map(fn($a) => $a['type'] === $type ? $a['balance'] : 0, $rows));
$cashCodes = array_map(fn($k) => (string)setting($k), ['acct_cash', 'acct_bank', 'acct_paymongo', 'acct_ewallet']);
$cash = array_sum(array_map(fn($c) => $byCode[$c]['balance'] ?? 0, $cashCodes));
$revMtd = $sumType($mtd, 'revenue');
$expMtd = $sumType($mtd, 'expense');
$walletGl = $byCode[(string)setting('acct_wallet_payable')]['balance'] ?? 0;
$walletActual = (float)q_val("SELECT COALESCE(SUM(wallet_balance),0) FROM users WHERE role = 'member'");
$payoutsPayable = $byCode[(string)setting('acct_payouts_payable')]['balance'] ?? 0;
$pendingPayouts = (float)q_val("SELECT COALESCE(SUM(amount),0) FROM payouts WHERE status = 'pending'");

// last 6 months revenue vs expenses
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("first day of -{$i} month"));
    $months[$m] = ['rev' => 0.0, 'exp' => 0.0];
}
foreach (q_all("SELECT DATE_FORMAT(e.posting_date, '%Y-%m') m, a.type, SUM(l.debit) d, SUM(l.credit) c
                FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id JOIN gl_accounts a ON a.id = l.account_id
                WHERE a.type IN ('revenue','expense') AND e.posting_date >= ? GROUP BY m, a.type", [array_key_first($months) . '-01']) as $r) {
    if (isset($months[$r['m']])) {
        $months[$r['m']][$r['type'] === 'revenue' ? 'rev' : 'exp'] = gl_balance($r['type'], (float)$r['d'], (float)$r['c']);
    }
}
$maxM = max(1, max(array_map(fn($m) => max($m['rev'], $m['exp']), $months)));
$recent = q_all('SELECT * FROM journal_entries ORDER BY id DESC LIMIT 8');
$expenseTop = array_filter($mtd, fn($a) => $a['type'] === 'expense' && $a['balance'] != 0);
usort($expenseTop, fn($a, $b) => $b['balance'] <=> $a['balance']);

page_header('Finance Dashboard', $me, 'ERP · General ledger overview');
?>
<?php if (!erp_on()): ?><div class="alert alert-warning">Automatic posting is switched off. Turn it on in <a href="<?= e(url('admin/erp_accounts.php')) ?>">Chart of Accounts → ERP Settings</a>.</div><?php endif; ?>
<div class="stats">
  <?= stat_card('Cash Position', money($cash), 'wallet', 'dark', 'Cash, bank, PayMongo & e-wallets') ?>
  <?= stat_card('Revenue (MTD)', money($revMtd), 'trending', 'gold', date('F Y')) ?>
  <?= stat_card('Expenses (MTD)', money($expMtd), 'cash', '', 'incl. commissions') ?>
  <?= stat_card('Net Income (MTD)', money($revMtd - $expMtd), 'chart', $revMtd - $expMtd >= 0 ? 'gold' : '') ?>
</div>

<div class="grid grid-2">
  <div class="card">
    <div class="card-header"><h3><?= icon('chart') ?> Revenue vs Expenses — 6 Months</h3></div>
    <div class="card-body">
      <div class="bar-chart bar-chart-pair">
        <?php foreach ($months as $m => $v): ?>
          <div class="bar-col">
            <div class="bar-pair">
              <div class="bar" title="Revenue <?= money($v['rev']) ?>" style="height:<?= max(2, round($v['rev'] / $maxM * 100)) ?>%"></div>
              <div class="bar gold" title="Expenses <?= money($v['exp']) ?>" style="height:<?= max(2, round($v['exp'] / $maxM * 100)) ?>%"></div>
            </div>
            <small><?= date('M', strtotime($m . '-01')) ?></small>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="small text-muted mb-0 mt-1"><span class="legend legend-green"></span> Revenue &nbsp; <span class="legend legend-gold"></span> Expenses</p>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h3><?= icon('shield') ?> Reconciliation</h3></div>
    <div class="card-body">
      <?php $diff1 = round($walletGl - $walletActual, 2); $diff2 = round($payoutsPayable - $pendingPayouts, 2); ?>
      <div class="summary-box mb-2">
        <div class="row"><span>Members' Wallet Payable (G/L)</span><strong><?= money($walletGl) ?></strong></div>
        <div class="row"><span>Sum of member wallets</span><strong><?= money($walletActual) ?></strong></div>
        <div class="row total"><span>Difference</span><span class="<?= $diff1 ? 'text-danger' : 'text-green' ?>"><?= money($diff1) ?> <?= $diff1 ? '⚠' : '✓' ?></span></div>
      </div>
      <div class="summary-box">
        <div class="row"><span>Payouts Payable (G/L)</span><strong><?= money($payoutsPayable) ?></strong></div>
        <div class="row"><span>Pending payout requests</span><strong><?= money($pendingPayouts) ?></strong></div>
        <div class="row total"><span>Difference</span><span class="<?= $diff2 ? 'text-danger' : 'text-green' ?>"><?= money($diff2) ?> <?= $diff2 ? '⚠' : '✓' ?></span></div>
      </div>
      <p class="small text-muted mb-0 mt-1">A difference means some movements happened while automatic posting was off, or a manual entry touched these accounts.</p>
    </div>
  </div>
</div>

<div class="grid grid-main">
  <div class="card">
    <div class="card-header"><h3><?= icon('book') ?> Latest Journal Entries</h3><div class="actions"><a class="btn btn-light btn-sm" href="<?= e(url('admin/erp_journal.php')) ?>">All entries</a><a class="btn btn-primary btn-sm" href="<?= e(url('admin/erp_journal.php?new=1')) ?>"><?= icon('plus', 14) ?> New Entry</a></div></div>
    <div class="card-body p-0 table-wrap">
      <table class="table stack">
        <thead><tr><th>Doc No.</th><th>Type</th><th>Date</th><th>Text</th><th class="text-right">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $j): ?>
          <tr><td><a class="mono" href="<?= e(url('admin/erp_journal.php?view=' . $j['id'])) ?>"><?= e($j['doc_no']) ?></a></td><td><span class="badge badge-muted" title="<?= e(erp_doc_types()[$j['doc_type']] ?? '') ?>"><?= e($j['doc_type']) ?></span></td>
            <td class="nowrap"><?= fdate($j['posting_date']) ?></td><td><?= e($j['memo']) ?><?= $j['status'] === 'reversed' ? ' ' . badge('cancelled') : '' ?></td><td class="text-right"><?= money($j['total']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$recent) echo empty_row(5, 'No journal entries yet. They are created automatically as transactions happen.'); ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h3><?= icon('cash') ?> Expenses This Month</h3></div>
    <div class="card-body">
      <ul class="list-plain small">
        <?php foreach (array_slice($expenseTop, 0, 8) as $a): ?>
          <li><span class="mono text-muted"><?= e($a['code']) ?></span> <?= e($a['name']) ?><strong style="margin-left:auto"><?= money($a['balance']) ?></strong></li>
        <?php endforeach; ?>
        <?php if (!$expenseTop): ?><li class="text-muted">No expenses posted this month.</li><?php endif; ?>
      </ul>
      <a class="btn btn-outline btn-sm btn-block mt-1" href="<?= e(url('admin/erp_expenses.php')) ?>"><?= icon('plus', 14) ?> Record Expense</a>
    </div>
  </div>
</div>
<?php page_footer();
