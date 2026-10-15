<?php
// ============================================================
// TRILINK – api/wallet.php
// Handles: topup_request, topup_status, topup_cancel, get_balance, get_history, send_money
// ============================================================

require_once __DIR__ . '/../includes/core.php';
if (!Auth::check()) jsonResponse(false, 'Unauthorized.', null, 401);

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$userId = Auth::userId();

// ── TOP-UP (QR Ph / Teller) ──────────────────────────────────
// Creates a pending request. The wallet is credited only when PayMongo
// confirms the QR Ph payment or a teller confirms the cash payment.
if (in_array($action, ['topup_request', 'topup_status', 'topup_cancel'], true)) {
    require_once __DIR__ . '/../../trilink-pay/TopupService.php';
}

if ($action === 'topup_request') {
    if (!Session::verifyCsrf($_POST[CSRF_TOKEN_NAME] ?? '')) {
        jsonResponse(false, 'Invalid CSRF token.');
    }
    try {
        $t = Topup::create(Topup::PASSENGER, $userId, $_POST['amount'] ?? 0, (string)($_POST['channel'] ?? ''));
        jsonResponse(true, 'Top-up request created.', [
            'code'     => $t['topup_code'],
            'channel'  => $t['channel'],
            'amount'   => (float)$t['amount'],
            'qr_image' => $t['qr_image'],
            'url'      => APP_URL . '/pages/topup.php?code=' . urlencode($t['topup_code']),
        ]);
    } catch (TopupException $e) {
        jsonResponse(false, $e->getMessage());
    } catch (Exception $e) {
        error_log('[TriLink Topup] ' . $e->getMessage());
        jsonResponse(false, 'Top-up failed. Please try again.');
    }
}

if ($action === 'topup_status') {
    $t = Topup::findForOwner((string)($_GET['code'] ?? ''), Topup::PASSENGER, $userId);
    if (!$t) jsonResponse(false, 'Top-up not found.', null, 404);
    $t = Topup::refresh($t);
    $acc = Topup::account(Topup::PASSENGER, $userId);
    jsonResponse(true, 'OK', [
        'status'       => $t['status'],
        'seconds_left' => max(0, (int)$t['seconds_left']),
        'balance'      => (float)($acc['wallet_balance'] ?? 0),
    ]);
}

if ($action === 'topup_cancel') {
    if (!Session::verifyCsrf($_POST[CSRF_TOKEN_NAME] ?? '')) {
        jsonResponse(false, 'Invalid CSRF token.');
    }
    $t = Topup::findForOwner((string)($_POST['code'] ?? ''), Topup::PASSENGER, $userId);
    if (!$t) jsonResponse(false, 'Top-up not found.', null, 404);
    $t = Topup::cancel($t);
    if ($t['status'] === 'paid') jsonResponse(false, 'Nabayaran na ito at na-credit na sa wallet mo.');
    jsonResponse(true, 'Top-up cancelled.', ['status' => $t['status']]);
}

// ── GET BALANCE ──────────────────────────────────────────────
if ($action === 'get_balance' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = Auth::user();
    jsonResponse(true, 'OK', ['balance' => (float)$user['wallet_balance']]);
}

// ── GET TRANSACTION HISTORY ──────────────────────────────────
if ($action === 'get_history' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 20;
    $offset = ($page - 1) * $limit;
    $type   = $_GET['type'] ?? 'all';

    $where  = $type !== 'all' ? "AND type=?" : '';
    $params = $type !== 'all'
        ? [$userId, $type, $limit, $offset]
        : [$userId, $limit, $offset];

    $txns = DB::all(
        "SELECT * FROM tl_wallet_transactions WHERE user_id=? {$where} ORDER BY created_at DESC LIMIT ? OFFSET ?",
        $params
    );

    $total = DB::val(
        "SELECT COUNT(*) FROM tl_wallet_transactions WHERE user_id=?" . ($type !== 'all' ? " AND type=?" : ""),
        $type !== 'all' ? [$userId, $type] : [$userId]
    );

    jsonResponse(true, 'OK', ['transactions' => $txns, 'total' => $total, 'page' => $page]);
}

// ── SEND MONEY ───────────────────────────────────────────────
if ($action === 'send_money') {
    if (!Session::verifyCsrf($_POST[CSRF_TOKEN_NAME] ?? '')) {
        jsonResponse(false, 'Invalid CSRF token.');
    }

    $toPhone = normalizePhone($_POST['to_phone'] ?? '');
    $amount  = (float)($_POST['amount'] ?? 0);
    $note    = clean($_POST['note'] ?? '');

    if (!isValidPhone($toPhone)) jsonResponse(false, 'Invalid phone number.');
    if ($amount < 1) jsonResponse(false, 'Minimum send amount is ₱1.');

    $sender = Auth::user();
    if ((float)$sender['wallet_balance'] < $amount) {
        jsonResponse(false, 'Insufficient wallet balance.');
    }

    $recipient = DB::row(
        "SELECT id,full_name,phone FROM tl_users WHERE phone=? AND status='active'",
        [$toPhone]
    );
    if (!$recipient) jsonResponse(false, 'Recipient not found or account inactive.');
    if ($recipient['id'] === $userId) jsonResponse(false, 'Cannot send money to yourself.');

    $pdo = DB::get();
    try {
        $pdo->beginTransaction();
        $desc = $note ?: "Sent to {$recipient['full_name']}";
        walletDebit($userId, $amount, 'send', 'transfer', $recipient['id'], $desc);
        walletCredit($recipient['id'], $amount, 'receive', 'transfer', $userId,
            "Received from {$sender['full_name']}");
        $pdo->commit();
        jsonResponse(true, "₱" . number_format($amount, 2) . " sent to {$recipient['full_name']}!");
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(false, 'Transfer failed. Please try again.');
    }
}

jsonResponse(false, 'Unknown action.', null, 400);
