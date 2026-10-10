<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
require_login();

$categories = ['National', 'Regional', 'Youth', 'Women', 'Men', 'Training', 'Worship', 'Outreach'];
$errors = [];

if (is_post()) {
    csrf_check();
    $title = trim($_POST['title'] ?? '');
    $category = in_array($_POST['category'] ?? '', $categories, true) ? $_POST['category'] : 'National';
    $start = strtotime(($_POST['date'] ?? '') . ' ' . ($_POST['start'] ?? ''));
    $end = !empty($_POST['end']) ? strtotime((($_POST['end_date'] ?? '') ?: ($_POST['date'] ?? '')) . ' ' . $_POST['end']) : null;
    if ($title === '') $errors[] = 'Event title is required.';
    if (!$start) $errors[] = 'Please choose a valid date and start time.';
    if ($start && $end && $end < $start) $errors[] = 'End time must be after the start time.';
    try {
        $img = $errors ? null : handle_upload('image', 'events', ['image']);
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
    }
    if (!$errors) {
        q('INSERT INTO events (user_id, title, category, description, location, starts_at, ends_at, image) VALUES (?,?,?,?,?,?,?,?)',
            [uid(), mb_substr($title, 0, 180), $category, trim($_POST['description'] ?? '') ?: null,
             mb_substr(trim($_POST['location'] ?? ''), 0, 180) ?: null, date('Y-m-d H:i:s', $start),
             $end ? date('Y-m-d H:i:s', $end) : null, $img[0] ?? null]);
        flash('success', 'Event created.');
        redirect('event.php?id=' . db()->lastInsertId());
    }
}

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
$showForm = !empty($_GET['new']) || $errors;
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
    <div class="modal-head"><h2>New Event</h2><button class="icon-btn" data-close aria-label="Close"><?= icon('x') ?></button></div>
    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
    <form method="post" enctype="multipart/form-data" class="form">
      <?= csrf_field() ?>
      <label>Title<input name="title" value="<?= old('title') ?>" required maxlength="180"></label>
      <div class="grid-2">
        <label>Category<select name="category"><?php foreach ($categories as $c): ?><option <?= ($_POST['category'] ?? '') === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></label>
        <label>Location<input name="location" value="<?= old('location') ?>" placeholder="City, venue"></label>
      </div>
      <div class="grid-3">
        <label>Date<input type="date" name="date" value="<?= old('date') ?>" required></label>
        <label>Start<input type="time" name="start" value="<?= old('start') ?: '09:00' ?>" required></label>
        <label>End<input type="time" name="end" value="<?= old('end') ?>"></label>
      </div>
      <label>End date (multi-day events)<input type="date" name="end_date" value="<?= old('end_date') ?>"></label>
      <label>Description<textarea name="description" rows="4"><?= old('description') ?></textarea></label>
      <label>Cover image<input type="file" name="image" accept="image/*"></label>
      <button class="btn btn-gradient btn-block"><?= icon('check') ?> Create Event</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
