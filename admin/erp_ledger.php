<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

$accounts = q_all('SELECT * FROM gl_accounts ORDER BY code');
$accId = input_int('account', (int)($accounts[0]['id'] ?? 0));
$acc = q_row('SELECT * FROM gl_accounts WHERE id = ?', [$accId]);
$from = input('from', date('Y-m-01'));
$to = input('to', date('Y-m-d'));

$opening = 0.0;
$rows = [];
if ($acc) {
    $o = q_row('SELECT COALESCE(SUM(l.debit),0) d, COALESCE(SUM(l.credit),0) c FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
                WHERE l.account_id = ? AND e.posting_date < ?', [$accId, $from]);
    $opening = gl_balance($acc['type'], (float)$o['d'], (float)$o['c']);
    $rows = q_all('SELECT l.*, e.doc_no, e.doc_type, e.posting_date, e.memo header, e.id entry_id, u.username
                   FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id LEFT JOIN users u ON u.id = l.user_id
                   WHERE l.account_id = ? AND e.posting_date BETWEEN ? AND ? ORDER BY e.posting_date, e.id, l.id', [$accId, $from, $to]);
    if (input('export') === 'csv') {
        $bal = $opening;
        $out = [['', '', '', 'Opening balance', '', '', '', number_format($opening, 2, '.', '')]];
        foreach ($rows as $r) {
            $bal += gl_balance($acc['type'], (float)$r['debit'], (float)$r['credit']);
            $out[] = [$r['posting_date'], $r['doc_no'], $r['doc_type'], $r['memo'] ?: $r['header'], $r['username'], $r['debit'], $r['credit'], number_format($bal, 2, '.', '')];
        }
        csv_download("gl-{$acc['code']}-{$from}-{$to}.csv", ['Date', 'Doc No', 'Type', 'Text', 'Member', 'Debit', 'Credit', 'Balance'], $out);
    }
}

page_header('General Ledger', $me, $acc ? $acc['code'] . ' · ' . $acc['name'] : '');
?>
<div class="card">
  <div class="card-body">
    <form class="filters" method="get">
      <div style="flex:1;min-width:220px"><label>Account</label><select class="input" name="account"><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === $accId ? 'selected' : '' ?>><?= e($a['code'] . ' · ' . $a['name']) ?></option><?php endforeach; ?></select></div>
      <div><label>From</label><input class="input" type="date" name="from" value="<?= e($from) ?>"></div>
      <div><label>To</label><input class="input" type="date" name="to" value="<?= e($to) ?>"></div>
      <button class="btn btn-primary">Show</button>
      <button class="btn btn-light" name="export" value="csv"><?= icon('download', 14) ?> CSV</button>
    </form>
  </div>
</div>
<?php if ($acc): $bal = $opening; $td = $tc = 0; ?>
<div class="card">
  <div class="card-header"><h3><?= icon('list') ?> <span class="mono"><?= e($acc['code']) ?></span> <?= e($acc['name']) ?> <span class="badge badge-muted"><?= e(ucfirst($acc['type'])) ?></span></h3></div>
  <div class="card-body p-0 table-wrap">
    <table class="table stack">
      <thead><tr><th>Date</th><th>Doc No.</th><th>Text</th><th>Member</th><th class="text-right">Debit</th><th class="text-right">Credit</th><th class="text-right">Balance</th></tr></thead>
      <tbody>
        <tr class="group-row"><td colspan="6">Opening balance as of <?= fdate($from) ?></td><td class="text-right"><?= money($opening) ?></td></tr>
        <?php foreach ($rows as $r): $bal += gl_balance($acc['type'], (float)$r['debit'], (float)$r['credit']); $td += $r['debit']; $tc += $r['credit']; ?>
          <tr>
            <td class="nowrap"><?= fdate($r['posting_date']) ?></td>
            <td><a class="mono" href="<?= e(url('admin/erp_journal.php?view=' . $r['entry_id'])) ?>"><?= e($r['doc_no']) ?></a> <span class="badge badge-muted"><?= e($r['doc_type']) ?></span></td>
            <td><?= e($r['memo'] ?: $r['header']) ?></td>
            <td><?= $r['username'] ? '@' . e($r['username']) : '' ?></td>
            <td class="text-right"><?= $r['debit'] > 0 ? money($r['debit']) : '' ?></td>
            <td class="text-right"><?= $r['credit'] > 0 ? money($r['credit']) : '' ?></td>
            <td class="text-right"><?= money($bal) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows) echo empty_row(7, 'No postings in this period.'); ?>
        <tr class="total-row"><td colspan="4">Period totals · Closing balance</td><td class="text-right"><?= money($td) ?></td><td class="text-right"><?= money($tc) ?></td><td class="text-right"><?= money($bal) ?></td></tr>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php page_footer();
