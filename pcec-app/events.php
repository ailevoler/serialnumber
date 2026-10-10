<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
require_login();

$categories = ['National', 'Regional', 'Youth', 'Women', 'Men', 'Training', 'Worship', 'Outreach'];
$errors = [];

$editing = !empty($_GET['edit']) || !empty($_POST['edit_id'])
    ? q_one('SELECT * FROM events WHERE id = ?', [(int) ($_GET['edit'] ?? $_POST['edit_id'])]) : null;
if ($editing && (int) $editing['user_id'] !== uid() && !is_admin()) $editing = null;

if (is_post()) {
    csrf_check();
    $title = trim($_POST['title'] ?? '');
    $category = in_array($_POST['category'] ?? '', $categories, true) ? $_POST['category'] : 'National';
    $start = strtotime(($_POST['date'] ?? '') . ' ' . ($_POST['start'] ?? ''));
    $end = !empty($_POST['end']) ? strtotime((($_POST['end_date'] ?? '') ?: ($_POST['date'] ?? '')) . ' ' . $_POST['end']) : null;
    $fee = (int) round((float) str_replace(',', '', (string) ($_POST['fee'] ?? '0')) * 100);
    $capacity = (int) ($_POST['capacity'] ?? 0) ?: null;
    if ($title === '') $errors[] = 'Event title is required.';
    if (!$start) $errors[] = 'Please choose a valid date and start time.';
    if ($start && $end && $end < $start) $errors[] = 'End time must be after the start time.';
    if ($fee < 0 || ($fee > 0 && $fee < MIN_PAYMENT)) $errors[] = 'A paid event needs a fee of at least ' . money(MIN_PAYMENT) . ' (or 0 for free).';
    try {
        $img = $errors ? null : handle_upload('image', 'events', ['image']);
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
    }
    if (!$errors) {
        $vals = [mb_substr($title, 0, 180), $category, trim($_POST['description'] ?? '') ?: null,
                 mb_substr(trim($_POST['location'] ?? ''), 0, 180) ?: null, mb_substr(trim($_POST['venue'] ?? ''), 0, 180) ?: null,
                 date('Y-m-d H:i:s', $start), $end ? date('Y-m-d H:i:s', $end) : null, $fee,
                 mb_substr(trim($_POST['fee_note'] ?? ''), 0, 255) ?: null,
                 mb_substr(implode(',', array_filter(array_map('trim', explode(',', (string) ($_POST['highlights'] ?? ''))))), 0, 255) ?: null, $capacity];
        if ($editing) {
            q('UPDATE events SET title=?, category=?, description=?, location=?, venue=?, starts_at=?, ends_at=?, fee=?, fee_note=?, highlights=?, capacity=? WHERE id=?',
                [...$vals, $editing['id']]);
            if ($img) q('UPDATE events SET image = ? WHERE id = ?', [$img[0], $editing['id']]);
            flash('success', 'Event updated.');
            redirect('event.php?id=' . $editing['id']);
        }
        q('INSERT INTO events (title, category, description, location, venue, starts_at, ends_at, fee, fee_note, highlights, capacity, image, user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [...$vals, $img[0] ?? null, uid()]);
        flash('success', 'Event created.');
        redirect('event.php?id=' . db()->lastInsertId());
    }
}

// Form values: what was posted, else the event being edited, else defaults.
$f = is_post() ? $_POST : ($editing ? [
    'title' => $editing['title'], 'category' => $editing['category'], 'location' => $editing['location'], 'venue' => $editing['venue'],
    'date' => date('Y-m-d', strtotime($editing['starts_at'])), 'start' => date('H:i', strtotime($editing['starts_at'])),
    'end' => $editing['ends_at'] ? date('H:i', strtotime($editing['ends_at'])) : '',
    'end_date' => $editing['ends_at'] && date('Ymd', strtotime($editing['ends_at'])) !== date('Ymd', strtotime($editing['starts_at'])) ? date('Y-m-d', strtotime($editing['ends_at'])) : '',
    'description' => $editing['description'], 'fee' => $editing['fee'] ? $editing['fee'] / 100 : '', 'fee_note' => $editing['fee_note'],
    'highlights' => $editing['highlights'], 'capacity' => $editing['capacity'],
] : ['start' => '09:00', 'highlights' => 'Worship,Prayer,Networking']);
$fv = fn($k) => e((string) ($f[$k] ?? ''));

$tab = $_GET['tab'] ?? 'upcoming';
$cat = in_array($_GET['cat'] ?? '', $categories, true) ? $_GET['cat'] : '';
$where = $tab === 'past' ? 'e.starts_at < CURDATE()' : ($tab === 'going'
    ? 'e.starts_at >= CURDATE() AND e.id IN (SELECT event_id FROM event_rsvps WHERE user_id = ' . uid() . ')'
    : 'e.starts_at >= CURDATE()');
$params = [];
if ($cat) {
    $where .= ' AND e.category = ?';
    $params[] = $cat;
}
$events = fetch_events($where, $params, 50);
if ($tab === 'past') $events = array_reverse($events);

$pageTitle = 'Events';
$activeNav = 'events';
$showForm = !empty($_GET['new']) || $errors || $editing;
require __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
  <div class="chips">
    <?php foreach (['upcoming' => 'Upcoming', 'going' => "I'm Going", 'past' => 'Past'] as $k => $label): ?>
      <a href="?tab=<?= $k ?>" class="chip<?= $tab === $k ? ' on' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
  <button class="btn btn-gradient" data-open="event-modal"><?= icon('plus-square') ?> New Event</button>
</div>
<div class="chips chips-scroll">
  <a href="?tab=<?= e($tab) ?>" class="chip chip-sm<?= $cat === '' ? ' on' : '' ?>">All categories</a>
  <?php foreach ($categories as $c): ?>
    <a href="?tab=<?= e($tab) ?>&cat=<?= e($c) ?>" class="chip chip-sm<?= $cat === $c ? ' on' : '' ?>"><?= e($c) ?></a>
  <?php endforeach; ?>
</div>

<div class="event-list">
  <?php foreach ($events as $ev) render_event($ev); ?>
</div>
<?php if (!$events) empty_state('calendar', 'No events found.'); ?>

<div class="modal" id="event-modal" <?= $showForm ? '' : 'hidden' ?>>
  <div class="modal-card">
    <div class="modal-head"><h2><?= $editing ? 'Edit Event' : 'New Event' ?></h2><button class="icon-btn" data-close aria-label="Close"><?= icon('x') ?></button></div>
    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
    <form method="post" enctype="multipart/form-data" class="form">
      <?= csrf_field() ?>
      <?php if ($editing): ?><input type="hidden" name="edit_id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
      <label>Title<input name="title" value="<?= $fv('title') ?>" required maxlength="180"></label>
      <div class="grid-2">
        <label>Category<select name="category"><?php foreach ($categories as $c): ?><option <?= ($f['category'] ?? '') === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></label>
        <label>City / location<input name="location" value="<?= $fv('location') ?>" placeholder="Manila, Philippines"></label>
      </div>
      <label>Venue<input name="venue" value="<?= $fv('venue') ?>" placeholder="PCEC Conference Center"></label>
      <div class="grid-3">
        <label>Date<input type="date" name="date" value="<?= $fv('date') ?>" required></label>
        <label>Start<input type="time" name="start" value="<?= $fv('start') ?>" required></label>
        <label>End<input type="time" name="end" value="<?= $fv('end') ?>"></label>
      </div>
      <label>End date (multi-day events)<input type="date" name="end_date" value="<?= $fv('end_date') ?>"></label>
      <label>Description<textarea name="description" rows="4"><?= $fv('description') ?></textarea></label>
      <div class="grid-2">
        <label>Registration fee (₱, 0 = free)<input type="number" name="fee" min="0" step="1" value="<?= $fv('fee') ?>" placeholder="0"></label>
        <label>Capacity (optional)<input type="number" name="capacity" min="1" value="<?= $fv('capacity') ?>"></label>
      </div>
      <label>Fee includes<input name="fee_note" value="<?= $fv('fee_note') ?>" placeholder="Event kit, lunch, and materials"></label>
      <label>Highlights (comma separated)<input name="highlights" value="<?= $fv('highlights') ?>" placeholder="Worship,Prayer,Networking,Breakout Sessions"></label>
      <label>Cover image<?= $editing && $editing['image'] ? ' (leave empty to keep current)' : '' ?><input type="file" name="image" accept="image/*"></label>
      <button class="btn btn-gradient btn-block"><?= icon('check') ?> <?= $editing ? 'Save Changes' : 'Create Event' ?></button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
