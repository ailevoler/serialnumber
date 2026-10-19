<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_member();
$uid = (int)$me['id'];

$summary = earnings_summary($uid);
$walletIncome = array_sum(array_map(fn($k) => $summary[$k]['total'], ['direct', 'indirect', 'passup', 'pairing', 'unilevel']));
$todayIncome = (float)q_val('SELECT COALESCE(SUM(amount),0) FROM earnings WHERE user_id = ? AND credited = 1 AND DATE(created_at) = CURDATE()', [$uid]);
$pendingPayout = (float)q_val("SELECT COALESCE(SUM(amount),0) FROM payouts WHERE user_id = ? AND status = 'pending'", [$uid]);
$unusedCodes = (int)q_val("SELECT COUNT(*) FROM codes WHERE owner_id = ? AND status = 'unused'", [$uid]);
$sponsor = $me['sponsor_id'] ? q_row('SELECT username, first_name, last_name FROM users WHERE id = ?', [$me['sponsor_id']]) : null;
$recent = q_all('SELECT e.*, s.username AS source FROM earnings e LEFT JOIN users s ON s.id = e.source_user_id WHERE e.user_id = ? ORDER BY e.id DESC LIMIT 8', [$uid]);
$directs = q_all('SELECT u.username, u.first_name, u.last_name, u.created_at, p.name package_name FROM users u LEFT JOIN packages p ON p.id = u.package_id WHERE u.sponsor_id = ? ORDER BY u.id DESC LIMIT 5', [$uid]);
$news = q_all("SELECT * FROM announcements WHERE status = 'published' ORDER BY id DESC LIMIT 4");

// last 7 days income chart
$days = [];
for ($i = 6; $i >= 0; $i--) {
    $days[date('Y-m-d', strtotime("-{$i} day"))] = 0.0;
}
foreach (q_all('SELECT DATE(created_at) d, SUM(amount) t FROM earnings WHERE user_id = ? AND credited = 1 AND created_at >= ? GROUP BY DATE(created_at)', [$uid, array_key_first($days)]) as $r) {
    $days[$r['d']] = (float)$r['t'];
}
$maxDay = max(1, max($days));
$refLink = absolute_url('register.php?ref=' . urlencode($me['username']));

page_header('Dashboard', $me, 'Overview of your business');
?>
<section class="hero-card">
  <div style="z-index:1">
    <span class="hero-badge"><?= e($me['package_name'] ?? 'Member') ?> Package</span>
    <h2>Mabuhay, <?= e($me['first_name']) ?>!</h2>
    <p>Member since <?= fdate($me['created_at']) ?><?= $sponsor ? ' · Sponsor: @' . e($sponsor['username']) : '' ?></p>
  </div>
  <div class="ref-box">
    <label>Your referral link</label>
    <div class="copy-group">
      <input id="ref-link" value="<?= e($refLink) ?>" readonly>
      <button class="btn btn-gold btn-sm" type="button" data-copy="#ref-link"><?= icon('copy', 14) ?> Copy</button>
    </div>
  </div>
</section>

<div class="stats stats-5">
  <?= stat_card('E-Wallet', money($me['wallet_balance']), 'wallet', 'dark', 'Available for payout') ?>
  <?php if ((float)$me['product_wallet'] > 0 || setting('pairing_to_product_wallet', '0') === '1'): ?>
  <?= stat_card('Product Wallet', money($me['product_wallet']), 'box', '', 'For products only') ?>
  <?php endif; ?>
  <?= stat_card('Total Earnings', money($me['total_earnings']), 'trending', 'gold', 'Today: ' . money($todayIncome)) ?>
  <?= stat_card('Direct Referrals', num($me['direct_count']), 'user-plus', '', 'Personally sponsored') ?>
  <?= stat_card('Pending Payouts', money($pendingPayout), 'cash', 'gold', $unusedCodes . ' unused code(s)') ?>
</div>

