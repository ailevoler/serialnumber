<?php
// ============================================================
// TRILINK – pages/topup.php
// Shows a pending top-up (QR Ph code or teller code) and waits
// for the payment to be confirmed.
// ============================================================
require_once __DIR__ . '/../includes/core.php';
require_once __DIR__ . '/../../trilink-pay/TopupService.php';
Auth::requireLogin();
$user = Auth::user();
if (!$user) Auth::logout();

$t = Topup::findForOwner((string)($_GET['code'] ?? ''), Topup::PASSENGER, (int)$user['id']);
if (!$t) redirect('/pages/wallet.php');
$t = Topup::refresh($t);
$acc = Topup::account(Topup::PASSENGER, (int)$user['id']);

$balance   = (float)($acc['wallet_balance'] ?? 0);
$pageTitle = 'Top Up – TriLink';
$backUrl   = APP_URL . '/pages/wallet.php';
$statusUrl = APP_URL . '/api/wallet.php?action=topup_status';
$cancelUrl = APP_URL . '/api/wallet.php?action=topup_cancel';
$csrfName  = CSRF_TOKEN_NAME;
$csrfToken = Session::csrfToken();

require __DIR__ . '/../../trilink-pay/views/topup_status.php';
