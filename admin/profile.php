<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();
$uid = (int)$me['id'];

if (is_post()) {
    csrf_check();
    $action = input('action');
    if ($action === 'password') {
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
    } elseif ($action === 'add_admin') {
        $username = strtolower(input('username'));
        $pw = (string)($_POST['password'] ?? '');
        if (!preg_match('/^[a-z0-9_.]{4,30}$/', $username) || !filter_var(input('email'), FILTER_VALIDATE_EMAIL) || input('first_name') === '') {
            flash('error', 'Please provide a valid username, name and email.');
        } elseif (q_val('SELECT id FROM users WHERE username = ?', [$username])) {
            flash('error', 'Username already exists.');
        } elseif ($err = validate_password($pw)) {
            flash('error', $err);
        } else {
            q("INSERT INTO users (username, email, password, first_name, last_name, role) VALUES (?,?,?,?,?,'admin')",
                [$username, input('email'), password_hash($pw, PASSWORD_DEFAULT), input('first_name'), input('last_name')]);
            log_activity($uid, "Created admin {$username}");
            flash('success', "Administrator @{$username} created.");
        }
    } elseif ($action === 'toggle_admin') {
        $aid = input_int('id');
        if ($aid !== $uid) {
            q("UPDATE users SET status = IF(status = 'active', 'blocked', 'active') WHERE id = ? AND role = 'admin'", [$aid]);
            flash('success', 'Administrator status updated.');
        }
    }
    redirect('admin/profile.php');
}

$admins = q_all("SELECT * FROM users WHERE role = 'admin' ORDER BY id");
$logs = q_all('SELECT l.*, u.username FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 15');

page_header('My Account', $me, 'Administrators and security');
?>
<div class="grid grid-main">
  <div>
    <div class="card">
      <div class="card-header"><h3><?= icon('shield') ?> Administrators</h3></div>
      <div class="card-body p-0 table-wrap">
        <table class="table stack">
          <thead><tr><th>Admin</th><th>Email</th><th>Last login</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($admins as $a): ?>
            <tr>
              <td><strong><?= e(full_name($a)) ?></strong><br><small class="text-muted">@<?= e($a['username']) ?></small></td>
              <td><?= e($a['email']) ?></td><td class="nowrap"><?= fdatetime($a['last_login']) ?></td><td><?= badge($a['status']) ?></td>
              <td><?php if ((int)$a['id'] !== $uid): ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_admin"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-xs btn-light" data-confirm="Change this admin's status?"><?= $a['status'] === 'active' ? 'Block' : 'Activate' ?></button></form><?php else: ?><span class="badge badge-gold">You</span><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h3><?= icon('list') ?> Recent System Activity</h3></div>
      <div class="card-body p-0 table-wrap">
        <table class="table stack">
          <thead><tr><th>Date</th><th>User</th><th>Action</th><th>IP</th></tr></thead>
          <tbody>
          <?php foreach ($logs as $l): ?>
            <tr><td class="nowrap"><?= fdatetime($l['created_at']) ?></td><td><?= $l['username'] ? '@' . e($l['username']) : '—' ?></td><td><?= e($l['action']) ?></td><td class="small mono"><?= e($l['ip']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div>
    <div class="card">
      <div class="card-header"><h3><?= icon('key') ?> Change Password</h3></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="password">
          <div class="form-group"><label>Current Password</label><input class="input" type="password" name="current_password" required></div>
          <div class="form-group"><label>New Password</label><input class="input" type="password" name="new_password" minlength="8" required></div>
          <div class="form-group"><label>Confirm Password</label><input class="input" type="password" name="confirm_password" minlength="8" required></div>
          <button class="btn btn-gold btn-block" type="submit">Update Password</button>
        </form>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h3><?= icon('user-plus') ?> Add Administrator</h3></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="add_admin">
          <div class="form-grid">
            <div class="form-group"><label>First Name</label><input class="input" name="first_name" required></div>
            <div class="form-group"><label>Last Name</label><input class="input" name="last_name"></div>
          </div>
          <div class="form-group mt-1"><label>Email</label><input class="input" type="email" name="email" required></div>
          <div class="form-group"><label>Username</label><input class="input" name="username" required></div>
          <div class="form-group"><label>Password</label><input class="input" type="password" name="password" minlength="8" required></div>
          <button class="btn btn-primary btn-block" type="submit">Create Admin</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php page_footer();
