<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_cms();
$uid = (int)$me['id'];

if (is_post()) {
    csrf_check();
    if (input('action') === 'profile') {
        if (input('first_name') === '' || !filter_var(input('email'), FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please enter your name and a valid email.');
        } else {
            q('UPDATE users SET first_name = ?, last_name = ?, email = ?, mobile = ? WHERE id = ?', [input('first_name'), input('last_name'), input('email'), input('mobile'), $uid]);
            flash('success', 'Profile updated.');
        }
    } else {
        $new = (string)($_POST['new_password'] ?? '');
        if (!password_verify((string)($_POST['current_password'] ?? ''), $me['password'])) {
            flash('error', 'Current password is incorrect.');
        } elseif ($err = validate_password($new)) {
            flash('error', $err);
        } elseif ($new !== ($_POST['confirm_password'] ?? '')) {
            flash('error', 'Passwords do not match.');
        } else {
            q('UPDATE users SET password = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $uid]);
            log_activity($uid, 'Changed password');
            flash('success', 'Password updated.');
        }
    }
    redirect('admin/cms_account.php');
}

$logs = q_all('SELECT * FROM activity_logs WHERE user_id = ? ORDER BY id DESC LIMIT 10', [$uid]);

page_header('My Account', $me, $me['role'] === 'cms' ? 'Content Manager' : 'Administrator');
?>
<div class="grid grid-2">
  <div class="card">
    <div class="card-header"><h3><?= icon('user') ?> Profile</h3></div>
    <div class="card-body">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="profile">
        <div class="form-grid">
          <div class="form-group"><label>First name</label><input class="input" name="first_name" value="<?= e($me['first_name']) ?>" required></div>
          <div class="form-group"><label>Last name</label><input class="input" name="last_name" value="<?= e($me['last_name']) ?>"></div>
          <div class="form-group full"><label>Email</label><input class="input" type="email" name="email" value="<?= e($me['email']) ?>" required></div>
          <div class="form-group full"><label>Mobile</label><input class="input" name="mobile" value="<?= e($me['mobile']) ?>"></div>
        </div>
        <button class="btn btn-primary mt-2" type="submit">Save Profile</button>
      </form>
      <div class="divider"></div>
      <h4 class="mb-1">Recent activity</h4>
      <ul class="list-plain small"><?php foreach ($logs as $l): ?><li><span><?= e($l['action']) ?></span><span class="text-muted" style="margin-left:auto"><?= fdatetime($l['created_at']) ?></span></li><?php endforeach; ?></ul>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h3><?= icon('key') ?> Change Password</h3></div>
    <div class="card-body">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="password">
        <div class="form-group"><label>Current password</label><input class="input" type="password" name="current_password" required></div>
        <div class="form-group"><label>New password</label><input class="input" type="password" name="new_password" minlength="8" required></div>
        <div class="form-group"><label>Confirm new password</label><input class="input" type="password" name="confirm_password" minlength="8" required></div>
        <button class="btn btn-gold btn-block" type="submit">Update Password</button>
      </form>
    </div>
  </div>
</div>
<?php page_footer();
