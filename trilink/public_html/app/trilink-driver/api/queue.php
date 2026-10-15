<?php
// ============================================================
// TRILINK DRIVER – api/queue.php
// Work mode + terminal pila:
//   status                         → current mode / pila position
//   set_mode (mode, terminal_id)   → Terminal (joins pila) or Not in Terminal (leaves)
//   join (terminal_id)             → back to the pila (e.g. after a dispatched trip)
// ============================================================
require_once __DIR__ . '/../includes/core.php';
require_once __DIR__ . '/../../trilink-dispatch/lib/Dispatch.php';
driverCheck();
$driverId = (int)$_SESSION['tl_driver_id'];
$action   = $_POST['action'] ?? $_GET['action'] ?? 'status';

if ($action === 'status') {
    jsonResponse(true, 'OK', ['work' => Dispatch::driverMode($driverId)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Method not allowed', null, 405);
if (!Session::verifyCsrf($_POST[CSRF_TOKEN_NAME] ?? '')) jsonResponse(false, 'Invalid token');

try {
    if ($action === 'set_mode' || $action === 'join' || $action === 'leave') {
        $mode = $action === 'join' ? 'terminal' : ($action === 'leave' ? 'street' : (string)($_POST['mode'] ?? ''));
        if ($mode === 'terminal') {
            if (getDriverActiveTrip($driverId)) jsonResponse(false, 'Tapusin muna ang kasalukuyang trip bago pumila.');
            // Use the freshest GPS fix the phone sent with the request
            $lat = (float)($_POST['lat'] ?? 0);
            $lng = (float)($_POST['lng'] ?? 0);
            if ($lat && $lng) DB::run("UPDATE tl_drivers SET current_lat=?, current_lng=? WHERE id=?", [$lat, $lng, $driverId]);
        }
        $work = Dispatch::setMode($driverId, $mode, (int)($_POST['terminal_id'] ?? 0));
        if ($work['mode'] === 'terminal') {
            DB::run("UPDATE tl_drivers SET is_online=1, last_online=NOW() WHERE id=?", [$driverId]);
            jsonResponse(true, "Terminal mode — nakapila ka sa {$work['queue']['terminal_name']} (#{$work['queue']['position']}).", ['work' => $work]);
        }
        jsonResponse(true, 'Not in Terminal — tatanggap ka na ng ride requests sa paligid.', ['work' => $work]);
    }
} catch (DispatchException $e) {
    jsonResponse(false, $e->getMessage());
}

jsonResponse(false, 'Unknown action.', null, 400);
