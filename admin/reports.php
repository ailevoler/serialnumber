<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

$from = input('from', date('Y-m-01'));
$to = input('to', date('Y-m-d'));
$range = [$from . ' 00:00:00', $to . ' 23:59:59'];

$newMembers = (int)q_val("SELECT COUNT(*) FROM users WHERE role = 'member' AND created_at BETWEEN ? AND ?", $range);
$activations = q_all("SELECT p.name, COUNT(*) n, SUM(p.price) total FROM codes c JOIN packages p ON p.id = c.package_id
                      WHERE c.status = 'used' AND c.code_type = 'paid' AND c.used_at BETWEEN ? AND ? GROUP BY p.id ORDER BY p.id", $range);
$activationTotal = array_sum(array_column($activations, 'total'));
$sales = q_row("SELECT COUNT(*) n, COALESCE(SUM(total),0) total, COALESCE(SUM(total_pv),0) pv, COALESCE(SUM(discount),0) discount
                FROM orders WHERE status IN ('paid','shipped','completed') AND created_at BETWEEN ? AND ?", $range);
$summary = earnings_summary(null, $from, $to);
$payoutTotal = q_row("SELECT COALESCE(SUM(amount),0) gross, COALESCE(SUM(fee),0) fee, COALESCE(SUM(net_amount),0) net FROM payouts WHERE status = 'approved' AND processed_at BETWEEN ? AND ?", $range);
$topProducts = q_all("SELECT oi.product_name, SUM(oi.qty) qty, SUM(oi.qty * oi.price) total FROM order_items oi JOIN orders o ON o.id = oi.order_id
                      WHERE o.status IN ('paid','shipped','completed') AND o.created_at BETWEEN ? AND ? GROUP BY oi.product_id, oi.product_name ORDER BY qty DESC LIMIT 5", $range);
$topRecruiters = q_all("SELECT s.username, s.first_name, s.last_name, COUNT(*) n FROM users u JOIN users s ON s.id = u.sponsor_id
                        WHERE u.created_at BETWEEN ? AND ? GROUP BY s.id ORDER BY n DESC LIMIT 5", $range);
$walletPayable = array_sum(array_map(fn($k) => $summary[$k]['total'], ['direct', 'indirect', 'passup', 'pairing', 'unilevel']));
$revenue = $activationTotal + (float)$sales['total'];
$payoutRatio = $revenue > 0 ? $walletPayable / $revenue * 100 : 0;

page_header('Reports', $me, 'Sales, commissions and payouts by period');
?>
<div class="card">
  <div class="card-body">
    <form class="filters" method="get">
      <div><label>From</label><input class="input" type="date" name="from" value="<?= e($from) ?>"></div>
      <div><label>To</label><input class="input" type="date" name="to" value="<?= e($to) ?>"></div>
      <button class="btn btn-primary" type="submit">Generate</button>
      <button class="btn btn-light" type="button" onclick="window.print()">Print</button>
    </form>
  </div>
</div>

<div class="stats">
  <?= stat_card('New Members', num($newMembers), 'user-plus') ?>
  <?= stat_card('Activation Sales', money($activationTotal), 'key', 'gold') ?>
  <?= stat_card('Product Sales', money($sales['total']), 'cart', '', num($sales['n']) . ' order(s) · ' . num($sales['pv']) . ' PV') ?>
  <?= stat_card('Gross Revenue', money($revenue), 'trending', 'dark') ?>
  <?= stat_card('Commissions (wallet)', money($walletPayable), 'wallet', 'gold') ?>
  <?= stat_card('Payout Ratio', num($payoutRatio, 1) . '%', 'chart', '', 'Commissions ÷ revenue') ?>
</div>

<div class="grid grid-2">
  <div class="card">
    <div class="card-header"><h3><?= icon('chart') ?> Commissions by Type</h3></div>
    <div class="card-body p-0 table-wrap">
      <table class="table">
        <thead><tr><th>Type</th><th>Records</th><th class="text-right">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($summary as $s): ?><tr><td><?= e($s['label']) ?></td><td><?= num($s['count']) ?></td><td class="text-right"><?= money($s['total']) ?></td></tr><?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h3><?= icon('key') ?> Package Activations</h3></div>
    <div class="card-body p-0 table-wrap">
      <table class="table">
        <thead><tr><th>Package</th><th>Count</th><th class="text-right">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($activations as $a): ?><tr><td><?= e($a['name']) ?></td><td><?= num($a['n']) ?></td><td class="text-right"><?= money($a['total']) ?></td></tr><?php endforeach; ?>
        <?php if (!$activations) echo empty_row(3); ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h3><?= icon('box') ?> Top Products</h3></div>
    <div class="card-body p-0 table-wrap">
      <table class="table">
        <thead><tr><th>Product</th><th>Qty</th><th class="text-right">Sales</th></tr></thead>
        <tbody>
        <?php foreach ($topProducts as $t): ?><tr><td><?= e($t['product_name']) ?></td><td><?= num($t['qty']) ?></td><td class="text-right"><?= money($t['total']) ?></td></tr><?php endforeach; ?>
        <?php if (!$topProducts) echo empty_row(3); ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h3><?= icon('users') ?> Top Recruiters</h3></div>
    <div class="card-body p-0 table-wrap">
      <table class="table">
        <thead><tr><th>Member</th><th class="text-right">New Directs</th></tr></thead>
        <tbody>
        <?php foreach ($topRecruiters as $t): ?><tr><td><?= e(full_name($t)) ?> <small class="text-muted">@<?= e($t['username']) ?></small></td><td class="text-right"><?= num($t['n']) ?></td></tr><?php endforeach; ?>
        <?php if (!$topRecruiters) echo empty_row(2); ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<div class="card">
  <div class="card-header"><h3><?= icon('cash') ?> Payouts Released</h3></div>
  <div class="card-body"><dl class="kv"><dt>Gross</dt><dd><?= money($payoutTotal['gross']) ?></dd><dt>Fees collected</dt><dd><?= money($payoutTotal['fee']) ?></dd><dt>Net released</dt><dd><strong><?= money($payoutTotal['net']) ?></strong></dd></dl></div>
</div>
<?php page_footer();
