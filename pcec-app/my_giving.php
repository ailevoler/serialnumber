<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
require_login();

$rows = q_all("SELECT p.*, d.id AS donation_id, d.gift_type, d.frequency, r.event_id
               FROM payments p LEFT JOIN donations d ON d.payment_id = p.id LEFT JOIN event_registrations r ON r.payment_id = p.id
               WHERE p.user_id = ? AND p.status <> 'cancelled' ORDER BY p.created_at DESC LIMIT 200", [uid()]);
$yearTotal = (int) q_val("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE user_id = ? AND purpose = 'donation' AND status = 'paid' AND YEAR(paid_at) = YEAR(CURDATE())", [uid()]);
$allTotal = (int) q_val("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE user_id = ? AND purpose = 'donation' AND status = 'paid'", [uid()]);
// Latest paid gift per recurring schedule.
$pledges = q_all("SELECT d.id, d.frequency, d.fund, p.amount, p.paid_at FROM donations d JOIN payments p ON p.id = d.payment_id
                  WHERE d.user_id = ? AND d.gift_type = 'recurring' AND p.status = 'paid'
                    AND p.paid_at = (SELECT MAX(p2.paid_at) FROM donations d2 JOIN payments p2 ON p2.id = d2.payment_id
                                     WHERE d2.user_id = d.user_id AND d2.gift_type = 'recurring' AND d2.frequency = d.frequency AND d2.fund = d.fund AND p2.status = 'paid')
                  ORDER BY p.paid_at DESC", [uid()]);
$interval = ['monthly' => '+1 month', 'quarterly' => '+3 months', 'yearly' => '+1 year'];

$pageTitle = 'My Giving';
$activeNav = 'give';
require __DIR__ . '/includes/header.php';
?>
<section class="stat-row">
  <div class="card stat"><small>Given in <?= date('Y') ?></small><strong><?= money($yearTotal) ?></strong></div>
  <div class="card stat"><small>All-time giving</small><strong><?= money($allTotal) ?></strong></div>
</section>
<a class="btn btn-gradient btn-block give-cta" href="<?= e(url('give.php')) ?>"><?= icon('gift') ?> Give Now</a>

<?php if ($pledges): ?>
  <?php section_head('Recurring Gifts'); ?>
  <div class="card list-card">
    <?php foreach ($pledges as $pl): $due = strtotime($interval[$pl['frequency']], strtotime($pl['paid_at'])); ?>
      <div class="giving-row">
        <span class="giving-ic"><?= icon('repeat') ?></span>
        <span class="giving-text"><strong><?= money((int) $pl['amount']) ?> · <?= e(ucfirst($pl['frequency'])) ?></strong>
          <small><?= e($pl['fund']) ?> · next gift <?= $due <= time() ? '<b class="due">due now</b>' : 'on ' . e(date('M j, Y', $due)) ?></small></span>
        <a class="btn btn-sm <?= $due <= time() ? 'btn-gradient' : 'btn-outline' ?>" href="<?= e(url('give.php?repeat=' . $pl['id'])) ?>">Give again</a>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php section_head('History'); ?>
<div class="card list-card">
  <?php foreach ($rows as $r): ?>
    <a class="giving-row" href="<?= e(url('payment.php?ref=' . urlencode($r['reference']))) ?>">
      <span class="giving-ic <?= $r['purpose'] === 'event' ? 'is-event' : '' ?>"><?= icon($r['purpose'] === 'event' ? 'calendar' : 'gift') ?></span>
      <span class="giving-text"><strong><?= e($r['description']) ?></strong>
        <small><?= e(date('M j, Y', strtotime($r['created_at']))) ?> · <?= e(method_label($r['method'])) ?> · <span class="mono"><?= e($r['reference']) ?></span></small></span>
      <span class="giving-amt"><b><?= money((int) $r['amount']) ?></b><?= payment_status_badge($r['status']) ?></span>
    </a>
  <?php endforeach; ?>
  <?php if (!$rows) empty_state('gift', 'No gifts or payments yet.'); ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
