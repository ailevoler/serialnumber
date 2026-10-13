<?php
// Form actions for the Community Feed: create/delete posts, comments, report, block, hide (admin).
require __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_post()) redirect('community.php');
csrf_check();
$me = uid();
$action = (string) ($_POST['action'] ?? '');

function go_back(string $fallback = 'community.php'): never
{
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref !== '' && parse_url($ref, PHP_URL_HOST) === parse_url(abs_url(''), PHP_URL_HOST)) {
        header('Location: ' . $ref);
        exit;
    }
    redirect($fallback);
}

/** True if either member blocked the other. */
function blocked_between(int $a, int $b): bool
{
    return (bool) q_val('SELECT 1 FROM user_blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?)', [$a, $b, $b, $a]);
}

$post = isset($_POST['id']) ? q_one('SELECT * FROM posts WHERE id = ?', [(int) $_POST['id']]) : null;
if ($post && $post['hidden_at'] && !is_admin()) $post = null;

switch ($action) {
    case 'create':
        $body = trim((string) ($_POST['body'] ?? ''));
        if (mb_strlen($body) > 2000) { flash('error', 'Posts can be up to 2,000 characters.'); go_back(); }
        if ((int) q_val('SELECT COUNT(*) FROM posts WHERE user_id = ? AND created_at > NOW() - INTERVAL 10 MINUTE', [$me]) >= 5) {
            flash('error', 'You can share up to 5 posts every 10 minutes. Please try again in a little while.');
            go_back();
        }
        try {
            $media = handle_upload('media', 'posts', ['image']);
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            go_back();
        }
        if ($body === '' && !$media) { flash('error', 'Write something or add a photo before posting.'); go_back(); }
        q('INSERT INTO posts (user_id, body, media_path, media_type, media_name) VALUES (?,?,?,?,?)',
            [$me, $body ?: null, $media[0] ?? null, $media ? 'image' : null, $media[2] ?? null]);
        flash('success', 'Posted.');
        redirect('community.php');

    case 'delete':
        if ($post && ((int) $post['user_id'] === $me || is_admin())) {
            q('DELETE FROM posts WHERE id = ?', [$post['id']]);
            if ($post['media_path'] && str_starts_with($post['media_path'], 'uploads/') && is_file(__DIR__ . '/' . $post['media_path'])) {
                @unlink(__DIR__ . '/' . $post['media_path']);
            }
            flash('success', 'Post deleted.');
        }
        if (str_contains((string) ($_SERVER['HTTP_REFERER'] ?? ''), 'community_post.php')) redirect('community.php');
        go_back();

    case 'hide':
    case 'unhide':
        if ($post && is_admin()) {
            q('UPDATE posts SET hidden_at = ' . ($action === 'hide' ? 'NOW()' : 'NULL') . ', hidden_by = ? WHERE id = ?', [$action === 'hide' ? $me : null, $post['id']]);
            if ($action === 'hide') q('UPDATE post_reports SET resolved_at = NOW(), resolved_by = ? WHERE post_id = ? AND resolved_at IS NULL', [$me, $post['id']]);
            flash('success', $action === 'hide' ? 'Post hidden. Members no longer see it.' : 'Post is visible again.');
        }
        go_back();

    case 'report':
        if ($post && (int) $post['user_id'] !== $me) {
            $done = q('INSERT IGNORE INTO post_reports (post_id, reporter_id, reason) VALUES (?,?,?)',
                [$post['id'], $me, mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 500)])->rowCount();
            if ($done) {
                foreach (q_all("SELECT id FROM users WHERE role = 'admin'") as $a) {
                    notify((int) $a['id'], null, 'report', 'A community post was reported and needs review.', 'admin/community.php');
                }
            }
            flash('success', $done ? 'Thank you. An admin will review it.' : 'You already reported this post. Thank you.');
        }
        go_back();

    case 'block':
    case 'unblock':
        $other = (int) ($_POST['user'] ?? 0);
        $u = $other && $other !== $me ? q_one('SELECT id, first_name FROM users WHERE id = ?', [$other]) : null;
        if ($u) {
            if ($action === 'block') {
                q('INSERT IGNORE INTO user_blocks (blocker_id, blocked_id) VALUES (?,?)', [$me, $other]);
                flash('success', $u['first_name'] . ' is blocked.');
            } else {
                q('DELETE FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?', [$me, $other]);
                flash('success', $u['first_name'] . ' is unblocked.');
            }
        }
        if ($action === 'block' && str_contains((string) ($_SERVER['HTTP_REFERER'] ?? ''), 'community_')) redirect('community.php');
        go_back();

    case 'comment':
        $body = trim((string) ($_POST['body'] ?? ''));
        if (!$post) go_back();
        if ($body === '' || mb_strlen($body) > 1000) { flash('error', 'Comments must be 1 to 1,000 characters.'); go_back(); }
        if (blocked_between($me, (int) $post['user_id'])) { flash('error', 'You can’t comment on this post.'); go_back(); }
        if ((int) q_val('SELECT COUNT(*) FROM comments WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 MINUTE', [$me]) >= 10) {
            flash('error', 'You’re commenting very quickly. Please wait a moment.');
            go_back();
        }
        q('INSERT INTO comments (post_id, user_id, body) VALUES (?,?,?)', [$post['id'], $me, $body]);
        $snippet = mb_strimwidth((string) ($post['body'] ?: 'your photo'), 0, 40, '…');
        notify((int) $post['user_id'], $me, 'comment', display_name(current_user()) . ' commented on your post “' . $snippet . '”', 'community_post.php?id=' . $post['id']);
        redirect('community_post.php?id=' . $post['id'] . '#comments');

    case 'delete_comment':
        $c = q_one('SELECT * FROM comments WHERE id = ?', [(int) ($_POST['comment'] ?? 0)]);
        if ($c && ((int) $c['user_id'] === $me || is_admin())) {
            q('DELETE FROM comments WHERE id = ?', [$c['id']]);
            flash('success', 'Comment deleted.');
        }
        go_back();
}
redirect('community.php');
