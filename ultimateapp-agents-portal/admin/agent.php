<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/agents.php';
admin_require('agents.view');
$id = (int) q('id');
$load = static function () use ($pdo, $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM agents WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
};
$a = $load();
if (!$a) { http_response_code(404); admin_header('Agent not found', 'agents'); echo '<div class="empty"><h2>Agent not found</h2></div>'; admin_footer(); exit; }
$statuses = agent_statuses();
$tempPassword = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_guard();
    $action = p('action');
    try {
        if ($action === 'status') {
            if (!admin_can('agents.review')) throw new InvalidArgumentException('Your role cannot change agent status.');
            $new = p('status'); $note = trim(p('note'));
            if (!isset($statuses[$new]) || $new === 'pending') throw new InvalidArgumentException('Choose a valid status.');
            if (in_array($new, ['rejected', 'suspended'], true) && mb_strlen($note) < 5) throw new InvalidArgumentException('Add a note for the agent explaining this decision.');
            $pdo->prepare('UPDATE agents SET status = ?, status_note = ? WHERE id = ?')->execute([$new, $note !== '' ? mb_substr($note, 0, 255) : null, $id]);
            admin_audit('agent_status', 'agent', $id, ['from' => $a['status'], 'to' => $new, 'note' => $note]);
            flash('success', 'Agent is now ' . $statuses[$new] . '.');
        } elseif ($action === 'commission') {
            if (!admin_can('agents.commissions')) throw new InvalidArgumentException('Your role cannot change commissions.');
            $to = p('to') === 'approved' ? 'approved' : 'reversed';
            $note = trim(p('note'));
            if ($to === 'reversed' && mb_strlen($note) < 4) throw new InvalidArgumentException('Give the reason for reversing this commission.');
            $stmt = $pdo->prepare('SELECT source_type, source_id, source_reference FROM agent_commissions WHERE id = ? AND agent_id = ?');
            $stmt->execute([(int) p('commission_id'), $id]);
            $c = $stmt->fetch();
            if (!$c) throw new InvalidArgumentException('Commission not found.');
            $changed = agent_tx($pdo, fn() => agent_set_commission_status($pdo, $c['source_type'], (int) $c['source_id'], $to, ($to === 'approved' ? 'Approved by admin' : 'Reversed by admin') . ($note !== '' ? ': ' . $note : '')));
            if (!$changed) throw new InvalidArgumentException('This commission was already ' . ($to === 'approved' ? 'approved or reversed.' : 'reversed.'));
            admin_audit('agent_commission_' . $to, 'agent', $id, ['reference' => $c['source_reference'], 'note' => $note]);
            flash('success', 'Commission for ' . $c['source_reference'] . ($to === 'approved' ? ' approved.' : ' reversed.'));
        } elseif ($action === 'master') {
            if (!admin_can('agents.review')) throw new InvalidArgumentException('Your role cannot change teams.');
            $code = trim(p('master_code'));
            $masterId = null;
            if ($code !== '') {
                $m = $pdo->prepare('SELECT id FROM agents WHERE code = ?');
                $m->execute([agent_normalize_code($code) ?? '']);
                $masterId = (int) ($m->fetchColumn() ?: 0) ?: null;
                if (!$masterId) throw new InvalidArgumentException('No agent has the code ' . $code . '.');
            }
            agent_set_master($pdo, $id, $masterId);
            admin_audit('agent_master_changed', 'agent', $id, ['from' => $a['parent_agent_id'], 'to' => $masterId]);
            flash('success', $masterId ? 'Agent moved under Master Agent ' . strtoupper($code) . '.' : 'Agent is now a Master Agent (no team).');
        } elseif ($action === 'reset_password') {
            if (!admin_can('agents.review')) throw new InvalidArgumentException('Your role cannot reset passwords.');
            $tempPassword = 'Ag' . substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 8) . random_int(10, 99);
            $pdo->prepare('UPDATE agents SET password_hash = ? WHERE id = ?')->execute([password_hash($tempPassword, PASSWORD_DEFAULT), $id]);
            admin_audit('agent_password_reset', 'agent', $id);
        }
    } catch (InvalidArgumentException $ex) {
        flash('error', $ex->getMessage());
    }
    if ($tempPassword === null) redirect('agent.php?id=' . $id);
    $a = $load();
}
agent_mature_pending($pdo, $id);
$a = $load();
$totals = agent_service_totals($pdo, $id);
$refs = $pdo->prepare("SELECT u.id, u.full_name, u.email, u.mobile, u.referred_at, (SELECT COUNT(*) FROM agent_commissions c WHERE c.user_id = u.id AND c.agent_id = ? AND c.status <> 'reversed') activities FROM users u WHERE u.referred_by_agent_id = ? ORDER BY u.referred_at DESC LIMIT 50");
$refs->execute([$id, $id]); $refs = $refs->fetchAll();
$cstatus = isset(agent_commission_statuses()[q('cstatus')]) ? q('cstatus') : '';
$cm = $pdo->prepare('SELECT c.*, u.full_name FROM agent_commissions c JOIN users u ON u.id = c.user_id WHERE c.agent_id = ?' . ($cstatus ? ' AND c.status = ?' : '') . ' ORDER BY c.id DESC LIMIT 100');
$cm->execute($cstatus ? [$id, $cstatus] : [$id]); $commissions = $cm->fetchAll();
$master = null;
if ($a['parent_agent_id']) { $m = $pdo->prepare('SELECT id, code, full_name FROM agents WHERE id = ?'); $m->execute([$a['parent_agent_id']]); $master = $m->fetch() ?: null; }
$tm = $pdo->prepare("SELECT g.id, g.code, g.full_name, g.status, (SELECT COALESCE(SUM(o.amount_centavos),0) FROM agent_commissions o WHERE o.agent_id = ? AND o.from_agent_id = g.id AND o.kind = 'override' AND o.status = 'approved') overrides FROM agents g WHERE g.parent_agent_id = ? ORDER BY g.id");
$tm->execute([$id, $id]); $team = $tm->fetchAll();
$po = $pdo->prepare('SELECT * FROM agent_payouts WHERE agent_id = ? ORDER BY id DESC LIMIT 20'); $po->execute([$id]); $payouts = $po->fetchAll();
admin_header($a['full_name'], 'agents');
?>
<p><a href="agents.php">&larr; All agents</a></p>
<?php if ($tempPassword): ?><div class="flash success" role="status">Temporary password: <b><?= e($tempPassword) ?></b> — give it to the agent privately; they should change it in Profile.</div><?php endif; ?>
<div class="grid kpis">
    <div class="card kpi hero"><small>Available balance</small><strong>₱<?= peso((int) $a['balance_centavos']) ?></strong><span><?= e($a['payout_method'] ? $a['payout_method'] . ' · ' . agent_mask_account($a['payout_account_no']) : 'No payout account') ?></span></div>
    <div class="card kpi"><small>Pending</small><strong>₱<?= peso(array_sum(array_column($totals, 'pending'))) ?></strong><span>Not yet earned</span></div>
    <div class="card kpi"><small>Earned (all time)</small><strong>₱<?= peso(array_sum(array_column($totals, 'earned'))) ?></strong><span><?= number_format(array_sum(array_column($totals, 'n'))) ?> activities</span></div>
    <div class="card kpi"><small>Referred customers</small><strong><?= number_format(count($refs)) ?><?= count($refs) === 50 ? '+' : '' ?></strong><span><?= number_format((int) $a['link_clicks']) ?> link clicks</span></div>
