<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_member();
$uid = (int)$me['id'];

$enabled = setting('code_purchase_enabled', '1') === '1';
$walletOk = setting('code_purchase_wallet', '1') === '1';
$pmOk = paymongo_enabled();
$packages = q_all("SELECT * FROM packages WHERE status = 'active' ORDER BY price");

if (is_post() && $enabled) {
    csrf_check();
    $pkg = q_row("SELECT * FROM packages WHERE id = ? AND status = 'active'", [input_int('package_id')]);
    $qty = input_int('qty');
    try {
        if (!$pkg || $qty < 1 || $qty > 100) {
            throw new RuntimeException('Choose a package and quantity (1–100).');
        }
        $method = input('method');
        if ($method === 'wallet' && $walletOk) {
            $codes = purchase_codes_wallet($uid, (int)$pkg['id'], $qty);
            flash('success', count($codes) . " {$pkg['name']} code(s) added to My Codes.");
            redirect('member/codes.php');
        } elseif ($method === 'paymongo' && $pmOk) {
            $url = paymongo_start_checkout($uid, 'codes', [['name' => "{$pkg['name']} Activation Code", 'amount' => (float)$pkg['price'], 'quantity' => $qty]],
                "XGEN LIFE activation codes for @{$me['username']}", null, (int)$pkg['id'], $qty);
            redirect($url);
        }
        throw new RuntimeException('Please choose a payment method.');
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('member/buy_codes.php');
}

$history = q_all("SELECT p.*, pk.name package_name FROM payments p LEFT JOIN packages pk ON pk.id = p.package_id WHERE p.user_id = ? AND p.purpose = 'codes' ORDER BY p.id DESC LIMIT 10", [$uid]);

page_header('Buy Activation Codes', $me, 'Get codes to register your new members');
?>
<?php if (!$enabled || (!$walletOk && !$pmOk)): ?>
  <div class="alert alert-warning">Online code purchase is currently not available. Please contact the company to buy codes.</div>
<?php else: ?>
<form method="post">
  <?= csrf_field() ?>
  <div class="product-grid mb-2">
    <?php foreach ($packages as $i => $pk): ?>
      <label class="product pkg-pick">
        <input type="radio" name="package_id" value="<?= (int)$pk['id'] ?>" <?= $i === 0 ? 'checked' : '' ?>>
        <div class="product-body">
          <span class="badge badge-gold" style="align-self:flex-start"><?= e($pk['name']) ?></span>
          <span class="price"><?= money($pk['price']) ?></span>
          <p><?= e($pk['description']) ?></p>
          <div class="price-row small"><span class="badge badge-success"><?= num($pk['pv']) ?> PV</span></div>
        </div>
      </label>
    <?php endforeach; ?>
  </div>
  <div class="card">
    <div class="card-body">
      <div class="filters">
        <div><label for="qty">Quantity</label><input class="input qty-input" id="qty" type="number" name="qty" value="1" min="1" max="100" required></div>
        <div><label>Pay with</label>
          <div class="radio-row">
            <?php if ($walletOk): ?><label class="radio-pill"><input type="radio" name="method" value="wallet" checked><span>E-Wallet (<?= money($me['wallet_balance']) ?>)</span></label><?php endif; ?>
            <?php if ($pmOk): ?><label class="radio-pill"><input type="radio" name="method" value="paymongo" <?= $walletOk ? '' : 'checked' ?>><span>PayMongo (GCash / Maya / Card)</span></label><?php endif; ?>
          </div>
        </div>
        <button class="btn btn-primary" type="submit"><?= icon('cart', 16) ?> Buy Codes</button>
      </div>
    </div>
  </div>
</form>
<?php endif; ?>

<div class="card">
  <div class="card-header"><h3><?= icon('list') ?> Online Code Purchases</h3><div class="actions"><a class="btn btn-light btn-sm" href="<?= e(url('member/codes.php')) ?>">My Codes</a></div></div>
  <div class="card-body p-0 table-wrap">
    <table class="table stack">
      <thead><tr><th>Reference</th><th>Package</th><th>Qty</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach ($history as $h): ?>
        <tr><td class="mono small"><?= e($h['reference']) ?></td><td><?= e($h['package_name']) ?></td><td><?= (int)$h['qty'] ?></td><td><?= money($h['amount']) ?></td><td><?= badge($h['status']) ?></td><td class="nowrap"><?= fdatetime($h['created_at']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$history) echo empty_row(6, 'No online purchases yet. Wallet purchases appear in your E-Wallet history.'); ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_footer();
