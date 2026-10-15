<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_member();
$uid = (int)$me['id'];

if (is_post()) {
    csrf_check();
    if (input('action') === 'profile') {
        $email = input('email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || input('first_name') === '' || input('last_name') === '') {
            flash('error', 'Please provide a valid name and email.');
        } else {
            q('UPDATE users SET first_name = ?, last_name = ?, email = ?, mobile = ?, address = ?, payout_method = ?, payout_account_name = ?, payout_account_number = ? WHERE id = ?',
                [input('first_name'), input('last_name'), $email, input('mobile'), input('address'), input('payout_method'), input('payout_account_name'), input('payout_account_number'), $uid]);
            flash('success', 'Profile updated.');
        }
    } elseif (input('action') === 'password') {
        $new = (string)($_POST['new_password'] ?? '');
        if (!password_verify((string)($_POST['current_password'] ?? ''), $me['password'])) {
            flash('error', 'Current password is incorrect.');
        } elseif ($err = validate_password($new)) {
            flash('error', $err);
        } elseif ($new !== ($_POST['confirm_password'] ?? '')) {
            flash('error', 'New passwords do not match.');
        } else {
            q('UPDATE users SET password = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $uid]);
            log_activity($uid, 'Changed password');
            flash('success', 'Password changed successfully.');
        }
    }
    redirect('member/profile.php');
}

$sponsor = $me['sponsor_id'] ? q_row('SELECT username, first_name, last_name FROM users WHERE id = ?', [$me['sponsor_id']]) : null;
$placement = $me['placement_id'] ? q_row('SELECT username FROM users WHERE id = ?', [$me['placement_id']]) : null;

page_header('My Profile', $me, 'Personal and account settings');
?>
<div class="grid grid-main">
  <div class="card">
    <div class="card-header"><h3><?= icon('user') ?> Personal Information</h3></div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="profile">
        <div class="form-grid">
          <div class="form-group"><label>First Name</label><input class="input" name="first_name" value="<?= e($me['first_name']) ?>" required></div>
          <div class="form-group"><label>Last Name</label><input class="input" name="last_name" value="<?= e($me['last_name']) ?>" required></div>
          <div class="form-group"><label>Email</label><input class="input" type="email" name="email" value="<?= e($me['email']) ?>" required></div>
          <div class="form-group"><label>Mobile</label><input class="input" name="mobile" value="<?= e($me['mobile']) ?>"></div>
          <div class="form-group full"><label>Address</label><input class="input" name="address" value="<?= e($me['address']) ?>"></div>
        </div>
        <div class="section-title">Default Payout Account</div>
        <div class="form-grid">
          <div class="form-group"><label>Method</label>
            <select class="input" name="payout_method"><option value="">—</option><?php foreach (setting_list('payout_methods') as $m): ?><option <?= $me['payout_method'] === $m ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label>Account Name</label><input class="input" name="payout_account_name" value="<?= e($me['payout_account_name']) ?>"></div>
          <div class="form-group full"><label>Account / Mobile Number</label><input class="input" name="payout_account_number" value="<?= e($me['payout_account_number']) ?>"></div>
        </div>
        <button class="btn btn-primary mt-2" type="submit">Save Changes</button>
      </form>
    </div>
  </div>
  <div>
    <div class="card">
      <div class="card-header"><h3><?= icon('shield') ?> Account</h3></div>
      <div class="card-body">
        <dl class="kv">
          <dt>Username</dt><dd>@<?= e($me['username']) ?></dd>
          <dt>Package</dt><dd><span class="badge badge-gold"><?= e($me['package_name']) ?></span></dd>
          <dt>Sponsor</dt><dd><?= $sponsor ? '@' . e($sponsor['username']) : '—' ?></dd>
          <dt>Placement</dt><dd><?= $placement ? '@' . e($placement['username']) . ' (' . ($me['position'] === 'L' ? 'Left' : 'Right') . ')' : '—' ?></dd>
          <dt>Status</dt><dd><?= badge($me['status']) ?></dd>
          <dt>Joined</dt><dd><?= fdate($me['created_at']) ?></dd>
        </dl>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h3><?= icon('key') ?> Change Password</h3></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="password">
          <div class="form-group"><label>Current Password</label><input class="input" type="password" name="current_password" required></div>
          <div class="form-group"><label>New Password</label><input class="input" type="password" name="new_password" minlength="8" required></div>
          <div class="form-group"><label>Confirm New Password</label><input class="input" type="password" name="confirm_password" minlength="8" required></div>
          <button class="btn btn-gold btn-block" type="submit">Update Password</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php page_footer();
