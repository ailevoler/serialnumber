<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

$run = q_row('SELECT r.*, u.username admin FROM simulation_runs r LEFT JOIN users u ON u.id = r.created_by WHERE r.id = ?', [input_int('id')]);
if (!$run || !$run['results']) {
    flash('error', 'Simulation report not found.');
    redirect('admin/simulation.php');
}
$p = json_decode($run['params'], true);
$r = json_decode($run['results'], true);
$pkgNames = array_column(q_all('SELECT id, name FROM packages'), 'name', 'id');
$net = (float)$r['profit']['net'];
$worst = (float)$r['profit']['worst'];
$rev = (float)$r['revenue']['total'];
$c = $r['costs'];
$share = fn($x) => $rev > 0 ? num($x / $rev * 100, 1) . '%' : '—';
$dl = backup_path(input('dl')) ? input('dl') : '';

if ($net < 0) {
    [$vClass, $vTitle, $vText] = ['loss', 'NALULUGI', 'Sa mga assumption na ito, mas malaki ang lumalabas (komisyon + gastos) kaysa pumapasok. Bawasan ang bonuses, taasan ang package price, o babaan ang pairing cap.'];
} elseif ($worst < 0) {
    [$vClass, $vTitle, $vText] = ['warn', 'KUMIKITA (may kondisyon)', 'Kumikita lang kung ang Product Wallet ay gagamitin sa produkto (sa cost price). Kapag ito ay ituring na cash, malulugi ang plan.'];
} else {
    [$vClass, $vTitle, $vText] = ['profit', 'KUMIKITA', 'Sa mga assumption na ito, kaya ng kita ang lahat ng komisyon at gastos, kahit ituring na cash ang Product Wallet.'];
}

// Daily chart: at most 30 bars
$days = $r['daily'];
$bucket = max(1, (int)ceil(count($days) / 30));
$bars = [];
foreach (array_chunk($days, $bucket, true) as $chunk) {
    $keys = array_keys($chunk);
    $bars[] = [
        'label' => date('M j', strtotime($keys[0])),
        'revenue' => array_sum(array_column($chunk, 'revenue')),
        'commissions' => array_sum(array_column($chunk, 'commissions')),
    ];
}
$maxBar = max(1, ...array_map(fn($b) => max($b['revenue'], $b['commissions']), $bars ?: [['revenue' => 1, 'commissions' => 1]]));

page_header('Simulation Report #' . $run['id'], $me, 'Actuarial projection: ' . fdate($r['start_date']) . ' – ' . fdate($r['end_date']));
?>
<?php if ($dl): ?>
<div class="alert alert-info no-print">The pre-simulation backup <strong class="mono"><?= e($dl) ?></strong> is downloading. If it does not start, <a href="<?= e(url('admin/backup_download.php?f=' . urlencode($dl))) ?>">click here</a>.</div>
<iframe src="<?= e(url('admin/backup_download.php?f=' . urlencode($dl))) ?>" style="display:none" title="backup download"></iframe>
<?php endif; ?>
<?php if ($run['status'] === 'failed'): ?><div class="alert alert-warning">This run stopped early: <?= e($run['error']) ?>. Figures cover the <?= num($r['members_created']) ?> member(s) created before it stopped.</div><?php endif; ?>

<div class="card no-print">
  <div class="card-body" style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn btn-light" href="<?= e(url('admin/simulation.php')) ?>">&larr; Simulation &amp; Reset</a>
    <button class="btn btn-primary" type="button" onclick="window.print()"><?= icon('download', 16) ?> Print / Save as PDF</button>
    <a class="btn btn-outline" href="<?= e(url('admin/members.php?q=sim' . $run['id'] . '_')) ?>">View simulated members</a>
  </div>
</div>

<div class="verdict verdict-<?= $vClass ?>">
  <div><small>Resulta ng Simulation #<?= (int)$run['id'] ?></small><strong><?= e($vTitle) ?></strong><p><?= e($vText) ?></p></div>
  <div class="verdict-num"><span>Net Profit</span><b><?= money($net) ?></b><small><?= num($r['ratios']['margin'], 1) ?>% margin · <?= money($r['profit']['per_member']) ?> / member</small></div>
</div>

