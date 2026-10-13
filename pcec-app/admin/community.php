<?php
require __DIR__ . '/_admin.php';

if (is_post()) {
    csrf_check();
    $post = q_one('SELECT * FROM posts WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
    if ($post) {
        switch ($_POST['action'] ?? '') {
            case 'hide':
                q('UPDATE posts SET hidden_at = NOW(), hidden_by = ? WHERE id = ?', [uid(), $post['id']]);
                q('UPDATE post_reports SET resolved_at = NOW(), resolved_by = ? WHERE post_id = ? AND resolved_at IS NULL', [uid(), $post['id']]);
                flash('success', 'Post hidden.');
                break;
            case 'unhide':
                q('UPDATE posts SET hidden_at = NULL, hidden_by = NULL WHERE id = ?', [$post['id']]);
                flash('success', 'Post is visible again.');
                break;
            case 'dismiss':
                q('UPDATE post_reports SET resolved_at = NOW(), resolved_by = ? WHERE post_id = ? AND resolved_at IS NULL', [uid(), $post['id']]);
                flash('info', 'Reports dismissed. The post stays up.');
                break;
            case 'delete':
                q('DELETE FROM posts WHERE id = ?', [$post['id']]);
                if ($post['media_path'] && str_starts_with($post['media_path'], 'uploads/') && is_file(__DIR__ . '/../' . $post['media_path'])) @unlink(__DIR__ . '/../' . $post['media_path']);
                flash('info', 'Post deleted.');
                break;
        }
    }
    redirect('admin/community.php?' . http_build_query(array_intersect_key($_GET, ['show' => 1])));
}

$show = ($_GET['show'] ?? '') === 'hidden' ? 'hidden' : 'reported';
$sql = "SELECT p.*, u.first_name, u.last_name, u.title, u.username, u.avatar,
          (SELECT COUNT(*) FROM post_reports r WHERE r.post_id = p.id AND r.resolved_at IS NULL) AS open_reports,
          (SELECT GROUP_CONCAT(NULLIF(r.reason, '') SEPARATOR ' | ') FROM post_reports r WHERE r.post_id = p.id AND r.resolved_at IS NULL) AS reasons
        FROM posts p JOIN users u ON u.id = p.user_id WHERE " .
       ($show === 'hidden' ? 'p.hidden_at IS NOT NULL' : 'EXISTS (SELECT 1 FROM post_reports r WHERE r.post_id = p.id AND r.resolved_at IS NULL)') .
       ' ORDER BY p.created_at DESC LIMIT 100';
$rows = q_all($sql);

$pageTitle = 'Admin';
$activeNav = 'admin';
require __DIR__ . '/../includes/header.php';
admin_tabs('community');
?>
<div class="admin-wrap">
  <header class="dash-header"><div><h2>Community</h2><p>Review reported posts. We aim to review reports within 48 hours.</p></div></header>
  <nav class="tabs-pill"><a href="?show=reported" class="<?= $show === 'reported' ? 'on' : '' ?>">REPORTED</a><a href="?show=hidden" class="<?= $show === 'hidden' ? 'on' : '' ?>">HIDDEN</a></nav>
  <div class="admin-list">
    <?php foreach ($rows as $p): ?>
      <article class="card admin-item">
        <div class="member-top">
          <?= avatar($p, 'md') ?>
          <div class="member-info">
            <strong><?= e(display_name($p)) ?></strong>
            <small><?= e(date('M j, Y g:i A', strtotime($p['created_at']))) ?> · <a class="link" href="<?= e(url('community_post.php?id=' . $p['id'])) ?>">Open post</a></small>
            <?php if ($p['body']): ?><p class="pre-line inbox-msg"><?= e(mb_strimwidth($p['body'], 0, 400, '…')) ?></p><?php endif; ?>
            <?php if ($p['media_path']): ?><img class="mod-img" src="<?= e(url($p['media_path'])) ?>" alt=""><?php endif; ?>
            <?php if ($p['open_reports']): ?><small class="email-no"><?= icon('shield') ?> <?= (int) $p['open_reports'] ?> report<?= $p['open_reports'] > 1 ? 's' : '' ?><?= $p['reasons'] ? ': ' . e($p['reasons']) : '' ?></small><?php endif; ?>
          </div>
          <?= $p['hidden_at'] ? '<span class="status status-cancelled">Hidden</span>' : '<span class="status status-pending">Visible</span>' ?>
        </div>
        <div class="admin-item-actions">
          <?php $btn = fn($a, $l, $c, $conf = '') => '<form method="post"' . ($conf ? ' data-confirm="' . e($conf) . '"' : '') . '>' . csrf_field() . '<input type="hidden" name="id" value="' . (int) $p['id'] . '"><input type="hidden" name="action" value="' . $a . '"><button class="btn btn-sm ' . $c . '">' . $l . '</button></form>'; ?>
          <?= $p['hidden_at'] ? $btn('unhide', 'Show again', 'btn-outline') : $btn('hide', 'Hide post', 'btn-gradient') ?>
          <?php if ($p['open_reports']): ?><?= $btn('dismiss', 'Dismiss reports', 'btn-outline') ?><?php endif; ?>
          <?= $btn('delete', 'Delete', 'btn-danger-outline', 'Delete this post? The post, its photo, reactions and comments are removed for good.') ?>
          <a class="btn btn-sm btn-outline" href="<?= e(url('admin/members.php?show=all&q=' . urlencode($p['first_name'] . ' ' . $p['last_name']))) ?>">Member…</a>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
  <?php if (!$rows) empty_state('check', $show === 'hidden' ? 'No hidden posts' : 'No open reports', $show === 'hidden' ? '' : 'Reported posts will appear here.'); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
