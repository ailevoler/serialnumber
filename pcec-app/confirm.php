<?php
// Landing page for the link in the confirmation email.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout_auth.php';

$token = (string) ($_GET['token'] ?? '');
$row = $token !== '' ? q_one("SELECT t.*, u.status, u.first_name FROM email_tokens t JOIN users u ON u.id = t.user_id
                             WHERE t.token_hash = ? AND t.purpose = 'confirm'", [hash('sha256', $token)]) : null;
$problem = null;
if (!$row) {
    $problem = 'This confirmation link is not valid. It may have been replaced by a newer email.';
} elseif ($row['used_at'] && $row['status'] !== 'pending') {
    flash('success', 'Your email is already confirmed. You can log in now.');
    redirect('login.php');
} elseif ($row['used_at'] || strtotime($row['expires_at']) < time()) {
    $problem = 'This confirmation link has expired. Log in with your email and password to get a new one.';
} elseif ($row['status'] === 'suspended') {
    $problem = 'This account is suspended. Please contact the PCEC office.';
} else {
    q('UPDATE email_tokens SET used_at = NOW() WHERE id = ?', [$row['id']]);
    // Confirmed members can use the app straight away; admins review new members within 30 days.
    q("UPDATE users SET email_verified_at = NOW(), status = 'approved' WHERE id = ? AND status = 'pending'", [$row['user_id']]);
    notify((int) $row['user_id'], null, 'welcome', 'Welcome to PCEC Connect, ' . $row['first_name'] . '! Complete your profile and say hello in the Community Feed.', 'profile.php');
    foreach (q_all("SELECT id FROM users WHERE role = 'admin' AND id <> ?", [$row['user_id']]) as $a) {
        notify((int) $a['id'], (int) $row['user_id'], 'membership', 'New member joined: ' . $row['first_name'] . '. Review within 30 days.', 'admin/members.php');
    }
    login_user((int) $row['user_id'], false);
    unset($_SESSION['confirm_email'], $_SESSION['confirm_failed']);
    flash('success', 'Email confirmed. Welcome to PCEC Connect!');
    redirect('index.php');
}

auth_head('Confirm email');
?>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-top auth-top-sm"><div class="auth-top-bar"><span></span><?= lang_switch() ?></div><?php auth_brand(true); ?></div>
    <div class="auth-panel notice-panel">
      <div class="notice-ic is-off"><?= icon('link') ?></div>
      <h1>That link did not work</h1>
      <p class="auth-sub"><?= e($problem) ?></p>
      <a class="btn btn-gradient btn-block" href="<?= e(url('login.php')) ?>"><?= icon('login') ?> Go to Log In</a>
      <a class="btn btn-outline btn-block" href="<?= e(url('forgot.php')) ?>">Reset my password</a>
    </div>
  </div>
</div>
<?php auth_foot(); ?>
