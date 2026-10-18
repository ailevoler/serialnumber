<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_member();
$uid = (int)$me['id'];

$enabled = upgrade_enabled();
$walletOk = setting('upgrade_wallet', '1') === '1';
$pmOk = paymongo_enabled();
$targets = upgrade_targets($me);
$current = $me['package_id'] ? q_row('SELECT * FROM packages WHERE id = ?', [(int)$me['package_id']]) : null;

if (is_post() && $enabled) {
    csrf_check();
    try {
        $method = input('method');
        if ($method === 'code') {
            upgrade_with_code($uid, input('code'), input('pin'));
            $new = q_val('SELECT p.name FROM users u JOIN packages p ON p.id = u.package_id WHERE u.id = ?', [$uid]);
            flash('success', "Congratulations! Your account is now {$new}.");
            redirect('member/upgrade.php');
        }
        $to = null;
        foreach ($targets as $t) {
            if ((int)$t['id'] === input_int('package_id')) {
                $to = $t;
            }
        }
        if (!$to) {
            throw new RuntimeException('Please choose a package to upgrade to.');
        }
        $qt = upgrade_quote($me, $to);
        if ($method === 'wallet' && $walletOk) {
            upgrade_member($uid, (int)$to['id'], 'wallet');
            flash('success', "Congratulations! Your account is now {$to['name']}. " . money($qt['amount']) . ' was deducted from your E-Wallet.');
            redirect('member/upgrade.php');
        } elseif ($method === 'paymongo' && $pmOk) {
            $url = paymongo_start_checkout($uid, 'upgrade', [['name' => 'Package upgrade ' . ($current['name'] ?? '') . " → {$to['name']}", 'amount' => $qt['amount'], 'quantity' => 1]],
                "XGEN LIFE package upgrade for @{$me['username']}", null, (int)$to['id'], 1);
            redirect($url);
        }
        throw new RuntimeException('Please choose a payment method.');
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('member/upgrade.php');
}

$history = q_all('SELECT u.*, f.name from_name, t.name to_name FROM package_upgrades u LEFT JOIN packages f ON f.id = u.from_package_id JOIN packages t ON t.id = u.to_package_id
                  WHERE u.user_id = ? ORDER BY u.id DESC', [$uid]);
$pending = q_all("SELECT p.*, pk.name package_name FROM payments p LEFT JOIN packages pk ON pk.id = p.package_id WHERE p.user_id = ? AND p.purpose = 'upgrade' AND p.status = 'pending' ORDER BY p.id DESC LIMIT 5", [$uid]);
$methods = upgrade_methods();

page_header('Upgrade Package', $me, 'Move up to a higher package — pay only the difference');
?>
<div class="card">
  <div class="card-body upg-current">
    <div><small class="text-muted">Your current package</small><strong><?= e($current['name'] ?? 'None') ?></strong></div>
    <div><small class="text-muted">Product discount</small><strong><?= $current ? num($current['discount_percent'], 0) . '%' : '—' ?></strong></div>
    <div><small class="text-muted">Package PV</small><strong><?= $current ? num($current['pv']) : '—' ?></strong></div>
    <div><small class="text-muted">E-Wallet</small><strong><?= money($me['wallet_balance']) ?></strong></div>
  </div>
</div>

<?php if (!$enabled): ?>
  <div class="alert alert-warning">Package upgrades are currently not available. Please contact the company.</div>
<?php elseif (!$targets): ?>
  <div class="alert alert-success">You already have the highest package. Salamat sa tiwala!</div>
<?php else: ?>
<form method="post" id="upgradeForm">
  <?= csrf_field() ?>
  <div class="product-grid mb-2">
    <?php foreach ($targets as $i => $t): $qt = upgrade_quote($me, $t); ?>
      <label class="product pkg-pick">
        <input type="radio" name="package_id" value="<?= (int)$t['id'] ?>" <?= $i === 0 ? 'checked' : '' ?>>
        <div class="product-body">
          <span class="badge badge-gold" style="align-self:flex-start"><?= e(($current['name'] ?? '') . ' → ' . $t['name']) ?></span>
          <span class="price"><?= money($qt['amount']) ?></span>
          <p class="small mb-0">Pay only the difference (<?= e($t['name']) ?> <?= money($t['price']) ?><?= $current ? ' less ' . e($current['name']) . ' ' . money($current['price']) : '' ?>).</p>
          <ul class="upg-perks">
            <li><?= num($t['discount_percent'], 0) ?>% product discount<?= $current ? ' (from ' . num($current['discount_percent'], 0) . '%)' : '' ?></li>
            <li>+<?= num($qt['pv']) ?> PV to your upline's binary</li>
            <li>Pairing value <?= num($t['pv']) ?> per match</li>
          </ul>
        </div>
      </label>
    <?php endforeach; ?>
  </div>
  <div class="card">
    <div class="card-body">
      <div class="filters">
        <div><label>Pay with</label>
          <div class="radio-row">
            <?php if ($walletOk): ?><label class="radio-pill"><input type="radio" name="method" value="wallet" checked><span>E-Wallet (<?= money($me['wallet_balance']) ?>)</span></label><?php endif; ?>
            <?php if ($pmOk): ?><label class="radio-pill"><input type="radio" name="method" value="paymongo" <?= $walletOk ? '' : 'checked' ?>><span><?= e(paymongo_label()) ?></span></label><?php endif; ?>
          </div>
        </div>
        <?php if ($walletOk || $pmOk): ?>
          <button class="btn btn-primary" type="submit" data-confirm="Upgrade your package now?"><?= icon('arrow-up', 16) ?> Upgrade Now</button>
        <?php else: ?>
          <span class="text-muted small">Online payment is off — use an Upgrade Code below.</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</form>

<div class="card">
  <div class="card-header"><h3><?= icon('key') ?> Have an Upgrade Code?</h3></div>
  <div class="card-body">
    <form method="post" class="filters">
      <?= csrf_field() ?><input type="hidden" name="method" value="code">
      <div><label for="upg_code">Upgrade Code</label><input class="input mono" id="upg_code" name="code" placeholder="XGXXXXXXXX" required></div>
      <div><label for="upg_pin">PIN</label><input class="input mono" id="upg_pin" name="pin" inputmode="numeric" required></div>
      <button class="btn btn-gold" type="submit"><?= icon('arrow-up', 16) ?> Redeem</button>
    </form>
    <p class="help mb-0 mt-1">Upgrade codes are issued by the company or a stockist. The code's package becomes your new package.</p>
  </div>
</div>
<?php endif; ?>

<?php if ($pending): ?>
<div class="alert alert-info">Waiting for payment confirmation: <?php foreach ($pending as $p): ?><span class="mono"><?= e($p['reference']) ?></span> (<?= e($p['package_name']) ?>, <?= money($p['amount']) ?>) <?php endforeach; ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><h3><?= icon('list') ?> Upgrade History</h3></div>
  <div class="card-body p-0 table-wrap">
    <table class="table stack">
      <thead><tr><th>Date</th><th>Upgrade</th><th>Paid with</th><th>Reference</th><th class="text-right">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($history as $h): ?>
        <tr><td class="nowrap"><?= fdatetime($h['created_at']) ?></td><td><?= e(($h['from_name'] ?? '—') . ' → ' . $h['to_name']) ?></td><td><?= e($methods[$h['method']] ?? $h['method']) ?></td>
          <td class="mono small"><?= e($h['reference']) ?></td><td class="text-right"><?= money($h['amount']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$history) echo empty_row(5, 'No upgrades yet.'); ?>
      </tbody>
    </table>
  </div>
</div>
<style>
.upg-current { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
.upg-current strong { display: block; font-size: 20px; color: var(--green-900); }
.upg-perks { margin: 8px 0 0; padding-left: 18px; font-size: 13px; color: var(--muted); }
@media (max-width: 640px) { .upg-current { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
<?php page_footer();
