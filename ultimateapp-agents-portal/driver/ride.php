<?php
require_once __DIR__ . '/_bootstrap.php';
$driver = driver_require();
$id = (int) $driver['id'];
$rideId = (int) dq('id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('driver_write', 'driver:' . $id, 120, 600);
    $rideId = (int) dp('ride_id');
    $action = dp('action');
    try {
        $ride = uride_driver_action($pdo, $id, $rideId, $action);
        $messages = ['arrived' => 'Passenger notified that you have arrived.', 'start' => 'Trip started. Drive safely!', 'release' => 'Ride given back. It was sent to other nearby drivers.'];
        if ($action === 'complete') {
            $fare = (int) $ride['fare_centavos'];
            dflash('success', 'Trip completed! ' . ($ride['payment_method'] === 'cash' ? 'Collect ₱' . peso($fare) . ' cash from the passenger.' : '₱' . peso($fare) . ' Credits added to your wallet.') . ' Commission ₱' . peso((int) $ride['commission_centavos']) . ' deducted.');
            redirect('trips.php?done=' . rawurlencode($ride['code']));
        }
        dflash('success', $messages[$action] ?? 'Updated.');
        redirect($action === 'release' ? 'dashboard.php' : 'ride.php?id=' . $rideId);
    } catch (InvalidArgumentException $ex) {
        dflash('error', $ex->getMessage());
        redirect('ride.php?id=' . $rideId);
    }
}

