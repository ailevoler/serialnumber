<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/marketplace.php';
$user = require_auth();
header('Cache-Control: no-store');
$service = in_array($_GET['s'] ?? '', ['upass', 'ugo', 'ulocal'], true) ? (string) $_GET['s'] : '';
$rows = [];
if (mk_ready()) {
    $s = $pdo->prepare('SELECT b.*, l.photo, l.category FROM mk_bookings b JOIN mk_listings l ON l.id = b.listing_id WHERE b.user_id = ?' . ($service ? ' AND b.service = ?' : '') . ' ORDER BY (b.status IN (\'pending\',\'confirmed\')) DESC, b.booking_date DESC, b.id DESC LIMIT 60');
    $s->execute($service ? [$user['id'], $service] : [$user['id']]);
    $rows = $s->fetchAll();
}
$back = $service ? mk_services()[$service]['page'] : 'dashboard.php';
$pageTitle = 'My bookings';
require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="assets/css/market.css?v=3">
<section class="screen mk-screen">
    <header class="page-head"><a href="<?= e($back) ?>" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>My bookings</h1><span></span></header>
    <nav class="mk-chips"><a href="mk-bookings.php" class="<?= $service === '' ? 'on' : '' ?>">All</a><a href="mk-bookings.php?s=upass" class="<?= $service === 'upass' ? 'on' : '' ?>">UPass</a><a href="mk-bookings.php?s=ugo" class="<?= $service === 'ugo' ? 'on' : '' ?>">UGo activities</a><a href="mk-bookings.php?s=ulocal" class="<?= $service === 'ulocal' ? 'on' : '' ?>">ULocal services</a></nav>
    <?php if (!$rows): ?><p class="mk-empty">No bookings yet.</p><a class="mk-cta" href="<?= e($service === 'ulocal' ? 'ulocal.php' : 'ugo.php') ?>">Find something to book</a><?php endif; ?>
    <div class="mk-list">
    <?php foreach ($rows as $b): ?>
        <a href="mk-booking.php?ref=<?= e(rawurlencode($b['reference'])) ?>"><span class="mk-thumb" style="<?= mk_img_style($b['photo'], $b['service'], $b['category']) ?>"></span>
            <span><b><?= e($b['title']) ?></b><small><?= e(date('D, M j', strtotime($b['booking_date']))) ?><?= $b['booking_time'] ? ' · ' . e(date('g:i A', strtotime($b['booking_time']))) : '' ?> · <?= (int) $b['quantity'] ?> <?= mk_services()[$b['service']]['guests'] ? 'guest(s)' : 'x' ?></small></span>
            <span class="mk-status s-<?= e($b['status']) ?>"><?= e(mk_booking_statuses()[$b['status']]) ?></span></a>
    <?php endforeach; ?>
    </div>
    <?php require __DIR__ . '/includes/nav.php'; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
