<?php
require_once __DIR__ . '/../includes/core.php';
driverCheck();
if(!Session::verifyCsrf($_POST[CSRF_TOKEN_NAME]??'')) { jsonResponse(false,'Invalid token'); }
$dId=(int)$_SESSION['tl_driver_id']; $bId=(int)($_POST['booking_id']??0);

// Terminal mode drivers only take rides assigned by the terminal dispatcher
require_once __DIR__ . '/../../trilink-dispatch/lib/Dispatch.php';
if (Dispatch::inTerminalMode($dId)) {
    jsonResponse(false, 'Naka-Terminal mode ka. Lumipat sa "Not in Terminal" para tumanggap ng requests sa labas.');
}

// A driver can only handle one job at a time
$active = getDriverActiveTrip($dId);
if ($active) {
    jsonResponse(false, "May kasalukuyan ka nang aktibong trip (#{$active['code']}). Kumpletuhin muna ito.");
}

// Wallet balance gate — driver must maintain a minimum reserve to cover per-trip commission
$minBalance = (float)setting('driver_min_wallet_balance', 20.00);
$commission = (float)setting('driver_commission_per_trip', 5.00);

$driver = DB::row("SELECT wallet_balance FROM tl_drivers WHERE id=?", [$dId]);
if (!$driver || (float)$driver['wallet_balance'] < $minBalance) {
    jsonResponse(false, "Kailangan mo munang mag-Top Up. Minimum na wallet balance para makapag-accept ay ₱" . number_format($minBalance, 2) . ".");
}

// Terminal bookings are assigned by the terminal dispatcher, not accepted from the street
$b=DB::row("SELECT * FROM tl_bookings WHERE id=? AND status='searching' AND driver_id IS NULL AND booking_mode='street'",[$bId]);
if(!$b) { jsonResponse(false,'Booking no longer available.'); }

// Atomic claim so two drivers can't accept the same ride
$claimed = DB::run("UPDATE tl_bookings SET driver_id=?,status='accepted',accepted_at=NOW()
                    WHERE id=? AND status='searching' AND driver_id IS NULL AND booking_mode='street'",[$dId,$bId])->rowCount();
if(!$claimed) { jsonResponse(false,'Booking no longer available.'); }

// Took a street job → leave any terminal pila
require_once __DIR__ . '/../../trilink-dispatch/lib/Dispatch.php';
Dispatch::leave($dId);

// Deduct per-trip commission from driver wallet
if ($commission > 0) {
    driverWalletDebit($dId, $commission, 'commission', 'booking', $bId,
        "Platform commission – booking #{$b['booking_code']}");
}

jsonResponse(true,'Ride accepted!',['booking_id'=>$bId]);
