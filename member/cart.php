<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_member();
$uid = (int)$me['id'];
$cart = $_SESSION['cart'] ?? [];

if (is_post()) {
    csrf_check();
    $action = input('action');
    if ($action === 'update') {
        foreach ((array)($_POST['qty'] ?? []) as $pid => $qty) {
            $qty = (int)$qty;
            if ($qty < 1) {
                unset($_SESSION['cart'][(int)$pid]);
            } else {
                $_SESSION['cart'][(int)$pid] = min(99, $qty);
            }
        }
        flash('success', 'Cart updated.');
    } elseif ($action === 'remove') {
        unset($_SESSION['cart'][input_int('product_id')]);
    } elseif ($action === 'checkout') {
        try {
            $method = input('payment_method');
            $orderId = create_order($uid, $cart, $method, input('shipping_address'), input('payment_ref'));
            $_SESSION['cart'] = [];
            if ($method === 'paymongo') {
                $o = q_row('SELECT * FROM orders WHERE id = ?', [$orderId]);
                $items = array_map(fn($it) => ['name' => $it['product_name'], 'amount' => (float)$it['price'], 'quantity' => (int)$it['qty']],
                    q_all('SELECT * FROM order_items WHERE order_id = ?', [$orderId]));
                try {
                    redirect(paymongo_start_checkout($uid, 'order', $items, "XGEN LIFE order {$o['order_no']}", $orderId));
                } catch (RuntimeException $ex) {
                    cancel_order($orderId);
                    throw $ex;
                }
            }
            $o = q_row('SELECT order_no, status FROM orders WHERE id = ?', [$orderId]);
            flash('success', "Order {$o['order_no']} placed" . ($o['status'] === 'paid' ? ' and paid from your wallet.' : '. Waiting for payment confirmation.'));
            redirect('member/orders.php');
        } catch (RuntimeException $ex) {
            flash('error', $ex->getMessage());
        }
    }
    redirect('member/cart.php');
}

$items = [];
$srp = $total = $pv = 0.0;
if ($cart) {
    $in = implode(',', array_fill(0, count($cart), '?'));
    foreach (q_all("SELECT * FROM products WHERE id IN ($in) AND status = 'active'", array_keys($cart)) as $p) {
        $qty = (int)$cart[$p['id']];
        $items[] = $p + ['qty' => $qty];
        $srp += $p['srp'] * $qty;
        $total += $p['member_price'] * $qty;
        $pv += $p['pv'] * $qty;
    }
}
$methods = setting_list('order_payment_methods');
if (paymongo_enabled()) {
    $methods[] = 'paymongo';
}
$labels = ['paymongo' => paymongo_label(), 'wallet' => 'E-Wallet', 'cash' => 'Cash / Pick-up', 'bank' => 'Bank Deposit', 'gcash' => 'GCash', 'maya' => 'Maya'];

page_header('My Cart', $me, 'Review items and checkout');
?>
<div class="grid grid-main">
  <form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update">
    <div class="card-header"><h3><?= icon('cart') ?> Cart Items</h3><div class="actions"><a href="<?= e(url('member/shop.php')) ?>" class="btn btn-light btn-sm">Continue shopping</a></div></div>
    <div class="card-body p-0 table-wrap">
      <table class="table stack">
        <thead><tr><th>Product</th><th>Price</th><th>Qty</th><th class="text-right">Subtotal</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><div class="user-cell"><img class="thumb" src="<?= e(product_image($it['image'])) ?>" alt=""><div><strong><?= e($it['name']) ?></strong><small><?= num($it['pv']) ?> PV each</small></div></div></td>
            <td><?= money($it['member_price']) ?><br><span class="srp"><?= money($it['srp']) ?></span></td>
            <td><input class="input qty-input" type="number" min="0" max="99" name="qty[<?= (int)$it['id'] ?>]" value="<?= (int)$it['qty'] ?>"></td>
            <td class="text-right"><strong><?= money($it['member_price'] * $it['qty']) ?></strong></td>
            <td><button class="btn btn-xs btn-light" form="rm-<?= (int)$it['id'] ?>" type="submit">Remove</button></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$items) echo empty_row(5, 'Your cart is empty.'); ?>
        </tbody>
      </table>
    </div>
    <?php if ($items): ?><div class="card-body"><button class="btn btn-outline btn-sm" type="submit">Update cart</button></div><?php endif; ?>
  </form>
  <?php foreach ($items as $it): ?>
    <form id="rm-<?= (int)$it['id'] ?>" method="post" hidden><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="product_id" value="<?= (int)$it['id'] ?>"></form>
  <?php endforeach; ?>

  <div class="card">
    <div class="card-header"><h3><?= icon('wallet') ?> Checkout</h3></div>
    <div class="card-body">
      <div class="summary-box mb-2">
        <div class="row"><span>Total SRP</span><span><?= money($srp) ?></span></div>
        <div class="row text-gold"><span>Member Discount</span><span>- <?= money($srp - $total) ?></span></div>
        <div class="row"><span>Total PV</span><span><?= num($pv) ?></span></div>
        <div class="row total"><span>Amount to Pay</span><span><?= money($total) ?></span></div>
      </div>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="checkout">
        <label>Payment Method</label>
        <div class="radio-row mb-2">
          <?php foreach ($methods as $i => $m): ?>
            <label class="radio-pill"><input type="radio" name="payment_method" value="<?= e($m) ?>" <?= $i === 0 ? 'checked' : '' ?>><span><?= e($labels[$m] ?? ucfirst($m)) ?></span></label>
          <?php endforeach; ?>
        </div>
        <p class="small text-muted">Wallet balance: <strong><?= money($me['wallet_balance']) ?></strong></p>
        <div id="payment-ref-box" class="form-group">
          <label for="payment_ref">Payment Reference No.</label>
          <input class="input" id="payment_ref" name="payment_ref" placeholder="e.g. GCash ref number">
          <div class="help"><?= e(setting('company_payment_info')) ?></div>
        </div>
        <div class="form-group">
          <label for="shipping_address">Delivery Address</label>
          <textarea class="input" id="shipping_address" name="shipping_address" required><?= e($me['address']) ?></textarea>
        </div>
        <button class="btn btn-primary btn-block" type="submit" <?= $items ? '' : 'disabled' ?>>Place Order</button>
      </form>
    </div>
  </div>
</div>
<?php page_footer();
