<?php
// POST id=<post>, kind=love|pray|amen  →  toggles or switches my reaction.
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/partials.php';
require_login();
if (!is_post()) json_out(['error' => 'POST required'], 405);
csrf_check();

$me = uid();
$kind = (string) ($_POST['kind'] ?? '');
$post = q_one('SELECT id, user_id, body, hidden_at FROM posts WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
if (!$post || ($post['hidden_at'] && !is_admin()) || !isset(REACTIONS[$kind])) json_out(['error' => 'Post not found'], 404);
if (q_val('SELECT 1 FROM user_blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?)', [$me, $post['user_id'], $post['user_id'], $me])) {
    json_out(['error' => 'You can’t react to this post.'], 403);
}

$current = q_val('SELECT kind FROM post_reactions WHERE post_id = ? AND user_id = ?', [$post['id'], $me]);
if ($current === $kind) {
    q('DELETE FROM post_reactions WHERE post_id = ? AND user_id = ?', [$post['id'], $me]);
    $mine = null;
} else {
    q('INSERT INTO post_reactions (post_id, user_id, kind) VALUES (?,?,?) ON DUPLICATE KEY UPDATE kind = VALUES(kind), created_at = NOW()', [$post['id'], $me, $kind]);
    $mine = $kind;
    if (!$current) {
        $snippet = mb_strimwidth((string) ($post['body'] ?: 'your photo'), 0, 40, '…');
        notify((int) $post['user_id'], $me, 'reaction', display_name(current_user()) . ' reacted ' . REACTIONS[$kind][0] . ' to your post “' . $snippet . '”', 'community_post.php?id=' . $post['id']);
    }
}
$row = q_one('SELECT COUNT(*) AS reactions, GROUP_CONCAT(DISTINCT kind) AS reaction_kinds FROM post_reactions WHERE post_id = ?', [$post['id']]);
json_out(['mine' => $mine, 'summary' => reaction_summary($row)]);
