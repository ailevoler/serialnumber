<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'list';
$userId = current_user_id();

switch ($action) {

    case 'list':
        $stmt = db()->prepare("SELECT * FROM rbnw_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 30");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();

        $items = array_map(fn($n) => [
            'id' => (int)$n['id'],
            'title' => $n['title'],
            'message' => $n['message'],
            'type' => $n['type'],
            'is_read' => (bool)$n['is_read'],
            'time_ago' => time_ago_ph($n['created_at']),
        ], $rows);

        json_out(['success' => true, 'items' => $items]);
        break;

    case 'mark_read':
        db()->prepare("UPDATE rbnw_notifications SET is_read = 1 WHERE user_id = ?")->execute([$userId]);
        json_out(['success' => true]);
        break;

    default:
        json_out(['success' => false, 'message' => 'Unknown action.'], 404);
}
