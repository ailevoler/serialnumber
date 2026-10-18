<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$userId = current_user_id();

switch ($action) {

    case 'balance':
        $wallet = get_wallet($userId);
        if (!$wallet) {
            json_out(['success' => false, 'message' => 'Wallet not found.'], 404);
        }
        json_out([
            'success' => true,
            'balance' => (float)$wallet['balance'],
            'balance_formatted' => peso((float)$wallet['balance']),
        ]);
        break;

    case 'transactions':
        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $wallet = get_wallet($userId);
        if (!$wallet) {
            json_out(['success' => false, 'message' => 'Wallet not found.'], 404);
        }

        $stmt = db()->prepare(
            "SELECT * FROM rbnw_wallet_transactions WHERE wallet_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $wallet['id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $items = array_map(function ($tx) {
            $meta = tx_display_meta($tx['type']);
            return [
                'reference_no' => $tx['reference_no'],
                'type' => $tx['type'],
                'label' => ucwords(str_replace('_', ' ', $tx['type'])),
                'counterparty_name' => $tx['counterparty_name'],
                'amount' => (float)$tx['amount'],
                'amount_formatted' => peso((float)$tx['amount']),
                'sign' => $meta['sign'],
                'class' => $meta['class'],
                'status' => $tx['status'],
                'note' => $tx['note'],
                'created_at' => $tx['created_at'],
                'time_ago' => time_ago_ph($tx['created_at']),
            ];
        }, $rows);

        json_out(['success' => true, 'items' => $items, 'page' => $page]);
        break;

    default:
        json_out(['success' => false, 'message' => 'Unknown action.'], 404);
}
