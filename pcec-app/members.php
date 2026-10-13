<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
require_login();

$search = trim((string) ($_GET['q'] ?? ''));
$where = "u.id <> ? AND u.status = 'approved' AND " . not_blocked_sql('u.id');
$params = [uid()];
if ($search !== '') {
    $where .= " AND (CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.church_name LIKE ?)";
    array_push($params, "%$search%", "%$search%");
}
$members = q_all("SELECT u.*, (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id AND p.hidden_at IS NULL) AS posts
                  FROM users u WHERE $where ORDER BY u.first_name, u.last_name LIMIT 200", $params);

$pageTitle = 'Members';
$activeNav = 'members';
require __DIR__ . '/includes/header.php';
?>
<header class="page-intro"><h2>Members</h2><p>Find fellow members and send them a message</p></header>
<form class="search-bar" method="get">
  <?= icon('search') ?>
  <input name="q" value="<?= e($search) ?>" placeholder="Search by name or church…" aria-label="Search members">
</form>
<div class="grid-cards members-grid">
  <?php foreach ($members as $m): ?>
    <article class="card member-card">
      <a href="<?= e(url('community_user.php?id=' . $m['id'])) ?>"><?= avatar($m, 'xl') ?></a>
      <a class="member-name" href="<?= e(url('community_user.php?id=' . $m['id'])) ?>"><?= e(display_name($m)) ?></a>
      <small class="muted"><?= e($m['church_name'] ?: 'PCEC Member') ?></small>
      <?php if ($m['role'] === 'admin'): ?><span class="tag tag-soft">PCEC Office</span><?php endif; ?>
      <div class="member-actions">
        <a class="btn btn-sm btn-gradient" href="<?= e(url('chat.php?to=' . $m['id'])) ?>"><?= icon('chat') ?> Message</a>
        <a class="btn btn-sm btn-outline" href="<?= e(url('community_user.php?id=' . $m['id'])) ?>"><?= (int) $m['posts'] ?> posts</a>
      </div>
    </article>
  <?php endforeach; ?>
</div>
<?php if (!$members) empty_state('users', $search ? 'No matching members' : 'No other members yet', $search ? 'Try another name or church.' : ''); ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
