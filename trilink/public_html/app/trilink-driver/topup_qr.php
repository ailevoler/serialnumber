<?php
// Shows a pending driver top-up (QR Ph code or Load Center/teller code)
// and waits until the payment is confirmed.
require_once __DIR__ . '/includes/core.php';
require_once __DIR__ . '/../trilink-pay/TopupService.php';
driverCheck();
$driver = getDriver();
if (!$driver) driverLogout();

$t = Topup::findForOwner((string)($_GET['code'] ?? ''), Topup::DRIVER, (int)$driver['id']);
if (!$t) redirect('/wallet.php');
$t = Topup::refresh($t);
$acc = Topup::account(Topup::DRIVER, (int)$driver['id']);

$balance   = (float)($acc['wallet_balance'] ?? 0);
$pageTitle = 'Top Up – TriLink Driver';
$backUrl   = APP_URL . '/wallet.php';
$statusUrl = APP_URL . '/api/topup_status.php';
$cancelUrl = APP_URL . '/api/topup_cancel.php';
$csrfName  = CSRF_TOKEN_NAME;
$csrfToken = Session::csrfToken();

require __DIR__ . '/../trilink-pay/views/topup_status.php';
