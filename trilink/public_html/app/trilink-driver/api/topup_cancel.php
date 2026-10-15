<?php
require_once __DIR__ . '/../includes/core.php';
require_once __DIR__ . '/../../trilink-pay/TopupService.php';
driverCheck();
$driverId = (int)$_SESSION['tl_driver_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Method not allowed', null, 405);
if (!Session::verifyCsrf($_POST[CSRF_TOKEN_NAME] ?? '')) jsonResponse(false, 'Invalid token.');

$t = Topup::findForOwner((string)($_POST['code'] ?? ''), Topup::DRIVER, $driverId);
if (!$t) jsonResponse(false, 'Request not found', null, 404);
$t = Topup::cancel($t);
if ($t['status'] === 'paid') jsonResponse(false, 'Nabayaran na ito at na-credit na sa wallet mo.');
jsonResponse(true, 'Top-up cancelled.', ['status' => $t['status']]);
