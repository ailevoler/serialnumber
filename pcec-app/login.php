<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout_auth.php';

if (current_user()) redirect('index.php');

$error = '';
$unconfirmed = null;
$next = (string) ($_GET['next'] ?? $_POST['next'] ?? '');
$safeNext = preg_match('~^[a-z0-9_./-]+\.php(\?[\w=&%.-]*)?$~i', $next) ? $next : '';

if (is_post()) {
    csrf_check();
    $login = strtolower(trim($_POST['login'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');

    // Simple brute-force throttle per session.
    $_SESSION['login_fail'] = $_SESSION['login_fail'] ?? ['n' => 0, 't' => 0];
    if ($_SESSION['login_fail']['n'] >= 5 && time() - $_SESSION['login_fail']['t'] < 300) {
        $error = 'Too many attempts. Please wait 5 minutes and try again.';
    } elseif (!filter_var($login, FILTER_VALIDATE_EMAIL) && !preg_match('/^[a-z0-9_.]{3,40}$/', $login)) {
        $error = 'Please enter a valid email address.';
    } elseif ($pass === '') {
        $error = 'Please enter your password.';
    } else {
        $user = q_one('SELECT * FROM users WHERE email = ? OR username = ?', [$login, $login]);
        if ($user && password_verify($pass, $user['password_hash'])) {
            unset($_SESSION['login_fail']);
            if ($user['status'] === 'suspended') {
                $error = 'This account is suspended. Please contact the PCEC office if you think this is a mistake.';
            } elseif ($user['status'] === 'pending') {
                $error = 'Please confirm your email first. We sent you a link when you signed up.';
                $unconfirmed = $user;
                $_SESSION['confirm_email'] = $user['email'];
            } else {
                if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                    q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $user['id']]);
                }
                login_user((int) $user['id'], !empty($_POST['remember']));
                flash('success', 'Welcome back, ' . $user['first_name'] . '!');
                redirect($safeNext ?: ($user['role'] === 'admin' ? 'admin/index.php' : 'index.php'));
            }
        } else {
            $_SESSION['login_fail'] = ['n' => $_SESSION['login_fail']['n'] + 1, 't' => time()];
            $error = 'Wrong email or password. Please try again.';
        }
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
      <h1>Welcome!</h1>
      <p class="auth-sub">Sign in to your PCEC member account!</p>
      <span class="accent-line"></span>
      <?php auth_flashes(); ?>
      <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
      <?php if ($unconfirmed): ?>
        <form method="post" action="<?= e(url('check_email.php')) ?>" class="resend-box"><?= csrf_field() ?>
          <button class="btn btn-outline btn-block"><?= icon('mail') ?> Send the confirmation link again</button></form>
      <?php endif; ?>
      <form method="post" class="auth-form" novalidate>
        <?= csrf_field() ?>
        <?php if ($safeNext): ?><input type="hidden" name="next" value="<?= e($safeNext) ?>"><?php endif; ?>
        <label class="field">
          <?= icon('mail') ?>
          <input type="email" name="login" placeholder="Email Address" value="<?= old('login') ?>" autocomplete="email" inputmode="email" maxlength="160" required>
        </label>
        <label class="field">
          <?= icon('lock') ?>
          <input type="password" name="password" placeholder="Password" autocomplete="current-password" required>
          <button type="button" class="pw-toggle" aria-label="Show password"><?= icon('eye-off') ?></button>
        </label>
        <div class="row-between">
          <label class="check"><input type="checkbox" name="remember" value="1" checked><span></span><?= e(t('Remember me')) ?></label>
          <a href="<?= e(url('forgot.php')) ?>" class="link">Forgot your password?</a>
        </div>
        <button class="btn btn-gradient btn-lg btn-block"><?= icon('login') ?> <?= e(t('Log In')) ?></button>
      </form>
      <div class="divider"><span>NEW TO PCEC CONNECT?</span></div>
      <a class="btn btn-outline btn-lg btn-block signup-btn" href="<?= e(url('register.php')) ?>"><?= icon('user-plus') ?> Sign Up</a>
      <p class="install-link hide-standalone"><a href="<?= e(url('install.php')) ?>"><?= icon('download') ?> Install the PCEC App</a></p>
    </div>
  </div>
</div>
<?php auth_foot(); ?>
