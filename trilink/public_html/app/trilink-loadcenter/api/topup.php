<?php
// ============================================================
// TRILINK LOAD CENTER – api/topup.php
// Teller (cash) top-ups for passengers AND drivers.
// Handles: lookup_request, confirm_topup
// The wallet is credited ONLY here, after the teller confirms
// the cash was received.
// ============================================================
require_once __DIR__ . '/../includes/core.php';
require_once __DIR__ . '/../../trilink-pay/TopupService.php';
loadCenterCheck();
$lc = getLoadCenter();
if (!$lc || $lc['status'] !== 'active') jsonResponse(false, 'Hindi active ang Load Center account na ito.', null, 403);
$lcId = (int)$lc['id'];

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function describeTopup(array $t): array {
    $acc = Topup::account($t['account_type'], (int)$t['account_id']);
    return [
        'id'           => (int)$t['id'],
        'request_code' => $t['topup_code'],
        'amount'       => (float)$t['amount'],
        'account_type' => $t['account_type'],
        'account_name' => $acc['name'] ?? '—',
        'account_code' => $acc['code'] ?? '',
        'account_phone'=> $acc['phone'] ?? '',
        'created_at'   => $t['created_at'],
    ];
}

function checkTellerTopup(?array $t): array {
    if (!$t) jsonResponse(false, 'Hindi mahanap ang request code na ito.');
    if ($t['channel'] !== Topup::TELLER) jsonResponse(false, 'QR Ph top-up ito — hindi kailangan ng teller. Awtomatiko itong papasok pag nabayaran.');
    $t = Topup::refresh($t);
    if ($t['status'] === 'paid')      jsonResponse(false, 'Na-confirm na ang top-up na ito kanina.');
    if ($t['status'] === 'cancelled') jsonResponse(false, 'Kinansela na ang top-up request na ito.');
    if ($t['status'] === 'expired')   jsonResponse(false, 'Expired na ang top-up request na ito. Pagawain ng bago ang customer.');
    if ($t['status'] !== 'pending')   jsonResponse(false, 'Hindi na pending ang request na ito.');
    return $t;
}

// ── LOOK UP A PENDING REQUEST BY CODE ──────────────────────────
if ($action === 'lookup_request') {
    $code = strtoupper(trim((string)($_POST['code'] ?? $_GET['code'] ?? '')));
    $code = ltrim($code, '#');
    if ($code === '') jsonResponse(false, 'Walang na-scan/i-type na code.');

    $t = checkTellerTopup(Topup::findByCode($code));
    jsonResponse(true, 'OK', describeTopup($t));
}

// ── CONFIRM CASH RECEIVED → CREDIT WALLET ─────────────────────
if ($action === 'confirm_topup') {
    if (!Session::verifyCsrf($_POST[CSRF_TOKEN_NAME] ?? '')) jsonResponse(false, 'Invalid token.');

    $t = checkTellerTopup(Topup::findById((int)($_POST['request_id'] ?? 0)));

    // The teller must re-enter the amount they received as a safety check.
    $received = round((float)($_POST['amount_received'] ?? 0), 2);
    if (abs($received - (float)$t['amount']) > 0.001) {
        jsonResponse(false, 'Hindi tugma ang halagang natanggap (₱' . number_format($received, 2) . ') sa top-up na ₱' . number_format((float)$t['amount'], 2) . '.');
    }

    try {
        $res = Topup::markPaid((int)$t['id'], [
            'load_center_id'   => $lcId,
            'load_center_name' => $lc['center_name'],
        ]);
        if (!$res['credited']) jsonResponse(false, 'Na-confirm na ang top-up na ito kanina.');
        $who = $t['account_type'] === Topup::DRIVER ? 'driver' : 'pasahero';
        jsonResponse(true, 'Na-credit na ang ₱' . number_format((float)$t['amount'], 2) . " sa {$who}!");
    } catch (TopupException $e) {
        jsonResponse(false, $e->getMessage());
    } catch (Exception $e) {
        error_log('[TriLink Topup] Teller confirm failed: ' . $e->getMessage());
        jsonResponse(false, 'Nagkaproblema. Subukan ulit.');
    }
}

jsonResponse(false, 'Unknown action.', null, 400);
