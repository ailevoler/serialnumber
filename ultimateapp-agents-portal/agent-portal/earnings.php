<?php
require_once __DIR__ . '/_bootstrap.php';
$a = agent_require();
$id = (int) $a['id'];
if ($a['status'] === 'approved') agent_mature_pending($pdo, $id);
$service = isset(agent_services()[aq('service')]) ? aq('service') : '';
$status = isset(agent_commission_statuses()[aq('status')]) ? aq('status') : '';
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/D', aq('from')) ? aq('from') : date('Y-m-d', strtotime('-89 days'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/D', aq('to')) ? aq('to') : date('Y-m-d');
$where = 'c.agent_id = ? AND c.created_at BETWEEN ? AND ?';
$params = [$id, $from . ' 00:00:00', $to . ' 23:59:59'];
if ($service) { $where .= ' AND c.service_code = ?'; $params[] = $service; }
if ($status) { $where .= ' AND c.status = ?'; $params[] = $status; }
$base = ' FROM agent_commissions c JOIN users u ON u.id = c.user_id WHERE ' . $where;
if (aq('export') === 'csv') {
    $s = $pdo->prepare('SELECT c.created_at, c.source_reference, c.service_code, u.full_name, c.base_centavos, c.amount_centavos, c.status, c.approved_at' . $base . ' ORDER BY c.id');
    $s->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="agent-earnings-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'Reference', 'Service', 'Customer', 'Activity amount', 'Commission', 'Status', 'Earned on']);
    foreach ($s as $r) fputcsv($out, [$r['created_at'], $r['source_reference'], $r['service_code'], agent_mask_name($r['full_name']), centavos_to_decimal((int) $r['base_centavos']), centavos_to_decimal((int) $r['amount_centavos']), agent_commission_statuses()[$r['status']], $r['approved_at']]);
    exit;
}
$t = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN c.status = 'approved' THEN c.amount_centavos END),0) earned, COALESCE(SUM(CASE WHEN c.status = 'pending' THEN c.amount_centavos END),0) pending, COALESCE(SUM(CASE WHEN c.status = 'reversed' THEN c.amount_centavos END),0) reversed" . $base);
$t->execute($params); $t = $t->fetch();
$page = max(1, (int) aq('page') ?: 1); $per = 30; $off = ($page - 1) * $per;
$s = $pdo->prepare('SELECT c.*, u.full_name, fa.code from_code' . str_replace(' WHERE ', ' LEFT JOIN agents fa ON fa.id = c.from_agent_id WHERE ', $base) . " ORDER BY c.id DESC LIMIT $per OFFSET $off");
$s->execute($params); $rows = $s->fetchAll();
$qs = static fn(array $extra): string => e(http_build_query(array_filter(['service' => $service, 'status' => $status, 'from' => $from, 'to' => $to] + $extra, static fn($v) => $v !== '' && $v !== null)));
agent_header('Earnings', 'earnings');
?>
<form class="toolbar" method="get">
    <select name="service" aria-label="Service"><option value="">All services</option><?php foreach (agent_services() as $code => $svc): ?><option value="<?= e($code) ?>"<?= $service === $code ? ' selected' : '' ?>><?= e($svc['label']) ?></option><?php endforeach; ?></select>
    <select name="status" aria-label="Status"><option value="">All statuses</option><?php foreach (agent_commission_statuses() as $k => $l): ?><option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <label>From <input type="date" name="from" value="<?= e($from) ?>"></label><label>To <input type="date" name="to" value="<?= e($to) ?>"></label>
    <button class="btn" type="submit">Apply</button><span class="spacer"></span><a class="btn" href="?<?= $qs(['export' => 'csv']) ?>">Export CSV</a>
</form>
<div class="grid kpis"><div class="card kpi hero"><small>Earned</small><strong>₱<?= peso((int) $t['earned']) ?></strong><span>Added to your balance</span></div><div class="card kpi"><small>Pending</small><strong>₱<?= peso((int) $t['pending']) ?></strong><span>Waiting for completion</span></div><div class="card kpi"><small>Reversed</small><strong>₱<?= peso((int) $t['reversed']) ?></strong><span>Cancelled / refunded</span></div><div class="card kpi"><small>Activities</small><strong><?= number_format((int) $t['n']) ?></strong><span>In period</span></div></div>
<div class="table-wrap mt"><table><thead><tr><th>Date</th><th>Service</th><th>Customer</th><th class="num">Activity amount</th><th class="num">Rate</th><th class="num">Commission</th><th>Status</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="7" class="muted">No commissions in this period.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?><tr><td><?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?><small><?= e($r['source_reference']) ?></small></td><td><?= e($r['service_code']) ?><?php if (($r['kind'] ?? 'direct') === 'override'): ?><small>Team override · <?= e((string) $r['from_code']) ?></small><?php endif; ?></td><td><?= e(agent_mask_name($r['full_name'])) ?></td><td class="num">₱<?= peso((int) $r['base_centavos']) ?></td>
    <td class="num"><?= e(trim(((int) $r['rate_bp'] ? rtrim(rtrim(number_format((int) $r['rate_bp'] / 100, 2, '.', ''), '0'), '.') . '%' : '') . ((int) $r['fixed_centavos'] ? ' + ₱' . peso((int) $r['fixed_centavos']) : ''), ' +')) ?></td>
    <td class="num">₱<?= peso((int) $r['amount_centavos']) ?></td><td><?= agent_badge($r['status']) ?><?php if ($r['note'] && $r['status'] === 'reversed'): ?><small><?= e($r['note']) ?></small><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<nav class="pager"><span><?= number_format((int) $t['n']) ?> records</span><?php if ($page > 1): ?><a href="?<?= $qs(['page' => $page - 1]) ?>">&larr; Prev</a><?php endif; ?><?php if ($off + $per < (int) $t['n']): ?><a href="?<?= $qs(['page' => $page + 1]) ?>">Next &rarr;</a><?php endif; ?></nav>
<?php agent_footer();
