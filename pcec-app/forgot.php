<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout_auth.php';

$sent = false;
$devLink = null;
if (is_post()) {
    csrf_check();
    $login = trim($_POST['login'] ?? '');
    $user = q_one('SELECT id, email FROM users WHERE email = ? OR username = ?', [$login, $login]);
    if ($user) {
        $token = bin2hex(random_bytes(32));
        q('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?,?, NOW() + INTERVAL 1 HOUR)',
            [$user['id'], hash('sha256', $token)]);
        $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
        $link = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('reset.php?token=' . $token);
        $body = "Hello,\n\nUse this link within one hour to reset your PCEC password:\n$link\n\nIf you did not request this, ignore this email.";
        // mail() needs a configured MTA; on local dev servers show the link instead.
        if (!@mail($user['email'], 'Reset your PCEC password', $body) && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
            $devLink = $link;
        }
    }
    $sent = true; // same message either way, so the form can't be used to discover accounts
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
      <p class="auth-sub">Enter your email or username and we'll send you a reset link.</p>
      <?php if ($sent): ?>
        <div class="alert alert-success">If an account matches, a reset link has been sent to its email address.</div>
        <?php if ($devLink): ?><div class="alert alert-info">Local dev (no mail server): <a href="<?= e($devLink) ?>">open reset link</a></div><?php endif; ?>
      <?php endif; ?>
      <form method="post" class="auth-form">
        <?= csrf_field() ?>
        <label class="field"><?= icon('mail') ?><input name="login" placeholder="<?= e(t('Email Address or Username')) ?>" required></label>
        <button class="btn btn-gradient btn-lg btn-block"><?= icon('send') ?> Send Reset Link</button>
      </form>
      <p class="auth-switch"><a href="<?= e(url('login.php')) ?>"><?= icon('arrow-left') ?> Back to <?= e(t('Log In')) ?></a></p>
    </div>
  </div>
</div>
<?php auth_foot(); ?>
