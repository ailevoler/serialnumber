<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);

if (is_post()) {
    csrf_check();
    $body = trim($_POST['body'] ?? '');
    $post = q_one('SELECT id, user_id FROM posts WHERE id = ?', [$id]);
    if ($post && $body !== '') {
        q('INSERT INTO comments (post_id, user_id, body) VALUES (?,?,?)', [$id, uid(), mb_substr($body, 0, 2000)]);
        notify((int) $post['user_id'], uid(), 'comment', display_name(current_user()) . ' commented on your post.', 'post.php?id=' . $id);
    }
    redirect('post.php?id=' . $id . '#comments');
}

$post = fetch_posts('p.id = ?', [$id], 1)[0] ?? null;
if (!$post) {
    flash('error', 'Post not found.');
    redirect('posts.php');
}
$comments = q_all('SELECT c.*, u.first_name, u.last_name, u.title, u.username, u.avatar FROM comments c
                   JOIN users u ON u.id = c.user_id WHERE c.post_id = ? ORDER BY c.created_at ASC', [$id]);

$pageTitle = 'Post';
$activeNav = 'posts';
$rightRail = right_rail();
require __DIR__ . '/includes/header.php';

render_post($post, true);
?>
<section class="card comments" id="comments">
  <h2 class="card-title">Comments (<?= count($comments) ?>)</h2>
  <?php foreach ($comments as $c): ?>
    <div class="comment">
      <a href="<?= e(url('profile.php?u=' . $c['username'])) ?>"><?= avatar($c, 'sm') ?></a>
      <div class="comment-bubble">
        <a class="comment-author" href="<?= e(url('profile.php?u=' . $c['username'])) ?>"><?= e(display_name($c)) ?></a>
        <div><?= rich_text($c['body']) ?></div>
        <small><?= e(time_ago($c['created_at'])) ?></small>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$comments): ?><p class="muted">No comments yet.</p><?php endif; ?>
  <form method="post" class="comment-form">
    <?= csrf_field() ?>
    <?= avatar(current_user(), 'sm') ?>
    <input name="body" placeholder="Write a comment…" maxlength="2000" required>
    <button class="icon-btn primary" aria-label="Send"><?= icon('send') ?></button>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
