<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
require_login();
$me = uid();

$username = (string) ($_GET['u'] ?? current_user()['username']);
$user = q_one('SELECT u.*, c.name AS church_name FROM users u LEFT JOIN churches c ON c.id = u.church_id WHERE u.username = ?', [$username]);
if (!$user) {
    flash('error', 'Member not found.');
    redirect('members.php');
}
$isMe = (int) $user['id'] === $me;
$errors = [];

if ($isMe && is_post()) {
    csrf_check();
    if (($_POST['action'] ?? '') === 'password') {
        $cur = (string) ($_POST['current'] ?? '');
        $new = (string) ($_POST['new'] ?? '');
        if (!password_verify($cur, $user['password_hash'])) $errors[] = 'Current password is incorrect.';
        elseif (strlen($new) < 8) $errors[] = 'New password must be at least 8 characters.';
        elseif ($new !== ($_POST['confirm'] ?? '')) $errors[] = 'New passwords do not match.';
        else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me]);
            flash('success', 'Password updated.');
            redirect('profile.php');
        }
    } else {
        $first = trim($_POST['first_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $church = (int) ($_POST['church_id'] ?? 0) ?: null;
        if ($first === '' || $last === '') $errors[] = 'First and last name are required.';
        try {
            $av = $errors ? null : handle_upload('avatar', 'avatars', ['image']);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
        if (!$errors) {
            q('UPDATE users SET first_name = ?, last_name = ?, title = ?, bio = ?, church_id = ? WHERE id = ?',
                [mb_substr($first, 0, 80), mb_substr($last, 0, 80), mb_substr(trim($_POST['title'] ?? ''), 0, 60) ?: null,
                 mb_substr(trim($_POST['bio'] ?? ''), 0, 500) ?: null, $church, $me]);
            if ($av) {
                if ($user['avatar'] && is_file(__DIR__ . '/' . $user['avatar'])) unlink(__DIR__ . '/' . $user['avatar']);
                q('UPDATE users SET avatar = ? WHERE id = ?', [$av[0], $me]);
            }
            flash('success', 'Profile updated.');
            redirect('profile.php');
        }
    }
}

$stats = q_one('SELECT
    (SELECT COUNT(*) FROM posts WHERE user_id = :id) AS posts,
    (SELECT COUNT(*) FROM follows WHERE following_id = :id2) AS followers,
    (SELECT COUNT(*) FROM follows WHERE follower_id = :id3) AS following', ['id' => $user['id'], 'id2' => $user['id'], 'id3' => $user['id']]);
$following = !$isMe && q_val('SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?', [$me, $user['id']]);
$posts = fetch_posts('p.user_id = ?', [$user['id']], 20);
$churches = $isMe ? q_all('SELECT id, name FROM churches ORDER BY name') : [];

$pageTitle = 'Profile';
$activeNav = 'profile';
require __DIR__ . '/includes/header.php';
?>
<section class="card profile-card">
  <div class="profile-cover"></div>
  <div class="profile-main">
    <?= avatar($user, 'xxl') ?>
    <h2><?= e(display_name($user)) ?></h2>
    <p class="muted">@<?= e($user['username']) ?><?= $user['church_name'] ? ' · ' . e($user['church_name']) : '' ?></p>
    <?php if ($user['role'] !== 'member'): ?><span class="tag tag-soft"><?= e(ucfirst($user['role'])) ?></span><?php endif; ?>
    <?php if ($user['bio']): ?><p class="profile-bio"><?= e($user['bio']) ?></p><?php endif; ?>
    <div class="profile-stats">
      <div><strong><?= (int) $stats['posts'] ?></strong><small>Posts</small></div>
      <a href="<?= e(url('members.php?filter=followers')) ?>"><strong data-followers><?= (int) $stats['followers'] ?></strong><small>Followers</small></a>
      <a href="<?= e(url('members.php?filter=following')) ?>"><strong><?= (int) $stats['following'] ?></strong><small>Following</small></a>
    </div>
    <div class="profile-actions">
      <?php if ($isMe): ?>
        <button class="btn btn-gradient" data-open="edit-profile"><?= icon('settings') ?> Edit Profile</button>
        <a class="btn btn-outline" href="<?= e(url('logout.php')) ?>"><?= icon('logout') ?> <?= e(t('Log Out')) ?></a>
      <?php else: ?>
        <button class="btn <?= $following ? 'btn-outline' : 'btn-gradient' ?>" data-follow="<?= (int) $user['id'] ?>"><?= $following ? 'Following' : e(t('Follow')) ?></button>
        <a class="btn btn-outline" href="<?= e(url('chat.php?to=' . $user['id'])) ?>"><?= icon('chat') ?> Message</a>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<?php section_head('Posts'); ?>
<?php foreach ($posts as $p) render_post($p); ?>
<?php if (!$posts) empty_state('edit', 'No posts yet.'); ?>

<?php if ($isMe): ?>
<div class="modal" id="edit-profile" <?= $errors ? '' : 'hidden' ?>>
  <div class="modal-card">
    <div class="modal-head"><h2>Edit Profile</h2><button class="icon-btn" data-close aria-label="Close"><?= icon('x') ?></button></div>
    <form method="post" enctype="multipart/form-data" class="form">
      <?= csrf_field() ?>
      <label>Profile photo<input type="file" name="avatar" accept="image/*"></label>
      <div class="grid-3">
        <label>Title<input name="title" value="<?= e($user['title']) ?>" placeholder="Rev., Ptr., Bro., Sis."></label>
        <label>First name<input name="first_name" value="<?= e($user['first_name']) ?>" required></label>
        <label>Last name<input name="last_name" value="<?= e($user['last_name']) ?>" required></label>
      </div>
      <label>Church<select name="church_id"><option value="">— None —</option><?php foreach ($churches as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $user['church_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
      <label>Bio<textarea name="bio" rows="3" maxlength="500"><?= e($user['bio']) ?></textarea></label>
      <button class="btn btn-gradient btn-block"><?= icon('check') ?> Save Profile</button>
    </form>
    <hr>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <h3>Change password</h3>
      <label>Current password<input type="password" name="current" required></label>
      <div class="grid-2">
        <label>New password<input type="password" name="new" minlength="8" required></label>
        <label>Confirm<input type="password" name="confirm" required></label>
      </div>
      <button class="btn btn-outline btn-block"><?= icon('lock') ?> Update Password</button>
    </form>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
