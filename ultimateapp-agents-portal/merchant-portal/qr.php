<?php
require_once __DIR__ . '/_bootstrap.php';
$m = merchant_require();
portal_header('My payment QR', 'qr');
if ($m['status'] !== 'approved'): ?>
<section class="card lock"><h2>Your QR unlocks after approval</h2><p class="muted">Status: <?= portal_badge($m['status']) ?>. Finish the onboarding checklist on your dashboard.</p><a class="btn primary" href="dashboard.php">Go to checklist</a></section>
<?php else: ?>
<div class="grid half">
    <div>
        <div class="qr-card" data-merchant-qr data-code="<?= e($m['code']) ?>">
            <div class="brand"><span class="brand-mark"></span><span><b>ULTIMATE APP</b><small>Scan to pay</small></span></div>
            <div class="qr-box" data-qr-target role="img" aria-label="Merchant payment QR code"></div>
            <strong><?= e($m['business_name']) ?></strong><span><?= e($m['code']) ?></span>
            <span class="amt" data-qr-amount hidden></span>
        </div>
    </div>
    <section class="card no-print"><h2>Use your QR</h2>
        <p class="muted">Print it for your counter, or set an amount for one customer. Customers pay with Credits or BCash; any customer-side charge is shown to them before they pay.</p>
        <form class="toolbar" data-qr-form><label for="amount">Amount (optional)</label><input id="amount" type="text" inputmode="decimal" placeholder="e.g. 350.00"><button class="btn" type="submit">Set amount</button><button class="btn" type="button" data-qr-clear>Clear</button></form>
        <p class="table-meta" data-qr-status role="status"></p>
        <div class="form-actions"><button class="btn primary" type="button" data-print>Print QR</button><button class="btn" type="button" data-qr-download>Download PNG</button><button class="btn" type="button" data-qr-copy>Copy pay link</button></div>
    </section>
</div>
<?php endif;
portal_footer(['../assets/js/qrcode.min.js', 'assets/qr.js?v=1']);
