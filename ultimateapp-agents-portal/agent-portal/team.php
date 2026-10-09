<?php
require_once __DIR__ . '/_bootstrap.php';
$a = agent_require();
$id = (int) $a['id'];
if (!empty($a['parent_agent_id'])) redirect('dashboard.php');
agent_header('My team', 'team');
if ($a['status'] !== 'approved') { agent_locked_panel($a); agent_footer(); exit; }
$invite = preg_replace('#/register\.php\?ref=.*$#', '/agent-portal/register.php?master=' . rawurlencode($a['code']), agent_referral_link($a));
$rows = $pdo->prepare("SELECT g.id, g.code, g.full_name, g.mobile, g.status, g.created_at,
        (SELECT COUNT(*) FROM users u WHERE u.referred_by_agent_id = g.id) referrals,
        (SELECT COALESCE(SUM(c.amount_centavos),0) FROM agent_commissions c WHERE c.agent_id = g.id AND c.kind = 'direct' AND c.status = 'approved' AND c.approved_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) sub_month,
        (SELECT COALESCE(SUM(o.amount_centavos),0) FROM agent_commissions o WHERE o.agent_id = ? AND o.from_agent_id = g.id AND o.kind = 'override' AND o.status = 'approved' AND o.approved_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) over_month,
        (SELECT COALESCE(SUM(o.amount_centavos),0) FROM agent_commissions o WHERE o.agent_id = ? AND o.from_agent_id = g.id AND o.kind = 'override' AND o.status = 'approved') over_all,
        (SELECT COALESCE(SUM(o.amount_centavos),0) FROM agent_commissions o WHERE o.agent_id = ? AND o.from_agent_id = g.id AND o.kind = 'override' AND o.status = 'pending') over_pending
    FROM agents g WHERE g.parent_agent_id = ? ORDER BY g.created_at DESC");
$rows->execute([$id, $id, $id, $id]);
$rows = $rows->fetchAll();
$tot = ['month' => array_sum(array_column($rows, 'over_month')), 'all' => array_sum(array_column($rows, 'over_all')), 'pending' => array_sum(array_column($rows, 'over_pending'))];
$active = count(array_filter($rows, static fn($r) => $r['status'] === 'approved'));
?>
<div class="grid kpis">
    <div class="card kpi hero"><small>Team override this month</small><strong>₱<?= peso((int) $tot['month']) ?></strong><span>From your Sub-Agents' customers</span></div>
    <div class="card kpi"><small>Override pending</small><strong>₱<?= peso((int) $tot['pending']) ?></strong><span>Becomes available when completed</span></div>
    <div class="card kpi"><small>Override all time</small><strong>₱<?= peso((int) $tot['all']) ?></strong><span>Added to your balance</span></div>
    <div class="card kpi"><small>Sub-Agents</small><strong><?= count($rows) ?></strong><span><?= $active ?> active</span></div>
</div>
<div class="grid two mt">
    <section class="card"><h2>Your Sub-Agents</h2>
        <div class="table-wrap"><table><thead><tr><th>Sub-Agent</th><th>Status</th><th class="num">Customers</th><th class="num">Their earnings<br>this month</th><th class="num">Your override<br>this month</th></tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="5" class="muted">No Sub-Agents yet. Send them your team invite link.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?><tr><td><b><?= e($r['full_name']) ?></b><small><?= e($r['code']) ?> · joined <?= e(date('M j, Y', strtotime($r['created_at']))) ?></small></td><td><?= agent_badge($r['status'], 'account') ?></td><td class="num"><?= number_format((int) $r['referrals']) ?></td><td class="num">₱<?= peso((int) $r['sub_month']) ?></td><td class="num">₱<?= peso((int) $r['over_month']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <p class="table-meta">To remove someone from your team, contact Ultimate App support.</p>
    </section>
    <section class="card share-card" data-agent-share data-link="<?= e($invite) ?>" data-code="<?= e($a['code']) ?>" data-label="Team invite link" data-code-label="Master Agent code" data-text="Join my Ultimate App agent team and earn from rides, food and tours in Boracay. Use my Master Agent code <?= e($a['code']) ?>:">
        <h2>Add a Sub-Agent</h2>
        <p class="muted">Send this invite link. Your Sub-Agent signs up with their own email and password and joins your team<?= setting('agents.sub_auto_approve', '0') === '1' ? '.' : ' after Ultimate App approves them.' ?></p>
        <div class="copy-row"><input type="text" readonly value="<?= e($invite) ?>" aria-label="Team invite link" data-share-link><button class="btn primary" type="button" data-copy="link">Copy link</button></div>
        <div class="code-row"><span>Master Agent code</span><strong><?= e($a['code']) ?></strong><button class="btn small" type="button" data-copy="code">Copy code</button></div>
        <p class="muted small">Signing up without the link? They enter this code in the <b>Master Agent code</b> box.</p>
        <div class="form-actions"><button class="btn" type="button" data-share>Share…</button></div>
        <p class="table-meta" data-share-status role="status"></p>
        <h3 class="mt">How you earn from your team</h3>
        <div class="table-wrap"><table><thead><tr><th>Service</th><th>Sub-Agent</th><th>You (on top)</th></tr></thead><tbody>
        <?php foreach (agent_services() as $code => $svc): ?><tr><td><?= e($svc['label']) ?></td><td><?= e(agent_rate_label($code, 'sub')) ?></td><td><?= e(agent_rate_label($code, 'override')) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <p class="table-meta">% of the activity amount. Your override is paid by Ultimate App; it never reduces your Sub-Agent's earnings.</p>
    </section>
</div>
<?php agent_footer(['assets/agent.js?v=2']);
