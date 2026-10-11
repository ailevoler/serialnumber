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
            $code = ($_POST['trip_type'] ?? 'ride') === 'rental'
                ? uride_book_rental($pdo, (int) $user['id'], $_POST, $requestKey)
                : uride_book($pdo, (int) $user['id'], $_POST, $requestKey);
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
$upcoming = null;
foreach ($requests as $r) {
    if (!in_array($r['status'], uride_active_statuses(), true)) continue;
    if (uride_is_upcoming($r)) { $upcoming ??= $r; continue; }
    $active ??= $r;
}
$rental = uride_rental_settings();
$packages = uride_rental_packages();
$rentalPrices = [];
foreach (array_keys($vehicles) as $key) { $rr = uride_rental_rule($key); $rentalPrices[$key] = ['enabled' => $rr['enabled'] && $rental['enabled'], 'hour' => $rr['hour'], 'min' => $rr['min_hours'], 'day' => $rr['day']]; }
$tripType = ($_POST['trip_type'] ?? $_GET['mode'] ?? '') === 'rental' || ($_GET['mode'] ?? '') === 'rent' ? 'rental' : 'ride';
if (!$rental['enabled']) $tripType = 'ride';
// Self-drive: customer drives; needs a verified ID (if Admin requires it) and a driver's license on file.
require_once __DIR__ . '/includes/kyc.php';
$selfOn = $rental['enabled'] && setting('uride.self_drive_enabled', '1') === '1' && uride_licenses_ready($pdo);
$selfPrices = [];
foreach (array_keys($vehicles) as $key) { $sr = uride_self_rule($key); $selfPrices[$key] = ['enabled' => $sr['enabled'] && $selfOn, 'hour' => $sr['hour'], 'min' => $sr['min_hours'], 'day' => $sr['day'], 'deposit' => $sr['deposit'], 'class' => uride_license_class($key)]; }
$license = $selfOn ? uride_user_license($pdo, (int) $user['id']) : null;
$needKyc = setting('uride.self_drive_kyc', '1') === '1';
$kycOk = !$needKyc || kyc_is_verified($pdo, 'user', (int) $user['id']);
$licOk = $license && $license['status'] !== 'rejected' && $license['expiry'] >= date('Y-m-d');
$rentalCounts = $rental['enabled'] ? uride_rental_counts($pdo) : [];
$rentalMode = $selfOn && (($_POST['rental_mode'] ?? '') === 'self' || ($_GET['drive'] ?? '') === 'self') ? 'self' : 'driver';
$googleMaps = google_maps_settings();
$statusLabels = uride_ride_statuses();
$pageTitle = 'URide';
require __DIR__ . '/includes/header.php';
?>
<section class="screen uride-screen">
    <svg class="uride-icon-defs" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
        <symbol id="uride-icon-e_trike" viewBox="0 0 48 48"><path d="M7 30h32l2 4H7v-4Zm5 0V18c0-4 3-7 7-7h11c4 0 7 3 7 7v12M12 21h25M24 11v19M10 34v2m29-2v2"/><circle cx="14" cy="36" r="4"/><circle cx="35" cy="36" r="4"/><path d="m27 23-3 4h3l-2 4"/></symbol>
        <symbol id="uride-icon-motorcycle" viewBox="0 0 48 48"><circle cx="10" cy="35" r="6"/><circle cx="38" cy="35" r="6"/><path d="m10 35 9-12h8l8 12H23l-6-9m3-9h8m-1 0 4 6h6m-9-6 4-4h5m-8 22 5-13"/></symbol>
        <symbol id="uride-icon-e_bike" viewBox="0 0 48 48"><circle cx="11" cy="33" r="7"/><circle cx="37" cy="33" r="7"/><path d="M11 33l7-13h12l7 13M18 20l-3-5h-4m19 5-4-7h5M24 33l6-13"/><path d="m21 26-2 3h3l-2 3"/></symbol>
        <symbol id="uride-icon-van" viewBox="0 0 48 48"><path d="M4 32V16c0-2 1-3 3-3h24c2 0 3 .5 4 2l6 7 3 2v8H4Zm0-10h37M12 13v9m10-9v9m9-8v8"/><circle cx="12" cy="33" r="3.5"/><circle cx="35" cy="33" r="3.5"/></symbol>
        <symbol id="uride-icon-car" viewBox="0 0 48 48"><path d="M7 30v-5l4-10c.5-1.5 2-2 4-2h18c2 0 3.5.5 4 2l4 10v5H7Zm0-5h34M13 21h22M9 30v4m30-4v4"/><circle cx="14" cy="32" r="3"/><circle cx="34" cy="32" r="3"/></symbol>
    </svg>
    <header class="page-head"><a href="dashboard.php" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>URide</h1><span></span></header>
    <?php if ($active): ?>
        <a class="uride-active" href="uride-ride.php?code=<?= e($active['code']) ?>"><span class="uride-live-dot"></span><span><small>Your ride · <?= e($active['code']) ?></small><strong><?= e($statusLabels[$active['status']]) ?></strong></span><span data-icon="arrow-right"></span></a>
    <?php endif; ?>
    <?php if ($upcoming): ?>
        <a class="uride-active upcoming" href="uride-ride.php?code=<?= e($upcoming['code']) ?>"><span class="uride-live-dot"></span><span><small>Upcoming rental · <?= e($upcoming['code']) ?></small><strong><?= e(date('D, M j · g:i A', strtotime((string) $upcoming['scheduled_at']))) ?> · <?= e(uride_rental_label($upcoming)) ?></strong></span><span data-icon="arrow-right"></span></a>
    <?php endif; ?>
    <div class="uride-intro"><div><small>ULTIMATE APP</small><h2 data-mode-title><?= $tripType === 'rental' ? 'Rent a ride' : 'Book a ride' ?></h2><p data-mode-sub><?= $tripType === 'rental' ? 'Hire a vehicle with its driver, or drive it yourself, by the hour or for the whole day.' : 'Choose your pick-up, destination, and vehicle.' ?></p></div><span data-icon="map-pin"></span></div>
    <?php if (!$settings['enabled']): ?><p class="alert" role="alert">URide bookings are paused right now. Please try again later.</p><?php endif; ?>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" class="uride-form" id="uride-form" data-mode="<?= $tripType ?>" data-rmode="<?= $rentalMode ?>" data-rental="<?= e(json_encode($rentalPrices)) ?>" data-self="<?= e(json_encode($selfPrices)) ?>" data-units="<?= e(json_encode($rentalCounts)) ?>" data-day-hours="<?= (int) $rental['day_hours'] ?>"
          data-lic-moto="<?= $licOk && (int) $license['can_motorcycle'] ? 1 : 0 ?>" data-lic-car="<?= $licOk && (int) $license['can_car'] ? 1 : 0 ?>" data-kyc-ok="<?= $kycOk ? 1 : 0 ?>">
        <?php if ($rental['enabled']): ?>
        <div class="uride-modes" role="radiogroup" aria-label="Ride type">
            <label><input type="radio" name="trip_type" value="ride"<?= $tripType === 'ride' ? ' checked' : '' ?>><span><b>Book a Ride</b><small>Point to point</small></span></label>
            <label><input type="radio" name="trip_type" value="rental"<?= $tripType === 'rental' ? ' checked' : '' ?>><span><b>Rent a Ride</b><small>With driver, by the hour</small></span></label>
        </div>
        <?php else: ?><input type="hidden" name="trip_type" value="ride"><?php endif; ?>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="request"><input type="hidden" name="request_key" value="<?= e($_SESSION['uride_request_key']) ?>">
        <input type="hidden" name="pickup_lat" id="uride-pickup-lat"><input type="hidden" name="pickup_lng" id="uride-pickup-lng"><input type="hidden" name="dropoff_lat" id="uride-dropoff-lat"><input type="hidden" name="dropoff_lng" id="uride-dropoff-lng">
        <div class="uride-locations"><div class="uride-location"><span class="uride-dot pickup"></span><label>Pick-up location<input name="pickup_address" id="uride-pickup" placeholder="Search a place or address" maxlength="255" autocomplete="off" aria-autocomplete="list" aria-controls="uride-pickup-results" aria-expanded="false" required><span class="uride-suggestions" id="uride-pickup-results" role="listbox" hidden></span></label><button type="button" id="uride-locate" title="Use my precise location" aria-label="Use my precise location"><span data-icon="map-pin"></span></button></div><div class="uride-location ride-only"><span class="uride-dot dropoff"></span><label>Drop-off location<input name="dropoff_address" id="uride-dropoff" placeholder="Search destination" maxlength="255" autocomplete="off" aria-autocomplete="list" aria-controls="uride-dropoff-results" aria-expanded="false" required><span class="uride-suggestions" id="uride-dropoff-results" role="listbox" hidden></span></label></div></div>
        <?php if ($rental['enabled']): ?>
        <div class="uride-rental rent-only">
            <?php if ($selfOn): ?>
            <h3>Who drives?</h3>
            <div class="uride-drive" role="radiogroup" aria-label="Who drives">
                <label><input type="radio" name="rental_mode" value="driver"<?= $rentalMode === 'driver' ? ' checked' : '' ?>><span><b>With driver</b><small>The driver takes you around</small></span></label>
                <label><input type="radio" name="rental_mode" value="self"<?= $rentalMode === 'self' ? ' checked' : '' ?>><span><b>Self-drive</b><small>You drive · license needed</small></span></label>
            </div>
            <div class="uride-self self-only">
                <ul class="uride-reqs">
                    <?php if ($needKyc): ?><li class="<?= $kycOk ? 'ok' : 'todo' ?>"><b><?= $kycOk ? '✓' : '!' ?></b><span>Verified ID<?php if (!$kycOk): ?> · <a href="kyc.php">Verify my identity</a><?php endif; ?></span></li><?php endif; ?>
                    <li class="<?= $licOk ? 'ok' : 'todo' ?>"><b><?= $licOk ? '✓' : '!' ?></b><span><?php if ($license && $licOk): ?>Driver's license <?= e(uride_license_label($license)) ?><?= $license['status'] === 'pending' ? ' · the owner checks it at hand-over' : '' ?> · <a href="uride-license.php">Update</a>
                    <?php elseif ($license && $license['status'] === 'rejected'): ?>Driver's license not accepted: <?= e($license['review_note'] ?? '') ?> · <a href="uride-license.php">Upload again</a>
                    <?php elseif ($license): ?>Driver's license expired · <a href="uride-license.php">Update it</a>
                    <?php else: ?>Driver's license · <a href="uride-license.php">Add my license</a><?php endif; ?></span></li>
                    <li class="info"><b>₱</b><span data-self-deposit>Refundable deposit</span></li>
                </ul>
                <label class="uride-terms"><input type="checkbox" name="self_terms" value="1"<?= ($_POST['self_terms'] ?? '') === '1' ? ' checked' : '' ?>><span>I will drive the vehicle myself with my valid license, wear a helmet on an e-trike or motorcycle, not drive after drinking, and return it to the pick-up point on time. Late returns cost the hourly rate for each started hour after <?= setting_int('uride.self_drive_grace_min', 15) ?> minutes, and damage can be taken from my deposit after Ultimate App reviews it.</span></label>
                <p class="uride-help">Self-drive is paid with Credits only. The owner brings the vehicle to your pick-up, checks your license and ID, and hands it over.</p>
            </div>
            <?php endif; ?>
            <h3>How long?</h3>
            <div class="uride-hours" role="radiogroup" aria-label="Rental duration"><?php $firstH = true; foreach ($packages as $k => $label): ?><label><input type="radio" name="rental_hours" value="<?= e((string) $k) ?>"<?= (($_POST['rental_hours'] ?? '') === (string) $k || (!isset($_POST['rental_hours']) && (string) $k === '3')) ? ' checked' : '' ?>><span><?= e($label) ?></span></label><?php endforeach; ?></div>
            <h3>Start</h3>
            <div class="uride-start">
                <label><input type="radio" name="start_mode" value="now"<?= ($_POST['start_mode'] ?? 'now') !== 'later' ? ' checked' : '' ?>><span>Now</span></label>
                <label><input type="radio" name="start_mode" value="later"<?= ($_POST['start_mode'] ?? '') === 'later' ? ' checked' : '' ?>><span>Schedule</span></label>
                <input type="datetime-local" name="scheduled_at" id="uride-scheduled" aria-label="Rental start date and time" min="<?= e(date('Y-m-d\TH:i', time() + 1800)) ?>" max="<?= e(date('Y-m-d\TH:i', time() + 86400 * max(0, $rental['schedule_days']))) ?>" value="<?= e(is_string($_POST['scheduled_at'] ?? null) ? $_POST['scheduled_at'] : '') ?>"<?= $rental['schedule_days'] > 0 ? '' : ' disabled' ?>>
            </div>
            <p class="uride-help driver-only">Your driver picks you up, waits and drives you around for the time you choose, then brings you back. Add your plan in the note below.</p>
            <p class="uride-help self-only">The rental time starts when the owner hands you the vehicle. Return it to the pick-up point by the end time.</p>
        </div>
        <?php endif; ?>
        <div class="uride-map-tools"><strong>Set on map</strong><div class="ride-only"><button type="button" class="active" data-map-mode="pickup">Pick-up</button><button type="button" data-map-mode="dropoff">Drop-off</button></div><button type="button" class="uride-maptype" data-map-type aria-pressed="false" hidden>Satellite</button></div>
        <div id="uride-map" role="application" aria-label="Google Map with pick-up and drop-off pins" data-google-key="<?= e($googleMaps['browser_key']) ?>" data-map-id="<?= e($googleMaps['map_id']) ?>" data-lat="<?= e((string) $googleMaps['lat']) ?>" data-lng="<?= e((string) $googleMaps['lng']) ?>" data-zoom="<?= (int) $googleMaps['zoom'] ?>"><p>Loading Google Map...</p></div><p class="uride-help" id="uride-map-status" role="status">Search a place above, use your location, or tap the map. Drag a pin to fine-tune it.</p>
        <h3>Choose vehicle</h3><div class="uride-vehicles"><?php $first = true; foreach ($vehicles as $key => $vehicle): $rule = uride_fare_rule($key); $anyOn = $rule['enabled'] || $rentalPrices[$key]['enabled'] || $selfPrices[$key]['enabled']; $onNow = $tripType === 'rental' ? ($rentalMode === 'self' ? $selfPrices[$key]['enabled'] : $rentalPrices[$key]['enabled']) : $rule['enabled']; ?><label class="uride-vehicle<?= $onNow ? '' : ' off' ?>" data-ride-ok="<?= $rule['enabled'] ? 1 : 0 ?>"><input type="radio" name="vehicle_type" value="<?= e($key) ?>" <?= $first && $onNow ? 'checked' : '' ?><?= $anyOn && $onNow ? '' : ' disabled' ?>><svg class="uride-vehicle-icon" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#uride-icon-<?= e($key) ?>"></use></svg><strong><?= e($vehicle['label']) ?></strong><small><?= e($vehicle['seats']) ?></small><em class="uride-vehicle-fare ride-only" data-vehicle-fare="<?= e($key) ?>"><?= $rule['enabled'] ? 'from ₱' . peso($rule['minimum']) : 'Unavailable' ?></em><em class="uride-vehicle-fare rent-only driver-only" data-vehicle-rent="<?= e($key) ?>"><?= $rentalPrices[$key]['enabled'] ? '₱' . rtrim(rtrim(peso($rentalPrices[$key]['hour']), '0'), '.') . '/hr' : 'No rentals' ?></em><em class="uride-vehicle-fare rent-only self-only" data-vehicle-self="<?= e($key) ?>"><?= $selfPrices[$key]['enabled'] ? '₱' . rtrim(rtrim(peso($selfPrices[$key]['hour']), '0'), '.') . '/hr' : 'No self-drive' ?></em><i class="uride-avail rent-only" data-avail="<?= e($key) ?>"></i></label><?php if ($onNow) $first = false; endforeach; ?></div>
        <h3>Payment</h3><div class="uride-payment">
            <?php if ($settings['credits_enabled']): ?><label><input type="radio" name="payment_method" value="credits" checked><span data-icon="wallet"></span><span><strong>Ultimate App Credits</strong><small><?= format_credits((float) $user['credits']) ?> available · fare is held when you book and returned if you cancel or no driver accepts</small></span></label><?php endif; ?>
            <?php if ($settings['cash_enabled']): ?><label><input type="radio" name="payment_method" value="cash"<?= $settings['credits_enabled'] ? '' : ' checked' ?>><span data-icon="banknote"></span><span><strong>Cash</strong><small>Pay the driver the fare shown in the app</small></span></label><?php endif; ?>
        </div>
        <label class="uride-note"><span data-note-label>Note for driver <small>(optional)</small></span><input name="passenger_note" maxlength="200" placeholder="e.g. near the blue gate, 2 bags" data-note-input></label>
        <div class="uride-summary ride-only"><div><span>Fare</span><strong id="uride-fare">Set both pins</strong></div><div class="uride-summary-sub"><span id="uride-distance">Set both pins</span><span id="uride-road"></span></div><p>Upfront fare from the Ultimate App fare matrix — it will not change during the trip. Nearby drivers get your request and the first to accept is assigned.</p></div>
        <div class="uride-summary rent-only"><div><span>Rental price</span><strong id="uride-rent-fare">—</strong></div><div class="uride-summary-sub"><span id="uride-rent-detail">Choose a vehicle and duration</span></div><div class="uride-summary-sub self-only"><span id="uride-rent-hold"></span></div><p>Upfront price with the driver included. Nearby drivers get your request<span data-rent-when></span> and the first to accept is assigned. Credits are held and returned if you cancel or no driver accepts.</p></div>
        <button type="submit" class="uride-submit"<?= $settings['enabled'] && !$active ? '' : ' disabled' ?>><span data-submit-label><?= $active ? 'You have an active ride' : ($tripType === 'rental' ? 'Rent this ride' : 'Request ride') ?></span> <span data-icon="arrow-right"></span></button>
    </form>
    <section class="uride-history"><h3>Your rides</h3><?php if (!$requests): ?><p class="uride-help">No rides yet.</p><?php endif; ?><?php foreach ($requests as $ride): ?><a class="uride-history-item" href="uride-ride.php?code=<?= e($ride['code']) ?>"><div class="uride-history-top"><strong><?= e($ride['code']) ?></strong><span class="uride-st <?= e($ride['status']) ?>"><?= e($statusLabels[$ride['status']] ?? ucfirst($ride['status'])) ?></span></div><p><?= e($ride['pickup_address']) ?> <span aria-hidden="true">→</span> <?= e($ride['dropoff_address']) ?><?= !empty($ride['scheduled_at']) ? ' · starts ' . e(date('M j, g:i A', strtotime((string) $ride['scheduled_at']))) : '' ?></p><small><?= e($vehicles[$ride['vehicle_type']]['label'] ?? $ride['vehicle_type']) ?> · <?= e(date('M j, g:i A', strtotime($ride['created_at']))) ?> · <?= e(ucfirst($ride['payment_method'])) ?><?= $ride['fare_centavos'] !== null ? ' · ₱' . peso((int) $ride['fare_centavos']) : '' ?></small></a><?php endforeach; ?></section>
</section>
<script defer src="assets/js/uride.js?v=14"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
