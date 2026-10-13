<?php
require __DIR__ . '/_admin.php';

$c = q_one("SELECT
    (SELECT COUNT(*) FROM users WHERE status = 'approved' AND reviewed_at IS NULL AND created_at > NOW() - INTERVAL 30 DAY) AS new_members,
    (SELECT COUNT(*) FROM users WHERE status = 'pending') AS unconfirmed,
    (SELECT COUNT(*) FROM contact_messages WHERE is_read = 0) AS unread,
    (SELECT COUNT(DISTINCT post_id) FROM post_reports WHERE resolved_at IS NULL) AS reports,
    (SELECT COALESCE(SUM(amount - fee_amount), 0) FROM payments WHERE purpose = 'donation' AND status = 'paid' AND paid_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS month_gifts,
    (SELECT COUNT(*) FROM users WHERE status = 'approved') AS members");
$name = current_user()['first_name'];
$cards = [
    ['admin/members.php', 'users', 'Member Management', 'View and manage members', (int) $c['new_members'] + (int) $c['unconfirmed']],
    ['admin/inbox.php', 'mail', 'Inbox', 'Contact Us messages', (int) $c['unread']],
    ['admin/community.php', 'comment', 'Community', 'Posts and reports', (int) $c['reports']],
    ['admin/announcements.php', 'bell', 'Announcements', 'Post updates', 0],
    ['admin/events.php', 'calendar', 'Events', 'Manage events', 0],
    ['admin/ndc.php', 'shield', 'NDC Posts', 'Events, pictures, promotions', 0],
    ['admin/payments.php', 'receipt', 'Donations', 'QR Ph gifts and event fees', 0],
    ['admin/paymongo.php', 'qr', 'QR Ph & Giving', 'PayMongo keys, amounts and fees', 0],
    ['admin/about.php', 'church', 'About & Office', 'Text and contact details', 0],
    ['admin/email.php', 'send', 'Email Settings', 'Confirmation and reset emails', 0],
    ['index.php', 'home', 'Member App', 'See what members see', 0],
];

$pageTitle = 'Admin';
$activeNav = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<div class="admin-wrap">
  <header class="dash-header">
    <div><h2>Admin Dashboard</h2><p>Welcome back, <?= e($name) ?></p></div>
  </header>
  <?php if (!PayMongo::fromSettings()): ?>
    <div class="alert alert-info">QR Ph giving is not connected yet. <a href="<?= e(url('admin/paymongo.php')) ?>">Set up PayMongo</a> to accept donations and event fees.</div>
  <?php endif; ?>
  <section class="kpis">
    <a class="card kpi" href="<?= e(url('admin/members.php?show=new')) ?>"><small>New members</small><strong><?= (int) $c['new_members'] ?></strong></a>
    <a class="card kpi" href="<?= e(url('admin/members.php?show=pending')) ?>"><small>Email not confirmed</small><strong><?= (int) $c['unconfirmed'] ?></strong></a>
    <a class="card kpi<?= $c['unread'] ? ' kpi-alert' : '' ?>" href="<?= e(url('admin/inbox.php')) ?>"><small>Unread messages</small><strong><?= (int) $c['unread'] ?></strong></a>
    <a class="card kpi" href="<?= e(url('admin/payments.php')) ?>"><small>Gifts this month</small><strong><?= money((int) $c['month_gifts']) ?></strong></a>
    <a class="card kpi" href="<?= e(url('admin/members.php?show=all')) ?>"><small>Active members</small><strong><?= (int) $c['members'] ?></strong></a>
    <a class="card kpi<?= $c['reports'] ? ' kpi-alert' : '' ?>" href="<?= e(url('admin/community.php')) ?>"><small>Reported posts</small><strong><?= (int) $c['reports'] ?></strong></a>
  </section>
  <nav class="dash-grid" aria-label="Admin">
    <?php foreach ($cards as [$href, $ic, $title, $sub, $badge]): ?>
      <a class="card dash-card" href="<?= e(url($href)) ?>">
        <span class="dash-card-icon"><?= icon($ic) ?></span>
        <?php if ($badge): ?><b class="pill dash-badge" aria-label="<?= $badge ?> need attention"><?= $badge ?></b><?php endif; ?>
        <strong><?= e($title) ?></strong><small><?= e($sub) ?></small>
      </a>
    <?php endforeach; ?>
  </nav>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
