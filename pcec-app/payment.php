<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
require_login();

$p = payment_by_ref((string) ($_GET['ref'] ?? $_POST['ref'] ?? ''));
if (!$p) {
    flash('error', 'Payment not found.');
    redirect('my_giving.php');
}
$self = 'payment.php?ref=' . urlencode($p['reference']);
$reg = $p['purpose'] === 'event' ? q_one('SELECT r.*, e.title, e.id AS eid FROM event_registrations r JOIN events e ON e.id = r.event_id WHERE r.payment_id = ?', [$p['id']]) : null;
$don = $p['purpose'] === 'donation' ? q_one('SELECT d.*, gp.title AS project FROM donations d LEFT JOIN giving_projects gp ON gp.id = d.project_id WHERE d.payment_id = ?', [$p['id']]) : null;

if (is_post() && $p['status'] === 'pending') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'method') {
            $to = payment_start($p, (string) ($_POST['method'] ?? 'qrph'));
            if ($to) { header('Location: ' . $to); exit; }
        } elseif ($action === 'bank_ref') {
            $refNo = trim((string) ($_POST['payer_reference'] ?? ''));
            if ($refNo === '') throw new RuntimeException('Please enter the reference number from your bank.');
            q('UPDATE payments SET payer_reference = ? WHERE id = ?', [mb_substr($refNo, 0, 100), $p['id']]);
            foreach (q_all("SELECT id FROM users WHERE role = 'admin'") as $a) {
                notify((int) $a['id'], uid(), 'payment', 'Bank transfer to verify: ' . money((int) $p['amount']) . ' (' . $p['reference'] . ').', 'admin/payments.php?q=' . $p['reference']);
            }
            flash('success', 'Thank you! We will confirm your transfer once it reflects in our account.');
        } elseif ($action === 'cancel') {
            q("UPDATE payments SET status = 'cancelled' WHERE id = ? AND status = 'pending'", [$p['id']]);
            if ($reg) q("DELETE FROM event_registrations WHERE id = ? AND status = 'pending'", [$reg['id']]);
            flash('info', 'Payment cancelled.');
            redirect($reg ? 'event.php?id=' . $reg['eid'] : 'give.php');
        }
    } catch (Throwable $e) {
        flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong. Please try again.');
    }
    redirect($self);
}

if (!empty($_GET['cancelled'])) flash('info', 'Checkout was cancelled. You can try again or choose another method.');
$p = payment_refresh($p, !empty($_GET['return']));
$opts = payment_options();
$qrValid = $p['method'] === 'qrph' && $p['qr_image'] && strtotime((string) $p['qr_expires_at']) > time();
$payee = setting('giving_payee', APP_ORG);
$forLabel = $reg ? $reg['title'] : ($don ? ($don['project'] ?: $don['fund']) : '');

$pageTitle = $p['status'] === 'paid' ? 'Receipt' : 'Complete Payment';
$activeNav = $p['purpose'] === 'event' ? 'events' : 'give';
require __DIR__ . '/includes/header.php';
?>
<?php if ($p['status'] === 'paid'): ?>
  <section class="card receipt">
    <div class="receipt-check"><?= icon('check') ?></div>
    <h2><?= $p['purpose'] === 'event' ? "You're registered!" : 'Thank you for your gift!' ?></h2>
    <p class="muted"><?= $p['purpose'] === 'event' ? 'Your payment was received and your seat is confirmed.' : '“God loves a cheerful giver.” — 2 Corinthians 9:7' ?></p>
    <div class="receipt-amount"><?= money((int) $p['amount'], true) ?></div>
    <dl class="receipt-list">
      <div><dt>Reference</dt><dd><?= e($p['reference']) ?> <button class="icon-btn" data-copy-text="<?= e($p['reference']) ?>" aria-label="Copy"><?= icon('copy') ?></button></dd></div>
      <div><dt>For</dt><dd><?= e($p['description']) ?></dd></div>
      <div><dt>Paid via</dt><dd><?= e(method_label($p['method'])) ?></dd></div>
      <div><dt>Date</dt><dd><?= e(date('M j, Y g:i A', strtotime((string) $p['paid_at']))) ?></dd></div>
      <?php if ($p['provider_payment_id']): ?><div><dt>Transaction ID</dt><dd class="mono"><?= e($p['provider_payment_id']) ?></dd></div><?php endif; ?>
    </dl>
    <div class="receipt-actions">
      <?php if ($reg): ?><a class="btn btn-gradient" href="<?= e(url('event.php?id=' . $reg['eid'])) ?>"><?= icon('calendar') ?> View Event</a>
      <?php else: ?><a class="btn btn-gradient" href="<?= e(url('give.php')) ?>"><?= icon('gift') ?> Give Again</a><?php endif; ?>
      <a class="btn btn-outline" href="<?= e(url('my_giving.php')) ?>"><?= icon('receipt') ?> My Giving</a>
      <button class="btn btn-outline" onclick="window.print()"><?= icon('download') ?> Save</button>
    </div>
  </section>

<?php elseif ($p['status'] !== 'pending'): ?>
  <section class="card receipt">
    <div class="receipt-check is-off"><?= icon('x') ?></div>
    <h2>Payment <?= e($p['status']) ?></h2>
    <p class="muted"><?= e($p['reference']) ?> · <?= money((int) $p['amount']) ?></p>
    <div class="receipt-actions"><a class="btn btn-gradient" href="<?= e(url($reg ? 'event.php?id=' . $reg['eid'] : 'give.php')) ?>">Start again</a></div>
  </section>

