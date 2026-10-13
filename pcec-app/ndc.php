<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
require_login();
$posts = q_all('SELECT * FROM ndc_posts ORDER BY created_at DESC LIMIT 100');
$pageTitle = 'NDC';
$activeNav = 'membership';
require __DIR__ . '/includes/header.php';
?>
<header class="page-intro"><h2>NDC</h2><p>Events, pictures and promotions</p></header>
<?php foreach ($posts as $n): ?>
  <article class="card ndc-card">
    <?php if ($n['image']): ?><img class="ndc-img" src="<?= e(url($n['image'])) ?>" alt="" loading="lazy"><?php endif; ?>
    <p class="ndc-date"><?= e(['event' => 'EVENT', 'picture' => 'PICTURES', 'promotion' => 'PROMOTION'][$n['post_type']]) ?> · <?= e(strtoupper(date('M j, Y', strtotime($n['created_at'])))) ?></p>
    <h3><?= e($n['title']) ?></h3>
    <?php if ($n['content']): ?><p class="pre-line"><?= e($n['content']) ?></p><?php endif; ?>
  </article>
<?php endforeach; ?>
<?php if (!$posts) empty_state('bell', 'Nothing posted yet', 'NDC events, pictures and promotions will appear here.'); ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
