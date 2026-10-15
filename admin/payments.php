<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

if (is_post()) {
    csrf_check();
    $pay = q_row('SELECT * FROM payments WHERE id = ?', [input_int('id')]);
    try {
        if (!$pay || $pay['status'] !== 'pending') {
            throw new RuntimeException('Payment not found or not pending.');
        }
        if (input('action') === 'verify') {
            if (!$pay['checkout_id'] || !paymongo_session_paid($pay['checkout_id'])) {
                throw new RuntimeException('PayMongo reports this checkout as not yet paid.');
            }
            fulfill_payment((int)$pay['id']);
            flash('success', "Payment {$pay['reference']} confirmed as paid.");
        } elseif (input('action') === 'cancel') {
            q("UPDATE payments SET status = 'cancelled' WHERE id = ? AND status = 'pending'", [$pay['id']]);
            if ($pay['order_id']) {
                cancel_order((int)$pay['order_id']);
            }
            flash('success', "Payment {$pay['reference']} cancelled.");
        }
        log_activity((int)$me['id'], input('action') . " payment {$pay['reference']}");
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('admin/payments.php?' . http_build_query(array_intersect_key($_GET, array_flip(['status', 'q']))));
}

$status = in_array(input('status'), ['pending', 'paid', 'failed', 'cancelled'], true) ? input('status') : '';
$search = input('q');
$where = ['1=1'];
$params = [];
if ($status) { $where[] = 'p.status = ?'; $params[] = $status; }
if ($search !== '') { $where[] = '(p.reference LIKE ? OR u.username LIKE ? OR p.checkout_id LIKE ?)'; array_push($params, "%$search%", "%$search%", "%$search%"); }
$w = implode(' AND ', $where);
$base = "FROM payments p JOIN users u ON u.id = p.user_id LEFT JOIN orders o ON o.id = p.order_id LEFT JOIN packages pk ON pk.id = p.package_id WHERE $w";
$pg = paginate("SELECT COUNT(*) $base", $params);
$rows = q_all("SELECT p.*, u.username, o.order_no, pk.name package_name $base ORDER BY p.id DESC LIMIT {$pg['limit']} OFFSET {$pg['offset']}", $params);

page_header('Online Payments', $me, 'PayMongo checkout transactions');
?>
<div class="tabs">
  <a href="?" class="<?= $status === '' ? 'active' : '' ?>">All</a>
  <?php foreach (['pending', 'paid', 'cancelled'] as $s): ?><a href="?status=<?= $s ?>" class="<?= $status === $s ? 'active' : '' ?>"><?= ucfirst($s) ?></a><?php endforeach; ?>
</div>
<div class="card">
  <div class="card-header"><form class="filters" method="get"><input type="hidden" name="status" value="<?= e($status) ?>"><input class="input" name="q" value="<?= e($search) ?>" placeholder="Reference, username, checkout ID"><button class="btn btn-primary btn-sm">Search</button></form></div>
  <div class="card-body p-0 table-wrap">
    <table class="table stack">
      <thead><tr><th>Reference</th><th>Member</th><th>For</th><th>Amount</th><th>Status</th><th>Date</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="mono small"><?= e($r['reference']) ?><br><span class="text-muted"><?= e($r['checkout_id']) ?></span></td>
          <td>@<?= e($r['username']) ?></td>
          <td><?= $r['purpose'] === 'order' ? 'Order ' . e($r['order_no']) : (int)$r['qty'] . ' × ' . e($r['package_name']) . ' code(s)' ?></td>
          <td><strong><?= money($r['amount']) ?></strong></td>
          <td><?= badge($r['status']) ?></td>
          <td class="nowrap"><?= fdatetime($r['created_at']) ?><?= $r['paid_at'] ? '<br><small class="text-muted">Paid ' . fdatetime($r['paid_at']) . '</small>' : '' ?></td>
          <td class="nowrap">
            <?php if ($r['status'] === 'pending'): ?>
              <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-xs btn-primary" name="action" value="verify">Check PayMongo</button>
                <button class="btn btn-xs btn-danger" name="action" value="cancel" data-confirm="Cancel this pending payment?">Cancel</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows) echo empty_row(7, 'No online payments yet.'); ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($pg) ?>
</div>
<?php page_footer();
