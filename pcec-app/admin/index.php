<?php
require __DIR__ . '/_admin.php';

$s = q_one("SELECT
    COALESCE(SUM(CASE WHEN purpose = 'donation' AND status = 'paid' AND paid_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN amount END), 0) AS month_total,
    COALESCE(SUM(CASE WHEN purpose = 'donation' AND status = 'paid' THEN amount END), 0) AS all_total,
    COALESCE(SUM(CASE WHEN purpose = 'event' AND status = 'paid' THEN amount END), 0) AS event_total,
    COUNT(DISTINCT CASE WHEN purpose = 'donation' AND status = 'paid' THEN user_id END) AS donors,
    SUM(status = 'pending' AND method = 'bank' AND payer_reference IS NOT NULL) AS to_verify
  FROM payments");
$regs = (int) q_val("SELECT COUNT(*) FROM event_registrations WHERE status = 'confirmed'");
$byFund = q_all("SELECT d.fund, SUM(p.amount) AS total FROM donations d JOIN payments p ON p.id = d.payment_id WHERE p.status = 'paid' GROUP BY d.fund ORDER BY total DESC");
$recent = q_all("SELECT p.*, u.first_name, u.last_name FROM payments p LEFT JOIN users u ON u.id = p.user_id WHERE p.status <> 'cancelled' ORDER BY p.created_at DESC LIMIT 8");
$fundMax = max(1, ...array_map(fn($r) => (int) $r['total'], $byFund ?: [['total' => 1]]));

$pageTitle = 'Admin';
$activeNav = 'admin';
require __DIR__ . '/../includes/header.php';
admin_tabs('index');
?>
<div class="admin-wrap">
  <?php if (!PayMongo::fromSettings()): ?>
    <div class="alert alert-info">PayMongo is not connected yet. <a href="<?= e(url('admin/paymongo.php')) ?>">Set it up now</a> to accept QR Ph, cards and e-wallets.</div>
  <?php endif; ?>
  <section class="kpis">
    <div class="card kpi"><small>Donations this month</small><strong><?= money((int) $s['month_total']) ?></strong></div>
    <div class="card kpi"><small>Total donations</small><strong><?= money((int) $s['all_total']) ?></strong></div>
    <div class="card kpi"><small>Donors</small><strong><?= (int) $s['donors'] ?></strong></div>
    <div class="card kpi"><small>Event fees collected</small><strong><?= money((int) $s['event_total']) ?></strong></div>
    <div class="card kpi"><small>Confirmed registrations</small><strong><?= $regs ?></strong></div>
    <a class="card kpi <?= $s['to_verify'] ? 'kpi-alert' : '' ?>" href="<?= e(url('admin/payments.php?method=bank&status=pending')) ?>"><small>Bank transfers to verify</small><strong><?= (int) $s['to_verify'] ?></strong></a>
  </section>

  <?php if ($byFund): ?>
  <section class="card">
    <h2 class="card-title">Giving by fund</h2>
    <?php foreach ($byFund as $f): ?>
      <div class="bar-row"><span><?= e($f['fund']) ?></span><span class="bar"><span style="width:<?= round($f['total'] / $fundMax * 100) ?>%"></span></span><b><?= money((int) $f['total']) ?></b></div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <section class="card list-card">
    <div class="section-head" style="margin:8px 10px"><h2>Recent payments</h2><a href="<?= e(url('admin/payments.php')) ?>">See All <?= icon('chevron-right') ?></a></div>
    <?php foreach ($recent as $r): ?>
      <a class="giving-row" href="<?= e(url('admin/payments.php?q=' . urlencode($r['reference']))) ?>">
        <span class="giving-ic <?= $r['purpose'] === 'event' ? 'is-event' : '' ?>"><?= icon($r['purpose'] === 'event' ? 'calendar' : 'gift') ?></span>
        <span class="giving-text"><strong><?= e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'Guest') ?></strong><small><?= e($r['description']) ?> · <?= e(time_ago($r['created_at'])) ?></small></span>
        <span class="giving-amt"><b><?= money((int) $r['amount']) ?></b><?= payment_status_badge($r['status']) ?></span>
      </a>
    <?php endforeach; ?>
    <?php if (!$recent) empty_state('receipt', 'No payments yet.'); ?>
  </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
