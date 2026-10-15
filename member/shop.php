<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_member();

if (is_post()) {
    csrf_check();
    $pid = input_int('product_id');
    $qty = max(1, min(99, input_int('qty', 1)));
    $p = q_row("SELECT id, name, stock FROM products WHERE id = ? AND status = 'active'", [$pid]);
    if ($p && $p['stock'] > 0) {
        $_SESSION['cart'][$pid] = min((int)$p['stock'], ($_SESSION['cart'][$pid] ?? 0) + $qty);
        flash('success', "{$p['name']} added to cart.");
    } else {
        flash('error', 'Product is unavailable.');
    }
    redirect('member/shop.php' . (input('q') ? '?q=' . urlencode(input('q')) : ''));
}

$search = input('q');
$products = q_all("SELECT * FROM products WHERE status = 'active' AND (name LIKE ? OR sku LIKE ?) ORDER BY name", ["%{$search}%", "%{$search}%"]);

page_header('Products', $me, 'Shop at member price and earn');
?>
<div class="card">
  <div class="card-body">
    <form class="filters" method="get">
      <input class="input" name="q" value="<?= e($search) ?>" placeholder="Search products…" style="flex:1">
      <button class="btn btn-primary" type="submit"><?= icon('search', 16) ?> Search</button>
      <a class="btn btn-gold" href="<?= e(url('member/cart.php')) ?>"><?= icon('cart', 16) ?> Cart (<?= (int)array_sum($_SESSION['cart'] ?? []) ?>)</a>
    </form>
  </div>
</div>
<div class="product-grid">
  <?php foreach ($products as $p): $save = $p['srp'] - $p['member_price']; ?>
    <div class="product">
      <div class="product-img"><img src="<?= e(product_image($p['image'])) ?>" alt="<?= e($p['name']) ?>" loading="lazy"></div>
      <div class="product-body">
        <h4><?= e($p['name']) ?></h4>
        <p><?= e($p['description']) ?></p>
        <div class="price-row"><span class="price"><?= money($p['member_price']) ?></span><span class="srp">SRP <?= money($p['srp']) ?></span></div>
        <div class="price-row small">
          <?php if ($save > 0): ?><span class="badge badge-gold">Save <?= money($save) ?></span><?php endif; ?>
          <span class="badge badge-success"><?= num($p['pv'], 0) ?> PV</span>
          <span class="text-muted"><?= (int)$p['stock'] ?> in stock</span>
        </div>
        <form method="post" class="filters mt-1">
          <?= csrf_field() ?>
          <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
          <input class="input qty-input" type="number" name="qty" value="1" min="1" max="<?= max(1, (int)$p['stock']) ?>" aria-label="Quantity">
          <button class="btn btn-primary btn-sm" style="flex:1" type="submit" <?= $p['stock'] < 1 ? 'disabled' : '' ?>><?= icon('cart', 14) ?> <?= $p['stock'] < 1 ? 'Out of stock' : 'Add' ?></button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php if (!$products): ?><div class="card"><div class="card-body text-center text-muted">No products found.</div></div><?php endif; ?>
<?php page_footer();
