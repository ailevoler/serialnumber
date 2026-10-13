<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';

$before = (int) ($_GET['before'] ?? 0);
$posts = fetch_posts('1=1', [], FEED_PAGE + 1, $before);
$more = count($posts) > FEED_PAGE;
$posts = array_slice($posts, 0, FEED_PAGE);

$pageTitle = 'Community Feed';
$activeNav = 'community';
$rightRail = right_rail();
require __DIR__ . '/includes/header.php';
?>
<header class="page-intro">
  <h2>Community Feed</h2>
  <p>Share, pray and encourage one another</p>
</header>

<?php if (!$before): ?>
  <section class="card composer-card">
    <?php $composerId = 'feed'; include __DIR__ . '/includes/composer_form.php'; ?>
  </section>
<?php endif; ?>

<?php foreach ($posts as $p) render_post($p); ?>
<?php if (!$posts) empty_state('comment', 'No posts yet', 'Share a prayer request, a testimony or an encouraging word.'); ?>

<div class="pager">
  <?php if ($before): ?><a class="btn btn-outline" href="<?= e(url('community.php')) ?>"><?= icon('arrow-left') ?> Newest posts</a><?php endif; ?>
  <?php if ($more): ?><a class="btn btn-outline" href="?before=<?= (int) end($posts)['id'] ?>">Load older posts <?= icon('chevron-down') ?></a><?php endif; ?>
</div>
<?= report_modal() ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
