<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_post()) redirect('index.php');
csrf_check();

$body = trim($_POST['body'] ?? '');
try {
    $media = handle_upload('media', 'posts');
} catch (RuntimeException $e) {
    flash('error', $e->getMessage());
    redirect('index.php');
}
if ($body === '' && !$media) {
    flash('error', 'Write something or attach a file before posting.');
    redirect('index.php');
}
q('INSERT INTO posts (user_id, body, media_path, media_type, media_name) VALUES (?,?,?,?,?)',
    [uid(), mb_substr($body, 0, 5000) ?: null, $media[0] ?? null, $media[1] ?? null, $media[2] ?? null]);

// Let followers know.
$name = display_name(current_user());
$postId = (int) db()->lastInsertId();
foreach (q_all('SELECT follower_id FROM follows WHERE following_id = ?', [uid()]) as $f) {
    notify((int) $f['follower_id'], uid(), 'post', "$name shared a new post.", 'post.php?id=' . $postId);
}
flash('success', 'Your post has been shared.');
redirect('index.php');
