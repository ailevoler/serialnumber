<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_member();
$uid = (int)$me['id'];

$p = paginate('SELECT COUNT(*) FROM users WHERE sponsor_id = ?', [$uid]);
$rows = q_all("SELECT u.*, p.name package_name, pl.username placement_name
               FROM users u LEFT JOIN packages p ON p.id = u.package_id LEFT JOIN users pl ON pl.id = u.placement_id
               WHERE u.sponsor_id = ? ORDER BY u.id DESC LIMIT {$p['limit']} OFFSET {$p['offset']}", [$uid]);
$passedUp = (int)q_val('SELECT COUNT(*) FROM users WHERE sponsor_id = ? AND passup_upline_id <> ?', [$uid, $uid]);
$receivedPassups = (int)q_val('SELECT COUNT(*) FROM users WHERE passup_upline_id = ? AND sponsor_id <> ?', [$uid, $uid]);

page_header('Direct Referrals', $me, 'Members you personally sponsored');
?>
<div class="stats">
  <?= stat_card('Direct Referrals', num($me['direct_count']), 'user-plus') ?>
  <?= stat_card('Passed Up', num($passedUp), 'arrow-up', 'gold', 'Recruits passed to your upline') ?>
  <?= stat_card('Pass-ups Received', num($receivedPassups), 'arrow-up', '', 'From your downline') ?>
</div>
<div class="card">
  <div class="card-header"><h3><?= icon('users') ?> My Direct Referrals</h3>
    <div class="actions"><a class="btn btn-primary btn-sm" href="<?= e(url('member/register.php')) ?>"><?= icon('user-plus', 14) ?> Register Member</a></div></div>
  <div class="card-body p-0 table-wrap">
    <table class="table stack">
      <thead><tr><th>Member</th><th>Package</th><th>Placement</th><th>Pass-up</th><th>Status</th><th>Joined</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><div class="user-cell"><div class="avatar"><?= e(initials($r)) ?></div><div><strong><?= e(full_name($r)) ?></strong><small>@<?= e($r['username']) ?></small></div></div></td>
          <td><span class="badge badge-gold"><?= e($r['package_name']) ?></span></td>
          <td>@<?= e($r['placement_name']) ?> (<?= $r['position'] === 'L' ? 'Left' : 'Right' ?>)</td>
          <td><?= (int)$r['passup_upline_id'] !== $uid ? '<span class="badge badge-info">Passed up</span>' : '<span class="badge badge-success">Kept</span>' ?></td>
          <td><?= badge($r['status']) ?></td>
          <td class="nowrap"><?= fdate($r['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows) echo empty_row(6, 'You have no direct referrals yet.'); ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($p) ?>
</div>
<?php page_footer();
