<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
$me = require_login();

// Old links to someone else's profile go to their posts.
if (!empty($_GET['u'])) {
    $other = q_one('SELECT id FROM users WHERE username = ?', [(string) $_GET['u']]);
    redirect($other ? 'community_user.php?id=' . $other['id'] : 'members.php');
}

if (is_post()) {
    csrf_check();
    try {
        $av = handle_upload('avatar', 'avatars', ['image']);
        if ($av) {
            if ($me['avatar'] && str_starts_with($me['avatar'], 'uploads/') && is_file(__DIR__ . '/' . $me['avatar'])) @unlink(__DIR__ . '/' . $me['avatar']);
            q('UPDATE users SET avatar = ? WHERE id = ?', [$av[0], $me['id']]);
            flash('success', 'Profile picture updated.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('profile.php');
}

function member_pill(array $u): string
{
    if ($u['status'] === 'suspended') return '<span class="status status-failed">Suspended</span>';
    if ($u['status'] === 'pending') return '<span class="status status-pending">Confirm your email</span>';
    if (is_new_member($u)) return '<span class="status status-new">New Member</span>';
    return '<span class="status status-paid">Active Member</span>';
}

$menu = [
    ['profile_edit.php', 'user', 'Edit Profile', 'Update your personal information'],
    ['community_user.php?id=' . $me['id'], 'comment', 'My Posts', 'Everything you’ve shared in the Community Feed'],
    ['membership.php', 'shield', 'Membership & NDC', 'Your church, renewal and NDC news'],
    ['media.php', 'camera', 'Media', 'Pictures from church life'],
    ['my_giving.php', 'receipt', 'My Giving', 'Your gifts, event payments and receipts'],
    ['chat.php', 'chat', 'Chat', 'Messages with other members'],
    ['members.php', 'users', 'Members', 'Find and message other members'],
    ['contact.php', 'mail', 'Contact Us', 'Office details and send a message'],
    ['settings.php', 'settings', 'Settings', 'Password and app settings'],
];
if ($me['role'] === 'admin') $menu[] = ['admin/index.php', 'chart', 'Admin Dashboard', 'Members, content and messages'];

$pageTitle = 'Profile';
$activeNav = 'profile';
require __DIR__ . '/includes/header.php';
?>
<section class="card profile-card">
  <div class="profile-cover"></div>
  <div class="profile-main">
    <form method="post" enctype="multipart/form-data" class="photo-form-lg">
      <?= csrf_field() ?>
      <label class="photo-box" title="Change profile picture">
        <?= avatar($me, 'xxl') ?><span class="photo-cam"><?= icon('camera') ?></span>
        <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp" class="visually-hidden" onchange="this.form.submit()">
      </label>
    </form>
    <h2><?= e(display_name($me)) ?></h2>
    <p class="muted">Member since <?= e(date('F j, Y', strtotime($me['created_at']))) ?></p>
    <?= member_pill($me) ?>
  </div>
</section>

<p class="menu-heading">ACCOUNT SETTINGS</p>
<nav class="card menu" aria-label="Account">
  <?php foreach ($menu as [$href, $ic, $title, $sub]): ?>
    <a class="menu-item" href="<?= e(url($href)) ?>">
      <span class="menu-icon"><?= icon($ic) ?></span>
      <span class="menu-text"><strong><?= e($title) ?></strong><small><?= e($sub) ?></small></span>
      <?= icon('chevron-right', 'menu-chevron') ?>
    </a>
  <?php endforeach; ?>
</nav>
<a class="btn btn-outline btn-block" href="<?= e(url('logout.php')) ?>"><?= icon('logout') ?> LOG OUT</a>
<footer class="app-foot"><p>PCEC CONNECT</p><small>Version <?= e(APP_VERSION) ?></small></footer>
<?php require __DIR__ . '/includes/footer.php'; ?>
