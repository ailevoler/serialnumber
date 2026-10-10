<?php
require_once __DIR__ . '/includes/functions.php';
require_auth();
$views = [
    'bct' => ['BCash Token (BCT)', 'bcash-bct.png', 'The BCash Token is coming to Ultimate App.', 'BCT will be the BCash rewards and community token for Boracay. Here you will see your BCT balance, where it came from and how to use it.'],
    'buy' => ['Buy BCT', 'bcash-buybct.png', 'Buying BCT is not open yet.', 'When it opens, you will be able to get BCT using your BCash. Nothing is sold or charged on this page today.'],
    'markets' => ['Markets', 'bcash-markets.png', 'BCT Markets are coming soon.', 'Markets will show BCT prices and activity once BCT is launched. No prices are shown until then.'],
];
$view = is_string($_GET['view'] ?? null) && isset($views[$_GET['view']]) ? $_GET['view'] : 'bct';
[$title, $icon, $headline, $body] = $views[$view];
$pageTitle = $title;
require __DIR__ . '/includes/header.php';
?>
<section class="screen with-nav bcash-page bcash-soon">
    <header class="page-head"><a href="dashboard.php?wallet=cash" aria-label="Back"><span data-icon="arrow-left"></span></a><h1><?= e($title) ?></h1><span></span></header>
    <div class="bcash-soon-hero">
        <span class="bcash-soon-icon"><img src="assets/images/services/<?= e($icon) ?>?v=1" alt="" width="84" height="84"></span>
        <span class="bcash-soon-badge">Coming soon</span>
        <h2><?= e($headline) ?></h2>
        <p><?= e($body) ?></p>
    </div>
    <nav class="bcash-soon-tabs" aria-label="BCash Token">
        <?php foreach ($views as $key => [$label]): ?><a href="bcash-token.php?view=<?= $key ?>"<?= $key === $view ? ' aria-current="page" class="active"' : '' ?>><?= e($key === 'bct' ? 'BCT' : $label) ?></a><?php endforeach; ?>
    </nav>
    <h3 class="bcash-soon-sub">You can already use BCash</h3>
    <div class="community-links">
        <a href="bcash-qr.php"><span data-icon="arrow-right"></span><span><strong>Send BCash</strong><small>Scan a friend's BCash QR</small></span><span data-icon="chevron-right"></span></a>
        <a href="bcash-qr.php?tab=receive"><span data-icon="qr-code"></span><span><strong>Receive BCash</strong><small>Show a QR with the amount</small></span><span data-icon="chevron-right"></span></a>
        <a href="boracay-cash.php"><span data-icon="refresh-cw"></span><span><strong>Convert</strong><small>Credits and BCash, 1 to 1, no fee</small></span><span data-icon="chevron-right"></span></a>
    </div>
    <?php require __DIR__ . '/includes/nav.php'; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
