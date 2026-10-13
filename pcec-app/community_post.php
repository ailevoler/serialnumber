<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$post = fetch_posts('p.id = ?', [$id], 1)[0] ?? null;
if (!$post) {
    flash('error', 'This post is no longer available.');
    redirect('community.php');
}
$comments = q_all('SELECT c.*, u.first_name, u.last_name, u.title, u.username, u.avatar FROM comments c
                   JOIN users u ON u.id = c.user_id WHERE c.post_id = ? AND ' . not_blocked_sql('c.user_id') . ' ORDER BY c.created_at ASC LIMIT 200', [$id]);

$pageTitle = 'Post';
$activeNav = 'community';
$rightRail = right_rail();
require __DIR__ . '/includes/header.php';

render_post($post, true);
?>
<section class="card comments" id="comments">
  <h2 class="card-title">Comments (<?= count($comments) ?>)</h2>
  <?php foreach ($comments as $c): ?>
    <div class="comment">
      <a href="<?= e(url('community_user.php?id=' . $c['user_id'])) ?>"><?= avatar($c, 'sm') ?></a>
      <div class="comment-bubble">
        <a class="comment-author" href="<?= e(url('community_user.php?id=' . $c['user_id'])) ?>"><?= e(display_name($c)) ?></a>
        <div><?= rich_text($c['body']) ?></div>
        <small><?= e(time_ago($c['created_at'])) ?>
          <?php if ((int) $c['user_id'] === uid() || is_admin()): ?>
            · <form method="post" action="<?= e(url('community_action.php')) ?>" class="inline-form" data-confirm="Delete this comment?"><?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_comment"><input type="hidden" name="comment" value="<?= (int) $c['id'] ?>">
                <button class="link danger-link">Delete</button></form>
          <?php endif; ?>
        </small>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$comments): ?><p class="muted">No comments yet. Be the first to encourage them.</p><?php endif; ?>
  <form method="post" action="<?= e(url('community_action.php')) ?>" class="comment-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="comment"><input type="hidden" name="id" value="<?= $id ?>">
    <?= avatar(current_user(), 'sm') ?>
    <input name="body" placeholder="Write a comment…" maxlength="1000" required>
    <button class="icon-btn primary" aria-label="Send comment"><?= icon('send') ?></button>
  </form>
</section>
<?= report_modal() ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
