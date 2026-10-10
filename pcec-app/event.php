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
$canEdit = (int) $ev['user_id'] === uid() || is_admin();
$self = 'event.php?id=' . $id;

if (is_post() && $canEdit) {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'delete':
                q('DELETE FROM events WHERE id = ?', [$id]);
                flash('success', 'Event deleted.');
                redirect('events.php');
            case 'add_speaker':
                $name = trim($_POST['name'] ?? '');
                if ($name === '') throw new RuntimeException('Speaker name is required.');
                $photo = handle_upload('photo', 'events', ['image']);
                q('INSERT INTO event_speakers (event_id, name, role, photo, sort) VALUES (?,?,?,?,?)',
                    [$id, mb_substr($name, 0, 120), mb_substr(trim($_POST['role'] ?? ''), 0, 160) ?: null, $photo[0] ?? null,
                     (int) q_val('SELECT COALESCE(MAX(sort), 0) + 1 FROM event_speakers WHERE event_id = ?', [$id])]);
                $tab = 'speakers';
                break;
            case 'del_speaker':
                q('DELETE FROM event_speakers WHERE id = ? AND event_id = ?', [(int) $_POST['item'], $id]);
                $tab = 'speakers';
                break;
            case 'add_sched':
                $title = trim($_POST['title'] ?? '');
                $time = trim($_POST['time_label'] ?? '');
                if ($title === '' || $time === '') throw new RuntimeException('Time and activity are required.');
                q('INSERT INTO event_schedule (event_id, time_label, title, description, sort) VALUES (?,?,?,?,?)',
                    [$id, mb_substr($time, 0, 40), mb_substr($title, 0, 180), mb_substr(trim($_POST['description'] ?? ''), 0, 500) ?: null,
                     (int) q_val('SELECT COALESCE(MAX(sort), 0) + 1 FROM event_schedule WHERE event_id = ?', [$id])]);
                $tab = 'schedule';
                break;
            case 'del_sched':
                q('DELETE FROM event_schedule WHERE id = ? AND event_id = ?', [(int) $_POST['item'], $id]);
                $tab = 'schedule';
                break;
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect($self . (isset($tab) ? '#' . $tab : ''));
}

$host = q_one('SELECT * FROM users WHERE id = ?', [$ev['user_id']]);
$attendees = q_all('SELECT u.* FROM event_rsvps r JOIN users u ON u.id = r.user_id WHERE r.event_id = ? LIMIT 12', [$id]);
$speakers = q_all('SELECT * FROM event_speakers WHERE event_id = ? ORDER BY sort, id', [$id]);
$schedule = q_all('SELECT * FROM event_schedule WHERE event_id = ? ORDER BY sort, id', [$id]);
$reg = q_one('SELECT * FROM event_registrations WHERE event_id = ? AND user_id = ?', [$id, uid()]);
$pay = $reg && $reg['payment_id'] ? payment_get((int) $reg['payment_id']) : null;
if ($pay && $pay['status'] === 'pending') {
    $pay = payment_refresh($pay);
    if ($pay['status'] === 'paid') $reg['status'] = 'confirmed';
}
$registered = $reg && $reg['status'] === 'confirmed' || (!$reg && $ev['is_going']);
$qrValid = $pay && $pay['status'] === 'pending' && $pay['method'] === 'qrph' && $pay['qr_image'] && strtotime((string) $pay['qr_expires_at']) > time();
$fee = (int) $ev['fee'];
$procFee = $fee && fee_config()['events'] ? processing_fee($fee) : 0;
$start = strtotime($ev['starts_at']);
$end = $ev['ends_at'] ? strtotime($ev['ends_at']) : null;
$past = $start < strtotime('today');
$full = $ev['capacity'] && (int) $ev['going'] >= (int) $ev['capacity'];
$highlights = array_values(array_filter(array_map('trim', explode(',', (string) $ev['highlights']))));
$hlIcon = ['worship' => 'music', 'prayer' => 'pray', 'networking' => 'users', 'breakout sessions' => 'book', 'training' => 'edit',
           'outdoor' => 'tree', 'fellowship' => 'chat', 'youth' => 'sun', 'preaching' => 'mic'];

$pageTitle = 'Event Details';
$activeNav = 'events';
$rightRail = right_rail();
require __DIR__ . '/includes/header.php';
?>
<section class="event-hero"<?= $ev['image'] ? ' style="--cover:url(\'' . e(url($ev['image'])) . '\')"' : '' ?>>
  <div class="event-hero-text">
    <span class="tag tag-<?= e(strtolower($ev['category'])) ?>"><?= e(strtoupper($ev['category'])) ?></span>
    <h2><?= e($ev['title']) ?></h2>
  </div>
</section>

