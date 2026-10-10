<?php
// Public page that explains and triggers installing the PCEC web app (PWA).
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout_auth.php';

$pageUrl = abs_url('install.php');
$loggedIn = (bool) current_user();
auth_head('Install App', 'install-body');
?>
<div class="install-wrap">
  <header class="install-hero">
    <div class="auth-top-bar">
      <a href="<?= e(url('index.php')) ?>" class="icon-btn light" aria-label="Back"><?= icon('arrow-left') ?></a>
      <?= lang_switch() ?>
    </div>
    <img class="install-icon" src="<?= e(url('assets/icons/icon-512.png')) ?>" alt="PCEC app icon" width="112" height="112">
    <h1>Install the PCEC App</h1>
    <p>Philippine Council of Evangelical Churches</p>
    <div class="install-badges">
      <span><?= icon('check') ?> Free</span>
      <span><?= icon('check') ?> No app store needed</span>
      <span><?= icon('check') ?> Android, iPhone &amp; Computer</span>
    </div>
  </header>

  <main class="install-card">
    <!-- Shown by app.js depending on the browser -->
    <div class="install-state" data-pwa-state="installed" hidden>
      <div class="install-done"><?= icon('check') ?></div>
      <h2>The app is installed</h2>
      <p class="muted">Open PCEC from your home screen or app list anytime.</p>
      <a class="btn btn-gradient btn-lg btn-block" href="<?= e(url('index.php?source=pwa')) ?>"><?= icon('home') ?> Open PCEC App</a>
    </div>

    <div class="install-state" data-pwa-state="ready" hidden>
      <button type="button" class="btn btn-gradient btn-lg btn-block" data-pwa-install><?= icon('download') ?> Install App</button>
      <p class="install-note">One tap — the PCEC icon will appear on your <span data-device-word>home screen</span>.</p>
    </div>

    <nav class="segmented install-tabs" role="tablist" aria-label="Choose your device">
      <label><input type="radio" name="platform" value="android" checked><span><?= icon('phone') ?> Android</span></label>
      <label><input type="radio" name="platform" value="ios"><span><?= icon('phone') ?> iPhone</span></label>
      <label><input type="radio" name="platform" value="desktop"><span><?= icon('globe') ?> Computer</span></label>
    </nav>

    <section class="install-steps" data-platform="android">
      <h2>Install on Android</h2>
      <ol>
        <li><span class="step-n">1</span><div><strong>Open this page in Chrome</strong><small>Samsung Internet and Edge also work.</small></div></li>
        <li><span class="step-n">2</span><div><strong>Tap <b>Install App</b> above</strong><small>Or tap the menu <b class="kbd">⋮</b> and choose <b>Install app</b> / <b>Add to Home screen</b>.</small></div></li>
        <li><span class="step-n">3</span><div><strong>Tap <b>Install</b> to confirm</strong><small>PCEC appears on your home screen and app drawer.</small></div></li>
      </ol>
    </section>

    <section class="install-steps" data-platform="ios" hidden>
      <h2>Install on iPhone / iPad</h2>
      <ol>
        <li><span class="step-n">1</span><div><strong>Open this page in Safari</strong><small>On iOS 16.4 or later, Chrome and Edge work too.</small></div></li>
        <li><span class="step-n">2</span><div><strong>Tap the Share button</strong>
          <small>The square with an arrow
            <svg class="icon inline-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-label="Share icon"><path d="M12 3v12M8 7l4-4 4 4"/><path d="M5 11v9h14v-9"/></svg>
            at the bottom (iPhone) or top (iPad) of the screen.</small></div></li>
        <li><span class="step-n">3</span><div><strong>Choose <b>Add to Home Screen</b></strong><small>Scroll down the share menu if you don't see it.</small></div></li>
        <li><span class="step-n">4</span><div><strong>Tap <b>Add</b></strong><small>Open PCEC from your home screen — it runs full screen like a regular app.</small></div></li>
      </ol>
    </section>

    <section class="install-steps" data-platform="desktop" hidden>
      <h2>Install on your computer</h2>
      <ol>
        <li><span class="step-n">1</span><div><strong>Open this page in Chrome or Microsoft Edge</strong><small>On a Mac you can also use Safari: <b>File → Add to Dock</b>.</small></div></li>
        <li><span class="step-n">2</span><div><strong>Click <b>Install App</b> above</strong><small>Or click the install icon
          <svg class="icon inline-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-label="Install icon"><rect x="3" y="4" width="18" height="13" rx="2"/><path d="M12 8v6M9 11l3 3 3-3M8 21h8"/></svg>
          at the right side of the address bar.</small></div></li>
        <li><span class="step-n">3</span><div><strong>Click <b>Install</b></strong><small>PCEC opens in its own window and is added to your Start menu / Applications.</small></div></li>
      </ol>
      <div class="install-share">
        <p><b>Want it on your phone?</b> Open this link on your phone:</p>
        <div class="copy-field"><code><?= e($pageUrl) ?></code><button type="button" class="icon-btn" data-copy-text="<?= e($pageUrl) ?>" aria-label="Copy link"><?= icon('copy') ?></button></div>
      </div>
    </section>

    <section class="install-why">
      <h2>Why install?</h2>
      <div class="why-grid">
        <div><span class="why-ic tile-blue"><?= icon('home') ?></span><strong>One tap away</strong><small>PCEC icon on your home screen</small></div>
        <div><span class="why-ic tile-green"><?= icon('phone') ?></span><strong>Full screen</strong><small>No browser bars, feels like an app</small></div>
        <div><span class="why-ic tile-orange"><?= icon('refresh') ?></span><strong>Loads faster</strong><small>Images and styles are saved on your device</small></div>
        <div><span class="why-ic tile-purple"><?= icon('shield') ?></span><strong>Always up to date</strong><small>No updates to download — ever</small></div>
      </div>
    </section>

    <a class="install-continue" href="<?= e(url($loggedIn ? 'index.php' : 'login.php')) ?>"><?= $loggedIn ? 'Back to the app' : 'Continue in browser' ?> <?= icon('chevron-right') ?></a>
  </main>
</div>
<?php auth_foot(); ?>
