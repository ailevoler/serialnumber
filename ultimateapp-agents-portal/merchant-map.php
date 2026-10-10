<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/merchants.php';
$user = require_auth();
$category = is_string($_GET['category'] ?? null) && in_array($_GET['category'], merchant_categories(), true) ? $_GET['category'] : '';
$sql = "SELECT code, business_name, category, barangay, address, latitude, longitude FROM merchants WHERE status = 'approved'" . ($category ? ' AND category = ?' : '') . ' ORDER BY business_name LIMIT 300';
$stmt = $pdo->prepare($sql);
$stmt->execute($category ? [$category] : []);
$merchants = $stmt->fetchAll();
$points = [];
foreach ($merchants as $m) {
    if ($m['latitude'] !== null) $points[] = ['lat' => (float) $m['latitude'], 'lng' => (float) $m['longitude'], 'title' => $m['business_name'], 'sub' => $m['category'] . ' · ' . $m['barangay']];
}
$maps = google_maps_settings();
$pageTitle = 'Pay with Credits';
require __DIR__ . '/includes/header.php';
?>
<section class="screen with-nav merchant-map-screen" <?= google_maps_attrs() ?>>
    <header class="page-head"><a href="dashboard.php" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>Where to pay</h1><span></span></header>
    <p class="pay-fine">Merchants that accept Ultimate App Credits and BCash. Scan their QR at the counter to pay.</p>
    <form class="mm-filter" method="get"><select name="category" aria-label="Category" onchange="this.form.submit()"><option value="">All categories</option><?php foreach (merchant_categories() as $c): ?><option<?= $category === $c ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select><noscript><button type="submit">Filter</button></noscript></form>
    <?php if ($maps['enabled']): ?><div class="map-box" data-map-view data-markers="<?= e(json_encode($points)) ?>">Loading map…</div><p class="pay-fine" data-map-status></p><?php endif; ?>
    <div class="support-list mm-list">
        <?php if (!$merchants): ?><p class="pay-fine">No merchants yet<?= $category ? ' in this category' : '' ?>. Check back soon.</p><?php endif; ?>
        <?php foreach ($merchants as $m): ?>
            <a href="<?= $m['latitude'] !== null ? 'https://www.google.com/maps/dir/?api=1&amp;destination=' . e($m['latitude'] . ',' . $m['longitude']) : 'https://www.google.com/maps/search/?api=1&amp;query=' . e(rawurlencode($m['business_name'] . ', ' . $m['address'] . ', Boracay')) ?>" target="_blank" rel="noopener"><span><strong><?= e($m['business_name']) ?></strong><small><?= e($m['category']) ?> · <?= e($m['barangay']) ?></small></span><b>Directions</b></a>
        <?php endforeach; ?>
    </div>
    <?php require __DIR__ . '/includes/nav.php'; ?>
</section>
<script defer src="assets/js/gmaps.js?v=1"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
