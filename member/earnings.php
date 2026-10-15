<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_member();
$uid = (int)$me['id'];

$types = earning_types();
$type = array_key_exists(input('type'), $types) ? input('type') : '';
$from = input('from');
$to = input('to');

$where = ['e.user_id = ?'];
$params = [$uid];
if ($type) { $where[] = 'e.type = ?'; $params[] = $type; }
if ($from) { $where[] = 'e.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to)   { $where[] = 'e.created_at <= ?'; $params[] = $to . ' 23:59:59'; }
$w = implode(' AND ', $where);

$p = paginate("SELECT COUNT(*) FROM earnings e WHERE $w", $params);
$rows = q_all("SELECT e.*, s.username source FROM earnings e LEFT JOIN users s ON s.id = e.source_user_id WHERE $w ORDER BY e.id DESC LIMIT {$p['limit']} OFFSET {$p['offset']}", $params);
$sum = (float)q_val("SELECT COALESCE(SUM(amount),0) FROM earnings e WHERE $w", $params);
$summary = earnings_summary($uid, $from ?: null, $to ?: null);

page_header('My Earnings', $me, 'Income from the 6 ways to earn');
?>
<div class="earn-grid mb-2">
  <?php foreach ($summary as $k => $s): ?>
    <a class="earn-item" href="?type=<?= $k ?>&from=<?= e($from) ?>&to=<?= e($to) ?>" style="<?= $type === $k ? 'border-color:var(--green)' : '' ?>">
      <div class="earn-ic"><?= icon($s['icon']) ?></div><span><?= e($s['label']) ?></span><strong><?= money($s['total']) ?></strong><span class="small"><?= $s['count'] ?> record(s)</span>
    </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header">
    <h3><?= icon('trending') ?> Income History</h3>
    <div class="actions">
      <form class="filters" method="get">
        <select class="input" name="type"><option value="">All types</option><?php foreach ($types as $k => $t): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= e($t['label']) ?></option><?php endforeach; ?></select>
        <input class="input" type="date" name="from" value="<?= e($from) ?>" aria-label="From">
        <input class="input" type="date" name="to" value="<?= e($to) ?>" aria-label="To">
        <button class="btn btn-primary btn-sm" type="submit">Filter</button>
      </form>
    </div>
  </div>
  <div class="card-body p-0 table-wrap">
    <table class="table stack">
      <thead><tr><th>Date</th><th>Type</th><th>Description</th><th>From</th><th class="text-right">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="nowrap"><?= fdatetime($r['created_at']) ?></td>
          <td><span class="badge badge-<?= $r['credited'] ? 'success' : 'gold' ?>"><?= e($types[$r['type']]['label']) ?></span></td>
          <td><?= e($r['description']) ?></td>
          <td><?= $r['source'] ? '@' . e($r['source']) : '—' ?></td>
          <td class="text-right amount-plus"><?= money($r['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows) echo empty_row(5); ?>
      </tbody>
    </table>
  </div>
  <div class="card-body"><strong>Total (filtered): <span class="text-green"><?= money($sum) ?></span></strong>
    <p class="small text-muted mb-0">Product Discount is your savings/retail profit and is not credited to the wallet.</p></div>
  <?= pagination_links($p) ?>
</div>
<?php page_footer();
