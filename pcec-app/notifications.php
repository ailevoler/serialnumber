<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
require_login();

if (is_post()) {
    csrf_check();
    q('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [uid()]);
    redirect('notifications.php');
}

if (isset($_GET['open'])) {
    $n = q_one('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [(int) $_GET['open'], uid()]);
    if ($n) {
        q('UPDATE notifications SET is_read = 1 WHERE id = ?', [$n['id']]);
        redirect($n['link'] ?: 'notifications.php');
    }
}

$items = q_all('SELECT n.*, u.first_name, u.last_name, u.title, u.username, u.avatar FROM notifications n
                LEFT JOIN users u ON u.id = n.actor_id WHERE n.user_id = ? ORDER BY n.created_at DESC LIMIT 100', [uid()]);
$typeIcon = ['follow' => 'user-plus', 'like' => 'heart', 'comment' => 'comment', 'message' => 'chat', 'post' => 'edit', 'rsvp' => 'calendar', 'prayer' => 'pray', 'payment' => 'gift', 'reminder' => 'repeat'];

$pageTitle = 'Notifications';
$activeNav = 'notifications';
require __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <span class="muted"><?= count($items) ?> notifications</span>
  <form method="post"><?= csrf_field() ?><button class="btn btn-sm btn-outline"><?= icon('check') ?> Mark all as read</button></form>
</div>
<div class="card list-card">
  <?php foreach ($items as $n): ?>
    <a class="notif<?= $n['is_read'] ? '' : ' unread' ?>" href="?open=<?= (int) $n['id'] ?>">
      <span class="notif-avatar">
        <?= $n['username'] ? avatar($n, 'md') : '<span class="avatar avatar-md avatar-anon">' . icon('bell') . '</span>' ?>
        <span class="notif-type t-<?= e($n['type']) ?>"><?= icon($typeIcon[$n['type']] ?? 'bell') ?></span>
      </span>
      <span class="notif-text"><?= e($n['message']) ?><small><?= e(time_ago($n['created_at'])) ?></small></span>
    </a>
  <?php endforeach; ?>
  <?php if (!$items) empty_state('bell', "You're all caught up."); ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
