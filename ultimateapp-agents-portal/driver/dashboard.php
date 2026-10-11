<?php
require_once __DIR__ . '/_bootstrap.php';
$driver = driver_require();
$id = (int) $driver['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('driver_write', 'driver:' . $id, 120, 600);
    $action = dp('action');
    $rideId = (int) dp('ride_id');
    try {
        if ($action === 'online' || $action === 'offline') {
            uride_set_online($pdo, $id, $action === 'online');
            dflash('success', $action === 'online' ? 'You are online. Keep this screen open to receive nearby requests.' : 'You are offline.');
        } elseif ($action === 'accept' && $rideId > 0) {
            uride_accept($pdo, $id, $rideId);
            dflash('success', 'Ride accepted. Head to the pick-up point.');
            redirect('ride.php?id=' . $rideId);
        } elseif ($action === 'skip' && $rideId > 0) {
            uride_skip($pdo, $id, $rideId);
        }
    } catch (InvalidArgumentException $ex) {
        dflash('error', $ex->getMessage());
    }
    redirect('dashboard.php');
}

uride_expire_stale($pdo);
$driver = driver_current(true);
$active = uride_driver_active_ride($pdo, $id);
$s = uride_settings();
$stmt = $pdo->prepare("SELECT COUNT(*) trips, COALESCE(SUM(fare_centavos),0) fares, COALESCE(SUM(commission_centavos),0) commission FROM uride_requests WHERE driver_id = ? AND status = 'completed' AND completed_at >= CURDATE()");
$stmt->execute([$id]);
$today = $stmt->fetch();
$approved = $driver['status'] === 'approved';
$online = (int) $driver['is_online'] === 1;
$lowWallet = (int) $driver['wallet_centavos'] < $s['min_wallet'];
$rating = uride_rating($driver);
$veh = uride_vehicles()[$driver['vehicle_type']]['label'] ?? $driver['vehicle_type'];
$fdir = uride_storage_dir() . '/';
$filesMissing = ($driver['selfie_file'] && !is_file($fdir . basename((string) $driver['selfie_file'])));
if (!$filesMissing) { $chk = $pdo->prepare('SELECT stored_name FROM uride_driver_documents WHERE driver_id = ?'); $chk->execute([$id]); foreach ($chk->fetchAll(PDO::FETCH_COLUMN) as $n) if (!is_file($fdir . basename((string) $n))) { $filesMissing = true; break; } }
driver_header('', 'dashboard', 'drive');
?>
<section class="d-hello">
    <div><small><?= e(date('l, M j')) ?></small><h1>Hi, <?= e(explode(' ', $driver['full_name'])[0]) ?></h1><p><?= e($veh) ?> · <?= e($driver['plate_no']) ?><?= $rating !== null ? ' · ★ ' . number_format($rating, 1) : '' ?></p></div>
    <?= driver_badge($driver['status']) ?>
</section>

<?php if ($filesMissing && !in_array($driver['status'], ['pending', 'under_review', 'needs_info'], true)): ?><div class="alert warn">Some of your photos or documents need to be uploaded again. <a href="account.php">Upload now</a></div><?php endif; ?>
<?php if (!$approved): ?>
    <?php $st = $driver['status']; $tone = ['needs_info' => 'warn', 'rejected' => 'bad', 'suspended' => 'bad'][$st] ?? 'info'; ?>
    <div class="status-banner <?= $tone ?>"><div>
        <h2><?= e(['pending' => 'Application received', 'under_review' => 'Your application is under review', 'needs_info' => 'We need a few more details', 'rejected' => 'Application not approved', 'suspended' => 'Your driver account is suspended'][$st] ?? 'Status') ?></h2>
        <p><?= $driver['review_note'] ? e($driver['review_note']) : e(['pending' => 'Our team will check your license, vehicle papers and selfie. This usually takes 1–2 working days.', 'under_review' => 'We are checking your documents now.'][$st] ?? 'Contact Ultimate App support for help.') ?></p>
        <?php if ($st === 'needs_info'): ?><p class="mt"><a class="btn small" href="account.php#documents">Update my details &amp; documents</a></p><?php endif; ?>
    </div></div>
    <ol class="steps">
        <li class="done"><i>1</i><span><b>Application submitted</b><small><?= e(date('M j, Y', strtotime($driver['created_at']))) ?></small></span></li>
        <li class="<?= in_array($st, ['under_review', 'needs_info'], true) ? 'now' : ($st === 'pending' ? '' : 'done') ?>"><i>2</i><span><b>Document review</b><small>License, OR/CR and selfie</small></span></li>
        <li class="<?= (int) $driver['wallet_centavos'] >= $s['min_wallet'] ? 'done' : 'now' ?>"><i>3</i><span><b>Top up your wallet</b><small>At least ₱<?= peso($s['min_wallet']) ?> to go online · <a href="wallet.php">Top up</a></small></span></li>
        <li><i>4</i><span><b>Go online &amp; accept rides</b><small>Available once approved</small></span></li>
    </ol>
