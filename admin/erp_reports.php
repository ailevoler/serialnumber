<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

$tab = in_array(input('report'), ['tb', 'pl', 'bs'], true) ? input('report') : 'pl';
$from = input('from', date('Y-01-01'));
$to = input('to', date('Y-m-d'));
$titles = ['tb' => 'Trial Balance', 'pl' => 'Income Statement', 'bs' => 'Balance Sheet'];

// P&L uses the period; Balance Sheet and Trial Balance are cumulative up to "to".
$period = gl_accounts_with_balances($from, $to);
$cumul = gl_accounts_with_balances(null, $to);
$filter = fn(array $rows, string $type) => array_values(array_filter($rows, fn($a) => $a['type'] === $type && (abs($a['balance']) > 0.004 || $a['debit'] > 0 || $a['credit'] > 0)));
$sum = fn(array $rows) => array_sum(array_column($rows, 'balance'));

$rev = $filter($period, 'revenue');
$exp = $filter($period, 'expense');
$netIncome = $sum($rev) - $sum($exp);

$assets = $filter($cumul, 'asset');
$liabs = $filter($cumul, 'liability');
$equity = $filter($cumul, 'equity');
$retained = $sum($filter($cumul, 'revenue')) - $sum($filter($cumul, 'expense'));

if (input('export') === 'csv') {
    $out = [];
    if ($tab === 'tb') {
        foreach ($cumul as $a) {
            if ($a['debit'] || $a['credit']) {
                $net = $a['debit'] - $a['credit'];
                $out[] = [$a['code'], $a['name'], ucfirst($a['type']), $net > 0 ? round($net, 2) : '', $net < 0 ? round(-$net, 2) : ''];
            }
        }
        csv_download("trial-balance-{$to}.csv", ['Code', 'Account', 'Type', 'Debit', 'Credit'], $out);
    }
    $sets = $tab === 'pl' ? ['Revenue' => $rev, 'Expenses' => $exp] : ['Assets' => $assets, 'Liabilities' => $liabs, 'Equity' => $equity];
    foreach ($sets as $label => $rows) {
        foreach ($rows as $a) {
            $out[] = [$label, $a['code'], $a['name'], round($a['balance'], 2)];
        }
    }
    $out[] = $tab === 'pl' ? ['Net Income', '', '', round($netIncome, 2)] : ['Equity', '', 'Current earnings', round($retained, 2)];
    csv_download(($tab === 'pl' ? 'income-statement' : 'balance-sheet') . "-{$to}.csv", ['Section', 'Code', 'Account', 'Amount'], $out);
}

function fs_section(string $title, array $rows, string $totalLabel, float $extra = 0.0, string $extraLabel = ''): float
{
    $total = array_sum(array_column($rows, 'balance')) + $extra;
    echo '<tr class="group-row"><td colspan="2">' . e($title) . '</td></tr>';
    foreach ($rows as $a) {
        echo '<tr><td><span class="mono text-muted">' . e($a['code']) . '</span> <a href="' . e(url('admin/erp_ledger.php?account=' . $a['id'])) . '">' . e($a['name']) . '</a></td><td class="text-right">' . money($a['balance']) . '</td></tr>';
    }
    if ($extraLabel !== '') {
        echo '<tr><td><em>' . e($extraLabel) . '</em></td><td class="text-right">' . money($extra) . '</td></tr>';
    }
    if (!$rows && $extraLabel === '') {
        echo '<tr><td class="text-muted" colspan="2">No balances</td></tr>';
    }
    echo '<tr class="total-row"><td>' . e($totalLabel) . '</td><td class="text-right">' . money($total) . '</td></tr>';
    return $total;
}

page_header('Financial Statements', $me, $titles[$tab]);
?>
<div class="tabs">
  <?php foreach ($titles as $k => $t): ?><a href="?<?= e(http_build_query(['report' => $k, 'from' => $from, 'to' => $to])) ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= e($t) ?></a><?php endforeach; ?>
