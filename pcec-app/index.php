<?php
require __DIR__ . '/includes/bootstrap.php';

if (!current_user()) {
    redirect(empty($_COOKIE['pcec_onboarded']) ? 'welcome.php' : 'login.php');
}

require __DIR__ . '/includes/partials.php';

$pageTitle = 'Home';
$activeNav = 'home';
$hero = true;
$posts = fetch_posts('1=1', [], 5);
$events = fetch_events('e.starts_at >= CURDATE()', [], 3);
$rightRail = right_rail();
$featured = q_all('SELECT * FROM churches WHERE is_featured = 1 ORDER BY name LIMIT 8');

$tiles = [
    ['churches.php', 'church', 'Our Churches', 'blue'],
    ['members.php', 'users', 'Members', 'green'],
    ['events.php', 'calendar', 'Events', 'orange'],
    ['resources.php', 'book', 'Resources', 'purple'],
    ['prayer.php', 'pray', 'Prayer Requests', 'red'],
    ['chat.php', 'chat', 'Chat', 'sky'],
    ['posts.php', 'edit', 'Posts', 'teal'],
    ['members.php?filter=suggested', 'user-plus', 'Follow', 'mint'],
];

require __DIR__ . '/includes/header.php';
?>
<section class="card composer-card">
  <?php $composerId = 'home'; include __DIR__ . '/includes/composer_form.php'; ?>
</section>

<section class="card tiles">
  <?php foreach ($tiles as [$href, $ic, $label, $color]): ?>
    <a class="tile tile-<?= $color ?>" href="<?= e(url($href)) ?>"><?= icon($ic) ?><span><?= e(t($label)) ?></span></a>
  <?php endforeach; ?>
</section>

<section class="give-card-home">
  <div class="give-card-text">
    <h2>Support God’s Work</h2>
    <p>Your generous giving helps advance the mission, support churches, and reach more communities.</p>
    <a href="<?= e(url('give.php')) ?>" class="btn btn-primary">Give Now <?= icon('arrow-right') ?></a>
  </div>
</section>

<div class="mobile-only">
  <?php section_head('Upcoming Events', 'events.php'); ?>
  <?php foreach (array_slice($events, 0, 1) as $ev) render_event($ev); ?>
  <?php if (!$events) empty_state('calendar', 'No upcoming events.'); ?>
</div>

<?php if ($featured): ?>
  <?php section_head('Featured Churches', 'churches.php'); ?>
  <div class="featured-row">
    <?php foreach ($featured as $c): ?>
      <a class="featured-church" href="<?= e(url('churches.php?q=' . urlencode($c['name']))) ?>">
        <span class="featured-photo"<?= $c['photo'] ? ' style="background-image:url(\'' . e(url($c['photo'])) . '\')"' : '' ?>></span>
        <strong><?= e($c['name']) ?></strong>
        <small><?= e($c['city']) ?></small>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<section class="banner">
  <div class="banner-text">
    <h2>One Body<br>Many Churches<br>Greater Impact</h2>
    <p class="banner-pillars">Unity &middot; Evangelism &middot; Discipleship</p>
    <a href="<?= e(url('about.php')) ?>" class="btn btn-ghost-light"><?= e(t('Learn More')) ?> <?= icon('arrow-right') ?></a>
  </div>
</section>

<?php section_head('Latest Posts', 'posts.php'); ?>
<?php foreach ($posts as $p) render_post($p); ?>
<?php if (!$posts) empty_state('edit', 'No posts yet. Be the first to share!'); ?>



<?php require __DIR__ . '/includes/footer.php'; ?>
