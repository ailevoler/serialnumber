<?php
require __DIR__ . '/includes/bootstrap.php';

if (!current_user()) redirect('login.php');

require __DIR__ . '/includes/partials.php';

$pageTitle = 'Home';
$activeNav = 'home';
$hero = true;
$me = current_user();
$latest = q_one('SELECT * FROM announcements ORDER BY created_at DESC LIMIT 1');
$posts = fetch_posts('1=1', [], 3);
$events = fetch_events('e.starts_at >= CURDATE()', [], 1);
$rightRail = right_rail();
$renewal = $me['membership_renewal_date'];

require __DIR__ . '/includes/header.php';
?>
<section class="card quick-card">
  <?php section_head('Quick Actions'); ?>
  <div class="quick-grid">
    <a class="tile tile-red" href="<?= e(url('donate.php')) ?>"><?= icon('heart') ?><span>Donate</span></a>
    <a class="tile tile-orange" href="<?= e(url('events.php')) ?>"><?= icon('calendar') ?><span>Events</span></a>
    <a class="tile tile-purple" href="<?= e(url('media.php')) ?>"><?= icon('camera') ?><span>Media</span></a>
  </div>
</section>

<?php section_head('Latest News', 'news.php', 'View All'); ?>
<?php if ($latest): render_news_card($latest, true); else: ?>
  <div class="card news-card"><div class="news-text"><h3>No announcements yet</h3><p>Check back soon for the latest updates from PCEC.</p></div></div>
<?php endif; ?>

<div class="mobile-only">
  <?php section_head('Upcoming Event', 'events.php?tab=upcoming', 'View All'); ?>
  <?php foreach ($events as $ev) render_event($ev); ?>
  <?php if (!$events) empty_state('calendar', 'No upcoming events.'); ?>
</div>

<?php section_head('Community Feed', 'community.php', 'View All'); ?>
<button type="button" class="card composer-bar" data-open="composer-modal">
  <?= avatar($me, 'md') ?><span>Share a prayer request, a testimony or an encouraging word…</span><?= icon('camera') ?>
</button>
<?php foreach ($posts as $p) render_post($p); ?>
<?php if (!$posts): ?><p class="muted feed-hint">No posts yet. Share a prayer request, a testimony or an encouraging word.</p><?php endif; ?>

<section class="renew-banner">
  <?= icon('bell') ?>
  <div>
    <strong>Membership Renewal</strong>
    <span><?= $renewal ? 'Your membership is due for renewal on ' . e(date('F j, Y', strtotime($renewal))) . '.' : 'Renew your membership today to support our mission.' ?></span>
  </div>
  <a class="btn btn-sm btn-ghost-light" href="<?= e(url('membership.php')) ?>">RENEW</a>
</section>
<?= report_modal() ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
