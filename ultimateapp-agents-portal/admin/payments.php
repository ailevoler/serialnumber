<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/p2m.php';
admin_require('payments.view');
$type = in_array(q('type'), ['p2m', 'p2p', 'topups'], true) ? q('type') : 'p2m';
$search = q('q');
if ($search !== '' && q('type') === '') {
    if (str_starts_with(strtoupper($search), 'QP-')) $type = 'p2p';
    elseif (str_starts_with(strtoupper($search), 'UA-')) $type = 'topups';
}
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/D', q('from')) ? q('from') : date('Y-m-d', strtotime('-30 days'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/D', q('to')) ? q('to') : date('Y-m-d');
if ($search !== '') { $from = '2000-01-01'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && p('action') === 'refund') {
    admin_post_guard();
    if (!admin_can('payments.refund')) { flash('error', 'Your role cannot refund payments.'); }
    else {
        try {
            $ref = p2m_refund($pdo, (int) p('id'), (int) admin_current()['id']);
            admin_audit('p2m_refunded', 'payment', $ref, p('reason'));
            flash('success', 'Payment ' . $ref . ' refunded in full to the customer.');
        } catch (InvalidArgumentException $ex) { flash('error', $ex->getMessage()); }
    }
    redirect('payments.php?' . http_build_query(['type' => 'p2m', 'q' => p('q')]));
}

