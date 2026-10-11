<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/marketplace.php';
$m = merchant_require();
$id = (int) $m['id'];
$tab = in_array(mq('tab'), ['food', 'bookings', 'history'], true) ? mq('tab') : '';
if (!mk_ready()) { portal_header('Orders', 'orders'); echo '<div class="flash error">Orders are being set up. Please check again later.</div>'; portal_footer(); exit; }
$waiting = mk_merchant_waiting($pdo, $id);
if ($tab === '') $tab = $waiting['orders'] || !$waiting['bookings'] && mk_store($pdo, $id) ? 'food' : 'bookings';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('merchant_orders', 'merchant:' . $id, 300, 3600);
    try {
        if (mp('kind') === 'order') {
            $r = mk_order_update($pdo, mp('ref'), mp('to'), 'merchant', $id, mp('note'));
            mflash('success', mp('ref') . ': ' . (mk_order_statuses()[$r['status']] ?? $r['status']) . ($r['status'] === 'declined' ? '. The customer was refunded.' : '.'));
        } else {
            $r = mk_booking_update($pdo, mp('ref'), mp('to'), 'merchant', $id, mp('note'));
            mflash('success', mp('ref') . ': ' . mk_booking_statuses()[$r['status']] . ($r['refunded'] ? '. The customer was refunded.' : '.'));
        }
    } catch (InvalidArgumentException $ex) {
        mflash('error', $ex->getMessage());
    }
    redirect('orders.php?tab=' . $tab);
}
$orders = [];
if ($tab === 'food') {
    $s = $pdo->prepare("SELECT o.*, u.full_name FROM ueat_orders o JOIN users u ON u.id = o.user_id WHERE o.merchant_id = ? AND o.status IN ('pending','accepted','preparing','on_the_way','ready') ORDER BY FIELD(o.status,'pending','accepted','preparing','ready','on_the_way'), o.id");
    $s->execute([$id]); $orders = $s->fetchAll();
    $items = [];
    if ($orders) {
        $ids = implode(',', array_map(static fn($o) => (int) $o['id'], $orders));
        foreach ($pdo->query("SELECT order_id, item_name, quantity FROM ueat_order_items WHERE order_id IN ($ids) ORDER BY id") as $it) $items[(int) $it['order_id']][] = $it;
    }
}
$bookings = [];
if ($tab === 'bookings') {
    $s = $pdo->prepare("SELECT b.*, u.full_name FROM mk_bookings b JOIN users u ON u.id = b.user_id WHERE b.merchant_id = ? AND b.status IN ('pending','confirmed') ORDER BY b.status = 'pending' DESC, b.booking_date, b.booking_time");
    $s->execute([$id]); $bookings = $s->fetchAll();
}
$history = [];
if ($tab === 'history') {
    $s = $pdo->prepare("(SELECT 'order' kind, o.reference, o.status, o.created_at, o.total_credits * 100 amount, CONCAT(o.fulfillment, ' · ', u.full_name) detail, NULL bdate FROM ueat_orders o JOIN users u ON u.id = o.user_id WHERE o.merchant_id = ? AND o.status IN ('completed','declined','cancelled'))
        UNION ALL (SELECT 'booking', b.reference, b.status, b.created_at, b.total_centavos, CONCAT(b.title, ' · ', u.full_name), b.booking_date FROM mk_bookings b JOIN users u ON u.id = b.user_id WHERE b.merchant_id = ? AND b.status IN ('completed','declined','cancelled'))
        ORDER BY created_at DESC LIMIT 100");
    $s->execute([$id, $id]); $history = $s->fetchAll();
}
$btn = static function (string $kind, string $ref, string $to, string $label, bool $primary = true, bool $askNote = false): string {
    $h = '<form method="post" class="inline' . ($askNote ? ' mk-decline' : '') . '"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="kind" value="' . $kind . '"><input type="hidden" name="ref" value="' . e($ref) . '"><input type="hidden" name="to" value="' . e($to) . '">';
    if ($askNote) $h .= '<input name="note" required minlength="3" maxlength="200" placeholder="Reason (e.g. sold out)" aria-label="Reason">';
    return $h . '<button class="btn small ' . ($primary ? 'primary' : ($askNote ? 'danger' : '')) . '" type="submit">' . e($label) . '</button></form>';
};
portal_header('Orders & bookings', 'orders');
?>
<link rel="stylesheet" href="../assets/css/market-admin.css?v=1">
<div class="tabs"><a class="<?= $tab === 'food' ? 'active' : '' ?>" href="?tab=food">UEat food orders<span><?= $waiting['orders'] ?></span></a><a class="<?= $tab === 'bookings' ? 'active' : '' ?>" href="?tab=bookings">UGo / ULocal bookings<span><?= $waiting['bookings'] ?></span></a><a class="<?= $tab === 'history' ? 'active' : '' ?>" href="?tab=history">History</a></div>

<?php if ($tab === 'food'): ?>
    <p class="muted" data-mk-refresh="30">New orders appear here automatically. Accept quickly: customers can cancel until you accept. Declining refunds the customer in full.</p>
    <?php if (!$orders): ?><div class="card empty"><h2>No open orders</h2><p class="muted">Keep this page open during service hours.</p><a class="btn" href="listings.php?s=ueat">Menu &amp; opening hours</a></div><?php endif; ?>
    <div class="mk-board">
    <?php foreach ($orders as $o): $next = mk_order_next($o); ?>
        <article class="card mk-ticket-card s-<?= e($o['status']) ?>">
            <header><b><?= e($o['reference']) ?></b><span class="badge <?= $o['status'] === 'pending' ? 'warn' : 'info' ?>"><?= e(mk_order_statuses()[$o['status']]) ?></span></header>
            <p class="mk-when"><?= e(date('g:i A', strtotime($o['created_at']))) ?> · <?= $o['fulfillment'] === 'delivery' ? '🛵 Delivery' : '🛍 Pick-up' ?> · <?= e(explode(' ', $o['full_name'])[0]) ?></p>
            <ul class="mk-items"><?php foreach ($items[(int) $o['id']] ?? [] as $it): ?><li><b><?= (int) $it['quantity'] ?>×</b> <?= e($it['item_name']) ?></li><?php endforeach; ?></ul>
            <?php if ($o['notes']): ?><p class="mk-cust-note">“<?= e($o['notes']) ?>”</p><?php endif; ?>
            <dl class="mk-kv"><?php if ($o['delivery_address']): ?><div><dt>Deliver to</dt><dd><?= e($o['delivery_address']) ?></dd></div><?php endif; ?><div><dt>Mobile</dt><dd><a href="tel:<?= e((string) $o['contact_mobile']) ?>"><?= e((string) $o['contact_mobile']) ?></a></dd></div><div><dt>Paid</dt><dd>₱<?= e(number_format((int) $o['subtotal_centavos'] / 100 + (int) $o['delivery_fee_centavos'] / 100, 2)) ?> <small>(food ₱<?= e(number_format((int) $o['subtotal_centavos'] / 100, 2)) ?><?= (int) $o['delivery_fee_centavos'] ? ' + delivery ₱' . e(number_format((int) $o['delivery_fee_centavos'] / 100, 2)) : '' ?>)</small></dd></div></dl>
            <div class="mk-acts"><?php foreach ($next as $to => $label): ?><?= $to === 'declined' ? $btn('order', $o['reference'], 'declined', 'Decline', false, true) : $btn('order', $o['reference'], $to, $label) ?><?php endforeach; ?></div>
        </article>
    <?php endforeach; ?>
    </div>

<?php elseif ($tab === 'bookings'): ?>
    <p class="muted" data-mk-refresh="60">Confirm or decline new requests. Declining or a customer cancelling in time refunds Credits in full. Mark it completed after the activity or service.</p>
    <?php if (!$bookings): ?><div class="card empty"><h2>No open bookings</h2><a class="btn" href="listings.php">Manage listings</a></div><?php endif; ?>
    <div class="table-wrap"><table><thead><tr><th>When</th><th>Booking</th><th>Customer</th><th class="num">Total</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($bookings as $b): ?>
        <tr><td><b><?= e(date('D, M j', strtotime($b['booking_date']))) ?></b><small><?= $b['booking_time'] ? e(date('g:i A', strtotime($b['booking_time']))) : '' ?></small></td>
            <td><b><?= e($b['title']) ?></b><small><?= e($b['reference']) ?> · <?= (int) $b['quantity'] ?> <?= $b['service'] === 'ugo' ? 'guest(s)' : '×' ?><?= $b['address'] ? ' · ' . e($b['address']) : '' ?></small><?php if ($b['notes']): ?><small>“<?= e($b['notes']) ?>”</small><?php endif; ?></td>
            <td><?= e($b['full_name']) ?><small><a href="tel:<?= e((string) $b['contact_mobile']) ?>"><?= e((string) $b['contact_mobile']) ?></a></small></td>
            <td class="num">₱<?= e(number_format((int) $b['total_centavos'] / 100, 2)) ?><small><?= $b['pay_method'] === 'credits' ? 'Paid · Credits' : 'Collect on the day' ?></small></td>
            <td><span class="badge <?= $b['status'] === 'pending' ? 'warn' : 'info' ?>"><?= e(mk_booking_statuses()[$b['status']]) ?></span></td>
            <td class="mk-acts"><?php if ($b['status'] === 'pending'): ?><?= $btn('booking', $b['reference'], 'confirm', 'Confirm') ?> <?= $btn('booking', $b['reference'], 'decline', 'Decline', false, true) ?><?php elseif ($b['booking_date'] <= date('Y-m-d')): ?><?= $btn('booking', $b['reference'], 'complete', 'Mark completed') ?><?php else: ?><small class="muted">Complete on the day</small><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>

<?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Reference</th><th>Details</th><th>Date</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody>
    <?php if (!$history): ?><tr><td colspan="5" class="muted">No finished orders or bookings yet.</td></tr><?php endif; ?>
    <?php foreach ($history as $h): ?><tr><td><b><?= e($h['reference']) ?></b><small><?= $h['kind'] === 'order' ? 'UEat' : 'Booking' ?></small></td><td><?= e($h['detail']) ?></td><td><?= e(date('M j, Y', strtotime($h['bdate'] ?: $h['created_at']))) ?></td><td class="num">₱<?= e(number_format((float) $h['amount'] / 100, 2)) ?></td><td><?= portal_badge($h['status']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
<?php portal_footer(['assets/orders.js?v=1']); ?>
