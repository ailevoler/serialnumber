<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/agents.php';
$admin = admin_require('agent_payouts.manage');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_guard();
    try {
        $action = p('action');
        $p = agent_payout_decide($pdo, (int) p('id'), $action, p('bank_reference'), p('note'), (int) $admin['id']);
        admin_audit('agent_payout_' . $action, 'agent_payout', (int) p('id'), ['reference' => $p['reference'], 'bank_reference' => p('bank_reference'), 'note' => p('note')]);
        flash('success', 'Payout ' . $p['reference'] . ($action === 'paid' ? ' marked as paid.' : ' returned to the agent balance.'));
    } catch (InvalidArgumentException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('agent-payouts.php' . (q('status') ? '?status=' . rawurlencode(q('status')) : ''));
}
$status = in_array(q('status'), ['requested', 'paid', 'rejected'], true) ? q('status') : 'requested';
$sum = $pdo->query("SELECT status, COUNT(*) n, COALESCE(SUM(amount_centavos),0) amt FROM agent_payouts GROUP BY status")->fetchAll(PDO::FETCH_UNIQUE);
[$page, $per, $offset] = paging();
$c = $pdo->prepare('SELECT COUNT(*) FROM agent_payouts WHERE status = ?'); $c->execute([$status]); $total = (int) $c->fetchColumn();
$stmt = $pdo->prepare("SELECT p.*, g.full_name, g.code, g.mobile, a.name processor FROM agent_payouts p JOIN agents g ON g.id = p.agent_id LEFT JOIN admin_users a ON a.id = p.processed_by WHERE p.status = ? ORDER BY p.id " . ($status === 'requested' ? 'ASC' : 'DESC') . " LIMIT $per OFFSET $offset");
$stmt->execute([$status]);
$rows = $stmt->fetchAll();
admin_header('Agent payouts', 'agent-payouts');
?>
<div class="grid kpis">
    <div class="card kpi hero"><small>Awaiting transfer</small><strong>₱<?= peso((int) ($sum['requested']['amt'] ?? 0)) ?></strong><span><?= (int) ($sum['requested']['n'] ?? 0) ?> request(s)</span></div>
    <div class="card kpi"><small>Paid to agents</small><strong>₱<?= peso((int) ($sum['paid']['amt'] ?? 0)) ?></strong><span><?= (int) ($sum['paid']['n'] ?? 0) ?> payout(s)</span></div>
    <div class="card kpi"><small>Returned</small><strong>₱<?= peso((int) ($sum['rejected']['amt'] ?? 0)) ?></strong><span>Back to agent balances</span></div>
</div>
<div class="tabs mt"><?php foreach (['requested' => 'To pay', 'paid' => 'Paid', 'rejected' => 'Returned'] as $k => $l): ?><a class="<?= $status === $k ? 'active' : '' ?>" href="?status=<?= $k ?>"><?= $l ?><span><?= (int) ($sum[$k]['n'] ?? 0) ?></span></a><?php endforeach; ?></div>
<div class="table-wrap"><table>
    <thead><tr><th>Reference</th><th>Agent</th><th>Send to</th><th class="num">Amount</th><th><?= $status === 'requested' ? 'Action' : 'Result' ?></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted">Nothing here.</td></tr><?php endif; ?>
    <?php foreach ($rows as $p): ?>
        <tr><td><?= e($p['reference']) ?><small><?= e(date('M j, Y g:i A', strtotime($p['created_at']))) ?></small></td>
            <td><a class="row-link" href="agent.php?id=<?= (int) $p['agent_id'] ?>"><?= e($p['full_name']) ?></a><small><?= e($p['code']) ?> · <?= e($p['mobile']) ?></small></td>
            <td><?= e($p['payout_method']) ?><small><?= e($p['payout_account_name']) ?> · <?= e($p['payout_account_no']) ?></small></td>
            <td class="num">₱<?= peso((int) $p['amount_centavos']) ?></td>
            <td><?php if ($status === 'requested'): ?>
                <form method="post" class="inline" data-confirm="Mark this payout as sent?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="paid"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="text" name="bank_reference" required placeholder="Transfer ref no." aria-label="Transfer reference"><button class="btn small primary" type="submit">Mark paid</button></form>
                <details class="mt"><summary>Return to balance</summary><form method="post" class="inline mt"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="rejected"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="text" name="note" required placeholder="Reason (agent sees this)" aria-label="Reason"><button class="btn small danger" type="submit">Return</button></form></details>
            <?php else: ?><?= status_badge($p['status']) ?><small><?= e((string) ($p['bank_reference'] ?? $p['note'] ?? '')) ?><?= $p['processor'] ? ' · ' . e($p['processor']) : '' ?> · <?= e(date('M j', strtotime((string) $p['processed_at']))) ?></small><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?= pager($page, $per, $total) ?>
<?php admin_footer();
