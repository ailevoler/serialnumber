<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

$topId = (int)q_val("SELECT id FROM users WHERE role = 'member' AND placement_id IS NULL ORDER BY id LIMIT 1");
$rootId = input_int('root', $topId);
if ($search = strtolower(input('find'))) {
    $f = find_member_by_username($search);
    if ($f) {
        $rootId = (int)$f['id'];
    } else {
        flash('error', 'Member not found.');
    }
}
$tree = $rootId ? binary_tree($rootId, 4) : null;

page_header('Genealogy', $me, 'Binary tree of the whole network');
?>
<div class="card">
  <div class="card-header">
    <h3><?= icon('tree') ?> <?= $tree ? 'Tree of @' . e($tree['username']) : 'No members yet' ?></h3>
    <div class="actions">
      <form class="filters" method="get">
        <input class="input" name="find" placeholder="Find username" value="<?= e($search) ?>">
        <button class="btn btn-primary btn-sm" type="submit"><?= icon('search', 14) ?> Find</button>
      </form>
      <?php if ($tree && $tree['placement_id']): ?><a class="btn btn-light btn-sm" href="?root=<?= (int)$tree['placement_id'] ?>">&uarr; Up</a><a class="btn btn-outline btn-sm" href="?">Top</a><?php endif; ?>
      <?php if ($tree): ?><a class="btn btn-light btn-sm" href="<?= e(url('admin/member_view.php?id=' . $tree['id'])) ?>">Manage</a><?php endif; ?>
      <a class="btn btn-gold btn-sm" href="<?= e(url('admin/register_member.php' . ($tree ? '?placement=' . urlencode($tree['username']) : ''))) ?>"><?= icon('user-plus', 14) ?> Register Member</a>
    </div>
  </div>
  <?php if ($tree): ?>
  <div class="card-body">
    <div class="bin-legs mb-2">
      <div class="leg left"><span>Left: <?= num($tree['left_count']) ?> members</span><strong><?= num($tree['left_points']) ?> PV</strong></div>
      <div class="leg right"><span>Right: <?= num($tree['right_count']) ?> members</span><strong><?= num($tree['right_points']) ?> PV</strong></div>
    </div>
    <div class="tree-wrap"><div class="tree"><ul><?= render_tree_node($tree, url('admin/genealogy.php'), null, '', 'admin/register_member.php') ?></ul></div></div>
  </div>
  <?php endif; ?>
</div>
<?php page_footer();
