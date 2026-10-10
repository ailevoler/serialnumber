<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/uride.php';
$user = require_auth();
header('Cache-Control: no-store');
$vehicles = uride_vehicles();
$settings = uride_settings();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('ride_write', 'user:' . $user['id'], 60, 3600);
    if (($_POST['action'] ?? '') === 'request') {
        rate_limit_enforce('ride_create', 'user:' . $user['id'], 20, 3600);
        try {
            $requestKey = $_POST['request_key'] ?? '';
            if (!is_string($requestKey) || !preg_match('/^[a-f0-9]{64}$/D', $requestKey) || !hash_equals($_SESSION['uride_request_key'] ?? '', $requestKey)) {
                throw new InvalidArgumentException('This request was already submitted. Refresh the page to start another.');
            }
            $code = uride_book($pdo, (int) $user['id'], $_POST, $requestKey);
            unset($_SESSION['uride_request_key']);
            redirect('uride-ride.php?code=' . rawurlencode($code));
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            error_log('URide request database error: ' . $exception->getCode() . ' ' . $exception->getMessage());
            $error = 'Could not save the request. Please try again.';
        }
    }
}
if (empty($_SESSION['uride_request_key'])) {
    $_SESSION['uride_request_key'] = bin2hex(random_bytes(32));
}
uride_expire_stale($pdo);
$stmt = $pdo->prepare('SELECT * FROM uride_requests WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 10');
$stmt->execute([$user['id']]);
$requests = $stmt->fetchAll();
$active = null;
foreach ($requests as $r) if (in_array($r['status'], uride_active_statuses(), true)) { $active = $r; break; }
$googleMaps = google_maps_settings();
$statusLabels = uride_ride_statuses();
$pageTitle = 'URide';
require __DIR__ . '/includes/header.php';
?>
<section class="screen uride-screen">
    <svg class="uride-icon-defs" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
        <symbol id="uride-icon-e_trike" viewBox="0 0 48 48"><path d="M7 30h32l2 4H7v-4Zm5 0V18c0-4 3-7 7-7h11c4 0 7 3 7 7v12M12 21h25M24 11v19M10 34v2m29-2v2"/><circle cx="14" cy="36" r="4"/><circle cx="35" cy="36" r="4"/><path d="m27 23-3 4h3l-2 4"/></symbol>
        <symbol id="uride-icon-motorcycle" viewBox="0 0 48 48"><circle cx="10" cy="35" r="6"/><circle cx="38" cy="35" r="6"/><path d="m10 35 9-12h8l8 12H23l-6-9m3-9h8m-1 0 4 6h6m-9-6 4-4h5m-8 22 5-13"/></symbol>
        <symbol id="uride-icon-car" viewBox="0 0 48 48"><path d="M7 30v-5l4-10c.5-1.5 2-2 4-2h18c2 0 3.5.5 4 2l4 10v5H7Zm0-5h34M13 21h22M9 30v4m30-4v4"/><circle cx="14" cy="32" r="3"/><circle cx="34" cy="32" r="3"/></symbol>
    </svg>
    <header class="page-head"><a href="dashboard.php" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>URide</h1><span></span></header>
    <?php if ($active): ?>
        <a class="uride-active" href="uride-ride.php?code=<?= e($active['code']) ?>"><span class="uride-live-dot"></span><span><small>Your ride · <?= e($active['code']) ?></small><strong><?= e($statusLabels[$active['status']]) ?></strong></span><span data-icon="arrow-right"></span></a>
    <?php endif; ?>
    <div class="uride-intro"><div><small>ULTIMATE APP</small><h2>Book a ride</h2><p>Choose your pick-up, destination, and vehicle.</p></div><span data-icon="map-pin"></span></div>
    <?php if (!$settings['enabled']): ?><p class="alert" role="alert">URide bookings are paused right now. Please try again later.</p><?php endif; ?>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" class="uride-form" id="uride-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="request"><input type="hidden" name="request_key" value="<?= e($_SESSION['uride_request_key']) ?>">
        <input type="hidden" name="pickup_lat" id="uride-pickup-lat"><input type="hidden" name="pickup_lng" id="uride-pickup-lng"><input type="hidden" name="dropoff_lat" id="uride-dropoff-lat"><input type="hidden" name="dropoff_lng" id="uride-dropoff-lng">
        <div class="uride-locations"><div class="uride-location"><span class="uride-dot pickup"></span><label>Pick-up location<input name="pickup_address" id="uride-pickup" placeholder="Search a place or address" maxlength="255" autocomplete="off" aria-autocomplete="list" aria-controls="uride-pickup-results" aria-expanded="false" required><span class="uride-suggestions" id="uride-pickup-results" role="listbox" hidden></span></label><button type="button" id="uride-locate" title="Use my precise location" aria-label="Use my precise location"><span data-icon="map-pin"></span></button></div><div class="uride-location"><span class="uride-dot dropoff"></span><label>Drop-off location<input name="dropoff_address" id="uride-dropoff" placeholder="Search destination" maxlength="255" autocomplete="off" aria-autocomplete="list" aria-controls="uride-dropoff-results" aria-expanded="false" required><span class="uride-suggestions" id="uride-dropoff-results" role="listbox" hidden></span></label></div></div>
        <div class="uride-map-tools"><strong>Set on map</strong><div><button type="button" class="active" data-map-mode="pickup">Pick-up</button><button type="button" data-map-mode="dropoff">Drop-off</button></div><button type="button" class="uride-maptype" data-map-type aria-pressed="false" hidden>Satellite</button></div>
        <div id="uride-map" role="application" aria-label="Google Map with pick-up and drop-off pins" data-google-key="<?= e($googleMaps['browser_key']) ?>" data-map-id="<?= e($googleMaps['map_id']) ?>" data-lat="<?= e((string) $googleMaps['lat']) ?>" data-lng="<?= e((string) $googleMaps['lng']) ?>" data-zoom="<?= (int) $googleMaps['zoom'] ?>"><p>Loading Google Map...</p></div><p class="uride-help" id="uride-map-status" role="status">Search a place above, use your location, or tap the map. Drag a pin to fine-tune it.</p>
        <h3>Choose vehicle</h3><div class="uride-vehicles"><?php $first = true; foreach ($vehicles as $key => $vehicle): $rule = uride_fare_rule($key); ?><label class="uride-vehicle<?= $rule['enabled'] ? '' : ' off' ?>"><input type="radio" name="vehicle_type" value="<?= e($key) ?>" <?= $first && $rule['enabled'] ? 'checked' : '' ?><?= $rule['enabled'] ? '' : ' disabled' ?>><svg class="uride-vehicle-icon" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#uride-icon-<?= e($key) ?>"></use></svg><strong><?= e($vehicle['label']) ?></strong><small><?= e($vehicle['seats']) ?></small><em class="uride-vehicle-fare" data-vehicle-fare="<?= e($key) ?>"><?= $rule['enabled'] ? 'from ₱' . peso($rule['minimum']) : 'Unavailable' ?></em></label><?php if ($rule['enabled']) $first = false; endforeach; ?></div>
        <h3>Payment</h3><div class="uride-payment">
            <?php if ($settings['credits_enabled']): ?><label><input type="radio" name="payment_method" value="credits" checked><span data-icon="wallet"></span><span><strong>Ultimate App Credits</strong><small><?= format_credits((float) $user['credits']) ?> available · fare is held when you book and returned if you cancel or no driver accepts</small></span></label><?php endif; ?>
            <?php if ($settings['cash_enabled']): ?><label><input type="radio" name="payment_method" value="cash"<?= $settings['credits_enabled'] ? '' : ' checked' ?>><span data-icon="banknote"></span><span><strong>Cash</strong><small>Pay the driver the fare shown in the app</small></span></label><?php endif; ?>
        </div>
        <label class="uride-note"><span>Note for driver <small>(optional)</small></span><input name="passenger_note" maxlength="200" placeholder="e.g. near the blue gate, 2 bags"></label>
        <div class="uride-summary"><div><span>Fare</span><strong id="uride-fare">Set both pins</strong></div><div class="uride-summary-sub"><span id="uride-distance">Set both pins</span><span id="uride-road"></span></div><p>Upfront fare from the Ultimate App fare matrix — it will not change during the trip. Nearby drivers get your request and the first to accept is assigned.</p></div>
        <button type="submit" class="uride-submit"<?= $settings['enabled'] && !$active ? '' : ' disabled' ?>><?= $active ? 'You have an active ride' : 'Request ride' ?> <span data-icon="arrow-right"></span></button>
    </form>
    <section class="uride-history"><h3>Your rides</h3><?php if (!$requests): ?><p class="uride-help">No rides yet.</p><?php endif; ?><?php foreach ($requests as $ride): ?><a class="uride-history-item" href="uride-ride.php?code=<?= e($ride['code']) ?>"><div class="uride-history-top"><strong><?= e($ride['code']) ?></strong><span class="uride-st <?= e($ride['status']) ?>"><?= e($statusLabels[$ride['status']] ?? ucfirst($ride['status'])) ?></span></div><p><?= e($ride['pickup_address']) ?> <span aria-hidden="true">→</span> <?= e($ride['dropoff_address']) ?></p><small><?= e($vehicles[$ride['vehicle_type']]['label'] ?? $ride['vehicle_type']) ?> · <?= e(date('M j, g:i A', strtotime($ride['created_at']))) ?> · <?= e(ucfirst($ride['payment_method'])) ?><?= $ride['fare_centavos'] !== null ? ' · ₱' . peso((int) $ride['fare_centavos']) : '' ?></small></a><?php endforeach; ?></section>
</section>
<script defer src="assets/js/uride.js?v=10"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