</div>
<div class="card">
  <div class="card-body">
    <form class="filters" method="get">
      <input type="hidden" name="report" value="<?= e($tab) ?>">
      <?php if ($tab === 'pl'): ?><div><label>From</label><input class="input" type="date" name="from" value="<?= e($from) ?>"></div><?php endif; ?>
      <div><label><?= $tab === 'pl' ? 'To' : 'As of' ?></label><input class="input" type="date" name="to" value="<?= e($to) ?>"></div>
      <button class="btn btn-primary">Generate</button>
      <button class="btn btn-light" name="export" value="csv"><?= icon('download', 14) ?> CSV</button>
      <button class="btn btn-light" type="button" onclick="window.print()">Print</button>
    </form>
  </div>
</div>

<div class="card fs-card">
  <div class="card-header fs-head">
    <div><h3><?= e(setting('site_name')) ?></h3><p class="mb-0"><strong><?= e($titles[$tab]) ?></strong> · <?= $tab === 'pl' ? fdate($from) . ' – ' . fdate($to) : 'As of ' . fdate($to) ?></p></div>
  </div>
  <div class="card-body p-0 table-wrap">
  <?php if ($tab === 'pl'): ?>
    <table class="table fs-table"><tbody>
      <?php $r = fs_section('Revenue', $rev, 'Total Revenue'); $x = fs_section('Expenses', $exp, 'Total Expenses'); ?>
      <tr class="grand-row <?= $netIncome < 0 ? 'neg' : '' ?>"><td>NET INCOME <?= $netIncome < 0 ? '(LOSS)' : '' ?></td><td class="text-right"><?= money($netIncome) ?></td></tr>
      <tr><td class="text-muted small">Commission payout ratio (commissions ÷ revenue)</td><td class="text-right small"><?php
        $comm = array_sum(array_map(fn($a) => str_starts_with($a['name'], 'Commission') ? $a['balance'] : 0, $exp));
        echo $r > 0 ? num($comm / $r * 100, 1) . '%' : '—'; ?></td></tr>
    </tbody></table>
  <?php elseif ($tab === 'bs'): ?>
    <table class="table fs-table"><tbody>
      <?php $ta = fs_section('Assets', $assets, 'Total Assets');
            $tl = fs_section('Liabilities', $liabs, 'Total Liabilities');
            $te = fs_section("Equity", $equity, "Total Equity", $retained, 'Current / retained earnings'); ?>
      <tr class="grand-row"><td>TOTAL LIABILITIES &amp; EQUITY</td><td class="text-right"><?= money($tl + $te) ?></td></tr>
      <tr><td class="small <?= abs($ta - $tl - $te) > 0.01 ? 'text-danger' : 'text-green' ?>"><?= abs($ta - $tl - $te) > 0.01 ? '⚠ Out of balance by ' . money($ta - $tl - $te) : '✓ Balanced: Assets = Liabilities + Equity' ?></td><td></td></tr>
    </tbody></table>
  <?php else: $tdr = $tcr = 0; ?>
    <table class="table stack">
      <thead><tr><th>Code</th><th>Account</th><th>Type</th><th class="text-right">Debit</th><th class="text-right">Credit</th></tr></thead>
      <tbody>
      <?php foreach ($cumul as $a): if (!$a['debit'] && !$a['credit']) continue; $net = $a['debit'] - $a['credit']; $tdr += max(0, $net); $tcr += max(0, -$net); ?>
        <tr><td class="mono"><?= e($a['code']) ?></td><td><a href="<?= e(url('admin/erp_ledger.php?account=' . $a['id'])) ?>"><?= e($a['name']) ?></a></td><td><?= e(ucfirst($a['type'])) ?></td>
          <td class="text-right"><?= $net > 0 ? money($net) : '' ?></td><td class="text-right"><?= $net < 0 ? money(-$net) : '' ?></td></tr>
      <?php endforeach; ?>
        <tr class="total-row"><td colspan="3">Total <?= abs($tdr - $tcr) < 0.01 ? '<span class="badge badge-success">Balanced</span>' : '<span class="badge badge-danger">Not balanced</span>' ?></td><td class="text-right"><?= money($tdr) ?></td><td class="text-right"><?= money($tcr) ?></td></tr>
      </tbody>
    </table>
  <?php endif; ?>
  </div>
</div>
<?php page_footer();
