<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout_auth.php';

$email = (string) ($_SESSION['confirm_email'] ?? '');
if (is_post()) {
    csrf_check();
    $wait = 60 - (time() - (int) ($_SESSION['confirm_sent'] ?? 0));
    $user = $email ? q_one("SELECT * FROM users WHERE email = ? AND status = 'pending'", [$email]) : null;
    if ($wait > 0) {
        flash('info', "Please wait $wait seconds before sending another link.");
    } elseif ($user) {
        $_SESSION['confirm_sent'] = time();
        $_SESSION['confirm_failed'] = !send_confirmation_email($user);
        flash('success', 'Sent. It can take a minute to arrive.');
    } else {
        flash('info', 'This email is already confirmed or not registered. Please log in.');
    }
    redirect('check_email.php');
}
$failed = !empty($_SESSION['confirm_failed']);
$devLink = $failed && is_local_request() ? ($_SESSION['last_mail_link'] ?? null) : null;

auth_head('Check your email');
?>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-top auth-top-sm"><div class="auth-top-bar"><span></span><?= lang_switch() ?></div><?php auth_brand(true); ?></div>
    <div class="auth-panel notice-panel">
      <div class="notice-ic"><?= icon('mail') ?></div>
      <p class="notice-kicker">Confirm your address</p>
      <h1>Check your email</h1>
      <p class="auth-sub">Open the confirmation link to finish creating your PCEC Connect account.</p>
      <?php auth_flashes(); ?>
      <div class="sent-to"><span>Sent to</span><strong><?= e($email ?: 'your email address') ?></strong></div>
      <?php if ($failed): ?>
        <div class="alert alert-error">We could not send the email right now. Please try “Send the link again” in a minute, or contact the PCEC office.</div>
      <?php endif; ?>
      <?php if ($devLink): ?><div class="alert alert-info">Local test (email not sent): <a href="<?= e($devLink) ?>">open the confirmation link</a></div><?php endif; ?>
      <p class="muted small">Allow a minute for it to arrive. If you don't see it, check your Spam or Promotions folder.</p>
      <form method="post"><?= csrf_field() ?><button class="btn btn-outline btn-block" <?= $email ? '' : 'disabled' ?>><?= icon('refresh') ?> Send the link again</button></form>
      <?php if (!$email): ?><p class="muted small">Use Log In to request another confirmation link after reopening this page.</p><?php endif; ?>
      <a class="btn btn-gradient btn-block" href="<?= e(url('login.php')) ?>"><?= icon('login') ?> Back to Log In</a>
    </div>
  </div>
</div>
<?php auth_foot(); ?>
