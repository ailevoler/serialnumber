<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/service-seeds.php';
$user = require_auth();
$service = is_string($_GET['service'] ?? null) ? $_GET['service'] : '';
if ($service === 'URide') {
    redirect('uride.php');
}
if ($service === 'UGo' || $service === 'ULocal') {
    redirect(strtolower($service) . '.php');
}
if ($service === 'UEat') {
    redirect('ueat.php');
}
if ($service === 'UBarangay') {
    redirect('ubarangay.php');
}
$catalog = service_seed_catalog();
if (!isset($catalog[$service])) { http_response_code(404); exit('Service not found.'); }
$pageTitle = $service;
require __DIR__ . '/includes/header.php';
?>
<section class="screen seed-screen">
    <header class="page-head"><a href="dashboard.php" aria-label="Back"><span data-icon="arrow-left"></span></a><h1><?= e($service) ?></h1><a href="service-requests.php" aria-label="My requests" title="My requests"><span data-icon="receipt"></span></a></header>
    <div class="seed-heading"><small>ULTIMATE APP · BORACAY</small><h2><?= e($catalog[$service]['title']) ?></h2><p><?= e($catalog[$service]['intro']) ?></p></div>
    <p class="seed-notice">Phase 1 sample listings. Photos are illustrative; names, prices and availability are not live partner offers.</p>
        <div class="seed-grid <?= $service === 'UPass' ? 'pass-grid' : '' ?>">
        <?php foreach ($catalog[$service]['items'] as $item):
            $photo = (int) $item['photo'];
            $x = ($photo % 5) * 25;
            $y = intdiv($photo, 5) * (100 / 3);
            $requestLink = 'service-request.php?service=' . rawurlencode($service) . '&amp;item=' . rawurlencode($item['id']);
        ?>
            <article class="seed-card">
                <div class="seed-photo" style="background-position:<?= $x ?>% <?= $y ?>%" role="img" aria-label="Illustrative photo for <?= e($item['name']) ?>"></div>
                <div class="seed-body"><h3><?= e($item['name']) ?></h3><p><?= e($item['detail']) ?></p>
                    <?php if (isset($item['price'])): ?><div class="seed-price"><strong><?= number_format($item['price']) ?> Credits</strong><small> / <?= e($item['unit']) ?></small></div><?php endif; ?>
                    <?php if ($service === 'UEat'): ?><a class="seed-direction-link" href="directions.php?service=<?= e($service) ?>&amp;item=<?= e($item['id']) ?>">Get directions</a><?php endif; ?>
                    <?php if ($service !== 'UEat'): ?><a class="seed-action" href="<?= $requestLink ?>"><?= $service === 'ULocal' ? 'Inquire' : ($service === 'UPass' ? 'Get pass' : 'Book now') ?></a><?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
        </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
