<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_member();
$uid = (int)$me['id'];

if (is_post() && input('action') === 'cancel') {
    csrf_check();
    $o = q_row("SELECT id FROM orders WHERE id = ? AND user_id = ? AND status = 'pending'", [input_int('id'), $uid]);
    if ($o) {
        cancel_order((int)$o['id']);
        flash('success', 'Order cancelled.');
    }
    redirect('member/orders.php');
}

$view = input_int('view');
$order = $view ? q_row('SELECT * FROM orders WHERE id = ? AND user_id = ?', [$view, $uid]) : null;
$p = paginate('SELECT COUNT(*) FROM orders WHERE user_id = ?', [$uid]);
$rows = q_all("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT {$p['limit']} OFFSET {$p['offset']}", [$uid]);

page_header('My Orders', $me, 'Track your product purchases');

if ($order):
    $items = q_all('SELECT * FROM order_items WHERE order_id = ?', [$order['id']]); ?>
<div class="card">
  <div class="card-header"><h3><?= icon('list') ?> Order <?= e($order['order_no']) ?></h3><div class="actions"><?= badge($order['status']) ?><a class="btn btn-light btn-sm" href="?">Close</a></div></div>
  <div class="card-body p-0 table-wrap">
    <table class="table stack">
      <thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>PV</th><th class="text-right">Subtotal</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr><td><?= e($it['product_name']) ?></td><td><?= (int)$it['qty'] ?></td><td><?= money($it['price']) ?></td><td><?= num($it['pv'] * $it['qty']) ?></td><td class="text-right"><?= money($it['price'] * $it['qty']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-body">
    <dl class="kv">
      <dt>Payment</dt><dd><?= e(strtoupper(str_replace('_', ' ', $order['payment_method']))) ?> <?= $order['payment_ref'] ? '· Ref ' . e($order['payment_ref']) : '' ?></dd>
      <dt>Discount</dt><dd class="text-gold"><?= money($order['discount']) ?></dd>
      <dt>Total</dt><dd><strong><?= money($order['total']) ?></strong> (<?= num($order['total_pv']) ?> PV)</dd>
      <dt>Deliver to</dt><dd><?= e($order['shipping_address']) ?></dd>
    </dl>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><h3><?= icon('cart') ?> Order History</h3><div class="actions"><a class="btn btn-primary btn-sm" href="<?= e(url('member/shop.php')) ?>">Shop now</a></div></div>
  <div class="card-body p-0 table-wrap">
    <table class="table stack">
      <thead><tr><th>Order #</th><th>Date</th><th>Payment</th><th>Total</th><th>PV</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><a href="?view=<?= (int)$r['id'] ?>"><strong><?= e($r['order_no']) ?></strong></a></td>
          <td class="nowrap"><?= fdatetime($r['created_at']) ?></td>
          <td><?= e(strtoupper(str_replace('_', ' ', $r['payment_method']))) ?></td>
          <td><?= money($r['total']) ?></td>
          <td><?= num($r['total_pv']) ?></td>
          <td><?= badge($r['status']) ?></td>
          <td class="nowrap">
            <a class="btn btn-xs btn-light" href="?view=<?= (int)$r['id'] ?>">View</a>
            <?php if ($r['status'] === 'pending'): ?>
              <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-xs btn-danger" data-confirm="Cancel this order?">Cancel</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows) echo empty_row(7, 'No orders yet.'); ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($p) ?>
</div>
<?php page_footer();
