<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/qr_pay.php';
$user = require_auth();
$active = 'qr';
header('Cache-Control: no-store');
$tab = ($_GET['tab'] ?? '') === 'myqr' ? 'myqr' : 'scan';
$pageTitle = 'Scan QR';
require __DIR__ . '/includes/header.php';
?>
<section class="screen with-nav qr-screen">
    <header class="page-head"><a href="dashboard.php" aria-label="Back"><span data-icon="arrow-left"></span></a><h1><?= $tab === 'myqr' ? 'My QR' : 'Scan QR' ?></h1><span></span></header>
    <div class="segmented" role="tablist"><button class="<?= $tab === 'scan' ? 'active' : '' ?>" type="button" data-tab="scan" role="tab">Scan</button><button class="<?= $tab === 'myqr' ? 'active' : '' ?>" type="button" data-tab="myqr" role="tab">My QR</button></div>

    <section class="qr-panel tab-panel <?= $tab === 'scan' ? 'active' : '' ?>" id="scan">
        <div class="qr-scanner">
            <div id="qr-reader" class="qr-reader"></div>
            <div class="qr-idle" data-qr-idle>
                <span data-icon="qr-code"></span>
                <p>Scan a merchant QR to pay (P2M) or a friend's QR to send Credits (P2P).</p>
                <button type="button" class="btn dark" data-qr-start>Open camera</button>
            </div>
            <i class="qr-corner tl"></i><i class="qr-corner tr"></i><i class="qr-corner bl"></i><i class="qr-corner br"></i>
        </div>
        <p class="qr-status" role="status" data-qr-status></p>
        <div class="quick-actions qr-tools">
            <button type="button" data-qr-torch disabled><span data-icon="zap"></span><small>Flash</small></button>
            <label class="qr-gallery"><input type="file" accept="image/*" data-qr-file hidden><span data-icon="image"></span><small>Gallery</small></label>
            <button type="button" data-qr-stop hidden><span data-icon="x"></span><small>Stop</small></button>
        </div>
        <form class="qr-manual" action="pay.php" method="get" data-qr-manual>
            <label for="qr-manual-code">Or enter a person (UA-) or merchant (UM-) QR ID</label>
            <div><input id="qr-manual-code" name="to" type="text" autocomplete="off" autocapitalize="characters" placeholder="UA-… or UM-…" pattern="[Uu][AaMm]-[A-Fa-f0-9]{10}" required><button type="submit" class="btn dark">Next</button></div>
        </form>
    </section>

    <section class="qr-panel tab-panel <?= $tab === 'myqr' ? 'active' : '' ?>" id="myqr">
        <div class="qr-box" data-qr-box data-pay-path="pay.php" data-unit="Credits" data-brand="Ultimate App Boracay" data-file="ultimate-app-qr">
        <div class="my-qr-card" data-my-qr data-code="<?= e($user['qr_code']) ?>">
            <div class="my-qr-brand"><span class="brand-symbol" aria-hidden="true"></span><span>ULTIMATE APP <b>BORACAY</b></span></div>
            <div class="my-qr-code" data-my-qr-target role="img" aria-label="Your payment QR code"></div>
            <strong><?= e(qr_display_name($user['full_name'])) ?></strong>
            <span class="my-qr-id"><?= e($user['qr_code']) ?></span>
            <p class="my-qr-amount" data-my-qr-amount hidden></p>
        </div>
        <form class="my-qr-request" data-my-qr-request>
            <label for="my-qr-amount">Request a specific amount <small>(optional)</small></label>
            <div><input id="my-qr-amount" type="number" inputmode="decimal" min="1" max="10000" step="0.01" placeholder="0.00"><button type="submit" class="btn light">Set</button></div>
        </form>
        <div class="my-qr-actions"><button type="button" class="btn light" data-my-qr-copy><span data-icon="receipt"></span> Copy link</button><button type="button" class="btn light" data-my-qr-download><span data-icon="download"></span> Save QR</button></div>
        <p class="my-qr-help">Anyone with Ultimate App can scan this to send you Credits. It never shows your balance. For BCash, use your <a href="bcash-qr.php?tab=myqr">BCash QR</a>.</p>
        </div>
    </section>
    <?php require __DIR__ . '/includes/nav.php'; ?>
</section>
<script defer src="assets/js/html5-qrcode.min.js"></script>
<script defer src="assets/js/qrcode.min.js"></script>
<script defer src="assets/js/qr.js?v=4"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
