<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

$webhookUrl = absolute_url('paymongo_webhook.php');

if (is_post()) {
    csrf_check();
    $action = input('action');
    try {
        if ($action === 'save') {
            save_setting('paymongo_enabled', isset($_POST['paymongo_enabled']) ? '1' : '0');
            save_setting('paymongo_mode', input('paymongo_mode') === 'live' ? 'live' : 'test');
            save_setting('paymongo_public_key', input('paymongo_public_key'));
            // Secret fields: leave blank to keep the saved value.
            foreach (['paymongo_secret_key', 'paymongo_webhook_secret'] as $k) {
                if (input($k) !== '') {
                    save_setting($k, input($k));
                }
            }
            $methods = array_values(array_intersect((array)($_POST['paymongo_methods'] ?? []), array_keys(paymongo_method_options())));
            save_setting('paymongo_methods', implode(',', $methods ?: ['qrph']));
            settings_all(true);
            $sk = (string)setting('paymongo_secret_key');
            $mode = setting('paymongo_mode');
            if ($sk !== '' && !str_starts_with($sk, $mode === 'live' ? 'sk_live_' : 'sk_test_')) {
                flash('warning', "The secret key does not look like a {$mode} key (expected " . ($mode === 'live' ? 'sk_live_' : 'sk_test_') . '...).');
            }
            log_activity((int)$me['id'], 'Updated PayMongo settings');
            flash('success', 'PayMongo settings saved.');
        } elseif ($action === 'test') {
            $res = paymongo_request('GET', '/webhooks');
            flash('success', 'Connected to PayMongo successfully. ' . count($res['data'] ?? []) . ' webhook(s) found on this account.');
        } elseif ($action === 'webhook') {
            $res = paymongo_request('POST', '/webhooks', ['data' => ['attributes' => [
                'url'    => $webhookUrl,
                'events' => ['checkout_session.payment.paid'],
            ]]]);
            save_setting('paymongo_webhook_id', (string)($res['data']['id'] ?? ''));
            if (!empty($res['data']['attributes']['secret_key'])) {
                save_setting('paymongo_webhook_secret', $res['data']['attributes']['secret_key']);
            }
            log_activity((int)$me['id'], 'Registered PayMongo webhook');
            flash('success', 'Webhook registered and its signing secret saved.');
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('admin/paymongo.php');
}

$mask = function (string $v): string {
    return $v === '' ? 'Not set' : str_repeat('•', 8) . substr($v, -4);
};
$methods = paymongo_methods();
$stats = q_row("SELECT COUNT(*) n, COALESCE(SUM(IF(status='paid', amount, 0)),0) paid, SUM(status='pending') pending FROM payments");

page_header('PayMongo Settings', $me, 'Accept GCash, Maya, cards and more');
?>
<div class="stats">
  <?= stat_card('Status', paymongo_enabled() ? 'Enabled' : 'Disabled', 'card', paymongo_enabled() ? 'dark' : '', 'Mode: ' . strtoupper((string)setting('paymongo_mode', 'test'))) ?>
  <?= stat_card('Collected Online', money($stats['paid']), 'cash', 'gold') ?>
  <?= stat_card('Pending Checkouts', num($stats['pending']), 'list', '', num($stats['n']) . ' total') ?>
</div>

<div class="grid grid-main">
  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <div class="card-header"><h3><?= icon('card') ?> API Configuration</h3></div>
    <div class="card-body">
      <label class="toggle-card mb-2" style="max-width:420px">
        <input type="checkbox" name="paymongo_enabled" value="1" <?= setting('paymongo_enabled') === '1' ? 'checked' : '' ?>>
        <span class="toggle-ui"></span>
        <span><strong>Enable PayMongo</strong><small>Show PayMongo at checkout and in Buy Codes</small></span>
      </label>
      <div class="form-grid">
        <div class="form-group"><label>Mode</label>
          <select class="input" name="paymongo_mode">
            <option value="test" <?= setting('paymongo_mode') !== 'live' ? 'selected' : '' ?>>Test (sandbox)</option>
            <option value="live" <?= setting('paymongo_mode') === 'live' ? 'selected' : '' ?>>Live</option>
          </select></div>
        <div class="form-group"><label>Public Key</label><input class="input mono" name="paymongo_public_key" value="<?= e(setting('paymongo_public_key')) ?>" placeholder="pk_test_..."></div>
        <div class="form-group"><label>Secret Key</label><input class="input mono" type="password" name="paymongo_secret_key" placeholder="<?= e($mask((string)setting('paymongo_secret_key'))) ?>" autocomplete="new-password"><div class="help">Leave blank to keep the saved key.</div></div>
        <div class="form-group"><label>Webhook Signing Secret</label><input class="input mono" type="password" name="paymongo_webhook_secret" placeholder="<?= e($mask((string)setting('paymongo_webhook_secret'))) ?>" autocomplete="new-password"><div class="help">Filled automatically when you click "Register Webhook".</div></div>
        <div class="form-group full"><label>Payment methods to offer</label>
          <div class="radio-row">
            <?php foreach (paymongo_method_options() as $k => $label): ?>
              <label class="radio-pill"><input type="checkbox" name="paymongo_methods[]" value="<?= $k ?>" <?= in_array($k, $methods, true) ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
            <?php endforeach; ?>
          </div>
          <div class="help">Only the ticked methods appear on the PayMongo checkout page. Tick <strong>QR Ph</strong> only to accept QR Ph payments (any bank or e-wallet app can scan it). Each method must be activated on your PayMongo account.</div></div>
      </div>
      <button class="btn btn-primary mt-2" type="submit">Save Settings</button>
    </div>
  </form>

  <div>
    <div class="card">
      <div class="card-header"><h3><?= icon('send') ?> Webhook</h3></div>
      <div class="card-body">
        <label>Webhook URL</label>
        <div class="copy-group mb-2"><input class="input small" id="wh-url" value="<?= e($webhookUrl) ?>" readonly><button class="btn btn-light btn-sm" type="button" data-copy="#wh-url"><?= icon('copy', 14) ?></button></div>
        <p class="small text-muted">Event: <code>checkout_session.payment.paid</code>. The URL must be public HTTPS.<?= setting('paymongo_webhook_id') ? ' Registered ID: <span class="mono">' . e(setting('paymongo_webhook_id')) . '</span>' : '' ?></p>
        <form method="post" class="filters">
          <?= csrf_field() ?>
          <button class="btn btn-outline btn-sm" name="action" value="test" type="submit">Test Connection</button>
          <button class="btn btn-gold btn-sm" name="action" value="webhook" type="submit" data-confirm="Register this webhook URL on your PayMongo account?">Register Webhook</button>
        </form>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h3><?= icon('list') ?> Setup Steps</h3></div>
      <div class="card-body small">
        <ol style="padding-left:18px;margin:0">
          <li>Create or log in to your account at <strong>dashboard.paymongo.com</strong>.</li>
          <li>Go to <em>Developers → API Keys</em> and copy the Public and Secret keys here.</li>
          <li>Save, then click <strong>Test Connection</strong>.</li>
          <li>Click <strong>Register Webhook</strong> (your site must be on HTTPS).</li>
          <li>Tick <strong>Enable PayMongo</strong>. Test with test keys first, then switch to Live.</li>
        </ol>
      </div>
    </div>
  </div>
</div>
<?php page_footer();
