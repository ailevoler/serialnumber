<?php
/**
 * Corporate website (landing page). Content is managed in Admin → Website CMS.
 * Shown at the site root when Home page = Website; always reachable at /home.php.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$sent = false;
$error = null;
$form = ['name' => '', 'email' => '', 'mobile' => '', 'interest' => 'both', 'message' => ''];
if (is_post() && input('form') === 'contact') {
    csrf_check();
    $form = form_values(array_keys($form));
    $recent = array_filter($_SESSION['site_msgs'] ?? [], fn($t) => $t > time() - 3600);
    try {
        if (input('website') !== '') {
            $sent = true; // honeypot
        } elseif (count($recent) >= 3) {
            throw new RuntimeException('Thank you — we already received your messages. Our team will contact you soon.');
        } else {
            if ($form['name'] === '' || ($form['email'] === '' && $form['mobile'] === '')) {
                throw new RuntimeException('Please enter your name and an email address or mobile number.');
            }
            if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please enter a valid email address.');
            }
            $mobile = preg_replace('/[^0-9+]/', '', $form['mobile']);
            tx(function () use ($form, $mobile) {
                $lead = q_val('SELECT id FROM crm_leads WHERE owner_user_id IS NULL AND ((email <> \'\' AND email = ?) OR (mobile <> \'\' AND mobile = ?))', [$form['email'], $mobile]);
                if (!$lead) {
                    $lead = crm_save_lead(['name' => $form['name'], 'email' => $form['email'], 'mobile' => $mobile, 'source' => 'Website',
                        'interest' => in_array($form['interest'], ['product', 'business', 'both'], true) ? $form['interest'] : 'both',
                        'status' => 'new', 'notes' => 'Contact form on the website.'], null);
                } else {
                    q("UPDATE crm_leads SET status = IF(status IN ('converted','lost'), status, 'new') WHERE id = ?", [$lead]);
                }
                crm_add_activity((int)$lead, null, null, 'email', 'Website message: ' . ($form['message'] !== '' ? $form['message'] : '(no message)'));
            });
            $recent[] = time();
            $_SESSION['site_msgs'] = $recent;
            $sent = true;
        }
    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    } catch (Throwable $ex) {
        $error = 'Sorry, we could not send your message right now. Please email us instead.';
    }
}

$site = (string)setting('site_name', 'XGEN LIFE Intl');
$logo = cms_img(cms('site.logo'), asset('img/logo-256.png'));
$heroImg = cms_img(cms('hero.image'), url('assets/img/logo.webp'));
$aboutImg = cms_img(cms('about.image'), '');
$stats = cms_on('stats') ? cms_items('stat') : [];
$features = cms_on('features') ? cms_items('feature') : [];
$testimonials = cms_on('testimonials') ? cms_items('testimonial') : [];
$faqs = cms_on('faq') ? cms_items('faq') : [];
$products = [];
if (cms_on('products')) {
    $n = max(1, min(12, (int)cms('products.count')));
    try {
        $products = q_all("SELECT * FROM products WHERE status = 'active' ORDER BY id LIMIT {$n}");
    } catch (Throwable $e) {
        $products = [];
    }
}
$ways = [
    'discount' => 'Buy at member price and earn retail profit on every product you sell.',
    'direct'   => 'Receive a bonus for every new member you personally sponsor.',
    'indirect' => 'Earn from the members your team brings in, up to ' . count(setting_list('indirect_levels')) . ' levels deep.',
    'passup'   => 'Australian Pass Up bonus as your organization grows.',
    'pairing'  => 'Binary pairing / matching bonus from your left and right teams.',
    'unilevel' => 'Earn on your team\'s repeat purchases every time they reorder.',
];
$ways = array_filter($ways, fn($k) => plan_on($k), ARRAY_FILTER_USE_KEY);

$nav = array_filter([
    'about'        => cms_on('about') ? 'About' : null,
    'products'     => $products ? 'Products' : null,
    'opportunity'  => cms_on('opportunity') ? 'Opportunity' : null,
    'testimonials' => $testimonials ? 'Stories' : null,
    'faq'          => $faqs ? 'FAQ' : null,
    'contact'      => cms_on('contact') ? 'Contact' : null,
]);
$socials = array_filter(['Facebook' => cms('footer.facebook'), 'Instagram' => cms('footer.instagram'), 'TikTok' => cms('footer.tiktok'), 'YouTube' => cms('footer.youtube')]);
$socialIcons = [
    'Facebook'  => '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>',
    'Instagram' => '<rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><path d="M17.5 6.5h.01"/>',
    'TikTok'    => '<path d="M16 3c.5 2.5 2 4 5 4.2v3.6c-1.9 0-3.6-.6-5-1.6V16a6 6 0 1 1-6-6v3.7a2.3 2.3 0 1 0 2.3 2.3V3z"/>',
    'YouTube'   => '<path d="M22.5 6.4a2.8 2.8 0 0 0-2-2C18.8 4 12 4 12 4s-6.8 0-8.5.4a2.8 2.8 0 0 0-2 2A29 29 0 0 0 1 12a29 29 0 0 0 .5 5.6 2.8 2.8 0 0 0 2 2C5.2 20 12 20 12 20s6.8 0 8.5-.4a2.8 2.8 0 0 0 2-2A29 29 0 0 0 23 12a29 29 0 0 0-.5-5.6z"/><path d="M9.8 15.5l5.7-3.5-5.7-3.5z"/>',
];
$heroTitle = cms('hero.title');
$preview = isset($_GET['preview']) && ($u = current_user()) && in_array($u['role'], ['admin', 'cms'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(cms('site.meta_title')) ?></title>
<meta name="description" content="<?= e(cms('site.meta_description')) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e(cms('site.meta_title')) ?>">
<meta property="og:description" content="<?= e(cms('site.meta_description')) ?>">
<meta property="og:image" content="<?= e(cms('site.og_image') !== '' ? cms_img(cms('site.og_image')) : absolute_url('assets/img/logo-256.png')) ?>">
<meta name="theme-color" content="#ffffff">
<link rel="icon" type="image/png" href="<?= e(asset('img/favicon.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/site.css')) ?>">
<script>document.documentElement.classList.add('js');</script>
</head>
<body>
<?php if ($preview): ?><div class="preview-bar">Preview mode · <a href="<?= e(url('admin/cms.php')) ?>">Back to CMS</a></div><?php endif; ?>
<?php if (cms('notice.enabled') === '1' && cms('notice.text') !== ''): ?>
  <div class="notice"><a href="<?= e(cms_link(cms('notice.link'))) ?>"><?= e(cms('notice.text')) ?> <span aria-hidden="true">→</span></a></div>
<?php endif; ?>

<header class="nav" data-nav>
  <div class="container nav-inner">
    <a class="brand" href="#top" aria-label="<?= e($site) ?> home">
      <img src="<?= e($logo) ?>" alt="" width="40" height="40">
      <span>XGEN <b>LIFE</b><small>International</small></span>
    </a>
    <nav class="menu" id="menu" aria-label="Main">
      <?php foreach ($nav as $id => $label): ?><a href="#<?= $id ?>"><?= e($label) ?></a><?php endforeach; ?>
      <div class="menu-cta">
        <a class="btn btn-text" href="<?= e(url('login.php')) ?>">Member Login</a>
        <a class="btn btn-primary btn-sm" href="<?= e(cms_link(cms('hero.cta_link'))) ?>"><?= e(cms('hero.cta_label')) ?></a>
      </div>
    </nav>
    <button class="burger" type="button" aria-label="Open menu" aria-controls="menu" aria-expanded="false" data-burger><span></span><span></span><span></span></button>
  </div>
</header>

<main id="top">
  <!-- Hero -->
  <section class="hero">
    <div class="container hero-grid">
      <div class="hero-copy reveal">
        <?php if (cms('hero.eyebrow') !== ''): ?><span class="eyebrow"><?= e(cms('hero.eyebrow')) ?></span><?php endif; ?>
        <h1><?= e($heroTitle) ?></h1>
        <p class="hero-sub"><?= e(cms('hero.subtitle')) ?></p>
        <div class="hero-actions">
          <?php if (cms('hero.cta_label') !== ''): ?><a class="btn btn-primary" href="<?= e(cms_link(cms('hero.cta_link'))) ?>"><?= e(cms('hero.cta_label')) ?> <span aria-hidden="true">→</span></a><?php endif; ?>
          <?php if (cms('hero.cta2_label') !== ''): ?><a class="btn btn-outline" href="<?= e(cms_link(cms('hero.cta2_link'))) ?>"><?= e(cms('hero.cta2_label')) ?></a><?php endif; ?>
        </div>
        <?php if ($trust = cms_lines('hero.trust')): ?>
          <ul class="trust"><?php foreach ($trust as $t): ?><li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg><?= e($t) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </div>
      <div class="hero-visual reveal" aria-hidden="true">
        <div class="ring r1"></div><div class="ring r2"></div>
        <img class="hero-img <?= cms('hero.image') === '' ? 'is-logo' : '' ?>" src="<?= e($heroImg) ?>" alt="">
        <div class="float f1"><span class="dot"></span>Automated e-wallet</div>
        <div class="float f2"><b><?= count($ways) ?></b> ways to earn</div>
      </div>
    </div>
  </section>

  <?php if ($stats): ?>
  <section class="stats">
    <div class="container stats-grid">
      <?php foreach ($stats as $s): ?><div class="stat reveal"><strong><?= e($s['title']) ?></strong><span><?= e($s['subtitle']) ?></span></div><?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if (cms_on('about')): ?>
  <section class="section" id="about">
    <div class="container split <?= $aboutImg === '' ? 'no-img' : '' ?>">
      <?php if ($aboutImg !== ''): ?><div class="split-media reveal"><img src="<?= e($aboutImg) ?>" alt="" loading="lazy"></div><?php endif; ?>
      <div class="reveal">
        <span class="eyebrow"><?= e(cms('about.eyebrow')) ?></span>
        <h2><?= e(cms('about.title')) ?></h2>
        <?php foreach (preg_split("/\n\s*\n/", str_replace("\r", '', cms('about.body'))) as $para): ?><p class="muted"><?= nl2br(e(trim($para))) ?></p><?php endforeach; ?>
        <?php if ($pts = cms_lines('about.points')): ?>
          <ul class="checklist"><?php foreach ($pts as $p): ?><li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg><?= e($p) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($products): ?>
  <section class="section alt" id="products">
    <div class="container">
      <div class="section-head reveal">
        <span class="eyebrow"><?= e(cms('products.eyebrow')) ?></span>
        <h2><?= e(cms('products.title')) ?></h2>
        <p class="muted"><?= e(cms('products.subtitle')) ?></p>
      </div>
      <div class="products">
        <?php foreach ($products as $p): ?>
          <article class="product reveal">
            <div class="product-img"><img src="<?= e(product_image($p['image'])) ?>" alt="<?= e($p['name']) ?>" loading="lazy"></div>
            <div class="product-body">
              <h3><?= e($p['name']) ?></h3>
              <p><?= e(mb_strimwidth((string)$p['description'], 0, 110, '…')) ?></p>
              <?php if (cms('products.show_price') === '1'): ?><span class="price"><?= money($p['srp']) ?> <small>SRP</small></span><?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
      <p class="center small muted reveal">Members buy at exclusive member prices. <a href="<?= e(url('login.php')) ?>">Sign in to shop →</a></p>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($features): ?>
  <section class="section" id="why">
    <div class="container">
      <div class="section-head reveal">
        <span class="eyebrow"><?= e(cms('features.eyebrow')) ?></span>
        <h2><?= e(cms('features.title')) ?></h2>
      </div>
      <div class="features">
        <?php foreach ($features as $f): ?>
          <article class="feature reveal">
            <div class="f-icon"><?= icon($f['icon'] ?: 'shield', 22) ?></div>
            <h3><?= e($f['title']) ?></h3>
            <p><?= e($f['body']) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if (cms_on('opportunity') && $ways): ?>
  <section class="section dark" id="opportunity">
    <div class="container opp-grid">
      <div class="reveal opp-copy">
        <span class="eyebrow light"><?= e(cms('opportunity.eyebrow')) ?></span>
        <h2><?= e(cms('opportunity.title')) ?></h2>
        <p><?= e(cms('opportunity.body')) ?></p>
        <?php if (cms('opportunity.cta_label') !== ''): ?><a class="btn btn-gold" href="<?= e(cms_link(cms('opportunity.cta_link'))) ?>"><?= e(cms('opportunity.cta_label')) ?> <span aria-hidden="true">→</span></a><?php endif; ?>
      </div>
      <ol class="ways">
        <?php $i = 0; foreach ($ways as $k => $desc): $i++; ?>
          <li class="reveal"><span class="num"><?= str_pad((string)$i, 2, '0', STR_PAD_LEFT) ?></span><div><h3><?= e(earning_types()[$k]['label']) ?></h3><p><?= e($desc) ?></p></div></li>
        <?php endforeach; ?>
      </ol>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($testimonials): ?>
  <section class="section" id="testimonials">
    <div class="container">
      <div class="section-head reveal">
        <span class="eyebrow"><?= e(cms('testimonials.eyebrow')) ?></span>
        <h2><?= e(cms('testimonials.title')) ?></h2>
      </div>
      <div class="quotes">
        <?php foreach ($testimonials as $t): ?>
          <figure class="quote reveal">
            <svg class="q-mark" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7h4v4c0 3-1.5 5-4 6l-.7-1.3C7.8 14.8 8.5 13.6 8.6 12H7zm8 0h4v4c0 3-1.5 5-4 6l-.7-1.3c1.5-.9 2.2-2.1 2.3-3.7H15z"/></svg>
            <blockquote><?= e($t['body']) ?></blockquote>
            <figcaption>
              <?php if ($t['image']): ?><img src="<?= e(cms_img($t['image'])) ?>" alt="" loading="lazy"><?php else: ?><span class="initials"><?= e(mb_strtoupper(mb_substr($t['title'], 0, 1))) ?></span><?php endif; ?>
              <span><strong><?= e($t['title']) ?></strong><small><?= e($t['subtitle']) ?></small></span>
            </figcaption>
          </figure>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($faqs): ?>
  <section class="section alt" id="faq">
    <div class="container narrow">
      <div class="section-head reveal">
        <span class="eyebrow"><?= e(cms('faq.eyebrow')) ?></span>
        <h2><?= e(cms('faq.title')) ?></h2>
      </div>
      <div class="faq">
        <?php foreach ($faqs as $i => $f): ?>
          <details class="reveal" <?= $i === 0 ? 'open' : '' ?>><summary><?= e($f['title']) ?><span class="chev" aria-hidden="true"></span></summary><p><?= nl2br(e($f['body'])) ?></p></details>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if (cms_on('cta')): ?>
  <section class="cta-band">
    <div class="container cta-inner reveal">
      <div><h2><?= e(cms('cta.title')) ?></h2><p><?= e(cms('cta.text')) ?></p></div>
      <?php if (cms('cta.label') !== ''): ?><a class="btn btn-light" href="<?= e(cms_link(cms('cta.link'))) ?>"><?= e(cms('cta.label')) ?> <span aria-hidden="true">→</span></a><?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if (cms_on('contact')): ?>
  <section class="section" id="contact">
    <div class="container contact-grid">
      <div class="reveal">
        <span class="eyebrow"><?= e(cms('contact.eyebrow')) ?></span>
        <h2><?= e(cms('contact.title')) ?></h2>
        <p class="muted"><?= e(cms('contact.text')) ?></p>
        <ul class="contact-list">
          <?php if (cms('contact.address') !== ''): ?><li><?= icon('home', 18) ?><span><?= nl2br(e(cms('contact.address'))) ?><?php if (cms('contact.map') !== ''): ?><br><a href="<?= e(cms('contact.map')) ?>" target="_blank" rel="noopener">View on map</a><?php endif; ?></span></li><?php endif; ?>
          <?php if (cms('contact.email') !== ''): ?><li><?= icon('message', 18) ?><a href="mailto:<?= e(cms('contact.email')) ?>"><?= e(cms('contact.email')) ?></a></li><?php endif; ?>
          <?php if (cms('contact.phone') !== ''): ?><li><?= icon('phone', 18) ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', cms('contact.phone'))) ?>"><?= e(cms('contact.phone')) ?></a></li><?php endif; ?>
          <?php if (cms('contact.hours') !== ''): ?><li><?= icon('calendar', 18) ?><span><?= e(cms('contact.hours')) ?></span></li><?php endif; ?>
        </ul>
      </div>
      <div class="contact-card reveal">
        <?php if ($sent): ?>
          <div class="sent"><div class="sent-check">✓</div><h3>Thank you<?= $form['name'] !== '' ? ', ' . e(strtok($form['name'], ' ')) : '' ?>!</h3><p class="muted">Your message has been received. Our team will get back to you within one business day.</p></div>
        <?php else: ?>
          <form method="post" action="#contact" novalidate>
            <?= csrf_field() ?><input type="hidden" name="form" value="contact">
            <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
            <?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
            <div class="field"><label for="c-name">Full name</label><input id="c-name" name="name" value="<?= e($form['name']) ?>" required maxlength="120" autocomplete="name"></div>
            <div class="field-row">
              <div class="field"><label for="c-email">Email</label><input id="c-email" type="email" name="email" value="<?= e($form['email']) ?>" autocomplete="email"></div>
              <div class="field"><label for="c-mobile">Mobile</label><input id="c-mobile" name="mobile" value="<?= e($form['mobile']) ?>" autocomplete="tel" placeholder="09XX XXX XXXX"></div>
            </div>
            <div class="field"><label for="c-interest">I'm interested in</label>
              <select id="c-interest" name="interest"><?php foreach (['both' => 'Products & business', 'product' => 'Products', 'business' => 'Business opportunity'] as $k => $l): ?><option value="<?= $k ?>" <?= $form['interest'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="c-msg">Message</label><textarea id="c-msg" name="message" rows="4" maxlength="2000"><?= e($form['message']) ?></textarea></div>
            <button class="btn btn-primary btn-block" type="submit">Send message</button>
            <p class="small muted center">By sending, you agree to be contacted by <?= e($site) ?>.</p>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>
</main>

<footer class="footer">
  <div class="container footer-grid">
    <div>
      <a class="brand" href="#top"><img src="<?= e($logo) ?>" alt="" width="40" height="40"><span>XGEN <b>LIFE</b><small>International</small></span></a>
      <p class="muted"><?= e(cms('footer.about')) ?></p>
      <?php if ($socials): ?><div class="socials"><?php foreach ($socials as $n => $l): ?><a href="<?= e($l) ?>" target="_blank" rel="noopener" aria-label="<?= e($n) ?>"><svg viewBox="0 0 24 24"><?= $socialIcons[$n] ?></svg></a><?php endforeach; ?></div><?php endif; ?>
    </div>
    <div><h4>Company</h4><ul><?php foreach ($nav as $id => $label): ?><li><a href="#<?= $id ?>"><?= e($label) ?></a></li><?php endforeach; ?></ul></div>
    <div><h4>Members</h4><ul><li><a href="<?= e(url('login.php')) ?>">Member login</a></li><li><a href="<?= e(url('register.php')) ?>">Activate account</a></li><li><a href="<?= e(url('member/support.php')) ?>">Live support</a></li></ul></div>
    <div><h4>Contact</h4><ul>
      <?php if (cms('contact.email') !== ''): ?><li><a href="mailto:<?= e(cms('contact.email')) ?>"><?= e(cms('contact.email')) ?></a></li><?php endif; ?>
      <?php if (cms('contact.phone') !== ''): ?><li><?= e(cms('contact.phone')) ?></li><?php endif; ?>
      <?php if (cms('contact.hours') !== ''): ?><li><?= e(cms('contact.hours')) ?></li><?php endif; ?>
    </ul></div>
  </div>
  <div class="container footer-bottom"><span>&copy; <?= date('Y') ?> <?= e(cms('footer.legal')) ?></span><span class="tagline"><?= e(setting('site_tagline', '')) ?></span></div>
</footer>

<script src="<?= e(asset('js/site.js')) ?>"></script>
</body>
</html>
