<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

if (($u = current_user()) && $u['role'] === 'admin') {
    redirect('admin/dashboard.php');
}

$error = null;
if (is_post()) {
    csrf_check();
    $error = attempt_login(input('username'), (string)($_POST['password'] ?? ''), 'admin');
    if (!$error) {
        redirect('admin/dashboard.php');
    }
}

auth_header('Administrator Login');
?>
<div class="gold-line"></div>
<h1>Administrator Backoffice</h1>
<p class="lead">Authorized personnel only.</p>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<form method="post">
  <?= csrf_field() ?>
  <div class="form-group">
    <label for="username">Admin Username</label>
    <input class="input" id="username" name="username" value="<?= e(input('username')) ?>" required autofocus>
  </div>
  <div class="form-group">
    <label for="password">Password</label>
    <input class="input" id="password" type="password" name="password" required>
  </div>
  <button class="btn btn-gold btn-block" type="submit"><?= icon('shield', 16) ?> Sign In to Backoffice</button>
</form>
<div class="auth-links"><a href="<?= e(url('login.php')) ?>">&larr; Member login</a></div>
<?php auth_footer();
