<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ueat.php';
require_once __DIR__ . '/includes/marketplace.php';
$user = require_auth();
header('Cache-Control: no-store');
$reference = is_string($_GET['reference'] ?? null) ? $_GET['reference'] : '';
$error = '';
$stmt = $pdo->prepare('SELECT * FROM ueat_orders WHERE reference = ? AND user_id = ?');
$stmt->execute([$reference, $user['id']]);
$order = $stmt->fetch();
if (!$order) { http_response_code(404); exit('Order not found.'); }
$live = !empty($order['merchant_id']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    verify_csrf();
    rate_limit_enforce('ueat_cancel_order', 'user:' . $user['id'], 12, 3600);
    try {
        if ($live) mk_order_update($pdo, $reference, 'cancelled', 'customer', (int) $user['id']);
        else ueat_cancel_order($pdo, (int) $user['id'], $reference);
        redirect('ueat-order.php?reference=' . rawurlencode($reference));
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('UEat cancellation failed: ' . get_class($exception));
        $error = 'Could not cancel right now. No Credits were changed.';
    }
}
$itemsStmt = $pdo->prepare('SELECT item_name, quantity, unit_credits, line_credits FROM ueat_order_items WHERE order_id = ? ORDER BY id');
$itemsStmt->execute([$order['id']]);
$items = $itemsStmt->fetchAll();
$store = $live ? mk_store($pdo, (int) $order['merchant_id']) : null;
$name = $store['display_name'] ?? (ueat_catalog()[$order['restaurant_id']]['name'] ?? $order['restaurant_id']);
$flow = $order['fulfillment'] === 'delivery' ? ['pending' => 'Order placed', 'accepted' => 'Accepted', 'preparing' => 'Preparing', 'on_the_way' => 'On the way', 'completed' => 'Delivered']
    : ['pending' => 'Order placed', 'accepted' => 'Accepted', 'preparing' => 'Preparing', 'ready' => 'Ready for pick-up', 'completed' => 'Picked up'];
