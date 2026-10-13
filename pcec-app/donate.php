<?php
// "The Gift of Giving": choose an amount, pay with QR Ph (PayMongo). The processing fee can be added on top.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
require_login();

$opts = payment_options();
$presets = array_map('intval', setting_list('giving_amounts', '100,250,500,1000,2500')) ?: [100, 250, 500, 1000, 2500];
$payee = setting('giving_payee', APP_ORG);
$fund = (setting_list('giving_funds', 'General Fund') ?: ['General Fund'])[0];
$fees = fee_config();

function donate_fail(string $msg): never
{
    if (wants_json()) json_out(['error' => $msg], 422);
    flash('error', $msg);
    redirect('donate.php');
}

if (is_post()) {
    csrf_check();
    $amount = ($_POST['amount'] ?? '') === 'other'
        ? (int) round((float) str_replace(',', '', (string) ($_POST['other_amount'] ?? '0')) * 100)
        : (int) ($_POST['amount'] ?? 0) * 100;
    if ($amount < MIN_PAYMENT) donate_fail('The minimum gift is ' . money(MIN_PAYMENT) . '.');
    if ($amount > 100000000) donate_fail('For gifts above ₱1,000,000 please contact the PCEC office.');
    if (empty($opts['qrph'])) donate_fail('Online giving is not available right now. Please try again later.');
    $fee = ($fees['donations'] === 'always' || ($fees['donations'] === 'optional' && !empty($_POST['cover_fee']))) ? processing_fee($amount) : 0;

    $p = payment_create('donation', 'Donation — ' . $fund, $amount + $fee, 'qrph', $fee);
    q('INSERT INTO donations (payment_id, user_id, gift_type, fund) VALUES (?,?,?,?)', [$p['id'], uid(), 'one_time', $fund]);
    try {
        payment_start($p, 'qrph');
    } catch (Throwable $e) {
        q("UPDATE payments SET status = 'cancelled', admin_note = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 255), $p['id']]);
        error_log('Donation start failed: ' . $e->getMessage());
        donate_fail($e instanceof PayMongoException ? 'We could not create your QR code: ' . $e->getMessage() : 'We could not create your QR code. Please try again.');
    }
    $p = payment_get((int) $p['id']);
    $page = url('payment.php?ref=' . urlencode($p['reference']));
    if (wants_json()) {
        json_out(['ref' => $p['reference'], 'qr' => $p['qr_image'], 'expires_in' => strtotime($p['qr_expires_at']) - time(),
                  'amount' => money($amount + $fee, $fee > 0), 'status_url' => url('api/payment_status.php?ref=' . urlencode($p['reference'])), 'page' => $page]);
    }
    header('Location: ' . $page);
    exit;
}

// "Give again" from the history pre-fills the amount.
$preAmount = 250;
if (!empty($_GET['amount'])) $preAmount = max(20, (int) $_GET['amount']);
$isPreset = in_array($preAmount, $presets, true);

$pageTitle = 'Donate';
$activeNav = 'donate';
require __DIR__ . '/includes/header.php';
?>
<section class="give-hero">
  <div class="give-hero-text">
    <h2>The Gift<br>of Giving</h2>
    <p>Your contributions directly support our mission and community outreach programs.</p>
  </div>
</section>

<?php if (!$opts['qrph']): ?>
  <div class="alert alert-info">Online giving is being set up. Please check back soon.<?php if (is_admin()): ?> <a href="<?= e(url('admin/paymongo.php')) ?>">Set up PayMongo</a><?php endif; ?></div>
<?php endif; ?>

<form method="post" class="card give-card" id="give-form" data-payee="<?= e($payee) ?>"
      data-fee-percent="<?= e((string) $fees['percent']) ?>" data-fee-fixed="<?= (int) $fees['fixed'] ?>" data-fee-mode="<?= e($fees['donations']) ?>">
  <?= csrf_field() ?>
  <input type="radio" name="gift_type" value="one_time" checked hidden>
  <input type="radio" name="fund" value="<?= e($fund) ?>" checked hidden>

  <h3 class="give-label">SELECT AMOUNT</h3>
  <div class="amount-grid">
    <?php foreach ($presets as $a): ?>
      <label class="amount"><input type="radio" name="amount" value="<?= $a ?>" <?= $isPreset && $preAmount === $a ? 'checked' : '' ?>><span>₱<?= number_format($a) ?></span></label>
    <?php endforeach; ?>
    <label class="amount"><input type="radio" name="amount" value="other" <?= $isPreset ? '' : 'checked' ?>><span>Custom</span></label>
  </div>
  <label class="give-label give-label-sm" for="other-amount">OTHER AMOUNT</label>
  <label class="other-amount<?= $isPreset ? '' : ' on' ?>">
    <span>₱</span><input type="number" id="other-amount" name="other_amount" min="20" max="1000000" step="0.01" inputmode="decimal" placeholder="Enter custom amount (min ₱20)" value="<?= $isPreset ? '' : $preAmount ?>">
  </label>

  <?php if ($fees['donations'] !== 'off' && ($fees['percent'] > 0 || $fees['fixed'] > 0)): ?>
    <?php if ($fees['donations'] === 'optional'): ?>
      <label class="check fee-check"><input type="checkbox" name="cover_fee" value="1" checked><span></span><em>Cover the processing fee so PCEC receives your full gift</em></label>
    <?php endif; ?>
    <div class="fee-breakdown" id="fee-breakdown">
      <div><span>Your gift</span><b data-fb="gift">₱0.00</b></div>
      <div><span>Processing fee <small>(<?= e(rtrim(rtrim(number_format($fees['percent'], 2), '0'), '.')) ?>%<?= $fees['fixed'] ? ' + ' . money($fees['fixed'], true) : '' ?>)</small></span><b data-fb="fee">₱0.00</b></div>
      <div class="fb-total"><span>Total to pay</span><b data-fb="total">₱0.00</b></div>
      <p>The processing fee is charged by the payment provider. Adding it means 100% of your gift goes to the ministry.</p>
    </div>
  <?php endif; ?>

  <h3 class="give-label">MODE OF PAYMENT</h3>
  <label class="pay-main<?= $opts['qrph'] ? '' : ' is-disabled' ?>">
    <input type="radio" name="method" value="qrph" checked <?= $opts['qrph'] ? '' : 'disabled' ?>>
    <span class="pay-main-body"><?= qrph_badge() ?><small>Scan with any banking app or e-wallet (GCash, Maya…)</small><span class="radio-dot"></span></span>
  </label>

  <div class="qr-panel" id="qr-panel">
    <p class="label-strong">SCAN TO PAY</p>
    <div class="qr-box" id="qr-box"><div class="qr-placeholder"><?= icon('qr') ?></div></div>
    <p class="qr-caption" id="qr-caption">Tap <b>Give</b> to generate your QR Ph code, then scan it using your banking app or e-wallet to complete your donation.</p>
    <div class="payee-box">
      <div><strong><?= e($payee) ?></strong><small id="payee-fund">for <?= e($fund) ?></small></div>
      <button type="button" class="icon-btn" data-copy-text="<?= e($payee) ?>" id="payee-copy" aria-label="Copy"><?= icon('copy') ?></button>
    </div>
  </div>

  <button class="btn btn-gradient btn-lg btn-block give-submit" <?= $opts['qrph'] ? '' : 'disabled' ?>><?= icon('heart') ?> <span id="give-label">Give Now</span></button>
  <p class="secure-note"><?= icon('shield') ?> You pay directly in your bank or e-wallet app through PayMongo. We never see your card or login.</p>
  <p class="secure-note"><a class="link" href="<?= e(url('my_giving.php')) ?>"><?= icon('receipt') ?> View my giving history</a></p>
</form>
<footer class="donate-foot"><p>Philippine Council of Evangelical Churches</p><p class="italic">Thank you for your generosity.</p></footer>
<?php require __DIR__ . '/includes/footer.php'; ?>
