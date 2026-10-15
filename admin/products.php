<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

if (is_post()) {
    csrf_check();
    $id = input_int('id');
    try {
        $sku = strtoupper(input('sku'));
        if ($sku === '' || input('name') === '') {
            throw new RuntimeException('SKU and name are required.');
        }
        if (q_val('SELECT id FROM products WHERE sku = ? AND id <> ?', [$sku, $id])) {
            throw new RuntimeException('SKU already exists.');
        }
        $image = upload_image('image', 'products');
        $data = [$sku, input('name'), input('description'), round(input_float('srp'), 2), round(input_float('member_price'), 2),
            round(input_float('pv'), 2), input_int('stock'), input('status') === 'inactive' ? 'inactive' : 'active'];
        if ($id) {
            q('UPDATE products SET sku = ?, name = ?, description = ?, srp = ?, member_price = ?, pv = ?, stock = ?, status = ? WHERE id = ?', array_merge($data, [$id]));
            if ($image) {
                q('UPDATE products SET image = ? WHERE id = ?', [$image, $id]);
            }
            flash('success', 'Product updated.');
        } else {
            q('INSERT INTO products (sku, name, description, srp, member_price, pv, stock, status, image) VALUES (?,?,?,?,?,?,?,?,?)', array_merge($data, [$image]));
            flash('success', 'Product added.');
        }
        log_activity((int)$me['id'], "Saved product {$sku}");
        redirect('admin/products.php');
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
        redirect('admin/products.php' . ($id ? '?edit=' . $id : ''));
    }
}

$edit = input_int('edit') ? q_row('SELECT * FROM products WHERE id = ?', [input_int('edit')]) : null;
$search = input('q');
$rows = q_all('SELECT p.*, (SELECT COALESCE(SUM(oi.qty),0) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.product_id = p.id AND o.status <> \'cancelled\') sold
               FROM products p WHERE p.name LIKE ? OR p.sku LIKE ? ORDER BY p.id DESC', ["%$search%", "%$search%"]);

page_header('Products', $me, 'Catalog, pricing and inventory');
?>
<div class="grid grid-main">
  <div class="card">
    <div class="card-header"><h3><?= icon('box') ?> Product List</h3>
      <div class="actions"><form class="filters" method="get"><input class="input" name="q" value="<?= e($search) ?>" placeholder="Search"><button class="btn btn-primary btn-sm">Search</button></form></div></div>
    <div class="card-body p-0 table-wrap">
      <table class="table stack">
        <thead><tr><th>Product</th><th>SRP</th><th>Member</th><th>PV</th><th>Stock</th><th>Sold</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><div class="user-cell"><img class="thumb" src="<?= e(product_image($r['image'])) ?>" alt=""><div><strong><?= e($r['name']) ?></strong><small><?= e($r['sku']) ?></small></div></div></td>
            <td><?= money($r['srp']) ?></td><td class="text-green"><strong><?= money($r['member_price']) ?></strong></td><td><?= num($r['pv']) ?></td>
            <td><?= $r['stock'] < 10 ? '<span class="text-danger">' . (int)$r['stock'] . '</span>' : (int)$r['stock'] ?></td>
            <td><?= num($r['sold']) ?></td><td><?= badge($r['status']) ?></td>
            <td><a class="btn btn-xs btn-outline" href="?edit=<?= (int)$r['id'] ?>">Edit</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows) echo empty_row(8); ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h3><?= icon($edit ? 'settings' : 'plus') ?> <?= $edit ? 'Edit Product' : 'Add Product' ?></h3></div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <div class="form-grid">
          <div class="form-group"><label>SKU</label><input class="input" name="sku" value="<?= e($edit['sku'] ?? '') ?>" required></div>
          <div class="form-group"><label>Status</label><select class="input" name="status"><option>active</option><option <?= ($edit['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>inactive</option></select></div>
          <div class="form-group full"><label>Name</label><input class="input" name="name" value="<?= e($edit['name'] ?? '') ?>" required></div>
          <div class="form-group full"><label>Description</label><textarea class="input" name="description"><?= e($edit['description'] ?? '') ?></textarea></div>
          <div class="form-group"><label>SRP (retail)</label><input class="input" type="number" step="0.01" name="srp" value="<?= e($edit['srp'] ?? '') ?>" required></div>
          <div class="form-group"><label>Member Price</label><input class="input" type="number" step="0.01" name="member_price" value="<?= e($edit['member_price'] ?? '') ?>" required></div>
          <div class="form-group"><label>PV (unilevel base)</label><input class="input" type="number" step="0.01" name="pv" value="<?= e($edit['pv'] ?? '') ?>" required></div>
          <div class="form-group"><label>Stock</label><input class="input" type="number" name="stock" value="<?= e($edit['stock'] ?? 0) ?>" required></div>
          <div class="form-group full"><label>Image</label><input class="input" type="file" name="image" accept="image/*"><div class="help">JPG, PNG or WEBP, max 3MB.</div></div>
        </div>
        <button class="btn btn-primary mt-2" type="submit">Save Product</button>
        <?php if ($edit): ?><a class="btn btn-light mt-2" href="?">Cancel</a><?php endif; ?>
      </form>
    </div>
  </div>
</div>
<?php page_footer();
