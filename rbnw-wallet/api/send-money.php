<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$userId = current_user_id();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {

    // Search registered RBNW Wallet users by mobile number or name (excluding self)
    case 'search':
        $q = trim($_GET['q'] ?? '');

        if ($q === '') {
            // No query yet: return frequently-sent contacts
            $stmt = db()->prepare(
                "SELECT c.mobile_number, c.nickname, u.id AS user_id, u.first_name, u.last_name
                 FROM rbnw_contacts c
                 LEFT JOIN rbnw_users u ON u.mobile_number = c.mobile_number
                 WHERE c.user_id = ? AND c.is_frequent = 1
                 ORDER BY c.id DESC LIMIT 8"
            );
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll();
        } else {
            $like = '%' . $q . '%';
            $stmt = db()->prepare(
                "SELECT id AS user_id, first_name, last_name, mobile_number
                 FROM rbnw_users
                 WHERE id != ? AND (mobile_number LIKE ? OR first_name LIKE ? OR last_name LIKE ?)
                 ORDER BY first_name LIMIT 15"
            );
            $stmt->execute([$userId, $like, $like, $like]);
            $rows = $stmt->fetchAll();
        }

        $results = array_map(function ($r) {
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            return [
                'user_id' => $r['user_id'] ?? null,
                'name' => $name ?: ($r['nickname'] ?? $r['mobile_number']),
                'mobile_number' => $r['mobile_number'],
                'initials' => strtoupper(substr($r['first_name'] ?? $r['mobile_number'], 0, 1) . substr($r['last_name'] ?? '', 0, 1)),
            ];
        }, $rows);

        json_out(['success' => true, 'results' => $results]);
        break;

    // Exact-match lookup by mobile number — used by the QR scanner (after
    // decoding another user's "My QR") and by Send Money's ?mobile= prefill.
    case 'lookup':
        $mobile = preg_replace('/\D/', '', $_GET['mobile'] ?? '');
        $mobile = preg_replace('/^63(?=\d{10}$)/', '0', $mobile); // 63XXXXXXXXXX -> 0XXXXXXXXXX

        if (!preg_match('/^09[0-9]{9}$/', $mobile)) {
            json_out(['found' => false, 'message' => 'Invalid mobile number format.']);
        }

        $stmt = db()->prepare(
            "SELECT id, first_name, last_name, mobile_number, kyc_status
             FROM rbnw_users WHERE mobile_number = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$mobile]);
        $found = $stmt->fetch();

        if (!$found) {
            json_out(['found' => false, 'message' => 'Mobile number is not an RBNW Wallet user.']);
        }
        if ((int)$found['id'] === $userId) {
            json_out(['found' => false, 'message' => 'You cannot send money to yourself.']);
        }

        $name = trim($found['first_name'] . ' ' . $found['last_name']);
        json_out([
            'found'         => true,
            'user_id'       => (int)$found['id'],
            'name'          => $name,
            'mobile_number' => $found['mobile_number'],
            'verified'      => $found['kyc_status'] === 'verified',
            'initials'      => strtoupper(substr($found['first_name'], 0, 1) . substr($found['last_name'], 0, 1)),
        ]);
        break;

    case 'execute':
        $recipientMobile = preg_replace('/\D/', '', $input['mobile_number'] ?? '');
        $amount = (float)($input['amount'] ?? 0);
        $note   = trim($input['note'] ?? '');
        $mpin   = trim($input['mpin'] ?? '');

        if (strlen($recipientMobile) !== 11) {
            json_out(['success' => false, 'message' => 'Invalid recipient.']);
        }
        if ($amount <= 0) {
            json_out(['success' => false, 'message' => 'Enter a valid amount.']);
        }
        if (!$mpin) {
            json_out(['success' => false, 'message' => 'Enter your MPIN to confirm.']);
        }

        $sender = get_user($userId);
        if (!password_verify($mpin, $sender['mpin_hash'])) {
            json_out(['success' => false, 'message' => 'Incorrect MPIN.']);
        }

        $stmt = db()->prepare("SELECT * FROM rbnw_users WHERE mobile_number = ? LIMIT 1");
        $stmt->execute([$recipientMobile]);
        $recipient = $stmt->fetch();

        if (!$recipient) {
            json_out(['success' => false, 'message' => 'Recipient is not an RBNW Wallet user yet.']);
        }
        if ((int)$recipient['id'] === $userId) {
            json_out(['success' => false, 'message' => 'You cannot send money to yourself.']);
        }

        $senderWallet    = get_wallet($userId);
        $recipientWallet = get_wallet((int)$recipient['id']);

        if (!$senderWallet || !$recipientWallet) {
            json_out(['success' => false, 'message' => 'Wallet setup error. Please contact support.'], 500);
        }
        if ($amount > (float)$senderWallet['balance']) {
            json_out(['success' => false, 'message' => 'Insufficient balance.']);
        }

        $reference = generate_reference('RBNW');
        $senderName = trim($sender['first_name'] . ' ' . $sender['last_name']);
        $recipientName = trim($recipient['first_name'] . ' ' . $recipient['last_name']);

        try {
            $debit = post_wallet_transaction($senderWallet['id'], 'debit', $amount, 'send', [
                'reference_no' => $reference,
                'counterparty_user_id' => $recipient['id'],
                'counterparty_name' => $recipientName,
                'counterparty_mobile' => $recipient['mobile_number'],
                'note' => $note,
            ]);

            post_wallet_transaction($recipientWallet['id'], 'credit', $amount, 'receive', [
                'reference_no' => $reference . '-R',
                'counterparty_user_id' => $userId,
                'counterparty_name' => $senderName,
                'counterparty_mobile' => $sender['mobile_number'],
                'note' => $note,
            ]);
        } catch (Throwable $e) {
            json_out(['success' => false, 'message' => $e->getMessage() ?: 'Transaction failed.'], 500);
        }

        // Remember as a frequent contact for next time
        db()->prepare(
            "INSERT INTO rbnw_contacts (user_id, contact_user_id, mobile_number, is_frequent)
             VALUES (?,?,?,1)
             ON DUPLICATE KEY UPDATE is_frequent = 1"
        )->execute([$userId, $recipient['id'], $recipient['mobile_number']]);

        push_notification((int)$recipient['id'], 'Money Received', "You received " . peso($amount) . " from {$senderName}.", 'receive');

        json_out([
            'success' => true,
            'reference_no' => $reference,
            'amount' => $amount,
            'amount_formatted' => peso($amount),
            'recipient_name' => $recipientName,
            'recipient_mobile' => $recipient['mobile_number'],
            'new_balance' => $debit['balance_after'],
            'new_balance_formatted' => peso($debit['balance_after']),
        ]);
        break;

    default:
        json_out(['success' => false, 'message' => 'Unknown action.'], 404);
}