$params = [$from . ' 00:00:00', $to . ' 23:59:59'];
if ($type === 'p2m') {
    $where = 'mp.created_at BETWEEN ? AND ?';
    if ($search !== '') { $where .= ' AND (mp.reference LIKE ? OR u.full_name LIKE ? OR m.business_name LIKE ?)'; array_push($params, "%$search%", "%$search%", "%$search%"); }
    if (in_array(q('source'), ['credits', 'boracay_cash'], true)) { $where .= ' AND mp.source = ?'; $params[] = q('source'); }
    if (in_array(q('status'), ['completed', 'refunded'], true)) { $where .= ' AND mp.status = ?'; $params[] = q('status'); }
    $base = " FROM merchant_payments mp JOIN users u ON u.id = mp.user_id JOIN merchants m ON m.id = mp.merchant_id WHERE $where";
    $cols = 'mp.*, u.full_name, m.business_name';
    $sumSql = "SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN mp.status='completed' THEN mp.amount_centavos END),0) gross, COALESCE(SUM(CASE WHEN mp.status='completed' THEN mp.fee_centavos END),0) fees$base";
    $csv = ['Reference', 'Date', 'Customer', 'Merchant', 'Source', 'Amount', 'Charge', 'Charge paid by', 'Customer debit', 'Merchant net', 'Status'];
    $csvRow = static fn($r) => [$r['reference'], $r['created_at'], $r['full_name'], $r['business_name'], p2m_source_label($r['source']), centavos_to_decimal((int) $r['amount_centavos']), centavos_to_decimal((int) $r['fee_centavos']), $r['fee_bearer'], centavos_to_decimal((int) $r['customer_debit_centavos']), centavos_to_decimal((int) $r['merchant_net_centavos']), $r['status']];
    $order = 'mp.id DESC';
} elseif ($type === 'p2p') {
    $where = 'q.created_at BETWEEN ? AND ?';
    if ($search !== '') { $where .= ' AND (q.reference LIKE ? OR a.full_name LIKE ? OR b.full_name LIKE ?)'; array_push($params, "%$search%", "%$search%", "%$search%"); }
    $base = " FROM qr_payments q JOIN users a ON a.id = q.payer_id JOIN users b ON b.id = q.payee_id WHERE $where";
    $cols = 'q.*, a.full_name payer, b.full_name payee';
    $sumSql = "SELECT COUNT(*) n, COALESCE(ROUND(SUM(q.credits)*100),0) gross, 0 fees$base";
    $csv = ['Reference', 'Date', 'From', 'To', 'Credits', 'Note'];
    $csvRow = static fn($r) => [$r['reference'], $r['created_at'], $r['payer'], $r['payee'], $r['credits'], $r['note']];
    $order = 'q.id DESC';
} else {
    $where = 't.created_at BETWEEN ? AND ?';
    if ($search !== '') { $where .= ' AND (t.reference LIKE ? OR u.full_name LIKE ?)'; array_push($params, "%$search%", "%$search%"); }
    if (in_array(q('status'), ['pending', 'paid'], true)) { $where .= ' AND t.status = ?'; $params[] = q('status'); }
    $base = " FROM credit_topups t JOIN users u ON u.id = t.user_id WHERE $where";
    $cols = 't.*, u.full_name';
    $sumSql = "SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN t.status='paid' THEN t.amount_centavos END),0) gross, COALESCE(SUM(CASE WHEN t.status='paid' THEN t.fee_centavos END),0) fees$base";
    $csv = ['Reference', 'Date', 'Customer', 'Method', 'Mode', 'Credits', 'Amount paid', 'Fee', 'Status', 'Paid at'];
    $csvRow = static fn($r) => [$r['reference'], $r['created_at'], $r['full_name'], $r['method'], $r['mode'], $r['credits'], centavos_to_decimal((int) $r['amount_centavos']), centavos_to_decimal((int) $r['fee_centavos']), $r['status'], $r['paid_at']];
    $order = 't.id DESC';
}
if (q('export') === 'csv') {
    $stmt = $pdo->prepare("SELECT $cols$base ORDER BY $order");
    $stmt->execute($params);
    admin_audit('export_payments', null, null, $type);
    csv_download("$type-$from-to-$to.csv", $csv, (function () use ($stmt, $csvRow) { foreach ($stmt as $r) yield $csvRow($r); })());
}
$sum = $pdo->prepare($sumSql); $sum->execute($params); $sum = $sum->fetch();
[$page, $per, $offset] = paging(30);
$stmt = $pdo->prepare("SELECT $cols$base ORDER BY $order LIMIT $per OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$tab = static fn(string $t, string $label) => '<a class="' . ($type === $t ? 'active' : '') . '" href="?type=' . $t . '">' . $label . '</a>';
admin_header('Payments', 'payments');
?>
<div class="tabs"><?= $tab('p2m', 'Merchant payments (P2M)') . $tab('p2p', 'Person-to-person (P2P)') . $tab('topups', 'Top-ups') ?></div>
<form class="toolbar" method="get"><input type="hidden" name="type" value="<?= e($type) ?>">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Reference or name" aria-label="Search">
    <label>From <input type="date" name="from" value="<?= e($from === '2000-01-01' ? '' : $from) ?>"></label><label>To <input type="date" name="to" value="<?= e($to) ?>"></label>
    <?php if ($type === 'p2m'): ?><select name="source" aria-label="Source"><option value="">All sources</option><option value="credits"<?= q('source') === 'credits' ? ' selected' : '' ?>>Credits</option><option value="boracay_cash"<?= q('source') === 'boracay_cash' ? ' selected' : '' ?>>BCash</option></select>
    <select name="status" aria-label="Status"><option value="">All statuses</option><option value="completed"<?= q('status') === 'completed' ? ' selected' : '' ?>>Completed</option><option value="refunded"<?= q('status') === 'refunded' ? ' selected' : '' ?>>Refunded</option></select><?php endif; ?>
    <?php if ($type === 'topups'): ?><select name="status" aria-label="Status"><option value="">All statuses</option><option value="paid"<?= q('status') === 'paid' ? ' selected' : '' ?>>Paid</option><option value="pending"<?= q('status') === 'pending' ? ' selected' : '' ?>>Pending</option></select><?php endif; ?>
    <button class="btn" type="submit">Apply</button><span class="spacer"></span>
    <a class="btn" href="?<?= e(http_build_query(array_merge($_GET, ['type' => $type, 'export' => 'csv']))) ?>"><?= icon('download') ?> Export CSV</a>
</form>
<div class="grid kpis">
    <div class="card kpi"><small>Records</small><strong><?= number_format((int) $sum['n']) ?></strong><span><?= e(date('M j', strtotime($from === '2000-01-01' ? '2000-01-01' : $from))) ?> – <?= e(date('M j, Y', strtotime($to))) ?></span></div>
    <div class="card kpi"><small><?= $type === 'topups' ? 'Collected' : 'Volume' ?></small><strong>₱<?= peso((int) $sum['gross']) ?></strong><span><?= $type === 'p2m' ? 'Completed only' : ($type === 'topups' ? 'Paid only' : 'All transfers') ?></span></div>
    <?php if ($type !== 'p2p'): ?><div class="card kpi"><small><?= $type === 'p2m' ? 'Charge revenue' : 'Service fees' ?></small><strong>₱<?= peso((int) $sum['fees']) ?></strong><span>Same period</span></div><?php endif; ?>
</div>
<div class="table-wrap mt"><table>
<?php if ($type === 'p2m'): ?>
    <thead><tr><th>Reference</th><th>Customer → Merchant</th><th>Source</th><th class="num">Amount</th><th class="num">Charge</th><th class="num">Merchant net</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><b><?= e($r['reference']) ?></b><small><?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></small></td><td><a href="customer.php?id=<?= (int) $r['user_id'] ?>"><?= e($r['full_name']) ?></a> → <a href="merchant.php?id=<?= (int) $r['merchant_id'] ?>"><?= e($r['business_name']) ?></a><?php if ($r['note']): ?><small><?= e($r['note']) ?></small><?php endif; ?></td><td><?= e(p2m_source_label($r['source'])) ?></td><td class="num">₱<?= peso((int) $r['amount_centavos']) ?></td><td class="num">₱<?= peso((int) $r['fee_centavos']) ?><small><?= $r['fee_bearer'] === 'customer' ? 'Customer' : 'Merchant' ?></small></td><td class="num">₱<?= peso((int) $r['merchant_net_centavos']) ?></td><td><?= status_badge($r['status']) ?></td>
        <td><?php if ($r['status'] === 'completed' && admin_can('payments.refund')): ?><form method="post" data-confirm="Refund ₱<?= peso((int) $r['customer_debit_centavos']) ?> to the customer and take ₱<?= peso((int) $r['merchant_net_centavos']) ?> back from the merchant balance?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="refund"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="q" value="<?= e($r['reference']) ?>"><button class="btn small danger" type="submit">Refund</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php elseif ($type === 'p2p'): ?>
    <thead><tr><th>Reference</th><th>From</th><th>To</th><th class="num">Credits</th><th>Note</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><b><?= e($r['reference']) ?></b><small><?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></small></td><td><a href="customer.php?id=<?= (int) $r['payer_id'] ?>"><?= e($r['payer']) ?></a></td><td><a href="customer.php?id=<?= (int) $r['payee_id'] ?>"><?= e($r['payee']) ?></a></td><td class="num"><?= number_format((float) $r['credits'], 2) ?></td><td><?= e($r['note'] ?? '') ?></td></tr><?php endforeach; ?>
<?php else: ?>
    <thead><tr><th>Reference</th><th>Customer</th><th>Method</th><th class="num">Credits</th><th class="num">Paid</th><th class="num">Fee</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><b><?= e($r['reference']) ?></b><small><?= e(strtoupper($r['mode'])) ?> · <?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></small></td><td><a href="customer.php?id=<?= (int) $r['user_id'] ?>"><?= e($r['full_name']) ?></a></td><td><?= e(strtoupper($r['method'])) ?></td><td class="num"><?= number_format((float) $r['credits']) ?></td><td class="num">₱<?= peso((int) $r['amount_centavos']) ?></td><td class="num">₱<?= peso((int) $r['fee_centavos']) ?></td><td><?= status_badge($r['status']) ?></td></tr><?php endforeach; ?>
<?php endif; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="muted">Nothing in this period.</td></tr><?php endif; ?>
    </tbody></table></div>
<?= pager($page, $per, (int) $sum['n']) ?>
<?php admin_footer();
