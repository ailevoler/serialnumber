<?php
require __DIR__ . '/_admin.php';

if (is_post()) {
    csrf_check();
    if (($_POST['action'] ?? '') === 'test') {
        $to = current_user()['email'];
        $ok = send_mail($to, 'PCEC Connect test email', email_layout('Email settings', 'It works!', '<p>This is a test email from PCEC Connect. Confirmation and password-reset emails will be sent the same way.</p>'));
        flash($ok ? 'success' : 'error', $ok ? "Test email sent to $to. Check your inbox (and Spam)." : 'Sending failed: ' . ($GLOBALS['mail_error'] ?? 'unknown error'));
    } else {
        $from = trim((string) ($_POST['mail_from_email'] ?? ''));
        if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please enter a valid sender email.');
            redirect('admin/email.php');
        }
        setting_set('mail_from_name', mb_substr(trim((string) ($_POST['mail_from_name'] ?? '')), 0, 100) ?: 'PCEC Connect');
        setting_set('mail_from_email', $from);
        setting_set('mail_host', trim((string) ($_POST['mail_host'] ?? '')));
        setting_set('mail_port', (string) max(1, (int) ($_POST['mail_port'] ?? 587)));
        setting_set('mail_encryption', in_array($_POST['mail_encryption'] ?? '', ['ssl', 'tls', 'none'], true) ? $_POST['mail_encryption'] : 'tls');
        setting_set('mail_username', trim((string) ($_POST['mail_username'] ?? '')));
        if (($_POST['mail_password'] ?? '') !== '') setting_set('mail_password', secret_encrypt((string) $_POST['mail_password']));
        flash('success', 'Email settings saved.');
    }
    redirect('admin/email.php');
}
$c = mail_config();
$hasPw = setting_secret('mail_password') !== '';

$pageTitle = 'Admin';
$activeNav = 'admin';
require __DIR__ . '/../includes/header.php';
admin_tabs('email');
?>
<div class="admin-wrap">
  <header class="dash-header"><div><h2>Email Settings</h2><p>Used for sign-up confirmation and password reset emails</p></div></header>
  <section class="card pm-status <?= $c['host'] ? 'is-ok' : 'is-off' ?>">
    <span class="pm-status-ic"><?= icon('send') ?></span>
    <div><strong><?= $c['host'] ? 'Sending through SMTP (' . e($c['host']) . ')' : 'Sending with PHP mail()' ?></strong>
      <small>From: <?= e($c['from_name']) ?> &lt;<?= e($c['from_email']) ?>&gt;</small></div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test"><button class="btn btn-sm btn-outline"><?= icon('send') ?> Send test email</button></form>
  </section>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <div class="grid-2">
      <label>Sender name<input name="mail_from_name" value="<?= e(setting('mail_from_name', 'PCEC Connect')) ?>" maxlength="100"></label>
      <label>Sender email<input type="email" name="mail_from_email" value="<?= e(setting('mail_from_email')) ?>" placeholder="<?= e($c['from_email']) ?>"></label>
    </div>
    <p class="hint"><?= icon('bell') ?> Use an address on your own domain (e.g. no-reply@yourdomain.com) so emails don't land in Spam. On Hostinger, create it in hPanel → Emails.</p>
    <h3>SMTP (recommended)</h3>
    <p class="muted small">Leave the host empty to use PHP mail(). For Hostinger email: host <code>smtp.hostinger.com</code>, port <code>465</code>, encryption SSL, username = the full email address.</p>
    <div class="grid-3">
      <label>Host<input name="mail_host" value="<?= e(setting('mail_host')) ?>" placeholder="smtp.hostinger.com"></label>
      <label>Port<input type="number" name="mail_port" value="<?= e((string) $c['port']) ?>"></label>
      <label>Encryption<select name="mail_encryption"><?php foreach (['ssl' => 'SSL (465)', 'tls' => 'STARTTLS (587)', 'none' => 'None'] as $k => $l): ?><option value="<?= $k ?>" <?= $c['encryption'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="grid-2">
      <label>Username<input name="mail_username" value="<?= e(setting('mail_username')) ?>" autocomplete="off"></label>
      <label>Password<input type="password" name="mail_password" placeholder="<?= $hasPw ? '•••••••• (leave blank to keep)' : '' ?>" autocomplete="new-password"></label>
    </div>
    <button class="btn btn-gradient btn-block"><?= icon('check') ?> Save Email Settings</button>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
