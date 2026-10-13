<?php
require __DIR__ . '/_admin.php';

$categories = ['National', 'Regional', 'Youth', 'Women', 'Men', 'Training', 'Worship', 'Outreach'];
$errors = [];
$editing = !empty($_GET['edit']) || !empty($_POST['edit_id']) ? q_one('SELECT * FROM events WHERE id = ?', [(int) ($_GET['edit'] ?? $_POST['edit_id'])]) : null;

if (is_post()) {
    csrf_check();
    if (($_POST['action'] ?? '') === 'delete') {
        q('DELETE FROM events WHERE id = ?', [(int) $_POST['id']]);
        flash('info', 'Event deleted.');
        redirect('admin/events.php');
    }
    $title = trim($_POST['title'] ?? '');
    $category = in_array($_POST['category'] ?? '', $categories, true) ? $_POST['category'] : 'National';
    $start = strtotime(($_POST['date'] ?? '') . ' ' . (($_POST['start'] ?? '') ?: '00:00'));
    $end = !empty($_POST['end']) ? strtotime((($_POST['end_date'] ?? '') ?: ($_POST['date'] ?? '')) . ' ' . $_POST['end']) : null;
    $fee = (int) round((float) str_replace(',', '', (string) ($_POST['fee'] ?? '0')) * 100);
    if ($title === '') $errors[] = 'Event name is required.';
    if (!$start) $errors[] = 'Please choose a valid date.';
    if ($start && $end && $end < $start) $errors[] = 'End time must be after the start time.';
    if ($fee < 0 || ($fee > 0 && $fee < MIN_PAYMENT)) $errors[] = 'A paid event needs a fee of at least ' . money(MIN_PAYMENT) . ' (or 0 for free).';
    try {
        $image = $errors ? null : admin_image('image', $editing['image'] ?? null);
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
    }
    if (!$errors) {
        $vals = [mb_substr($title, 0, 180), $category, trim($_POST['description'] ?? '') ?: null,
                 mb_substr(trim($_POST['location'] ?? ''), 0, 180) ?: null, mb_substr(trim($_POST['venue'] ?? ''), 0, 180) ?: null,
                 date('Y-m-d H:i:s', $start), $end ? date('Y-m-d H:i:s', $end) : null, $fee,
                 mb_substr(trim($_POST['fee_note'] ?? ''), 0, 255) ?: null,
                 mb_substr(implode(',', array_filter(array_map('trim', explode(',', (string) ($_POST['highlights'] ?? ''))))), 0, 255) ?: null,
                 (int) ($_POST['capacity'] ?? 0) ?: null, $image];
        if ($editing) {
            q('UPDATE events SET title=?, category=?, description=?, location=?, venue=?, starts_at=?, ends_at=?, fee=?, fee_note=?, highlights=?, capacity=?, image=? WHERE id=?', [...$vals, $editing['id']]);
            flash('success', 'Event updated.');
            redirect('event.php?id=' . $editing['id']);
        }
        q('INSERT INTO events (title, category, description, location, venue, starts_at, ends_at, fee, fee_note, highlights, capacity, image, user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [...$vals, uid()]);
        $newId = (int) db()->lastInsertId();
        if (!empty($_POST['notify'])) notify_all_members('event', 'Upcoming event: ' . $title . ', ' . date('M j', $start) . ($_POST['location'] ? ' · ' . trim($_POST['location']) : ''), 'event.php?id=' . $newId);
        flash('success', 'Event added.');
        redirect('event.php?id=' . $newId);
    }
}

$f = is_post() ? $_POST : ($editing ? [
    'title' => $editing['title'], 'category' => $editing['category'], 'location' => $editing['location'], 'venue' => $editing['venue'],
    'date' => date('Y-m-d', strtotime($editing['starts_at'])), 'start' => date('H:i', strtotime($editing['starts_at'])),
    'end' => $editing['ends_at'] ? date('H:i', strtotime($editing['ends_at'])) : '',
    'end_date' => $editing['ends_at'] && date('Ymd', strtotime($editing['ends_at'])) !== date('Ymd', strtotime($editing['starts_at'])) ? date('Y-m-d', strtotime($editing['ends_at'])) : '',
    'description' => $editing['description'], 'fee' => $editing['fee'] ? $editing['fee'] / 100 : '', 'fee_note' => $editing['fee_note'],
    'highlights' => $editing['highlights'], 'capacity' => $editing['capacity'],
] : ['start' => '09:00', 'highlights' => 'Worship,Prayer,Networking']);
$fv = fn($k) => e((string) ($f[$k] ?? ''));
$events = q_all('SELECT e.*, (SELECT COUNT(*) FROM event_rsvps r WHERE r.event_id = e.id) AS going FROM events e ORDER BY e.starts_at DESC');

$pageTitle = 'Admin';
$activeNav = 'admin';
require __DIR__ . '/../includes/header.php';
admin_tabs('events');
?>
<div class="admin-wrap">
  <div class="page-actions">
    <header class="dash-header"><div><h2>Events</h2><p>Members register from the Events tab</p></div></header>
    <button class="btn btn-gradient" data-open="event-modal"><?= icon('plus-square') ?> New event</button>
  </div>
  <div class="admin-list">
    <?php foreach ($events as $ev): $past = strtotime($ev['starts_at']) < strtotime('today'); ?>
      <article class="card admin-item row-card<?= $past ? ' is-muted' : '' ?>">
        <?php if ($ev['image']): ?><img class="row-img" src="<?= e(url($ev['image'])) ?>" alt=""><?php endif; ?>
        <div class="row-main"><strong><?= e($ev['title']) ?></strong>
          <small class="muted"><?= e(date('D, M j, Y g:i A', strtotime($ev['starts_at']))) ?><?= $ev['location'] ? ' · ' . e($ev['location']) : '' ?></small>
          <small><?= $ev['fee'] ? money((int) $ev['fee']) : 'Free' ?> · <?= (int) $ev['going'] ?> registered<?= $past ? ' · ended' : '' ?></small></div>
        <div class="admin-item-actions">
          <a class="btn btn-sm btn-outline" href="<?= e(url('event.php?id=' . $ev['id'])) ?>">View</a>
          <a class="btn btn-sm btn-outline" href="?edit=<?= (int) $ev['id'] ?>"><?= icon('edit') ?> Edit</a>
          <a class="btn btn-sm btn-outline" href="<?= e(url('admin/registrations.php?event=' . $ev['id'])) ?>"><?= icon('users') ?> Registrations</a>
          <form method="post" data-confirm="Delete “<?= e($ev['title']) ?>”? Registrations are removed too."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $ev['id'] ?>"><button class="icon-btn" aria-label="Delete"><?= icon('trash') ?></button></form>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
  <?php if (!$events) empty_state('calendar', 'No events yet'); ?>
</div>

<div class="modal" id="event-modal" <?= $editing || $errors || !empty($_GET['new']) ? '' : 'hidden' ?>>
  <div class="modal-card">
    <div class="modal-head"><h2><?= $editing ? 'Edit event' : 'New event' ?></h2><a class="icon-btn" href="<?= e(url('admin/events.php')) ?>" data-close aria-label="Close"><?= icon('x') ?></a></div>
    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
    <form method="post" enctype="multipart/form-data" class="form">
      <?= csrf_field() ?>
      <?php if ($editing): ?><input type="hidden" name="edit_id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
      <label>Event Name<input name="title" value="<?= $fv('title') ?>" required maxlength="180"></label>
      <div class="grid-2">
        <label>Category<select name="category"><?php foreach ($categories as $c): ?><option <?= ($f['category'] ?? '') === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></label>
        <label>Location<input name="location" value="<?= $fv('location') ?>" placeholder="e.g. Iloilo City"></label>
      </div>
      <label>Venue<input name="venue" value="<?= $fv('venue') ?>" placeholder="e.g. PCEC Conference Center"></label>
      <div class="grid-3">
        <label>Date<input type="date" name="date" value="<?= $fv('date') ?>" required></label>
        <label>Time (optional)<input type="time" name="start" value="<?= $fv('start') ?>"></label>
        <label>Ends<input type="time" name="end" value="<?= $fv('end') ?>"></label>
      </div>
      <label>End date (multi-day events)<input type="date" name="end_date" value="<?= $fv('end_date') ?>"></label>
      <label>Details<textarea name="description" rows="5" maxlength="8000"><?= $fv('description') ?></textarea></label>
      <div class="grid-2">
        <label>Registration fee (₱, 0 = free)<input type="number" name="fee" min="0" step="1" value="<?= $fv('fee') ?>" placeholder="0"></label>
        <label>Capacity (optional)<input type="number" name="capacity" min="1" value="<?= $fv('capacity') ?>"></label>
      </div>
      <label>Fee includes<input name="fee_note" value="<?= $fv('fee_note') ?>" placeholder="Event kit, lunch, and materials"></label>
      <label>Highlights (comma separated)<input name="highlights" value="<?= $fv('highlights') ?>" placeholder="Worship,Prayer,Networking,Breakout Sessions"></label>
      <label>Picture<?= $editing && $editing['image'] ? ' (leave empty to keep)' : '' ?><input type="file" name="image" accept="image/*"></label>
      <?php if (!empty($editing['image'])): ?><label class="check"><input type="checkbox" name="remove_image" value="1"><span></span>Remove current picture</label><?php endif; ?>
      <?php if (!$editing): ?><label class="check"><input type="checkbox" name="notify" value="1" checked><span></span>Notify all members</label><?php endif; ?>
      <button class="btn btn-gradient btn-block"><?= icon('check') ?> <?= $editing ? 'Save changes' : 'Add event' ?></button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
