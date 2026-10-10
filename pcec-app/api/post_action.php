<?php
// POST action=like|save|share, id=<post id>  →  JSON with the new state.
require __DIR__ . '/../includes/bootstrap.php';
require_login();
if (!is_post()) json_out(['error' => 'POST required'], 405);
csrf_check();

$id = (int) ($_POST['id'] ?? 0);
$post = q_one('SELECT id, user_id FROM posts WHERE id = ?', [$id]);
if (!$post) json_out(['error' => 'Post not found'], 404);
$me = uid();

switch ($_POST['action'] ?? '') {
    case 'like':
        if (q_val('SELECT 1 FROM post_likes WHERE post_id = ? AND user_id = ?', [$id, $me])) {
            q('DELETE FROM post_likes WHERE post_id = ? AND user_id = ?', [$id, $me]);
            $on = false;
        } else {
            q('INSERT INTO post_likes (post_id, user_id) VALUES (?,?)', [$id, $me]);
            notify((int) $post['user_id'], $me, 'like', display_name(current_user()) . ' liked your post.', 'post.php?id=' . $id);
            $on = true;
        }
        json_out(['on' => $on, 'count' => (int) q_val('SELECT COUNT(*) FROM post_likes WHERE post_id = ?', [$id])]);
    case 'save':
        if (q_val('SELECT 1 FROM bookmarks WHERE post_id = ? AND user_id = ?', [$id, $me])) {
            q('DELETE FROM bookmarks WHERE post_id = ? AND user_id = ?', [$id, $me]);
            json_out(['on' => false]);
        }
        q('INSERT INTO bookmarks (post_id, user_id) VALUES (?,?)', [$id, $me]);
        json_out(['on' => true]);
    case 'share':
        q('UPDATE posts SET share_count = share_count + 1 WHERE id = ?', [$id]);
        json_out(['count' => (int) q_val('SELECT share_count FROM posts WHERE id = ?', [$id])]);
}
json_out(['error' => 'Unknown action'], 400);