<section class="card event-facts">
  <div class="fact-row">
    <span><?= icon('calendar') ?><?= e(date('F j, Y', $start)) ?><?= $end && date('Ymd', $end) !== date('Ymd', $start) ? ' – ' . e(date('M j', $end)) : '' ?></span>
    <span><?= icon('clock') ?><?= e(date('g:i A', $start)) ?><?= $end ? ' – ' . e(date('g:i A', $end)) : '' ?></span>
  </div>
  <?php if ($ev['venue'] || $ev['location']): ?>
    <div class="fact-row fact-venue"><?= icon('pin') ?><div><?php if ($ev['venue']): ?><span><?= e($ev['venue']) ?></span><?php endif; ?><small><?= e($ev['location']) ?></small></div></div>
  <?php endif; ?>

  <nav class="tabs" data-tabs>
    <a href="#overview" data-tab="overview" class="on">Overview</a>
    <a href="#speakers" data-tab="speakers">Speakers</a>
    <a href="#schedule" data-tab="schedule">Schedule</a>
    <a href="#registration" data-tab="registration">Registration</a>
  </nav>

  <div class="tab-panel" data-panel="overview" id="overview">
    <h3 class="panel-title">About This Event</h3>
    <div class="prose"><?= $ev['description'] ? rich_text($ev['description']) : '<p class="muted">No description yet.</p>' ?></div>
    <?php if ($highlights): ?>
      <div class="highlights">
        <?php foreach ($highlights as $i => $h): ?>
          <div class="hl hl-<?= $i % 4 ?>"><span><?= icon($hlIcon[strtolower($h)] ?? 'star') ?></span><?= e($h) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="host-row">
      <?= avatar($host, 'sm') ?>
      <span>Hosted by <a class="link" href="<?= e(url('profile.php?u=' . $host['username'])) ?>"><?= e(display_name($host)) ?></a></span>
      <button class="btn btn-sm btn-outline" data-copy="<?= e(url($self)) ?>"><?= icon('share') ?> Share</button>
    </div>
    <?php if ($attendees): ?>
      <h3 class="sub-title"><?= (int) $ev['going'] ?> going<?= $ev['capacity'] ? ' · ' . max(0, (int) $ev['capacity'] - (int) $ev['going']) . ' seats left' : '' ?></h3>
      <div class="avatar-stack">
        <?php foreach ($attendees as $a): ?><a href="<?= e(url('profile.php?u=' . $a['username'])) ?>" title="<?= e(display_name($a)) ?>"><?= avatar($a, 'sm') ?></a><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="tab-panel" data-panel="speakers" id="speakers">
    <h3 class="panel-title">Speakers</h3>
    <div class="speakers">
      <?php foreach ($speakers as $s): ?>
        <div class="speaker">
          <?= $s['photo'] ? '<img class="avatar avatar-xl" src="' . e(url($s['photo'])) . '" alt="">' : '<span class="avatar avatar-xl avatar-initials" style="--h:' . (crc32($s['name']) % 360) . '">' . e(mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', preg_replace('/^(Rev|Ptr|Bishop|Dr|Bro|Sis)\.?\s+/i', '', $s['name'])), 0, 2))))) . '</span>' ?>
          <strong><?= e($s['name']) ?></strong>
          <?php if ($s['role']): ?><small><?= e($s['role']) ?></small><?php endif; ?>
          <?php if ($canEdit): ?>
            <form method="post" data-confirm="Remove this speaker?"><?= csrf_field() ?><input type="hidden" name="action" value="del_speaker"><input type="hidden" name="item" value="<?= (int) $s['id'] ?>">
              <button class="icon-btn" aria-label="Remove"><?= icon('trash') ?></button></form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (!$speakers): ?><p class="muted">Speakers will be announced soon.</p><?php endif; ?>
    <?php if ($canEdit): ?>
      <form method="post" enctype="multipart/form-data" class="form inline-add">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_speaker">
        <div class="grid-2"><label>Name<input name="name" required maxlength="120"></label><label>Role / church<input name="role" maxlength="160"></label></div>
        <label>Photo (optional)<input type="file" name="photo" accept="image/*"></label>
        <button class="btn btn-outline btn-sm"><?= icon('plus-square') ?> Add speaker</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="tab-panel" data-panel="schedule" id="schedule">
    <h3 class="panel-title">Schedule</h3>
    <ol class="timeline">
      <?php foreach ($schedule as $s): ?>
        <li>
          <span class="tl-time"><?= e($s['time_label']) ?></span>
          <div class="tl-body"><strong><?= e($s['title']) ?></strong><?php if ($s['description']): ?><small><?= e($s['description']) ?></small><?php endif; ?></div>
          <?php if ($canEdit): ?>
            <form method="post" data-confirm="Remove this item?"><?= csrf_field() ?><input type="hidden" name="action" value="del_sched"><input type="hidden" name="item" value="<?= (int) $s['id'] ?>">
              <button class="icon-btn" aria-label="Remove"><?= icon('trash') ?></button></form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
    <?php if (!$schedule): ?><p class="muted">The program will be posted soon.</p><?php endif; ?>
    <?php if ($canEdit): ?>
      <form method="post" class="form inline-add">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_sched">
        <div class="grid-2"><label>Time<input name="time_label" required maxlength="40" placeholder="9:00 AM"></label><label>Activity<input name="title" required maxlength="180"></label></div>
        <label>Details (optional)<input name="description" maxlength="500"></label>
        <button class="btn btn-outline btn-sm"><?= icon('plus-square') ?> Add to schedule</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="tab-panel" data-panel="registration" id="registration">
    <div class="fee-card">
      <div class="fee-info">
        <h3>Registration Fee</h3>
        <?php if ($fee > 0): ?>
          <div class="fee-amount"><?= money($fee) ?><small>/person</small></div>
          <?php if ($procFee): ?><p class="fee-proc">+ <?= money($procFee, true) ?> processing fee · <b>Total <?= money($fee + $procFee, true) ?></b></p><?php endif; ?>
          <?php if ($ev['fee_note']): ?><p><?= e($ev['fee_note']) ?></p><?php endif; ?>
        <?php else: ?>
          <div class="fee-amount fee-free">FREE</div>
          <p>Registration is free. Reserve your seat below.</p>
        <?php endif; ?>
        <?php if ($registered): ?><span class="status status-paid"><?= icon('check') ?> You're registered</span>
        <?php elseif ($pay && $pay['status'] === 'pending'): ?><span class="status status-pending">Payment pending</span><?php endif; ?>
      </div>
      <?php if ($fee > 0): ?>
        <a class="fee-qr" href="<?= $pay && $pay['status'] === 'pending' ? e(url('payment.php?ref=' . urlencode($pay['reference']))) : '#register' ?>">
          <?= qrph_badge() ?>
          <?php if ($qrValid): ?>
            <img src="<?= e($pay['qr_image']) ?>" alt="QR Ph code">
            <small>Scan to Pay<br>Registration Fee</small>
          <?php elseif ($registered): ?>
            <span class="fee-qr-done"><?= icon('check') ?></span><small>Paid</small>
          <?php else: ?>
            <span class="qr-placeholder sm"><?= icon('qr') ?></span><small>Tap Register Now<br>to get your QR</small>
          <?php endif; ?>
        </a>
      <?php endif; ?>
    </div>
  </div>
</section>

<div class="event-cta-bar<?= $registered || $past || $full ? '' : ' is-sticky' ?>" id="register">
  <?php if ($registered): ?>
    <div class="cta-done"><?= icon('check') ?> You're registered<?= $fee ? ' and paid' : '' ?>.</div>
    <?php if (!$fee): ?>
      <form method="post" action="<?= e(url('event_register.php')) ?>" data-confirm="Cancel your registration?"><?= csrf_field() ?>
        <input type="hidden" name="event_id" value="<?= $id ?>"><input type="hidden" name="action" value="cancel">
        <button class="btn btn-outline">Cancel</button></form>
    <?php endif; ?>
  <?php elseif ($past): ?>
    <button class="btn btn-lg btn-block btn-outline" disabled>Registration closed</button>
  <?php elseif ($full): ?>
    <button class="btn btn-lg btn-block btn-outline" disabled>Event is full</button>
  <?php elseif ($pay && $pay['status'] === 'pending'): ?>
    <a class="btn btn-primary btn-lg btn-block" href="<?= e(url('payment.php?ref=' . urlencode($pay['reference']))) ?>">Complete Payment <?= icon('arrow-right') ?></a>
  <?php else: ?>
    <form method="post" action="<?= e(url('event_register.php')) ?>" class="cta-form"><?= csrf_field() ?>
      <input type="hidden" name="event_id" value="<?= $id ?>"><input type="hidden" name="action" value="register">
      <button class="btn btn-primary btn-lg btn-block">Register Now<?= $fee ? ' · ' . money($fee + $procFee, $procFee > 0) : '' ?> <?= icon('arrow-right') ?></button>
    </form>
  <?php endif; ?>
</div>

<?php if ($canEdit): ?>
  <div class="owner-actions">
    <a class="btn btn-sm btn-outline" href="<?= e(url('events.php?edit=' . $id)) ?>"><?= icon('edit') ?> Edit event</a>
    <?php if (is_admin()): ?><a class="btn btn-sm btn-outline" href="<?= e(url('admin/registrations.php?event=' . $id)) ?>"><?= icon('users') ?> Registrations</a><?php endif; ?>
    <form method="post" data-confirm="Delete this event?"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
      <button class="btn btn-sm btn-danger-outline"><?= icon('trash') ?> Delete</button></form>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
