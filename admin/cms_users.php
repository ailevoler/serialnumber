<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

if (is_post()) {
    csrf_check();
    $action = input('action');
    try {
        if ($action === 'create') {
            $username = strtolower(input('username'));
            $pw = (string)($_POST['password'] ?? '');
            if (!preg_match('/^[a-z0-9_.]{4,30}$/', $username)) {
                throw new RuntimeException('Username must be 4–30 characters (letters, numbers, underscore, dot).');
            }
            if (input('first_name') === '' || !filter_var(input('email'), FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please enter a name and a valid email.');
            }
            if (q_val('SELECT id FROM users WHERE username = ?', [$username])) {
                throw new RuntimeException('Username already exists.');
            }
            if ($err = validate_password($pw)) {
                throw new RuntimeException($err);
            }
            q("INSERT INTO users (username, email, password, first_name, last_name, mobile, role) VALUES (?,?,?,?,?,?,'cms')",
                [$username, input('email'), password_hash($pw, PASSWORD_DEFAULT), input('first_name'), input('last_name'), input('mobile')]);
            log_activity((int)$me['id'], "Created Content Manager @{$username}");
            flash('success', "Content Manager @{$username} created. They can sign in at the Administrator login.");
        } else {
            $u = q_row("SELECT * FROM users WHERE id = ? AND role = 'cms'", [input_int('id')]);
            if (!$u) {
                throw new RuntimeException('Account not found.');
            }
            if ($action === 'toggle') {
                q("UPDATE users SET status = IF(status = 'active', 'blocked', 'active') WHERE id = ?", [$u['id']]);
                flash('success', 'Account status updated.');
            } elseif ($action === 'reset') {
                $pw = (string)($_POST['new_password'] ?? '');
                if ($err = validate_password($pw)) {
                    throw new RuntimeException($err);
                }
                q('UPDATE users SET password = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
                flash('success', "Password reset for @{$u['username']}.");
            } elseif ($action === 'delete') {
                q("DELETE FROM users WHERE id = ? AND role = 'cms'", [$u['id']]);
                flash('success', "Account @{$u['username']} deleted.");
            }
            log_activity((int)$me['id'], "Content Manager @{$u['username']}: {$action}");
        }
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    } catch (PDOException $ex) {
        flash('error', 'This account has related records and cannot be deleted. Block it instead.');
    }
    redirect('admin/cms_users.php');
}

$rows = q_all("SELECT u.*, (SELECT COUNT(*) FROM cms_content c WHERE c.updated_by = u.id) edits FROM users u WHERE u.role = 'cms' ORDER BY u.id DESC");

page_header('Content Managers', $me, 'Accounts that can only manage the website CMS');
?>
<div class="grid grid-main">
  <div class="card">
    <div class="card-header"><h3><?= icon('users') ?> Content Manager Accounts</h3></div>
    <div class="card-body p-0 table-wrap">
      <table class="table stack">
        <thead><tr><th>Account</th><th>Email</th><th>Last login</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><div class="user-cell"><div class="avatar"><?= e(initials($r)) ?></div><div><strong><?= e(full_name($r)) ?></strong><small>@<?= e($r['username']) ?> · <?= (int)$r['edits'] ?> content field(s) edited</small></div></div></td>
            <td><?= e($r['email']) ?></td>
            <td class="nowrap"><?= fdatetime($r['last_login']) ?></td>
            <td><?= badge($r['status']) ?></td>
            <td class="nowrap">
              <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-xs btn-light" name="action" value="toggle" data-confirm="Change this account's status?"><?= $r['status'] === 'active' ? 'Block' : 'Activate' ?></button>
                <button class="btn btn-xs btn-light" name="action" value="delete" data-confirm="Delete this Content Manager account?">Delete</button></form>
              <details class="inline-reset"><summary class="btn btn-xs btn-outline">Reset password</summary>
                <form method="post" class="filters mt-1"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input class="input" name="new_password" minlength="8" placeholder="New password" required><button class="btn btn-xs btn-primary">Save</button></form></details>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows) echo empty_row(5, 'No Content Manager accounts yet.'); ?>
        </tbody>
      </table>
    </div>
  </div>
  <div>
    <div class="card">
      <div class="card-header"><h3><?= icon('user-plus') ?> New Content Manager</h3></div>
      <div class="card-body">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create">
          <div class="form-grid">
            <div class="form-group"><label>First name</label><input class="input" name="first_name" required></div>
            <div class="form-group"><label>Last name</label><input class="input" name="last_name"></div>
            <div class="form-group full"><label>Email</label><input class="input" type="email" name="email" required></div>
            <div class="form-group full"><label>Mobile</label><input class="input" name="mobile"></div>
            <div class="form-group"><label>Username</label><input class="input" name="username" pattern="[A-Za-z0-9_.]{4,30}" required></div>
            <div class="form-group"><label>Password</label><input class="input" type="text" name="password" minlength="8" required></div>
          </div>
          <button class="btn btn-primary btn-block mt-2" type="submit">Create Account</button>
        </form>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h3><?= icon('shield') ?> What they can access</h3></div>
      <div class="card-body small">
        <ul class="checklist-sm">
          <li class="yes">Website Overview &amp; home page mode</li>
          <li class="yes">Page sections, lists, testimonials, FAQ</li>
          <li class="yes">Media Library and website preview</li>
          <li class="yes">Their own password</li>
          <li class="no">Members, wallets, payouts, orders</li>
          <li class="no">Compensation plan, ERP, CRM, settings</li>
        </ul>
        <p class="text-muted mb-0">They sign in at <strong><?= e(absolute_url('admin/login.php')) ?></strong>.</p>
      </div>
    </div>
  </div>
</div>
<?php page_footer();
