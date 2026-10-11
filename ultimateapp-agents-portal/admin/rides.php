<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/uride.php';
admin_require('rides.view');
uride_expire_stale($pdo);
$labels = uride_ride_statuses();
$vehicles = uride_vehicles();
$kpi = $pdo->query("SELECT
    SUM(status = 'requested') waiting, SUM(status IN ('accepted','arrived','in_progress')) ongoing,
    SUM(status = 'completed' AND completed_at >= CURDATE()) done_today,
    COALESCE(SUM(CASE WHEN status = 'completed' AND completed_at >= CURDATE() THEN fare_centavos END),0) fares_today,
    COALESCE(SUM(CASE WHEN status = 'completed' AND completed_at >= CURDATE() THEN commission_centavos END),0) commission_today,
    SUM(status = 'expired' AND created_at >= CURDATE()) expired_today
    FROM uride_requests")->fetch();
$online = (int) $pdo->query("SELECT COUNT(*) FROM uride_drivers WHERE status = 'approved' AND is_online = 1 AND location_at >= NOW() - INTERVAL 2 MINUTE")->fetchColumn();

$search = q('q'); $status = q('status'); $from = q('from'); $to = q('to'); $pay = q('pay');
$where = ['1=1']; $params = [];
if ($search !== '') { $where[] = '(r.code LIKE ? OR u.full_name LIKE ? OR u.mobile LIKE ? OR d.full_name LIKE ? OR d.plate_no LIKE ?)'; array_push($params, ...array_fill(0, 5, '%' . $search . '%')); }
if ($status === 'active') $where[] = "r.status IN ('requested','accepted','arrived','in_progress')";
elseif (isset($labels[$status])) { $where[] = 'r.status = ?'; $params[] = $status; }
if (in_array($pay, ['credits', 'cash', 'bcash'], true)) { $where[] = 'r.payment_method = ?'; $params[] = $pay; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from)) { $where[] = 'r.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $to)) { $where[] = 'r.created_at <= ?'; $params[] = $to . ' 23:59:59'; }
$sql = ' FROM uride_requests r JOIN users u ON u.id = r.user_id LEFT JOIN uride_drivers d ON d.id = r.driver_id WHERE ' . implode(' AND ', $where);
if (q('export') === 'csv') {
    $stmt = $pdo->prepare('SELECT r.code, r.created_at, r.status, r.vehicle_type, u.full_name, d.full_name, d.plate_no, r.pickup_address, r.dropoff_address, r.road_km, r.payment_method, r.fare_centavos/100, r.commission_centavos/100, r.completed_at, r.cancelled_by, r.rating' . $sql . ' ORDER BY r.id DESC');
    $stmt->execute($params);
    admin_audit('export_rides');
    csv_download('uride-rides-' . date('Ymd') . '.csv', ['Code', 'Booked', 'Status', 'Vehicle', 'Passenger', 'Driver', 'Plate', 'Pick-up', 'Drop-off', 'Km', 'Payment', 'Fare', 'Commission', 'Completed', 'Cancelled by', 'Rating'], $stmt);
}
[$page, $per, $offset] = paging(30);
$c = $pdo->prepare('SELECT COUNT(*)' . $sql); $c->execute($params); $total = (int) $c->fetchColumn();
$stmt = $pdo->prepare('SELECT r.*, u.full_name passenger, d.full_name driver, d.plate_no' . $sql . " ORDER BY r.id DESC LIMIT $per OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$tone = ['requested' => 'pending', 'accepted' => 'open', 'arrived' => 'open', 'in_progress' => 'open', 'completed' => 'completed', 'cancelled' => 'cancelled', 'expired' => 'closed'];
admin_header('URide rides', 'rides');
?>
<div class="grid kpis">
    <div class="card kpi hero"><small>Waiting for a driver</small><strong><?= (int) $kpi['waiting'] ?></strong><span><?= $online ?> driver<?= $online === 1 ? '' : 's' ?> online now</span></div>
    <div class="card kpi"><small>Rides in progress</small><strong><?= (int) $kpi['ongoing'] ?></strong><span>Accepted, arrived or on trip</span></div>
    <div class="card kpi"><small>Completed today</small><strong><?= (int) $kpi['done_today'] ?></strong><span>Fares ₱<?= peso((int) $kpi['fares_today']) ?></span></div>
    <div class="card kpi"><small>Commission today</small><strong>₱<?= peso((int) $kpi['commission_today']) ?></strong><span><?= (int) $kpi['expired_today'] ?> request<?= (int) $kpi['expired_today'] === 1 ? '' : 's' ?> found no driver today</span></div>
</div>
<div class="tabs mt"><a class="<?= $status === '' ? 'active' : '' ?>" href="rides.php">All</a><a class="<?= $status === 'active' ? 'active' : '' ?>" href="?status=active">Active</a><?php foreach ($labels as $k => $l): ?><a class="<?= $status === $k ? 'active' : '' ?>" href="?status=<?= e($k) ?>"><?= e(['requested' => 'Waiting', 'accepted' => 'Accepted', 'arrived' => 'Arrived', 'in_progress' => 'On trip', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'expired' => 'No driver'][$k]) ?></a><?php endforeach; ?></div>
<form class="toolbar" method="get">
    <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Ride code, passenger, driver, plate" aria-label="Search rides">
    <select name="pay" data-autosubmit aria-label="Payment"><option value="">Any payment</option><option value="credits"<?= $pay === 'credits' ? ' selected' : '' ?>>Credits</option><option value="cash"<?= $pay === 'cash' ? ' selected' : '' ?>>Cash</option><option value="bcash"<?= $pay === 'bcash' ? ' selected' : '' ?>>BCash</option></select>
    <input type="date" name="from" value="<?= e($from) ?>" aria-label="From"><input type="date" name="to" value="<?= e($to) ?>" aria-label="To">
    <button class="btn" type="submit">Filter</button><span class="spacer"></span>
    <a class="btn" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>"><?= icon('download') ?> Export CSV</a>
</form>
<div class="table-wrap"><table>
    <thead><tr><th>Ride</th><th>Passenger</th><th>Driver</th><th>Route</th><th>Payment</th><th class="num">Fare</th><th class="num">Commission</th><th>Status</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="8" class="muted">No rides match.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
        <tr><td><a class="row-link" href="ride.php?id=<?= (int) $r['id'] ?>"><?= e($r['code']) ?></a><small><?= e(date('M j, g:i A', strtotime($r['created_at']))) ?> · <?= e($vehicles[$r['vehicle_type']]['label'] ?? '') ?></small></td>
            <td><?= e($r['passenger']) ?></td>
            <td><?= $r['driver'] ? '<a href="driver.php?id=' . (int) $r['driver_id'] . '">' . e($r['driver']) . '</a><small>' . e($r['plate_no']) . '</small>' : '<span class="muted">—</span>' ?></td>
            <td class="route"><?= e(mb_strimwidth($r['pickup_address'], 0, 38, '…')) ?><small>→ <?= e(mb_strimwidth($r['dropoff_address'], 0, 38, '…')) ?></small></td>
            <td><?= e(ucfirst($r['payment_method'])) ?></td>
            <td class="num"><?= $r['fare_centavos'] !== null ? '₱' . peso((int) $r['fare_centavos']) : '—' ?></td>
            <td class="num"><?= $r['commission_centavos'] !== null && $r['status'] === 'completed' ? '₱' . peso((int) $r['commission_centavos']) : '—' ?></td>
            <td><span class="badge <?= ['pending' => 'warn', 'open' => 'info', 'completed' => 'good', 'cancelled' => 'muted', 'closed' => 'muted'][$tone[$r['status']] ?? 'closed'] ?>"><?= e($labels[$r['status']] ?? $r['status']) ?></span></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?= pager($page, $per, $total) ?>
<?php admin_footer();
