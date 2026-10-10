<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_post()) redirect('index.php');
csrf_check();

$post = q_one('SELECT * FROM posts WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
if ($post && ((int) $post['user_id'] === uid() || is_admin())) {
    q('DELETE FROM posts WHERE id = ?', [$post['id']]);
    if ($post['media_path'] && is_file(__DIR__ . '/' . $post['media_path'])) {
        unlink(__DIR__ . '/' . $post['media_path']);
    }
    flash('success', 'Post deleted.');
}
redirect('posts.php');
