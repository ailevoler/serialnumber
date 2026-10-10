<?php
// POST id=<user id>  →  toggles following that member.
require __DIR__ . '/../includes/bootstrap.php';
require_login();
if (!is_post()) json_out(['error' => 'POST required'], 405);
csrf_check();

$id = (int) ($_POST['id'] ?? 0);
$me = uid();
if ($id === $me || !q_val('SELECT 1 FROM users WHERE id = ?', [$id])) json_out(['error' => 'Invalid member'], 400);

if (q_val('SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?', [$me, $id])) {
    q('DELETE FROM follows WHERE follower_id = ? AND following_id = ?', [$me, $id]);
    $on = false;
} else {
    q('INSERT INTO follows (follower_id, following_id) VALUES (?,?)', [$me, $id]);
    notify($id, $me, 'follow', display_name(current_user()) . ' started following you.', 'profile.php?u=' . current_user()['username']);
    $on = true;
}
json_out(['on' => $on, 'followers' => (int) q_val('SELECT COUNT(*) FROM follows WHERE following_id = ?', [$id])]);
