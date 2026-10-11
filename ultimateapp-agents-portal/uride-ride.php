<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/uride.php';
$user = require_auth();
header('Cache-Control: no-store');
$code = strtoupper((string) ($_GET['code'] ?? $_POST['code'] ?? ''));
if (!preg_match('/^UR-[A-F0-9]{12}$/D', $code)) redirect('uride.php');
$notice = '';
$error = '';
$load = static function () use ($pdo, $code, $user): ?array {
    $stmt = $pdo->prepare('SELECT * FROM uride_requests WHERE code = ? AND user_id = ?');
    $stmt->execute([$code, $user['id']]);
    return $stmt->fetch() ?: null;
};
$ride = $load();
if (!$ride) redirect('uride.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('ride_write', 'user:' . $user['id'], 60, 3600);
    try {
        if (($_POST['action'] ?? '') === 'cancel') {
            uride_cancel_by_passenger($pdo, (int) $user['id'], (int) $ride['id'], (string) ($_POST['reason'] ?? ''));
            $_SESSION['uride_notice'] = 'Ride cancelled.' . ((int) $ride['held_centavos'] > 0 ? ' ₱' . peso((int) $ride['held_centavos']) . ' Credits returned to your wallet.' : '');
        } elseif (($_POST['action'] ?? '') === 'rate') {
            uride_rate($pdo, (int) $user['id'], (int) $ride['id'], (int) ($_POST['rating'] ?? 0), (string) ($_POST['comment'] ?? ''));
            $_SESSION['uride_notice'] = 'Thanks for rating your driver!';
        }
        redirect('uride-ride.php?code=' . rawurlencode($code));
    } catch (InvalidArgumentException $ex) {
        $error = $ex->getMessage();
    }
}
if (!empty($_SESSION['uride_notice'])) { $notice = (string) $_SESSION['uride_notice']; unset($_SESSION['uride_notice']); }
uride_expire_stale($pdo);
$ride = $load();
$driver = null;
if ($ride['driver_id']) {
    $stmt = $pdo->prepare('SELECT id, full_name, mobile, vehicle_type, plate_no, vehicle_model, vehicle_color, rating_sum, rating_count, trips_completed, lat, lng, location_at, selfie_file FROM uride_drivers WHERE id = ?');
    $stmt->execute([$ride['driver_id']]);
    $driver = $stmt->fetch() ?: null;
}
$status = $ride['status'];
$labels = uride_ride_statuses();
$active = in_array($status, uride_active_statuses(), true);
$fare = (int) $ride['fare_centavos'];
$steps = ['requested' => 0, 'accepted' => 1, 'arrived' => 2, 'in_progress' => 3, 'completed' => 4];
$now = $steps[$status] ?? -1;
$showDriver = $driver && in_array($status, ['accepted', 'arrived', 'in_progress', 'completed'], true);
$isRental = ($ride['trip_type'] ?? 'ride') === 'rental';
$upcoming = uride_is_upcoming($ride);
$rentalEnds = $isRental && $ride['started_at'] ? strtotime((string) ($ride['ends_at'] ?? '') ?: (string) $ride['started_at']) + ($ride['ends_at'] ? 0 : 3600 * (int) $ride['rental_hours']) : null;
$isSelf = uride_is_self_drive($ride);
$phone = $driver && $active && preg_match('/^09\d{9}$/D', (string) $driver['mobile']) ? (string) $driver['mobile'] : '';
$pageTitle = 'Your ride';
require __DIR__ . '/includes/header.php';
?>
<section class="screen uride-screen uride-ride" data-ride-status data-code="<?= e($code) ?>" data-status="<?= e($status) ?>" data-trip="<?= $isRental ? 'rental' : 'ride' ?>"<?= $rentalEnds ? ' data-ends="' . e(date('g:i A', $rentalEnds)) . '"' : '' ?>>
    <header class="page-head"><a href="uride.php" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>Your ride</h1><span></span></header>
    <?php if ($notice): ?><p class="uride-notice" role="status"><?= e($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>

    <div class="uride-status-card <?= e($status) ?>">
        <small><?= e($code) ?> · <?= e(uride_vehicles()[$ride['vehicle_type']]['label'] ?? '') ?><?= $isRental ? ' · ' . e(uride_rental_label($ride)) : '' ?></small>
        <h2 data-rs-label><?= $upcoming ? 'Scheduled' : e($labels[$status] ?? $status) ?></h2>
        <p data-rs-sub><?php
            if ($upcoming) echo 'Starts ' . e(date('D, M j · g:i A', strtotime((string) $ride['scheduled_at']))) . '. Nearby drivers get your request ' . URIDE_RENTAL_LEAD_MIN . ' minutes before.';
            elseif ($status === 'requested') echo 'Sending your request to nearby drivers…';
            elseif ($isSelf && $status === 'accepted') echo 'The owner is bringing the vehicle to your pick-up. Have your driver\'s license and ID ready.';
            elseif ($isSelf && $status === 'arrived') echo 'The vehicle is at the pick-up. Show your driver\'s license and ID to the owner.';
            elseif ($isSelf && $status === 'in_progress') echo 'Enjoy the drive. Return the vehicle to the pick-up point by ' . ($rentalEnds ? e(date('g:i A', $rentalEnds)) : 'the end time') . '. Late returns use your deposit.';
            elseif ($isSelf && $status === 'completed') echo 'Vehicle returned. Thank you! ' . e(uride_deposit_status_label($ride));
            elseif ($isRental && $status === 'in_progress') echo 'Your rental is running' . ($rentalEnds ? ' until about ' . e(date('g:i A', $rentalEnds)) : '') . '. Your driver brings you back to the pick-up point.';
            elseif ($status === 'accepted') echo 'Your driver is heading to the pick-up point.';
            elseif ($status === 'arrived') echo 'Your driver is at the pick-up point.';
            elseif ($status === 'in_progress') echo 'Enjoy your ride. You are on the way to your drop-off.';
            elseif ($status === 'completed') echo 'You have arrived. Thank you for riding with URide!';
            elseif ($status === 'expired') echo 'No driver accepted in time.' . ($ride['payment_method'] !== 'cash' ? ' Your ' . uride_pay_label((string) $ride['payment_method']) . ' was returned.' : '');
            else echo 'This ride was cancelled' . ($ride['cancelled_by'] === 'driver' ? ' by the driver' : ($ride['cancelled_by'] === 'admin' ? ' by Ultimate App' : '')) . '.' . ($ride['payment_method'] !== 'cash' ? ' Your ' . uride_pay_label((string) $ride['payment_method']) . ' was returned.' : '');
        ?></p>
        <?php if ($status === 'requested' && !$upcoming): ?><p class="uride-searching"><span></span><span></span><span></span></p><p class="uride-help" data-rs-nearby></p><?php endif; ?>
    </div>

    <?php if ($now >= 0): ?>
    <ol class="uride-steps" aria-label="Ride progress"><?php foreach (['Requested', 'Driver assigned', 'Arrived', 'On trip', 'Done'] as $i => $l): ?><li class="<?= $now >= $i ? 'done' : '' ?>"><?= $l ?></li><?php endforeach; ?></ol>
    <?php endif; ?>

    <div class="uride-live-map" <?= google_maps_attrs() ?> data-live-map data-pickup="<?= e($ride['pickup_lat'] . ',' . $ride['pickup_lng']) ?>" data-dropoff="<?= e($ride['dropoff_lat'] . ',' . $ride['dropoff_lng']) ?>"<?= $showDriver && $active && $driver['lat'] !== null ? ' data-driver="' . e($driver['lat'] . ',' . $driver['lng']) . '"' : '' ?>>
        <p>Live map appears when Google Maps is enabled.</p>
    </div>
    <p class="uride-help" data-rs-eta></p>

    <?php if ($showDriver): $rating = uride_rating($driver); ?>
    <div class="uride-driver-card">
        <img src="uride-driver-photo.php?code=<?= e($code) ?>" alt="Your driver" width="56" height="56">
        <div><strong><?= e(uride_short_name($driver['full_name'])) ?></strong><small><?= e($driver['vehicle_color'] . ' ' . $driver['vehicle_model']) ?></small><span class="uride-plate"><?= e($driver['plate_no']) ?></span></div>
        <div class="uride-driver-meta"><?php if ($rating !== null): ?><b>★ <?= number_format($rating, 1) ?></b><?php endif; ?><small><?= (int) $driver['trips_completed'] ?> trip<?= (int) $driver['trips_completed'] === 1 ? '' : 's' ?></small><?php if ($phone): ?><a href="tel:<?= e($phone) ?>" class="uride-call" aria-label="Call driver"><span data-icon="phone"></span>Call</a><?php endif; ?></div>
    </div>
    <?php endif; ?>

    <div class="uride-trip-card">
        <p class="uride-pt"><span class="uride-dot pickup"></span><span><small>Pick-up</small><?= e($ride['pickup_address']) ?></span></p>
        <p class="uride-pt"><span class="uride-dot dropoff"></span><span><small><?= $isRental ? 'Rental' : 'Drop-off' ?></small><?= e($ride['dropoff_address']) ?><?= !empty($ride['scheduled_at']) ? ' · starts ' . e(date('M j, g:i A', strtotime((string) $ride['scheduled_at']))) : '' ?></span></p>
        <div class="uride-fare-row"><span><?= $isRental ? e(uride_rental_label($ride)) . ' · ' : ($ride['road_km'] !== null ? e(number_format((float) $ride['road_km'], 1)) . ' km · ' : '') ?><?= $ride['payment_method'] === 'cash' ? 'Pay cash to driver' : 'Paid with ' . e(uride_pay_label((string) $ride['payment_method'])) ?></span><strong><?= $fare ? '₱' . peso($fare) : '—' ?></strong></div>
        <?php if ($ride['payment_method'] !== 'cash' && $active): ?><p class="uride-help">₱<?= peso($fare) ?> is on hold and goes to the <?= $isSelf ? 'owner when the vehicle is returned' : 'driver when the trip is completed' ?>.</p><?php endif; ?>
        <?php if ($isSelf && (int) $ride['deposit_centavos'] > 0): ?><p class="uride-help uride-deposit"><b>Deposit:</b> <?= e(uride_deposit_status_label($ride)) ?><?= $ride['deposit_status'] === 'held' ? ' (returned when the vehicle comes back on time and undamaged)' : '' ?></p><?php endif; ?>
    </div>

    <?php if (in_array($status, ['requested', 'accepted', 'arrived'], true)): ?>
    <details class="uride-cancel"><summary>Cancel ride</summary>
        <form method="post" data-confirm-cancel><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="code" value="<?= e($code) ?>"><input type="hidden" name="action" value="cancel">
            <select name="reason" aria-label="Reason"><option value="">Reason (optional)</option><option>Driver is taking too long</option><option>Changed my plans</option><option>Booked by mistake</option><option>Found another ride</option></select>
            <button type="submit">Cancel this ride</button></form>
    </details>
    <?php endif; ?>

    <?php if ($status === 'completed' && $ride['rating'] === null && $driver): ?>
    <form method="post" class="uride-rate"><h3>Rate your driver</h3>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="code" value="<?= e($code) ?>"><input type="hidden" name="action" value="rate">
        <div class="uride-stars" role="radiogroup" aria-label="Rating"><?php for ($i = 5; $i >= 1; $i--): ?><input type="radio" id="star<?= $i ?>" name="rating" value="<?= $i ?>" required><label for="star<?= $i ?>" title="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>">★</label><?php endfor; ?></div>
        <input name="comment" maxlength="300" placeholder="Anything to share? (optional)">
        <button type="submit" class="uride-submit">Submit rating</button>
    </form>
    <?php elseif ($ride['rating'] !== null): ?><p class="uride-help">You rated this ride <?= str_repeat('★', (int) $ride['rating']) ?>.</p><?php endif; ?>

    <?php if (!$active): ?><a class="uride-submit uride-again" href="uride.php">Book another ride</a><?php endif; ?>
</section>
<script defer src="assets/js/uride-ride.js?v=3"></script>
<script defer src="assets/js/uride-live.js?v=1"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