<div class="stats">
  <?= stat_card('Members Simulated', num($r['members_created']), 'users', '', num($p['levels']) . ' level(s) · ' . num($r['invites_used']) . ' invite(s) each · ' . num($p['days']) . ' day(s)') ?>
  <?= stat_card('Total Revenue', money($rev), 'trending', 'dark', 'Packages ' . money($r['revenue']['packages']) . ' · Products ' . money($r['revenue']['products'])) ?>
  <?= stat_card('Total Commissions', money($c['ewallet'] + $c['outright'] + $c['product_wallet']), 'wallet', 'gold', 'Payout ratio ' . num($r['ratios']['payout'], 1) . '% of revenue') ?>
  <?= stat_card('Cash Payout Ratio', num($r['ratios']['cash_payout'], 1) . '%', 'cash', '', 'E-wallet + outright only') ?>
  <?= stat_card('Binary Pairs Paid', num($r['pairs_paid']), 'git-merge', '', num($r['flushed']['events']) . ' flushed (' . money($r['flushed']['value']) . ') by the ' . ($p['pair_cap'] ?: 'no') . '/day cap') ?>
  <?= stat_card('Worst Case Net', money($worst), 'chart', $worst < 0 ? '' : 'gold', 'If the Product Wallet were paid in cash') ?>
</div>

