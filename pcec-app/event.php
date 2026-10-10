<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$ev = fetch_events('e.id = ?', [$id], 1)[0] ?? null;
if (!$ev) {
    flash('error', 'Event not found.');
    redirect('events.php');
}

if (is_post()) {
    csrf_check();
    if (($_POST['action'] ?? '') === 'delete' && ((int) $ev['user_id'] === uid() || is_admin())) {
        q('DELETE FROM events WHERE id = ?', [$id]);
        flash('success', 'Event deleted.');
        redirect('events.php');
    }
    if ($ev['is_going']) {
        q('DELETE FROM event_rsvps WHERE event_id = ? AND user_id = ?', [$id, uid()]);
    } else {
        q('INSERT IGNORE INTO event_rsvps (event_id, user_id) VALUES (?,?)', [$id, uid()]);
        notify((int) $ev['user_id'], uid(), 'rsvp', display_name(current_user()) . ' is going to ' . $ev['title'] . '.', 'event.php?id=' . $id);
    }
    redirect('event.php?id=' . $id);
}

$host = q_one('SELECT * FROM users WHERE id = ?', [$ev['user_id']]);
$attendees = q_all('SELECT u.* FROM event_rsvps r JOIN users u ON u.id = r.user_id WHERE r.event_id = ? LIMIT 12', [$id]);
$start = strtotime($ev['starts_at']);

$pageTitle = 'Event';
$activeNav = 'events';
$rightRail = right_rail();
require __DIR__ . '/includes/header.php';
?>
<article class="card event-detail">
  <div class="event-cover"<?= $ev['image'] ? ' style="background-image:url(\'' . e(url($ev['image'])) . '\')"' : '' ?>>
    <span class="tag tag-<?= e(strtolower($ev['category'])) ?>"><?= e(strtoupper($ev['category'])) ?></span>
  </div>
  <div class="event-detail-body">
    <h2><?= e($ev['title']) ?></h2>
    <ul class="meta-list">
      <li><?= icon('calendar') ?><?= e(date('l, F j, Y', $start)) ?><?= $ev['ends_at'] && date('Ymd', strtotime($ev['ends_at'])) !== date('Ymd', $start) ? ' – ' . e(date('F j, Y', strtotime($ev['ends_at']))) : '' ?></li>
      <li><?= icon('clock') ?><?= e(date('g:i A', $start)) ?><?= $ev['ends_at'] ? ' – ' . e(date('g:i A', strtotime($ev['ends_at']))) : '' ?></li>
      <?php if ($ev['location']): ?><li><?= icon('pin') ?><?= e($ev['location']) ?></li><?php endif; ?>
      <li><?= icon('user') ?>Hosted by <a href="<?= e(url('profile.php?u=' . $host['username'])) ?>"><?= e(display_name($host)) ?></a></li>
    </ul>
    <?php if ($ev['description']): ?><div class="prose"><?= rich_text($ev['description']) ?></div><?php endif; ?>
    <div class="event-cta">
      <form method="post"><?= csrf_field() ?>
        <button class="btn <?= $ev['is_going'] ? 'btn-outline' : 'btn-gradient' ?>"><?= icon($ev['is_going'] ? 'check' : 'calendar') ?> <?= $ev['is_going'] ? "You're going" : "I'm going" ?></button>
      </form>
      <button class="btn btn-outline" data-copy="<?= e(url('event.php?id=' . $id)) ?>"><?= icon('share') ?> Share</button>
      <?php if ((int) $ev['user_id'] === uid() || is_admin()): ?>
        <form method="post" data-confirm="Delete this event?"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
          <button class="btn btn-danger-outline"><?= icon('trash') ?> Delete</button></form>
      <?php endif; ?>
    </div>
    <h3 class="sub-title"><?= (int) $ev['going'] ?> going</h3>
    <div class="avatar-stack">
      <?php foreach ($attendees as $a): ?><a href="<?= e(url('profile.php?u=' . $a['username'])) ?>" title="<?= e(display_name($a)) ?>"><?= avatar($a, 'sm') ?></a><?php endforeach; ?>
    </div>
  </div>
</article>
<?php require __DIR__ . '/includes/footer.php'; ?>
