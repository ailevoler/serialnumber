<?php
require __DIR__ . '/_admin.php';

$allMethods = ['qrph' => 'QR Ph (any banking app)', 'card' => 'Credit / Debit Card', 'gcash' => 'GCash', 'paymaya' => 'Maya',
               'grab_pay' => 'GrabPay', 'shopee_pay' => 'ShopeePay', 'dob' => 'Online Banking (BPI, UnionBank…)'];
$webhookUrl = abs_url('webhook/paymongo.php');
$webhookEvents = ['payment.paid', 'payment.failed', 'checkout_session.payment.paid'];

if (is_post()) {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_paymongo') {
            setting_set('paymongo_mode', ($_POST['mode'] ?? 'test') === 'live' ? 'live' : 'test');
            foreach (['test', 'live'] as $m) {
                $pk = trim((string) ($_POST["{$m}_public"] ?? ''));
                if ($pk !== '' && !str_starts_with($pk, "pk_{$m}_")) throw new RuntimeException(ucfirst($m) . " public key must start with pk_{$m}_");
                setting_set("paymongo_{$m}_public", $pk);
                $sk = trim((string) ($_POST["{$m}_secret"] ?? ''));
                if ($sk !== '') {
                    if (!str_starts_with($sk, "sk_{$m}_")) throw new RuntimeException(ucfirst($m) . " secret key must start with sk_{$m}_");
                    setting_set("paymongo_{$m}_secret", secret_encrypt($sk));
                }
                $wh = trim((string) ($_POST["{$m}_webhook"] ?? ''));
                if ($wh !== '') {
                    if (!str_starts_with($wh, 'whsk_')) throw new RuntimeException('Webhook secret must start with whsk_');
                    setting_set("paymongo_{$m}_webhook_secret", secret_encrypt($wh));
                }
                if (!empty($_POST["{$m}_clear"])) {
                    foreach (['public', 'secret', 'webhook_secret'] as $k) setting_set("paymongo_{$m}_{$k}", '');
                }
            }
            $methods = array_values(array_intersect(array_keys($allMethods), (array) ($_POST['methods'] ?? [])));
            setting_set('paymongo_methods', implode(',', $methods));
            if (setting('paymongo_mode') === 'live' && setting_secret('paymongo_live_secret') === '') {
                flash('info', 'Live mode is on but no live secret key is saved — online payments are disabled until you add it.');
            }
            flash('success', 'PayMongo settings saved.');
        } elseif ($action === 'test') {
            $pm = PayMongo::fromSettings() ?? throw new RuntimeException('Add a ' . setting('paymongo_mode', 'test') . ' secret key first.');
            $hooks = $pm->listWebhooks();
            $ours = array_filter($hooks, fn($h) => ($h['attributes']['url'] ?? '') === $webhookUrl);
            flash('success', 'Connected to PayMongo (' . setting('paymongo_mode', 'test') . ' mode). ' . count($hooks) . ' webhook(s) registered'
                . ($ours ? ', including this site.' : '. This site\'s webhook is not registered yet.'));
        } elseif ($action === 'webhook') {
            $mode = setting('paymongo_mode', 'test');
            $pm = PayMongo::fromSettings() ?? throw new RuntimeException("Add a $mode secret key first.");
            if (!str_starts_with($webhookUrl, 'https://')) throw new RuntimeException('PayMongo only delivers webhooks to a public HTTPS URL. Open the admin page from your live domain and try again.');
            $hook = $pm->createWebhook($webhookUrl, $webhookEvents);
            setting_set("paymongo_{$mode}_webhook_secret", secret_encrypt($hook['attributes']['secret_key']));
            flash('success', 'Webhook registered and its signing secret saved.');
        } elseif ($action === 'save_bank') {
            setting_set('bank_enabled', !empty($_POST['bank_enabled']) ? '1' : '0');
            foreach (['bank_name' => 80, 'bank_account_name' => 120, 'bank_account_number' => 40, 'bank_instructions' => 500] as $k => $len) {
                setting_set($k, mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $len));
            }
            flash('success', 'Bank transfer settings saved.');
        } elseif ($action === 'save_giving') {
            $payee = trim((string) ($_POST['giving_payee'] ?? ''));
            $funds = array_filter(array_map(fn($f) => mb_substr(trim($f), 0, 80), explode(',', (string) ($_POST['giving_funds'] ?? ''))));
            $amounts = array_filter(array_map('intval', explode(',', (string) ($_POST['giving_amounts'] ?? ''))), fn($a) => $a >= 20);
            if ($payee === '' || !$funds || !$amounts) throw new RuntimeException('Payee, at least one fund and one amount (₱20 or more) are required.');
            setting_set('giving_payee', mb_substr($payee, 0, 160));
            setting_set('giving_funds', implode(',', array_unique($funds)));
            setting_set('giving_amounts', implode(',', array_slice(array_unique($amounts), 0, 5)));
            flash('success', 'Giving options saved.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/paymongo.php');
}

