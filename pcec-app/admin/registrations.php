<?php
require __DIR__ . '/_admin.php';

$events = q_all("SELECT e.id, e.title, e.starts_at, e.fee,
                   (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status = 'confirmed') AS confirmed,
                   (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status = 'pending') AS pending
                 FROM events e ORDER BY e.starts_at DESC");
$eventId = (int) ($_GET['event'] ?? ($events[0]['id'] ?? 0));
$event = q_one('SELECT * FROM events WHERE id = ?', [$eventId]);
$rows = $event ? q_all("SELECT r.*, u.first_name, u.last_name, u.title, u.email, u.username, c.name AS church, p.reference, p.method, p.amount - p.fee_amount AS amount, p.fee_amount, p.status AS pay_status
                        FROM event_registrations r JOIN users u ON u.id = r.user_id LEFT JOIN churches c ON c.id = u.church_id
                        LEFT JOIN payments p ON p.id = r.payment_id WHERE r.event_id = ? ORDER BY r.status = 'confirmed' DESC, r.created_at", [$eventId]) : [];

if ($event && ($_GET['export'] ?? '') === 'csv') {
    csv_out('registrations-' . $eventId . '.csv', ['Name', 'Email', 'Church', 'Status', 'Registered', 'Payment ref', 'Method', 'Amount (PHP)', 'Payment status'],
        array_map(fn($r) => [display_name($r), $r['email'], $r['church'], $r['status'], $r['created_at'], $r['reference'],
            $r['method'] ? method_label($r['method']) : '', $r['amount'] ? number_format($r['amount'] / 100, 2, '.', '') : '0.00', $r['pay_status']], $rows));
}

$pageTitle = 'Admin';
$activeNav = 'admin';
require __DIR__ . '/../includes/header.php';
admin_tabs('registrations');
?>
<div class="admin-wrap">
  <form class="filter-bar card" method="get">
    <select name="event" onchange="this.form.submit()" class="grow">
      <?php foreach ($events as $ev): ?>
        <option value="<?= (int) $ev['id'] ?>" <?= $ev['id'] == $eventId ? 'selected' : '' ?>><?= e(date('M j, Y', strtotime($ev['starts_at'])) . ' — ' . $ev['title']) ?> (<?= (int) $ev['confirmed'] ?>)</option>
      <?php endforeach; ?>
    </select>
    <?php if ($event): ?><a class="btn btn-outline" href="?event=<?= $eventId ?>&export=csv"><?= icon('download') ?> CSV</a><?php endif; ?>
  </form>
  <?php if ($event): ?>
    <section class="kpis kpis-3">
      <div class="card kpi"><small>Confirmed</small><strong><?= count(array_filter($rows, fn($r) => $r['status'] === 'confirmed')) ?></strong></div>
      <div class="card kpi"><small>Awaiting payment</small><strong><?= count(array_filter($rows, fn($r) => $r['status'] === 'pending')) ?></strong></div>
      <div class="card kpi"><small>Fees collected</small><strong><?= money(array_sum(array_map(fn($r) => $r['pay_status'] === 'paid' ? (int) $r['amount'] - (int) $r['fee_amount'] : 0, $rows))) ?></strong></div>
    </section>
    <div class="card list-card">
      <?php foreach ($rows as $r): ?>
        <div class="giving-row">
          <?= avatar($r, 'sm') ?>
          <span class="giving-text"><strong><?= e(display_name($r)) ?></strong><small><?= e($r['church'] ?: $r['email']) ?> · <?= e(date('M j, g:i A', strtotime($r['created_at']))) ?><?= $r['reference'] ? ' · <span class="mono">' . e($r['reference']) . '</span>' : '' ?></small></span>
          <span class="giving-amt"><?= $r['amount'] ? '<b>' . money((int) $r['amount']) . '</b>' : '<b>Free</b>' ?><span class="status status-<?= $r['status'] === 'confirmed' ? 'paid' : 'pending' ?>"><?= e(ucfirst($r['status'])) ?></span></span>
        </div>
      <?php endforeach; ?>
      <?php if (!$rows) empty_state('users', 'No registrations yet.'); ?>
    </div>
  <?php else: ?>
    <?php empty_state('calendar', 'No events yet.'); ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
