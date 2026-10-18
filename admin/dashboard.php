<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

$members = (int)q_val("SELECT COUNT(*) FROM users WHERE role = 'member'");
$newToday = (int)q_val("SELECT COUNT(*) FROM users WHERE role = 'member' AND DATE(created_at) = CURDATE()");
$activationSales = (float)q_val("SELECT COALESCE(SUM(p.price),0) FROM codes c JOIN packages p ON p.id = c.package_id WHERE c.status = 'used' AND c.code_type = 'paid'");
$productSales = (float)q_val("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('paid','shipped','completed')");
$commissions = (float)q_val('SELECT COALESCE(SUM(amount),0) FROM earnings WHERE credited = 1');
$walletTotal = (float)q_val("SELECT COALESCE(SUM(wallet_balance + product_wallet),0) FROM users WHERE role = 'member'");
$pendingPayouts = q_row("SELECT COUNT(*) n, COALESCE(SUM(amount),0) t FROM payouts WHERE status = 'pending'");
$pendingOrders = (int)q_val("SELECT COUNT(*) FROM orders WHERE status = 'pending'");
$unusedCodes = (int)q_val("SELECT COUNT(*) FROM codes WHERE status = 'unused'");
$paidOut = (float)q_val("SELECT COALESCE(SUM(amount),0) FROM payouts WHERE status = 'approved'");
$summary = earnings_summary();

$days = [];
for ($i = 6; $i >= 0; $i--) {
    $days[date('Y-m-d', strtotime("-{$i} day"))] = ['reg' => 0, 'sales' => 0.0];
}
$start = array_key_first($days);
foreach (q_all("SELECT DATE(created_at) d, COUNT(*) n FROM users WHERE role = 'member' AND created_at >= ? GROUP BY DATE(created_at)", [$start]) as $r) {
    $days[$r['d']]['reg'] = (int)$r['n'];
}
foreach (q_all("SELECT DATE(created_at) d, SUM(total) t FROM orders WHERE status IN ('paid','shipped','completed') AND created_at >= ? GROUP BY DATE(created_at)", [$start]) as $r) {
    $days[$r['d']]['sales'] = (float)$r['t'];
}
$maxReg = max(1, max(array_column($days, 'reg')));
$maxSales = max(1, max(array_column($days, 'sales')));

$latest = q_all("SELECT u.*, p.name package_name, s.username sponsor FROM users u LEFT JOIN packages p ON p.id = u.package_id LEFT JOIN users s ON s.id = u.sponsor_id WHERE u.role = 'member' ORDER BY u.id DESC LIMIT 6");
$top = q_all("SELECT u.username, u.first_name, u.last_name, u.total_earnings FROM users u WHERE u.role = 'member' ORDER BY u.total_earnings DESC LIMIT 5");

page_header('Admin Dashboard', $me, 'Company-wide overview');
?>
<section class="hero-card">
  <div style="z-index:1">
    <span class="hero-badge">Administrator</span>
    <h2>Good day, <?= e($me['first_name']) ?>!</h2>
    <p><?= $newToday ?> new member(s) today · <?= (int)$pendingPayouts['n'] ?> payout(s) and <?= $pendingOrders ?> order(s) waiting for action.</p>
  </div>
  <div class="ref-box" style="display:flex;gap:8px;flex-wrap:wrap;min-width:0">
    <a class="btn btn-gold btn-sm" href="<?= e(url('admin/payouts.php?status=pending')) ?>"><?= icon('cash', 14) ?> Payouts</a>
    <a class="btn btn-light btn-sm" href="<?= e(url('admin/orders.php?status=pending')) ?>"><?= icon('cart', 14) ?> Orders</a>
    <a class="btn btn-light btn-sm" href="<?= e(url('admin/codes.php')) ?>"><?= icon('key', 14) ?> Generate Codes</a>
  </div>
</section>

