<?php
/**
 * Coming Soon landing page. Shown on the home page while "Coming Soon" is enabled
 * (Admin → General Settings), and always reachable at /coming-soon.php.
 * Sign-ups are saved as company CRM leads (source: Website).
 */
require_once __DIR__ . '/includes/bootstrap.php';

$launch = (string)setting('launch_date', '');
$launchTs = $launch !== '' ? strtotime($launch) : false;
$form = ['name' => '', 'contact' => '', 'interest' => 'both'];
$done = false;
$error = null;

if (is_post()) {
    csrf_check();
    $form = form_values(['name', 'contact', 'interest']);
    $recent = array_filter($_SESSION['cs_signups'] ?? [], fn($t) => $t > time() - 3600);
    try {
        if (input('website') !== '') {           // honeypot: bots fill hidden fields
            $done = true;
        } elseif (count($recent) >= 3) {
            throw new RuntimeException('You have already signed up. Thank you!');
        } else {
            $contact = $form['contact'];
            $isEmail = (bool)filter_var($contact, FILTER_VALIDATE_EMAIL);
            $mobile = preg_replace('/[^0-9+]/', '', $contact);
            if ($form['name'] === '' || mb_strlen($form['name']) > 120) {
                throw new RuntimeException('Please enter your name.');
            }
            if (!$isEmail && strlen($mobile) < 10) {
                throw new RuntimeException('Please enter a valid email address or mobile number.');
            }
            $exists = q_val('SELECT id FROM crm_leads WHERE owner_user_id IS NULL AND ' . ($isEmail ? 'email = ?' : 'mobile = ?'), [$isEmail ? $contact : $mobile]);
            if (!$exists) {
                crm_save_lead([
                    'name'     => $form['name'],
                    'email'    => $isEmail ? $contact : '',
                    'mobile'   => $isEmail ? '' : $mobile,
                    'source'   => 'Website',
                    'interest' => in_array($form['interest'], ['product', 'business', 'both'], true) ? $form['interest'] : 'both',
                    'status'   => 'new',
                    'notes'    => 'Signed up on the Coming Soon page.',
                ], null);
            }
            $recent[] = time();
            $_SESSION['cs_signups'] = $recent;
            $done = true;
        }
    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    } catch (Throwable $ex) {
        $error = 'Sorry, we could not save your details right now. Please try again later.';
    }
}

