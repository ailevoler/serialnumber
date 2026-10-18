<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

$cats = ['earning', 'payout', 'payout_refund', 'purchase', 'purchase_refund', 'transfer_in', 'transfer_out', 'adjustment', 'code_purchase'];
$wal = in_array(input('wallet'), ['ewallet', 'product'], true) ? input('wallet') : '';
$cat = in_array(input('category'), $cats, true) ? input('category') : '';
$user = strtolower(input('user'));
$where = ['1=1'];
$params = [];
if ($cat) { $where[] = 'w.category = ?'; $params[] = $cat; }
if ($wal) { $where[] = 'w.wallet = ?'; $params[] = $wal; }
if ($user !== '') { $where[] = 'u.username = ?'; $params[] = $user; }
$w = implode(' AND ', $where);
$base = "FROM wallet_transactions w JOIN users u ON u.id = w.user_id WHERE $w";

$p = paginate("SELECT COUNT(*) $base", $params, 30);
$rows = q_all("SELECT w.*, u.username $base ORDER BY w.id DESC LIMIT {$p['limit']} OFFSET {$p['offset']}", $params);
$tot = q_row("SELECT COALESCE(SUM(IF(w.direction='credit', w.amount, 0)),0) cr, COALESCE(SUM(IF(w.direction='debit', w.amount, 0)),0) dr $base", $params);

page_header('Wallet Ledger', $me, 'Every e-wallet movement');
?>
<div class="stats">
  <?= stat_card('Credits', money($tot['cr']), 'arrow-up') ?>
  <?= stat_card('Debits', money($tot['dr']), 'cash', 'gold') ?>
  <?= stat_card('E-Wallet Balances', money(q_val("SELECT COALESCE(SUM(wallet_balance),0) FROM users WHERE role = 'member'")), 'wallet', 'dark') ?>
  <?= stat_card('Product Wallet Balances', money(q_val("SELECT COALESCE(SUM(product_wallet),0) FROM users WHERE role = 'member'")), 'box') ?>
</div>
<div class="card">
  <div class="card-header">
    <form class="filters" method="get">
      <select class="input" name="category"><option value="">All categories</option><?php foreach ($cats as $c): ?><option value="<?= $c ?>" <?= $cat === $c ? 'selected' : '' ?>><?= str_replace('_', ' ', $c) ?></option><?php endforeach; ?></select>
      <select class="input" name="wallet"><option value="">All wallets</option><option value="ewallet" <?= $wal === 'ewallet' ? 'selected' : '' ?>>E-Wallet</option><option value="product" <?= $wal === 'product' ? 'selected' : '' ?>>Product Wallet</option></select>
      <input class="input" name="user" value="<?= e($user) ?>" placeholder="Username">
      <button class="btn btn-primary btn-sm">Filter</button>
    </form>
  </div>
  <div class="card-body p-0 table-wrap">
    <table class="table stack">
      <thead><tr><th>Date</th><th>Member</th><th>Wallet</th><th>Category</th><th>Description</th><th>Ref</th><th class="text-right">Amount</th><th class="text-right">Balance</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="nowrap"><?= fdatetime($r['created_at']) ?></td>
          <td>@<?= e($r['username']) ?></td>
          <td><span class="badge badge-<?= $r['wallet'] === 'product' ? 'info' : 'success' ?>"><?= e(wallet_labels()[$r['wallet']]) ?></span></td>
          <td><span class="badge badge-muted"><?= e(str_replace('_', ' ', $r['category'])) ?></span></td>
          <td><?= e($r['description']) ?></td>
          <td class="small mono"><?= e($r['reference']) ?></td>
          <td class="text-right <?= $r['direction'] === 'credit' ? 'amount-plus' : 'amount-minus' ?>"><?= $r['direction'] === 'credit' ? '+' : '−' ?><?= money($r['amount']) ?></td>
          <td class="text-right"><?= money($r['balance_after']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows) echo empty_row(8); ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($p) ?>
</div>
<?php page_footer();
