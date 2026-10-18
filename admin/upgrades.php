<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

$user = strtolower(input('user'));
$from = input('from');
$to = input('to');
$where = ['1=1'];
$params = [];
if ($user !== '') { $where[] = 'm.username = ?'; $params[] = $user; }
if ($from) { $where[] = 'u.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to) { $where[] = 'u.created_at <= ?'; $params[] = $to . ' 23:59:59'; }
$w = implode(' AND ', $where);
$base = "FROM package_upgrades u JOIN users m ON m.id = u.user_id LEFT JOIN packages f ON f.id = u.from_package_id JOIN packages t ON t.id = u.to_package_id
         LEFT JOIN users a ON a.id = u.created_by WHERE $w";
$methods = upgrade_methods();

if (input('export') === 'csv') {
    $rows = q_all("SELECT u.created_at, m.username, f.name, t.name, u.amount, u.pv_added, u.bonuses, u.method, u.reference, a.username $base ORDER BY u.id DESC", $params);
    csv_download('xgen-upgrades-' . date('Ymd') . '.csv', ['Date', 'Member', 'From', 'To', 'Amount', 'PV Added', 'Bonuses Paid', 'Method', 'Reference', 'By Admin'], $rows);
}
$tot = q_row("SELECT COUNT(*) n, COALESCE(SUM(u.amount),0) amount, COALESCE(SUM(u.bonuses),0) bonuses, COALESCE(SUM(u.pv_added),0) pv $base", $params);
$p = paginate("SELECT COUNT(*) $base", $params, 30);
$rows = q_all("SELECT u.*, m.username, f.name from_name, t.name to_name, a.username admin $base ORDER BY u.id DESC LIMIT {$p['limit']} OFFSET {$p['offset']}", $params);

page_header('Package Upgrades', $me, 'Members who moved up to a higher package');
?>
<div class="stats">
  <?= stat_card('Upgrades', num($tot['n']), 'arrow-up') ?>
  <?= stat_card('Upgrade Sales', money($tot['amount']), 'cash', 'dark') ?>
  <?= stat_card('Bonus Difference Paid', money($tot['bonuses']), 'trending', 'gold', $tot['amount'] > 0 ? num($tot['bonuses'] / $tot['amount'] * 100, 1) . '% of upgrade sales' : '') ?>
  <?= stat_card('PV Added to Binary', num($tot['pv']), 'git-merge') ?>
</div>
<div class="card">
  <div class="card-header">
    <form class="filters" method="get">
      <input class="input" name="user" value="<?= e($user) ?>" placeholder="Username">
      <input class="input" type="date" name="from" value="<?= e($from) ?>" aria-label="From">
      <input class="input" type="date" name="to" value="<?= e($to) ?>" aria-label="To">
      <button class="btn btn-primary btn-sm">Filter</button>
      <button class="btn btn-light btn-sm" name="export" value="csv"><?= icon('download', 14) ?> CSV</button>
    </form>
    <div class="actions"><a class="btn btn-light btn-sm" href="<?= e(url('admin/compensation.php#upgrade')) ?>"><?= icon('settings', 14) ?> Upgrade settings</a></div>
  </div>
  <div class="card-body p-0 table-wrap">
    <table class="table stack">
      <thead><tr><th>Date</th><th>Member</th><th>Upgrade</th><th>Paid with</th><th>Reference</th><th class="text-right">PV added</th><th class="text-right">Bonuses</th><th class="text-right">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="nowrap"><?= fdatetime($r['created_at']) ?><?= $r['admin'] ? '<br><small class="text-muted">by @' . e($r['admin']) . '</small>' : '' ?></td>
          <td><a href="<?= e(url('admin/member_view.php?id=' . $r['user_id'])) ?>">@<?= e($r['username']) ?></a></td>
          <td><?= e(($r['from_name'] ?? '—') . ' → ' . $r['to_name']) ?></td>
          <td><?= e($methods[$r['method']] ?? $r['method']) ?></td>
          <td class="mono small"><?= e($r['reference']) ?></td>
          <td class="text-right"><?= num($r['pv_added']) ?></td>
          <td class="text-right"><?= money($r['bonuses']) ?></td>
          <td class="text-right"><strong><?= money($r['amount']) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows) echo empty_row(8, 'No package upgrades yet.'); ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($p) ?>
</div>
<?php page_footer();
