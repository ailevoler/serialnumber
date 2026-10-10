<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout_auth.php';

if (current_user()) redirect('index.php');

$error = '';
if (is_post()) {
    csrf_check();
    $login = trim($_POST['login'] ?? '');
    $pass = (string) ($_POST['password'] ?? '');

    // Simple brute-force throttle per session.
    $_SESSION['login_fail'] = $_SESSION['login_fail'] ?? ['n' => 0, 't' => 0];
    if ($_SESSION['login_fail']['n'] >= 5 && time() - $_SESSION['login_fail']['t'] < 300) {
        $error = 'Too many attempts. Please wait 5 minutes and try again.';
    } else {
        $user = q_one('SELECT id, password_hash FROM users WHERE email = ? OR username = ?', [$login, $login]);
        if ($user && password_verify($pass, $user['password_hash'])) {
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $user['id']]);
            }
            unset($_SESSION['login_fail']);
            login_user((int) $user['id'], !empty($_POST['remember']));
            redirect('index.php');
        }
        $_SESSION['login_fail'] = ['n' => $_SESSION['login_fail']['n'] + 1, 't' => time()];
        $error = 'Invalid email/username or password.';
    }
}

auth_head(t('Log In'));
?>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-top">
      <div class="auth-top-bar"><span></span><?= lang_switch() ?></div>
      <?php auth_brand(); ?>
    </div>
    <div class="auth-panel">
      <h1><?= e(t('Welcome Back!')) ?></h1>
      <p class="auth-sub"><?= e(t('Log in to your PCEC Community Platform')) ?></p>
      <span class="accent-line"></span>
      <?php auth_flashes(); ?>
      <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
      <form method="post" class="auth-form" novalidate>
        <?= csrf_field() ?>
        <label class="field">
          <?= icon('mail') ?>
          <input type="text" name="login" placeholder="<?= e(t('Email Address or Username')) ?>" value="<?= old('login') ?>" autocomplete="username" required>
        </label>
        <label class="field">
          <?= icon('lock') ?>
          <input type="password" name="password" placeholder="<?= e(t('Password')) ?>" autocomplete="current-password" required>
          <button type="button" class="pw-toggle" aria-label="Show password"><?= icon('eye-off') ?></button>
        </label>
        <div class="row-between">
          <label class="check"><input type="checkbox" name="remember" value="1" checked><span></span><?= e(t('Remember me')) ?></label>
          <a href="<?= e(url('forgot.php')) ?>" class="link"><?= e(t('Forgot your password?')) ?></a>
        </div>
        <button class="btn btn-gradient btn-lg btn-block"><?= icon('login') ?> <?= e(t('Log In')) ?></button>
      </form>
      <div class="divider"><span><?= e(t('OR CONTINUE WITH')) ?></span></div>
      <div class="social-row">
        <a href="<?= e(url('social.php?p=google')) ?>" class="btn btn-social btn-google"><?= brand_icon('google') ?> <?= e(t('Continue with Google')) ?></a>
        <a href="<?= e(url('social.php?p=facebook')) ?>" class="btn btn-social btn-facebook"><?= brand_icon('facebook') ?> <?= e(t('Continue with Facebook')) ?></a>
      </div>
      <p class="auth-switch"><?= e(t("Don't have an account?")) ?> <a href="<?= e(url('register.php')) ?>"><?= e(t('Create an Account')) ?> <?= icon('chevron-right') ?></a></p>
    </div>
  </div>
</div>
<?php auth_foot(); ?>
