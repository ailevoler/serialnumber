<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_member();
$uid = (int)$me['id'];

$rootId = input_int('root', $uid);
if ($search = strtolower(input('find'))) {
    $f = find_member_by_username($search);
    $rootId = $f ? (int)$f['id'] : -1;
}
if ($rootId <= 0 || !in_binary_downline($uid, $rootId)) {
    if ($search || $rootId !== $uid) {
        flash('error', 'That member is not in your genealogy.');
    }
    $rootId = $uid;
}
$tree = binary_tree($rootId, 4);
$parent = $rootId !== $uid ? (int)$tree['placement_id'] : 0;

page_header('Binary Genealogy', $me, 'Click a member to view their downline');
?>
<div class="card">
  <div class="card-header">
    <h3><?= icon('tree') ?> Genealogy of @<?= e($tree['username']) ?></h3>
    <div class="actions">
      <form class="filters" method="get">
        <input class="input" name="find" placeholder="Find username" value="<?= e($search) ?>">
        <button class="btn btn-primary btn-sm" type="submit"><?= icon('search', 14) ?> Find</button>
      </form>
      <?php if ($parent): ?><a class="btn btn-light btn-sm" href="?root=<?= $parent ?>">&uarr; Up one level</a><?php endif; ?>
      <?php if ($rootId !== $uid): ?><a class="btn btn-outline btn-sm" href="?">Back to me</a><?php endif; ?>
    </div>
  </div>
  <div class="card-body">
    <div class="bin-legs mb-2">
      <div class="leg left"><span>Left: <?= num($tree['left_count']) ?> members</span><strong><?= num($tree['left_points']) ?> PV</strong></div>
      <div class="leg right"><span>Right: <?= num($tree['right_count']) ?> members</span><strong><?= num($tree['right_points']) ?> PV</strong></div>
    </div>
    <div class="tree-wrap">
      <div class="tree"><ul><?= render_tree_node($tree, url('member/genealogy.php'), null, '', true) ?></ul></div>
    </div>
  </div>
</div>
<?php page_footer();
