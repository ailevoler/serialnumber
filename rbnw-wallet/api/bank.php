<?php
require_once __DIR__ . '/../includes/bank.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$userId = current_user_id();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {

    case 'summary':
        $stmt = db()->prepare(
            "SELECT reference_no, bank_name, account_number_masked, channel, amount, fee, status, failure_reason, created_at
             FROM rbnw_bank_transfers WHERE user_id = ? ORDER BY id DESC LIMIT 15"
        );
        $stmt->execute([$userId]);
        json_out([
            'success'        => true,
            'wallet_balance' => (float)get_wallet($userId)['balance'],
            'accounts'       => array_map(fn($a) => [
                'id' => (int)$a['id'], 'bank_code' => $a['bank_code'], 'bank_name' => $a['bank_name'],
                'short' => bank_directory()[$a['bank_code']][1] ?? $a['bank_name'],
                'account_name' => $a['account_name'], 'masked' => $a['account_number_masked'],
                'is_primary' => (bool)$a['is_primary'],
            ], bank_accounts($userId)),
            'transfers' => array_map(fn($t) => [
                'reference_no' => $t['reference_no'],
                'bank_name' => array_column(bank_directory(), 1, 0)[$t['bank_name']] ?? $t['bank_name'], 'masked' => $t['account_number_masked'],
                'channel' => $t['channel'], 'amount' => (float)$t['amount'], 'fee' => (float)$t['fee'],
                'status' => $t['status'], 'reason' => $t['failure_reason'], 'when' => time_ago_ph($t['created_at']),
            ], $stmt->fetchAll()),
            'banks'  => array_map(fn($code, $b) => ['code' => $code, 'name' => $b[0], 'short' => $b[1], 'type' => $b[2]],
                                  array_keys(bank_directory()), bank_directory()),
            'limits' => [
                'max_linked' => BANK_MAX_LINKED, 'min' => BANK_TRANSFER_MIN,
                'instapay_max' => BANK_INSTAPAY_MAX, 'instapay_fee' => BANK_INSTAPAY_FEE,
                'pesonet_max' => BANK_PESONET_MAX, 'pesonet_fee' => BANK_PESONET_FEE,
            ],
        ]);
        break;

    case 'link':
        $code    = strtoupper(trim((string)($input['bank_code'] ?? '')));
        $name    = trim(preg_replace('/\s+/', ' ', (string)($input['account_name'] ?? '')));
        $number  = preg_replace('/\D/', '', (string)($input['account_number'] ?? ''));
        $banks   = bank_directory();

        if (!isset($banks[$code])) {
            json_out(['success' => false, 'message' => 'Choose a bank or e-wallet.']);
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            json_out(['success' => false, 'message' => 'Enter the account name exactly as it appears on the account.']);
        }
        $isEwallet = $banks[$code][2] === 'ewallet';
        if ($isEwallet) {
            $number = normalize_mobile($number);
            if (!preg_match('/^09\d{9}$/', $number)) {
                json_out(['success' => false, 'message' => 'Enter the 11-digit mobile number of the ' . $banks[$code][1] . ' account.']);
            }
        } elseif (strlen($number) < 6 || strlen($number) > 20) {
            json_out(['success' => false, 'message' => 'Enter a valid account number (6–20 digits).']);
        }

        $accounts = bank_accounts($userId);
        if (count($accounts) >= BANK_MAX_LINKED) {
            json_out(['success' => false, 'message' => 'You can link up to ' . BANK_MAX_LINKED . ' accounts.']);
        }
        $dup = db()->prepare("SELECT id FROM rbnw_bank_accounts WHERE user_id = ? AND bank_code = ? AND account_number = ? AND is_linked = 1");
        $dup->execute([$userId, $code, $number]);
        if ($dup->fetch()) {
            json_out(['success' => false, 'message' => 'This account is already linked.']);
        }

        db()->prepare(
            "INSERT INTO rbnw_bank_accounts (user_id, bank_name, bank_code, account_number, account_number_masked, account_name, is_primary)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([$userId, $banks[$code][0], $code, $number, bank_mask($number), $name, $accounts ? 0 : 1]);
        json_out(['success' => true, 'id' => (int)db()->lastInsertId()]);
        break;

    case 'set_primary':
    case 'unlink':
        $id = (int)($input['id'] ?? 0);
        $stmt = db()->prepare("SELECT * FROM rbnw_bank_accounts WHERE id = ? AND user_id = ? AND is_linked = 1");
        $stmt->execute([$id, $userId]);
        $acct = $stmt->fetch();
        if (!$acct) {
            json_out(['success' => false, 'message' => 'Account not found.']);
        }
        if ($action === 'set_primary') {
            db()->prepare("UPDATE rbnw_bank_accounts SET is_primary = (id = ?) WHERE user_id = ? AND is_linked = 1")->execute([$id, $userId]);
        } else {
            db()->prepare("UPDATE rbnw_bank_accounts SET is_linked = 0, is_primary = 0 WHERE id = ?")->execute([$id]);
            if ($acct['is_primary']) {
                // promote the oldest remaining account
                db()->prepare(
                    "UPDATE rbnw_bank_accounts SET is_primary = 1 WHERE user_id = ? AND is_linked = 1 ORDER BY id LIMIT 1"
                )->execute([$userId]);
            }
        }
        json_out(['success' => true]);
        break;

    case 'transfer':
        $id      = (int)($input['account_id'] ?? 0);
        $amount  = round((float)($input['amount'] ?? 0), 2);
        $channel = ($input['channel'] ?? 'instapay') === 'pesonet' ? 'pesonet' : 'instapay';
        $mpin    = trim((string)($input['mpin'] ?? ''));

        $user = get_user($userId);
        if ($mpin === '' || empty($user['mpin_hash']) || !password_verify($mpin, $user['mpin_hash'])) {
            json_out(['success' => false, 'message' => 'Incorrect MPIN.']);
        }
        $stmt = db()->prepare("SELECT * FROM rbnw_bank_accounts WHERE id = ? AND user_id = ? AND is_linked = 1");
        $stmt->execute([$id, $userId]);
        $acct = $stmt->fetch();
        if (!$acct) {
            json_out(['success' => false, 'message' => 'Choose a linked account.']);
        }
        $q = bank_channel_quote($acct['bank_code'], $channel, $amount);
        if ($amount < BANK_TRANSFER_MIN) {
            json_out(['success' => false, 'message' => 'Minimum transfer is ' . peso(BANK_TRANSFER_MIN) . '.']);
        }
        if ($amount > $q['max']) {
            json_out(['success' => false, 'message' => 'Maximum per transfer via this channel is ' . peso($q['max']) . '.']);
        }

        $ref    = generate_reference('BT');
        $wallet = get_wallet($userId);
        $pdo    = db();
        try {
            $pdo->beginTransaction();
            post_wallet_transaction((int)$wallet['id'], 'debit', $amount, 'bank_transfer', [
                'reference_no'        => $ref,
                'fee'                 => $q['fee'],
                'counterparty_name'   => $acct['bank_name'],
                'counterparty_mobile' => null,
                'note'                => strtoupper($q['channel']) . " to {$acct['account_name']} {$acct['account_number_masked']}",
            ]);
            $pdo->prepare(
                "INSERT INTO rbnw_bank_transfers
                    (reference_no, user_id, bank_account_id, bank_name, account_name, account_number_masked, channel, amount, fee)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            )->execute([$ref, $userId, $acct['id'], $acct['bank_name'], $acct['account_name'], $acct['account_number_masked'],
                        $q['channel'], $amount, $q['fee']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = $e instanceof RuntimeException ? $e->getMessage() : 'Transfer failed. Please try again.';
            json_out(['success' => false, 'message' => $msg === 'Insufficient balance.' ? 'Not enough wallet balance to cover the amount and fee.' : $msg]);
        }

        if (BANK_TRANSFER_AUTO_COMPLETE) {
            bank_transfer_settle($ref, 'completed');
        }

        json_out([
            'success'      => true,
            'reference_no' => $ref,
            'amount'       => $amount,
            'fee'          => $q['fee'],
            'eta'          => $q['eta'],
            'status'       => BANK_TRANSFER_AUTO_COMPLETE ? 'completed' : 'processing',
            'to'           => $acct['bank_name'] . ' ' . $acct['account_number_masked'],
        ]);
        break;

    default:
        json_out(['success' => false, 'message' => 'Unknown action.'], 404);
}