$mode = setting('paymongo_mode', 'test');
$enabled = setting_list('paymongo_methods');
$configured = PayMongo::fromSettings() !== null;

$pageTitle = 'Admin';
$activeNav = 'admin';
require __DIR__ . '/../includes/header.php';
admin_tabs('paymongo');
?>
<div class="admin-wrap">
  <section class="card pm-status <?= $configured ? 'is-ok' : 'is-off' ?>">
    <span class="pm-status-ic"><?= icon($configured ? 'shield' : 'lock') ?></span>
    <div>
      <strong><?= $configured ? 'PayMongo connected' : 'PayMongo not set up' ?></strong>
      <small><?= $configured ? ucfirst($mode) . ' mode · ' . count($enabled) . ' method(s) enabled' : 'Add your API keys from dashboard.paymongo.com → Developers → API Keys.' ?></small>
    </div>
    <span class="mode-pill mode-<?= e($mode) ?>"><?= e(strtoupper($mode)) ?></span>
  </section>

  <form method="post" class="card form">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_paymongo">
    <h2 class="card-title"><?= icon('settings') ?> PayMongo API</h2>
    <div class="segmented segmented-sm">
      <label><input type="radio" name="mode" value="test" <?= $mode === 'test' ? 'checked' : '' ?>><span>Test mode</span></label>
      <label><input type="radio" name="mode" value="live" <?= $mode === 'live' ? 'checked' : '' ?>><span>Live mode</span></label>
    </div>
    <p class="hint"><?= icon('bell') ?> Use <b>Test mode</b> with test keys while setting up; no real money moves. Switch to <b>Live</b> after PayMongo activates your account.</p>

    <?php foreach (['test' => 'Test keys', 'live' => 'Live keys'] as $m => $label):
        $sk = setting_secret("paymongo_{$m}_secret"); $wh = setting_secret("paymongo_{$m}_webhook_secret"); ?>
      <fieldset class="key-set">
        <legend><?= e($label) ?> <?= $sk ? '<span class="status status-paid">Saved</span>' : '<span class="status status-pending">Not set</span>' ?></legend>
        <label>Public key<input name="<?= $m ?>_public" value="<?= e(setting("paymongo_{$m}_public")) ?>" placeholder="pk_<?= $m ?>_…" autocomplete="off" spellcheck="false"></label>
        <label>Secret key<input type="password" name="<?= $m ?>_secret" placeholder="<?= $sk ? e(mask_secret($sk)) . '  (leave blank to keep)' : 'sk_' . $m . '_…' ?>" autocomplete="new-password" spellcheck="false"></label>
        <label>Webhook signing secret<input type="password" name="<?= $m ?>_webhook" placeholder="<?= $wh ? e(mask_secret($wh)) . '  (leave blank to keep)' : 'whsk_… (or use “Register webhook” below)' ?>" autocomplete="new-password" spellcheck="false"></label>
        <?php if ($sk || $wh): ?><label class="check"><input type="checkbox" name="<?= $m ?>_clear" value="1"><span></span>Remove saved <?= e(strtolower($label)) ?></label><?php endif; ?>
      </fieldset>
    <?php endforeach; ?>

    <h3>Payment methods to offer</h3>
    <div class="method-checks">
      <?php foreach ($allMethods as $k => $label): ?>
        <label class="check"><input type="checkbox" name="methods[]" value="<?= $k ?>" <?= in_array($k, $enabled, true) ? 'checked' : '' ?>><span></span><?= e($label) ?></label>
      <?php endforeach; ?>
    </div>
    <p class="hint">Only enable methods that are activated on your PayMongo account. Secret keys are stored encrypted and are never shown again.</p>
    <button class="btn btn-gradient btn-block"><?= icon('check') ?> Save PayMongo Settings</button>
  </form>

  <section class="card">
    <h2 class="card-title"><?= icon('link') ?> Webhook</h2>
    <p class="muted">PayMongo calls this URL to confirm payments instantly (events: <?= e(implode(', ', $webhookEvents)) ?>).</p>
    <div class="copy-field"><code><?= e($webhookUrl) ?></code><button type="button" class="icon-btn" data-copy-text="<?= e($webhookUrl) ?>" aria-label="Copy"><?= icon('copy') ?></button></div>
    <div class="btn-row">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test"><button class="btn btn-outline" <?= $configured ? '' : 'disabled' ?>><?= icon('refresh') ?> Test Connection</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="webhook"><button class="btn btn-outline" <?= $configured ? '' : 'disabled' ?>><?= icon('link') ?> Register Webhook (<?= e($mode) ?>)</button></form>
    </div>
    <p class="hint">Without a webhook the app still confirms payments by checking PayMongo while the donor's payment page is open.</p>
  </section>

  <form method="post" class="card form">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_bank">
    <h2 class="card-title"><?= icon('bank') ?> Bank Transfer (manual)</h2>
    <label class="check"><input type="checkbox" name="bank_enabled" value="1" <?= setting('bank_enabled') === '1' ? 'checked' : '' ?>><span></span>Show bank details as a payment option</label>
    <div class="grid-2">
      <label>Bank<input name="bank_name" value="<?= e(setting('bank_name')) ?>"></label>
      <label>Account number<input name="bank_account_number" value="<?= e(setting('bank_account_number')) ?>"></label>
    </div>
    <label>Account name<input name="bank_account_name" value="<?= e(setting('bank_account_name')) ?>"></label>
    <label>Instructions for donors<textarea name="bank_instructions" rows="2"><?= e(setting('bank_instructions')) ?></textarea></label>
    <p class="hint">Donors submit their bank reference; confirm each transfer in <a class="link" href="<?= e(url('admin/payments.php?method=bank&status=pending')) ?>">Payments</a>. If this is off and “Online Banking” is enabled above, Bank Transfer uses PayMongo instead.</p>
    <button class="btn btn-outline btn-block"><?= icon('check') ?> Save Bank Details</button>
  </form>

  <form method="post" class="card form">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_giving">
    <h2 class="card-title"><?= icon('gift') ?> Giving Options</h2>
    <label>Payee name<input name="giving_payee" value="<?= e(setting('giving_payee', APP_ORG)) ?>" required></label>
    <label>Funds (comma separated)<input name="giving_funds" value="<?= e(setting('giving_funds')) ?>" placeholder="General Fund,Missions,Disaster Relief"></label>
    <label>Preset amounts in ₱ (up to 5, comma separated)<input name="giving_amounts" value="<?= e(setting('giving_amounts')) ?>" placeholder="100,500,1000,2500,5000"></label>
    <button class="btn btn-outline btn-block"><?= icon('check') ?> Save Giving Options</button>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
