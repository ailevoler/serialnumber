<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';

$filter = $_GET['filter'] ?? 'all';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$where = '1=1';
$params = [];
if ($filter === 'following') {
    $where = 'p.user_id IN (SELECT following_id FROM follows WHERE follower_id = ?) OR p.user_id = ?';
    $params = [uid(), uid()];
} elseif ($filter === 'saved') {
    $where = 'p.id IN (SELECT post_id FROM bookmarks WHERE user_id = ?)';
    $params = [uid()];
} elseif ($filter === 'mine') {
    $where = 'p.user_id = ?';
    $params = [uid()];
}
$posts = fetch_posts($where, $params, $perPage + 1, ($page - 1) * $perPage);
$hasMore = count($posts) > $perPage;
$posts = array_slice($posts, 0, $perPage);

$pageTitle = 'Posts';
$activeNav = 'posts';
$rightRail = right_rail();
require __DIR__ . '/includes/header.php';
?>
<section class="card composer-card">
  <?php $composerId = 'feed'; include __DIR__ . '/includes/composer_form.php'; ?>
</section>

<div class="chips">
  <?php foreach (['all' => 'All', 'following' => 'Following', 'saved' => 'Saved', 'mine' => 'My Posts'] as $k => $label): ?>
    <a href="?filter=<?= $k ?>" class="chip<?= $filter === $k ? ' on' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php foreach ($posts as $p) render_post($p); ?>
<?php if (!$posts) empty_state('edit', 'Nothing here yet.'); ?>

<div class="pager">
  <?php if ($page > 1): ?><a class="btn btn-outline" href="?filter=<?= e($filter) ?>&page=<?= $page - 1 ?>"><?= icon('arrow-left') ?> Newer</a><?php endif; ?>
  <?php if ($hasMore): ?><a class="btn btn-outline" href="?filter=<?= e($filter) ?>&page=<?= $page + 1 ?>">Older <?= icon('arrow-right') ?></a><?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
