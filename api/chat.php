<?php
/**
 * Live Chat JSON API (polling).
 * Member: GET action=poll&after=ID · GET action=unread · POST action=send (body)
 * Admin:  GET action=poll&thread=ID&after=ID · GET action=inbox · POST action=send (thread, body)
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$u = current_user();
if (!$u) {
    out(['error' => 'Please log in again.'], 401);
}
if (!chat_enabled() && $u['role'] === 'member') {
    out(['error' => 'Live chat is currently unavailable.'], 403);
}
$uid = (int)$u['id'];
$action = input('action');

try {
    if (is_post()) {
        $token = $_POST['_csrf'] ?? '';
        if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
            out(['error' => 'Session expired. Please refresh the page.'], 419);
        }
    }

    if ($u['role'] === 'member') {
        if ($action === 'unread') {
            out(['unread' => chat_unread_member($uid)]);
        }
        if ($action === 'send' && is_post()) {
            $t = chat_member_thread($uid, true);
            chat_send((int)$t['id'], $uid, 'member', (string)($_POST['body'] ?? ''));
            $action = 'poll';
        }
        if ($action === 'poll') {
            $t = chat_member_thread($uid, false);
            if (!$t) {
                out(['thread' => null, 'messages' => [], 'unread' => 0]);
            }
            chat_mark_read((int)$t['id'], 'member');
            out(['thread' => (int)$t['id'], 'status' => $t['status'], 'messages' => chat_messages((int)$t['id'], input_int('after')), 'unread' => 0]);
        }
    } elseif ($u['role'] === 'admin') {
        if ($action === 'inbox') {
            $rows = q_all("SELECT t.id, t.status, t.unread_admin, t.last_message_at, u.username, u.first_name, u.last_name,
                           (SELECT body FROM chat_messages m WHERE m.thread_id = t.id ORDER BY m.id DESC LIMIT 1) last_body
                           FROM chat_threads t JOIN users u ON u.id = t.user_id WHERE t.status = ? ORDER BY t.last_message_at DESC LIMIT 100",
                [input('status') === 'closed' ? 'closed' : 'open']);
            out(['unread_threads' => chat_unread_threads_admin(), 'threads' => array_map(fn($r) => [
                'id' => (int)$r['id'], 'name' => full_name($r), 'username' => $r['username'], 'unread' => (int)$r['unread_admin'],
                'last' => mb_substr((string)$r['last_body'], 0, 80), 'time' => date('M d, h:i A', strtotime($r['last_message_at'])),
            ], $rows)]);
        }
        $threadId = input_int('thread');
        $t = q_row('SELECT * FROM chat_threads WHERE id = ?', [$threadId]);
        if (!$t) {
            out(['error' => 'Conversation not found.'], 404);
        }
        if ($action === 'send' && is_post()) {
            chat_send($threadId, $uid, 'admin', (string)($_POST['body'] ?? ''));
            $action = 'poll';
        }
        if ($action === 'poll') {
            chat_mark_read($threadId, 'admin');
            out(['thread' => $threadId, 'status' => $t['status'], 'messages' => chat_messages($threadId, input_int('after'))]);
        }
    }
    out(['error' => 'Unknown action.'], 400);
} catch (RuntimeException $ex) {
    out(['error' => $ex->getMessage()], 422);
}
