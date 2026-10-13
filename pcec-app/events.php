<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
require_login();

$tab = in_array($_GET['tab'] ?? '', ['upcoming', 'past'], true) ? $_GET['tab'] : 'all';
$where = ['all' => '1=1', 'upcoming' => 'e.starts_at >= CURDATE()', 'past' => 'e.starts_at < CURDATE()'][$tab];
$events = fetch_events($where, [], 100, $tab === 'past' ? 'DESC' : 'ASC');
if ($tab === 'all') {
    // Upcoming first (soonest at the top), then past events (most recent first) — like the calendar view.
    $today = strtotime('today');
    $up = array_values(array_filter($events, fn($e) => strtotime($e['starts_at']) >= $today));
    $past = array_reverse(array_values(array_filter($events, fn($e) => strtotime($e['starts_at']) < $today)));
    $events = array_merge($up, $past);
}
$myRegs = [];
foreach (q_all('SELECT r.event_id, r.status, p.reference, p.status AS pay_status FROM event_registrations r
                LEFT JOIN payments p ON p.id = r.payment_id WHERE r.user_id = ?', [uid()]) as $r) {
    $myRegs[(int) $r['event_id']] = $r;
}
$empty = ['all' => ['No events yet', 'New PCEC events will be posted here.'],
          'upcoming' => ['No upcoming events', 'Check back soon. New events are added regularly.'],
          'past' => ['No past events', 'Events that have ended will appear here.']][$tab];

$pageTitle = 'Events';
$activeNav = 'events';
require __DIR__ . '/includes/header.php';
?>
<header class="page-intro">
  <h2>Events Calendar</h2>
  <p>Conferences, prayer gatherings and fellowship across the nation</p>
</header>
<div class="page-actions">
  <nav class="tabs-pill" aria-label="Filter events">
    <?php foreach (['all' => 'ALL', 'upcoming' => 'UPCOMING', 'past' => 'PAST'] as $k => $label): ?>
      <a href="<?= e(url('events.php' . ($k === 'all' ? '' : '?tab=' . $k))) ?>" class="<?= $tab === $k ? 'on' : '' ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= $label ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if (is_admin()): ?><a class="btn btn-sm btn-outline" href="<?= e(url('admin/events.php')) ?>"><?= icon('settings') ?> Manage events</a><?php endif; ?>
</div>

<div class="event-cards">
  <?php foreach ($events as $ev):
      $start = strtotime($ev['starts_at']);
      $isPast = $start < strtotime('today');
      $reg = $myRegs[(int) $ev['id']] ?? null;
      $registered = ($reg && $reg['status'] === 'confirmed') || (!$reg && $ev['is_going']);
      $pending = $reg && $reg['status'] === 'pending' && $reg['pay_status'] === 'pending';
      $fee = (int) $ev['fee'];
  ?>
    <article class="card event-card<?= $isPast ? ' is-past' : '' ?>">
      <a class="event-card-img" href="<?= e(url('event.php?id=' . $ev['id'])) ?>"<?= $ev['image'] ? ' style="background-image:url(\'' . e(url($ev['image'])) . '\')"' : '' ?>>
        <span class="date-chip"><?= e(strtoupper(date('D, M j', $start))) ?></span>
        <?php if ($fee): ?><span class="fee-chip"><?= money($fee) ?></span><?php else: ?><span class="fee-chip is-free">FREE</span><?php endif; ?>
      </a>
      <div class="event-card-body">
        <div class="event-card-top">
          <h3><a href="<?= e(url('event.php?id=' . $ev['id'])) ?>"><?= e($ev['title']) ?></a></h3>
          <a class="icon-btn" href="<?= e(url('event_ics.php?id=' . $ev['id'])) ?>" aria-label="Add to calendar" title="Add to calendar"><?= icon('calendar') ?></a>
        </div>
        <p class="event-meta"><?= icon('pin') ?><?= e(implode(' • ', array_filter([$ev['venue'] ?: $ev['location'], event_time_label($ev)]))) ?></p>
        <hr>
        <?php if ($isPast): ?>
          <button class="btn btn-outline btn-block" disabled>Event has ended</button>
        <?php elseif ($registered): ?>
          <?php if ($fee): ?>
            <a class="btn btn-outline btn-block is-on" href="<?= e(url('event.php?id=' . $ev['id'])) ?>"><?= icon('check') ?> Registered</a>
          <?php else: ?>
            <form method="post" action="<?= e(url('event_register.php')) ?>" data-confirm="Cancel your registration?"><?= csrf_field() ?>
              <input type="hidden" name="event_id" value="<?= (int) $ev['id'] ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="back" value="events">
              <button class="btn btn-outline btn-block is-on" aria-pressed="true"><?= icon('check') ?> Registered</button></form>
          <?php endif; ?>
        <?php elseif ($pending): ?>
          <a class="btn btn-primary btn-block" href="<?= e(url('payment.php?ref=' . urlencode($reg['reference']))) ?>">Complete payment <?= icon('arrow-right') ?></a>
        <?php elseif ($fee): ?>
          <a class="btn btn-outline btn-block" href="<?= e(url('event.php?id=' . $ev['id'] . '#registration')) ?>"><?= icon('plus-square') ?> Register · <?= money($fee) ?></a>
        <?php else: ?>
          <form method="post" action="<?= e(url('event_register.php')) ?>"><?= csrf_field() ?>
            <input type="hidden" name="event_id" value="<?= (int) $ev['id'] ?>"><input type="hidden" name="action" value="register"><input type="hidden" name="back" value="events">
            <button class="btn btn-outline btn-block" aria-pressed="false"><?= icon('plus-square') ?> Register</button></form>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>
<?php if (!$events) empty_state('calendar', $empty[0], $empty[1]); ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
