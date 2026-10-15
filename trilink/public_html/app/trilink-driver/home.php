<?php
require_once __DIR__ . '/includes/core.php';
driverCheck();
$driver = getDriver();
if (!$driver) driverLogout();

// If the driver already has an in-progress trip (ride, delivery, or merchant
// order), send them straight back to it instead of losing track of it.
$activeTrip = getDriverActiveTrip($driver['id']);
if ($activeTrip) {
    redirect('/on_trip.php?id=' . $activeTrip['id'] . '&type=' . $activeTrip['type']);
}

// Today's earnings
$todayStats = DB::row(
    "SELECT
        COALESCE(SUM(CASE WHEN status='completed' THEN fare_total ELSE 0 END),0) AS total,
        COALESCE(SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END),0) AS completed,
        COALESCE(SUM(distance_km),0) AS total_km
     FROM tl_bookings WHERE driver_id=? AND DATE(created_at)=CURDATE()",
    [$driver['id']]
);

$earningsBase      = (float)($todayStats['total'] ?? 0) * 0.85;
$earningsIncentive = (float)($todayStats['total'] ?? 0) * 0.10;
$earningsTips      = (float)($todayStats['total'] ?? 0) * 0.05;

// Work mode: Terminal (pila, dispatcher assigns) or Not in Terminal (street requests)
require_once __DIR__ . '/../trilink-dispatch/lib/Dispatch.php';
$work = ['mode' => 'street', 'terminal_id' => null, 'terminal_name' => null, 'queue' => null];
try { $work = Dispatch::driverMode((int)$driver['id']); } catch (Exception $e) { /* dispatch migration not run yet */ }
$streetOff = $work['mode'] === 'terminal';

// Pending ride requests (excluding ones this driver already declined)
$pendingRequests = $streetOff ? [] : DB::all(
    "SELECT b.*, u.full_name AS passenger_name, u.phone AS passenger_phone
     FROM tl_bookings b JOIN tl_users u ON b.user_id=u.id
     WHERE b.status='searching' AND b.driver_id IS NULL AND b.vehicle_type=?
       AND b.booking_mode='street'
       AND b.id NOT IN (SELECT booking_id FROM tl_booking_declines WHERE driver_id = ?)
     ORDER BY b.created_at ASC LIMIT 3",
    [$driver['vehicle_type'], $driver['id']]
);

// Pending merchant order pickups (excluding ones this driver already declined)
$pendingOrders = $streetOff ? [] : DB::all(
    "SELECT o.id, o.order_code, o.total, o.delivery_fee, o.payment_method, o.delivery_address,
            m.store_name, m.address AS pickup_address,
            u.full_name AS customer_name, u.phone AS customer_phone
     FROM tl_merchant_orders o
     JOIN tl_merchants m ON o.merchant_id = m.id
     JOIN tl_users u ON o.user_id = u.id
     WHERE o.status='ready' AND o.driver_id IS NULL
       AND o.id NOT IN (SELECT order_id FROM tl_order_declines WHERE driver_id = ?)
     ORDER BY o.created_at ASC LIMIT 3",
    [$driver['id']]
);

// Pending package delivery requests (excluding ones this driver already declined)
$pendingDeliveries = $streetOff ? [] : DB::all(
    "SELECT d.id, d.delivery_code, d.pickup_address, d.dropoff_address,
            d.fee_total, d.payment_method, d.distance_km, d.package_type,
            u.full_name AS sender_name, u.phone AS sender_phone
     FROM tl_deliveries d
     JOIN tl_users u ON d.user_id = u.id
     WHERE d.status='searching' AND d.driver_id IS NULL
       AND d.id NOT IN (SELECT delivery_id FROM tl_delivery_declines WHERE driver_id = ?)
     ORDER BY d.created_at ASC LIMIT 3",
    [$driver['id']]
);

// Recent trips
$recentTrips = DB::all(
    "SELECT b.*, u.full_name AS passenger_name
     FROM tl_bookings b JOIN tl_users u ON b.user_id=u.id
     WHERE b.driver_id=? ORDER BY b.created_at DESC LIMIT 5",
    [$driver['id']]
);

$isOnline  = (bool)$driver['is_online'];
$firstName = explode(' ', $driver['full_name'])[0];
$csrfToken = Session::csrfToken();
$requestTimeoutSecs = (int)setting('driver_request_timeout_secs', 30);

