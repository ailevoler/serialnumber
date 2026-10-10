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

<div class="mobile-only">
  <?php section_head('Upcoming Events', 'events.php'); ?>
  <?php foreach ($events as $ev) render_event($ev); ?>
  <?php if (!$events) empty_state('calendar', 'No upcoming events.'); ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
