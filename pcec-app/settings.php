<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
$me = require_login();
$pwError = '';

if (is_post()) {
    csrf_check();
    $new = (string) ($_POST['new'] ?? '');
    if (!password_verify((string) ($_POST['current'] ?? ''), $me['password_hash'])) $pwError = 'Your current password is incorrect.';
    elseif (strlen($new) < 8) $pwError = 'Use at least 8 characters.';
    elseif ($new !== ($_POST['confirm'] ?? '')) $pwError = "Passwords don't match.";
    else {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
        q('DELETE FROM remember_tokens WHERE user_id = ?', [$me['id']]); // sign out other devices
        flash('success', 'Password changed.');
        redirect('settings.php');
    }
}
$blocked = q_all('SELECT u.* FROM user_blocks b JOIN users u ON u.id = b.blocked_id WHERE b.blocker_id = ? ORDER BY b.created_at DESC', [$me['id']]);

$pageTitle = 'Settings';
$activeNav = 'settings';
require __DIR__ . '/includes/header.php';
?>
<section class="card">
  <p class="mc-label dark">CHANGE PASSWORD</p>
  <?php if ($pwError): ?><div class="alert alert-error"><?= e($pwError) ?></div><?php endif; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label>Current password<input type="password" name="current" autocomplete="current-password" required></label>
    <div class="grid-2">
      <label>New password<input type="password" name="new" minlength="8" autocomplete="new-password" required></label>
      <label>Confirm new password<input type="password" name="confirm" autocomplete="new-password" required></label>
    </div>
    <button class="btn btn-gradient btn-block"><?= icon('lock') ?> Change Password</button>
  </form>
</section>

<section class="card hide-standalone">
  <p class="mc-label dark">APP</p>
  <a class="btn btn-outline btn-block" href="<?= e(url('install.php')) ?>"><?= icon('download') ?> Install PCEC Connect</a>
  <p class="muted small">Adds PCEC Connect to your home screen so it opens like a normal app.</p>
</section>

<section class="card">
  <p class="mc-label dark">BLOCKED MEMBERS</p>
  <?php foreach ($blocked as $b): ?>
    <div class="person-row">
      <?= avatar($b, 'sm') ?><span class="person-name"><?= e(display_name($b)) ?></span>
      <form method="post" action="<?= e(url('community_action.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="unblock"><input type="hidden" name="user" value="<?= (int) $b['id'] ?>">
        <button class="btn btn-sm btn-outline">Unblock</button></form>
    </div>
  <?php endforeach; ?>
  <?php if (!$blocked): ?><p class="muted small">You haven’t blocked anyone. To block someone, tap ••• on one of their posts.</p><?php endif; ?>
</section>

<section class="card">
  <p class="mc-label dark">ABOUT THIS APP</p>
  <p class="muted small">PCEC Connect <?= e(APP_VERSION) ?> · Signed in as <?= e($me['email']) ?></p>
  <p class="small legal-links"><a class="link" href="<?= e(url('terms.php')) ?>">Terms of Service &amp; Community Rules</a> · <a class="link" href="<?= e(url('privacy.php')) ?>">Privacy Notice</a></p>
</section>

<section class="card danger-zone">
  <p class="mc-label dark">DELETE ACCOUNT</p>
  <p class="muted small">Permanently delete your account, your posts, photos and everything linked to you.</p>
  <a class="btn btn-danger-outline btn-block" href="<?= e(url('delete_account.php')) ?>"><?= icon('trash') ?> Delete my account</a>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
