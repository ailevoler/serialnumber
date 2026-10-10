<?php
// GET  ?c=<conversation>&after=<last message id>  →  new messages (used for polling)
// POST c=<conversation>, body=<text>              →  send a message
require __DIR__ . '/../includes/bootstrap.php';
require_login();
$me = uid();
$cid = (int) ($_REQUEST['c'] ?? 0);

if (!q_val('SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?', [$cid, $me])) {
    json_out(['error' => 'Conversation not found'], 404);
}

if (is_post()) {
    csrf_check();
    $body = trim($_POST['body'] ?? '');
    if ($body === '') json_out(['error' => 'Empty message'], 400);
    q('INSERT INTO messages (conversation_id, user_id, body) VALUES (?,?,?)', [$cid, $me, mb_substr($body, 0, 2000)]);
    q('UPDATE conversations SET updated_at = NOW() WHERE id = ?', [$cid]);
    // Notify the other member only if they have no unread message notification pending yet.
    foreach (q_all('SELECT user_id FROM conversation_members WHERE conversation_id = ? AND user_id <> ?', [$cid, $me]) as $m) {
        if (!q_val("SELECT 1 FROM notifications WHERE user_id = ? AND actor_id = ? AND type = 'message' AND is_read = 0", [$m['user_id'], $me])) {
            notify((int) $m['user_id'], $me, 'message', display_name(current_user()) . ' sent you a message.', 'chat.php?c=' . $cid);
        }
    }
}

$after = (int) ($_GET['after'] ?? $_POST['after'] ?? 0);
$rows = q_all('SELECT m.id, m.user_id, m.body, m.created_at FROM messages m WHERE m.conversation_id = ? AND m.id > ? ORDER BY m.id ASC LIMIT 200', [$cid, $after]);
if ($rows) {
    q('UPDATE conversation_members SET last_read_id = GREATEST(last_read_id, ?) WHERE conversation_id = ? AND user_id = ?', [end($rows)['id'], $cid, $me]);
}
json_out(['messages' => array_map(fn($r) => [
    'id' => (int) $r['id'],
    'mine' => (int) $r['user_id'] === $me,
    'body' => $r['body'],
    'time' => date('M j, g:i A', strtotime($r['created_at'])),
], $rows)]);
