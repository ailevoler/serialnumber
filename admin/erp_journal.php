<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

if (is_post()) {
    csrf_check();
    try {
        if (input('action') === 'post') {
            $lines = [];
            foreach ((array)($_POST['line'] ?? []) as $l) {
                if (!is_array($l) || empty($l['account'])) {
                    continue;
                }
                $lines[] = [(string)$l['account'], (float)($l['debit'] ?? 0), (float)($l['credit'] ?? 0), trim((string)($l['memo'] ?? '')) ?: null];
            }
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', input('posting_date')) ? input('posting_date') : date('Y-m-d');
            if (input('memo') === '') {
                throw new RuntimeException('Header text is required.');
            }
            $id = erp_post('SA', input('memo'), $lines, 'manual', null, $date, true);
            log_activity((int)$me['id'], 'Posted manual journal entry #' . $id);
            flash('success', 'Journal entry posted.');
            redirect('admin/erp_journal.php?view=' . $id);
        } elseif (input('action') === 'reverse') {
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', input('reverse_date')) ? input('reverse_date') : date('Y-m-d');
            $id = erp_reverse(input_int('id'), $date);
            log_activity((int)$me['id'], 'Reversed journal entry #' . input_int('id'));
            flash('success', 'Entry reversed.');
            redirect('admin/erp_journal.php?view=' . $id);
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('admin/erp_journal.php' . (input('action') === 'post' ? '?new=1' : '?view=' . input_int('id')));
}

$accounts = q_all("SELECT id, code, name FROM gl_accounts WHERE status = 'active' ORDER BY code");
$view = input_int('view') ? q_row('SELECT j.*, u.username FROM journal_entries j LEFT JOIN users u ON u.id = j.created_by WHERE j.id = ?', [input_int('view')]) : null;

$type = array_key_exists(input('type'), erp_doc_types()) ? input('type') : '';
$from = input('from');
$to = input('to');
$search = input('q');
$where = ['1=1'];
$params = [];
if ($type) { $where[] = 'doc_type = ?'; $params[] = $type; }
if ($from) { $where[] = 'posting_date >= ?'; $params[] = $from; }
if ($to) { $where[] = 'posting_date <= ?'; $params[] = $to; }
if ($search !== '') { $where[] = '(doc_no LIKE ? OR memo LIKE ?)'; array_push($params, "%$search%", "%$search%"); }
$w = implode(' AND ', $where);

if (input('export') === 'csv') {
    $rows = q_all("SELECT j.doc_no, j.doc_type, j.posting_date, j.memo, a.code, a.name, l.debit, l.credit, l.memo line_memo
                   FROM journal_entries j JOIN journal_lines l ON l.entry_id = j.id JOIN gl_accounts a ON a.id = l.account_id WHERE $w ORDER BY j.id, l.id", $params);
    csv_download('xgen-journal-' . date('Ymd') . '.csv', ['Doc No', 'Type', 'Posting Date', 'Header Text', 'Account', 'Account Name', 'Debit', 'Credit', 'Line Text'], $rows);
}
$p = paginate("SELECT COUNT(*) FROM journal_entries WHERE $w", $params, 30);
$rows = q_all("SELECT * FROM journal_entries WHERE $w ORDER BY id DESC LIMIT {$p['limit']} OFFSET {$p['offset']}", $params);

page_header('Journal Entries', $me, 'Double-entry documents (FB50-style)');
?>
<?php if ($view): $lines = q_all('SELECT l.*, a.code, a.name, u.username FROM journal_lines l JOIN gl_accounts a ON a.id = l.account_id LEFT JOIN users u ON u.id = l.user_id WHERE l.entry_id = ? ORDER BY l.id', [$view['id']]); ?>
<div class="card">
  <div class="card-header">
    <h3><?= icon('book') ?> Document <span class="mono"><?= e($view['doc_no']) ?></span> <span class="badge badge-muted"><?= e($view['doc_type']) ?> · <?= e(erp_doc_types()[$view['doc_type']] ?? '') ?></span> <?= $view['status'] === 'reversed' ? badge('cancelled') : badge('active') ?></h3>
    <div class="actions">
      <?php if ($view['status'] === 'posted' && $view['doc_type'] !== 'AB'): ?>
        <form method="post" class="filters"><?= csrf_field() ?><input type="hidden" name="action" value="reverse"><input type="hidden" name="id" value="<?= (int)$view['id'] ?>">
          <input class="input" type="date" name="reverse_date" value="<?= date('Y-m-d') ?>" aria-label="Reversal date">
          <button class="btn btn-danger btn-sm" data-confirm="Post a reversal document for this entry?">Reverse</button></form>
      <?php endif; ?>
      <a class="btn btn-light btn-sm" href="?">Close</a>
    </div>
  </div>
  <div class="card-body">
    <dl class="kv mb-2">
      <dt>Posting date</dt><dd><?= fdate($view['posting_date']) ?></dd>
      <dt>Header text</dt><dd><?= e($view['memo']) ?></dd>
      <dt>Reference</dt><dd><?= e($view['ref_type'] ? $view['ref_type'] . ' #' . $view['ref_id'] : '—') ?></dd>
      <dt>Entered by</dt><dd><?= $view['username'] ? '@' . e($view['username']) : 'System' ?> · <?= fdatetime($view['created_at']) ?></dd>
      <?php if ($view['reversal_of']): ?><dt><?= $view['doc_type'] === 'AB' ? 'Reverses' : 'Reversed by' ?></dt><dd><a href="?view=<?= (int)$view['reversal_of'] ?>">#<?= (int)$view['reversal_of'] ?></a></dd><?php endif; ?>
    </dl>
  </div>
  <div class="card-body p-0 table-wrap">
    <table class="table stack">
      <thead><tr><th>Account</th><th>Text</th><th>Member</th><th class="text-right">Debit</th><th class="text-right">Credit</th></tr></thead>
      <tbody>
      <?php $td = $tc = 0; foreach ($lines as $l): $td += $l['debit']; $tc += $l['credit']; ?>
        <tr><td><a href="<?= e(url('admin/erp_ledger.php?account=' . $l['account_id'])) ?>"><span class="mono"><?= e($l['code']) ?></span> <?= e($l['name']) ?></a></td><td><?= e($l['memo']) ?></td><td><?= $l['username'] ? '@' . e($l['username']) : '' ?></td>
          <td class="text-right"><?= $l['debit'] > 0 ? money($l['debit']) : '' ?></td><td class="text-right"><?= $l['credit'] > 0 ? money($l['credit']) : '' ?></td></tr>
      <?php endforeach; ?>
        <tr class="total-row"><td colspan="3">Total</td><td class="text-right"><?= money($td) ?></td><td class="text-right"><?= money($tc) ?></td></tr>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if (input('new')): ?>
<form method="post" class="card" data-je-form>
  <?= csrf_field() ?><input type="hidden" name="action" value="post">
  <div class="card-header"><h3><?= icon('plus') ?> New G/L Journal Entry (SA)</h3></div>
  <div class="card-body">
    <div class="form-grid mb-2">
      <div class="form-group"><label>Posting date</label><input class="input" type="date" name="posting_date" value="<?= date('Y-m-d') ?>" required></div>
      <div class="form-group"><label>Header text</label><input class="input" name="memo" placeholder="e.g. Owner capital contribution" required></div>
    </div>
    <div class="table-wrap">
      <table class="table je-table">
        <thead><tr><th style="min-width:220px">G/L Account</th><th>Line text</th><th style="width:140px">Debit</th><th style="width:140px">Credit</th><th></th></tr></thead>
        <tbody data-je-lines>
        <?php for ($i = 0; $i < 2; $i++): ?>
          <tr>
            <td><select class="input" name="line[<?= $i ?>][account]" required><option value="">— select —</option><?php foreach ($accounts as $a): ?><option value="<?= e($a['code']) ?>"><?= e($a['code'] . ' · ' . $a['name']) ?></option><?php endforeach; ?></select></td>
            <td><input class="input" name="line[<?= $i ?>][memo]"></td>
            <td><input class="input" type="number" step="0.01" min="0" name="line[<?= $i ?>][debit]" data-je-dr></td>
            <td><input class="input" type="number" step="0.01" min="0" name="line[<?= $i ?>][credit]" data-je-cr></td>
            <td><button type="button" class="btn btn-xs btn-light" data-je-remove>&times;</button></td>
          </tr>
        <?php endfor; ?>
        </tbody>
        <tfoot><tr class="total-row"><td colspan="2"><button type="button" class="btn btn-outline btn-xs" data-je-add><?= icon('plus', 12) ?> Add line</button></td>
          <td class="text-right" data-je-total-dr>0.00</td><td class="text-right" data-je-total-cr>0.00</td><td></td></tr></tfoot>
      </table>
    </div>
    <p class="small mt-1" data-je-status></p>
    <button class="btn btn-primary" type="submit" data-je-submit><?= icon('book', 15) ?> Post Document</button>
    <a class="btn btn-light" href="?">Cancel</a>
  </div>
</form>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <form class="filters" method="get" style="flex:1">
      <select class="input" name="type"><option value="">All document types</option><?php foreach (erp_doc_types() as $k => $l): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= $k ?> · <?= e($l) ?></option><?php endforeach; ?></select>
      <input class="input" type="date" name="from" value="<?= e($from) ?>" aria-label="From">
      <input class="input" type="date" name="to" value="<?= e($to) ?>" aria-label="To">
      <input class="input" name="q" value="<?= e($search) ?>" placeholder="Doc no. or text">
      <button class="btn btn-primary btn-sm">Filter</button>
      <button class="btn btn-light btn-sm" name="export" value="csv"><?= icon('download', 14) ?> CSV</button>
      <a class="btn btn-gold btn-sm" href="?new=1"><?= icon('plus', 14) ?> New Entry</a>
    </form>
  </div>
  <div class="card-body p-0 table-wrap">
    <table class="table stack">
      <thead><tr><th>Doc No.</th><th>Type</th><th>Posting Date</th><th>Header Text</th><th>Status</th><th class="text-right">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $j): ?>
        <tr>
          <td><a class="mono" href="?view=<?= (int)$j['id'] ?>"><?= e($j['doc_no']) ?></a></td>
          <td><span class="badge badge-muted" title="<?= e(erp_doc_types()[$j['doc_type']] ?? '') ?>"><?= e($j['doc_type']) ?></span></td>
          <td class="nowrap"><?= fdate($j['posting_date']) ?></td>
          <td><?= e($j['memo']) ?></td>
          <td><?= $j['status'] === 'reversed' ? badge('cancelled') : badge('active') ?></td>
          <td class="text-right"><?= money($j['total']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows) echo empty_row(6); ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($p) ?>
</div>
<?php page_footer();
