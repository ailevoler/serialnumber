<?php
require_once __DIR__ . '/../includes/core.php';
Auth::requireLogin();
$user = Auth::user();
if (!$user) { Auth::logout(); }

// Recent activity
$recentBookings = DB::all(
    "SELECT 'ride' AS svc_type, booking_code AS code, pickup_address, dropoff_address,
            fare_total AS amount, vehicle_type, payment_method, status, created_at
     FROM tl_bookings WHERE user_id=?
     UNION ALL
     SELECT 'delivery', delivery_code, pickup_address, dropoff_address,
            fee_total, package_type, payment_method, status, created_at
     FROM tl_deliveries WHERE user_id=?
     ORDER BY created_at DESC LIMIT 5",
    [$user['id'], $user['id']]
);

// Active promotional banners (Admin > Banners) for the home screen carousel
$banners = DB::all("SELECT * FROM tl_banners WHERE is_active=1 ORDER BY sort_order ASC, created_at DESC");
$fareMatrix = DB::all("SELECT * FROM tl_fare_matrix WHERE is_active=1");
$fareJson   = json_encode(array_column($fareMatrix, null, 'vehicle_type'));

// Terminals for "Terminal" ride bookings (dispatched from the tricycle pila)
$terminals = [];
try {
    require_once __DIR__ . '/../../trilink-dispatch/lib/Dispatch.php';
    $terminals = Dispatch::activeTerminals();
} catch (Exception $e) {
    $terminals = []; // dispatch migration not run yet → only "Not in Terminal"
}

// Active trip (ride or delivery) — used to block booking a new one while one is ongoing
$activeTrip = getActiveTrip($user['id']);

// Nearby merchants (live)
$merchantIcons = [
    'groceries' => ['icon' => 'fa-shopping-cart', 'bg' => '#E8F5E9', 'fg' => '#2E7D32'],
    'beverages' => ['icon' => 'fa-coffee',        'bg' => '#FFF3E0', 'fg' => '#E65100'],
    'beauty'    => ['icon' => 'fa-spa',           'bg' => '#FCE4EC', 'fg' => '#C62828'],
    'food'      => ['icon' => 'fa-hamburger',     'bg' => '#FFF9C4', 'fg' => '#F57F17'],
    'pharmacy'  => ['icon' => 'fa-pills',         'bg' => '#E8EAF6', 'fg' => '#3949AB'],
    'other'     => ['icon' => 'fa-shopping-bag',  'bg' => '#E3F2FD', 'fg' => '#1565C0'],
];
$merchants = DB::all(
    "SELECT id, merchant_code, store_name, category, address, logo
     FROM tl_merchants WHERE status='active' AND is_open=1
     ORDER BY created_at ASC LIMIT 6"
);

