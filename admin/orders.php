<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();
$statuses = ['pending', 'paid', 'shipped', 'completed', 'cancelled'];

if (is_post()) {
    csrf_check();
    $id = input_int('id');
    $new = input('status');
    try {
        $o = q_row('SELECT * FROM orders WHERE id = ?', [$id]);
        if (!$o || !in_array($new, $statuses, true)) {
            throw new RuntimeException('Invalid order or status.');
        }
        if ($new === 'cancelled') {
            cancel_order($id);
        } elseif ($o['status'] === 'cancelled') {
            throw new RuntimeException('Cancelled orders cannot be reopened.');
        } else {
            tx(function () use ($id, $new, $o) {
                q('UPDATE orders SET status = ?, paid_at = COALESCE(paid_at, IF(? <> \'pending\', NOW(), NULL)) WHERE id = ?', [$new, $new, $id]);
                if ($new !== 'pending') {
                    process_order_commission($id);
                }
            });
        }
        log_activity((int)$me['id'], "Order {$o['order_no']} → {$new}");
        flash('success', "Order {$o['order_no']} updated to {$new}.");
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('admin/orders.php?' . http_build_query(array_intersect_key($_GET, array_flip(['status', 'q', 'view']))));
}

$status = in_array(input('status'), $statuses, true) ? input('status') : '';
$search = input('q');
$where = ['1=1'];
$params = [];
if ($status) { $where[] = 'o.status = ?'; $params[] = $status; }
if ($search !== '') { $where[] = '(o.order_no LIKE ? OR u.username LIKE ? OR o.payment_ref LIKE ?)'; array_push($params, "%$search%", "%$search%", "%$search%"); }
$w = implode(' AND ', $where);
$p = paginate("SELECT COUNT(*) FROM orders o JOIN users u ON u.id = o.user_id WHERE $w", $params);
$rows = q_all("SELECT o.*, u.username, u.first_name, u.last_name FROM orders o JOIN users u ON u.id = o.user_id WHERE $w ORDER BY o.id DESC LIMIT {$p['limit']} OFFSET {$p['offset']}", $params);
$view = input_int('view') ? q_row('SELECT o.*, u.username, u.first_name, u.last_name, u.mobile FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ?', [input_int('view')]) : null;
$counts = array_column(q_all('SELECT status, COUNT(*) n FROM orders GROUP BY status'), 'n', 'status');

page_header('Orders', $me, 'Product orders and fulfillment');
?>
<div class="tabs">
  <a href="?" class="<?= $status === '' ? 'active' : '' ?>">All</a>
  <?php foreach ($statuses as $s): ?><a href="?status=<?= $s ?>" class="<?= $status === $s ? 'active' : '' ?>"><?= ucfirst($s) ?> (<?= (int)($counts[$s] ?? 0) ?>)</a><?php endforeach; ?>
</div>

<?php if ($view): $items = q_all('SELECT * FROM order_items WHERE order_id = ?', [$view['id']]); ?>
<div class="card">
  <div class="card-header"><h3><?= icon('list') ?> Order <?= e($view['order_no']) ?></h3><div class="actions"><?= badge($view['status']) ?><a class="btn btn-light btn-sm" href="?<?= e(http_build_query(array_diff_key($_GET, ['view' => 1]))) ?>">Close</a></div></div>
  <div class="card-body">
    <div class="grid grid-2">
      <dl class="kv">
        <dt>Member</dt><dd><?= e(full_name($view)) ?> (@<?= e($view['username']) ?>)</dd>
        <dt>Mobile</dt><dd><?= e($view['mobile']) ?></dd>
        <dt>Deliver to</dt><dd><?= e($view['shipping_address']) ?></dd>
        <dt>Payment</dt><dd><?= e(strtoupper(str_replace('_', ' ', $view['payment_method']))) ?> <?= $view['payment_ref'] ? '· Ref <strong>' . e($view['payment_ref']) . '</strong>' : '' ?></dd>
        <dt>Commission</dt><dd><?= $view['commission_processed'] ? '<span class="badge badge-success">Processed</span>' : '<span class="badge badge-warning">Not yet</span>' ?></dd>
      </dl>
      <div class="summary-box">
        <?php foreach ($items as $it): ?><div class="row"><span><?= (int)$it['qty'] ?> × <?= e($it['product_name']) ?></span><span><?= money($it['price'] * $it['qty']) ?></span></div><?php endforeach; ?>
        <div class="row text-gold"><span>Discount</span><span><?= money($view['discount']) ?></span></div>
        <div class="row"><span>PV</span><span><?= num($view['total_pv']) ?></span></div>
        <div class="row total"><span>Total</span><span><?= money($view['total']) ?></span></div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><form class="filters" method="get"><input type="hidden" name="status" value="<?= e($status) ?>"><input class="input" name="q" value="<?= e($search) ?>" placeholder="Order #, username, payment ref"><button class="btn btn-primary btn-sm">Search</button></form></div>
  <div class="card-body p-0 table-wrap">
    <table class="table stack">
      <thead><tr><th>Order #</th><th>Member</th><th>Date</th><th>Payment</th><th>Total</th><th>PV</th><th>Status</th><th>Update</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><a href="?<?= e(http_build_query(array_merge($_GET, ['view' => $r['id']]))) ?>"><strong><?= e($r['order_no']) ?></strong></a></td>
          <td><?= e(full_name($r)) ?><br><small class="text-muted">@<?= e($r['username']) ?></small></td>
          <td class="nowrap"><?= fdatetime($r['created_at']) ?></td>
          <td><?= e(strtoupper(str_replace('_', ' ', $r['payment_method']))) ?><?= $r['payment_ref'] ? '<br><small class="text-muted">' . e($r['payment_ref']) . '</small>' : '' ?></td>
          <td><?= money($r['total']) ?></td><td><?= num($r['total_pv']) ?></td>
          <td><?= badge($r['status']) ?></td>
          <td>
            <?php if ($r['status'] !== 'cancelled'): ?>
            <form method="post" class="filters" style="flex-wrap:nowrap">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <select class="input" name="status" style="min-width:110px;padding:6px 8px">
                <?php foreach ($statuses as $s): if ($s === 'pending' && $r['status'] !== 'pending') continue; ?><option <?= $r['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
              </select>
              <button class="btn btn-xs btn-primary" type="submit" data-confirm="Update this order's status? Marking as paid will release commissions.">Save</button>
            </form>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows) echo empty_row(8); ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($p) ?>
</div>
<?php page_footer();
