<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

$ref = strtolower(input('ref'));
$form = ['sponsor' => $ref, 'position' => ''];
$lockSponsor = $ref !== '' && find_member_by_username($ref) !== null;
$codeOptions = null;
$error = null;

if (is_post()) {
    csrf_check();
    $form = form_values(['code', 'pin', 'sponsor', 'placement', 'position', 'first_name', 'last_name', 'email', 'mobile', 'address', 'username']);
    $form['password'] = (string)($_POST['password'] ?? '');
    try {
        $id = register_member($form);
        session_regenerate_id(true);
        $_SESSION['uid'] = $id;
        $_SESSION['role'] = 'member';
        flash('success', 'Welcome to XGEN LIFE Intl! Your account is now active.');
        redirect('member/dashboard.php');
    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    }
    unset($form['password']);
}

auth_header('Activate Account');
?>
<div class="gold-line"></div>
<h1>Join XGEN LIFE</h1>
<p class="lead">Activate your membership using your activation code and PIN.</p>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<form method="post">
  <?= csrf_field() ?>
  <?php require __DIR__ . '/includes/register_form.php'; ?>
  <button class="btn btn-primary btn-block mt-3" type="submit">Activate &amp; Create Account</button>
</form>
<div class="auth-links">Already a member? <a href="<?= e(url('login.php')) ?>"><strong>Sign in</strong></a></div>
<?php auth_footer();
