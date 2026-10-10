<?php
// Driver app heartbeat: saves the driver's GPS position and returns nearby requests / current ride state.
require_once __DIR__ . '/_bootstrap.php';
header('Cache-Control: no-store');
$driver = driver_current();
if (!$driver) driver_json(['error' => 'signed_out'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') driver_json(['error' => 'method_not_allowed'], 405);
$token = $_POST['csrf'] ?? '';
if (!is_string($token) || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $token)) driver_json(['error' => 'invalid_token'], 419);
session_write_close();
$id = (int) $driver['id'];
$limit = rate_limit_take($pdo, 'driver_pulse', 'driver:' . $id, 40, 60);
if (!$limit['allowed']) driver_json(['error' => 'slow_down'], 429);

$lat = filter_var($_POST['lat'] ?? null, FILTER_VALIDATE_FLOAT);
$lng = filter_var($_POST['lng'] ?? null, FILTER_VALIDATE_FLOAT);
$gps = null;
if ($lat !== false && $lng !== false && $lat !== null && $lng !== null) {
    try { uride_heartbeat($pdo, $id, (float) $lat, (float) $lng); $gps = 'ok'; } catch (InvalidArgumentException $e) { $gps = 'outside'; }
}
uride_expire_stale($pdo);
$driver = driver_current(true);
$active = uride_driver_active_ride($pdo, $id);
$out = [
    'status' => $driver['status'], 'online' => (int) $driver['is_online'] === 1, 'live' => uride_driver_is_live($driver), 'gps' => $gps,
    'wallet' => peso((int) $driver['wallet_centavos']), 'active' => $active ? ['id' => (int) $active['id'], 'status' => $active['status']] : null,
    'requests' => [],
];
if (!$active && $out['live']) $out['requests'] = uride_feed($pdo, $driver);

// Ride page: report what happened to the ride being viewed (e.g. the passenger cancelled).
$rideId = (int) ($_POST['ride_id'] ?? 0);
if ($rideId > 0) {
    $stmt = $pdo->prepare('SELECT status, cancelled_by FROM uride_requests WHERE id = ? AND driver_id = ?');
    $stmt->execute([$rideId, $id]);
    $r = $stmt->fetch();
    $out['ride'] = $r ? ['status' => $r['status'], 'cancelled_by' => $r['cancelled_by'], 'label' => uride_ride_statuses()[$r['status']] ?? $r['status']] : null;
}
driver_json($out);