<div class="stats">
  <?= stat_card('Total Members', num($members), 'users', '', "+{$newToday} today") ?>
  <?= stat_card('Activation Sales', money($activationSales), 'key', 'gold') ?>
  <?= stat_card('Product Sales', money($productSales), 'cart') ?>
  <?= stat_card('Commissions Paid', money($commissions), 'trending', 'gold') ?>
  <?= stat_card('Wallet Liabilities', money($walletTotal), 'wallet', 'dark', 'E-Wallet + Product Wallet') ?>
  <?= stat_card('Pending Payouts', money($pendingPayouts['t']), 'cash', 'gold', (int)$pendingPayouts['n'] . ' request(s)') ?>
  <?= stat_card('Total Paid Out', money($paidOut), 'download') ?>
  <?= stat_card('Unused Codes', num($unusedCodes), 'key', 'gold') ?>
</div>

<div class="grid grid-2">
  <div class="card">
    <div class="card-header"><h3><?= icon('user-plus') ?> New Members – 7 Days</h3></div>
    <div class="card-body"><div class="bar-chart">
      <?php foreach ($days as $d => $v): ?>
        <div class="bar-col"><b><?= $v['reg'] ?: '' ?></b><div class="bar" style="height:<?= max(2, round($v['reg'] / $maxReg * 100)) ?>%"></div><small><?= date('D', strtotime($d)) ?></small></div>
      <?php endforeach; ?>
    </div></div>
  </div>
  <div class="card">
    <div class="card-header"><h3><?= icon('cart') ?> Product Sales – 7 Days</h3></div>
    <div class="card-body"><div class="bar-chart">
      <?php foreach ($days as $d => $v): ?>
        <div class="bar-col"><b><?= $v['sales'] ? num($v['sales']) : '' ?></b><div class="bar gold" style="height:<?= max(2, round($v['sales'] / $maxSales * 100)) ?>%"></div><small><?= date('D', strtotime($d)) ?></small></div>
      <?php endforeach; ?>
    </div></div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3><?= icon('chart') ?> Commissions by Type (6 Ways to Earn)</h3><div class="actions"><a class="btn btn-light btn-sm" href="<?= e(url('admin/earnings.php')) ?>">Details</a></div></div>
  <div class="card-body"><div class="earn-grid">
    <?php foreach ($summary as $k => $s): ?>
      <div class="earn-item"><div class="earn-ic"><?= icon($s['icon']) ?></div><span><?= e($s['label']) ?></span><strong><?= money($s['total']) ?></strong><span class="small"><?= num($s['count']) ?> record(s)</span></div>
    <?php endforeach; ?>
  </div></div>
</div>

<div class="grid grid-main">
  <div class="card">
    <div class="card-header"><h3><?= icon('users') ?> Latest Registrations</h3><div class="actions"><a class="btn btn-light btn-sm" href="<?= e(url('admin/members.php')) ?>">All members</a></div></div>
    <div class="card-body p-0 table-wrap">
      <table class="table stack">
        <thead><tr><th>Member</th><th>Package</th><th>Sponsor</th><th>Joined</th></tr></thead>
        <tbody>
        <?php foreach ($latest as $r): ?>
          <tr>
            <td><a class="user-cell" href="<?= e(url('admin/member_view.php?id=' . $r['id'])) ?>"><div class="avatar"><?= e(initials($r)) ?></div><div><strong><?= e(full_name($r)) ?></strong><small>@<?= e($r['username']) ?></small></div></a></td>
            <td><span class="badge badge-gold"><?= e($r['package_name'] ?? '—') ?></span></td>
            <td><?= $r['sponsor'] ? '@' . e($r['sponsor']) : '—' ?></td>
            <td class="nowrap"><?= fdatetime($r['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$latest) echo empty_row(4); ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h3><?= icon('trending') ?> Top Earners</h3></div>
    <div class="card-body">
      <ul class="list-plain">
        <?php foreach ($top as $i => $t): ?>
          <li><span class="badge <?= $i === 0 ? 'badge-gold' : 'badge-success' ?>">#<?= $i + 1 ?></span><div><strong><?= e(full_name($t)) ?></strong><br><small class="text-muted">@<?= e($t['username']) ?></small></div><strong style="margin-left:auto" class="text-green"><?= money($t['total_earnings']) ?></strong></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>
<?php page_footer();
