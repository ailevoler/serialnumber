<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';

$id = (int) ($_GET['id'] ?? 0);
$item = $id ? q_one('SELECT * FROM announcements WHERE id = ?', [$id]) : null;
$news = $id ? [] : q_all('SELECT * FROM announcements ORDER BY created_at DESC LIMIT 50');
if ($id && !$item) {
    flash('error', 'This announcement may have been removed.');
    redirect('news.php');
}

$pageTitle = $item ? 'Announcement' : 'Latest News';
$activeNav = 'news';
$rightRail = right_rail();
require __DIR__ . '/includes/header.php';
?>
<?php if ($item): ?>
  <article class="card article">
    <?php if ($item['image']): ?><img class="article-img" src="<?= e(url($item['image'])) ?>" alt=""><?php endif; ?>
    <div class="article-body">
      <p class="article-kicker">Announcement · <?= e(date('F j, Y', strtotime($item['created_at']))) ?></p>
      <h2><?= e($item['title']) ?></h2>
      <div class="prose"><?php foreach (preg_split('/\n{2,}/', (string) $item['body']) as $para): ?><p><?= rich_text($para) ?></p><?php endforeach; ?></div>
      <button class="btn btn-outline btn-sm" data-copy="<?= e(url('news.php?id=' . $item['id'])) ?>"><?= icon('share') ?> Share</button>
    </div>
  </article>
  <a class="link back-link" href="<?= e(url('news.php')) ?>"><?= icon('arrow-left') ?> All announcements</a>
<?php else: ?>
  <header class="page-intro"><h2>Latest News</h2><p>Announcements from PCEC</p></header>
  <div class="news-grid">
    <?php foreach ($news as $i => $n) render_news_card($n, true); ?>
  </div>
  <?php if (!$news) empty_state('bell', 'No announcements yet', 'Check back soon for the latest updates from PCEC.'); ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