<div class="grid grid-2">
  <div class="card">
    <div class="card-header"><h3><?= icon('book') ?> Projected Profit &amp; Loss</h3></div>
    <div class="card-body p-0 table-wrap">
      <table class="table">
        <thead><tr><th>Item</th><th class="text-right">Amount</th><th class="text-right">% of revenue</th></tr></thead>
        <tbody>
          <tr><td>Package sales (<?= num($r['members_created']) ?> activations)</td><td class="text-right"><?= money($r['revenue']['packages']) ?></td><td class="text-right"><?= $share($r['revenue']['packages']) ?></td></tr>
          <tr><td>Product sales (<?= num($r['orders']['units']) ?> unit(s), <?= num($r['orders']['count']) ?> order(s))</td><td class="text-right"><?= money($r['revenue']['products']) ?></td><td class="text-right"><?= $share($r['revenue']['products']) ?></td></tr>
          <tr class="row-total"><td><strong>Total revenue</strong></td><td class="text-right"><strong><?= money($rev) ?></strong></td><td class="text-right">100%</td></tr>
          <tr><td>Less: package product cost (<?= num($p['package_cost_percent'], 1) ?>%)</td><td class="text-right text-danger"><?= money(-$c['package_cost']) ?></td><td class="text-right"><?= $share($c['package_cost']) ?></td></tr>
          <tr><td>Less: repeat product COGS (<?= num($p['cogs_percent'], 1) ?>%)</td><td class="text-right text-danger"><?= money(-$c['product_cogs']) ?></td><td class="text-right"><?= $share($c['product_cogs']) ?></td></tr>
          <tr><td>Less: Direct Referral paid outright</td><td class="text-right text-danger"><?= money(-$c['outright']) ?></td><td class="text-right"><?= $share($c['outright']) ?></td></tr>
          <tr><td>Less: E-wallet commissions (withdrawable)</td><td class="text-right text-danger"><?= money(-$c['ewallet']) ?></td><td class="text-right"><?= $share($c['ewallet']) ?></td></tr>
          <tr><td>Less: Product Wallet at cost (<?= money($c['product_wallet']) ?> × <?= num($p['cogs_percent'], 1) ?>%)</td><td class="text-right text-danger"><?= money(-$c['product_wallet_cost']) ?></td><td class="text-right"><?= $share($c['product_wallet_cost']) ?></td></tr>
          <tr><td>Less: operating expenses (<?= num($p['opex_percent'], 1) ?>%)</td><td class="text-right text-danger"><?= money(-$c['opex']) ?></td><td class="text-right"><?= $share($c['opex']) ?></td></tr>
          <tr class="row-total"><td><strong>Net profit / (loss)</strong></td><td class="text-right <?= $net < 0 ? 'text-danger' : 'text-green' ?>"><strong><?= money($net) ?></strong></td><td class="text-right"><strong><?= num($r['ratios']['margin'], 1) ?>%</strong></td></tr>
          <tr><td class="text-muted">Worst case (Product Wallet paid at full value)</td><td class="text-right <?= $worst < 0 ? 'text-danger' : '' ?>"><?= money($worst) ?></td><td class="text-right"><?= num($r['ratios']['worst_margin'], 1) ?>%</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3><?= icon('trending') ?> Commissions by Type</h3></div>
    <div class="card-body p-0 table-wrap">
      <table class="table">
        <thead><tr><th>Type</th><th>Records</th><th>Paid to</th><th class="text-right">Amount</th><th class="text-right">% rev</th></tr></thead>
        <tbody>
        <?php foreach ($r['by_type'] as $k => $t): if ($k === 'discount') continue; ?>
          <tr>
            <td><?= e($t['label']) ?><?= $k === 'pairing' ? '<br><small class="text-muted">' . num($t['pairs']) . ' pair(s)</small>' : '' ?></td>
            <td><?= num($t['count']) ?></td>
            <td><?php foreach ($t['paid_to'] as $to => $amt) echo paid_to_badge($to) . ' '; ?><?= !$t['paid_to'] ? '<span class="text-muted">—</span>' : '' ?></td>
            <td class="text-right"><?= money($t['amount']) ?></td>
            <td class="text-right"><?= $share($t['amount']) ?></td>
          </tr>
        <?php endforeach; ?>
          <tr class="row-total"><td colspan="3"><strong>Total commissions</strong></td><td class="text-right"><strong><?= money($c['ewallet'] + $c['outright'] + $c['product_wallet']) ?></strong></td><td class="text-right"><strong><?= num($r['ratios']['payout'], 1) ?>%</strong></td></tr>
          <tr><td colspan="3" class="text-muted">Member discounts (already deducted from product sales)</td><td class="text-right text-muted"><?= money($r['by_type']['discount']['amount']) ?></td><td></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3><?= icon('package') ?> Cost per Package Sold</h3></div>
    <div class="card-body p-0 table-wrap">
      <table class="table">
        <thead><tr><th>Package</th><th>Members</th><th class="text-right">Revenue</th><th class="text-right">Commissions triggered</th><th class="text-right">Per member</th></tr></thead>
        <tbody>
        <?php foreach ($r['packages'] as $pk): $ratio = $pk['revenue'] > 0 ? $pk['commissions'] / $pk['revenue'] * 100 : 0; ?>
          <tr>
            <td><?= e($pk['name']) ?><br><small class="text-muted"><?= money($pk['price']) ?></small></td>
            <td><?= num($pk['members']) ?></td>
            <td class="text-right"><?= money($pk['revenue']) ?></td>
            <td class="text-right"><?= money($pk['commissions']) ?><br><small class="<?= $ratio > 100 ? 'text-danger' : 'text-muted' ?>"><?= num($ratio, 1) ?>% of its revenue</small></td>
            <td class="text-right"><?= money($pk['members'] ? $pk['commissions'] / $pk['members'] : 0) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$r['packages']) echo empty_row(5); ?>
        </tbody>
      </table>
    </div>
    <div class="card-body small text-muted">Commissions triggered = all bonuses (direct, indirect, coded, pairing, unilevel) caused by members who bought that package.</div>
  </div>

  <div class="card">
    <div class="card-header"><h3><?= icon('users') ?> Member Outcomes</h3></div>
    <div class="card-body">
      <?php $roi = $r['roi']; $pctRec = $roi['members'] ? $roi['recovered'] / $roi['members'] * 100 : 0; ?>
      <div class="sim-kv"><span>Members who earned back their package price</span><b><?= num($roi['recovered']) ?> of <?= num($roi['members']) ?> (<?= num($pctRec, 1) ?>%)</b></div>
      <div class="sim-kv"><span>Members who earned nothing yet</span><b><?= num($roi['zero']) ?></b></div>
      <div class="sim-kv"><span>Average earnings per simulated member</span><b><?= money($roi['avg']) ?></b></div>
      <div class="sim-kv"><span>Highest earnings of a simulated member</span><b><?= money($roi['max']) ?></b></div>
      <div class="sim-kv"><span>Product Wallet liability created</span><b><?= money($c['product_wallet']) ?></b></div>
      <div class="sim-kv"><span>Withdrawable E-wallet liability created</span><b><?= money($c['ewallet']) ?></b></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3><?= icon('chart') ?> Revenue vs Commissions over Time</h3><div class="actions small"><span class="legend-dot"></span> Revenue <span class="legend-dot gold"></span> Commissions</div></div>
  <div class="card-body">
    <div class="sim-chart">
      <?php foreach ($bars as $b): ?>
        <div class="sim-col" title="<?= e($b['label'] . ': revenue ' . money($b['revenue']) . ', commissions ' . money($b['commissions'])) ?>">
          <div class="sim-pair"><i class="bar" style="height:<?= round($b['revenue'] / $maxBar * 100, 1) ?>%"></i><i class="bar gold" style="height:<?= round($b['commissions'] / $maxBar * 100, 1) ?>%"></i></div>
          <small><?= e($b['label']) ?></small>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="grid grid-2">
  <div class="card">
    <div class="card-header"><h3><?= icon('trending') ?> Top Earners</h3></div>
    <div class="card-body p-0 table-wrap">
      <table class="table">
        <thead><tr><th>Member</th><th class="text-right">E-wallet</th><th class="text-right">Product</th><th class="text-right">Outright</th><th class="text-right">Total</th></tr></thead>
        <tbody>
        <?php foreach ($r['top'] as $t): ?>
          <tr><td>@<?= e($t['username']) ?><br><small class="text-muted"><?= e($t['package'] ?? '') ?></small></td>
            <td class="text-right"><?= money($t['ewallet']) ?></td><td class="text-right"><?= money($t['product']) ?></td><td class="text-right"><?= money($t['outright']) ?></td><td class="text-right"><strong><?= money($t['total']) ?></strong></td></tr>
        <?php endforeach; ?>
        <?php if (!$r['top']) echo empty_row(5); ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3><?= icon('settings') ?> Assumptions</h3></div>
    <div class="card-body">
      <div class="sim-kv"><span>Members planned / created</span><b><?= num($r['members_planned']) ?> / <?= num($r['members_created']) ?></b></div>
      <div class="sim-kv"><span>Levels · invites per member</span><b><?= num($p['levels']) ?> · <?= num($r['invites_used']) ?><?= $p['invites'] ? '' : ' (auto)' ?></b></div>
      <div class="sim-kv"><span>Top sponsor</span><b>@<?= e($p['top']) ?></b></div>
      <div class="sim-kv"><span>Placement</span><b><?= $p['placement'] === 'ratio' ? num($p['left_percent']) . '% left / ' . num(100 - $p['left_percent']) . '% right' : 'Balanced auto-fill' ?></b></div>
      <div class="sim-kv"><span>Pairing</span><b><?= e(ucfirst($r['plan']['pairing_mode'])) ?> · <?= num($r['plan']['pair_rate']) ?>% · cap <?= $p['pair_cap'] ?: 'none' ?>/day<?= $r['plan']['pairing_to_product_wallet'] ? ' · Product Wallet' : '' ?></b></div>
      <div class="sim-kv"><span>Period</span><b><?= num($p['days']) ?> day(s): <?= e(fdate($r['start_date'])) ?> – <?= e(fdate($r['end_date'])) ?></b></div>
      <div class="sim-kv"><span>Package mix</span><b><?= e(implode(', ', array_map(fn($id, $v) => ($pkgNames[$id] ?? '#' . $id) . ' ' . num($v, 1) . '%', array_keys($p['mix']), $p['mix']))) ?></b></div>
      <div class="sim-kv"><span>Repeat units per member</span><b><?= num($p['repeat_units']) ?></b></div>
      <div class="sim-kv"><span>COGS · package cost · opex</span><b><?= num($p['cogs_percent'], 1) ?>% · <?= num($p['package_cost_percent'], 1) ?>% · <?= num($p['opex_percent'], 1) ?>%</b></div>
      <div class="sim-kv"><span>Run by</span><b>@<?= e($run['admin'] ?? '—') ?> · <?= e(fdatetime($run['created_at'])) ?></b></div>
      <?php if ($run['backup_file']): ?><div class="sim-kv"><span>Backup before run</span><b class="mono small"><?= e($run['backup_file']) ?></b></div><?php endif; ?>
    </div>
  </div>
