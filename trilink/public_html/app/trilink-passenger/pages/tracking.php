<?php
require_once __DIR__ . '/../includes/core.php';
Auth::requireLogin();
$user = Auth::user();
if (!$user) Auth::logout();

$code = clean($_GET['code'] ?? '');
$type = $_GET['type'] ?? 'ride';

if ($type === 'delivery') {
    $trip = DB::row(
        "SELECT d.*, dr.full_name AS driver_name, dr.phone AS driver_phone,
                dr.vehicle_type, dr.plate_number, dr.rating AS driver_rating,
                dr.current_lat, dr.current_lng, dr.avatar AS driver_avatar
         FROM tl_deliveries d
         LEFT JOIN tl_drivers dr ON d.driver_id=dr.id
         WHERE d.delivery_code=? AND d.user_id=?",
        [$code, $user['id']]
    );
} elseif ($type === 'order') {
    $trip = DB::row(
        "SELECT o.*, o.order_code AS booking_code, m.store_name, m.address AS pickup_address,
                o.delivery_address AS dropoff_address, NULL AS pickup_lat, NULL AS pickup_lng,
                o.delivery_lat AS dropoff_lat, o.delivery_lng AS dropoff_lng,
                dr.full_name AS driver_name, dr.phone AS driver_phone,
                dr.vehicle_type, dr.plate_number, dr.rating AS driver_rating,
                dr.current_lat, dr.current_lng, dr.avatar AS driver_avatar
         FROM tl_merchant_orders o
         JOIN tl_merchants m ON o.merchant_id = m.id
         LEFT JOIN tl_drivers dr ON o.driver_id=dr.id
         WHERE o.order_code=? AND o.user_id=?",
        [$code, $user['id']]
    );
} else {
    $trip = DB::row(
        "SELECT b.*, dr.full_name AS driver_name, dr.phone AS driver_phone,
                dr.vehicle_type, dr.plate_number, dr.rating AS driver_rating,
                dr.current_lat, dr.current_lng, dr.avatar AS driver_avatar
         FROM tl_bookings b
         LEFT JOIN tl_drivers dr ON b.driver_id=dr.id
         WHERE b.booking_code=? AND b.user_id=?",
        [$code, $user['id']]
    );
}

if (!$trip) {
    header('Location: ' . APP_URL . '/pages/bookings.php'); exit;
}

$status  = $trip['status'];
$isRide  = ($type === 'ride');
$isOrder = ($type === 'order');
$isDone  = in_array($status, ['completed','delivered','cancelled']);

$entityLabel = $isRide ? 'Trip' : ($isOrder ? 'Order' : 'Delivery');
$backLink    = $isRide ? 'bookings.php' : ($isOrder ? 'shop_orders.php' : 'orders.php');