<div class="card">
  <div class="card-header"><h3><?= icon('chart') ?> 6 Ways to Earn</h3><div class="actions"><a class="btn btn-light btn-sm" href="<?= e(url('member/earnings.php')) ?>">View history</a></div></div>
  <div class="card-body">
    <div class="earn-grid">
      <?php foreach ($summary as $k => $s): ?>
        <a class="earn-item" href="<?= e(url('member/earnings.php?type=' . $k)) ?>">
          <div class="earn-ic"><?= icon($s['icon']) ?></div>
          <span><?= e($s['label']) ?></span>
          <strong><?= money($s['total']) ?></strong>
          <span class="small"><?= e($s['hint']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="grid grid-main">
  <div>
    <div class="card">
      <div class="card-header"><h3><?= icon('trending') ?> Income – Last 7 Days</h3></div>
      <div class="card-body">
        <div class="bar-chart">
          <?php foreach ($days as $d => $t): ?>
            <div class="bar-col">
              <b><?= $t > 0 ? num($t) : '' ?></b>
              <div class="bar <?= $d === date('Y-m-d') ? 'gold' : '' ?>" style="height: <?= max(2, round($t / $maxDay * 100)) ?>%"></div>
              <small><?= date('D', strtotime($d)) ?></small>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3><?= icon('list') ?> Recent Earnings</h3></div>
      <div class="card-body p-0 table-wrap">
        <table class="table stack">
          <thead><tr><th>Date</th><th>Type</th><th>Details</th><th class="text-right">Amount</th></tr></thead>
          <tbody>
          <?php foreach ($recent as $r): ?>
            <tr>
              <td class="nowrap"><?= fdatetime($r['created_at']) ?></td>
              <td><span class="badge badge-<?= $r['type'] === 'discount' ? 'gold' : 'success' ?>"><?= e(earning_types()[$r['type']]['label']) ?></span></td>
              <td><?= e($r['description']) ?></td>
              <td class="text-right amount-plus"><?= money($r['amount']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$recent) echo empty_row(4, 'No earnings yet. Share your referral link to start earning!'); ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="card-header"><h3><?= icon('git-merge') ?> Binary Summary</h3></div>
      <div class="card-body">
        <div class="bin-legs">
          <div class="leg left"><span>Left Members</span><strong><?= num($me['left_count']) ?></strong><span><?= num($me['left_points']) ?> PV carry</span></div>
          <div class="leg right"><span>Right Members</span><strong><?= num($me['right_count']) ?></strong><span><?= num($me['right_points']) ?> PV carry</span></div>
        </div>
        <p class="small text-muted mt-2 mb-0">Every <?= num(setting('pair_pv')) ?> PV left + right pays <?= money(setting('pair_bonus')) ?> (max <?= (int)setting('pair_daily_cap') ?> pairs/day).</p>
        <a class="btn btn-outline btn-sm btn-block mt-2" href="<?= e(url('member/genealogy.php')) ?>"><?= icon('tree', 15) ?> View Genealogy</a>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3><?= icon('users') ?> Newest Directs</h3></div>
      <div class="card-body">
        <ul class="list-plain">
          <?php foreach ($directs as $d): ?>
            <li><div class="user-cell"><div class="avatar"><?= e(initials($d)) ?></div><div><strong><?= e(full_name($d)) ?></strong><small>@<?= e($d['username']) ?> · <?= e($d['package_name']) ?></small></div></div><span class="small text-muted" style="margin-left:auto"><?= fdate($d['created_at'], 'M d') ?></span></li>
          <?php endforeach; ?>
          <?php if (!$directs): ?><li class="text-muted">No direct referrals yet.</li><?php endif; ?>
        </ul>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3><?= icon('megaphone') ?> Announcements</h3></div>
      <div class="card-body">
        <?php foreach ($news as $n): ?>
          <div class="announcement"><h4><?= e($n['title']) ?></h4><p><?= e($n['body']) ?></p><small><?= fdate($n['created_at']) ?></small></div>
        <?php endforeach; ?>
        <?php if (!$news): ?><p class="text-muted mb-0">No announcements.</p><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php page_footer();