</div>
<div class="grid two mt">
    <section class="card"><h2>Profile <?= status_badge($a['status']) ?></h2>
        <dl class="facts">
            <div><dt>Referral code</dt><dd><b><?= e($a['code']) ?></b></dd></div>
            <div><dt>Role</dt><dd><?= e(agent_role($a)) ?><?php if ($master): ?> of <a class="row-link" href="agent.php?id=<?= (int) $master['id'] ?>"><?= e($master['full_name']) ?></a> (<?= e($master['code']) ?>)<?php elseif ($team): ?> · <?= count($team) ?> Sub-Agent<?= count($team) === 1 ? '' : 's' ?><?php endif; ?></dd></div>
            <div><dt>Email</dt><dd><?= e($a['email']) ?></dd></div>
            <div><dt>Mobile</dt><dd><?= e($a['mobile']) ?></dd></div>
            <div><dt>Barangay</dt><dd><?= e($a['barangay'] ?: '—') ?></dd></div>
            <div><dt>Payout account</dt><dd><?= $a['payout_account_no'] ? e($a['payout_method'] . ' · ' . $a['payout_account_name'] . ' · ' . $a['payout_account_no']) : '—' ?></dd></div>
            <div><dt>Registered</dt><dd><?= e(date('M j, Y g:i A', strtotime($a['created_at']))) ?></dd></div>
            <div><dt>Last login</dt><dd><?= $a['last_login_at'] ? e(date('M j, Y g:i A', strtotime($a['last_login_at']))) : '—' ?></dd></div>
            <?php if ($a['status_note']): ?><div><dt>Note to agent</dt><dd><?= e($a['status_note']) ?></dd></div><?php endif; ?>
        </dl>
    </section>
    <section class="card"><h2>Decision</h2>
        <?php if (admin_can('agents.review')): ?>
        <form method="post" class="stack"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="status">
            <div class="field"><label for="status">Set status</label><select id="status" name="status"><?php foreach (['approved' => 'Approve / reactivate', 'suspended' => 'Suspend', 'rejected' => 'Reject'] as $k => $l): ?><option value="<?= $k ?>"<?= $a['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="note">Note to agent</label><input id="note" name="note" type="text" maxlength="255" placeholder="Required when suspending or rejecting"></div>
            <button class="btn primary" type="submit">Save status</button></form>
        <form method="post" class="inline mt"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="master">
            <input type="text" name="master_code" maxlength="12" placeholder="Master Agent code (blank = none)" aria-label="Master Agent code" value="<?= e((string) ($master['code'] ?? '')) ?>"><button class="btn small" type="submit">Set Master Agent</button></form>
        <form method="post" class="mt" data-confirm="Create a new temporary password for this agent?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reset_password"><button class="btn small" type="submit">Reset password</button></form>
        <?php else: ?><p class="muted">Your role can view this agent only.</p><?php endif; ?>
        <p class="table-meta">Suspended agents keep their balance and can still cash out, but their link stops tagging new customers and no new commissions are recorded.</p>
    </section>
</div>
<section class="card mt"><h2>By service</h2>
    <div class="table-wrap"><table><thead><tr><th>Service</th><th>Rate now</th><th class="num">Activities</th><th class="num">Pending</th><th class="num">Earned</th></tr></thead><tbody>
    <?php foreach (agent_services() as $code => $svc): $t = $totals[$code]; ?><tr><td><b><?= e($svc['label']) ?></b></td><td><?= e(agent_rate_label($code)) ?></td><td class="num"><?= number_format($t['n']) ?></td><td class="num">₱<?= peso($t['pending']) ?></td><td class="num">₱<?= peso($t['earned']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</section>
<section class="card mt"><h2>Commissions <small>latest 100</small></h2>
    <div class="tabs"><a class="<?= $cstatus === '' ? 'active' : '' ?>" href="?id=<?= $id ?>">All</a><?php foreach (agent_commission_statuses() as $k => $l): ?><a class="<?= $cstatus === $k ? 'active' : '' ?>" href="?id=<?= $id ?>&amp;cstatus=<?= $k ?>"><?= e($l) ?></a><?php endforeach; ?></div>
    <div class="table-wrap"><table><thead><tr><th>Date</th><th>Reference</th><th>Customer</th><th class="num">Amount</th><th class="num">Commission</th><th>Status</th><th>Action</th></tr></thead><tbody>
    <?php if (!$commissions): ?><tr><td colspan="7" class="muted">No commissions.</td></tr><?php endif; ?>
    <?php foreach ($commissions as $c): ?>
        <tr><td><?= e(date('M j, Y g:i A', strtotime($c['created_at']))) ?></td><td><?= e($c['service_code']) ?><small><?= e($c['source_reference']) ?><?= ($c['kind'] ?? 'direct') === 'override' ? ' · team override' : '' ?></small></td>
            <td><a class="row-link" href="customer.php?id=<?= (int) $c['user_id'] ?>"><?= e($c['full_name']) ?></a></td>
            <td class="num">₱<?= peso((int) $c['base_centavos']) ?></td><td class="num">₱<?= peso((int) $c['amount_centavos']) ?></td>
            <td><?= status_badge($c['status'] === 'approved' ? 'approved' : ($c['status'] === 'reversed' ? 'cancelled' : 'pending')) ?><?php if ($c['note']): ?><small><?= e($c['note']) ?></small><?php endif; ?></td>
            <td><?php if ($c['status'] !== 'reversed' && admin_can('agents.commissions')): ?>
                <?php if ($c['status'] === 'pending'): ?><form method="post" class="inline" data-confirm="Approve this commission now? It is added to the agent's balance."><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="commission"><input type="hidden" name="to" value="approved"><input type="hidden" name="commission_id" value="<?= (int) $c['id'] ?>"><button class="btn small primary" type="submit">Approve</button></form><?php endif; ?>
                <details><summary>Reverse</summary><form method="post" class="inline mt"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="commission"><input type="hidden" name="to" value="reversed"><input type="hidden" name="commission_id" value="<?= (int) $c['id'] ?>"><input type="text" name="note" required placeholder="Reason" aria-label="Reason"><button class="btn small danger" type="submit">Reverse</button></form></details>
            <?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<div class="grid half mt">
    <?php if ($team): ?><section class="card"><h2>Team · Sub-Agents <small><?= count($team) ?></small></h2>
        <div class="table-wrap"><table><thead><tr><th>Sub-Agent</th><th>Status</th><th class="num">Override earned</th></tr></thead><tbody>
        <?php foreach ($team as $t): ?><tr><td><a class="row-link" href="agent.php?id=<?= (int) $t['id'] ?>"><?= e($t['full_name']) ?></a><small><?= e($t['code']) ?></small></td><td><?= status_badge($t['status']) ?></td><td class="num">₱<?= peso((int) $t['overrides']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section><?php endif; ?>
    <section class="card"><h2>Referred customers <small>latest 50</small></h2>
        <div class="table-wrap"><table><thead><tr><th>Customer</th><th>Signed up</th><th class="num">Activities</th></tr></thead><tbody>
        <?php if (!$refs): ?><tr><td colspan="3" class="muted">None yet.</td></tr><?php endif; ?>
        <?php foreach ($refs as $r): ?><tr><td><a class="row-link" href="customer.php?id=<?= (int) $r['id'] ?>"><?= e($r['full_name']) ?></a><small><?= e($r['email']) ?> · <?= e($r['mobile']) ?></small></td><td><?= $r['referred_at'] ? e(date('M j, Y', strtotime($r['referred_at']))) : '—' ?></td><td class="num"><?= (int) $r['activities'] ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
    <section class="card"><h2>Payouts <a href="agent-payouts.php">Manage</a></h2>
        <div class="table-wrap"><table><thead><tr><th>Reference</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody>
        <?php if (!$payouts): ?><tr><td colspan="3" class="muted">No payouts yet.</td></tr><?php endif; ?>
        <?php foreach ($payouts as $p): ?><tr><td><?= e($p['reference']) ?><small><?= e(date('M j, Y', strtotime($p['created_at']))) ?></small></td><td class="num">₱<?= peso((int) $p['amount_centavos']) ?></td><td><?= status_badge($p['status'] === 'requested' ? 'pending' : $p['status']) ?><small><?= e((string) ($p['bank_reference'] ?: $p['note'])) ?></small></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
</div>
<?php admin_footer();