<?php else: ?>
  <section class="card pay-summary">
    <div><small class="muted"><?= $p['purpose'] === 'event' ? 'Registration fee' : 'Your gift' ?></small><strong><?= money((int) $p['amount'], true) ?></strong></div>
    <div class="pay-summary-meta"><span><?= e($p['description']) ?></span><span class="mono"><?= e($p['reference']) ?></span></div>
  </section>

  <?php if ($p['method'] === 'qrph'): ?>
    <section class="card qr-panel qr-panel-page" id="qr-live" data-status-url="<?= e(url('api/payment_status.php?ref=' . urlencode($p['reference']))) ?>"
             data-expires="<?= $qrValid ? strtotime((string) $p['qr_expires_at']) - time() : 0 ?>">
      <?= qrph_badge('qrph-lg') ?>
      <?php if ($qrValid): ?>
        <div class="qr-box"><img src="<?= e($p['qr_image']) ?>" alt="QR Ph code for <?= e($p['reference']) ?>"></div>
        <p class="qr-caption">Scan this QR code using your banking app or e-wallet to complete your <?= $p['purpose'] === 'event' ? 'registration' : 'donation' ?>.</p>
        <p class="qr-timer"><?= icon('clock') ?> Expires in <b data-countdown>30:00</b></p>
        <p class="qr-waiting"><span class="spinner"></span> Waiting for payment…</p>
      <?php else: ?>
        <div class="qr-box"><div class="qr-placeholder"><?= icon('qr') ?></div></div>
        <p class="qr-caption">This QR code has expired.</p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="method"><input type="hidden" name="method" value="qrph">
          <button class="btn btn-gradient"><?= icon('refresh') ?> Generate New QR</button></form>
      <?php endif; ?>
      <div class="payee-box">
        <div><strong><?= e($payee) ?></strong><small>for <?= e($forLabel) ?></small></div>
        <button type="button" class="icon-btn" data-copy-text="<?= e($p['reference']) ?>" aria-label="Copy reference"><?= icon('copy') ?></button>
      </div>
    </section>

  <?php elseif ($p['method'] === 'bank' && setting('bank_enabled', '0') === '1'): ?>
    <section class="card bank-card">
      <h2 class="card-title"><?= icon('bank') ?> Bank Transfer</h2>
      <dl class="receipt-list">
        <div><dt>Bank</dt><dd><?= e(setting('bank_name')) ?></dd></div>
        <div><dt>Account name</dt><dd><?= e(setting('bank_account_name')) ?></dd></div>
        <div><dt>Account number</dt><dd class="mono"><?= e(setting('bank_account_number')) ?> <button class="icon-btn" data-copy-text="<?= e(setting('bank_account_number')) ?>" aria-label="Copy"><?= icon('copy') ?></button></dd></div>
        <div><dt>Amount</dt><dd><b><?= money((int) $p['amount'], true) ?></b></dd></div>
        <div><dt>Transfer note</dt><dd class="mono"><?= e($p['reference']) ?> <button class="icon-btn" data-copy-text="<?= e($p['reference']) ?>" aria-label="Copy"><?= icon('copy') ?></button></dd></div>
      </dl>
      <?php if (setting('bank_instructions')): ?><p class="hint"><?= icon('bell') ?> <?= e(setting('bank_instructions')) ?></p><?php endif; ?>
      <?php if ($p['payer_reference']): ?>
        <div class="alert alert-info">Submitted bank reference: <b><?= e($p['payer_reference']) ?></b>. Awaiting verification by the PCEC finance team.</div>
      <?php endif; ?>
      <form method="post" class="form">
        <?= csrf_field() ?><input type="hidden" name="action" value="bank_ref">
        <label>Bank reference / transaction number<input name="payer_reference" value="<?= e($p['payer_reference']) ?>" required maxlength="100" placeholder="e.g. 1234567890"></label>
        <button class="btn btn-gradient btn-block"><?= icon('send') ?> <?= $p['payer_reference'] ? 'Update Reference' : 'I Have Sent the Transfer' ?></button>
      </form>
    </section>

  <?php else: ?>
    <section class="card receipt">
      <div class="receipt-check is-wait"><span class="spinner"></span></div>
      <h2>Waiting for confirmation</h2>
      <p class="muted">If you already paid on the PayMongo page, this updates automatically in a few seconds.</p>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="method"><input type="hidden" name="method" value="<?= e($p['method']) ?>">
        <button class="btn btn-gradient"><?= icon('external') ?> Continue to <?= e(method_label($p['method'])) ?> payment</button></form>
      <div id="qr-live" data-status-url="<?= e(url('api/payment_status.php?ref=' . urlencode($p['reference']))) ?>" data-expires="0"></div>
    </section>
  <?php endif; ?>

  <section class="card">
    <h3 class="give-label">Pay another way</h3>
    <div class="pay-grid pay-grid-4">
      <?php foreach (['qrph' => ['qr', 'QR Ph'], 'bank' => ['bank', 'Bank Transfer'], 'card' => ['card', 'Card'], 'ewallet' => ['wallet', 'E-Wallet']] as $k => [$ic, $label]):
          if ($k === $p['method'] || empty($opts[$k])) continue; ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="method"><input type="hidden" name="method" value="<?= $k ?>">
          <button class="pay-alt-btn"><?= icon($ic) ?><?= e($label) ?></button></form>
      <?php endforeach; ?>
    </div>
    <form method="post" data-confirm="Cancel this payment?" class="cancel-form"><?= csrf_field() ?><input type="hidden" name="action" value="cancel">
      <button class="link danger-link"><?= icon('x') ?> Cancel payment</button></form>
  </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