$statusConfig = $isRide ? [
    'searching' => ['label'=>'Finding your driver…',    'sub'=>'Please wait while we match you with a driver',    'color'=>'#1565C0', 'icon'=>'fa-search',         'anim'=>true],
    'accepted'  => ['label'=>'Driver is on the way!',   'sub'=>'Your driver is heading to your pick-up location', 'color'=>'#E65100', 'icon'=>'fa-motorcycle',     'anim'=>true],
    'arrived'   => ['label'=>'Driver has arrived!',     'sub'=>'Please come to your pick-up location now',        'color'=>'#7B1FA2', 'icon'=>'fa-map-marker-alt', 'anim'=>false],
    'on_trip'   => ['label'=>"You're on your way!",     'sub'=>'Heading to your destination',                    'color'=>'#2E7D32', 'icon'=>'fa-road',           'anim'=>true],
    'completed' => ['label'=>'Trip Completed!',          'sub'=>'You have arrived at your destination. Safe trip!','color'=>'#2E7D32', 'icon'=>'fa-check-circle',   'anim'=>false],
    'cancelled' => ['label'=>'Trip Cancelled',           'sub'=>'This trip has been cancelled',                   'color'=>'#C62828', 'icon'=>'fa-times-circle',   'anim'=>false],
] : ($isOrder ? [
    'new'        => ['label'=>'Order Placed!',              'sub'=>'Hinihintay ng merchant ang iyong order',                'color'=>'#1565C0', 'icon'=>'fa-receipt',        'anim'=>true],
    'preparing'  => ['label'=>'Inihahanda ang Order Mo!',    'sub'=>'Ginagawa na ng merchant ang iyong order',               'color'=>'#E65100', 'icon'=>'fa-fire',           'anim'=>true],
    'ready'      => ['label'=>'Ready na, Naghahanap ng Driver…', 'sub'=>'Ready na ang order, hinihintay ang magpi-pickup', 'color'=>'#3949AB', 'icon'=>'fa-search',         'anim'=>true],
    'assigned'   => ['label'=>'Papunta na ang Driver sa Tindahan!', 'sub'=>'May driver nang tatanggap ng order mo',        'color'=>'#E65100', 'icon'=>'fa-motorcycle',     'anim'=>true],
    'picked_up'  => ['label'=>'Nakuha na! Papunta na sa Iyo!', 'sub'=>'Papunta na ang driver sa delivery address mo',       'color'=>'#2E7D32', 'icon'=>'fa-shipping-fast',  'anim'=>true],
    'completed'  => ['label'=>'Na-deliver na!',              'sub'=>'Successfully na-deliver ang order mo',                  'color'=>'#2E7D32', 'icon'=>'fa-check-circle',   'anim'=>false],
    'cancelled'  => ['label'=>'Order Cancelled',             'sub'=>'Kinansela ang order na ito',                            'color'=>'#C62828', 'icon'=>'fa-times-circle',   'anim'=>false],
] : [
    'searching'  => ['label'=>'Finding a driver…',          'sub'=>'Please wait while we match a driver for your package', 'color'=>'#1565C0', 'icon'=>'fa-search',         'anim'=>true],
    'accepted'   => ['label'=>'Driver is on the way!',       'sub'=>'Driver is heading to pick up your package',            'color'=>'#E65100', 'icon'=>'fa-motorcycle',     'anim'=>true],
    'picked_up'  => ['label'=>'Package Picked Up!',          'sub'=>'Your package is with the driver',                     'color'=>'#7B1FA2', 'icon'=>'fa-box',            'anim'=>true],
    'on_the_way' => ['label'=>'On the Way to Recipient!',    'sub'=>'Package is being delivered now',                      'color'=>'#2E7D32', 'icon'=>'fa-shipping-fast',  'anim'=>true],
    'delivered'  => ['label'=>'Package Delivered!',          'sub'=>'Your package has been delivered successfully',         'color'=>'#2E7D32', 'icon'=>'fa-check-circle',   'anim'=>false],
    'cancelled'  => ['label'=>'Delivery Cancelled',          'sub'=>'This delivery has been cancelled',                    'color'=>'#C62828', 'icon'=>'fa-times-circle',   'anim'=>false],
]);

// Terminal ride: the dispatcher assigns the driver from the pila
if ($isRide && ($trip['booking_mode'] ?? 'street') === 'terminal' && isset($statusConfig['searching'])) {
    $terminalName = $trip['terminal_id'] ? DB::val("SELECT name FROM tl_terminals WHERE id=?", [$trip['terminal_id']]) : '';
    $statusConfig['searching']['label'] = 'Naghihintay sa dispatcher…';
    $statusConfig['searching']['sub']   = 'Ia-assign ka ng dispatcher' . ($terminalName ? ' ng ' . $terminalName : '') . ' sa tricycle na nasa unahan ng pila';
    $statusConfig['searching']['icon']  = 'fa-list-ol';
}

