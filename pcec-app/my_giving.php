<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
require_login();

$rows = q_all("SELECT p.* FROM payments p WHERE p.user_id = ? AND p.status <> 'cancelled' ORDER BY p.created_at DESC LIMIT 200", [uid()]);
$yearTotal = (int) q_val("SELECT COALESCE(SUM(amount - fee_amount), 0) FROM payments WHERE user_id = ? AND purpose = 'donation' AND status = 'paid' AND YEAR(paid_at) = YEAR(CURDATE())", [uid()]);
$allTotal = (int) q_val("SELECT COALESCE(SUM(amount - fee_amount), 0) FROM payments WHERE user_id = ? AND purpose = 'donation' AND status = 'paid'", [uid()]);

$pageTitle = 'My Giving';
$activeNav = 'donate';
require __DIR__ . '/includes/header.php';
?>
<section class="stat-row">
  <div class="card stat"><small>Given in <?= date('Y') ?></small><strong><?= money($yearTotal) ?></strong></div>
  <div class="card stat"><small>All-time giving</small><strong><?= money($allTotal) ?></strong></div>
</section>
<a class="btn btn-gradient btn-block give-cta" href="<?= e(url('donate.php')) ?>"><?= icon('heart') ?> Give Now</a>

<?php section_head('History'); ?>
<div class="card list-card">
  <?php foreach ($rows as $r): $gift = (int) $r['amount'] - (int) $r['fee_amount']; ?>
    <div class="giving-row">
      <a class="giving-row-main" href="<?= e(url('payment.php?ref=' . urlencode($r['reference']))) ?>">
        <span class="giving-ic <?= $r['purpose'] === 'event' ? 'is-event' : '' ?>"><?= icon($r['purpose'] === 'event' ? 'calendar' : 'heart') ?></span>
        <span class="giving-text"><strong><?= e($r['description']) ?></strong>
          <small><?= e(date('M j, Y', strtotime($r['created_at']))) ?> · <span class="mono"><?= e($r['reference']) ?></span></small></span>
        <span class="giving-amt"><b><?= money($gift) ?></b><?= payment_status_badge($r['status']) ?></span>
      </a>
      <?php if ($r['purpose'] === 'donation' && $r['status'] === 'paid'): ?>
        <a class="btn btn-sm btn-outline" href="<?= e(url('donate.php?amount=' . intdiv($gift, 100))) ?>">Give again</a>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (!$rows) empty_state('heart', 'No gifts or payments yet.'); ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
