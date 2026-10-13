<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
$me = require_login();

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

$items = q_all('SELECT * FROM notifications WHERE user_id = ? AND created_at > NOW() - INTERVAL 60 DAY ORDER BY created_at DESC LIMIT 100', [uid()]);
// [icon, tone, title]
$look = [
    'announcement' => ['bell', 'blue', 'New Announcement'], 'event' => ['calendar', 'navy', 'Upcoming Event'], 'ndc' => ['shield', 'navy', 'NDC Update'],
    'comment' => ['comment', 'blue', 'New Comment'], 'reaction' => ['heart', 'green', 'New Reaction'], 'membership' => ['user', 'navy', 'Membership'],
    'welcome' => ['heart', 'slate', 'Welcome'], 'message' => ['chat', 'blue', 'New Message'], 'payment' => ['receipt', 'green', 'Payment'],
    'rsvp' => ['calendar', 'navy', 'Event Registration'], 'report' => ['shield', 'red', 'Report'],
];
$groups = ['Today' => [], 'Yesterday' => [], 'Earlier' => []];
$today = strtotime('today');
foreach ($items as $n) {
    $t = strtotime($n['created_at']);
    $groups[$t >= $today ? 'Today' : ($t >= $today - 86400 ? 'Yesterday' : 'Earlier')][] = $n;
}
// Membership renewal reminder (computed, not stored).
$renew = null;
if ($me['membership_renewal_date']) {
    $days = (int) floor((strtotime($me['membership_renewal_date']) - $today) / 86400);
    if ($days <= 30) $renew = $days < 0 ? 'Your membership renewal date has passed. Please renew to stay active.' : "Your membership is due for renewal in $days day" . ($days === 1 ? '' : 's') . '.';
}
$unread = count(array_filter($items, fn($n) => !$n['is_read']));

$pageTitle = 'Notifications';
$activeNav = 'notifications';
require __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <header class="page-intro"><h2>Notifications</h2><p><?= $unread ? $unread . ' new' : 'You’re all caught up' ?></p></header>
  <?php if ($unread): ?><form method="post"><?= csrf_field() ?><button class="btn btn-sm btn-outline"><?= icon('check') ?> Mark all as read</button></form><?php endif; ?>
</div>
<?php if ($renew): ?>
  <a class="card notif-item is-unread" href="<?= e(url('membership.php')) ?>">
    <span class="notif-icon tone-navy"><?= icon('user') ?></span>
    <span class="notif-main"><span class="notif-top"><strong>Membership Renewal</strong></span><span class="notif-text"><?= e($renew) ?></span></span>
  </a>
<?php endif; ?>
<?php foreach ($groups as $label => $list): if (!$list) continue; ?>
  <h3 class="notif-group-title"><?= $label ?></h3>
  <div class="card list-card">
    <?php foreach ($list as $n): [$ic, $tone, $title] = $look[$n['type']] ?? ['bell', 'slate', 'Notification']; ?>
      <a class="notif-item<?= $n['is_read'] ? '' : ' is-unread' ?>" href="?open=<?= (int) $n['id'] ?>">
        <span class="notif-icon tone-<?= $tone ?>"><?= icon($ic) ?></span>
        <span class="notif-main">
          <span class="notif-top"><strong><?= e($title) ?></strong><small><?= e(time_ago($n['created_at'])) ?></small></span>
          <span class="notif-text"><?= e($n['message']) ?></span>
        </span>
        <?php if (!$n['is_read']): ?><span class="visually-hidden">(new)</span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<?php if (!$items && !$renew) empty_state('bell', 'No notifications yet', 'New announcements, events and replies to your posts will show up here.'); ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
