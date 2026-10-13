<?php
// Public page for account deletion (also the "delete account" link for app stores).
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout_auth.php';

$me = current_user();
$error = '';

if (is_post() && $me) {
    csrf_check();
    if (strtoupper(trim((string) ($_POST['confirm'] ?? ''))) !== 'DELETE') {
        $error = 'Type DELETE to confirm.';
    } elseif ($me['role'] === 'admin' && (int) q_val("SELECT COUNT(*) FROM users WHERE role = 'admin'") <= 1) {
        $error = 'You are the only administrator. Make another member an admin before deleting this account.';
    } else {
        $uid = (int) $me['id'];
        // Remove the member's files, then the account (posts, comments, reactions, media, messages… cascade).
        $files = array_merge(
            [$me['avatar']],
            array_column(q_all('SELECT media_path FROM posts WHERE user_id = ?', [$uid]), 'media_path'),
            array_column(q_all('SELECT path FROM media WHERE user_id = ?', [$uid]), 'path'),
        );
        q('DELETE FROM users WHERE id = ?', [$uid]);
        foreach (array_filter($files) as $f) {
            $abs = __DIR__ . '/' . $f;
            if (str_starts_with($f, 'uploads/') && is_file($abs)) @unlink($abs);
        }
        logout_user();
        redirect('account_deleted.php');
    }
}

$whatGoes = [
    'Your profile: name, email, phone, church and photo',
    'Everything you posted in the Community Feed, with your comments and reactions',
    'Photos you shared in Media and your chat messages',
    'Your event registrations and Contact Us messages',
    'Your login. You will be signed out on every device',
];
auth_head('Delete your account');
?>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-top auth-top-sm"><div class="auth-top-bar"><a href="<?= e(url($me ? 'settings.php' : 'login.php')) ?>" class="icon-btn light" aria-label="Back"><?= icon('arrow-left') ?></a><span></span></div><?php auth_brand(true); ?></div>
    <div class="auth-panel prose">
      <h1>Delete your account</h1>
      <p>You can delete your PCEC Connect account and the information linked to it at any time. Deleting removes:</p>
      <ul class="delete-list"><?php foreach ($whatGoes as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul>
      <p>It happens straight away and can’t be undone. Announcements and events posted by PCEC are not affected. Records of payments you made are kept without your profile, as required for accounting.</p>
      <h2>Delete it now</h2>
      <?php if ($me): ?>
        <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="form" data-confirm="Delete your account forever? This can't be undone.">
          <?= csrf_field() ?>
          <p>You’re signed in as <strong><?= e($me['email']) ?></strong>.</p>
          <label>Type DELETE to confirm<input name="confirm" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="DELETE" required></label>
          <button class="btn btn-danger btn-block"><?= icon('trash') ?> Delete my account forever</button>
        </form>
      <?php else: ?>
        <p>Sign in first, then come back to this page (or open Profile → Settings → Delete my account in the app).</p>
        <a class="btn btn-gradient btn-block" href="<?= e(url('login.php?next=delete_account.php')) ?>"><?= icon('login') ?> Sign in to delete my account</a>
      <?php endif; ?>
      <h2>Can’t sign in?</h2>
      <p>Use <a href="<?= e(url('forgot.php')) ?>">Forgot your password</a>, or ask the PCEC office (Data Protection Officer) to delete it for you. Write from the email address on the account so we know it’s you. See the <a href="<?= e(url('privacy.php')) ?>">Privacy Notice</a> for contact details.</p>
    </div>
  </div>
</div>
<?php auth_foot(); ?>
