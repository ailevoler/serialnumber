<?php
declare(strict_types=1);

/**
 * Public "Get the apps" page (no login, no database). The same page is served at three URLs so that each
 * app can be installed from inside its own scope (browsers only offer to install the app whose manifest
 * the page links):
 *   /install.php (and /get-app.php)   → Ultimate App (users)
 *   /driver/install.php               → URide Driver
 *   /merchant-portal/install.php      → Ultimate App Merchant
 * $focus = the app this URL installs; $root = relative path to the app root ('' or '../').
 */
function get_app_render(string $focus, string $root): never
{
    $h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $apps = [
        'user' => [
            'name' => 'Ultimate App', 'tag' => 'For residents & travelers', 'icon' => 'icon-192.png',
            'install' => 'install.php', 'open' => 'index.php', 'manifest' => 'manifest.webmanifest', 'title' => 'Ultimate App',
            'desc' => 'Your Boracay super-app: wallet, payments and island services in one place.',
            'features' => ['Credits & BCash wallet with QR Ph top-up', 'Scan to pay merchants and send to friends', 'Book URide, order UEat, tours and island passes'],
        ],
        'driver' => [
            'name' => 'URide Driver', 'tag' => 'For E-Trike, motorcycle & car drivers', 'icon' => 'driver-192.png',
            'install' => 'driver/install.php', 'open' => 'driver/', 'manifest' => 'driver/manifest.webmanifest', 'title' => 'URide Driver',
            'desc' => 'Receive nearby ride requests, navigate trips and track your earnings.',
            'features' => ['Go online and accept nearby rides', 'Trip navigation, passenger contact & live status', 'Driver wallet: QR Ph, MCTC & BCash top-ups'],
        ],
        'merchant' => [
            'name' => 'Ultimate App Merchant', 'tag' => 'For shops, restaurants & MCTC centers', 'icon' => 'merchant-192.png',
            'install' => 'merchant-portal/install.php', 'open' => 'merchant-portal/', 'manifest' => 'merchant-portal/manifest.webmanifest', 'title' => 'UA Merchant',
            'desc' => 'Accept Credits and BCash payments and grow with Ultimate App.',
            'features' => ['Your payment QR and real-time sales', 'MCTC top-up center for customers & drivers', 'Payouts, reports and support in one place'],
        ],
    ];
    if (!isset($apps[$focus])) $focus = 'user';
    $me = $apps[$focus];
    $url = static fn(string $path): string => $root . $path;
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    $check = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5 10.5l3.2 3.2L15 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Get the apps · Ultimate App Boracay</title>
<meta name="description" content="Install Ultimate App, URide Driver and Ultimate App Merchant on your phone — no app store needed.">
<meta name="theme-color" content="#ffffff">
<link rel="manifest" href="<?= $h($url($me['manifest'])) ?>">
<link rel="icon" href="<?= $h($url('assets/images/pwa/' . $me['icon'])) ?>">
<link rel="apple-touch-icon" href="<?= $h($url('assets/images/pwa/' . str_replace('192', '180', $me['icon']))) ?>">
<meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="<?= $h($me['title']) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $h($url('assets/css/get-app.css?v=3')) ?>">
</head>
<body data-focus="<?= $h($focus) ?>" data-root="<?= $h($root) ?>">
<header class="ga-top">
    <div class="ga-wrap ga-top-inner">
        <a class="ga-brand" href="<?= $h($url('install.php')) ?>"><img src="<?= $h($url('assets/images/pwa/icon-192.png')) ?>" alt="" width="36" height="36"><span><b>Ultimate App</b><small>Boracay</small></span></a>
        <nav class="ga-nav" aria-label="Apps"><?php foreach ($apps as $k => $a): ?><a href="#<?= $k ?>"><?= $h($a['name']) ?></a><?php endforeach; ?><a href="#how">How to install</a></nav>
    </div>
</header>

<main>
<section class="ga-hero">
    <div class="ga-wrap">
        <p class="ga-eyebrow">Download center</p>
        <h1>Get the Ultimate App Boracay apps</h1>
        <p class="ga-lead">Fast, secure apps for travelers, drivers and businesses on the island. Install straight from your browser — no app store, no large download, always up to date.</p>
        <ul class="ga-trust">
            <li><?= $check ?> Secure HTTPS</li><li><?= $check ?> Android &amp; iPhone</li><li><?= $check ?> Under 1 MB</li><li><?= $check ?> Automatic updates</li>
        </ul>
    </div>
</section>

<section class="ga-wrap ga-apps" aria-label="Apps">
<?php foreach ($apps as $k => $a): $here = $k === $focus; ?>
    <article class="ga-card<?= $here ? ' is-here' : '' ?>" id="<?= $k ?>" data-app="<?= $k ?>" data-install-url="<?= $h($url($a['install'])) ?>">
        <div class="ga-card-head">
            <img src="<?= $h($url('assets/images/pwa/' . str_replace('192', '512', $a['icon']))) ?>" alt="" width="64" height="64" class="ga-icon">
            <div><h2><?= $h($a['name']) ?></h2><p><?= $h($a['tag']) ?></p></div>
        </div>
        <p class="ga-desc"><?= $h($a['desc']) ?></p>
        <ul class="ga-features"><?php foreach ($a['features'] as $f): ?><li><?= $check ?><span><?= $h($f) ?></span></li><?php endforeach; ?></ul>
        <div class="ga-actions">
            <?php if ($here): ?>
                <button type="button" class="ga-btn primary" data-install><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 3v10m0 0l-4-4m4 4l4-4M4 16h12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Install app</span></button>
            <?php else: ?>
                <a class="ga-btn primary" href="<?= $h($url($a['install'])) ?>#install"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 3v10m0 0l-4-4m4 4l4-4M4 16h12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Install app</span></a>
            <?php endif; ?>
            <a class="ga-btn ghost" href="<?= $h($url($a['open'])) ?>">Open in browser</a>
        </div>
        <p class="ga-status" role="status" data-status></p>
        <div class="ga-qr" aria-hidden="true"><div class="ga-qr-code" data-qr></div><p>Scan with your phone camera to install</p></div>
    </article>
<?php endforeach; ?>
</section>

<section class="ga-how" id="how">
    <div class="ga-wrap">
        <h2>How to install</h2>
        <p class="ga-sub">Takes about 10 seconds. The app appears on your home screen and opens full-screen like any other app.</p>
        <div class="ga-tabs" role="tablist">
            <button type="button" role="tab" aria-selected="true" data-tab="android">Android</button>
            <button type="button" role="tab" aria-selected="false" data-tab="ios">iPhone &amp; iPad</button>
            <button type="button" role="tab" aria-selected="false" data-tab="desktop">Computer</button>
        </div>
        <div class="ga-steps" data-panel="android">
            <div><span>1</span><b>Open in Chrome</b><p>Open this page in Google Chrome (or Samsung Internet / Edge).</p></div>
            <div><span>2</span><b>Tap Install app</b><p>Tap the app's <em>Install app</em> button, or open the ⋮ menu and choose <em>Install app</em>.</p></div>
            <div><span>3</span><b>Confirm</b><p>Tap <em>Install</em>. Find the app on your home screen and app drawer.</p></div>
        </div>
        <div class="ga-steps" data-panel="ios" hidden>
            <div><span>1</span><b>Open in Safari</b><p>iPhone and iPad install apps from Safari. Open this page in Safari.</p></div>
            <div><span>2</span><b>Tap Share</b><p>Tap the Share button <em>(square with an arrow)</em>, then <em>Add to Home Screen</em>.</p></div>
            <div><span>3</span><b>Add</b><p>Keep <em>Open as Web App</em> on if shown, then tap <em>Add</em>.</p></div>
        </div>
        <div class="ga-steps" data-panel="desktop" hidden>
            <div><span>1</span><b>Use Chrome or Edge</b><p>Open this page in Google Chrome or Microsoft Edge on your computer.</p></div>
            <div><span>2</span><b>Click Install</b><p>Click the app's <em>Install app</em> button, or the install icon in the address bar.</p></div>
            <div><span>3</span><b>Or scan the QR</b><p>Scan the app's QR code with your phone to install it there.</p></div>
        </div>
    </div>
</section>

<section class="ga-wrap ga-faq">
    <h2>Questions</h2>
    <details><summary>Is it safe?</summary><p>Yes. The apps run on Ultimate App's secure HTTPS website. They cannot read your other apps, files or contacts, and payments always need your sign-in.</p></details>
    <details><summary>Do I need the Play Store or App Store?</summary><p>No. These are progressive web apps: they install from your browser and take almost no storage.</p></details>
    <details><summary>How do updates work?</summary><p>Automatically. Every time you open the app you get the latest version.</p></details>
    <details><summary>Can I install more than one app?</summary><p>Yes. For example, a driver can install both URide Driver and the Ultimate App for personal payments.</p></details>
</section>
</main>

<footer class="ga-foot"><div class="ga-wrap"><span>© <?= date('Y') ?> Ultimate App Boracay</span><span><a href="<?= $h($url('login.php')) ?>">User sign in</a> · <a href="<?= $h($url('driver/login.php')) ?>">Driver sign in</a> · <a href="<?= $h($url('merchant-portal/login.php')) ?>">Merchant sign in</a></span></div></footer>
<script src="<?= $h($url('assets/js/qrcode.min.js')) ?>"></script>
<script src="<?= $h($url('assets/js/get-app.js?v=1')) ?>"></script>
</body>
</html>
<?php
    exit;
}
