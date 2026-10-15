<?php
// ============================================================
// TRILINK DRIVER – api/queue.php
// Terminal pila: status | join | leave
// ============================================================
require_once __DIR__ . '/../includes/core.php';
require_once __DIR__ . '/../../trilink-dispatch/lib/Dispatch.php';
driverCheck();
$driverId = (int)$_SESSION['tl_driver_id'];
$action   = $_POST['action'] ?? $_GET['action'] ?? 'status';

if ($action === 'status') {
    jsonResponse(true, 'OK', ['queue' => Dispatch::driverQueue($driverId)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Method not allowed', null, 405);
if (!Session::verifyCsrf($_POST[CSRF_TOKEN_NAME] ?? '')) jsonResponse(false, 'Invalid token');

try {
    if ($action === 'join') {
        // Use the freshest GPS fix the phone sent with the request
        $lat = (float)($_POST['lat'] ?? 0);
        $lng = (float)($_POST['lng'] ?? 0);
        if ($lat && $lng) DB::run("UPDATE tl_drivers SET current_lat=?, current_lng=? WHERE id=?", [$lat, $lng, $driverId]);

        $q = Dispatch::join((int)($_POST['terminal_id'] ?? 0), $driverId);
        DB::run("UPDATE tl_drivers SET is_online=1, last_online=NOW() WHERE id=?", [$driverId]);
        jsonResponse(true, "Nakapila ka na sa {$q['terminal_name']} — #{$q['position']}.", ['queue' => $q]);
    }
    if ($action === 'leave') {
        Dispatch::leave($driverId);
        jsonResponse(true, 'Umalis ka na sa pila.', ['queue' => null]);
    }
} catch (DispatchException $e) {
    jsonResponse(false, $e->getMessage());
}

jsonResponse(false, 'Unknown action.', null, 400);
