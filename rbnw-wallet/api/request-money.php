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

/** Resolve a request recipient. Returns the user row, or ['error' => msg]. */
function find_request_payee(string $by, string $value, int $selfId): array
{
    if ($by === 'mobile') {
        $value = normalize_mobile($value);
        if (!preg_match('/^09\d{9}$/', $value)) {
            return ['error' => 'Enter a valid mobile number.'];
        }
        $sql = "mobile_number = ?";
    } elseif ($by === 'email') {
        $value = strtolower(trim($value));
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'Enter a valid email address.'];
        }
        $sql = "LOWER(email) = ?";
    } else {
        $sql = "id = ?";
    }
    $stmt = db()->prepare("SELECT id, first_name, last_name, mobile_number, email FROM rbnw_users WHERE {$sql} AND status = 'active' LIMIT 1");
    $stmt->execute([$value]);
    $user = $stmt->fetch();
    if (!$user) {
        return ['error' => $by === 'email'
            ? 'No RBNW Wallet account uses this email. Try Share Link instead.'
            : 'This number is not an RBNW Wallet user yet. Try Share Link instead.'];
    }
    if ((int)$user['id'] === $selfId) {
        return ['error' => 'You cannot request money from yourself.'];
    }
    return $user;
}

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

        // Optional specific payer (From Contacts / Mobile Number / Email).
        // Without one it's an open request anyone can pay via QR or link.
        $payee = null;
        if (!empty($input['to_user_id'])) {
            $payee = find_request_payee('id', (string)(int)$input['to_user_id'], $userId);
        } elseif (!empty($input['to_mobile'])) {
            $payee = find_request_payee('mobile', (string)$input['to_mobile'], $userId);
        } elseif (!empty($input['to_email'])) {
            $payee = find_request_payee('email', (string)$input['to_email'], $userId);
        }
        if ($payee && isset($payee['error'])) {
            json_out(['success' => false, 'message' => $payee['error']]);
        }

        $reference = generate_reference('RM');
        $payeeName = $payee ? trim($payee['first_name'] . ' ' . $payee['last_name']) : null;

        db()->prepare(
            "INSERT INTO rbnw_money_requests
                (reference_no, requester_id, requestee_id, requestee_mobile, requestee_name, amount, note, due_date, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
        )->execute([
            $reference, $userId, $payee['id'] ?? null, $payee['mobile_number'] ?? null, $payeeName,
            $amount, $note ?: null, $dueDateSql,
        ]);

        if ($payee) {
            $me = get_user($userId);
            try {
                push_notification((int)$payee['id'], 'Money request',
                    trim($me['first_name'] . ' ' . $me['last_name']) . ' is requesting ' . peso($amount)
                    . ($note ? " for \"{$note}\"" : '') . '. Tap to view and pay.', 'money_request');
            } catch (Throwable $e) {
                error_log('[RBNW] request notification failed: ' . $e->getMessage());
            }
        }

        json_out([
            'success'      => true,
            'reference_no' => $reference,
            'amount'       => $amount,
            'note'         => $note,
            'due_date'     => $dueDateSql,
            'to_name'      => $payeeName,
            'link'         => rtrim(APP_URL, '/') . '/request-money.php?ref=' . rawurlencode($reference),
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

        if (!$isOwner && $req['requestee_id'] && (int)$req['requestee_id'] !== $userId) {
            json_out(['success' => false, 'message' => 'This request was sent to another RBNW Wallet user.'], 403);
        }

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
                'to_name'      => $req['requestee_name'],
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

    // Find a registered user by email for "Email Address" requests.
    case 'lookup_email':
        $payee = find_request_payee('email', (string)($_GET['email'] ?? ''), $userId);
        if (isset($payee['error'])) {
            json_out(['found' => false, 'message' => $payee['error']]);
        }
        json_out([
            'found'         => true,
            'user_id'       => (int)$payee['id'],
            'name'          => trim($payee['first_name'] . ' ' . $payee['last_name']),
            'mobile_number' => $payee['mobile_number'],
            'initials'      => strtoupper(substr($payee['first_name'], 0, 1) . substr($payee['last_name'], 0, 1)),
        ]);
        break;

    // Requests waiting on me, and the ones I've sent.
    case 'lists':
        db()->prepare(
            "UPDATE rbnw_money_requests SET status = 'expired'
             WHERE (requester_id = ? OR requestee_id = ?) AND status IN ('pending','viewed')
               AND due_date IS NOT NULL AND due_date < CURDATE()"
        )->execute([$userId, $userId]);

        $stmt = db()->prepare(
            "SELECT r.reference_no, r.amount, r.note, r.due_date, r.status, r.created_at,
                    u.first_name, u.last_name, u.mobile_number
             FROM rbnw_money_requests r JOIN rbnw_users u ON u.id = r.requester_id
             WHERE r.requestee_id = ? AND r.status IN ('pending','viewed')
             ORDER BY r.id DESC LIMIT 20"
        );
        $stmt->execute([$userId]);
        $incoming = array_map(fn($r) => [
            'reference_no' => $r['reference_no'],
            'amount'       => (float)$r['amount'],
            'note'         => $r['note'],
            'due_date'     => $r['due_date'],
            'from_name'    => trim($r['first_name'] . ' ' . $r['last_name']),
            'when'         => time_ago_ph($r['created_at']),
        ], $stmt->fetchAll());

        $stmt = db()->prepare(
            "SELECT reference_no, amount, note, status, requestee_name, created_at
             FROM rbnw_money_requests WHERE requester_id = ? ORDER BY id DESC LIMIT 10"
        );
        $stmt->execute([$userId]);
        $outgoing = array_map(fn($r) => [
            'reference_no' => $r['reference_no'],
            'amount'       => (float)$r['amount'],
            'note'         => $r['note'],
            'status'       => $r['status'],
            'to_name'      => $r['requestee_name'],
            'when'         => time_ago_ph($r['created_at']),
        ], $stmt->fetchAll());

        json_out(['success' => true, 'incoming' => $incoming, 'outgoing' => $outgoing]);
        break;

    // The person asked to pay declines.
    case 'decline':
        $ref = trim($input['ref'] ?? '');
        $stmt = db()->prepare(
            "SELECT * FROM rbnw_money_requests WHERE reference_no = ? AND requestee_id = ? AND status IN ('pending','viewed') LIMIT 1"
        );
        $stmt->execute([$ref, $userId]);
        $req = $stmt->fetch();
        if (!$req) {
            json_out(['success' => false, 'message' => 'Request not found or already settled.']);
        }
        db()->prepare("UPDATE rbnw_money_requests SET status = 'cancelled' WHERE id = ?")->execute([$req['id']]);
        $me = get_user($userId);
        try {
            push_notification((int)$req['requester_id'], 'Request declined',
                trim($me['first_name'] . ' ' . $me['last_name']) . ' declined your request of ' . peso((float)$req['amount']) . '.', 'request_declined');
        } catch (Throwable $e) {
            error_log('[RBNW] decline notification failed: ' . $e->getMessage());
        }
        json_out(['success' => true]);
        break;

    default:
        json_out(['success' => false, 'message' => 'Unknown action.'], 404);
}

} catch (Throwable $e) {
    json_out(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}