<?php else: ?>
    <?php if ($active): ?>
        <a class="d-active" href="ride.php?id=<?= (int) $active['id'] ?>">
            <span class="d-pulse"></span>
            <span><small>Current ride · <?= e($active['code']) ?></small><b><?= e(uride_ride_statuses()[$active['status']]) ?></b><em><?= e($active['status'] === 'in_progress' ? $active['dropoff_address'] : $active['pickup_address']) ?></em></span>
            <strong>Open</strong>
        </a>
    <?php endif; ?>

    <?php $outNow = uride_fleet_ready($pdo) ? uride_driver_rentals_out($pdo, $id) : []; if ($outNow): ?>
        <div class="d-out"><small>Self-drive vehicles out now</small><?php foreach ($outNow as $o): ?><a href="ride.php?id=<?= (int) $o['id'] ?>"><span><b><?= e(uride_vehicles()[$o['vehicle_type']]['label'] ?? '') ?></b> · <?= e(uride_short_name((string) $o['full_name'])) ?> · <?= e($o['code']) ?></span><span>back by <?= $o['ends_at'] ? e(date('g:i A', strtotime((string) $o['ends_at']))) : '—' ?></span></a><?php endforeach; ?></div>
    <?php endif; ?>

    <form method="post" class="d-online <?= $online ? 'on' : 'off' ?>" data-drive data-online="<?= $online ? '1' : '0' ?>" data-csrf="<?= e(csrf_token()) ?>" data-active="<?= $active ? (int) $active['id'] : 0 ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= $online ? 'offline' : 'online' ?>">
        <div class="d-online-text">
            <small><?= $online ? 'YOU ARE ONLINE' : 'YOU ARE OFFLINE' ?></small>
            <b><?= $online ? 'Receiving nearby requests' : 'Go online to get rides' ?></b>
            <span data-gps><?= $online ? 'Checking your location…' : 'Requests within ' . e(rtrim(rtrim(number_format($s['radius_km'], 1), '0'), '.')) . ' km of you will appear here.' ?></span>
        </div>
        <button type="submit" class="d-power" aria-label="<?= $online ? 'Go offline' : 'Go online' ?>"<?= !$online && $lowWallet ? ' disabled' : '' ?>><?= driver_icon('power') ?><span><?= $online ? 'Go offline' : 'Go online' ?></span></button>
    </form>
    <?php if ($lowWallet): ?><div class="alert warn">Your wallet is below ₱<?= peso($s['min_wallet']) ?>. <a href="wallet.php">Top up via QR Ph</a> to <?= $online ? 'keep accepting' : 'go online and accept' ?> rides.</div><?php endif; ?>

    <section class="d-feed" aria-live="polite">
        <div class="d-feed-head"><h2>Nearby requests</h2><span data-feed-count><?= $online ? '' : 'Offline' ?></span></div>
        <div data-feed>
            <p class="d-empty"><?= $online ? 'Looking for passengers near you…' : 'You will see ride requests here when you are online.' ?></p>
        </div>
        <template data-feed-tpl>
            <article class="d-req">
                <header><b data-f="fare"></b><span data-f="pay"></span><span class="d-req-away" data-f="away"></span></header>
                <p class="d-req-pt pick"><?= driver_icon('pin') ?><span data-f="pickup"></span></p>
                <p class="d-req-pt drop"><?= driver_icon('flag') ?><span data-f="dropoff"></span></p>
                <p class="d-req-meta"><span data-f="passenger"></span><span data-f="trip"></span><span data-f="net"></span></p>
                <p class="d-req-note" data-f="note" hidden></p>
                <div class="d-req-actions">
                    <form method="post"><input type="hidden" name="csrf"><input type="hidden" name="action" value="skip"><input type="hidden" name="ride_id"><button class="btn" type="submit">Skip</button></form>
                    <form method="post"><input type="hidden" name="csrf"><input type="hidden" name="action" value="accept"><input type="hidden" name="ride_id"><button class="btn grad" type="submit">Accept</button></form>
                </div>
            </article>
        </template>
    </section>
<?php endif; ?>

<section class="d-stats">
    <div><small>Trips today</small><b><?= (int) $today['trips'] ?></b></div>
    <div><small>Fares today</small><b>₱<?= peso((int) $today['fares']) ?></b></div>
    <div><small>Commission</small><b>₱<?= peso((int) $today['commission']) ?></b></div>
</section>
<?php driver_footer(['assets/driver.js?v=5']);