$stmt = $pdo->prepare('SELECT r.*, u.full_name passenger_name, u.mobile passenger_mobile FROM uride_requests r JOIN users u ON u.id = r.user_id WHERE r.id = ? AND r.driver_id = ?');
$stmt->execute([$rideId, $id]);
$ride = $stmt->fetch();
if (!$ride) { dflash('error', 'Ride not found.'); redirect('dashboard.php'); }
$status = $ride['status'];
$activeRide = in_array($status, ['accepted', 'arrived', 'in_progress'], true);
$fare = (int) $ride['fare_centavos'];
$commission = (int) ($ride['commission_centavos'] ?? uride_commission_for($fare));
$isRental = ($ride['trip_type'] ?? 'ride') === 'rental';
$rentalEnds = $isRental && $ride['started_at'] ? strtotime((string) $ride['started_at']) + 3600 * (int) $ride['rental_hours'] : null;
$navTo = $status === 'in_progress' && !$isRental ? [$ride['dropoff_lat'], $ride['dropoff_lng']] : [$ride['pickup_lat'], $ride['pickup_lng']];
$navUrl = 'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination=' . rawurlencode($navTo[0] . ',' . $navTo[1]);
$phone = preg_match('/^09\d{9}$/D', (string) $ride['passenger_mobile']) ? (string) $ride['passenger_mobile'] : '';
$steps = ['accepted' => 1, 'arrived' => 2, 'in_progress' => 3, 'completed' => 4];
$now = $steps[$status] ?? 0;
driver_header('', 'dashboard', 'ride');
?>
<section class="d-ride" data-ride-page data-ride="<?= (int) $ride['id'] ?>" data-status="<?= e($status) ?>" data-live="<?= $activeRide ? '1' : '0' ?>" data-csrf="<?= e(csrf_token()) ?>">
    <div class="d-ride-top">
        <a href="dashboard.php" class="d-back" aria-label="Back">&larr;</a>
        <div><small><?= e($ride['code']) ?> · <?= $ride['payment_method'] === 'cash' ? 'Cash' : 'Credits' ?></small><h1><?= e(uride_ride_statuses()[$status] ?? $status) ?></h1></div>
    </div>

    <?php if ($activeRide): ?>
    <ol class="d-progress" aria-label="Trip progress">
        <li class="<?= $now >= 1 ? 'done' : '' ?>">Accepted</li><li class="<?= $now >= 2 ? 'done' : '' ?>">Arrived</li><li class="<?= $now >= 3 ? 'done' : '' ?>">On trip</li><li>Done</li>
    </ol>
    <?php endif; ?>

    <div class="d-map" <?= google_maps_attrs() ?> data-live-map data-pickup="<?= e($ride['pickup_lat'] . ',' . $ride['pickup_lng']) ?>" data-dropoff="<?= e($ride['dropoff_lat'] . ',' . $ride['dropoff_lng']) ?>"<?= $driver['lat'] !== null ? ' data-driver="' . e($driver['lat'] . ',' . $driver['lng']) . '"' : '' ?>>
        <p class="d-map-off">Map turns on when Google Maps is enabled. Use <b>Navigate</b> to open directions.</p>
    </div>

    <div class="d-card">
        <div class="d-pax">
            <span class="d-avatar"><?= e(mb_strtoupper(mb_substr($ride['passenger_name'], 0, 1))) ?></span>
            <div><b><?= e(uride_short_name($ride['passenger_name'])) ?></b><small>Passenger · <?= e(uride_vehicles()[$ride['vehicle_type']]['label'] ?? '') ?></small></div>
            <?php if ($activeRide && $phone): ?><a class="btn small" href="tel:<?= e($phone) ?>"><?= driver_icon('phone') ?> Call</a><?php endif; ?>
        </div>
        <p class="d-req-pt pick"><?= driver_icon('pin') ?><span><small>Pick-up</small><?= e($ride['pickup_address']) ?></span></p>
        <p class="d-req-pt drop"><?= driver_icon('flag') ?><span><small><?= $isRental ? 'Rental' : 'Drop-off' ?></small><?= e($ride['dropoff_address']) ?><?= !empty($ride['scheduled_at']) ? ' · starts ' . e(date('M j, g:i A', strtotime((string) $ride['scheduled_at']))) : '' ?><?= $rentalEnds ? ' · ends about ' . e(date('g:i A', $rentalEnds)) : '' ?></span></p>
        <?php if ($ride['passenger_note']): ?><p class="d-req-note">Note: <?= e($ride['passenger_note']) ?></p><?php endif; ?>
        <?php if ($activeRide): ?><a class="btn d-nav" href="<?= e($navUrl) ?>" target="_blank" rel="noopener"><?= driver_icon('nav') ?> Navigate to <?= $status === 'in_progress' ? ($isRental ? 'start point' : 'drop-off') : 'pick-up' ?></a><?php endif; ?>
    </div>

    <div class="d-card d-fare">
        <div class="kv"><span><?= $isRental ? e(uride_rental_label($ride)) : 'Fare (' . e(number_format((float) $ride['road_km'], 1)) . ' km)' ?></span><b>₱<?= peso($fare) ?></b></div>
        <div class="kv"><span>Payment</span><b><?= $ride['payment_method'] === 'cash' ? 'Cash — collect from passenger' : 'Ultimate App Credits — paid in app' ?></b></div>
        <div class="kv"><span>URide commission</span><b>− ₱<?= peso($commission) ?></b></div>
        <div class="kv total"><span>Your earnings</span><b>₱<?= peso($fare - $commission) ?></b></div>
    </div>

    <?php if ($activeRide): ?>
    <div class="d-ride-actions">
        <?php $form = static fn(string $action, string $label, string $class, string $confirm = '') => '<form method="post"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="ride_id" value="' . (int) $GLOBALS['rideId'] . '"><input type="hidden" name="action" value="' . $action . '"><button class="btn ' . $class . '" type="submit">' . e($label) . '</button></form>'; ?>
        <?php if ($status === 'accepted'): ?><?= $form('arrived', "I've arrived at pick-up", 'grad big') ?><?= $form('start', 'Passenger on board — start trip', 'big') ?><?php endif; ?>
        <?php if ($status === 'arrived'): ?><?= $form('start', 'Passenger on board — start trip', 'grad big') ?><?php endif; ?>
        <?php if ($status === 'in_progress'): ?><?= $form('complete', 'Complete trip at drop-off', 'grad big', $ride['payment_method'] === 'cash' ? 'Collect ₱' . peso($fare) . ' cash, then complete the trip?' : 'Complete this trip?') ?><?php endif; ?>
        <?php if (in_array($status, ['accepted', 'arrived'], true)): ?><?= $form('release', "Can't make it — give ride back", 'ghost', 'Give this ride back? It will be offered to other drivers.') ?><?php endif; ?>
    </div>
    <p class="table-meta" data-gps role="status"></p>
    <?php else: ?>
        <?php if ($status === 'completed'): ?><div class="alert success">Completed <?= e(date('M j, g:i A', strtotime((string) $ride['completed_at']))) ?><?= $ride['rating'] !== null ? ' · Passenger rated ' . str_repeat('★', (int) $ride['rating']) : '' ?></div>
        <?php else: ?><div class="alert warn">This ride was <?= e(strtolower(uride_ride_statuses()[$status] ?? $status)) ?><?= $ride['cancelled_by'] ? ' by the ' . e($ride['cancelled_by'] === 'admin' ? 'Ultimate App team' : $ride['cancelled_by']) : '' ?>.<?= $ride['cancel_reason'] ? ' ' . e($ride['cancel_reason']) : '' ?></div><?php endif; ?>
        <a class="btn" href="dashboard.php">Back to Drive</a>
    <?php endif; ?>
</section>
<?php driver_footer(['assets/driver.js?v=5', '../assets/js/uride-live.js?v=1']);
