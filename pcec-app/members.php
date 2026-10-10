<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
require_login();

$search = trim($_GET['q'] ?? '');
$church = (int) ($_GET['church'] ?? 0);
$filter = $_GET['filter'] ?? 'all';
$me = uid();

$where = 'u.id <> ?';
$params = [$me];
if ($search !== '') {
    $where .= " AND (CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.username LIKE ?)";
    array_push($params, "%$search%", "%$search%");
}
if ($church) {
    $where .= ' AND u.church_id = ?';
    $params[] = $church;
}
if ($filter === 'following') {
    $where .= " AND u.id IN (SELECT following_id FROM follows WHERE follower_id = $me)";
} elseif ($filter === 'followers') {
    $where .= " AND u.id IN (SELECT follower_id FROM follows WHERE following_id = $me)";
} elseif ($filter === 'suggested') {
    $where .= " AND u.id NOT IN (SELECT following_id FROM follows WHERE follower_id = $me)";
}
$members = q_all("SELECT u.*, c.name AS church_name,
    EXISTS(SELECT 1 FROM follows f WHERE f.follower_id = $me AND f.following_id = u.id) AS is_following,
    (SELECT COUNT(*) FROM follows f WHERE f.following_id = u.id) AS followers
  FROM users u LEFT JOIN churches c ON c.id = u.church_id WHERE $where ORDER BY u.role = 'member', u.first_name LIMIT 100", $params);
$churchName = $church ? q_val('SELECT name FROM churches WHERE id = ?', [$church]) : null;

$pageTitle = 'Members';
$activeNav = 'members';
require __DIR__ . '/includes/header.php';
?>
<form class="search-bar" method="get">
  <?= icon('search') ?>
  <input name="q" value="<?= e($search) ?>" placeholder="Search members…">
  <?php if ($church): ?><input type="hidden" name="church" value="<?= $church ?>"><?php endif; ?>
  <input type="hidden" name="filter" value="<?= e($filter) ?>">
</form>
<div class="chips">
  <?php foreach (['all' => 'All', 'following' => 'Following', 'followers' => 'Followers', 'suggested' => 'Suggested'] as $k => $label): ?>
    <a href="?filter=<?= $k ?><?= $church ? '&church=' . $church : '' ?>" class="chip<?= $filter === $k ? ' on' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
<?php if ($churchName): ?><p class="muted">Showing members of <strong><?= e($churchName) ?></strong> · <a class="link" href="members.php">clear</a></p><?php endif; ?>

<div class="grid-cards members-grid">
  <?php foreach ($members as $m): ?>
    <article class="card member-card">
      <a href="<?= e(url('profile.php?u=' . $m['username'])) ?>"><?= avatar($m, 'xl') ?></a>
      <a class="member-name" href="<?= e(url('profile.php?u=' . $m['username'])) ?>"><?= e(display_name($m)) ?></a>
      <small class="muted"><?= e($m['church_name'] ?: '@' . $m['username']) ?></small>
      <?php if ($m['role'] !== 'member'): ?><span class="tag tag-soft"><?= e(ucfirst($m['role'])) ?></span><?php endif; ?>
      <small class="muted"><?= (int) $m['followers'] ?> followers</small>
      <div class="member-actions">
        <button class="btn btn-sm <?= $m['is_following'] ? 'btn-outline' : 'btn-gradient' ?>" data-follow="<?= (int) $m['id'] ?>"><?= $m['is_following'] ? 'Following' : e(t('Follow')) ?></button>
        <a class="btn btn-sm btn-outline" href="<?= e(url('chat.php?to=' . $m['id'])) ?>" aria-label="Message"><?= icon('chat') ?></a>
      </div>
    </article>
  <?php endforeach; ?>
</div>
<?php if (!$members) empty_state('users', 'No members found.'); ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
