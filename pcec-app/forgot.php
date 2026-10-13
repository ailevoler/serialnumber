<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout_auth.php';

$sent = false;
$devLink = null;
if (is_post()) {
    csrf_check();
    $login = strtolower(trim($_POST['login'] ?? ''));
    $user = q_one('SELECT * FROM users WHERE email = ? OR username = ?', [$login, $login]);
    if ($user && $user['status'] !== 'suspended') {
        $token = bin2hex(random_bytes(32));
        q('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?,?, NOW() + INTERVAL 1 HOUR)',
            [$user['id'], hash('sha256', $token)]);
        if (!send_password_reset_email($user, abs_url('reset.php?token=' . $token)) && is_local_request()) {
            $devLink = $_SESSION['last_mail_link'] ?? null;
        }
    }
    $sent = true; // same message either way, so the form can't be used to find out who is a member
}

auth_head('Forgot Password');
?>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-top auth-top-sm">
      <div class="auth-top-bar"><a href="<?= e(url('login.php')) ?>" class="icon-btn light" aria-label="Back"><?= icon('arrow-left') ?></a><?= lang_switch() ?></div>
      <?php auth_brand(true); ?>
    </div>
    <div class="auth-panel">
      <h1>Forgot Password</h1>
      <p class="auth-sub">Enter the email address for your PCEC Connect account. We'll send you a link to choose a new password.</p>
      <?php if ($sent): ?>
        <div class="alert alert-success">If that email has an account, a reset link is on its way. Check your Spam folder if it does not arrive.</div>
        <?php if ($devLink): ?><div class="alert alert-info">Local test (email not sent): <a href="<?= e($devLink) ?>">open reset link</a></div><?php endif; ?>
      <?php endif; ?>
      <form method="post" class="auth-form">
        <?= csrf_field() ?>
        <label class="field"><?= icon('mail') ?><input name="login" type="email" placeholder="Email Address" autocomplete="email" required></label>
        <button class="btn btn-gradient btn-lg btn-block"><?= icon('send') ?> Send Reset Link</button>
      </form>
      <p class="auth-switch"><a href="<?= e(url('login.php')) ?>"><?= icon('arrow-left') ?> Back to <?= e(t('Log In')) ?></a></p>
    </div>
  </div>
</div>
<?php auth_foot(); ?>
