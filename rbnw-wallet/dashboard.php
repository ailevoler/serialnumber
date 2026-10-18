<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$user   = get_user(current_user_id());
$wallet = get_wallet(current_user_id());
$recentTx = get_recent_transactions(current_user_id(), 5);

$stmt = db()->prepare("SELECT COUNT(*) c FROM rbnw_notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([current_user_id()]);
$unreadCount = (int)$stmt->fetch()['c'];

$activeNav = 'home';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Home — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
<div class="app-shell">

  <div class="topbar-brand">
    <button class="icon-btn" id="menuBtn"><i class="fa-solid fa-bars"></i></button>
    <div class="brand-lockup">
      <span class="brand-r">R</span>
      <span class="brand-text"><strong>RBNW</strong><small>WALLET</small></span>
    </div>
    <a href="notifications.php" class="icon-btn">
      <i class="fa-solid fa-bell"></i>
      <?php if ($unreadCount > 0): ?><span class="badge-dot"><?= min($unreadCount, 9) ?></span><?php endif; ?>
    </a>
  </div>

  <div class="topbar">
    <div class="greet">
      <div class="avatar"><?= strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)) ?></div>
      <div>
        <div class="name">Hello, <?= htmlspecialchars($user['first_name']) ?>!</div>
        <div class="verified">
          <?php if ($user['kyc_status'] === 'verified'): ?>
            <i class="fa-solid fa-circle-check"></i> Verified
          <?php else: ?>
            <span style="color:var(--text-muted);"><i class="fa-regular fa-circle"></i> Unverified — <a href="kyc.php" style="color:var(--rbnw-blue);font-weight:700;">verify now</a></span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="app-main">

    <div class="balance-card">
      <div class="balance-top">
        <span><i class="fa-regular fa-eye"></i> Total Balance</span>
        <span class="wallet-level-chip">Wallet Level <?= (int)$user['wallet_level'] ?></span>
      </div>
      <div class="balance-amount" id="balanceAmount"><?= peso((float)$wallet['balance']) ?></div>
      <div class="balance-actions">
        <button onclick="location.href='top-up.php'">
          <span class="balance-action-icon cash-in"><i class="fa-solid fa-plus"></i></span>
          <span>Cash In</span>
        </button>
        <button onclick="location.href='send-money.php'">
          <span class="balance-action-icon"><i class="fa-solid fa-paper-plane"></i></span>
          <span>Send Money</span>
        </button>
        <button onclick="location.href='scan-qr.php'">
          <span class="balance-action-icon"><i class="fa-solid fa-qrcode"></i></span>
          <span>Scan QR</span>
        </button>
        <button onclick="location.href='cash-out.php'">
          <span class="balance-action-icon"><i class="fa-solid fa-arrow-down"></i></span>
          <span>Cash Out</span>
        </button>
      </div>
    </div>

    <div class="section-head">
      <h3>Quick Services</h3>
      <a href="services.php">See All</a>
    </div>
    <div class="services-grid">
      <?php render_service_tiles(['send', 'cashin', 'bills', 'loans', 'save', 'request', 'bank', 'more']); ?>
    </div>

    <?php
    // Slides: [slug, eyebrow, headline accent, headline, text, link, CTA].
    // Drop a photo at assets/img/banners/<slug>.jpg|.png|.webp (a transparent
    // PNG cut-out of a person looks best) and it replaces the illustration.
    $banners = [
      ['banner-cash-in', 'RBNW Wallet', 'Cash In', 'Anytime', 'Add money from any QR Ph app in seconds.', 'top-up.php', 'Cash In Now'],
      ['banner-request', 'Get paid faster', 'Request', 'Money', 'Share a QR code and get paid instantly.', 'request-money.php', 'Try It Now'],
      ['banner-secure', 'Bank-grade security', 'Always', 'Secure', 'MPIN-protected and backed by RBNW.', 'security.php', 'Learn More'],
    ];
    $bannerImage = function (string $slug): array {
        foreach (['png', 'webp', 'jpg', 'jpeg'] as $ext) {
            if (is_file(__DIR__ . "/assets/img/banners/{$slug}.{$ext}")) {
                return [asset_url("assets/img/banners/{$slug}.{$ext}"), true];
            }
        }
        return [asset_url("assets/img/banners/{$slug}.svg"), false];
    };
    ?>
    <section class="banner-slider" aria-roledescription="carousel" aria-label="Promotions">
      <div class="banner-track" id="bannerTrack">
        <?php foreach ($banners as $i => [$slug, $eyebrow, $accent, $headline, $text, $href, $cta]): ?>
          <?php [$img, $isPhoto] = $bannerImage($slug); ?>
          <a class="banner-slide" href="<?= $href ?>" aria-label="<?= htmlspecialchars("$accent $headline") ?>" aria-roledescription="slide">
            <div class="banner-photo <?= $isPhoto ? 'is-photo' : 'is-art' ?>">
              <img src="<?= $img ?>" alt="" <?= $i ? 'loading="lazy"' : '' ?>>
            </div>
            <span class="banner-deco tri-1"></span>
            <span class="banner-deco tri-2"></span>
            <span class="banner-deco plus-1">+</span>
            <span class="banner-deco plus-2">+</span>
            <div class="banner-copy">
              <small><?= htmlspecialchars($eyebrow) ?></small>
              <strong><span><?= htmlspecialchars($accent) ?></span><?= htmlspecialchars($headline) ?></strong>
              <p><?= htmlspecialchars($text) ?></p>
              <em class="banner-cta"><?= htmlspecialchars($cta) ?></em>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="banner-dots" id="bannerDots">
        <?php foreach ($banners as $i => $_): ?>
          <button type="button" aria-label="Show slide <?= $i + 1 ?>" class="<?= $i === 0 ? 'active' : '' ?>"></button>
        <?php endforeach; ?>
      </div>
    </section>

    <div class="section-head">
      <h3>Recent Transactions</h3>
      <a href="transactions.php">See All</a>
    </div>
    <div class="card">
      <?php if (empty($recentTx)): ?>
        <div class="empty-state">
          <div class="icon"><i class="fa-regular fa-clock"></i></div>
          No transactions yet. Once you send, receive, or pay, they'll show up here.
        </div>
      <?php else: ?>
        <?php foreach ($recentTx as $tx):
          $meta = tx_display_meta($tx['type']);
          $label = tx_type_label($tx['type']);
          if ($tx['counterparty_name']) {
              $label .= ' — ' . $tx['counterparty_name'];
          }
        ?>
        <div class="tx-row">
          <div class="tx-icon <?= $meta['class'] ?>">
            <i class="fa-solid <?= $meta['sign'] === '-' ? 'fa-arrow-up' : 'fa-arrow-down' ?>"></i>
          </div>
          <div class="tx-info">
            <div class="tx-title"><?= htmlspecialchars($label) ?></div>
            <div class="tx-time"><?= time_ago_ph($tx['created_at']) ?></div>
          </div>
          <div class="tx-amount <?= $meta['class'] ?>"><?= $meta['sign'] ?><?= peso((float)$tx['amount']) ?></div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div>

  <?php include __DIR__ . '/includes/bottom-nav.php'; ?>