$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$firstName = htmlspecialchars(explode(' ', $user['full_name'])[0]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>TriLink</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css?v=<?= filemtime(__DIR__.'/../assets/css/app.css') ?>">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/home.css?v=<?= filemtime(__DIR__.'/../assets/css/home.css') ?>">
</head>
<body>
<div class="app" id="app">

  <!-- ══ TOPBAR ══════════════════════════════════════════════ -->
  <header class="topbar">
    <button class="tb-btn" id="menuBtn"><i class="fas fa-bars"></i></button>
    <div class="tb-logo">
      <div class="tb-logo-icon"><i class="fas fa-motorcycle"></i></div>
      <span><span class="tri">Tri</span><span class="lnk">Link</span></span>
    </div>
    <button class="tb-btn" id="notifBtn"><i class="fas fa-bell"></i></button>
  </header>

  <!-- ══ SIDE DRAWER ═════════════════════════════════════════ -->
  <div class="drawer-overlay hidden" id="drawerOverlay"></div>
  <nav class="side-drawer hidden" id="sideDrawer">
    <div class="dr-head">
      <div class="dr-user">
        <div class="dr-avatar">
          <?php if ($user['avatar']): ?>
          <img src="<?= APP_URL ?>/uploads/avatars/<?= htmlspecialchars($user['avatar']) ?>" alt="">
          <?php else: ?><i class="fas fa-user"></i><?php endif; ?>
        </div>
        <div>
          <div class="dr-name"><?= htmlspecialchars($user['full_name']) ?></div>
          <div class="dr-phone"><?= htmlspecialchars($user['phone']) ?></div>
        </div>
      </div>
      <button id="drawerClose"><i class="fas fa-times"></i></button>
    </div>
    <div class="dr-wallet">
      <span class="dr-wlabel"><i class="fas fa-wallet"></i> Wallet Balance</span>
      <span class="dr-wbal">₱ <?= number_format((float)$user['wallet_balance'], 2) ?></span>
      <div class="dr-wbtns">
        <a href="<?= APP_URL ?>/pages/wallet.php?action=topup" class="dr-wbtn"><i class="fas fa-plus"></i> Top Up</a>
        <a href="<?= APP_URL ?>/pages/wallet.php" class="dr-wbtn"><i class="fas fa-history"></i> History</a>
      </div>
    </div>
    <ul class="dr-nav">
      <li class="dr-active"><a href="#" onclick="closeDrawer();showScreen('screenHome');"><i class="fas fa-home"></i> Home</a></li>
      <li><a href="<?= APP_URL ?>/pages/bookings.php"><i class="fas fa-calendar-check"></i> My Bookings</a></li>
      <li><a href="<?= APP_URL ?>/pages/orders.php"><i class="fas fa-box"></i> My Orders</a></li>
      <li><a href="<?= APP_URL ?>/pages/wallet.php"><i class="fas fa-wallet"></i> Wallet</a></li>
      <li><a href="<?= APP_URL ?>/pages/promos.php"><i class="fas fa-tag"></i> Promos & Deals</a></li>
      <li><a href="<?= APP_URL ?>/pages/support.php"><i class="fas fa-headset"></i> Help & Support</a></li>
      <li><a href="<?= APP_URL ?>/pages/profile.php"><i class="fas fa-user-circle"></i> My Profile</a></li>
      <li class="dr-sep"></li>
      <li><a href="<?= APP_URL ?>/auth/logout.php" class="dr-logout"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
    </ul>
  </nav>

  <!-- ══ SCREENS CONTAINER ═══════════════════════════════════ -->
  <main class="screens">

    <!-- ════ HOME SCREEN ════════════════════════════════════ -->
    <section class="screen active" id="screenHome">

      <!-- Greeting row -->
      <div class="home-greeting">
        <div>
          <p class="hg-sub">Hello, <strong><?= $firstName ?>!</strong></p>
          <p class="hg-where">Where to today?</p>
        </div>
        <button class="wallet-chip" onclick="showScreen('screenWallet')">
          <div class="wc-label">Wallet Balance</div>
          <div class="wc-amt">
            ₱ <?= number_format((float)$user['wallet_balance'], 2) ?>
            <i class="fas fa-plus-circle"></i>
          </div>
        </button>
      </div>

      <!-- Active Trip banner — always visible when there's an ongoing ride/delivery/order -->
      <?php if ($activeTrip):
        $atLabels = ['ride' => ['label' => 'Active Ride', 'icon' => 'fa-motorcycle'], 'delivery' => ['label' => 'Active Delivery', 'icon' => 'fa-box'], 'order' => ['label' => 'Active Order', 'icon' => 'fa-shopping-basket']];
        $atInfo = $atLabels[$activeTrip['kind']] ?? ['label' => 'Active Trip', 'icon' => 'fa-route'];
        $atStatusLabel = ucfirst(str_replace('_', ' ', $activeTrip['status']));
        $atUrl = APP_URL . '/pages/tracking.php?code=' . urlencode($activeTrip['code']) . '&type=' . $activeTrip['kind'];
      ?>
      <div onclick="window.location.href='<?= $atUrl ?>'" style="cursor:pointer;display:flex;align-items:center;gap:12px;background:linear-gradient(135deg,#1B5E20,#2E7D32);border-radius:16px;padding:14px 16px;margin-bottom:14px;box-shadow:0 4px 16px rgba(46,125,50,0.25);">
        <div style="width:42px;height:42px;border-radius:50%;background:rgba(255,255,255,0.18);display:flex;align-items:center;justify-content:center;flex-shrink:0;position:relative;">
          <i class="fas <?= $atInfo['icon'] ?>" style="color:#fff;font-size:18px;"></i>
          <span style="position:absolute;top:-2px;right:-2px;width:10px;height:10px;border-radius:50%;background:#FFD600;border:2px solid #1B5E20;"></span>
        </div>
        <div style="flex:1;min-width:0;">
          <div style="font-size:13px;font-weight:800;color:#fff;"><?= $atInfo['label'] ?> · #<?= htmlspecialchars($activeTrip['code']) ?></div>
          <div style="font-size:11.5px;color:rgba(255,255,255,0.85);margin-top:1px;"><?= $atStatusLabel ?> — I-tap para makita ang detalye</div>
        </div>
        <i class="fas fa-chevron-right" style="color:rgba(255,255,255,0.85);font-size:14px;"></i>
      </div>
      <?php endif; ?>

      <!-- 3 Service Buttons -->
      <div class="svc-row">
        <button class="svc-btn" onclick="showScreen('screenRide')">
          <div class="svc-icon-wrap svc-ride">
            <i class="fas fa-motorcycle"></i>
          </div>
          <span class="svc-name">Ride</span>
          <span class="svc-sub">Book a ride now</span>
        </button>
        <button class="svc-btn" onclick="showScreen('screenDelivery')">
          <div class="svc-icon-wrap svc-delivery">
            <i class="fas fa-box"></i>
          </div>
          <span class="svc-name">Delivery</span>
          <span class="svc-sub">Send a package</span>
        </button>
        <button class="svc-btn" onclick="showScreen('screenMore')">
          <div class="svc-icon-wrap svc-more">
            <i class="fas fa-th"></i>
          </div>
          <span class="svc-name">More</span>
          <span class="svc-sub">More Services</span>
        </button>
      </div>

      <!-- Banner Carousel -->
      <div class="banner-wrap">
        <div class="banner-track" id="bannerTrack">
        <?php if (!empty($banners)): ?>
          <?php foreach ($banners as $i => $bn):
            $bnHref = '#';
            $bnClick = '';
            switch ($bn['link_type']) {
              case 'ride':     $bnClick = "showScreen('screenRide')"; break;
              case 'delivery': $bnClick = "showScreen('screenDelivery')"; break;
              case 'shop':     $bnClick = "window.location.href='" . APP_URL . "/pages/shops.php'"; break;
              case 'promo':    $bnClick = "window.location.href='" . APP_URL . "/pages/promos.php'"; break;
              case 'merchant': $bnClick = "window.location.href='" . APP_URL . "/pages/shop.php?id=" . (int)$bn['link_value'] . "'"; break;
              case 'external': $bnClick = "window.open('" . htmlspecialchars($bn['link_value'], ENT_QUOTES) . "','_blank')"; break;
              default:         $bnClick = ''; break;
            }
          ?>
          <div class="banner-slide banner-slide-img" style="background-image:url('<?= htmlspecialchars($bn['image_url']) ?>');background-size:cover;background-position:center;<?= $bnClick ? 'cursor:pointer;' : '' ?>" <?= $bnClick ? 'onclick="'.$bnClick.'"' : '' ?>>
            <?php if ($bn['title'] || $bn['subtitle']): ?>
            <div class="slide-text" style="background:linear-gradient(0deg,rgba(15,26,58,0.78),rgba(15,26,58,0.05) 60%);">
              <?php if ($bn['subtitle']): ?><p class="slide-eyebrow"><?= htmlspecialchars($bn['subtitle']) ?></p><?php endif; ?>
              <?php if ($bn['title']): ?><h3 style="margin-bottom:0;"><?= htmlspecialchars($bn['title']) ?></h3><?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
          <!-- Slide 1 -->
          <div class="banner-slide slide-1">
            <div class="slide-text">
              <p class="slide-eyebrow">Safe, Fast and Reliable</p>
              <h3>Go Anywhere<br>with TriLink</h3>
              <button class="slide-btn" onclick="showScreen('screenRide')">Book Now</button>
            </div>
            <div class="slide-art">
              <!-- SVG tricycle illustration -->
              <svg viewBox="0 0 160 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="30" y="30" width="80" height="45" rx="8" fill="#1a2b5e" opacity="0.9"/>
                <rect x="40" y="18" width="50" height="28" rx="6" fill="#2d4a8a"/>
                <rect x="45" y="22" width="20" height="16" rx="3" fill="#a8d4ff" opacity="0.8"/>
                <rect x="68" y="22" width="20" height="16" rx="3" fill="#a8d4ff" opacity="0.8"/>
                <circle cx="48" cy="78" r="13" fill="#1a2b5e" stroke="#fff" stroke-width="2"/>
                <circle cx="48" cy="78" r="6" fill="#4a7fc1"/>
                <circle cx="108" cy="78" r="13" fill="#1a2b5e" stroke="#fff" stroke-width="2"/>
                <circle cx="108" cy="78" r="6" fill="#4a7fc1"/>
                <rect x="18" y="50" width="20" height="8" rx="4" fill="#2E7D32"/>
                <text x="38" y="62" font-size="9" fill="#00C853" font-weight="bold">TriLink</text>
              </svg>
            </div>
          </div>
          <!-- Slide 2 -->
          <div class="banner-slide slide-2">
            <div class="slide-text">
              <p class="slide-eyebrow">Deliver Anything</p>
              <h3>Send Packages<br>Fast & Secure</h3>
              <button class="slide-btn" onclick="showScreen('screenDelivery')">Send Now</button>
            </div>
          </div>
          <!-- Slide 3 -->
          <div class="banner-slide slide-3">
            <div class="slide-text">
              <p class="slide-eyebrow">Enjoy Discounts</p>
              <h3>Up to 50% OFF<br>Today Only!</h3>
              <button class="slide-btn">Shop Now</button>
            </div>
          </div>
        <?php endif; ?>
        </div>
        <!-- Dots -->
        <div class="banner-dots" id="bannerDots">
          <?php $bnCount = !empty($banners) ? count($banners) : 3; for ($d = 0; $d < $bnCount; $d++): ?>
          <span class="bdot <?= $d === 0 ? 'active' : '' ?>"></span>
          <?php endfor; ?>
        </div>
      </div>

      <!-- Nearby Merchant Shops -->
      <div class="section-hdr">
        <span class="section-title">Nearby Merchant Shops</span>
        <a href="<?= APP_URL ?>/pages/shops.php" class="see-all">See All</a>
      </div>
      <div class="merchants-row">
        <?php if (empty($merchants)): ?>
        <p style="color:#9AA0A6;font-size:13px;padding:8px 0;">Wala pang bukas na merchant sa ngayon.</p>
        <?php else: foreach ($merchants as $m):
          $mi = $merchantIcons[$m['category']] ?? $merchantIcons['other'];
        ?>
        <a class="mc" href="<?= APP_URL ?>/pages/shop.php?id=<?= (int)$m['id'] ?>">
          <div class="mc-icon" style="background:<?= $mi['bg'] ?>;"><i class="fas <?= $mi['icon'] ?>" style="color:<?= $mi['fg'] ?>;"></i></div>
          <span><?= htmlspecialchars($m['store_name']) ?></span><small><?= htmlspecialchars(ucfirst($m['category'])) ?></small>
        </a>
        <?php endforeach; endif; ?>
      </div>

      <!-- Enjoy Discounts Banner -->
      <div class="discount-banner">
        <div class="db-left">
          <p class="db-eyebrow">Special Offers</p>
          <h4>Enjoy Discounts<br><span>Up to 50% OFF!</span></h4>
          <button class="db-shopbtn">SHOP NOW</button>
        </div>
        <div class="db-right">
          <div class="db-bag">
            <i class="fas fa-shopping-bag"></i>
            <span class="db-pct">%</span>
          </div>
        </div>
      </div>

      <!-- Recent Activity (compact) -->
      <?php if (!empty($recentBookings)): ?>
      <div class="section-hdr" style="margin-top:4px;">
        <span class="section-title">Recent Activity</span>
        <a href="<?= APP_URL ?>/pages/bookings.php" class="see-all">See All</a>
      </div>
      <div class="recent-list pb-nav">
        <?php foreach ($recentBookings as $b):
          $icon = $b['svc_type'] === 'ride' ? 'fa-motorcycle' : 'fa-box';
          $iClass = $b['svc_type'] === 'ride' ? 'ri-ride' : 'ri-delivery';
          $stClass = ['completed'=>'st-done','cancelled'=>'st-cancel','pending'=>'st-pend','accepted'=>'st-active','on_trip'=>'st-active'][$b['status']] ?? 'st-pend';
          $riUrl = APP_URL . '/pages/tracking.php?code=' . urlencode($b['code']) . '&type=' . $b['svc_type'];
        ?>
        <div class="ri" onclick="window.location.href='<?= $riUrl ?>'" style="cursor:pointer;">
          <div class="ri-icon <?= $iClass ?>"><i class="fas <?= $icon ?>"></i></div>
          <div class="ri-info">
            <span class="ri-dest"><?= htmlspecialchars(mb_substr($b['dropoff_address'],0,38)) ?><?= mb_strlen($b['dropoff_address'])>38?'…':'' ?></span>
            <span class="ri-meta"><?= date('M d · h:i A', strtotime($b['created_at'])) ?></span>
          </div>
          <div class="ri-right">
            <span class="ri-amt">₱<?= number_format((float)$b['amount'],2) ?></span>
            <span class="ri-st <?= $stClass ?>"><?= ucfirst(str_replace('_',' ',$b['status'])) ?></span>
          </div>
          <i class="fas fa-chevron-right" style="color:#DADCE0;font-size:12px;margin-left:6px;"></i>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="empty-rides pb-nav">
        <i class="fas fa-route"></i>
        <p>No bookings yet</p>
        <button onclick="showScreen('screenRide')">Book a Ride</button>
      </div>
      <?php endif; ?>

    </section>

    <!-- ════ RIDE BOOKING SCREEN ═════════════════════════════ -->
    <section class="screen" id="screenRide">
      <div class="screen-hdr">
        <button class="back-btn" onclick="showScreen('screenHome')"><i class="fas fa-arrow-left"></i></button>
        <h2>Book a Ride</h2>
      </div>
      <div class="booking-form">
        <form id="rideForm" method="POST" action="<?= APP_URL ?>/api/booking.php">
          <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Session::csrfToken() ?>">
          <input type="hidden" name="action" value="book_ride">
          <input type="hidden" name="fare_total"   id="hidFare" value="35">
          <input type="hidden" name="distance_km"  id="hidDist" value="1">
          <input type="hidden" name="pickup_lat"   id="ridePickupLat">
          <input type="hidden" name="pickup_lng"   id="ridePickupLng">
          <input type="hidden" name="dropoff_lat"  id="rideDropoffLat">
          <input type="hidden" name="dropoff_lng"  id="rideDropoffLng">

          <?php if (!empty($terminals)): ?>
          <!-- Terminal or Not in Terminal -->
          <style>
            .mode-opts { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin:4px 0 12px; }
            .mode-card { display:flex; flex-direction:column; gap:3px; padding:12px; border-radius:14px; border:2px solid #EAECF0; background:#fff; cursor:pointer; }
            .mode-card input { display:none; }
            .mode-card i { font-size:20px; color:#9AA0A6; margin-bottom:2px; }
            .mode-card b { font-size:13px; color:#202124; }
            .mode-card small { font-size:11px; color:#9AA0A6; line-height:1.35; }
            .mode-card:has(input:checked) { border-color:#1A2B5E; background:#EEF2FF; }
            .mode-card:has(input:checked) i { color:#1A2B5E; }
            .terminal-pick { margin-bottom:12px; }
            .terminal-pick select { width:100%; padding:13px 12px; border-radius:12px; border:1.5px solid #EAECF0; font-size:14px; font-family:inherit; background:#fff; }
            .terminal-info { margin-top:8px; padding:10px 12px; border-radius:10px; background:#FFF8E1; color:#5D4037; font-size:12px; line-height:1.5; }
            .veh-card.locked { opacity:.4; pointer-events:none; }
          </style>
          <label class="f-label">Saan ka sasakay?</label>
          <div class="mode-opts">
            <label class="mode-card">
              <input type="radio" name="booking_mode" value="terminal">
              <i class="fas fa-warehouse"></i>
              <b>Terminal</b>
              <small>Nasa terminal ako — sa pila ng tricycle</small>
            </label>
            <label class="mode-card">
              <input type="radio" name="booking_mode" value="street" checked>
              <i class="fas fa-map-marker-alt"></i>
              <b>Not in Terminal</b>
              <small>Susunduin ako kahit saan</small>
            </label>
          </div>
          <div class="terminal-pick" id="terminalPick" style="display:none;">
            <select name="terminal_id" id="terminalSelect">
              <option value="">Piliin ang terminal…</option>
              <?php foreach ($terminals as $t): ?>
              <option value="<?= (int)$t['id'] ?>"
                      data-lat="<?= htmlspecialchars((string)$t['lat']) ?>" data-lng="<?= htmlspecialchars((string)$t['lng']) ?>"
                      data-label="<?= htmlspecialchars($t['name'] . ' – ' . $t['address']) ?>"
                      data-vehicle="<?= htmlspecialchars($t['vehicle_type']) ?>" data-queue="<?= (int)$t['queue_count'] ?>">
                <?= htmlspecialchars($t['name']) ?> (<?= (int)$t['queue_count'] ?> nakapila)
              </option>
              <?php endforeach; ?>
            </select>
            <div class="terminal-info" id="terminalInfo">Ang dispatcher sa terminal ang mag-a-assign sa'yo ng tricycle na nasa unahan ng pila.</div>
          </div>
          <?php endif; ?>

          <!-- Location row -->
          <div class="loc-card">
            <div class="lc-row">
              <div class="lc-dot green"></div>
              <div class="lc-field">
                <label id="pickupLabel">Pick-up Location</label>
                <input type="text" id="pickupAddr" name="pickup_address"
                       placeholder="Enter pick-up location" autocomplete="off" required>
              </div>
              <button type="button" class="gps-btn" id="pickupGpsBtn" onclick="useMyLocation()">
                <i class="fas fa-crosshairs"></i>
              </button>
            </div>
            <div class="lc-divider"><div class="lc-line"></div></div>
            <div class="lc-row">
              <div class="lc-dot navy"></div>
              <div class="lc-field">
                <label>Drop-off Location</label>
                <input type="text" id="dropoffAddr" name="dropoff_address"
                       placeholder="Where to?" autocomplete="off" required>
              </div>
            </div>
          </div>

          <!-- OSM Map -->
          <div id="rideMapBox" class="osm-map"></div>
          <p class="map-tip"><i class="fas fa-hand-pointer"></i> Tap map to set pick-up &amp; drop-off</p>

          <!-- Vehicle -->
          <label class="f-label">Choose Vehicle</label>
          <div class="vehicle-grid">
            <?php foreach ($fareMatrix as $v):
              $vIco = ['tricycle'=>'fa-motorcycle','e_trike'=>'fa-bolt','motorcycle'=>'fa-biking'][$v['vehicle_type']] ?? 'fa-motorcycle';
              $vLbl = ['tricycle'=>'Tricycle','e_trike'=>'E-Trike','motorcycle'=>'Motorcycle'][$v['vehicle_type']] ?? ucfirst($v['vehicle_type']);
            ?>
            <label class="veh-card <?= $v['vehicle_type']==='tricycle'?'sel':'' ?>">
              <input type="radio" name="vehicle_type" value="<?= $v['vehicle_type'] ?>" <?= $v['vehicle_type']==='tricycle'?'checked':'' ?>>
              <i class="fas <?= $vIco ?>"></i>
              <span><?= $vLbl ?></span>
              <small>₱<?= (int)$v['min_fare'] ?>+</small>
            </label>
            <?php endforeach; ?>
          </div>

          <!-- Payment -->
          <label class="f-label">Payment Method</label>
          <div class="pay-opts">
            <div class="pay-opt active" data-pay="wallet">
              <i class="fas fa-wallet"></i>
              <div><b>TriLink Wallet</b><small>₱<?= number_format((float)$user['wallet_balance'],2) ?></small></div>
              <i class="fas fa-check-circle chk"></i>
            </div>
            <div class="pay-opt" data-pay="cash">
              <i class="fas fa-money-bill-wave"></i>
              <div><b>Cash</b><small>Pay driver directly</small></div>
              <i class="fas fa-check-circle chk"></i>
            </div>
          </div>
          <input type="hidden" name="payment_method" id="payMethod" value="wallet">

          <!-- Fare estimate -->
          <div class="fare-box">
            <div class="fare-main"><span>Estimated Fare</span><strong id="fareDisplay">₱ 35.00</strong></div>
            <div class="fare-sub"><span>Distance</span><span id="distDisplay">–</span></div>
            <div class="fare-sub"><span>ETA</span><span id="etaDisplay">–</span></div>
          </div>

          <button type="submit" class="btn-book" id="rideSubmitBtn">
            <i class="fas fa-search"></i> Find Ride
          </button>
        </form>
      </div>
    </section>

    <!-- ════ DELIVERY SCREEN ════════════════════════════════ -->
    <section class="screen" id="screenDelivery">
      <div class="screen-hdr">
        <button class="back-btn" onclick="showScreen('screenHome')"><i class="fas fa-arrow-left"></i></button>
        <h2>Send a Package</h2>
      </div>
      <div class="booking-form">
        <form id="delivForm" method="POST" action="<?= APP_URL ?>/api/delivery.php">
          <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Session::csrfToken() ?>">
          <input type="hidden" name="action" value="book_delivery">
          <input type="hidden" name="fee_total"   id="hidDelFee" value="60">
          <input type="hidden" name="distance_km" id="hidDelDist" value="1">
          <input type="hidden" name="pickup_lat"  id="delivPickupLat">
          <input type="hidden" name="pickup_lng"  id="delivPickupLng">
          <input type="hidden" name="dropoff_lat" id="delivDropoffLat">
          <input type="hidden" name="dropoff_lng" id="delivDropoffLng">

          <div class="loc-card">
            <div class="lc-row">
              <div class="lc-dot green"></div>
              <div class="lc-field">
                <label>Pick-up Location</label>
                <input type="text" id="delivPickupAddrInput" name="pickup_address" placeholder="Enter pick-up location" required autocomplete="off">
              </div>
            </div>
            <div class="lc-divider"><div class="lc-line"></div></div>
            <div class="lc-row">
              <div class="lc-dot navy"></div>
              <div class="lc-field">
                <label>Drop-off Location</label>
                <input type="text" id="delivDropoffAddrInput" name="dropoff_address" placeholder="Recipient address" required autocomplete="off">
              </div>
            </div>
          </div>

          <div id="delivMapBox" class="osm-map"></div>
          <p class="map-tip"><i class="fas fa-hand-pointer"></i> Tap map to set locations</p>

          <label class="f-label">Package Size</label>
          <div class="pkg-grid">
            <label class="pkg-card sel"><input type="radio" name="package_type" value="small" checked><i class="fas fa-box-open"></i><span>Small</span><small>Max 5kg</small></label>
            <label class="pkg-card"><input type="radio" name="package_type" value="medium"><i class="fas fa-box"></i><span>Medium</span><small>Max 10kg</small></label>
            <label class="pkg-card"><input type="radio" name="package_type" value="large"><i class="fas fa-archive"></i><span>Large</span><small>Max 20kg</small></label>
          </div>

          <label class="f-label">Recipient</label>
          <input type="text" name="recipient_name" class="f-input" placeholder="Recipient name">
          <input type="tel" name="recipient_phone" class="f-input mt8" placeholder="09XXXXXXXXX">

          <label class="f-label mt8">Notes</label>
          <textarea name="package_notes" class="f-textarea" placeholder="Fragile, handle with care…"></textarea>

          <!-- Payment -->
          <label class="f-label">Payment Method</label>
          <div class="pay-opts">
            <div class="pay-opt active" data-pay="wallet">
              <i class="fas fa-wallet"></i>
              <div><b>TriLink Wallet</b><small>₱<?= number_format((float)$user['wallet_balance'],2) ?></small></div>
              <i class="fas fa-check-circle chk"></i>
            </div>
            <div class="pay-opt" data-pay="cash">
              <i class="fas fa-money-bill-wave"></i>
              <div><b>Cash</b><small>Bayad kay Rider</small></div>
              <i class="fas fa-check-circle chk"></i>
            </div>
          </div>
          <input type="hidden" name="payment_method" id="delivPayMethod" value="wallet">

          <div class="cash-payer-group" id="cashPayerGroup" style="display:none;">
            <label class="f-label">Sino ang Magbabayad ng Cash?</label>
            <div class="pay-opts">
              <div class="pay-opt active" data-payer="sender">
                <i class="fas fa-user"></i>
                <div><b>Ako (Sender)</b><small>Babayaran ko agad ang rider</small></div>
                <i class="fas fa-check-circle chk"></i>
              </div>
              <div class="pay-opt" data-payer="recipient">
                <i class="fas fa-hand-holding-usd"></i>
                <div><b>Recipient (COD)</b><small>Ang tatanggap ang magbabayad</small></div>
                <i class="fas fa-check-circle chk"></i>
              </div>
            </div>
            <input type="hidden" name="cash_payer" id="cashPayerInput" value="sender">
          </div>

          <div class="fare-box">
            <div class="fare-main"><span>Delivery Fee</span><strong id="delFeeDisplay">₱ 60.00</strong></div>
            <div class="fare-sub"><span>Distance</span><span id="delDistDisplay">–</span></div>
          </div>
          <button type="submit" class="btn-book"><i class="fas fa-shipping-fast"></i> Find Delivery</button>
        </form>
      </div>
    </section>

    <!-- ════ MORE SCREEN ════════════════════════════════════ -->
    <section class="screen" id="screenMore">
      <div class="screen-hdr">
        <button class="back-btn" onclick="showScreen('screenHome')"><i class="fas fa-arrow-left"></i></button>
        <h2>More Services</h2>
      </div>
      <div class="more-grid">
        <a href="<?= APP_URL ?>/pages/shops.php" class="more-card"><i class="fas fa-store"></i><span>Shops</span></a>
        <a href="<?= APP_URL ?>/pages/promos.php" class="more-card"><i class="fas fa-tag"></i><span>Promos</span></a>
        <a href="<?= APP_URL ?>/pages/wallet.php" class="more-card"><i class="fas fa-wallet"></i><span>Wallet</span></a>
        <a href="<?= APP_URL ?>/pages/profile.php" class="more-card"><i class="fas fa-user"></i><span>Profile</span></a>
        <a href="<?= APP_URL ?>/pages/support.php" class="more-card"><i class="fas fa-headset"></i><span>Support</span></a>
        <a href="<?= APP_URL ?>/pages/bookings.php" class="more-card"><i class="fas fa-calendar-check"></i><span>Bookings</span></a>
      </div>
    </section>

    <!-- ════ WALLET SCREEN ══════════════════════════════════ -->
    <section class="screen" id="screenWallet">
      <div class="screen-hdr">
        <button class="back-btn" onclick="showScreen('screenHome')"><i class="fas fa-arrow-left"></i></button>
        <h2>My Wallet</h2>
      </div>
      <div class="wallet-card-big">
        <div class="wcb-label">TriLink Wallet</div>
        <div class="wcb-bal">₱ <?= number_format((float)$user['wallet_balance'],2) ?></div>
        <div class="wcb-actions">
          <a href="<?= APP_URL ?>/pages/wallet.php?action=topup" class="wcb-btn"><i class="fas fa-plus"></i><span>Top Up</span></a>
          <a href="<?= APP_URL ?>/pages/wallet.php?action=send"  class="wcb-btn"><i class="fas fa-paper-plane"></i><span>Send</span></a>
          <a href="<?= APP_URL ?>/pages/wallet.php" class="wcb-btn"><i class="fas fa-history"></i><span>History</span></a>
        </div>
      </div>
      <?php
      $recentTxns = DB::all("SELECT * FROM tl_wallet_transactions WHERE user_id=? ORDER BY created_at DESC LIMIT 8", [$user['id']]);
      ?>
      <div class="section-hdr"><span class="section-title">Recent Transactions</span></div>
      <?php if (empty($recentTxns)): ?>
      <div class="empty-rides pb-nav"><i class="fas fa-wallet"></i><p>No transactions yet.</p></div>
      <?php else: ?>
      <div class="recent-list pb-nav">
        <?php foreach ($recentTxns as $t):
          $isCr = $t['direction'] === 'credit';
          $tIco = ['topup'=>'fa-plus','ride_payment'=>'fa-motorcycle','delivery_payment'=>'fa-box','send'=>'fa-paper-plane','receive'=>'fa-download','refund'=>'fa-undo','incentive'=>'fa-star'][$t['type']] ?? 'fa-exchange-alt';
        ?>
        <div class="ri">
          <div class="ri-icon <?= $isCr?'ri-credit':'ri-debit' ?>"><i class="fas <?= $tIco ?>"></i></div>
          <div class="ri-info">
            <span class="ri-dest"><?= htmlspecialchars($t['description'] ?? ucfirst(str_replace('_',' ',$t['type']))) ?></span>
            <span class="ri-meta"><?= date('M d, h:i A', strtotime($t['created_at'])) ?></span>
          </div>
          <div class="ri-right">
            <span class="ri-amt <?= $isCr?'amt-cr':'' ?>"><?= $isCr?'+':'-' ?>₱<?= number_format((float)$t['amount'],2) ?></span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

  </main>

  <!-- BOTTOM NAV -->
  <nav class="bottom-nav">
    <button class="bn active" onclick="showScreen('screenHome')"><i class="fas fa-home"></i><span>Home</span></button>
    <a href="<?= APP_URL ?>/pages/bookings.php" class="bn"><i class="fas fa-calendar-check"></i><span>Bookings</span></a>
    <a href="<?= APP_URL ?>/pages/orders.php"   class="bn"><i class="fas fa-box"></i><span>Orders</span></a>
    <a href="<?= APP_URL ?>/pages/wallet.php"   class="bn"><i class="fas fa-wallet"></i><span>Wallet</span></a>
    <a href="<?= APP_URL ?>/pages/profile.php"  class="bn"><i class="fas fa-user"></i><span>Account</span></a>
  </nav>

</div><!-- /app -->

<script>
const FARE_MATRIX = <?= $fareJson ?>;
const APP_URL     = '<?= APP_URL ?>';
const ACTIVE_TRIP  = <?= $activeTrip ? json_encode(['type' => $activeTrip['kind'], 'code' => $activeTrip['code'], 'status' => $activeTrip['status']]) : 'null' ?>;
const DEFAULT_LAT = <?= DEFAULT_LAT ?>;
const DEFAULT_LNG = <?= DEFAULT_LNG ?>;
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= filemtime(__DIR__.'/../assets/js/app.js') ?>"></script>
<script src="<?= APP_URL ?>/assets/js/map.js?v=<?= filemtime(__DIR__.'/../assets/js/map.js') ?>"></script>
<script>
// ── ACTIVE TRIP STATUS WATCHER (runs on the main dashboard) ──
// Polls the active trip's status every few seconds so the passenger still gets
// notified about driver assignment / arrival / etc. even while sitting on
// the home screen instead of the dedicated tracking page.
(function() {
  if (!ACTIVE_TRIP) return;

  var lastStatus = ACTIVE_TRIP.status;
  var statusLabels = {
    searching:  'Naghahanap ng driver…',
    accepted:   'Tinanggap na ng driver ang trip mo!',
    arrived:    'Nasa pick-up na ang driver mo!',
    on_trip:    'Nag-umpisa na ang biyahe mo.',
    picked_up:  'Nakuha na ng driver ang package mo.',
    on_the_way: 'Papunta na sa recipient ang package mo.',
    assigned:   'May driver na papunta sa tindahan.',
    completed:  'Tapos na ang trip mo!',
    delivered:  'Na-deliver na ang package mo!',
    cancelled:  'Na-cancel ang trip mo.',
  };

  if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
    Notification.requestPermission();
  }

  function notifyStatusChange(newStatus) {
    var msg = statusLabels[newStatus] || ('Update sa trip mo: ' + newStatus);
    if (typeof showToast === 'function') showToast(msg, 'success');

    if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
      var n = new Notification('TriLink — #' + ACTIVE_TRIP.code, { body: msg, tag: 'trilink-trip-status', renotify: true });
      n.onclick = function() {
        window.focus();
        window.location.href = APP_URL + '/pages/tracking.php?code=' + encodeURIComponent(ACTIVE_TRIP.code) + '&type=' + ACTIVE_TRIP.type;
        n.close();
      };
      setTimeout(function() { n.close(); }, 8000);
    }

    var doneStates = ['completed', 'delivered', 'cancelled'];
    if (doneStates.indexOf(newStatus) !== -1) {
      clearInterval(watchTimer);
      setTimeout(function() { window.location.reload(); }, 1500);
    }
  }

  function pollActiveTripStatus() {
    fetch(APP_URL + '/api/trip_status.php?code=' + encodeURIComponent(ACTIVE_TRIP.code) + '&type=' + ACTIVE_TRIP.type + '&t=' + Date.now(), { cache: 'no-store' })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (!res.success || !res.data) return;
        var newStatus = res.data.status;
        if (newStatus && newStatus !== lastStatus) {
          lastStatus = newStatus;
          notifyStatusChange(newStatus);
        }
      })
      .catch(function() {});
  }

  var watchTimer = setInterval(pollActiveTripStatus, 5000);
})();
</script>
</body>
</html>
