<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

if (($u = current_user()) && in_array($u['role'], ['admin', 'cms'], true)) {
    redirect(home_for($u));
}

$error = null;
if (is_post()) {
    csrf_check();
    $error = attempt_login(input('username'), (string)($_POST['password'] ?? ''), 'admin');
    if (!$error) {
        redirect(home_for(current_user(true)));
    }
}

auth_header('Administrator Login');
?>
<div class="gold-line"></div>
<h1>Administrator Backoffice</h1>
<p class="lead">Authorized personnel only. Administrators and Content Managers sign in here.</p>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<form method="post">
  <?= csrf_field() ?>
  <div class="form-group">
    <label for="username">Username</label>
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
