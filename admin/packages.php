<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

if (is_post()) {
    csrf_check();
    $id = input_int('id');
    $data = [input('name'), round(input_float('price'), 2), round(input_float('pv'), 2), round(input_float('direct_bonus'), 2), input('description'),
        input('status') === 'inactive' ? 'inactive' : 'active'];
    if ($data[0] === '') {
        flash('error', 'Package name is required.');
    } elseif ($id) {
        q('UPDATE packages SET name = ?, price = ?, pv = ?, direct_bonus = ?, description = ?, status = ? WHERE id = ?', array_merge($data, [$id]));
        flash('success', 'Package updated.');
    } else {
        q('INSERT INTO packages (name, price, pv, direct_bonus, description, status) VALUES (?,?,?,?,?,?)', $data);
        flash('success', 'Package added.');
    }
    log_activity((int)$me['id'], 'Saved package ' . $data[0]);
    redirect('admin/packages.php');
}

$edit = input_int('edit') ? q_row('SELECT * FROM packages WHERE id = ?', [input_int('edit')]) : null;
$rows = q_all("SELECT p.*, (SELECT COUNT(*) FROM users u WHERE u.package_id = p.id AND u.role = 'member') members FROM packages p ORDER BY p.id");

page_header('Packages', $me, 'Membership / entry packages');
?>
<div class="grid grid-main">
  <div class="card">
    <div class="card-header"><h3><?= icon('package') ?> Packages</h3></div>
    <div class="card-body p-0 table-wrap">
      <table class="table stack">
        <thead><tr><th>Name</th><th>Price</th><th>PV</th><th>Direct Bonus</th><th>Members</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><strong><?= e($r['name']) ?></strong><br><small class="text-muted"><?= e($r['description']) ?></small></td>
            <td><?= money($r['price']) ?></td><td><?= num($r['pv']) ?></td><td class="text-green"><?= money($r['direct_bonus']) ?></td>
            <td><?= num($r['members']) ?></td><td><?= badge($r['status']) ?></td>
            <td><a class="btn btn-xs btn-outline" href="?edit=<?= (int)$r['id'] ?>">Edit</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h3><?= icon($edit ? 'settings' : 'plus') ?> <?= $edit ? 'Edit Package' : 'Add Package' ?></h3></div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <div class="form-group"><label>Name</label><input class="input" name="name" value="<?= e($edit['name'] ?? '') ?>" required></div>
        <div class="form-grid">
          <div class="form-group"><label>Price</label><input class="input" type="number" step="0.01" name="price" value="<?= e($edit['price'] ?? '') ?>" required></div>
          <div class="form-group"><label>PV (binary points)</label><input class="input" type="number" step="0.01" name="pv" value="<?= e($edit['pv'] ?? '') ?>" required></div>
          <div class="form-group"><label>Direct Referral Bonus</label><input class="input" type="number" step="0.01" name="direct_bonus" value="<?= e($edit['direct_bonus'] ?? '') ?>" required></div>
          <div class="form-group"><label>Status</label><select class="input" name="status"><option>active</option><option <?= ($edit['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>inactive</option></select></div>
        </div>
        <div class="form-group mt-1"><label>Description</label><input class="input" name="description" value="<?= e($edit['description'] ?? '') ?>"></div>
        <button class="btn btn-primary" type="submit">Save</button>
        <?php if ($edit): ?><a class="btn btn-light" href="?">Cancel</a><?php endif; ?>
      </form>
    </div>
  </div>
</div>
<?php page_footer();