$site = (string)setting('site_name', 'XGEN LIFE Intl');
$headline = (string)setting('coming_soon_headline', 'Something healthy is coming.');
$text = (string)setting('coming_soon_text', 'Be the first to know when we launch.');
$socials = array_filter([
    'Facebook' => setting('social_facebook', ''),
    'TikTok'   => setting('social_tiktok', ''),
    'YouTube'  => setting('social_youtube', ''),
]);
$socialIcons = [
    'Facebook' => '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>',
    'TikTok'   => '<path d="M16 3c.5 2.5 2 4 5 4.2v3.6c-1.9 0-3.6-.6-5-1.6V16a6 6 0 1 1-6-6v3.7a2.3 2.3 0 1 0 2.3 2.3V3z"/>',
    'YouTube'  => '<path d="M22.5 6.4a2.8 2.8 0 0 0-2-2C18.8 4 12 4 12 4s-6.8 0-8.5.4a2.8 2.8 0 0 0-2 2A29 29 0 0 0 1 12a29 29 0 0 0 .5 5.6 2.8 2.8 0 0 0 2 2C5.2 20 12 20 12 20s6.8 0 8.5-.4a2.8 2.8 0 0 0 2-2A29 29 0 0 0 23 12a29 29 0 0 0-.5-5.6z"/><path d="M9.8 15.5l5.7-3.5-5.7-3.5z" fill="#fff" stroke="none"/>',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Coming Soon · <?= e($site) ?></title>
<meta name="description" content="<?= e($text) ?>">
<meta property="og:title" content="<?= e($site) ?> — Coming Soon">
<meta property="og:description" content="<?= e($text) ?>">
<meta property="og:image" content="<?= e(absolute_url('assets/img/logo-256.png')) ?>">
<meta name="theme-color" content="#0b6b2f">
<link rel="icon" type="image/png" href="<?= e(asset('img/favicon.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/coming-soon.css')) ?>">
</head>
<body>
<div class="bg" aria-hidden="true"><span class="blob b1"></span><span class="blob b2"></span><span class="blob b3"></span></div>

<header class="top">
  <a class="brand" href="<?= e(url('')) ?>">
    <img src="<?= e(asset('img/logo-256.png')) ?>" alt="">
    <span>XGEN <b>LIFE</b></span>
  </a>
  <a class="login-link" href="<?= e(url('login.php')) ?>">Member Login</a>
</header>

<main class="wrap">
  <section class="hero">
    <div class="logo-ring"><img src="<?= e(asset('img/logo-256.png')) ?>" alt="XGEN LIFE International logo" width="168" height="168"></div>
    <span class="eyebrow"><span class="pulse"></span> Launching soon</span>
    <h1><?= e($headline) ?></h1>
    <p class="lead"><?= e($text) ?></p>

    <div class="countdown" data-launch="<?= $launchTs ? date('c', $launchTs) : '' ?>" <?= $launchTs ? '' : 'hidden' ?>>
      <div class="unit"><strong data-d>00</strong><span>Days</span></div>
      <div class="unit"><strong data-h>00</strong><span>Hours</span></div>
      <div class="unit"><strong data-m>00</strong><span>Minutes</span></div>
      <div class="unit"><strong data-s>00</strong><span>Seconds</span></div>
    </div>
    <?php if ($launchTs): ?><p class="launch-date">Grand launch · <?= e(date('F j, Y · g:i A', $launchTs)) ?></p><?php endif; ?>
    <div class="live" data-live hidden>
      <p>We're live! 🎉</p>
      <a class="btn btn-primary" href="<?= e(url('register.php')) ?>">Join XGEN LIFE</a>
      <a class="btn btn-ghost" href="<?= e(url('login.php')) ?>">Member Login</a>
    </div>
  </section>

  <section class="notify" id="notify">
    <?php if ($done): ?>
      <div class="thanks">
        <div class="check">✓</div>
        <h2>Salamat<?= $form['name'] !== '' ? ', ' . e(strtok($form['name'], ' ')) : '' ?>!</h2>
        <p>You're on the list. We'll contact you as soon as XGEN LIFE launches.</p>
      </div>
    <?php else: ?>
      <h2>Be the first to know</h2>
      <p>Get launch updates, opening promos and early-bird invitations.</p>
      <?php if ($error): ?><div class="error" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post" action="#notify" novalidate>
        <?= csrf_field() ?>
        <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
        <label class="sr" for="cs-name">Your name</label>
        <input id="cs-name" name="name" value="<?= e($form['name']) ?>" placeholder="Your name" required maxlength="120" autocomplete="name">
        <label class="sr" for="cs-contact">Email or mobile number</label>
        <input id="cs-contact" name="contact" value="<?= e($form['contact']) ?>" placeholder="Email or mobile number" required autocomplete="email">
        <div class="interest" role="radiogroup" aria-label="I'm interested in">
          <?php foreach (['both' => 'Products & Business', 'product' => 'Products', 'business' => 'Business'] as $k => $l): ?>
            <label><input type="radio" name="interest" value="<?= $k ?>" <?= $form['interest'] === $k ? 'checked' : '' ?>><span><?= $l ?></span></label>
          <?php endforeach; ?>
        </div>
        <button class="btn btn-primary" type="submit">Notify Me</button>
        <small>We respect your privacy. No spam, ever.</small>
      </form>
    <?php endif; ?>
  </section>

  <section class="features" aria-label="What's coming">
    <article>
      <div class="f-ic"><svg viewBox="0 0 24 24"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.5 19 2c1 2 2 4.2 2 8 0 5.5-4.8 10-10 10z"/><path d="M2 21c0-3 1.9-5.4 5.1-6"/></svg></div>
      <h3>Better Health</h3>
      <p>Premium wellness products at exclusive member prices.</p>
    </article>
    <article>
      <div class="f-ic gold"><svg viewBox="0 0 24 24"><path d="M23 6l-9.5 9.5-5-5L1 18"/><path d="M17 6h6v6"/></svg></div>
      <h3>6 Ways to Earn</h3>
      <p>Direct, Indirect, Australian Pass Up, Pairing, Unilevel and product discounts.</p>
    </article>
    <article>
      <div class="f-ic"><svg viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/></svg></div>
      <h3>Digital Platform</h3>
      <p>E-wallet, genealogy, QR Ph payments and live support — all in one app.</p>
    </article>
  </section>
</main>

<footer class="foot">
  <?php if ($socials): ?>
    <div class="socials">
      <?php foreach ($socials as $name => $link): ?>
        <a href="<?= e($link) ?>" target="_blank" rel="noopener" aria-label="<?= e($name) ?>"><svg viewBox="0 0 24 24"><?= $socialIcons[$name] ?></svg></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <p class="contact">
    <?php if (setting('contact_email')): ?><a href="mailto:<?= e(setting('contact_email')) ?>"><?= e(setting('contact_email')) ?></a><?php endif; ?>
    <?php if (setting('contact_mobile')): ?><span>·</span><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string)setting('contact_mobile'))) ?>"><?= e(setting('contact_mobile')) ?></a><?php endif; ?>
  </p>
  <p>&copy; <?= date('Y') ?> <?= e($site) ?> · <span class="tag"><?= e(setting('site_tagline', 'Better Health • Brighter Future. Together.')) ?></span></p>
</footer>

<script>
(function () {
  var box = document.querySelector('.countdown');
  if (!box || !box.dataset.launch) { return; }
  var target = new Date(box.dataset.launch).getTime();
  var pad = function (n) { return (n < 10 ? '0' : '') + n; };
  var els = { d: box.querySelector('[data-d]'), h: box.querySelector('[data-h]'), m: box.querySelector('[data-m]'), s: box.querySelector('[data-s]') };
  var tick = function () {
    var diff = Math.max(0, Math.floor((target - Date.now()) / 1000));
    if (diff === 0) {
      box.hidden = true;
      var date = document.querySelector('.launch-date'); if (date) { date.hidden = true; }
      document.querySelector('[data-live]').hidden = false;
      clearInterval(timer);
      return;
    }
    els.d.textContent = pad(Math.floor(diff / 86400));
    els.h.textContent = pad(Math.floor(diff % 86400 / 3600));
    els.m.textContent = pad(Math.floor(diff % 3600 / 60));
    els.s.textContent = pad(diff % 60);
  };
  var timer = setInterval(tick, 1000);
  tick();
})();
</script>
</body>
</html>
