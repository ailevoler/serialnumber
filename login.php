<?php
require __DIR__ . '/includes/bootstrap.php';

if ($u = current_user()) {
    redirect($u['role'] === 'admin' ? 'admin/dashboard.php' : 'member/dashboard.php');
}

$error = null;
if (is_post()) {
    csrf_check();
    $error = attempt_login(input('username'), (string)($_POST['password'] ?? ''), 'member');
    if (!$error) {
        redirect('member/dashboard.php');
    }
}

auth_header('Member Login');
?>
<div class="gold-line"></div>
<h1>Welcome back!</h1>
<p class="lead">Sign in to your XGEN LIFE member account.</p>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<form method="post" autocomplete="on">
  <?= csrf_field() ?>
  <div class="form-group">
    <label for="username">Username or Email</label>
    <input class="input" id="username" name="username" value="<?= e(input('username')) ?>" required autofocus>
  </div>
  <div class="form-group">
    <label for="password">Password</label>
    <input class="input" id="password" type="password" name="password" required>
  </div>
  <button class="btn btn-primary btn-block" type="submit">Sign In</button>
</form>
<div class="auth-links">
  New member? <a href="<?= e(url('register.php' . (isset($_GET['ref']) ? '?ref=' . urlencode((string)$_GET['ref']) : ''))) ?>"><strong>Activate your account</strong></a>
  <br><a href="<?= e(url('admin/login.php')) ?>" class="small text-muted">Administrator login</a>
</div>
<?php auth_footer();
