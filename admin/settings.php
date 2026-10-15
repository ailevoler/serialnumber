<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

$fields = [
    'General' => [
        'site_name'            => ['Company / Site Name', 'text'],
        'site_tagline'         => ['Tagline', 'text'],
        'currency'             => ['Currency Symbol', 'text'],
        'company_payment_info' => ['Company Payment Instructions (shown at checkout)', 'text'],
    ],
    'Indirect Referral' => [
        'indirect_levels' => ['Amount per level above the sponsor (comma-separated, level 1 first)', 'list', 'e.g. 50,30,20,10,10 → 5 levels'],
    ],
    'Pass Up Bonus (Australian Pass-up)' => [
        'passup_enabled'   => ['Enable Pass Up', 'bool'],
        'passup_positions' => ["Which of a sponsor's recruits are passed up (comma-separated)", 'list', 'e.g. 1 = 1st recruit; 1,2 = 1st and 2nd (2-up)'],
        'passup_bonus'     => ['Pass-up bonus amount', 'number'],
    ],
    'Pairing Bonus (Binary)' => [
        'pair_pv'        => ['PV per side to form 1 pair', 'number'],
        'pair_bonus'     => ['Bonus per pair', 'number'],
        'pair_daily_cap' => ['Max pairs per day (0 = unlimited)', 'number', 'Excess pairs are flushed'],
    ],
    'Repeat Purchase Unilevel' => [
        'unilevel_percent'        => ['Percent of order PV per level (comma-separated)', 'list', 'e.g. 10,5,5,3,2 → 5 levels'],
        'unilevel_require_active' => ['Only active members earn unilevel', 'bool'],
    ],
    'Wallet & Payouts' => [
        'payout_min'              => ['Minimum payout', 'number'],
        'payout_fee_percent'      => ['Payout fee (%)', 'number'],
        'payout_fee_fixed'        => ['Payout fee (fixed)', 'number'],
        'payout_methods'          => ['Payout methods (comma-separated)', 'list'],
        'wallet_transfer_enabled' => ['Allow member-to-member wallet transfer', 'bool'],
        'wallet_transfer_min'     => ['Minimum wallet transfer', 'number'],
        'order_payment_methods'   => ['Order payment methods (wallet, cash, bank, gcash, maya)', 'list'],
    ],
];

if (is_post()) {
    csrf_check();
    foreach ($fields as $group) {
        foreach ($group as $key => [$label, $type]) {
            $v = $type === 'bool' ? (isset($_POST[$key]) ? '1' : '0') : input($key);
            if ($type === 'number') {
                $v = (string)max(0, (float)$v);
            } elseif ($type === 'list') {
                $v = implode(',', array_filter(array_map('trim', explode(',', $v)), fn($x) => $x !== ''));
            }
            if ($key === 'order_payment_methods') {
                $v = implode(',', array_intersect(explode(',', $v), ['wallet', 'cash', 'bank', 'gcash', 'maya'])) ?: 'wallet';
            }
            save_setting($key, $v);
        }
    }
    log_activity((int)$me['id'], 'Updated compensation/settings');
    flash('success', 'Settings saved.');
    redirect('admin/settings.php');
}

page_header('Compensation & Settings', $me, 'Configure the 6 ways to earn');
?>
<div class="alert alert-info">Direct Referral Bonus and binary PV are set per package under <a href="<?= e(url('admin/packages.php')) ?>"><strong>Packages</strong></a>. Product Discount comes from SRP vs. Member Price under <a href="<?= e(url('admin/products.php')) ?>"><strong>Products</strong></a>. Changes apply to new transactions only.</div>
<form method="post">
  <?= csrf_field() ?>
  <div class="grid grid-2">
  <?php foreach ($fields as $group => $items): ?>
    <div class="card">
      <div class="card-header"><h3><?= icon('settings') ?> <?= e($group) ?></h3></div>
      <div class="card-body">
        <?php foreach ($items as $key => $f): [$label, $type] = $f; $hint = $f[2] ?? ''; ?>
          <div class="form-group">
            <?php if ($type === 'bool'): ?>
              <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="<?= e($key) ?>" value="1" <?= setting($key) === '1' ? 'checked' : '' ?>> <?= e($label) ?></label>
            <?php else: ?>
              <label for="<?= e($key) ?>"><?= e($label) ?></label>
              <input class="input" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e(setting($key, '')) ?>" <?= $type === 'number' ? 'type="number" step="0.01" min="0"' : '' ?>>
            <?php endif; ?>
            <?php if ($hint): ?><div class="help"><?= e($hint) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  <button class="btn btn-primary" type="submit">Save Settings</button>
</form>
<?php page_footer();
