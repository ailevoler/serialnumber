<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/bcash.php';
$user = require_auth();
$active = 'qr';
header('Cache-Control: no-store');
$tabs = ['scan' => 'Send BCash', 'receive' => 'Receive BCash', 'myqr' => 'My BCash QR'];
$tab = is_string($_GET['tab'] ?? null) && isset($tabs[$_GET['tab']]) ? $_GET['tab'] : 'scan';
$balance = bcash_transfers_ready($pdo) ? bcash_balance($pdo, (int) $user['id']) : 0;
$name = qr_display_name($user['full_name']);
$logo = 'assets/images/bcash/bcash-icon-192.png?v=1';
$pageTitle = $tabs[$tab];
require __DIR__ . '/includes/header.php';
?>
<section class="screen with-nav qr-screen bcash-page">
    <header class="page-head"><a href="dashboard.php?wallet=cash" aria-label="Back"><span data-icon="arrow-left"></span></a><h1><?= e($tabs[$tab]) ?></h1><span></span></header>
    <div class="segmented" role="tablist">
        <?php foreach (['scan' => 'Send', 'receive' => 'Receive', 'myqr' => 'My QR'] as $key => $label): ?><button class="<?= $tab === $key ? 'active' : '' ?>" type="button" data-tab="<?= $key ?>" data-title="<?= e($tabs[$key]) ?>" role="tab" aria-selected="<?= $tab === $key ? 'true' : 'false' ?>"><?= $label ?></button><?php endforeach; ?>
    </div>
    <p class="bcash-balance-line"><img src="assets/images/bcash/bcash-icon-96.png?v=1" alt="" width="20" height="20">Your BCash: <strong>PHP <?= peso($balance) ?></strong></p>

    <section class="qr-panel tab-panel <?= $tab === 'scan' ? 'active' : '' ?>" id="scan" data-qr-mode="bcash">
        <div class="qr-scanner">
            <div id="qr-reader" class="qr-reader"></div>
            <div class="qr-idle" data-qr-idle>
                <span data-icon="qr-code"></span>
                <p>Scan a friend's BCash QR to send BCash.</p>
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
        <form class="qr-manual" action="bcash-pay.php" method="get" data-qr-manual>
            <label for="qr-manual-code">Or enter their QR ID</label>
            <div><input id="qr-manual-code" name="to" type="text" autocomplete="off" autocapitalize="characters" placeholder="UA-…" pattern="[Uu][Aa]-[A-Fa-f0-9]{10}" required><button type="submit" class="btn dark">Next</button></div>
        </form>
    </section>

    <section class="qr-panel tab-panel <?= $tab === 'receive' ? 'active' : '' ?>" id="receive">
        <div class="qr-box" data-qr-box data-pay-path="bcash-pay.php" data-unit="PHP" data-logo="<?= e($logo) ?>" data-brand="BCash · Ultimate App Boracay" data-file="bcash-request" data-require-amount="1">
            <form class="my-qr-request" data-my-qr-request>
                <label for="bcash-request-amount">How much BCash do you want to receive?</label>
                <div><input id="bcash-request-amount" type="number" inputmode="decimal" min="1" max="10000" step="0.01" placeholder="0.00" required><button type="submit" class="btn dark">Create QR</button></div>
            </form>
            <div class="my-qr-card bcash-qr-card" data-my-qr data-code="<?= e($user['qr_code']) ?>" hidden>
                <div class="my-qr-brand"><img src="assets/images/bcash/bcash-logo.png?v=1" alt="BCash" width="720" height="223"></div>
                <div class="my-qr-code" data-my-qr-target role="img" aria-label="BCash request QR code"></div>
                <strong><?= e($name) ?></strong>
                <span class="my-qr-id"><?= e($user['qr_code']) ?></span>
                <p class="my-qr-amount" data-my-qr-amount hidden></p>
            </div>
            <div class="my-qr-actions" hidden data-qr-actions><button type="button" class="btn light" data-my-qr-copy><span data-icon="receipt"></span> Copy link</button><button type="button" class="btn light" data-my-qr-download><span data-icon="download"></span> Save QR</button></div>
            <p class="my-qr-help">Show this QR to the person paying you. The amount is locked, and the BCash goes straight to your BCash wallet.</p>
        </div>
    </section>

    <section class="qr-panel tab-panel <?= $tab === 'myqr' ? 'active' : '' ?>" id="myqr">
        <div class="qr-box" data-qr-box data-pay-path="bcash-pay.php" data-unit="PHP" data-logo="<?= e($logo) ?>" data-brand="BCash · Ultimate App Boracay" data-file="bcash-qr">
            <div class="my-qr-card bcash-qr-card" data-my-qr data-code="<?= e($user['qr_code']) ?>">
                <div class="my-qr-brand"><img src="assets/images/bcash/bcash-logo.png?v=1" alt="BCash" width="720" height="223"></div>
                <div class="my-qr-code" data-my-qr-target role="img" aria-label="Your BCash QR code"></div>
                <strong><?= e($name) ?></strong>
                <span class="my-qr-id"><?= e($user['qr_code']) ?></span>
                <p class="my-qr-amount" data-my-qr-amount hidden></p>
            </div>
            <div class="my-qr-actions" data-qr-actions><button type="button" class="btn light" data-my-qr-copy><span data-icon="receipt"></span> Copy link</button><button type="button" class="btn light" data-my-qr-download><span data-icon="download"></span> Save QR</button></div>
            <p class="my-qr-help">This is your BCash QR. It is separate from your Credits QR: anyone who scans it sends you BCash, never Credits. It never shows your balance.</p>
        </div>
    </section>
    <?php require __DIR__ . '/includes/nav.php'; ?>
</section>
<script defer src="assets/js/html5-qrcode.min.js"></script>
<script defer src="assets/js/qrcode.min.js"></script>
<script defer src="assets/js/qr.js?v=4"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
