<?php
require_once __DIR__ . '/../includes/core.php';
driverCheck();
if (!Session::verifyCsrf($_POST[CSRF_TOKEN_NAME] ?? '')) { jsonResponse(false, 'Invalid token'); }
$dId = (int)$_SESSION['tl_driver_id'];
$oId = (int)($_POST['order_id'] ?? 0);

// A driver can only handle one job at a time
$active = getDriverActiveTrip($dId);
if ($active) {
    jsonResponse(false, "May kasalukuyan ka nang aktibong trip (#{$active['code']}). Kumpletuhin muna ito.");
}

// Same wallet balance gate as ride bookings
$minBalance = (float)setting('driver_min_wallet_balance', 20.00);
$commission = (float)setting('driver_commission_per_trip', 5.00);

$driver = DB::row("SELECT wallet_balance FROM tl_drivers WHERE id=?", [$dId]);
if (!$driver || (float)$driver['wallet_balance'] < $minBalance) {
    jsonResponse(false, "Kailangan mo munang mag-Top Up. Minimum na wallet balance para makapag-accept ay ₱" . number_format($minBalance, 2) . ".");
}

$o = DB::row("SELECT * FROM tl_merchant_orders WHERE id=? AND status='ready' AND driver_id IS NULL", [$oId]);
if (!$o) { jsonResponse(false, 'Order no longer available.'); }

DB::run("UPDATE tl_merchant_orders SET driver_id=?, status='assigned' WHERE id=?", [$dId, $oId]);

// Took another job → leave any terminal pila
require_once __DIR__ . '/../../trilink-dispatch/lib/Dispatch.php';
Dispatch::leave($dId);

if ($commission > 0) {
    driverWalletDebit($dId, $commission, 'commission', 'booking', $oId,
        "Platform commission – order #{$o['order_code']}");
}

jsonResponse(true, 'Order pickup accepted!', ['order_id' => $oId]);
