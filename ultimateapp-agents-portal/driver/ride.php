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
        if ($action === 'return') {
            $claimPeso = trim(dp('claim'));
            if ($claimPeso !== '' && !preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $claimPeso)) throw new InvalidArgumentException('Enter the damage amount in pesos, e.g. 1500.');
            $claim = $claimPeso === '' ? 0 : (int) round((float) $claimPeso * 100);
            $ride = uride_selfdrive_return($pdo, $id, $rideId, dp('return_state') === 'damage' ? $claim : 0, dp('return_note'));
            $msg = 'Rental finished. ₱' . peso((int) $ride['fare_centavos']) . ' added to your wallet, less ₱' . peso((int) $ride['commission_centavos']) . ' commission.';
            if ((int) $ride['late_fee_centavos'] > 0) $msg .= ' Late fee ₱' . peso((int) $ride['late_fee_centavos']) . ' from the deposit.';
            if ($ride['deposit_status'] === 'claimed') $msg .= ' Damage claim ₱' . peso((int) $ride['claim_centavos']) . ' sent to Ultimate App for review.';
            dflash('success', $msg);
            redirect('trips.php?done=' . rawurlencode($ride['code']));
        }
        $ride = uride_driver_action($pdo, $id, $rideId, $action, $action === 'start' && dp('license_ok') === '1' ? 'license_checked' : '');
        if ($action === 'start' && dp('handover_note') !== '') $pdo->prepare('UPDATE uride_requests SET handover_note = ? WHERE id = ? AND driver_id = ?')->execute([mb_substr(dp('handover_note'), 0, 300), $rideId, $id]);
        $messages = ['arrived' => 'Customer notified that you have arrived.', 'start' => uride_is_self_drive($ride) ? 'Vehicle handed over. The rental has started; you will confirm the return here.' : 'Trip started. Drive safely!', 'release' => 'Ride given back. It was sent to other nearby drivers.'];
        if ($action === 'complete') {
            $fare = (int) $ride['fare_centavos'];
            dflash('success', 'Trip completed! ' . ($ride['payment_method'] === 'cash' ? 'Collect ₱' . peso($fare) . ' cash from the passenger.' : '₱' . peso($fare) . ' (' . uride_pay_label((string) $ride['payment_method']) . ') added to your wallet.') . ' Commission ₱' . peso((int) $ride['commission_centavos']) . ' deducted.');
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
$commission = (int) ($ride['commission_centavos'] ?? uride_commission_for($fare, (string) ($ride['trip_type'] ?? 'ride')));
$isRental = ($ride['trip_type'] ?? 'ride') === 'rental';
$rentalEnds = $isRental && $ride['started_at'] ? strtotime((string) $ride['started_at']) + 3600 * (int) $ride['rental_hours'] : null;
$isSelf = uride_is_self_drive($ride);
$lic = null;
if ($isSelf && $ride['license_id']) { $ls = $pdo->prepare('SELECT * FROM user_driver_licenses WHERE id = ?'); $ls->execute([$ride['license_id']]); $lic = $ls->fetch() ?: null; }
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
        <div><small><?= e($ride['code']) ?> · <?= e(uride_pay_label((string) $ride['payment_method'])) ?></small><h1><?= e(uride_ride_statuses()[$status] ?? $status) ?></h1></div>
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
            <div><b><?= e(uride_short_name($ride['passenger_name'])) ?></b><small><?= $isSelf ? 'Customer drives · ' : 'Passenger · ' ?><?= e(uride_vehicles()[$ride['vehicle_type']]['label'] ?? '') ?></small></div>
            <?php if ($activeRide && $phone): ?><a class="btn small" href="tel:<?= e($phone) ?>"><?= driver_icon('phone') ?> Call</a><?php endif; ?>
        </div>
        <p class="d-req-pt pick"><?= driver_icon('pin') ?><span><small>Pick-up</small><?= e($ride['pickup_address']) ?></span></p>
        <p class="d-req-pt drop"><?= driver_icon('flag') ?><span><small><?= $isRental ? 'Rental' : 'Drop-off' ?></small><?= e($ride['dropoff_address']) ?><?= !empty($ride['scheduled_at']) ? ' · starts ' . e(date('M j, g:i A', strtotime((string) $ride['scheduled_at']))) : '' ?><?= $rentalEnds ? ' · ends about ' . e(date('g:i A', $rentalEnds)) : '' ?></span></p>
        <?php if ($ride['passenger_note']): ?><p class="d-req-note">Note: <?= e($ride['passenger_note']) ?></p><?php endif; ?>
        <?php if ($activeRide): ?><a class="btn d-nav" href="<?= e($navUrl) ?>" target="_blank" rel="noopener"><?= driver_icon('nav') ?> Navigate to <?= $status === 'in_progress' ? ($isRental ? 'start point' : 'drop-off') : 'pick-up' ?></a><?php endif; ?>
    </div>

    <?php if ($isSelf): ?>
    <div class="d-card d-self">
        <h3>Self-drive · customer drives</h3>
        <div class="kv"><span>Driver's license</span><b><?= $lic ? e(uride_license_label($lic)) : '—' ?><?= $lic && $lic['status'] === 'approved' ? ' ✓' : '' ?></b></div>
        <?php if ($lic && $activeRide): ?><p><a class="btn small" href="license-photo.php?ride=<?= (int) $rideId ?>" target="_blank" rel="noopener">View license photo</a></p><?php endif; ?>
        <div class="kv"><span>Deposit (in app)</span><b>₱<?= peso((int) $ride['deposit_centavos']) ?></b></div>
        <?php if ($ride['ends_at']): ?><div class="kv"><span>Return by</span><b><?= e(date('M j, g:i A', strtotime((string) $ride['ends_at']))) ?> <small>(+<?= setting_int('uride.self_drive_grace_min', 15) ?> min grace)</small></b></div><?php endif; ?>
        <?php if ($ride['handover_note']): ?><div class="kv"><span>At hand-over</span><b><?= e($ride['handover_note']) ?></b></div><?php endif; ?>
        <?php if ($status === 'completed'): ?><div class="kv"><span>Deposit</span><b><?= e(uride_deposit_status_label($ride)) ?></b></div><?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="d-card d-fare">
        <div class="kv"><span><?= $isRental ? e(uride_rental_label($ride)) : 'Fare (' . e(number_format((float) $ride['road_km'], 1)) . ' km)' ?></span><b>₱<?= peso($fare) ?></b></div>
        <div class="kv"><span>Payment</span><b><?= $ride['payment_method'] === 'cash' ? 'Cash — collect from passenger' : e(uride_pay_label((string) $ride['payment_method'])) . ' — paid in the app' ?></b></div>
        <div class="kv"><span><?= $isRental ? 'Commission (' . e(rtrim(rtrim(number_format($fare > 0 ? $commission * 100 / $fare : 0, 1, '.', ''), '0'), '.')) . '%)' : 'URide trip fee' ?></span><b>− ₱<?= peso($commission) ?></b></div>
        <div class="kv total"><span>Your earnings</span><b>₱<?= peso($fare - $commission) ?></b></div>
    </div>

    <?php if ($activeRide): ?>
    <div class="d-ride-actions">
        <?php $form = static fn(string $action, string $label, string $class, string $confirm = '') => '<form method="post"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="ride_id" value="' . (int) $GLOBALS['rideId'] . '"><input type="hidden" name="action" value="' . $action . '"><button class="btn ' . $class . '" type="submit">' . e($label) . '</button></form>'; ?>
        <?php if ($isSelf): $csrfIn = '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="ride_id" value="' . (int) $rideId . '">'; ?>
            <?php if ($status === 'accepted'): ?><?= $form('arrived', "I've arrived with the vehicle", 'grad big') ?><?php endif; ?>
            <?php if (in_array($status, ['accepted', 'arrived'], true)): ?>
            <form method="post" class="d-card d-handover"><?= $csrfIn ?><input type="hidden" name="action" value="start">
                <h3>Hand over the vehicle</h3>
                <label class="d-check"><input type="checkbox" name="license_ok" value="1" required> I checked the customer's physical driver's license and ID. The name, photo and license number <b><?= e($lic['license_no'] ?? '—') ?></b> match, and it allows this vehicle.</label>
                <label>Condition, fuel or battery at hand-over <small>(optional)</small><input name="handover_note" maxlength="300" placeholder="e.g. battery 90%, small scratch on left side"></label>
                <button class="btn grad big" type="submit">Hand over — start rental</button>
            </form>
            <?php endif; ?>
            <?php if ($status === 'in_progress'): ?>
            <form method="post" class="d-card d-return" data-confirm="Confirm the vehicle is back?"><?= $csrfIn ?><input type="hidden" name="action" value="return">
                <h3>Vehicle returned</h3>
                <label class="d-check"><input type="radio" name="return_state" value="ok" checked> Returned in good condition</label>
                <label class="d-check"><input type="radio" name="return_state" value="damage"> There is damage (Ultimate App reviews the claim)</label>
                <label>Damage amount (PHP) <small>up to the deposit ₱<?= peso((int) $ride['deposit_centavos']) ?></small><input name="claim" inputmode="decimal" placeholder="0.00"></label>
                <label>What happened <small>(needed for a claim)</small><input name="return_note" maxlength="300" placeholder="e.g. cracked side mirror, photos sent to support"></label>
                <button class="btn grad big" type="submit">Confirm return</button>
            </form>
            <?php endif; ?>
        <?php else: ?>
        <?php if ($status === 'accepted'): ?><?= $form('arrived', "I've arrived at pick-up", 'grad big') ?><?= $form('start', 'Passenger on board — start trip', 'big') ?><?php endif; ?>
        <?php if ($status === 'arrived'): ?><?= $form('start', 'Passenger on board — start trip', 'grad big') ?><?php endif; ?>
        <?php if ($status === 'in_progress'): ?><?= $form('complete', 'Complete trip at drop-off', 'grad big', $ride['payment_method'] === 'cash' ? 'Collect ₱' . peso($fare) . ' cash, then complete the trip?' : 'Complete this trip?') ?><?php endif; ?>
        <?php endif; ?>
        <?php if (in_array($status, ['accepted', 'arrived'], true)): ?><?= $form('release', "Can't make it — give ride back", 'ghost', 'Give this ride back? It will be offered to other drivers.') ?><?php endif; ?>
    </div>
    <p class="table-meta" data-gps role="status"></p>
    <?php else: ?>
        <?php if ($status === 'completed'): ?><div class="alert success">Completed <?= e(date('M j, g:i A', strtotime((string) $ride['completed_at']))) ?><?= $ride['rating'] !== null ? ' · Passenger rated ' . str_repeat('★', (int) $ride['rating']) : '' ?></div>
        <?php else: ?><div class="alert warn">This ride was <?= e(strtolower(uride_ride_statuses()[$status] ?? $status)) ?><?= $ride['cancelled_by'] ? ' by the ' . e($ride['cancelled_by'] === 'admin' ? 'Ultimate App team' : $ride['cancelled_by']) : '' ?>.<?= $ride['cancel_reason'] ? ' Reason: ' . e($ride['cancel_reason']) . '.' : '' ?><?= (int) ($ride['cancel_fee_centavos'] ?? 0) ? ' Cancellation fee ₱' . peso((int) $ride['cancel_fee_centavos']) . ' added to your wallet.' : '' ?></div><?php endif; ?>
        <a class="btn" href="dashboard.php">Back to Drive</a>
    <?php endif; ?>
</section>
<?php driver_footer(['assets/driver.js?v=5', '../assets/js/uride-live.js?v=1']);