</div>

<style>
.verdict { display: flex; gap: 20px; align-items: center; justify-content: space-between; flex-wrap: wrap; border-radius: 16px; padding: 20px 24px; margin-bottom: 20px; color: #fff; }
.verdict small { opacity: .85; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; }
.verdict strong { display: block; font-size: 30px; font-family: var(--font-head); letter-spacing: 1px; }
.verdict p { margin: 4px 0 0; max-width: 620px; opacity: .92; }
.verdict-profit { background: linear-gradient(135deg, var(--green-700), var(--green-900)); }
.verdict-warn { background: linear-gradient(135deg, #c99a1c, #8a6a10); }
.verdict-loss { background: linear-gradient(135deg, #c0392b, #7d1f16); }
.verdict-num { text-align: right; }
.verdict-num span { display: block; font-size: 12px; opacity: .85; }
.verdict-num b { display: block; font-size: 28px; }
.row-total td { background: var(--green-50); }
.sim-kv { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-bottom: 1px dashed var(--border); font-size: 13.5px; }
.sim-kv:last-child { border-bottom: 0; }
.sim-kv span { color: var(--muted); }
.sim-kv b { text-align: right; }
.sim-chart { display: flex; align-items: flex-end; gap: 6px; height: 200px; overflow-x: auto; }
.sim-col { flex: 1; min-width: 22px; height: 100%; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; gap: 4px; }
.sim-pair { flex: 1; width: 100%; display: flex; align-items: flex-end; gap: 2px; justify-content: center; }
.sim-pair .bar { max-width: 14px; border-radius: 4px 4px 1px 1px; }
.sim-col small { font-size: 10px; color: var(--muted); white-space: nowrap; }
.legend-dot { display: inline-block; width: 10px; height: 10px; border-radius: 3px; background: var(--green); margin: 0 4px 0 10px; }
.legend-dot.gold { background: var(--gold); }
@media (max-width: 640px) { .verdict-num { text-align: left; } .verdict strong { font-size: 24px; } }
@media print { .no-print, .sidebar, .topbar { display: none !important; } .main { margin: 0 !important; } .verdict { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style>
<?php page_footer();
