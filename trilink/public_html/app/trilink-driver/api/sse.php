<?php
// Server-Sent Events endpoint for driver real-time updates
require_once __DIR__ . '/../includes/core.php';
require_once __DIR__ . '/../../trilink-dispatch/lib/Dispatch.php';
driverCheck();

$driverId = (int)$_SESSION['tl_driver_id'];
$driver   = DB::row("SELECT vehicle_type, is_online FROM tl_drivers WHERE id=?", [$driverId]);

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // Nginx: disable buffering
header('Connection: keep-alive');

// Disable output buffering
if (ob_get_level()) ob_end_clean();

function sendEvent($type, $data) {
    echo "event: {$type}\n";
    echo "data: " . json_encode($data) . "\n\n";
    flush();
}

// Send initial ping
sendEvent('ping', ['time' => time()]);

$lastId   = (int)($_GET['last_id'] ?? 0);
$lastDeliveryId = (int)($_GET['last_delivery_id'] ?? 0);
$vtype    = $driver['vehicle_type'] ?? 'tricycle';
$maxTime  = 25; // seconds to keep connection open (Hostinger timeout safe)
$start    = time();

while (time() - $start < $maxTime) {
    // Check session still valid
    session_start();
    if (empty($_SESSION['tl_driver_logged_in'])) {
        sendEvent('logout', ['reason' => 'session_expired']);
        break;
    }
    session_write_close();

    // If a job is already active, don't surface new requests
    $activeTrip = getDriverActiveTrip($driverId);
    // Terminal mode: no street requests (the dispatcher assigns rides)
    if (!$activeTrip && Dispatch::inTerminalMode($driverId)) $activeTrip = true;

    // Check for new booking requests
    $requests = $activeTrip ? [] : DB::all(
        "SELECT b.id, b.booking_code, b.pickup_address, b.dropoff_address,
                b.fare_total, b.payment_method, b.distance_km,
                u.full_name AS passenger_name, u.phone AS passenger_phone
         FROM tl_bookings b
         JOIN tl_users u ON b.user_id = u.id
         WHERE b.status='searching' AND b.driver_id IS NULL AND b.vehicle_type=?
           AND b.booking_mode='street'
         ORDER BY b.created_at ASC LIMIT 3",
        [$vtype]
    );

    // Check for new package delivery requests
    $deliveryRequests = $activeTrip ? [] : DB::all(
        "SELECT d.id, d.delivery_code, d.pickup_address, d.dropoff_address,
                d.fee_total, d.payment_method, d.distance_km, d.package_type,
                u.full_name AS sender_name, u.phone AS sender_phone
         FROM tl_deliveries d
         JOIN tl_users u ON d.user_id = u.id
         WHERE d.status='searching' AND d.driver_id IS NULL
         ORDER BY d.created_at ASC LIMIT 3",
        []
    );

    // Find new ones
    $newRequests = [];
    foreach ($requests as $r) {
        if ((int)$r['id'] > $lastId) $newRequests[] = $r;
    }

    $newDeliveryRequests = [];
    foreach ($deliveryRequests as $r) {
        if ((int)$r['id'] > $lastDeliveryId) $newDeliveryRequests[] = $r;
    }

    if (!empty($newRequests)) {
        $latestId = max(array_column($requests, 'id'));
        $lastId   = $latestId;

        // Build preview
        $p = $newRequests[0];
        $parts = explode(',', $p['pickup_address']);
        $dparts = explode(',', $p['dropoff_address']);
        $preview = trim($parts[0]) . ' → ' . trim($dparts[0]) . ' · ₱' . number_format((float)$p['fare_total'], 2);

        sendEvent('new_booking', [
            'requests'  => $requests,
            'new_count' => count($newRequests),
            'latest_id' => $latestId,
            'preview'   => $preview,
        ]);
    } elseif (!empty($newDeliveryRequests)) {
        $latestDeliveryId = max(array_column($deliveryRequests, 'id'));
        $lastDeliveryId   = $latestDeliveryId;

        $fd = $newDeliveryRequests[0];
        $preview = ucfirst($fd['package_type']) . ' package · ₱' . number_format((float)$fd['fee_total'], 2);

        sendEvent('new_delivery', [
            'requests'          => $deliveryRequests,
            'new_count'         => count($newDeliveryRequests),
            'latest_delivery_id'=> $latestDeliveryId,
            'preview'           => $preview,
        ]);
    } elseif (!empty($requests) || !empty($deliveryRequests)) {
        // Still have active requests - sync
        sendEvent('sync_requests', ['requests' => $requests, 'delivery_requests' => $deliveryRequests]);
    } else {
        // Heartbeat every 5s
        sendEvent('ping', ['time' => time()]);
    }

    // Check if client disconnected
    if (connection_aborted()) break;

    sleep(2); // Check every 2 seconds
}

sendEvent('reconnect', ['delay' => 1000]);
