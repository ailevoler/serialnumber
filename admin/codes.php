<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

if (is_post()) {
    csrf_check();
    $action = input('action');
    try {
        if ($action === 'generate') {
            $qty = input_int('qty');
            $pkg = q_row("SELECT * FROM packages WHERE id = ? AND status = 'active'", [input_int('package_id')]);
            if (!$pkg || $qty < 1 || $qty > 500) {
                throw new RuntimeException('Choose a package and quantity (1–500).');
            }
            $owner = null;
            if (input('owner') !== '') {
                $o = find_member_by_username(strtolower(input('owner')));
                if (!$o) {
                    throw new RuntimeException('Owner username not found.');
                }
                $owner = (int)$o['id'];
            }
            $type = in_array(input('code_type'), ['free', 'upgrade'], true) ? input('code_type') : 'paid';
            $saleMethod = input('sale_method');
            $codes = tx(function () use ($pkg, $qty, $type, $owner, $saleMethod) {
                $codes = generate_codes((int)$pkg['id'], $qty, $type, $owner);
                if ($type === 'upgrade') {
                    // The amount depends on the member's current package, so the sale is booked when the code is redeemed.
                    $in = implode(',', array_fill(0, count($codes), '?'));
                    q("UPDATE codes SET sale_method = ? WHERE code IN ($in)", array_merge([in_array($saleMethod, ['cash', 'bank', 'gcash'], true) ? $saleMethod : null], $codes));
                } elseif ($type === 'paid' && in_array($saleMethod, ['cash', 'bank', 'gcash'], true)) {
                    erp_post_code_sale($saleMethod, (float)$pkg['price'] * $qty, "Code sale: {$qty} × {$pkg['name']} (" . strtoupper($saleMethod) . ')' . ($owner ? ' to @' . input('owner') : ''), $owner, 'codes', null);
                }
                return $codes;
            });
            log_activity((int)$me['id'], "Generated {$qty} {$type} {$pkg['name']} code(s)" . ($owner ? ' for ' . input('owner') : ''));
            flash('success', count($codes) . " {$pkg['name']} code(s) generated.");
        } elseif ($action === 'assign' || $action === 'block' || $action === 'unblock') {
            $ids = array_map('intval', (array)($_POST['ids'] ?? []));
            if (!$ids) {
                throw new RuntimeException('Select at least one code.');
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            if ($action === 'assign') {
                $o = find_member_by_username(strtolower(input('owner')));
                if (!$o) {
                    throw new RuntimeException('Member username not found.');
                }
                $n = q("UPDATE codes SET owner_id = ? WHERE status = 'unused' AND id IN ($in)", array_merge([$o['id']], $ids))->rowCount();
                flash('success', "{$n} code(s) assigned to @{$o['username']}.");
            } else {
                [$from, $to] = $action === 'block' ? ['unused', 'blocked'] : ['blocked', 'unused'];
                $n = q("UPDATE codes SET status = '$to' WHERE status = '$from' AND id IN ($in)", $ids)->rowCount();
                flash('success', "{$n} code(s) {$action}ed.");
            }
            log_activity((int)$me['id'], ucfirst($action) . ' codes: ' . implode(',', $ids));
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('admin/codes.php?' . http_build_query(array_intersect_key($_GET, array_flip(['status', 'q', 'package']))));
}

$status = in_array(input('status'), ['unused', 'used', 'blocked'], true) ? input('status') : '';
$search = input('q');
$package = input_int('package');
$where = ['1=1'];
$params = [];
if ($status) { $where[] = 'c.status = ?'; $params[] = $status; }
if ($package) { $where[] = 'c.package_id = ?'; $params[] = $package; }
if ($search !== '') { $where[] = '(c.code LIKE ? OR o.username LIKE ? OR u.username LIKE ?)'; array_push($params, "%$search%", "%$search%", "%$search%"); }
$w = implode(' AND ', $where);
$from = "FROM codes c JOIN packages p ON p.id = c.package_id LEFT JOIN users o ON o.id = c.owner_id LEFT JOIN users u ON u.id = c.used_by WHERE $w";

if (input('export') === 'csv') {
    $rows = q_all("SELECT c.code, c.pin, p.name, c.code_type, c.status, o.username owner, u.username used_by, c.created_at, c.used_at $from ORDER BY c.id DESC", $params);
    csv_download('xgen-codes-' . date('Ymd') . '.csv', ['Code', 'PIN', 'Package', 'Type', 'Status', 'Owner', 'Used By', 'Created', 'Used At'], $rows);
}
$p = paginate("SELECT COUNT(*) $from", $params, 30);
$rows = q_all("SELECT c.*, p.name package_name, o.username owner, u.username used_username $from ORDER BY c.id DESC LIMIT {$p['limit']} OFFSET {$p['offset']}", $params);
$packages = q_all("SELECT * FROM packages WHERE status = 'active' ORDER BY id");
$counts = array_column(q_all('SELECT status, COUNT(*) n FROM codes GROUP BY status'), 'n', 'status');

page_header('Activation Codes', $me, 'Generate and distribute serial codes');
?>
<div class="stats">
  <?= stat_card('Unused', num($counts['unused'] ?? 0), 'key') ?>
  <?= stat_card('Used', num($counts['used'] ?? 0), 'user-plus', 'gold') ?>
  <?= stat_card('Blocked', num($counts['blocked'] ?? 0), 'shield') ?>
</div>

<div class="card">
  <div class="card-header"><h3><?= icon('plus') ?> Generate Codes</h3></div>
  <div class="card-body">
    <form method="post" class="filters">
      <?= csrf_field() ?><input type="hidden" name="action" value="generate">
      <div><label>Package</label><select class="input" name="package_id" required><?php foreach ($packages as $pk): ?><option value="<?= $pk['id'] ?>"><?= e($pk['name']) ?> – <?= money($pk['price']) ?></option><?php endforeach; ?></select></div>
      <div><label>Quantity</label><input class="input" type="number" name="qty" min="1" max="500" value="10" required></div>
      <div><label>Type</label><select class="input" name="code_type"><option value="paid">Paid (earns bonuses)</option><option value="free">Free (no bonuses / PV)</option><option value="upgrade">Upgrade (members move up to this package)</option></select></div>
      <div><label>Assign to (username)</label><input class="input" name="owner" placeholder="optional"></div>
      <div><label>Payment received (ERP)</label><select class="input" name="sale_method"><option value="">Not yet / record later</option><option value="cash">Cash on Hand</option><option value="bank">Bank Deposit</option><option value="gcash">GCash / Maya</option></select></div>
      <button class="btn btn-primary" type="submit"><?= icon('key', 15) ?> Generate</button>
    </form>
    <p class="help mb-0 mt-1">Upgrade codes: the member pays the price difference to their current package; the payment method chosen here is booked in the ERP when the code is redeemed.</p>
  </div>
</div>

<form method="post" class="card">
  <?= csrf_field() ?>
  <div class="card-header">
    <div class="filters" style="flex:1">
      <input class="input" name="owner" placeholder="Username to assign selected">
      <button class="btn btn-gold btn-sm" name="action" value="assign" type="submit"><?= icon('send', 14) ?> Assign</button>
      <button class="btn btn-light btn-sm" name="action" value="block" type="submit" data-confirm="Block the selected codes?">Block</button>
      <button class="btn btn-light btn-sm" name="action" value="unblock" type="submit">Unblock</button>
    </div>
  </div>
  <div class="card-body" style="padding-bottom:0">
    <div class="filters">
      <input class="input" form="code-filter" name="q" value="<?= e($search) ?>" placeholder="Search code / owner / user">
      <select class="input" form="code-filter" name="status"><option value="">All status</option><?php foreach (['unused', 'used', 'blocked'] as $s): ?><option <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
      <select class="input" form="code-filter" name="package"><option value="0">All packages</option><?php foreach ($packages as $pk): ?><option value="<?= $pk['id'] ?>" <?= $package === (int)$pk['id'] ? 'selected' : '' ?>><?= e($pk['name']) ?></option><?php endforeach; ?></select>
      <button class="btn btn-primary btn-sm" form="code-filter" type="submit">Filter</button>
      <button class="btn btn-light btn-sm" form="code-filter" name="export" value="csv" type="submit"><?= icon('download', 14) ?> CSV</button>
    </div>
  </div>
  <div class="card-body p-0 table-wrap mt-2">
    <table class="table stack">
      <thead><tr><th><input type="checkbox" data-check-all=".code-check" aria-label="Select all"></th><th>Code</th><th>PIN</th><th>Package</th><th>Type</th><th>Owner</th><th>Status</th><th>Used by</th><th>Created</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?php if ($r['status'] !== 'used'): ?><input type="checkbox" class="code-check" name="ids[]" value="<?= (int)$r['id'] ?>"><?php endif; ?></td>
          <td><span class="code-chip mono"><?= e($r['code']) ?></span></td>
          <td class="mono"><?= e($r['pin']) ?></td>
          <td><?= e($r['package_name']) ?></td>
          <td><?= code_type_badge($r['code_type']) ?></td>
          <td><?= $r['owner'] ? '@' . e($r['owner']) : '<span class="text-muted">Company</span>' ?></td>
          <td><?= badge($r['status']) ?></td>
          <td><?= $r['used_username'] ? '@' . e($r['used_username']) . '<br><small class="text-muted">' . fdate($r['used_at']) . '</small>' : '—' ?></td>
          <td class="nowrap"><?= fdate($r['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows) echo empty_row(9); ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($p) ?>
</form>
<form id="code-filter" method="get"></form>
<?php page_footer();
