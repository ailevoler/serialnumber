<?php
// A member's posts ("My Posts" when it's you).
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
require_login();

$id = (int) ($_GET['id'] ?? uid());
$member = q_one('SELECT * FROM users WHERE id = ?', [$id]);
if (!$member) {
    flash('error', 'Member not found.');
    redirect('community.php');
}
$isMe = $id === uid();
$iBlocked = !$isMe && q_val('SELECT 1 FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?', [uid(), $id]);
$posts = $iBlocked ? [] : fetch_posts('p.user_id = ?', [$id], 50);
$total = (int) q_val('SELECT COUNT(*) FROM posts WHERE user_id = ? AND hidden_at IS NULL', [$id]);

$pageTitle = $isMe ? 'My Posts' : 'Posts';
$activeNav = $isMe ? 'profile' : 'community';
require __DIR__ . '/includes/header.php';
?>
<section class="card wall-head">
  <?= avatar($member, 'xl') ?>
  <div class="wall-who">
    <h2><?= e(display_name($member)) ?></h2>
    <p class="muted"><?= $member['church_name'] ? e($member['church_name']) . ' · ' : '' ?><?= $total === 1 ? '1 post' : $total . ' posts' ?></p>
  </div>
  <?php if (!$isMe): ?>
    <div class="wall-actions">
      <a class="btn btn-sm btn-outline" href="<?= e(url('chat.php?to=' . $id)) ?>"><?= icon('chat') ?> Message</a>
      <form method="post" action="<?= e(url('community_action.php')) ?>" <?= $iBlocked ? '' : 'data-confirm="Block ' . e($member['first_name']) . '? You won\'t see their posts or comments, and they won\'t be able to comment on or react to your posts."' ?>>
        <?= csrf_field() ?><input type="hidden" name="action" value="<?= $iBlocked ? 'unblock' : 'block' ?>"><input type="hidden" name="user" value="<?= $id ?>">
        <button class="btn btn-sm <?= $iBlocked ? 'btn-gradient' : 'btn-outline' ?>"><?= icon($iBlocked ? 'check' : 'x') ?> <?= $iBlocked ? 'Unblock' : 'Block' ?></button>
      </form>
    </div>
  <?php endif; ?>
</section>
<?php if ($iBlocked): ?>
  <?php empty_state('x', 'You blocked ' . $member['first_name'], 'Unblock to see their posts again.'); ?>
<?php else: ?>
  <?php foreach ($posts as $p) render_post($p); ?>
  <?php if (!$posts) empty_state('comment', $isMe ? 'You haven’t posted yet' : 'No posts yet', $isMe ? 'Share a prayer request, a testimony or an encouraging word.' : ''); ?>
<?php endif; ?>
<?= report_modal() ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
