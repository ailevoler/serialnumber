<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

$search = input('q');
$status = in_array(input('status'), ['active', 'inactive', 'blocked'], true) ? input('status') : '';
$package = input_int('package');

$where = ["u.role = 'member'"];
$params = [];
if ($search !== '') {
    $where[] = '(u.username LIKE ? OR u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.mobile LIKE ?)';
    array_push($params, ...array_fill(0, 5, "%{$search}%"));
}
if ($status) { $where[] = 'u.status = ?'; $params[] = $status; }
if ($package) { $where[] = 'u.package_id = ?'; $params[] = $package; }
$w = implode(' AND ', $where);

$sql = "SELECT u.*, p.name package_name, s.username sponsor FROM users u LEFT JOIN packages p ON p.id = u.package_id LEFT JOIN users s ON s.id = u.sponsor_id WHERE $w ORDER BY u.id DESC";
if (input('export') === 'csv') {
    $rows = q_all($sql, $params);
    csv_download('xgen-members-' . date('Ymd') . '.csv',
        ['ID', 'Username', 'First Name', 'Last Name', 'Email', 'Mobile', 'Package', 'Sponsor', 'Status', 'Directs', 'Left', 'Right', 'Wallet', 'Total Earnings', 'Joined'],
        array_map(fn($r) => [$r['id'], $r['username'], $r['first_name'], $r['last_name'], $r['email'], $r['mobile'], $r['package_name'], $r['sponsor'], $r['status'],
            $r['direct_count'], $r['left_count'], $r['right_count'], $r['wallet_balance'], $r['total_earnings'], $r['created_at']], $rows));
}
$p = paginate("SELECT COUNT(*) FROM users u WHERE $w", $params);
$rows = q_all("$sql LIMIT {$p['limit']} OFFSET {$p['offset']}", $params);
$packages = q_all('SELECT id, name FROM packages ORDER BY id');

page_header('Members', $me, num($p['total']) . ' member(s) found');
?>
<div class="card">
  <div class="card-header">
    <form class="filters" method="get" style="flex:1">
      <input class="input" name="q" value="<?= e($search) ?>" placeholder="Search name, username, email, mobile" style="flex:1">
      <select class="input" name="status"><option value="">All status</option><?php foreach (['active', 'inactive', 'blocked'] as $s): ?><option <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
      <select class="input" name="package"><option value="0">All packages</option><?php foreach ($packages as $pk): ?><option value="<?= $pk['id'] ?>" <?= $package === (int)$pk['id'] ? 'selected' : '' ?>><?= e($pk['name']) ?></option><?php endforeach; ?></select>
      <button class="btn btn-primary btn-sm" type="submit"><?= icon('search', 14) ?> Search</button>
      <a class="btn btn-light btn-sm" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>"><?= icon('download', 14) ?> CSV</a>
    </form>
  </div>
  <div class="card-body p-0 table-wrap">
    <table class="table stack">
      <thead><tr><th>Member</th><th>Package</th><th>Sponsor</th><th>Directs</th><th>L / R</th><th>Wallet</th><th>Earnings</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><div class="user-cell"><div class="avatar"><?= e(initials($r)) ?></div><div><strong><?= e(full_name($r)) ?></strong><small>@<?= e($r['username']) ?> · <?= e($r['email']) ?></small></div></div></td>
          <td><span class="badge badge-gold"><?= e($r['package_name'] ?? '—') ?></span></td>
          <td><?= $r['sponsor'] ? '@' . e($r['sponsor']) : '—' ?></td>
          <td><?= num($r['direct_count']) ?></td>
          <td class="nowrap"><?= num($r['left_count']) ?> / <?= num($r['right_count']) ?></td>
          <td><?= money($r['wallet_balance']) ?></td>
          <td class="text-green"><?= money($r['total_earnings']) ?></td>
          <td><?= badge($r['status']) ?></td>
          <td><a class="btn btn-xs btn-outline" href="<?= e(url('admin/member_view.php?id=' . $r['id'])) ?>">Manage</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows) echo empty_row(9); ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($p) ?>
</div>
<?php page_footer();
