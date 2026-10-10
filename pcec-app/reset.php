<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout_auth.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$row = $token ? q_one('SELECT * FROM password_resets WHERE token_hash = ? AND used = 0 AND expires_at > NOW()', [hash('sha256', $token)]) : null;
$error = '';

if ($row && is_post()) {
    csrf_check();
    $pass = (string) ($_POST['password'] ?? '');
    if (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pass !== ($_POST['confirm'] ?? '')) {
        $error = 'Passwords do not match.';
    } else {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $row['user_id']]);
        q('UPDATE password_resets SET used = 1 WHERE user_id = ?', [$row['user_id']]);
        q('DELETE FROM remember_tokens WHERE user_id = ?', [$row['user_id']]);
        flash('success', 'Your password has been reset. You can now log in.');
        redirect('login.php');
    }
}

auth_head('Reset Password');
?>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-top auth-top-sm"><div class="auth-top-bar"><span></span><?= lang_switch() ?></div><?php auth_brand(true); ?></div>
    <div class="auth-panel">
      <h1>Reset Password</h1>
      <?php if (!$row): ?>
        <div class="alert alert-error">This reset link is invalid or has expired.</div>
        <p class="auth-switch"><a href="<?= e(url('forgot.php')) ?>">Request a new link <?= icon('chevron-right') ?></a></p>
      <?php else: ?>
        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="auth-form">
          <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
          <label class="field"><?= icon('lock') ?><input type="password" name="password" placeholder="New Password" minlength="8" required><button type="button" class="pw-toggle" aria-label="Show password"><?= icon('eye-off') ?></button></label>
          <label class="field"><?= icon('lock') ?><input type="password" name="confirm" placeholder="<?= e(t('Confirm Password')) ?>" required><button type="button" class="pw-toggle" aria-label="Show password"><?= icon('eye-off') ?></button></label>
          <button class="btn btn-gradient btn-lg btn-block"><?= icon('check') ?> Save New Password</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php auth_foot(); ?>
