<?php
require_once __DIR__ . '/../includes/core.php';
driverCheck();
if (!Session::verifyCsrf($_POST[CSRF_TOKEN_NAME] ?? '')) { jsonResponse(false, 'Invalid token'); }

$dId = (int)$_SESSION['tl_driver_id'];
$delId = (int)($_POST['delivery_id'] ?? 0);

// Terminal mode drivers only take rides assigned by the terminal dispatcher
require_once __DIR__ . '/../../trilink-dispatch/lib/Dispatch.php';
if (Dispatch::inTerminalMode($dId)) {
    jsonResponse(false, 'Naka-Terminal mode ka. Lumipat sa "Not in Terminal" para tumanggap ng requests sa labas.');
}

// A driver can only handle one job at a time — block accepting a new
// delivery while a ride/delivery/order is still in progress.
$active = getDriverActiveTrip($dId);
if ($active) {
    jsonResponse(false, "May kasalukuyan ka nang aktibong trip (#{$active['code']}). Kumpletuhin muna ito.");
}

// Same wallet balance gate as ride bookings / order pickups
$minBalance = (float)setting('driver_min_wallet_balance', 20.00);
$commission = (float)setting('driver_commission_per_trip', 5.00);

$driver = DB::row("SELECT wallet_balance FROM tl_drivers WHERE id=?", [$dId]);
if (!$driver || (float)$driver['wallet_balance'] < $minBalance) {
    jsonResponse(false, "Kailangan mo munang mag-Top Up. Minimum na wallet balance para makapag-accept ay ₱" . number_format($minBalance, 2) . ".");
}

$d = DB::row("SELECT * FROM tl_deliveries WHERE id=? AND status='searching' AND driver_id IS NULL", [$delId]);
if (!$d) { jsonResponse(false, 'Delivery no longer available.'); }

DB::run("UPDATE tl_deliveries SET driver_id=?, status='accepted' WHERE id=?", [$dId, $delId]);

// Took another job → leave any terminal pila
require_once __DIR__ . '/../../trilink-dispatch/lib/Dispatch.php';
Dispatch::leave($dId);

if ($commission > 0) {
    driverWalletDebit($dId, $commission, 'commission', 'delivery', $delId,
        "Platform commission – delivery #{$d['delivery_code']}");
}

jsonResponse(true, 'Delivery accepted!', ['delivery_id' => $delId]);
