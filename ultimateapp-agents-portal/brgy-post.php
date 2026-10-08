<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_auth();
if (!ubarangay_enabled()) redirect('ubarangay.php');
$stmt = $pdo->prepare('SELECT * FROM brgy_posts WHERE id = ? AND published = 1');
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$p = $stmt->fetch();
if (!$p) { http_response_code(404); exit('Post not found.'); }
$pageTitle = $p['title'];
require __DIR__ . '/includes/header.php';
$tab = ['news' => 'news', 'announcement' => 'announcements', 'activity' => 'activities'][$p['type']];
?>
<section class="screen ub-screen">
    <header class="page-head"><a href="ubarangay.php?tab=<?= $tab ?>" aria-label="Back"><span data-icon="arrow-left"></span></a><h1><?= e(ucfirst($p['type'])) ?></h1><span></span></header>
    <article class="ub-article">
        <small><?= e($p['barangay'] ? 'Barangay ' . $p['barangay'] : 'All barangays') ?> · <?= e(date('F j, Y', strtotime($p['created_at']))) ?></small>
        <h2><?= e($p['title']) ?></h2>
        <?php if ($p['type'] === 'activity'): ?><div class="ub-when"><span data-icon="clock"></span><span><strong><?= $p['event_at'] ? e(date('l, F j, Y · g:i A', strtotime($p['event_at']))) : 'Date to follow' ?></strong><?php if ($p['location']): ?><small><?= e($p['location']) ?></small><?php endif; ?></span></div><?php endif; ?>
        <div class="ub-body"><?= nl2br(e($p['body'])) ?></div>
        <?php if ($p['location']): ?><a class="btn light" href="https://www.google.com/maps/search/?api=1&amp;query=<?= e(rawurlencode($p['location'] . ', Boracay, Malay, Aklan')) ?>" target="_blank" rel="noopener">Open location in Google Maps</a><?php endif; ?>
    </article>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
