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
    'Coming Soon Page' => [
        'coming_soon_enabled'  => ['Show the Coming Soon page on the home page', 'bool', 'Members and admins can still log in via /login.php and /admin/login.php'],
        'launch_date'          => ['Launch date & time', 'datetime', 'Countdown target (Philippine time)'],
        'coming_soon_headline' => ['Headline', 'text'],
        'coming_soon_text'     => ['Short description', 'text'],
        'contact_email'        => ['Contact email', 'text'],
        'contact_mobile'       => ['Contact mobile', 'text'],
        'social_facebook'      => ['Facebook page URL', 'text'],
        'social_tiktok'        => ['TikTok URL', 'text'],
        'social_youtube'       => ['YouTube URL', 'text'],
    ],
    'Wallet & Payouts' => [
        'payout_min'              => ['Minimum payout', 'number'],
        'payout_fee_percent'      => ['Payout fee (%)', 'number'],
        'payout_fee_fixed'        => ['Payout fee (fixed)', 'number'],
        'payout_methods'          => ['Payout methods (comma-separated)', 'list'],
        'wallet_transfer_enabled' => ['Allow member-to-member wallet transfer', 'bool'],
        'wallet_transfer_min'     => ['Minimum wallet transfer', 'number'],
        'order_payment_methods'   => ['Manual order payment methods (wallet, cash, bank, gcash, maya)', 'list', 'PayMongo is added automatically when enabled'],
    ],
    'Activation Code Purchase' => [
        'code_purchase_enabled' => ['Members can buy activation codes online', 'bool'],
        'code_purchase_wallet'  => ['Allow paying for codes with the e-wallet', 'bool', 'PayMongo is also offered when enabled'],
    ],
];

if (is_post()) {
    csrf_check();
    foreach ($fields as $group) {
        foreach ($group as $key => [$label, $type]) {
            $v = $type === 'bool' ? (isset($_POST[$key]) ? '1' : '0') : input($key);
            if ($type === 'datetime') {
                $v = $v !== '' && strtotime($v) ? date('Y-m-d H:i', strtotime($v)) : '';
            } elseif (str_starts_with($key, 'social_') && $v !== '' && !preg_match('#^https?://#i', $v)) {
                $v = 'https://' . ltrim($v, '/');
            }
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
    log_activity((int)$me['id'], 'Updated general settings');
    flash('success', 'Settings saved.');
    redirect('admin/settings.php');
}

page_header('General Settings', $me, 'Company, wallet and payout options');
?>
<div class="alert alert-info">Bonuses, levels and commissions are set in <a href="<?= e(url('admin/compensation.php')) ?>"><strong>Compensation Plan</strong></a>. Online payments are set in <a href="<?= e(url('admin/paymongo.php')) ?>"><strong>PayMongo Settings</strong></a>.</div>
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
              <input class="input" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($type === 'datetime' && setting($key) ? date('Y-m-d\TH:i', strtotime((string)setting($key))) : setting($key, '')) ?>" <?= $type === 'number' ? 'type="number" step="0.01" min="0"' : ($type === 'datetime' ? 'type="datetime-local"' : '') ?>>
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
