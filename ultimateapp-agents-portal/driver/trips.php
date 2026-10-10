<?php
require_once __DIR__ . '/_bootstrap.php';
$driver = driver_require();
$id = (int) $driver['id'];
$range = in_array(dq('range'), ['today', 'week', 'month', 'all'], true) ? dq('range') : 'week';
$from = ['today' => date('Y-m-d 00:00:00'), 'week' => date('Y-m-d 00:00:00', strtotime('-6 days')), 'month' => date('Y-m-01 00:00:00'), 'all' => '2000-01-01 00:00:00'][$range];
$stmt = $pdo->prepare("SELECT COUNT(*) trips, COALESCE(SUM(fare_centavos),0) fares, COALESCE(SUM(commission_centavos),0) commission,
    COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN fare_centavos END),0) cash, COALESCE(SUM(CASE WHEN payment_method = 'credits' THEN fare_centavos END),0) credits
    FROM uride_requests WHERE driver_id = ? AND status = 'completed' AND completed_at >= ?");
$stmt->execute([$id, $from]);
$sum = $stmt->fetch();
$stmt = $pdo->prepare("SELECT r.*, u.full_name passenger_name FROM uride_requests r JOIN users u ON u.id = r.user_id WHERE r.driver_id = ? AND COALESCE(r.completed_at, r.cancelled_at, r.accepted_at, r.created_at) >= ? ORDER BY r.id DESC LIMIT 100");
$stmt->execute([$id, $from]);
$rides = $stmt->fetchAll();
driver_header('Trips & earnings', 'trips');
?>
<nav class="d-seg" aria-label="Period"><?php foreach (['today' => 'Today', 'week' => '7 days', 'month' => 'This month', 'all' => 'All'] as $k => $l): ?><a href="?range=<?= $k ?>" class="<?= $range === $k ? 'active' : '' ?>"><?= $l ?></a><?php endforeach; ?></nav>
<section class="d-earn">
    <div class="d-earn-main"><small>Net earnings</small><b>₱<?= peso((int) $sum['fares'] - (int) $sum['commission']) ?></b><span><?= (int) $sum['trips'] ?> trip<?= (int) $sum['trips'] === 1 ? '' : 's' ?></span></div>
    <div class="d-earn-grid">
        <div><small>Total fares</small><b>₱<?= peso((int) $sum['fares']) ?></b></div>
        <div><small>Cash collected</small><b>₱<?= peso((int) $sum['cash']) ?></b></div>
        <div><small>Credits to wallet</small><b>₱<?= peso((int) $sum['credits']) ?></b></div>
        <div><small>Commission</small><b>− ₱<?= peso((int) $sum['commission']) ?></b></div>
    </div>
</section>
<section class="d-list">
    <?php if (!$rides): ?><p class="d-empty">No trips in this period yet.</p><?php endif; ?>
    <?php foreach ($rides as $r): $fare = (int) $r['fare_centavos']; ?>
        <a class="d-trip" href="ride.php?id=<?= (int) $r['id'] ?>">
            <div class="d-trip-top"><b><?= e($r['code']) ?></b><?= driver_badge($r['status']) ?></div>
            <p><?= e($r['pickup_address']) ?> <span aria-hidden="true">→</span> <?= e($r['dropoff_address']) ?></p>
            <div class="d-trip-meta"><span><?= e(date('M j, g:i A', strtotime((string) ($r['completed_at'] ?? $r['accepted_at'] ?? $r['created_at'])))) ?> · <?= e(uride_short_name($r['passenger_name'])) ?> · <?= $r['payment_method'] === 'cash' ? 'Cash' : 'Credits' ?></span>
                <b><?= $r['status'] === 'completed' ? '₱' . peso($fare - (int) $r['commission_centavos']) : '—' ?></b></div>
            <?php if ($r['rating'] !== null): ?><small class="d-stars"><?= str_repeat('★', (int) $r['rating']) . str_repeat('☆', 5 - (int) $r['rating']) ?><?= $r['rating_comment'] ? ' “' . e($r['rating_comment']) . '”' : '' ?></small><?php endif; ?>
        </a>
    <?php endforeach; ?>
</section>
<?php driver_footer();
