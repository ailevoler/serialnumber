<?php
/**
 * RBNW WALLET — Request Money (QR Code with Amount)
 * A request is created without a specific payer attached — anyone who
 * scans the resulting QR (or opens the shared link) and is logged into
 * their own RBNW Wallet can view and pay it, once.
 */
ob_start();
require_once __DIR__ . '/../includes/functions.php';
ob_clean();

header('Content-Type: application/json; charset=utf-8');

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e['message']]);
    }
});

require_login();

$action = $_GET['action'] ?? '';
$userId = current_user_id();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

try {

switch ($action) {

    // Create a new open (QR-based) money request.
    case 'generate':
        $amount  = round((float)($input['amount'] ?? 0), 2);
        $note    = trim($input['note'] ?? '');
        $dueDate = trim($input['due_date'] ?? '');

        if ($amount <= 0) {
            json_out(['success' => false, 'message' => 'Enter a valid amount.']);
        }
        if (mb_strlen($note) > 100) {
            json_out(['success' => false, 'message' => 'Note is too long (max 100 characters).']);
        }
        $dueDateSql = null;
        if ($dueDate) {
            $ts = strtotime($dueDate);
            if (!$ts || $ts < strtotime('today')) {
                json_out(['success' => false, 'message' => 'Due date must be today or later.']);
            }
            $dueDateSql = date('Y-m-d', $ts);
        }

        $reference = generate_reference('RM');

        db()->prepare(
            "INSERT INTO rbnw_money_requests (reference_no, requester_id, amount, note, due_date, status)
             VALUES (?, ?, ?, ?, ?, 'pending')"
        )->execute([$reference, $userId, $amount, $note ?: null, $dueDateSql]);

        json_out([
            'success'      => true,
            'reference_no' => $reference,
            'amount'       => $amount,
            'note'         => $note,
            'due_date'     => $dueDateSql,
            // Compact payload for the client to render a QR from (qrcodejs) —
            // amount/note are always fetched fresh via ?action=get, never
            // trusted from the QR itself, so an edited/cancelled request
            // can't be replayed with stale info.
            'qr_payload'   => base64_encode(json_encode(['type' => 'rbnw_request', 'ref' => $reference])),
        ]);
        break;

    // Fetch a request's details. Behaves differently for the owner
    // (full activity view) vs. anyone else (payer preview — also flips
    // pending -> viewed on first look).
    case 'get':
        $ref = trim($_GET['ref'] ?? '');
        if (!$ref) {
            json_out(['success' => false, 'message' => 'Missing reference.']);
        }

        $stmt = db()->prepare(
            "SELECT r.*, u.first_name, u.last_name, u.mobile_number
             FROM rbnw_money_requests r
             JOIN rbnw_users u ON u.id = r.requester_id
             WHERE r.reference_no = ? LIMIT 1"
        );
        $stmt->execute([$ref]);
        $req = $stmt->fetch();

        if (!$req) {
            json_out(['success' => false, 'message' => 'Request not found.'], 404);
        }

        // Auto-expire if past due date
        if ($req['status'] === 'pending' || $req['status'] === 'viewed') {
            if ($req['due_date'] && strtotime($req['due_date']) < strtotime('today')) {
                db()->prepare("UPDATE rbnw_money_requests SET status = 'expired' WHERE id = ?")->execute([$req['id']]);
                $req['status'] = 'expired';
            }
        }

        $isOwner = (int)$req['requester_id'] === $userId;
        $requesterName = trim($req['first_name'] . ' ' . $req['last_name']);

        if ($isOwner) {
            json_out([
                'success'      => true,
                'is_owner'     => true,
                'reference_no' => $req['reference_no'],
                'amount'       => (float)$req['amount'],
                'note'         => $req['note'],
                'due_date'     => $req['due_date'],
                'status'       => $req['status'],
                'created_at'   => $req['created_at'],
                'viewed_at'    => $req['viewed_at'],
                'paid_at'      => $req['paid_at'],
                'requester_name' => $requesterName,
            ]);
        }

        // Payer preview — first view flips pending -> viewed
        if ($req['status'] === 'pending') {
            db()->prepare("UPDATE rbnw_money_requests SET status = 'viewed', viewed_at = NOW() WHERE id = ?")
                ->execute([$req['id']]);
            $req['status'] = 'viewed';
        }

        json_out([
            'success'         => true,
            'is_owner'        => false,
            'reference_no'    => $req['reference_no'],
            'amount'          => (float)$req['amount'],
            'note'            => $req['note'],
            'due_date'        => $req['due_date'],
            'status'          => $req['status'],
            'payable'         => in_array($req['status'], ['pending', 'viewed'], true) && (int)$req['requester_id'] !== $userId,
            'requester_name'  => $requesterName,
            'requester_mobile' => $req['mobile_number'],
        ]);
        break;

    // Pay someone else's request.
    case 'pay':
        $ref  = trim($input['ref'] ?? '');
        $mpin = trim($input['mpin'] ?? '');

        if (!$ref) {
            json_out(['success' => false, 'message' => 'Missing reference.']);
        }
        if (!$mpin) {
            json_out(['success' => false, 'message' => 'Enter your MPIN to confirm.']);
        }

        $result = pay_money_request($ref, $userId, $mpin);

        if (!$result['ok']) {
            json_out(['success' => false, 'message' => $result['message']]);
        }

        json_out([
            'success'         => true,
            'amount'          => $result['amount'],
            'amount_formatted' => peso($result['amount']),
            'requester_name'  => $result['requester_name'],
            'reference_no'    => $result['reference_no'],
        ]);
        break;

    // Requester cancels their own still-open request.
    case 'cancel':
        $ref = trim($input['ref'] ?? '');
        if (!$ref) {
            json_out(['success' => false, 'message' => 'Missing reference.']);
        }

        $stmt = db()->prepare("SELECT * FROM rbnw_money_requests WHERE reference_no = ? AND requester_id = ? LIMIT 1");
        $stmt->execute([$ref, $userId]);
        $req = $stmt->fetch();

        if (!$req) {
            json_out(['success' => false, 'message' => 'Request not found.'], 404);
        }
        if (!in_array($req['status'], ['pending', 'viewed'], true)) {
            json_out(['success' => false, 'message' => 'This request can no longer be cancelled.']);
        }

        db()->prepare("UPDATE rbnw_money_requests SET status = 'cancelled' WHERE id = ?")->execute([$req['id']]);

        json_out(['success' => true, 'status' => 'cancelled']);
        break;

    default:
        json_out(['success' => false, 'message' => 'Unknown action.'], 404);
}

} catch (Throwable $e) {
    json_out(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}
