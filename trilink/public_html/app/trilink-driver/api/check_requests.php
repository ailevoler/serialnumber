<?php
require_once __DIR__ . '/../includes/core.php';
require_once __DIR__ . '/../../trilink-dispatch/lib/Dispatch.php';

// Check session - if expired, return special response
if (empty($_SESSION['tl_driver_logged_in']) || empty($_SESSION['tl_driver_id'])) {
    jsonResponse(false, 'session_expired');
}

$driverId       = (int)$_SESSION['tl_driver_id'];
$lastId         = (int)($_GET['last_id'] ?? 0);
$lastOrderId    = (int)($_GET['last_order_id'] ?? 0);
$lastDeliveryId = (int)($_GET['last_delivery_id'] ?? 0);

// If the driver already has a job in progress, don't surface new requests —
// they need to finish (or the page will redirect them back to) that job first.
$activeTrip = getDriverActiveTrip($driverId);

$driver = DB::row("SELECT vehicle_type, is_online FROM tl_drivers WHERE id=?", [$driverId]);
if (!$driver) { jsonResponse(false, 'Driver not found'); }

$vtype    = $driver['vehicle_type'] ?? 'tricycle';
$isOnline = (bool)$driver['is_online'];

// Get ALL current searching requests (excluding ones this driver already declined)
$requests = $activeTrip ? [] : DB::all(
    "SELECT b.id, b.booking_code, b.pickup_address, b.dropoff_address,
            b.fare_total, b.payment_method, b.distance_km, b.vehicle_type,
            u.full_name AS passenger_name, u.phone AS passenger_phone
     FROM tl_bookings b
     JOIN tl_users u ON b.user_id = u.id
     WHERE b.status = 'searching'
       AND b.driver_id IS NULL
       AND b.booking_mode = 'street'
       AND b.vehicle_type = ?
       AND b.id NOT IN (SELECT booking_id FROM tl_booking_declines WHERE driver_id = ?)
     ORDER BY b.created_at ASC
     LIMIT 3",
    [$vtype, $driverId]
);

// Find new ones since last poll (PHP 7.2+ compatible - no fn())
$newRequests = array();
foreach ($requests as $r) {
    if ((int)$r['id'] > $lastId) {
        $newRequests[] = $r;
    }
}

$hasNew   = count($newRequests) > 0;
$latestId = !empty($requests) ? max(array_column($requests, 'id')) : $lastId;

// Build pickup preview
$pickupPreview = '';
if ($hasNew) {
    $first  = $newRequests[0];
    $pparts = explode(',', $first['pickup_address']);
    $dparts = explode(',', $first['dropoff_address']);
    $pickupPreview  = trim($pparts[0]) . ' → ' . trim($dparts[0]);
    $pickupPreview .= ' · ₱' . number_format((float)$first['fare_total'], 2);
}

// ── MERCHANT ORDERS ready for pickup (no vehicle_type restriction) ──
$orderRequests = $activeTrip ? [] : DB::all(
    "SELECT o.id, o.order_code, o.total, o.delivery_fee, o.payment_method, o.delivery_address,
            m.store_name, m.address AS pickup_address,
            u.full_name AS customer_name, u.phone AS customer_phone
     FROM tl_merchant_orders o
     JOIN tl_merchants m ON o.merchant_id = m.id
     JOIN tl_users u ON o.user_id = u.id
     WHERE o.status = 'ready'
       AND o.driver_id IS NULL
       AND o.id NOT IN (SELECT order_id FROM tl_order_declines WHERE driver_id = ?)
     ORDER BY o.created_at ASC
     LIMIT 3",
    [$driverId]
);
$newOrderRequests = array();
foreach ($orderRequests as $r) {
    if ((int)$r['id'] > $lastOrderId) $newOrderRequests[] = $r;
}
$hasNewOrder   = count($newOrderRequests) > 0;
$latestOrderId = !empty($orderRequests) ? max(array_column($orderRequests, 'id')) : $lastOrderId;
$orderPreview  = '';
if ($hasNewOrder) {
    $fo = $newOrderRequests[0];
    $orderPreview = $fo['store_name'] . ' · ₱' . number_format((float)$fo['delivery_fee'], 2);
}

// ── PACKAGE DELIVERY requests (no vehicle_type restriction) ──
$deliveryRequests = $activeTrip ? [] : DB::all(
    "SELECT d.id, d.delivery_code, d.pickup_address, d.dropoff_address,
            d.fee_total, d.payment_method, d.distance_km, d.package_type,
            u.full_name AS sender_name, u.phone AS sender_phone
     FROM tl_deliveries d
     JOIN tl_users u ON d.user_id = u.id
     WHERE d.status = 'searching'
       AND d.driver_id IS NULL
       AND d.id NOT IN (SELECT delivery_id FROM tl_delivery_declines WHERE driver_id = ?)
     ORDER BY d.created_at ASC
     LIMIT 3",
    [$driverId]
);
$newDeliveryRequests = array();
foreach ($deliveryRequests as $r) {
    if ((int)$r['id'] > $lastDeliveryId) $newDeliveryRequests[] = $r;
}
$hasNewDelivery   = count($newDeliveryRequests) > 0;
$latestDeliveryId = !empty($deliveryRequests) ? max(array_column($deliveryRequests, 'id')) : $lastDeliveryId;
$deliveryPreview  = '';
if ($hasNewDelivery) {
    $fd = $newDeliveryRequests[0];
    $deliveryPreview = ucfirst($fd['package_type']) . ' package · ₱' . number_format((float)$fd['fee_total'], 2);
}

jsonResponse(true, 'OK', [
    'is_online'          => $isOnline,
    'active_trip'        => $activeTrip,
    'has_requests'       => count($requests) > 0,
    'new_count'          => count($newRequests),
    'latest_id'          => $latestId,
    'pickup_preview'     => $pickupPreview,
    'requests'           => $requests,
    'has_order_requests' => count($orderRequests) > 0,
    'order_new_count'    => count($newOrderRequests),
    'latest_order_id'    => $latestOrderId,
    'order_pickup_preview' => $orderPreview,
    'order_requests'     => $orderRequests,
    'has_delivery_requests' => count($deliveryRequests) > 0,
    'delivery_new_count'    => count($newDeliveryRequests),
    'latest_delivery_id'    => $latestDeliveryId,
    'delivery_pickup_preview' => $deliveryPreview,
    'delivery_requests'      => $deliveryRequests,
    'queue'              => Dispatch::driverQueue($driverId), // terminal pila position
    'server_time'        => time(), // For debugging
]);
