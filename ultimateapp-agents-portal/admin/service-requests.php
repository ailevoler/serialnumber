<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/service-seeds.php';
require_once __DIR__ . '/../includes/agents.php';
$admin = admin_require('service_requests.view');
$services = ['UPass', 'UGo', 'ULocal'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_guard();
    try {
        if (!admin_can('service_requests.manage')) throw new InvalidArgumentException('Your role cannot update requests.');
        $to = p('to') === 'completed' ? 'completed' : 'cancelled';
        $ref = agent_tx($pdo, function () use ($pdo, $to) {
            $stmt = $pdo->prepare("SELECT id, reference, status FROM service_requests WHERE id = ? AND service_code IN ('UPass','UGo','ULocal') FOR UPDATE");
            $stmt->execute([(int) p('id')]);
            $r = $stmt->fetch();
            if (!$r) throw new InvalidArgumentException('Request not found.');
            if ($r['status'] !== 'pending') throw new InvalidArgumentException('This request was already ' . $r['status'] . '.');
            $pdo->prepare('UPDATE service_requests SET status = ? WHERE id = ?')->execute([$to, $r['id']]);
            // Completing approves the referral agent's commission; cancelling reverses it.
            if ($to === 'completed') agent_approve_source($pdo, 'service_request', (int) $r['id'], 'Request completed');
            else agent_reverse_source($pdo, 'service_request', (int) $r['id'], 'Request cancelled');
            return $r['reference'];
        });
        admin_audit('service_request_' . $to, 'service_request', (int) p('id'), ['reference' => $ref]);
        flash('success', 'Request ' . $ref . ' marked ' . $to . '.');
    } catch (InvalidArgumentException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('service-requests.php?' . http_build_query(array_filter(['status' => q('status'), 'service' => q('service')])));
}
$status = in_array(q('status'), ['pending', 'completed', 'cancelled', 'closed'], true) ? q('status') : 'pending';
$service = in_array(q('service'), $services, true) ? q('service') : '';
$counts = $pdo->query("SELECT status, COUNT(*) FROM service_requests WHERE service_code IN ('UPass','UGo','ULocal') GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$where = "r.service_code IN ('UPass','UGo','ULocal') AND r.status = ?"; $params = [$status];
if ($service) { $where .= ' AND r.service_code = ?'; $params[] = $service; }
[$page, $per, $offset] = paging();
$c = $pdo->prepare("SELECT COUNT(*) FROM service_requests r WHERE $where"); $c->execute($params); $total = (int) $c->fetchColumn();
$stmt = $pdo->prepare("SELECT r.*, u.full_name, u.mobile, g.code agent_code, g.id agent_id, ac.amount_centavos agent_amount, ac.status agent_status
    FROM service_requests r JOIN users u ON u.id = r.user_id LEFT JOIN agents g ON g.id = u.referred_by_agent_id
    LEFT JOIN agent_commissions ac ON ac.source_type = 'service_request' AND ac.source_id = r.id
    WHERE $where ORDER BY r.id " . ($status === 'pending' ? 'ASC' : 'DESC') . " LIMIT $per OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();
admin_header('UPass / UGo / ULocal requests', 'service-requests');
?>
<p class="muted">Customer requests from UPass, UGo and ULocal. Mark a request <b>completed</b> once the service was delivered: this releases the referral agent's commission. Cancelling reverses it.</p>
<div class="tabs"><?php foreach (['pending' => 'To do', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'closed' => 'Closed (old)'] as $k => $l): ?><a class="<?= $status === $k ? 'active' : '' ?>" href="?<?= e(http_build_query(array_filter(['status' => $k, 'service' => $service]))) ?>"><?= $l ?><span><?= (int) ($counts[$k] ?? 0) ?></span></a><?php endforeach; ?></div>
<form class="toolbar" method="get"><input type="hidden" name="status" value="<?= e($status) ?>"><select name="service" data-autosubmit aria-label="Service"><option value="">All services</option><?php foreach ($services as $s): ?><option<?= $service === $s ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></form>
<div class="table-wrap"><table>
    <thead><tr><th>Reference</th><th>Customer</th><th>Listing</th><th class="num">Value</th><th>Agent</th><th><?= $status === 'pending' ? 'Action' : 'Status' ?></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted">Nothing here.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $item = service_seed_item($r['service_code'], $r['item_id']); $value = isset($item['price']) ? (int) $item['price'] * 100 * (int) $r['quantity'] : null; ?>
        <tr><td><?= e($r['reference']) ?><small><?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></small></td>
            <td><a class="row-link" href="customer.php?id=<?= (int) $r['user_id'] ?>"><?= e($r['full_name']) ?></a><small><?= e($r['mobile']) ?></small></td>
            <td><b><?= e($r['service_code']) ?></b> · <?= e($item['name'] ?? $r['item_id']) ?><small>Qty <?= (int) $r['quantity'] ?><?= $r['preferred_date'] ? ' · ' . e($r['preferred_date']) : '' ?><?= $r['notes'] ? ' · ' . e(mb_strimwidth((string) $r['notes'], 0, 80, '…')) : '' ?></small></td>
            <td class="num"><?= $value !== null ? '₱' . peso($value) : '—' ?></td>
            <td><?php if ($r['agent_id']): ?><a class="row-link" href="agent.php?id=<?= (int) $r['agent_id'] ?>"><?= e($r['agent_code']) ?></a><small><?= $r['agent_amount'] !== null ? '₱' . peso((int) $r['agent_amount']) . ' · ' . e(agent_commission_statuses()[$r['agent_status']] ?? $r['agent_status']) : 'No commission' ?></small><?php else: ?>—<?php endif; ?></td>
            <td><?php if ($status === 'pending' && admin_can('service_requests.manage')): ?>
                <form method="post" class="inline" data-confirm="Mark this request as completed?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="to" value="completed"><button class="btn small primary" type="submit">Completed</button></form>
                <form method="post" class="inline" data-confirm="Cancel this request?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="to" value="cancelled"><button class="btn small danger" type="submit">Cancel</button></form>
            <?php else: ?><?= status_badge($r['status']) ?><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?= pager($page, $per, $total) ?>
<?php admin_footer();