</div>

<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script>
document.getElementById('menuBtn').addEventListener('click', () => {
  RBNW.toast('Menu coming soon — use the bottom nav or Profile for now.', 'info');
});
</script>
<script>
(() => {
  const track = document.getElementById('bannerTrack');
  if (!track) return;
  const slides = track.children;
  const dots = document.querySelectorAll('#bannerDots button');
  let index = 0, timer = null;

  const go = (i) => {
    index = (i + slides.length) % slides.length;
    track.scrollTo({ left: slides[index].offsetLeft - track.offsetLeft, behavior: 'smooth' });
  };
  const start = () => { stop(); timer = setInterval(() => go(index + 1), 5000); };
  const stop = () => clearInterval(timer);

  track.addEventListener('scroll', () => {
    const i = Math.round(track.scrollLeft / track.clientWidth);
    if (i !== index) index = i;
    dots.forEach((d, n) => d.classList.toggle('active', n === i));
  }, { passive: true });
  dots.forEach((d, n) => d.addEventListener('click', () => { go(n); start(); }));
  ['pointerdown', 'touchstart'].forEach(ev => track.addEventListener(ev, stop, { passive: true }));
  ['pointerup', 'touchend'].forEach(ev => track.addEventListener(ev, start, { passive: true }));

  if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) start();
})();
</script>
</body>
</html>