$terminals = [];
try { $terminals = Dispatch::activeTerminals(); } catch (Exception $e) { /* dispatch migration not run yet */ }

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>TriLink Driver – Dashboard</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/home.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/driver.css">
</head>
<body>
<div class="app" style="font-family:'Inter',sans-serif;overflow:visible;">

  <!-- TOPBAR -->
  <header class="topbar" style="background:linear-gradient(135deg,#0F1A3A,#1A2B5E);">
    <button class="tb-btn" id="menuBtn"><i class="fas fa-bars"></i></button>
    <div class="tb-logo">
      <div class="tb-logo-icon"><i class="fas fa-motorcycle"></i></div>
      <span><span class="tri">Tri</span><span class="lnk">Link</span></span>
      <span style="font-size:10px;font-weight:700;background:rgba(0,200,83,0.2);border:1px solid rgba(0,200,83,0.4);color:#00C853;padding:2px 8px;border-radius:10px;margin-left:4px;">DRIVER</span>
    </div>
    <button class="tb-btn" id="notifBtn"><i class="fas fa-bell"></i></button>
  </header>

  <!-- SIDE DRAWER -->
  <div class="drawer-overlay hidden" id="drawerOverlay"></div>
  <nav class="side-drawer hidden" id="sideDrawer">
    <div class="dr-head">
      <div class="dr-user">
        <div class="dr-avatar"><i class="fas fa-user"></i></div>
        <div>
          <div class="dr-name"><?= htmlspecialchars($driver['full_name']) ?></div>
          <div class="dr-phone"><?= htmlspecialchars($driver['driver_code']) ?></div>
        </div>
      </div>
      <button id="drawerClose"><i class="fas fa-times"></i></button>
    </div>
    <div class="dr-wallet">
      <span class="dr-wlabel"><i class="fas fa-wallet"></i> Driver Wallet</span>
      <span class="dr-wbal">₱ <?= number_format((float)$driver['wallet_balance'],2) ?></span>
    </div>
    <ul class="dr-nav">
      <li class="dr-active"><a href="<?= APP_URL ?>/home.php"><i class="fas fa-home"></i> Dashboard</a></li>
      <li><a href="<?= APP_URL ?>/trips.php"><i class="fas fa-route"></i> My Trips</a></li>
      <li><a href="<?= APP_URL ?>/deliveries.php"><i class="fas fa-box"></i> My Deliveries</a></li>
      <li><a href="<?= APP_URL ?>/earnings.php"><i class="fas fa-chart-line"></i> Earnings</a></li>
      <li><a href="<?= APP_URL ?>/profile.php"><i class="fas fa-user"></i> My Profile</a></li>
      <li class="dr-sep"></li>
      <li><a href="<?= APP_URL ?>/logout.php" class="dr-logout"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
    </ul>
  </nav>

  <!-- MAIN -->
  <main style="flex:1;overflow-y:auto;background:#F5F6FA;">

    <!-- DRIVER HERO -->
    <div class="driver-hero">
      <div class="dh-top">
        <div>
          <div class="dh-greeting">Hello, <?= htmlspecialchars($firstName) ?>!</div>
          <div class="dh-code"><?= htmlspecialchars($driver['driver_code']) ?></div>
          <div class="dh-rating">
            <i class="fas fa-star"></i> <?= number_format((float)$driver['rating'],1) ?>
            <span>(<?= $driver['total_ratings'] ?> ratings)</span>
          </div>
        </div>
        <div class="dh-avatar">
          <i class="fas fa-user"></i>
          <div class="dh-status-dot <?= $isOnline?'online':'offline' ?>"></div>
        </div>
      </div>

      <!-- Online Toggle -->
      <div class="online-toggle-row">
        <div class="ot-left">
          <div class="ot-label" id="onlineLabel"><?= $isOnline?'You are Online':'You are Offline' ?></div>
          <div class="ot-sub" id="onlineSub"><?= $isOnline ? ($streetOff ? 'Terminal mode – hintayin ang dispatcher' : 'Receiving ride requests') : 'Go online to receive rides' ?></div>
        </div>
        <label class="toggle-switch">
          <input type="checkbox" id="onlineToggle" <?= $isOnline?'checked':'' ?>>
          <span class="toggle-slider"></span>
        </label>
      </div>

      <?php if (!empty($terminals)): ?>
      <!-- WORK MODE: Terminal / Not in Terminal -->
      <style>
        .mode-card { margin:12px 0 4px; background:#fff; border-radius:16px; padding:12px; box-shadow:0 4px 14px rgba(0,0,0,.15); color:#202124; }
        .mode-seg { display:grid; grid-template-columns:1fr 1fr; gap:6px; background:#F1F3F4; padding:4px; border-radius:12px; }
        .mode-seg button { border:none; background:transparent; padding:10px 6px; border-radius:9px; font-weight:800; font-size:13px; color:#5F6368; cursor:pointer; font-family:inherit; display:flex; align-items:center; justify-content:center; gap:6px; }
        .mode-seg button.on { background:#fff; color:#1A2B5E; box-shadow:0 1px 4px rgba(0,0,0,.12); }
        .mode-seg button.on[data-mode=terminal] { color:#E65100; }
        .mode-seg button.on[data-mode=street] { color:#2E7D32; }
        .mode-pane { margin-top:10px; }
        .mode-row { display:flex; gap:8px; align-items:center; }
        .mode-row select { flex:1; min-width:0; padding:11px; border-radius:11px; border:1.5px solid #EAECF0; font-size:13px; font-family:inherit; background:#fff; }
        .mode-btn { padding:11px 14px; border-radius:11px; border:none; font-weight:800; font-size:13px; cursor:pointer; font-family:inherit; background:linear-gradient(135deg,#E65100,#FB8C00); color:#fff; white-space:nowrap; }
        .mode-status { display:flex; align-items:center; gap:10px; margin-top:10px; padding:10px 12px; border-radius:12px; background:#FFF3E0; }
        .mode-status .pos { font-family:'Plus Jakarta Sans',sans-serif; font-size:26px; font-weight:800; color:#E65100; line-height:1; }
        .mode-status .txt { font-size:12px; color:#5D4037; line-height:1.4; }
        .mode-status.out { background:#FFEBEE; }
        .mode-status.out .txt { color:#C62828; }
        .mode-note { font-size:12px; color:#5F6368; line-height:1.45; }
      </style>
      <div class="mode-card" id="modeCard">
        <div class="mode-seg">
          <button type="button" data-mode="terminal" onclick="pickMode('terminal')"><i class="fas fa-warehouse"></i> Terminal</button>
          <button type="button" data-mode="street" onclick="pickMode('street')"><i class="fas fa-map-marker-alt"></i> Not in Terminal</button>
        </div>
        <div class="mode-pane" id="paneTerminal" style="display:none;">
          <div class="mode-row">
            <select id="modeTerminal" onchange="renderModeCard()">
              <?php foreach ($terminals as $t): ?>
              <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['name']) ?> (<?= (int)$t['queue_count'] ?> nakapila)</option>
              <?php endforeach; ?>
            </select>
            <button type="button" class="mode-btn" id="modeJoinBtn" onclick="joinTerminal()"><i class="fas fa-sign-in-alt"></i> Pumila</button>
          </div>
          <div class="mode-status" id="modeStatus" style="display:none;"></div>
        </div>
        <div class="mode-pane mode-note" id="paneStreet" style="display:none;">
          <i class="fas fa-satellite-dish" style="color:#2E7D32;"></i> Tumatanggap ka ng ride, delivery at order requests sa paligid mo.
        </div>
      </div>
      <?php endif; ?>

      <!-- Earnings -->
      <div class="today-earnings">
        <div class="te-label">Today's Earnings</div>
        <div class="te-amount">₱ <?= number_format((float)($todayStats['total']??0),2) ?></div>
        <div class="te-breakdown">
          <div class="te-item"><span>Base Fare</span><strong>₱<?= number_format($earningsBase,2) ?></strong></div>
          <div class="te-div"></div>
          <div class="te-item"><span>Incentives</span><strong>₱<?= number_format($earningsIncentive,2) ?></strong></div>
          <div class="te-div"></div>
          <div class="te-item"><span>Tips</span><strong>₱<?= number_format($earningsTips,2) ?></strong></div>
        </div>
      </div>
    </div>

    <!-- STATS -->
    <div class="stats-row">
      <div class="stat-card"><i class="fas fa-check-circle" style="color:#2E7D32;"></i><div class="sc-val"><?= $todayStats['completed']??0 ?></div><div class="sc-label">Completed</div></div>
      <div class="stat-card"><i class="fas fa-clock" style="color:#1565C0;"></i><div class="sc-val">–</div><div class="sc-label">Online Time</div></div>
      <div class="stat-card"><i class="fas fa-road" style="color:#E65100;"></i><div class="sc-val"><?= number_format((float)($todayStats['total_km']??0),1) ?> km</div><div class="sc-label">Distance</div></div>
    </div>

    <!-- INCENTIVE BANNER -->
    <div class="incentive-banner">
      <div class="ib-icon">🏆</div>
      <div class="ib-text">
        <div class="ib-title">More Trips, More Earnings!</div>
        <div class="ib-sub">Complete 5 trips today for a ₱100 bonus</div>
      </div>
    </div>

    <!-- RIDE REQUESTS -->
    <div id="requestsSection">
    <?php if (!empty($pendingRequests)): ?>
    <div class="section-hdr">
      <span class="section-title">Ride Requests</span>
      <span class="request-count"><?= count($pendingRequests) ?> new</span>
    </div>
    <?php foreach ($pendingRequests as $i => $req):
      $dist = $req['distance_km'] ? number_format((float)$req['distance_km'],1).' km' : '–';
    ?>
    <div class="request-card <?= $i===0?'first-request':'' ?>" id="rcard_<?= $req['id'] ?>">
      <div class="rc-header">
        <span class="rc-badge"><i class="fas fa-bell"></i> New Request</span>
        <span class="rc-timer" id="timer_<?= $req['id'] ?>"><?= $requestTimeoutSecs ?>s</span>
      </div>
      <div class="rc-route">
        <div class="rc-stop"><div class="rc-dot green"></div><div><div class="rc-addr"><?= htmlspecialchars(mb_substr($req['pickup_address'],0,45)) ?></div><div class="rc-label">Pick-up</div></div></div>
        <div class="rc-line"></div>
        <div class="rc-stop"><div class="rc-dot navy"></div><div><div class="rc-addr"><?= htmlspecialchars(mb_substr($req['dropoff_address'],0,45)) ?></div><div class="rc-label">Drop-off</div></div></div>
      </div>
      <div class="rc-meta">
        <span><i class="fas fa-road"></i> <?= $dist ?></span>
        <span><i class="fas fa-<?= $req['payment_method']==='cash'?'money-bill':'wallet' ?>"></i> <?= ucfirst($req['payment_method']) ?></span>
      </div>
      <div class="rc-fare">₱ <?= number_format((float)$req['fare_total'],2) ?></div>
      <div class="rc-passenger">
        <div class="rcp-avatar"><i class="fas fa-user"></i></div>
        <div><div class="rcp-name"><?= htmlspecialchars($req['passenger_name']) ?></div><div class="rcp-phone"><?= htmlspecialchars($req['passenger_phone']) ?></div></div>
      </div>
      <div class="rc-actions">
        <button class="btn-decline" onclick="declineRequest(<?= $req['id'] ?>)"><i class="fas fa-times"></i> Decline</button>
        <button class="btn-accept" onclick="acceptRequest(<?= $req['id'] ?>)"><i class="fas fa-check"></i> Accept</button>
      </div>
      <div class="rc-autotimer">Auto-decline in <span id="auto_<?= $req['id'] ?>"><?= $requestTimeoutSecs ?></span>s</div>
    </div>
    <?php endforeach; ?>
    <?php elseif ($isOnline): ?>
    <div class="no-requests">
      <div class="nr-pulse"><div class="nr-ring r1"></div><div class="nr-ring r2"></div><div class="nr-ring r3"></div><div class="nr-center"><i class="fas fa-motorcycle"></i></div></div>
      <p>Searching for ride requests…</p><small>Stay online to receive bookings</small>
    </div>
    <?php else: ?>
    <div class="no-requests offline-state">
      <i class="fas fa-power-off" style="font-size:48px;color:#DADCE0;"></i>
      <p>You are offline</p><small>Toggle online to receive ride requests</small>
    </div>
    <?php endif; ?>
    </div>

    <!-- MERCHANT ORDER PICKUPS -->
    <div id="orderRequestsSection">
    <?php if (!empty($pendingOrders)): ?>
    <div class="section-hdr">
      <span class="section-title">Shop Order Pickups</span>
      <span class="request-count"><?= count($pendingOrders) ?> new</span>
    </div>
    <?php foreach ($pendingOrders as $ord): ?>
    <div class="request-card" id="ordcard_<?= $ord['id'] ?>">
      <div class="rc-header">
        <span class="rc-badge" style="background:#3949AB;"><i class="fas fa-shopping-basket"></i> Shop Pickup</span>
        <span class="rc-timer" id="otimer_<?= $ord['id'] ?>"><?= $requestTimeoutSecs ?>s</span>
      </div>
      <div class="rc-route">
        <div class="rc-stop"><div class="rc-dot green"></div><div><div class="rc-addr"><?= htmlspecialchars(mb_substr($ord['pickup_address'] ?: $ord['store_name'],0,45)) ?></div><div class="rc-label"><?= htmlspecialchars($ord['store_name']) ?></div></div></div>
        <div class="rc-line"></div>
        <div class="rc-stop"><div class="rc-dot navy"></div><div><div class="rc-addr"><?= htmlspecialchars(mb_substr($ord['delivery_address'],0,45)) ?></div><div class="rc-label">Deliver to</div></div></div>
      </div>
      <div class="rc-meta">
        <span><i class="fas fa-store"></i> <?= htmlspecialchars($ord['store_name']) ?></span>
        <span><i class="fas fa-<?= $ord['payment_method']==='cash'?'money-bill':'wallet' ?>"></i> <?= ucfirst($ord['payment_method']) ?></span>
      </div>
      <div class="rc-fare">₱ <?= number_format((float)$ord['delivery_fee'],2) ?> <small style="font-size:11px;color:#9AA0A6;font-weight:600;">delivery fee</small></div>
      <div class="rc-passenger">
        <div class="rcp-avatar"><i class="fas fa-user"></i></div>
        <div><div class="rcp-name"><?= htmlspecialchars($ord['customer_name']) ?></div><div class="rcp-phone"><?= htmlspecialchars($ord['customer_phone']) ?></div></div>
      </div>
      <div class="rc-actions">
        <button class="btn-decline" onclick="declineOrderPickup(<?= $ord['id'] ?>)"><i class="fas fa-times"></i> Decline</button>
        <button class="btn-accept" onclick="acceptOrderPickup(<?= $ord['id'] ?>)"><i class="fas fa-check"></i> Accept</button>
      </div>
      <div class="rc-autotimer">Auto-decline in <span id="oauto_<?= $ord['id'] ?>"><?= $requestTimeoutSecs ?></span>s</div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
    </div>

    <!-- PACKAGE DELIVERY REQUESTS -->
    <div id="deliveryRequestsSection">
    <?php if (!empty($pendingDeliveries)): ?>
    <div class="section-hdr">
      <span class="section-title">Package Deliveries</span>
      <span class="request-count"><?= count($pendingDeliveries) ?> new</span>
    </div>
    <?php foreach ($pendingDeliveries as $del):
      $ddist = $del['distance_km'] ? number_format((float)$del['distance_km'],1).' km' : '–';
    ?>
    <div class="request-card" id="delcard_<?= $del['id'] ?>">
      <div class="rc-header">
        <span class="rc-badge" style="background:#8E24AA;"><i class="fas fa-box"></i> Package Delivery</span>
        <span class="rc-timer" id="dtimer_<?= $del['id'] ?>"><?= $requestTimeoutSecs ?>s</span>
      </div>
      <div class="rc-route">
        <div class="rc-stop"><div class="rc-dot green"></div><div><div class="rc-addr"><?= htmlspecialchars(mb_substr($del['pickup_address'],0,45)) ?></div><div class="rc-label">Pick-up</div></div></div>
        <div class="rc-line"></div>
        <div class="rc-stop"><div class="rc-dot navy"></div><div><div class="rc-addr"><?= htmlspecialchars(mb_substr($del['dropoff_address'],0,45)) ?></div><div class="rc-label">Drop-off</div></div></div>
      </div>
      <div class="rc-meta">
        <span><i class="fas fa-box-open"></i> <?= ucfirst($del['package_type']) ?></span>
        <span><i class="fas fa-road"></i> <?= $ddist ?></span>
        <span><i class="fas fa-<?= $del['payment_method']==='cash'?'money-bill':'wallet' ?>"></i> <?= ucfirst($del['payment_method']) ?></span>
      </div>
      <div class="rc-fare">₱ <?= number_format((float)$del['fee_total'],2) ?></div>
      <div class="rc-passenger">
        <div class="rcp-avatar"><i class="fas fa-user"></i></div>
        <div><div class="rcp-name"><?= htmlspecialchars($del['sender_name']) ?></div><div class="rcp-phone"><?= htmlspecialchars($del['sender_phone']) ?></div></div>
      </div>
      <div class="rc-actions">
        <button class="btn-decline" onclick="declineDelivery(<?= $del['id'] ?>)"><i class="fas fa-times"></i> Decline</button>
        <button class="btn-accept" onclick="acceptDelivery(<?= $del['id'] ?>)"><i class="fas fa-check"></i> Accept</button>
      </div>
      <div class="rc-autotimer">Auto-decline in <span id="dauto_<?= $del['id'] ?>"><?= $requestTimeoutSecs ?></span>s</div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
    </div>

    <!-- RECENT TRIPS -->
    <div class="section-hdr">
      <span class="section-title">Recent Trips</span>
      <a href="<?= APP_URL ?>/trips.php" class="see-all">See All</a>
    </div>
    <?php if (empty($recentTrips)): ?>
    <div style="text-align:center;padding:30px 20px 90px;color:#9AA0A6;font-size:14px;">
      <i class="fas fa-route" style="font-size:36px;opacity:0.3;display:block;margin-bottom:10px;"></i>No trips yet today
    </div>
    <?php else: ?>
    <div class="trips-list">
      <?php foreach ($recentTrips as $t):
        $stClass = ['completed'=>'st-done','cancelled'=>'st-cancel','on_trip'=>'st-active','accepted'=>'st-active'][$t['status']] ?? 'st-pend';
        $stLabel = ['completed'=>'Completed','cancelled'=>'Cancelled','on_trip'=>'On Trip','accepted'=>'Accepted'][$t['status']] ?? ucfirst($t['status']);
      ?>
      <div class="trip-item">
        <div class="ti-icon"><i class="fas fa-motorcycle"></i></div>
        <div class="ti-info">
          <div class="ti-dest"><?= htmlspecialchars(mb_substr($t['dropoff_address'],0,38)) ?>…</div>
          <div class="ti-meta"><?= date('M d · h:i A', strtotime($t['created_at'])) ?> · <?= htmlspecialchars($t['passenger_name']) ?></div>
        </div>
        <div class="ti-right">
          <div class="ti-amt">+₱<?= number_format((float)$t['fare_total'],2) ?></div>
          <div class="ti-st <?= $stClass ?>"><?= $stLabel ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="height:80px;"></div>
    <?php endif; ?>

  </main>

  <!-- BOTTOM NAV -->
  <nav class="bottom-nav">
    <button class="bn active"><i class="fas fa-home"></i><span>Home</span></button>
    <a class="bn" href="<?= APP_URL ?>/trips.php"><i class="fas fa-route"></i><span>Trips</span></a>
    <a class="bn" href="<?= APP_URL ?>/earnings.php"><i class="fas fa-chart-line"></i><span>Earnings</span></a>
    <a class="bn" href="<?= APP_URL ?>/wallet.php"><i class="fas fa-wallet"></i><span>Wallet</span></a>
    <a class="bn" href="<?= APP_URL ?>/profile.php"><i class="fas fa-user"></i><span>Profile</span></a>
  </nav>

</div>

<script>
const APP_URL   = '<?= APP_URL ?>';
const CSRF_NAME = '<?= CSRF_TOKEN_NAME ?>';
const CSRF_TOK  = '<?= $csrfToken ?>';

// Drawer
document.getElementById('menuBtn')?.addEventListener('click',()=>{document.getElementById('sideDrawer').classList.remove('hidden');document.getElementById('drawerOverlay').classList.remove('hidden');});
document.getElementById('drawerClose')?.addEventListener('click',closeDrawer);
document.getElementById('drawerOverlay')?.addEventListener('click',closeDrawer);
function closeDrawer(){document.getElementById('sideDrawer').classList.add('hidden');document.getElementById('drawerOverlay').classList.add('hidden');}

// Work mode: Terminal (pila) / Not in Terminal (street)
let WORK = <?= json_encode($work) ?>;
let modeView = WORK.mode;            // which tab is showing (may differ before joining)
function renderModeCard() {
  if (!document.getElementById('modeCard')) return;
  document.querySelectorAll('.mode-seg button').forEach(b => b.classList.toggle('on', b.dataset.mode === modeView));
  document.getElementById('paneTerminal').style.display = modeView === 'terminal' ? 'block' : 'none';
  document.getElementById('paneStreet').style.display   = modeView === 'street' ? 'block' : 'none';
  if (modeView !== 'terminal') return;
  const sel = document.getElementById('modeTerminal');
  const btn = document.getElementById('modeJoinBtn');
  const st  = document.getElementById('modeStatus');
  const q   = WORK.queue;
  const inThisPila = q && String(q.terminal_id) === sel.value;
  btn.style.display = inThisPila ? 'none' : '';
  btn.innerHTML = '<i class="fas fa-sign-in-alt"></i> ' + (q ? 'Lumipat dito' : (WORK.mode === 'terminal' ? 'Pumila ulit' : 'Pumila'));
  if (q) {
    st.className = 'mode-status'; st.style.display = 'flex';
    st.innerHTML = '<div class="pos">#' + q.position + '</div><div class="txt"><b>' + q.terminal_name + '</b> · ' + q.waiting_count + ' nakapila<br>'
      + (q.position === 1 ? 'Ikaw ang susunod! Abangan ang dispatcher.' : 'Nakapila ka. Ang dispatcher ang magbibigay ng pasahero.') + '</div>';
  } else if (WORK.mode === 'terminal') {
    st.className = 'mode-status out'; st.style.display = 'flex';
    st.innerHTML = '<i class="fas fa-exclamation-circle" style="color:#C62828;font-size:20px;"></i><div class="txt">Terminal mode ka pero <b>wala ka sa pila</b>. Bumalik sa terminal at pindutin ang <b>Pumila ulit</b>.</div>';
  } else {
    st.style.display = 'none';
  }
}
function updateModeCard(work) {
  const changedMode = work.mode !== WORK.mode;
  WORK = work;
  if (changedMode) modeView = work.mode;   // e.g. admin closed the terminal
  renderModeCard();
}
function modePost(data) {
  const body = new URLSearchParams(Object.assign({ [CSRF_NAME]: CSRF_TOK }, data));
  return fetch(APP_URL + '/api/queue.php', { method: 'POST', body }).then(r => r.json());
}
function pickMode(mode) {
  if (mode === modeView) return;
  if (mode === 'street') {
    if (WORK.mode === 'terminal') {
      if (WORK.queue && !confirm('Lumipat sa Not in Terminal? Mawawala ang puwesto mo sa pila.')) return;
      modePost({ action: 'set_mode', mode: 'street' }).then(res => {
        showToast(res.message, res.success ? 'success' : 'error');
        if (res.success) setTimeout(() => location.reload(), 700);
      });
      return;
    }
    modeView = 'street'; renderModeCard(); return;
  }
  modeView = 'terminal'; renderModeCard();   // choose terminal, then press Pumila
}
function joinTerminal() {
  const btn = document.getElementById('modeJoinBtn');
  const terminal_id = document.getElementById('modeTerminal').value;
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
  const send = (lat, lng) => modePost({ action: 'set_mode', mode: 'terminal', terminal_id, lat: lat || '', lng: lng || '' })
    .then(res => {
      showToast(res.message, res.success ? 'success' : 'error');
      if (res.success) setTimeout(() => location.reload(), 700);
    })
    .catch(() => showToast('Network error. Subukan ulit.', 'error'))
    .finally(() => { btn.disabled = false; renderModeCard(); });
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(p => send(p.coords.latitude, p.coords.longitude), () => send(), { enableHighAccuracy: true, timeout: 8000 });
  } else { send(); }
}
if (WORK.terminal_id && document.getElementById('modeTerminal')) document.getElementById('modeTerminal').value = WORK.terminal_id;
renderModeCard();

// Online toggle
document.getElementById('onlineToggle')?.addEventListener('change',function(){
  const on=this.checked?1:0;
  fetch(APP_URL+'/api/toggle_online.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`is_online=${on}&${CSRF_NAME}=${CSRF_TOK}`})
  .then(r=>r.json()).then(res=>{
    if(res.success){
      document.getElementById('onlineLabel').textContent=on?'You are Online':'You are Offline';
      document.getElementById('onlineSub').textContent=on?(WORK.mode==='terminal'?'Terminal mode – hintayin ang dispatcher':'Receiving ride requests'):'Go online to receive rides';
      document.querySelector('.dh-status-dot').className='dh-status-dot '+(on?'online':'offline');
      showToast(on?'You are now Online!':'You are now Offline.',on?'success':'info');
      setTimeout(()=>location.reload(),1200);
    }
  });
});

// Accept/Decline
function acceptRequest(id){
  if(!confirm('Accept this ride?'))return;
  fetch(APP_URL+'/api/accept_ride.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`booking_id=${id}&${CSRF_NAME}=${CSRF_TOK}`})
  .then(r=>r.json()).then(res=>{if(res.success){showToast('Ride accepted! Starting trip…','success');setTimeout(()=>{window.location.href=APP_URL+'/on_trip.php?id='+id+'&type=ride';},1000);}else{showToast(res.message,'error');}});
}
function declineRequest(id){
  fetch(APP_URL+'/api/decline_ride.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`booking_id=${id}&${CSRF_NAME}=${CSRF_TOK}`})
  .then(r=>r.json()).then(()=>{
    showToast('Declined.');
    const card=document.getElementById('rcard_'+id);
    if(card) card.remove();
    const sec=document.getElementById('requestsSection');
    if(sec && !sec.querySelector('.request-card')){
      sec.innerHTML = '<div class="section-hdr"><span class="section-title">Ride Requests</span></div>';
    }
  });
}

// Accept/Decline — Merchant Order Pickups
function acceptOrderPickup(id){
  if(!confirm('Accept this shop order pickup?'))return;
  fetch(APP_URL+'/api/accept_order_pickup.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`order_id=${id}&${CSRF_NAME}=${CSRF_TOK}`})
  .then(r=>r.json()).then(res=>{if(res.success){showToast('Order accepted! Starting trip…','success');setTimeout(()=>{window.location.href=APP_URL+'/on_trip.php?id='+id+'&type=order';},1000);}else{showToast(res.message,'error');}});
}
function declineOrderPickup(id){
  fetch(APP_URL+'/api/decline_order_pickup.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`order_id=${id}&${CSRF_NAME}=${CSRF_TOK}`})
  .then(r=>r.json()).then(()=>{
    showToast('Declined.');
    const card=document.getElementById('ordcard_'+id);
    if(card) card.remove();
    const sec=document.getElementById('orderRequestsSection');
    if(sec && !sec.querySelector('.request-card')){
      sec.innerHTML = '';
    }
  });
}

// Accept/Decline — Package Deliveries
function acceptDelivery(id){
  if(!confirm('Accept this package delivery?'))return;
  fetch(APP_URL+'/api/accept_delivery.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`delivery_id=${id}&${CSRF_NAME}=${CSRF_TOK}`})
  .then(r=>r.json()).then(res=>{if(res.success){showToast('Delivery accepted! Starting trip…','success');setTimeout(()=>{window.location.href=APP_URL+'/on_trip.php?id='+id+'&type=delivery';},1000);}else{showToast(res.message,'error');}});
}
function declineDelivery(id){
  fetch(APP_URL+'/api/decline_delivery.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`delivery_id=${id}&${CSRF_NAME}=${CSRF_TOK}`})
  .then(r=>r.json()).then(()=>{
    showToast('Declined.');
    const card=document.getElementById('delcard_'+id);
    if(card) card.remove();
    const sec=document.getElementById('deliveryRequestsSection');
    if(sec && !sec.querySelector('.request-card')){
      sec.innerHTML = '';
    }
  });
}

// Countdown timers are now started by the polling/render system below,
// so both server-rendered and dynamically-added cards work the same way.


// ── REAL-TIME BOOKING NOTIFICATIONS ─────────────────────────
// realtime vars injected below


function showToast(msg,type='info'){
  document.querySelectorAll('.tl-toast').forEach(t=>t.remove());
  const bg={info:'#1A2B5E',success:'#2E7D32',error:'#C62828'}[type]||'#1A2B5E';
  const el=document.createElement('div');el.className='tl-toast';el.innerHTML=msg;
  Object.assign(el.style,{position:'fixed',bottom:'76px',left:'50%',transform:'translateX(-50%)',background:bg,color:'#fff',padding:'12px 20px',borderRadius:'30px',fontSize:'13px',fontWeight:'700',boxShadow:'0 4px 20px rgba(0,0,0,0.22)',zIndex:'9999',whiteSpace:'nowrap',fontFamily:"'Inter',sans-serif"});
  document.body.appendChild(el);
  setTimeout(()=>{el.style.opacity='0';el.style.transition='opacity 0.3s';setTimeout(()=>el.remove(),300);},3000);
}
</script>
<script>
/* ============================================================
   TriLink Driver - Live polling, no page reload needed
   ============================================================ */

(function() {
  'use strict';

  var APP_URL         = '<?= APP_URL ?>';
  var LAST_ID         = <?= !empty($pendingRequests) ? (int)max(array_column($pendingRequests, 'id')) : 0 ?>;
  var LAST_ORDER_ID   = <?= !empty($pendingOrders) ? (int)max(array_column($pendingOrders, 'id')) : 0 ?>;
  var LAST_DELIVERY_ID= <?= !empty($pendingDeliveries) ? (int)max(array_column($pendingDeliveries, 'id')) : 0 ?>;
  var driverIsOnline  = <?= $isOnline ? 'true' : 'false' ?>;
  var REQUEST_TIMEOUT = <?= $requestTimeoutSecs ?>;
  var renderedIds     = <?= json_encode(array_map(function($r){ return (int)$r['id']; }, $pendingRequests)) ?>;
  var renderedOrderIds= <?= json_encode(array_map(function($r){ return (int)$r['id']; }, $pendingOrders)) ?>;
  var renderedDeliveryIds = <?= json_encode(array_map(function($r){ return (int)$r['id']; }, $pendingDeliveries)) ?>;
  var cardTimers      = {}; // requestId -> interval id
  var orderCardTimers = {}; // orderId -> interval id
  var deliveryCardTimers = {}; // deliveryId -> interval id

  // ── SOUND ──────────────────────────────────────────────────
  function playAlert() {
    try {
      var ctx = new (window.AudioContext || window.webkitAudioContext)();
      [0, 0.3, 0.6].forEach(function(t) {
        var o = ctx.createOscillator(), g = ctx.createGain();
        o.connect(g); g.connect(ctx.destination);
        o.frequency.value = 880; o.type = 'sine';
        g.gain.setValueAtTime(0.5, ctx.currentTime + t);
        g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + t + 0.25);
        o.start(ctx.currentTime + t);
        o.stop(ctx.currentTime + t + 0.3);
      });
    } catch(e) {}
    if (navigator.vibrate) navigator.vibrate([300, 100, 300, 100, 300]);
  }

  // ── BANNER ─────────────────────────────────────────────────
  function showBanner(preview, title, targetSectionId, emoji) {
    title = title || 'New Ride Request!';
    targetSectionId = targetSectionId || 'requestsSection';
    emoji = emoji || '🛺';
    document.querySelectorAll('.tl-driver-banner').forEach(function(b) { b.remove(); });

    var el = document.createElement('div');
    el.className = 'tl-driver-banner';
    el.innerHTML =
      '<div style="display:flex;align-items:center;gap:12px;padding:16px 18px;">' +
      '<div style="font-size:28px;flex-shrink:0;">' + emoji + '</div>' +
      '<div style="flex:1;">' +
        '<div style="font-size:15px;font-weight:800;">' + title + '</div>' +
        '<div style="font-size:12px;opacity:0.85;margin-top:3px;">' + preview + '</div>' +
      '</div>' +
      '<div style="font-size:13px;font-weight:700;background:rgba(255,255,255,0.2);padding:6px 12px;border-radius:20px;">View →</div>' +
      '</div>';
    Object.assign(el.style, {
      position: 'fixed', top: '0', left: '50%',
      transform: 'translateX(-50%) translateY(-100%)',
      width: '100%', maxWidth: '480px',
      background: 'linear-gradient(135deg, #1B5E20, #2E7D32)',
      color: '#fff', zIndex: '9999',
      boxShadow: '0 6px 24px rgba(0,0,0,0.35)',
      borderRadius: '0 0 18px 18px',
      fontFamily: "'Inter', sans-serif",
      transition: 'transform 0.35s cubic-bezier(.25,.1,.25,1)',
      cursor: 'pointer',
    });
    // Tap banner → dismiss and scroll straight to the request card (no reload)
    el.onclick = function() {
      el.remove();
      var sec = document.getElementById(targetSectionId);
      if (sec) sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    document.body.appendChild(el);

    requestAnimationFrame(function() {
      requestAnimationFrame(function() {
        el.style.transform = 'translateX(-50%) translateY(0)';
      });
    });
    setTimeout(function() {
      if (el.parentNode) {
        el.style.transform = 'translateX(-50%) translateY(-100%)';
        setTimeout(function() { el.remove(); }, 350);
      }
    }, 8000);
  }

  // ── STATUS DOT (green = connected) ────────────────────────
  function setDot(color) {
    var el = document.getElementById('tl-rt-dot');
    if (!el) {
      el = document.createElement('div');
      el.id = 'tl-rt-dot';
      Object.assign(el.style, {
        position: 'fixed', bottom: '72px', right: '14px',
        width: '10px', height: '10px', borderRadius: '50%',
        zIndex: '999', transition: 'background 0.3s',
        border: '2px solid rgba(255,255,255,0.9)',
        boxShadow: '0 1px 4px rgba(0,0,0,0.3)',
        pointerEvents: 'none',
      });
      document.body.appendChild(el);
    }
    el.style.background = color;
  }

  // ── HTML ESCAPE ────────────────────────────────────────────
  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
  }

  // ── BUILD ONE REQUEST CARD ────────────────────────────────
  function cardHtml(req) {
    var dist     = req.distance_km ? parseFloat(req.distance_km).toFixed(1) + ' km' : '–';
    var payIcon  = req.payment_method === 'cash' ? 'fa-money-bill' : 'fa-wallet';
    var payLabel = req.payment_method.charAt(0).toUpperCase() + req.payment_method.slice(1);
    return (
      '<div class="request-card" id="rcard_' + req.id + '">' +
        '<div class="rc-header">' +
          '<span class="rc-badge"><i class="fas fa-bell"></i> New Request</span>' +
          '<span class="rc-timer" id="timer_' + req.id + '">' + REQUEST_TIMEOUT + 's</span>' +
        '</div>' +
        '<div class="rc-route">' +
          '<div class="rc-stop"><div class="rc-dot green"></div><div><div class="rc-addr">' + esc(req.pickup_address.slice(0,45)) + '</div><div class="rc-label">Pick-up</div></div></div>' +
          '<div class="rc-line"></div>' +
          '<div class="rc-stop"><div class="rc-dot navy"></div><div><div class="rc-addr">' + esc(req.dropoff_address.slice(0,45)) + '</div><div class="rc-label">Drop-off</div></div></div>' +
        '</div>' +
        '<div class="rc-meta">' +
          '<span><i class="fas fa-road"></i> ' + dist + '</span>' +
          '<span><i class="fas ' + payIcon + '"></i> ' + payLabel + '</span>' +
        '</div>' +
        '<div class="rc-fare">₱ ' + parseFloat(req.fare_total).toFixed(2) + '</div>' +
        '<div class="rc-passenger">' +
          '<div class="rcp-avatar"><i class="fas fa-user"></i></div>' +
          '<div><div class="rcp-name">' + esc(req.passenger_name) + '</div><div class="rcp-phone">' + esc(req.passenger_phone) + '</div></div>' +
        '</div>' +
        '<div class="rc-actions">' +
          '<button class="btn-decline" onclick="declineRequest(' + req.id + ')"><i class="fas fa-times"></i> Decline</button>' +
          '<button class="btn-accept" onclick="acceptRequest(' + req.id + ')"><i class="fas fa-check"></i> Accept</button>' +
        '</div>' +
        '<div class="rc-autotimer">Auto-decline in <span id="auto_' + req.id + '">' + REQUEST_TIMEOUT + '</span>s</div>' +
      '</div>'
    );
  }

  function emptyStateHtml() {
    return driverIsOnline
      ? '<div class="no-requests"><div class="nr-pulse"><div class="nr-ring r1"></div><div class="nr-ring r2"></div><div class="nr-ring r3"></div><div class="nr-center"><i class="fas fa-motorcycle"></i></div></div><p>Searching for ride requests…</p><small>Stay online to receive bookings</small></div>'
      : '<div class="no-requests offline-state"><i class="fas fa-power-off" style="font-size:48px;color:#DADCE0;"></i><p>You are offline</p><small>Toggle online to receive ride requests</small></div>';
  }

  function startCardTimer(id) {
    if (cardTimers[id]) return; // already running
    var s = REQUEST_TIMEOUT;
    cardTimers[id] = setInterval(function() {
      s--;
      var timer = document.getElementById('timer_' + id);
      var auto  = document.getElementById('auto_' + id);
      if (timer) timer.textContent = s + 's';
      if (auto)  auto.textContent  = s;
      if (s <= 0) {
        clearInterval(cardTimers[id]);
        delete cardTimers[id];
        declineRequest(id);
      }
    }, 1000);
  }

  function stopCardTimer(id) {
    if (cardTimers[id]) {
      clearInterval(cardTimers[id]);
      delete cardTimers[id];
    }
  }

  // ── SYNC #requestsSection WITHOUT WIPING UNCHANGED CARDS ──
  function syncRequests(requests) {
    var container = document.getElementById('requestsSection');
    if (!container) return;

    var currentIds = requests.map(function(r) { return r.id; });

    // Remove cards that are no longer pending (taken by another driver, expired, etc.)
    renderedIds.filter(function(id) { return currentIds.indexOf(id) === -1; }).forEach(function(id) {
      stopCardTimer(id);
      var el = document.getElementById('rcard_' + id);
      if (el) el.remove();
    });

    if (requests.length === 0) {
      renderedIds = [];
      container.innerHTML = emptyStateHtml();
      return;
    }

    // If we're transitioning from empty/offline state, clear it first
    if (renderedIds.length === 0) {
      container.innerHTML =
        '<div class="section-hdr"><span class="section-title">Ride Requests</span><span class="request-count" id="reqCount">' + requests.length + ' new</span></div>';
    }

    // Add cards that are new
    requests.forEach(function(req) {
      if (renderedIds.indexOf(req.id) === -1) {
        container.insertAdjacentHTML('beforeend', cardHtml(req));
        startCardTimer(req.id);
      }
    });

    var countEl = document.getElementById('reqCount');
    if (countEl) countEl.textContent = requests.length + ' new';

    renderedIds = currentIds;
  }

  // ── BUILD ONE MERCHANT ORDER CARD ─────────────────────────
  function orderCardHtml(ord) {
    var payIcon  = ord.payment_method === 'cash' ? 'fa-money-bill' : 'fa-wallet';
    var payLabel = ord.payment_method.charAt(0).toUpperCase() + ord.payment_method.slice(1);
    return (
      '<div class="request-card" id="ordcard_' + ord.id + '">' +
        '<div class="rc-header">' +
          '<span class="rc-badge" style="background:#3949AB;"><i class="fas fa-shopping-basket"></i> Shop Pickup</span>' +
          '<span class="rc-timer" id="otimer_' + ord.id + '">' + REQUEST_TIMEOUT + 's</span>' +
        '</div>' +
        '<div class="rc-route">' +
          '<div class="rc-stop"><div class="rc-dot green"></div><div><div class="rc-addr">' + esc((ord.pickup_address || ord.store_name).slice(0,45)) + '</div><div class="rc-label">' + esc(ord.store_name) + '</div></div></div>' +
          '<div class="rc-line"></div>' +
          '<div class="rc-stop"><div class="rc-dot navy"></div><div><div class="rc-addr">' + esc(ord.delivery_address.slice(0,45)) + '</div><div class="rc-label">Deliver to</div></div></div>' +
        '</div>' +
        '<div class="rc-meta">' +
          '<span><i class="fas fa-store"></i> ' + esc(ord.store_name) + '</span>' +
          '<span><i class="fas ' + payIcon + '"></i> ' + payLabel + '</span>' +
        '</div>' +
        '<div class="rc-fare">₱ ' + parseFloat(ord.delivery_fee).toFixed(2) + ' <small style="font-size:11px;color:#9AA0A6;font-weight:600;">delivery fee</small></div>' +
        '<div class="rc-passenger">' +
          '<div class="rcp-avatar"><i class="fas fa-user"></i></div>' +
          '<div><div class="rcp-name">' + esc(ord.customer_name) + '</div><div class="rcp-phone">' + esc(ord.customer_phone) + '</div></div>' +
        '</div>' +
        '<div class="rc-actions">' +
          '<button class="btn-decline" onclick="declineOrderPickup(' + ord.id + ')"><i class="fas fa-times"></i> Decline</button>' +
          '<button class="btn-accept" onclick="acceptOrderPickup(' + ord.id + ')"><i class="fas fa-check"></i> Accept</button>' +
        '</div>' +
        '<div class="rc-autotimer">Auto-decline in <span id="oauto_' + ord.id + '">' + REQUEST_TIMEOUT + '</span>s</div>' +
      '</div>'
    );
  }

  function startOrderCardTimer(id) {
    if (orderCardTimers[id]) return;
    var s = REQUEST_TIMEOUT;
    orderCardTimers[id] = setInterval(function() {
      s--;
      var timer = document.getElementById('otimer_' + id);
      var auto  = document.getElementById('oauto_' + id);
      if (timer) timer.textContent = s + 's';
      if (auto)  auto.textContent  = s;
      if (s <= 0) {
        clearInterval(orderCardTimers[id]);
        delete orderCardTimers[id];
        declineOrderPickup(id);
      }
    }, 1000);
  }

  function stopOrderCardTimer(id) {
    if (orderCardTimers[id]) {
      clearInterval(orderCardTimers[id]);
      delete orderCardTimers[id];
    }
  }

  // ── SYNC #orderRequestsSection WITHOUT WIPING UNCHANGED CARDS ──
  function syncOrderRequests(orders) {
    var container = document.getElementById('orderRequestsSection');
    if (!container) return;

    var currentIds = orders.map(function(o) { return o.id; });

    renderedOrderIds.filter(function(id) { return currentIds.indexOf(id) === -1; }).forEach(function(id) {
      stopOrderCardTimer(id);
      var el = document.getElementById('ordcard_' + id);
      if (el) el.remove();
    });

    if (orders.length === 0) {
      renderedOrderIds = [];
      container.innerHTML = '';
      return;
    }

    if (renderedOrderIds.length === 0) {
      container.innerHTML =
        '<div class="section-hdr"><span class="section-title">Shop Order Pickups</span><span class="request-count" id="ordCount">' + orders.length + ' new</span></div>';
    }

    orders.forEach(function(ord) {
      if (renderedOrderIds.indexOf(ord.id) === -1) {
        container.insertAdjacentHTML('beforeend', orderCardHtml(ord));
        startOrderCardTimer(ord.id);
      }
    });

    var countEl = document.getElementById('ordCount');
    if (countEl) countEl.textContent = orders.length + ' new';

    renderedOrderIds = currentIds;
  }

  // ── BUILD ONE PACKAGE DELIVERY CARD ───────────────────────
  function deliveryCardHtml(del) {
    var dist    = del.distance_km ? parseFloat(del.distance_km).toFixed(1) + ' km' : '–';
    var payIcon = del.payment_method === 'cash' ? 'fa-money-bill' : 'fa-wallet';
    var payLabel= del.payment_method.charAt(0).toUpperCase() + del.payment_method.slice(1);
    var pkgLabel= del.package_type.charAt(0).toUpperCase() + del.package_type.slice(1);
    return (
      '<div class="request-card" id="delcard_' + del.id + '">' +
        '<div class="rc-header">' +
          '<span class="rc-badge" style="background:#8E24AA;"><i class="fas fa-box"></i> Package Delivery</span>' +
          '<span class="rc-timer" id="dtimer_' + del.id + '">' + REQUEST_TIMEOUT + 's</span>' +
        '</div>' +
        '<div class="rc-route">' +
          '<div class="rc-stop"><div class="rc-dot green"></div><div><div class="rc-addr">' + esc(del.pickup_address.slice(0,45)) + '</div><div class="rc-label">Pick-up</div></div></div>' +
          '<div class="rc-line"></div>' +
          '<div class="rc-stop"><div class="rc-dot navy"></div><div><div class="rc-addr">' + esc(del.dropoff_address.slice(0,45)) + '</div><div class="rc-label">Drop-off</div></div></div>' +
        '</div>' +
        '<div class="rc-meta">' +
          '<span><i class="fas fa-box-open"></i> ' + pkgLabel + '</span>' +
          '<span><i class="fas fa-road"></i> ' + dist + '</span>' +
          '<span><i class="fas ' + payIcon + '"></i> ' + payLabel + '</span>' +
        '</div>' +
        '<div class="rc-fare">₱ ' + parseFloat(del.fee_total).toFixed(2) + '</div>' +
        '<div class="rc-passenger">' +
          '<div class="rcp-avatar"><i class="fas fa-user"></i></div>' +
          '<div><div class="rcp-name">' + esc(del.sender_name) + '</div><div class="rcp-phone">' + esc(del.sender_phone) + '</div></div>' +
        '</div>' +
        '<div class="rc-actions">' +
          '<button class="btn-decline" onclick="declineDelivery(' + del.id + ')"><i class="fas fa-times"></i> Decline</button>' +
          '<button class="btn-accept" onclick="acceptDelivery(' + del.id + ')"><i class="fas fa-check"></i> Accept</button>' +
        '</div>' +
        '<div class="rc-autotimer">Auto-decline in <span id="dauto_' + del.id + '">' + REQUEST_TIMEOUT + '</span>s</div>' +
      '</div>'
    );
  }

  function startDeliveryCardTimer(id) {
    if (deliveryCardTimers[id]) return;
    var s = REQUEST_TIMEOUT;
    deliveryCardTimers[id] = setInterval(function() {
      s--;
      var timer = document.getElementById('dtimer_' + id);
      var auto  = document.getElementById('dauto_' + id);
      if (timer) timer.textContent = s + 's';
      if (auto)  auto.textContent  = s;
      if (s <= 0) {
        clearInterval(deliveryCardTimers[id]);
        delete deliveryCardTimers[id];
        declineDelivery(id);
      }
    }, 1000);
  }

  function stopDeliveryCardTimer(id) {
    if (deliveryCardTimers[id]) {
      clearInterval(deliveryCardTimers[id]);
      delete deliveryCardTimers[id];
    }
  }

  // ── SYNC #deliveryRequestsSection WITHOUT WIPING UNCHANGED CARDS ──
  function syncDeliveryRequests(deliveries) {
    var container = document.getElementById('deliveryRequestsSection');
    if (!container) return;

    var currentIds = deliveries.map(function(d) { return d.id; });

    renderedDeliveryIds.filter(function(id) { return currentIds.indexOf(id) === -1; }).forEach(function(id) {
      stopDeliveryCardTimer(id);
      var el = document.getElementById('delcard_' + id);
      if (el) el.remove();
    });

    if (deliveries.length === 0) {
      renderedDeliveryIds = [];
      container.innerHTML = '';
      return;
    }

    if (renderedDeliveryIds.length === 0) {
      container.innerHTML =
        '<div class="section-hdr"><span class="section-title">Package Deliveries</span><span class="request-count" id="delCount">' + deliveries.length + ' new</span></div>';
    }

    deliveries.forEach(function(del) {
      if (renderedDeliveryIds.indexOf(del.id) === -1) {
        container.insertAdjacentHTML('beforeend', deliveryCardHtml(del));
        startDeliveryCardTimer(del.id);
      }
    });

    var countEl = document.getElementById('delCount');
    if (countEl) countEl.textContent = deliveries.length + ' new';

    renderedDeliveryIds = currentIds;
  }

  // ── MAIN POLL ──────────────────────────────────────────────
  function poll() {
    fetch(APP_URL + '/api/check_requests.php?last_id=' + LAST_ID + '&last_order_id=' + LAST_ORDER_ID + '&last_delivery_id=' + LAST_DELIVERY_ID + '&t=' + Date.now(), {
      credentials: 'same-origin',
      cache: 'no-store',
    })
    .then(function(r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    })
    .then(function(res) {
      setDot('#00C853'); // green = connected

      if (!res.success) {
        if (res.message === 'session_expired') {
          window.location.href = APP_URL + '/login.php';
        }
        return;
      }

      // jsonResponse() nests the payload under "data" — read from there, not from res directly
      var d = res.data || {};

      if (typeof updateModeCard === 'function' && d.work) updateModeCard(d.work);

      // If a job got accepted (e.g. on another device/tab), stop polling and jump to it
      if (d.active_trip) {
        window.location.href = APP_URL + '/on_trip.php?id=' + d.active_trip.id + '&type=' + d.active_trip.type;
        return;
      }

      var isNew         = d.new_count > 0 && d.latest_id > LAST_ID;
      var isNewOrder     = d.order_new_count > 0 && d.latest_order_id > LAST_ORDER_ID;
      var isNewDelivery = d.delivery_new_count > 0 && d.latest_delivery_id > LAST_DELIVERY_ID;

      syncRequests(d.requests || []);
      syncOrderRequests(d.order_requests || []);
      syncDeliveryRequests(d.delivery_requests || []);

      if (isNew) {
        playAlert();
        showBanner(d.pickup_preview || 'New passenger request!', 'New Ride Request!', 'requestsSection', '🛺');

        if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
          var n = new Notification('New TriLink Booking! 🛺', {
            body: d.pickup_preview || 'Tap to view the booking',
            tag: 'trilink-booking', renotify: true,
          });
          n.onclick = function() { window.focus(); n.close(); };
          setTimeout(function() { n.close(); }, 8000);
        }
      }

      // Order alert fires on its own cycle so it doesn't get swallowed by a simultaneous ride alert
      if (isNewOrder) {
        var fireOrderAlert = function() {
          playAlert();
          showBanner(d.order_pickup_preview || 'New shop order for pickup!', 'New Shop Order Pickup!', 'orderRequestsSection', '🛍️');

          if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
            var no = new Notification('New TriLink Shop Order! 🛍️', {
              body: d.order_pickup_preview || 'Tap to view the pickup',
              tag: 'trilink-order', renotify: true,
            });
            no.onclick = function() { window.focus(); no.close(); };
            setTimeout(function() { no.close(); }, 8000);
          }
        };
        // Stagger slightly so both banners are noticeable if they arrive together
        if (isNew) setTimeout(fireOrderAlert, 1200); else fireOrderAlert();
      }

      // Delivery alert also fires on its own staggered cycle
      if (isNewDelivery) {
        var fireDeliveryAlert = function() {
          playAlert();
          showBanner(d.delivery_pickup_preview || 'New package delivery request!', 'New Package Delivery!', 'deliveryRequestsSection', '📦');

          if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
            var nd = new Notification('New TriLink Delivery! 📦', {
              body: d.delivery_pickup_preview || 'Tap to view the delivery',
              tag: 'trilink-delivery', renotify: true,
            });
            nd.onclick = function() { window.focus(); nd.close(); };
            setTimeout(function() { nd.close(); }, 8000);
          }
        };
        var delay = (isNew ? 1200 : 0) + (isNewOrder ? 1200 : 0);
        if (delay > 0) setTimeout(fireDeliveryAlert, delay); else fireDeliveryAlert();
      }

      LAST_ID       = d.latest_id != null ? d.latest_id : LAST_ID;
      LAST_ORDER_ID = d.latest_order_id != null ? d.latest_order_id : LAST_ORDER_ID;
      LAST_DELIVERY_ID = d.latest_delivery_id != null ? d.latest_delivery_id : LAST_DELIVERY_ID;
    })
    .catch(function() {
      setDot('#C62828'); // red = error
    });
  }

  // ── INIT ───────────────────────────────────────────────────
  if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
    Notification.requestPermission();
  }
  renderedIds.forEach(function(id) { startCardTimer(id); }); // resume timers for server-rendered cards
  renderedOrderIds.forEach(function(id) { startOrderCardTimer(id); }); // resume timers for server-rendered order cards
  renderedDeliveryIds.forEach(function(id) { startDeliveryCardTimer(id); }); // resume timers for server-rendered delivery cards
  setDot('#FFB300'); // yellow = connecting
  poll(); // immediate first poll
  setInterval(poll, 3000); // every 3 seconds

})();

</script>
</body>
</html>
