<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
require_login();

$opts = payment_options();
$funds = setting_list('giving_funds', 'General Fund') ?: ['General Fund'];
$presets = array_map('intval', setting_list('giving_amounts', '100,500,1000,2500,5000'));
$projects = q_all("SELECT p.*, (SELECT COALESCE(SUM(pm.amount), 0) FROM donations d JOIN payments pm ON pm.id = d.payment_id
                    WHERE d.project_id = p.id AND pm.status = 'paid') AS raised
                  FROM giving_projects p WHERE p.is_active = 1 ORDER BY p.sort, p.id");
$payee = setting('giving_payee', APP_ORG);
$frequencies = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'];

function give_fail(string $msg): never
{
    if (wants_json()) json_out(['error' => $msg], 422);
    flash('error', $msg);
    redirect('give.php');
}

if (is_post()) {
    csrf_check();
    $type = in_array($_POST['gift_type'] ?? '', ['one_time', 'recurring', 'project'], true) ? $_POST['gift_type'] : 'one_time';
    $amount = ($_POST['amount'] ?? '') === 'other'
        ? (int) round((float) str_replace(',', '', (string) ($_POST['other_amount'] ?? '0')) * 100)
        : (int) ($_POST['amount'] ?? 0) * 100;
    $method = (string) ($_POST['method'] ?? 'qrph');
    $fund = in_array($_POST['fund'] ?? '', $funds, true) ? $_POST['fund'] : $funds[0];
    $frequency = $type === 'recurring' ? (array_key_exists($_POST['frequency'] ?? '', $frequencies) ? $_POST['frequency'] : 'monthly') : null;
    $project = null;

    if ($amount < MIN_PAYMENT) give_fail('The minimum gift is ' . money(MIN_PAYMENT) . '.');
    if ($amount > 100000000) give_fail('For gifts above ₱1,000,000 please contact the PCEC office.');
    if (empty($opts[$method])) give_fail('Please choose an available payment method.');
    if ($type === 'project') {
        $project = q_one('SELECT * FROM giving_projects WHERE id = ? AND is_active = 1', [(int) ($_POST['project_id'] ?? 0)]);
        if (!$project) give_fail('Please choose a project to support.');
        $fund = 'Projects';
    }

    $desc = match ($type) {
        'project' => 'Gift for ' . $project['title'],
        'recurring' => $frequencies[$frequency] . ' gift — ' . $fund,
        default => 'Donation — ' . $fund,
    };
    $p = payment_create('donation', $desc, $amount, $method);
    q('INSERT INTO donations (payment_id, user_id, gift_type, frequency, fund, project_id, is_anonymous, message) VALUES (?,?,?,?,?,?,?,?)',
        [$p['id'], uid(), $type, $frequency, $fund, $project['id'] ?? null, !empty($_POST['anonymous']) ? 1 : 0,
         mb_substr(trim((string) ($_POST['message'] ?? '')), 0, 500) ?: null]);

    try {
        $redirect = payment_start($p, $method);
    } catch (Throwable $e) {
        q("UPDATE payments SET status = 'cancelled', admin_note = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 255), $p['id']]);
        error_log('Giving start failed: ' . $e->getMessage());
        give_fail($e instanceof PayMongoException ? 'We could not start the payment: ' . $e->getMessage() : 'We could not start the payment. Please try again.');
    }
    $page = url('payment.php?ref=' . urlencode($p['reference']));
    if (wants_json()) {
        $p = payment_get((int) $p['id']);
        if ($method === 'qrph') {
            json_out(['ref' => $p['reference'], 'qr' => $p['qr_image'], 'expires_in' => strtotime($p['qr_expires_at']) - time(),
                      'amount' => money($amount), 'status_url' => url('api/payment_status.php?ref=' . urlencode($p['reference'])), 'page' => $page]);
        }
        json_out(['redirect' => $redirect ?: $page]);
    }
    header('Location: ' . ($redirect ?: $page));
    exit;
}

// Prefill from "Give again" or a project link.
$pre = ['type' => 'one_time', 'amount' => 500, 'fund' => $funds[0], 'frequency' => 'monthly', 'project' => 0];
if (!empty($_GET['repeat'])) {
    $d = q_one('SELECT d.*, p.amount FROM donations d JOIN payments p ON p.id = d.payment_id WHERE d.id = ? AND d.user_id = ?', [(int) $_GET['repeat'], uid()]);
    if ($d) $pre = ['type' => $d['gift_type'], 'amount' => intdiv((int) $d['amount'], 100), 'fund' => $d['fund'], 'frequency' => $d['frequency'] ?: 'monthly', 'project' => (int) $d['project_id']];
}
if (!empty($_GET['project'])) {
    $pre['type'] = 'project';
    $pre['project'] = (int) $_GET['project'];
}
if (!empty($_GET['type']) && in_array($_GET['type'], ['one_time', 'recurring', 'project'], true)) $pre['type'] = $_GET['type'];
$isPreset = in_array($pre['amount'], $presets, true);
$defaultMethod = $opts['qrph'] ? 'qrph' : ($opts['bank'] ? 'bank' : ($opts['card'] ? 'card' : 'ewallet'));
$anyOnline = $opts['qrph'] || $opts['card'] || $opts['ewallet'] || $opts['bank'];

$pageTitle = 'Donation & Giving';
$activeNav = 'give';
require __DIR__ . '/includes/header.php';
?>
<section class="give-hero">
  <div class="give-hero-text">
    <h2>Support<br>God’s Work</h2>
    <p>Your generous giving helps advance the mission, support churches, train leaders, and reach more communities for Christ.</p>
  </div>
</section>

<?php if (!$anyOnline): ?>
  <div class="alert alert-info">Online giving is not set up yet.<?php if (is_admin()): ?> <a href="<?= e(url('admin/paymongo.php')) ?>">Set up PayMongo</a><?php endif; ?></div>
<?php endif; ?>

<form method="post" class="card give-card" id="give-form" data-payee="<?= e($payee) ?>">
  <?= csrf_field() ?>
  <div class="segmented" role="tablist">
    <?php foreach (['one_time' => 'One-Time', 'recurring' => 'Recurring', 'project' => 'Projects'] as $k => $label): ?>
      <label><input type="radio" name="gift_type" value="<?= $k ?>" <?= $pre['type'] === $k ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
    <?php endforeach; ?>
  </div>

  <div class="give-pane" data-pane="recurring">
    <h3 class="give-label">How often?</h3>
    <div class="chips">
      <?php foreach ($frequencies as $k => $label): ?>
        <label class="chip-radio"><input type="radio" name="frequency" value="<?= $k ?>" <?= $pre['frequency'] === $k ? 'checked' : '' ?>><span><?= icon('repeat') ?><?= e($label) ?></span></label>
      <?php endforeach; ?>
    </div>
    <p class="hint"><?= icon('bell') ?> You give the first gift now and we'll remind you when the next one is due. Nothing is charged automatically.</p>
  </div>

  <div class="give-pane" data-pane="project">
    <h3 class="give-label">Choose a project</h3>
    <?php foreach ($projects as $i => $pr): $pct = $pr['goal_amount'] > 0 ? min(100, (int) round($pr['raised'] / $pr['goal_amount'] * 100)) : 0; ?>
      <label class="project-option">
        <input type="radio" name="project_id" value="<?= (int) $pr['id'] ?>" <?= $pre['project'] === (int) $pr['id'] || (!$pre['project'] && $i === 0) ? 'checked' : '' ?>>
        <span class="project-body">
          <span class="project-thumb"<?= $pr['image'] ? ' style="background-image:url(\'' . e(url($pr['image'])) . '\')"' : '' ?>><?= $pr['image'] ? '' : icon('target') ?></span>
          <span class="project-info">
            <strong><?= e($pr['title']) ?></strong>
            <?php if ($pr['description']): ?><small><?= e($pr['description']) ?></small><?php endif; ?>
            <?php if ($pr['goal_amount'] > 0): ?>
              <span class="progress"><span style="width:<?= $pct ?>%"></span></span>
              <small class="progress-meta"><b><?= money((int) $pr['raised']) ?></b> raised of <?= money((int) $pr['goal_amount']) ?> · <?= $pct ?>%</small>
            <?php endif; ?>
          </span>
          <?= icon('check', 'project-check') ?>
        </span>
      </label>
    <?php endforeach; ?>
    <?php if (!$projects): ?><p class="muted">No active projects right now.</p><?php endif; ?>
  </div>

  <div class="give-pane" data-pane="one_time recurring">
    <h3 class="give-label">Designate to</h3>
    <div class="chips chips-scroll">
      <?php foreach ($funds as $f): ?>
        <label class="chip-radio"><input type="radio" name="fund" value="<?= e($f) ?>" <?= $pre['fund'] === $f ? 'checked' : '' ?>><span><?= e($f) ?></span></label>
      <?php endforeach; ?>
    </div>
  </div>

  <h3 class="give-label">Select Amount</h3>
  <div class="amount-grid">
    <?php foreach ($presets as $a): ?>
      <label class="amount"><input type="radio" name="amount" value="<?= $a ?>" <?= $isPreset && $pre['amount'] === $a ? 'checked' : '' ?>><span>₱<?= number_format($a) ?></span></label>
    <?php endforeach; ?>
    <label class="amount"><input type="radio" name="amount" value="other" <?= $isPreset ? '' : 'checked' ?>><span>Other</span></label>
  </div>
  <label class="other-amount" <?= $isPreset ? 'hidden' : '' ?>>
    <span>₱</span><input type="number" name="other_amount" min="20" max="1000000" step="1" inputmode="decimal" placeholder="Enter amount (min ₱20)" value="<?= $isPreset ? '' : (int) $pre['amount'] ?>">
  </label>

  <h3 class="give-label">Payment Method</h3>
  <label class="pay-main<?= $opts['qrph'] ? '' : ' is-disabled' ?>">
    <input type="radio" name="method" value="qrph" <?= $defaultMethod === 'qrph' ? 'checked' : '' ?> <?= $opts['qrph'] ? '' : 'disabled' ?>>
    <span class="pay-main-body"><?= qrph_badge() ?><small>(Any Banking App)</small><span class="radio-dot"></span></span>
  </label>
  <?php if ($opts['bank'] || $opts['card'] || $opts['ewallet']): ?>
  <p class="give-sub">Other Options</p>
  <div class="pay-grid">
    <?php foreach (['bank' => ['bank', 'Bank Transfer'], 'card' => ['card', 'Credit / Debit Card'], 'ewallet' => ['wallet', 'E-Wallet']] as $k => [$ic, $label]): if (!$opts[$k]) continue; ?>
      <label class="pay-alt<?= $opts[$k] ? '' : ' is-disabled' ?>" title="<?= $opts[$k] ? '' : 'Not available yet' ?>">
        <input type="radio" name="method" value="<?= $k ?>" <?= $defaultMethod === $k ? 'checked' : '' ?> <?= $opts[$k] ? '' : 'disabled' ?>>
        <span><?= icon($ic) ?><?= e($label) ?></span>
      </label>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <details class="give-more">
    <summary>Add a message (optional)</summary>
    <label class="form-inline"><textarea name="message" rows="2" maxlength="500" placeholder="Prayer, dedication or note"></textarea></label>
    <label class="check"><input type="checkbox" name="anonymous" value="1"><span></span>Give anonymously</label>
  </details>

  <div class="qr-panel" id="qr-panel">
    <?= qrph_badge('qrph-lg') ?>
    <div class="qr-box" id="qr-box">
      <div class="qr-placeholder"><?= icon('qr') ?></div>
    </div>
    <p class="qr-caption" id="qr-caption">Tap <b>Give</b> to generate your QR code, then scan it using your banking app or e-wallet to complete your donation.</p>
    <div class="payee-box">
      <div><strong><?= e($payee) ?></strong><small id="payee-fund">for <?= e($pre['fund']) ?></small></div>
      <button type="button" class="icon-btn" data-copy-text="<?= e($payee) ?>" id="payee-copy" aria-label="Copy"><?= icon('copy') ?></button>
    </div>
  </div>

  <button class="btn btn-gradient btn-lg btn-block give-submit" <?= $anyOnline ? '' : 'disabled' ?>><?= icon('gift') ?> <span id="give-label">Give ₱<?= number_format($pre['amount']) ?> Now</span></button>
  <p class="secure-note"><?= icon('shield') ?> Payments are processed securely by PayMongo. You'll receive a receipt by email.</p>
  <p class="secure-note"><a class="link" href="<?= e(url('my_giving.php')) ?>"><?= icon('receipt') ?> View my giving history</a></p>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