$sc = $statusConfig[$status] ?? ['label'=>ucfirst($status),'sub'=>'','color'=>'#1A2B5E','icon'=>'fa-circle','anim'=>false];
$allSteps = $isRide ? ['searching','accepted','arrived','on_trip','completed']
                    : ($isOrder ? ['new','preparing','ready','assigned','picked_up','completed']
                    : ['searching','accepted','picked_up','on_the_way','delivered']);
$stepLabels = $isRide ? ['Searching','Accepted','Arrived','On Trip','Done']
                      : ($isOrder ? ['Placed','Preparing','Ready','Assigned','Pickup','Done']
                      : ['Searching','Accepted','Picked Up','On the Way','Delivered']);
$currentIdx = array_search($status, $allSteps);
if ($currentIdx === false) $currentIdx = 0;

$pickupLat  = (float)($trip['pickup_lat']  ?: DEFAULT_LAT);
$pickupLng  = (float)($trip['pickup_lng']  ?: DEFAULT_LNG);
$dropoffLat = (float)($trip['dropoff_lat'] ?: DEFAULT_LAT);
$dropoffLng = (float)($trip['dropoff_lng'] ?: DEFAULT_LNG);
$driverLat  = (float)($trip['current_lat'] ?: $pickupLat);
$driverLng  = (float)($trip['current_lng'] ?: $pickupLng);
$amount     = (float)($isRide ? $trip['fare_total'] : ($isOrder ? $trip['total'] : $trip['fee_total']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Track <?= $entityLabel ?> – TriLink</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/home.css?v=<?= filemtime(__DIR__.'/../assets/css/home.css') ?>">
  <style>
    body { background:#F5F6FA; font-family:'Inter',sans-serif; }
    .app  { max-width:480px;margin:0 auto;height:100vh;display:flex;flex-direction:column;overflow:hidden;font-family:'Inter',sans-serif; }
    #trackMap { width:100%;height:100%;position:absolute;top:0;left:0; }
    .map-wrap { flex:1;position:relative;min-height:280px;height:280px; }
    #trackMap { width:100%;height:100%;min-height:280px;position:absolute;top:0;left:0;right:0;bottom:0; }

    .track-sheet { background:#fff;border-radius:22px 22px 0 0;padding:0 0 20px;box-shadow:0 -4px 30px rgba(0,0,0,0.18);max-height:62vh;overflow-y:auto;position:relative;z-index:10; }
    .sheet-handle { width:40px;height:4px;background:#E0E0E0;border-radius:4px;margin:12px auto 4px; }

    .status-head { padding:12px 18px 14px;display:flex;align-items:center;gap:12px;border-bottom:1px solid #F1F3F4; }
    .sh-icon-wrap { position:relative;width:50px;height:50px;flex-shrink:0; }
    .sh-icon { width:50px;height:50px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff; }
    .sh-pulse { position:absolute;inset:-6px;border-radius:50%;border:2px solid;opacity:0;animation:pulse 1.5s ease-out infinite; }
    @keyframes pulse { 0%{transform:scale(0.8);opacity:0.7} 100%{transform:scale(1.4);opacity:0} }
    .sh-title { font-family:'Plus Jakarta Sans',sans-serif;font-size:18px;font-weight:800; }
    .sh-sub   { font-size:12px;color:#9AA0A6;margin-top:3px;line-height:1.4; }

    .progress-bar-wrap { padding:14px 18px 0; }
    .pb-steps { display:flex;align-items:center; }
    .pb-step  { flex:1;position:relative;display:flex;flex-direction:column;align-items:center; }
    .pb-dot   { width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;z-index:2; }
    .pb-dot.done   { background:#2E7D32;color:#fff; }
    .pb-dot.active { border:2px solid; }
    .pb-dot.pend   { background:#F1F3F4;color:#DADCE0;border:2px solid #EAECF0; }
    .pb-line  { position:absolute;top:10px;left:calc(50% + 11px);right:calc(-50% + 11px);height:2px;background:#EAECF0;z-index:1; }
    .pb-line.done { background:#2E7D32; }
    .pb-lbl   { font-size:9px;color:#9AA0A6;margin-top:4px;text-align:center; }
    .pb-lbl.active { color:#202124;font-weight:700; }

    .driver-card { display:flex;align-items:center;gap:12px;padding:14px 18px;border-top:1px solid #F5F6FA; }
    .dc-av { width:46px;height:46px;border-radius:50%;background:#F1F3F4;display:flex;align-items:center;justify-content:center;font-size:20px;color:#9AA0A6;flex-shrink:0;overflow:hidden; }
    .dc-av img { width:100%;height:100%;object-fit:cover; }
    .dc-name  { font-size:15px;font-weight:700;color:#202124; }
    .dc-plate { display:inline-flex;align-items:center;gap:5px;background:#F5F6FA;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;color:#3C4043;margin-top:3px; }
    .dc-rating { font-size:12px;color:#F9A825;margin-top:2px; }
    .dc-call  { width:38px;height:38px;border-radius:50%;background:#E8F5E9;color:#2E7D32;display:flex;align-items:center;justify-content:center;text-decoration:none;margin-left:auto; }

    .route-mini { padding:0 18px 12px;border-top:1px solid #F5F6FA; }
    .rm-row { display:flex;align-items:flex-start;gap:10px;padding:9px 0;font-size:13px; }
    .rm-row + .rm-row { border-top:1px dashed #EAECF0; }
    .rm-dot { width:10px;height:10px;border-radius:50%;flex-shrink:0;margin-top:4px; }
    .rm-dot.green { background:#2E7D32; }
    .rm-dot.navy  { background:#1A2B5E; }
    .rm-lbl  { font-size:10px;color:#9AA0A6;margin-bottom:2px; }
    .rm-addr { font-weight:600;color:#202124;line-height:1.3; }

    .fare-strip { display:flex;justify-content:space-between;align-items:center;padding:12px 18px;border-top:1px solid #F5F6FA; }
    .fare-strip .lbl { font-size:12px;color:#9AA0A6; }
    .fare-strip .amt { font-family:'Plus Jakarta Sans',sans-serif;font-size:22px;font-weight:800;color:#2E7D32; }

    .rate-stars { display:flex;gap:8px;justify-content:center;padding:16px 0 8px; }
    .star-btn { font-size:36px;color:#DADCE0;background:none;border:none;cursor:pointer;padding:4px;transition:color 0.15s; }
    .star-btn.lit { color:#F9A825; }

    .modal-overlay { display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:500;align-items:flex-end;justify-content:center; }
    .modal-overlay.open { display:flex; }
    .modal-sheet { background:#fff;border-radius:22px 22px 0 0;width:100%;max-width:480px;padding:0 0 32px;animation:sheetUp 0.28s cubic-bezier(.25,.1,.25,1); }
    @keyframes sheetUp { from{transform:translateY(100%)} to{transform:translateY(0)} }

    .track-topbar { position:absolute;top:0;left:0;right:0;display:flex;align-items:center;justify-content:space-between;padding:12px 16px;z-index:5;background:linear-gradient(to bottom,rgba(15,26,58,0.85) 0%,transparent 100%); }
    .tb-back { width:38px;height:38px;border-radius:50%;background:rgba(255,255,255,0.15);border:none;color:#fff;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;text-decoration:none; }
    .tb-code { color:#fff;font-size:12px;font-weight:700;background:rgba(255,255,255,0.15);padding:5px 12px;border-radius:20px; }
  </style>
</head>
<body>
<div class="app">
  <div class="map-wrap">
    <div id="trackMap" style="width:100%;height:100%;min-height:250px;"></div>
    <div class="track-topbar">
      <a href="<?= APP_URL ?>/pages/<?= $backLink ?>" class="tb-back"><i class="fas fa-arrow-left"></i></a>
      <div class="tb-code"><?= htmlspecialchars($code) ?></div>
      <div style="width:38px;"></div>
    </div>
  </div>

  <div class="track-sheet">
    <div class="sheet-handle"></div>

    <?php if ($status === 'cancelled'): ?>
    <div style="text-align:center;padding:28px 20px;">
      <i class="fas fa-times-circle" style="font-size:44px;color:#C62828;"></i>
      <div style="font-family:'Plus Jakarta Sans',sans-serif;font-size:18px;font-weight:800;color:#C62828;margin-top:10px;"><?= $entityLabel ?> Cancelled</div>
      <div style="font-size:13px;color:#9AA0A6;margin-top:6px;">This <?= strtolower($entityLabel) ?> has been cancelled.</div>
      <a href="<?= APP_URL ?>/pages/<?= $backLink ?>" style="display:inline-block;margin-top:16px;padding:12px 28px;background:#1A2B5E;color:#fff;border-radius:12px;text-decoration:none;font-weight:700;">Go Back</a>
    </div>

    <?php else: ?>

    <!-- STATUS -->
    <div class="status-head">
      <div class="sh-icon-wrap">
        <div class="sh-icon" style="background:<?= $sc['color'] ?>;"><i class="fas <?= $sc['icon'] ?>"></i></div>
        <?php if ($sc['anim']): ?><div class="sh-pulse" style="color:<?= $sc['color'] ?>;border-color:<?= $sc['color'] ?>;"></div><?php endif; ?>
      </div>
      <div>
        <div class="sh-title" style="color:<?= $sc['color'] ?>;"><?= $sc['label'] ?></div>
        <div class="sh-sub"><?= $sc['sub'] ?></div>
      </div>
    </div>

    <!-- PROGRESS -->
    <div class="progress-bar-wrap">
      <div class="pb-steps">
        <?php
        // If trip is fully completed/delivered, mark ALL steps as done
        $allDone = in_array($status, ['completed', 'delivered']);
        foreach ($allSteps as $i => $st):
          $done   = $allDone || $i < $currentIdx;
          $active = !$allDone && ($i === $currentIdx);
        ?>
        <div class="pb-step">
          <?php if ($i < count($allSteps)-1): ?>
          <div class="pb-line <?= $done?'done':'' ?>"></div>
          <?php endif; ?>
          <div class="pb-dot <?= $done?'done':($active?'active':'pend') ?>"
               style="<?= $active?'border-color:'.$sc['color'].';color:'.$sc['color']:'' ?>">
            <?php if ($done): ?><i class="fas fa-check"></i>
            <?php else: ?><i class="fas fa-circle" style="font-size:6px;"></i><?php endif; ?>
          </div>
          <div class="pb-lbl <?= ($active||($allDone&&$i===count($allSteps)-1))?'active':'' ?>"><?= $stepLabels[$i] ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- DRIVER -->
    <?php if (!empty($trip['driver_name'])): ?>
    <div class="driver-card">
      <div class="dc-av">
        <?php if (!empty($trip['driver_avatar'])): ?>
        <img src="<?= APP_URL ?>/uploads/avatars/<?= htmlspecialchars($trip['driver_avatar']) ?>" alt="">
        <?php else: ?><i class="fas fa-user"></i><?php endif; ?>
      </div>
      <div>
        <div class="dc-name"><?= htmlspecialchars($trip['driver_name']) ?></div>
        <?php if (!empty($trip['plate_number'])): ?>
        <div><span class="dc-plate"><i class="fas fa-motorcycle"></i> <?= htmlspecialchars($trip['plate_number']) ?></span></div>
        <?php endif; ?>
        <div class="dc-rating"><i class="fas fa-star"></i> <?= number_format((float)($trip['driver_rating']??5),1) ?></div>
      </div>
      <a href="tel:<?= htmlspecialchars($trip['driver_phone']??'') ?>" class="dc-call"><i class="fas fa-phone"></i></a>
    </div>
    <?php endif; ?>

    <!-- ROUTE -->
    <div class="route-mini">
      <div class="rm-row">
        <div class="rm-dot green"></div>
        <div><div class="rm-lbl"><?= $isOrder ? 'Store' : 'Pick-up' ?></div><div class="rm-addr"><?= htmlspecialchars($trip['pickup_address']) ?></div></div>
      </div>
      <div class="rm-row">
        <div class="rm-dot navy"></div>
        <div><div class="rm-lbl"><?= $isRide?'Drop-off':'Deliver to' ?></div><div class="rm-addr"><?= htmlspecialchars($trip['dropoff_address']) ?></div></div>
      </div>
    </div>

    <!-- FARE -->
    <div class="fare-strip">
      <div class="lbl"><?= $isRide?'Fare':($isOrder?'Total':'Delivery Fee') ?> · <i class="fas fa-<?= ($trip['payment_method']??'cash')==='cash'?'money-bill-wave':'wallet' ?>"></i> <?= ucfirst($trip['payment_method']??'cash') ?></div>
      <div class="amt">₱ <?= number_format($amount, 2) ?></div>
    </div>

    <!-- RATE -->
    <?php if (!empty($trip['rating'])): ?>
    <div style="text-align:center;padding:12px;font-size:13px;color:#9AA0A6;">
      <i class="fas fa-star" style="color:#F9A825;"></i> You rated: <?= str_repeat('⭐', (int)$trip['rating']) ?>
    </div>
    <?php else: ?>
    <div id="rateDriverArea" style="padding:0 14px;display:<?= in_array($status,['completed','delivered']) ? 'block' : 'none' ?>;">
      <button onclick="openRateModal()" style="width:100%;padding:14px;border-radius:14px;background:linear-gradient(135deg,#F9A825,#FFB300);color:#0F1A3A;font-weight:800;font-size:15px;border:none;cursor:pointer;font-family:'Inter',sans-serif;display:flex;align-items:center;justify-content:center;gap:10px;box-shadow:0 4px 14px rgba(249,168,37,0.35);">
        <i class="fas fa-star"></i> Rate Your Driver
      </button>
    </div>
    <?php endif; ?>

    <?php endif; // end not cancelled ?>
  </div>
</div>

<!-- RATE MODAL -->
<div class="modal-overlay" id="rateModal" onclick="if(event.target===this)closeRateModal()">
  <div class="modal-sheet">
    <div style="width:40px;height:4px;background:#E0E0E0;border-radius:4px;margin:12px auto 0;"></div>
    <div style="text-align:center;padding:16px 20px 4px;">
      <div style="font-family:'Plus Jakarta Sans',sans-serif;font-size:20px;font-weight:800;color:#1A2B5E;">How was your <?= $isRide?'ride':($isOrder?'order':'delivery') ?>?</div>
      <div style="font-size:13px;color:#9AA0A6;margin-top:4px;">Rate your experience with <?= htmlspecialchars($trip['driver_name']??'your driver') ?></div>
    </div>
    <div class="rate-stars" id="starRow">
      <?php for($i=1;$i<=5;$i++): ?>
      <button class="star-btn" data-star="<?= $i ?>" onclick="setRating(<?= $i ?>)"><i class="fas fa-star"></i></button>
      <?php endfor; ?>
    </div>
    <div style="padding:0 20px 12px;">
      <textarea id="ratingNote" placeholder="Leave a comment (optional)…" style="width:100%;padding:12px;border-radius:12px;border:1.5px solid #EAECF0;font-size:14px;font-family:'Inter',sans-serif;resize:none;height:72px;outline:none;"></textarea>
    </div>
    <div style="padding:0 20px;">
      <button onclick="submitRating()" style="width:100%;padding:14px;border-radius:12px;background:linear-gradient(135deg,#1A2B5E,#2D4A8A);color:#fff;font-weight:800;font-size:15px;border:none;cursor:pointer;font-family:'Inter',sans-serif;box-shadow:0 4px 14px rgba(26,43,94,0.3);">
        <i class="fas fa-paper-plane"></i> Submit Rating
      </button>
    </div>
  </div>
</div>

<script>
// Config for tracking_realtime.js
window.TL_APP_URL    = '<?= APP_URL ?>';
window.TL_TRIP_CODE  = '<?= htmlspecialchars($code) ?>';
window.TL_TRIP_TYPE  = '<?= $type ?>';
window.TL_CUR_STATUS = '<?= $status ?>';

const APP_URL   = '<?= APP_URL ?>';
const CSRF_NAME = '<?= CSRF_TOKEN_NAME ?>';
const CSRF_TOK  = '<?= Session::csrfToken() ?>';
const TRIP_CODE = '<?= htmlspecialchars($code) ?>';
const TRIP_TYPE = '<?= $type ?>';
const IS_DONE   = <?= $isDone?'true':'false' ?>;
const PLAT=<?= $pickupLat ?>, PLNG=<?= $pickupLng ?>, DLAT=<?= $dropoffLat ?>, DLNG=<?= $dropoffLng ?>;
let DRVLAT=<?= $driverLat ?>, DRVLNG=<?= $driverLng ?>;

// Google Maps init via map.js
let gMap, mkPickup, mkDropoff, mkDrv;

function initGoogleTrackMap() {
  gMap = new google.maps.Map(document.getElementById('trackMap'), {
    center: { lat: DRVLAT || PLAT, lng: DRVLNG || PLNG },
    zoom: 15,
    mapTypeControl: false, streetViewControl: false, fullscreenControl: false,
    zoomControl: true,
    zoomControlOptions: { position: google.maps.ControlPosition.RIGHT_CENTER },
    styles: [
      { featureType:'poi', elementType:'labels', stylers:[{visibility:'off'}] },
      { featureType:'transit', elementType:'labels', stylers:[{visibility:'off'}] },
      { featureType:'water', stylers:[{color:'#C9D8E8'}] },
      { featureType:'road.highway', elementType:'geometry', stylers:[{color:'#F5F5F5'}] },
      { featureType:'landscape', stylers:[{color:'#F2F3F4'}] },
    ],
  });

  mkPickup = new google.maps.Marker({ position:{lat:PLAT,lng:PLNG}, map:gMap, title:'Pick-up',
    icon:{ url:'data:image/svg+xml;charset=UTF-8,'+encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="28" height="36" viewBox="0 0 36 44"><path d="M18 0C8.06 0 0 8.06 0 18c0 13.5 18 26 18 26S36 31.5 36 18C36 8.06 27.94 0 18 0z" fill="#2E7D32"/><circle cx="18" cy="18" r="7" fill="white"/></svg>'),
      scaledSize: new google.maps.Size(28,36), anchor: new google.maps.Point(14,36) }
  });
  mkDropoff = new google.maps.Marker({ position:{lat:DLAT,lng:DLNG}, map:gMap, title:'Drop-off',
    icon:{ url:'data:image/svg+xml;charset=UTF-8,'+encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="28" height="36" viewBox="0 0 36 44"><path d="M18 0C8.06 0 0 8.06 0 18c0 13.5 18 26 18 26S36 31.5 36 18C36 8.06 27.94 0 18 0z" fill="#1A2B5E"/><circle cx="18" cy="18" r="7" fill="white"/></svg>'),
      scaledSize: new google.maps.Size(28,36), anchor: new google.maps.Point(14,36) }
  });
  // Only show driver marker if we have real coordinates
  if (DRVLAT && DRVLNG && DRVLAT !== PLAT) {
    mkDrv = new google.maps.Marker({ position:{lat:DRVLAT,lng:DRVLNG}, map:gMap, title:'Driver',
      icon:{ url:'data:image/svg+xml;charset=UTF-8,'+encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 44 44"><circle cx="22" cy="22" r="22" fill="#E65100"/><text x="22" y="30" text-anchor="middle" font-size="22">🛺</text></svg>'),
        scaledSize: new google.maps.Size(40,40), anchor: new google.maps.Point(20,20) }
    });
  }

  // Directions route (only if both points valid)
  if (PLAT && PLNG && DLAT && DLNG) {
    new google.maps.DirectionsService().route({
      origin:{lat:PLAT,lng:PLNG}, destination:{lat:DLAT,lng:DLNG},
      travelMode: google.maps.TravelMode.DRIVING, region:'PH'
    }, (res,status) => {
      if (status==='OK') new google.maps.DirectionsRenderer({
        map:gMap, suppressMarkers:true,
        polylineOptions:{strokeColor:'#1A2B5E',strokeWeight:4,strokeOpacity:0.8}
      }).setDirections(res);
    });
  }

  // Fit bounds - only add valid markers
  const b = new google.maps.LatLngBounds();
  [mkPickup, mkDropoff, mkDrv].forEach(m => { if (m) b.extend(m.getPosition()); });
  if (!b.isEmpty()) {
    gMap.fitBounds(b, { top:80, bottom:20, left:40, right:40 });
  }
  // Give map reference to tracking realtime
  if (window.TL_TRACKING) window.TL_TRACKING.setMap(gMap);
}

// Load Google Maps then init
window._gmapsReady = function() {
  initGoogleTrackMap();
};
(function() {
  const s=document.createElement('script');
  s.src='https://maps.googleapis.com/maps/api/js?key=AIzaSyAtEVzsy6v4l69zp5Glyql9xNby1FhTiZk&callback=_gmapsReady&language=en&region=PH';
  s.async=true; document.head.appendChild(s);
})();



// ── RATE MODAL ────────────────────────────────────────────────
var _selectedRating = 0;

function openRateModal() {
  var modal = document.getElementById('rateModal');
  modal.style.zIndex = '9998'; // ensure above everything
  modal.classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeRateModal() {
  document.getElementById('rateModal').classList.remove('open');
  document.body.style.overflow = '';
}
function setRating(r) {
  _selectedRating = r;
  document.querySelectorAll('.star-btn').forEach(function(b, i) {
    b.classList.toggle('lit', i < r);
  });
}
function submitRating() {
  if (!_selectedRating) { alert('Please select a star rating.'); return; }
  var note = document.getElementById('ratingNote') ? document.getElementById('ratingNote').value : '';
  var btn  = document.querySelector('#rateModal .submit-btn') || document.querySelector('#rateModal button[onclick="submitRating()"]');
  if (btn) { btn.disabled = true; btn.textContent = 'Submitting…'; }

  fetch(APP_URL + '/api/rate_trip.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: CSRF_NAME + '=' + CSRF_TOK
      + '&code=' + encodeURIComponent(TRIP_CODE)
      + '&type=' + encodeURIComponent(TRIP_TYPE)
      + '&rating=' + _selectedRating
      + '&note=' + encodeURIComponent(note)
  })
  .then(function(r) { return r.json(); })
  .then(function(res) {
    closeRateModal();
    if (res.success) {
      // Hide rate button, show rated message
      var area = document.getElementById('rateDriverArea');
      if (area) {
        area.innerHTML = '<div style="text-align:center;padding:12px;font-size:13px;color:#9AA0A6;">'
          + '<i class="fas fa-star" style="color:#F9A825;"></i> '
          + 'Thank you for rating! ' + '⭐'.repeat(_selectedRating)
          + '</div>';
      }
    } else {
      alert(res.message || 'Rating failed. Please try again.');
      if (btn) { btn.disabled = false; btn.textContent = 'Submit Rating'; }
    }
  })
  .catch(function() {
    alert('Network error. Please try again.');
    if (btn) { btn.disabled = false; }
  });
}
</script>
<script src="<?= APP_URL ?>/assets/js/tracking_realtime.js?v=<?= filemtime(__DIR__.'/../assets/js/tracking_realtime.js') ?>"></script>
</body>
</html>