$keys = array_keys($flow);
$at = array_search($order['status'], $keys, true);
$ended = in_array($order['status'], ['cancelled', 'declined'], true);
$headline = [
    'pending' => ['Waiting for ' . $name, 'The restaurant will accept your order shortly.'],
    'accepted' => ['Order accepted', $name . ' accepted your order.'],
    'preparing' => ['Preparing your food', 'The kitchen is cooking. ' . ($order['fulfillment'] === 'delivery' ? 'It will be on the way soon.' : 'We will tell you when it is ready.')],
    'on_the_way' => ['On the way!', 'Your food is on the way to you.'],
    'ready' => ['Ready for pick-up', 'Your order is waiting at ' . $name . '.'],
    'completed' => ['Enjoy your meal!', 'Order completed. Thank you for ordering with UEat.'],
    'declined' => ['Order declined', 'The restaurant could not take this order. Your Credits were returned.'],
    'cancelled' => ['Order cancelled', 'Your Credits were returned to your wallet.'],
    'sample' => ['Sample order', 'This was a sample order.'],
][$order['status']] ?? [ucfirst($order['status']), ''];
$pageTitle = 'UEat order';
require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="assets/css/market.css?v=2">
<section class="screen ue-screen">
    <header class="page-head"><a href="ueat.php?view=orders" aria-label="Back to orders"><span data-icon="arrow-left"></span></a><h1>Order</h1><span></span></header>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
    <div class="ue-track <?= $ended ? 'ended' : '' ?>" <?= $live && !$ended && $order['status'] !== 'completed' ? 'data-ue-refresh="20"' : '' ?>>
        <span class="ue-track-ic"><span data-icon="<?= $ended ? 'circle-alert' : ($order['status'] === 'completed' ? 'check-circle' : ($order['status'] === 'on_the_way' ? 'bike' : 'utensils')) ?>"></span></span>
        <h2><?= e($headline[0]) ?></h2><p><?= e($headline[1]) ?></p>
        <?php if ($order['status_note']): ?><p class="mk-note"><b><?= $order['status'] === 'declined' ? 'Reason' : 'Note' ?>:</b> <?= e($order['status_note']) ?></p><?php endif; ?>
        <?php if ($live && !$ended): ?>
            <ol class="mk-progress ue-progress"><?php foreach ($flow as $k => $label): $i = array_search($k, $keys, true); ?><li class="<?= $at !== false && $i <= $at ? 'done' : '' ?><?= $i === $at ? ' now' : '' ?>"><?= e($label) ?></li><?php endforeach; ?></ol>
            <?php if (!in_array($order['status'], ['completed'], true) && $store): ?><p class="mk-fine">Estimated <?= e(date('g:i A', strtotime($order['created_at']) + 60 * ((int) $store['prep_minutes'] + ($order['fulfillment'] === 'delivery' ? 15 : 0)))) ?><?php if ($store['merchant_mobile']): ?> · <a href="tel:<?= e($store['merchant_mobile']) ?>">Call restaurant</a><?php endif; ?></p><?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="ue-sum ue-receipt">
        <h3><?= e($name) ?></h3>
        <?php foreach ($items as $item): ?><div><span><?= (int) $item['quantity'] ?> × <?= e($item['item_name']) ?></span><span><?= e(mk_money((int) round((float) $item['line_credits'] * 100))) ?></span></div><?php endforeach; ?>
        <?php if ($live): ?>
            <div><span>Subtotal</span><span><?= e(mk_money((int) $order['subtotal_centavos'])) ?></span></div>
            <?php if ($order['fulfillment'] === 'delivery'): ?><div><span>Delivery fee</span><span><?= (int) $order['delivery_fee_centavos'] ? e(mk_money((int) $order['delivery_fee_centavos'])) : 'Free' ?></span></div><?php endif; ?>
            <?php $charge = (int) round((float) $order['total_credits'] * 100) - (int) $order['subtotal_centavos'] - (int) $order['delivery_fee_centavos']; if ($charge > 0): ?><div><span>Payment charge</span><span><?= e(mk_money($charge)) ?></span></div><?php endif; ?>
        <?php endif; ?>
        <div class="tot"><span>Total <?= $order['payment_status'] === 'refunded' ? 'refunded' : 'paid' ?></span><span><?= e(mk_money((int) round((float) $order['total_credits'] * 100))) ?></span></div>
    </div>
    <dl class="mk-dl">
        <div><dt>Reference</dt><dd><?= e($order['reference']) ?></dd></div>
        <div><dt><?= $order['fulfillment'] === 'delivery' ? 'Deliver to' : 'Pick up at' ?></dt><dd><?= e($order['fulfillment'] === 'delivery' ? (string) $order['delivery_address'] : ($store ? $store['display_name'] . ($store['address'] ? ', ' . $store['address'] : '') : $name)) ?></dd></div>
        <div><dt>Placed</dt><dd><?= e(date('M j, Y g:i A', strtotime($order['created_at']))) ?></dd></div>
        <div><dt>Payment</dt><dd>Credits · <?= e(ucfirst($order['payment_status'])) ?></dd></div>
        <?php if ($order['notes']): ?><div><dt>Note</dt><dd><?= e($order['notes']) ?></dd></div><?php endif; ?>
    </dl>
    <?php if ($order['status'] === 'pending'): ?><form method="post" data-confirm="Cancel this order? Your Credits will be returned."><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="cancel"><button class="mk-ghost" type="submit">Cancel order</button></form><?php endif; ?>
    <a class="mk-cta" href="<?= $live && $store ? 'ueat.php?r=' . (int) $order['merchant_id'] : 'ueat.php' ?>"><?= $order['status'] === 'completed' ? 'Order again' : 'Back to UEat' ?></a>
</section>
<script defer src="assets/js/market.js?v=1"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
