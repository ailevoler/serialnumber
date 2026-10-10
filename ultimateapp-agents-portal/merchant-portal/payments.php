<?php
require_once __DIR__ . '/_bootstrap.php';
$m = merchant_require();
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/D', mq('from')) ? mq('from') : date('Y-m-d', strtotime('-29 days'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/D', mq('to')) ? mq('to') : date('Y-m-d');
$params = [$m['id'], $from . ' 00:00:00', $to . ' 23:59:59'];
$base = ' FROM merchant_payments mp JOIN users u ON u.id = mp.user_id WHERE mp.merchant_id = ? AND mp.created_at BETWEEN ? AND ?';
if (mq('export') === 'csv') {
    $s = $pdo->prepare('SELECT mp.reference, mp.created_at, u.full_name, mp.source, mp.amount_centavos, mp.fee_centavos, mp.fee_bearer, mp.merchant_net_centavos, mp.status, mp.note' . $base . ' ORDER BY mp.id');
    $s->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payments-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Reference', 'Date', 'Customer', 'Paid with', 'Amount', 'Charge', 'Charge paid by', 'You receive', 'Status', 'Note']);
    foreach ($s as $r) fputcsv($out, [$r['reference'], $r['created_at'], explode(' ', $r['full_name'])[0], $r['source'] === 'credits' ? 'Credits' : 'BCash', centavos_to_decimal((int) $r['amount_centavos']), centavos_to_decimal((int) $r['fee_centavos']), $r['fee_bearer'], centavos_to_decimal((int) $r['merchant_net_centavos']), $r['status'], preg_replace('/^[=+\-@]/', "'$0", (string) $r['note'])]);
    exit;
}
$t = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN mp.status='completed' THEN mp.amount_centavos END),0) v, COALESCE(SUM(CASE WHEN mp.status='completed' THEN mp.merchant_net_centavos END),0) net" . $base);
$t->execute($params); $t = $t->fetch();
$page = max(1, (int) mq('page') ?: 1); $per = 30; $off = ($page - 1) * $per;
$s = $pdo->prepare('SELECT mp.*, u.full_name' . $base . " ORDER BY mp.id DESC LIMIT $per OFFSET $off");
$s->execute($params); $rows = $s->fetchAll();
portal_header('Payments', 'payments');
?>
<form class="toolbar" method="get"><label>From <input type="date" name="from" value="<?= e($from) ?>"></label><label>To <input type="date" name="to" value="<?= e($to) ?>"></label><button class="btn" type="submit">Apply</button><span class="spacer"></span><a class="btn" href="?<?= e(http_build_query(['from' => $from, 'to' => $to, 'export' => 'csv'])) ?>">Export CSV</a></form>
<div class="grid kpis"><div class="card kpi"><small>Payments</small><strong><?= number_format((int) $t['n']) ?></strong><span>In period</span></div><div class="card kpi"><small>Gross sales</small><strong>₱<?= peso((int) $t['v']) ?></strong><span>Excludes refunds</span></div><div class="card kpi"><small>You receive</small><strong>₱<?= peso((int) $t['net']) ?></strong><span>After merchant charges</span></div></div>
<div class="table-wrap mt"><table><thead><tr><th>Reference</th><th>Customer</th><th>Paid with</th><th class="num">Amount</th><th class="num">Charge</th><th class="num">You receive</th><th>Status</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="7" class="muted">No payments in this period.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?><tr><td><b><?= e($r['reference']) ?></b><small><?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></small><?php if ($r['note']): ?><small><?= e($r['note']) ?></small><?php endif; ?></td><td><?= e(explode(' ', $r['full_name'])[0]) ?></td><td><?= $r['source'] === 'credits' ? 'Credits' : 'BCash' ?></td><td class="num">₱<?= peso((int) $r['amount_centavos']) ?></td><td class="num">₱<?= peso((int) $r['fee_centavos']) ?><small><?= $r['fee_bearer'] === 'merchant' ? 'You' : 'Customer' ?></small></td><td class="num">₱<?= peso((int) $r['merchant_net_centavos']) ?></td><td><?= portal_badge($r['status']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<nav class="pager"><span><?= number_format((int) $t['n']) ?> records</span><?php if ($page > 1): ?><a href="?<?= e(http_build_query(['from' => $from, 'to' => $to, 'page' => $page - 1])) ?>">&larr; Prev</a><?php endif; ?><?php if ($off + $per < (int) $t['n']): ?><a href="?<?= e(http_build_query(['from' => $from, 'to' => $to, 'page' => $page + 1])) ?>">Next &rarr;</a><?php endif; ?></nav>
<?php portal_footer();
