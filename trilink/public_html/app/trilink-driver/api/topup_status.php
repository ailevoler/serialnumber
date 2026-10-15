<?php
require_once __DIR__ . '/../includes/core.php';
require_once __DIR__ . '/../../trilink-pay/TopupService.php';
driverCheck();
$driverId = (int)$_SESSION['tl_driver_id'];

$t = Topup::findForOwner((string)($_GET['code'] ?? ''), Topup::DRIVER, $driverId);
if (!$t) jsonResponse(false, 'Request not found', null, 404);
$t = Topup::refresh($t);
$acc = Topup::account(Topup::DRIVER, $driverId);

jsonResponse(true, 'OK', [
    'status'       => $t['status'],
    'seconds_left' => max(0, (int)$t['seconds_left']),
    'balance'      => (float)($acc['wallet_balance'] ?? 0),
]);
