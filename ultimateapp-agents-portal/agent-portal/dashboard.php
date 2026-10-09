<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/kyc.php';
$a = agent_require();
$id = (int) $a['id'];
if ($a['status'] === 'approved' && agent_mature_pending($pdo, $id)) $a = agent_current(true);
$banner = [
    'pending' => ['info', 'Application submitted', 'Our team will review your details soon. Your referral code starts working once you are approved.'],
    'approved' => ['good', 'You are an active agent', 'Share your link or code. You earn when your referred customers use URide, UPass, UGo, ULocal and UEat.'],
    'suspended' => ['bad', 'Account suspended', ($a['status_note'] ?: 'Your referral link is paused and no new commissions are recorded.') . ' Contact support to resolve this.'],
    'rejected' => ['bad', 'Application not approved', $a['status_note'] ?: 'Contact support for more information.'],
][$a['status']];
$totals = agent_service_totals($pdo, $id);
$s = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referred_by_agent_id = ?"); $s->execute([$id]); $referrals = (int) $s->fetchColumn();
$s = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM agent_commissions WHERE agent_id = ? AND status <> 'reversed'"); $s->execute([$id]); $activeReferrals = (int) $s->fetchColumn();
$s = $pdo->prepare("SELECT COALESCE(SUM(amount_centavos),0) FROM agent_commissions WHERE agent_id = ? AND status = 'approved' AND approved_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"); $s->execute([$id]); $month = (int) $s->fetchColumn();
$pending = array_sum(array_column($totals, 'pending'));
$tier = agent_own_tier($a);
$master = agent_master_of($a);
$s = $pdo->prepare("SELECT COALESCE(SUM(amount_centavos),0) FROM agent_commissions WHERE agent_id = ? AND kind = 'override' AND status = 'approved' AND approved_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"); $s->execute([$id]); $teamMonth = (int) $s->fetchColumn();
$s = $pdo->prepare("SELECT COUNT(*) FROM agents WHERE parent_agent_id = ?"); $s->execute([$id]); $teamSize = (int) $s->fetchColumn();
$recent = $pdo->prepare('SELECT c.*, u.full_name FROM agent_commissions c JOIN users u ON u.id = c.user_id WHERE c.agent_id = ? ORDER BY c.id DESC LIMIT 8');
$recent->execute([$id]); $recent = $recent->fetchAll();
$link = agent_referral_link($a);
$st = agent_settings();
agent_header('Hello, ' . explode(' ', $a['full_name'])[0], 'dashboard');
?>
<?php $kycState = kyc_status($pdo, 'agent', $id); if ($kycState !== 'approved'): ?><div class="status-banner <?= $kycState === 'pending' ? 'info' : 'warn' ?>"><div><h2>Identity verification <?= kyc_badge($kycState) ?></h2><p><?= $kycState === 'pending' ? 'We are reviewing your ID and selfie.' : 'Verify your ID and do a quick selfie check. It is needed for approval and before you can cash out.' ?></p><?php if ($kycState !== 'pending'): ?><p class="mt"><a class="btn small primary" href="kyc.php">Verify now</a></p><?php endif; ?></div></div><?php endif; ?>
<div class="status-banner <?= e($banner[0]) ?>"><div><h2><?= e($banner[1]) ?> <?= agent_badge($a['status'], 'account') ?> <span class="badge info"><?= e(agent_role($a)) ?></span></h2><p><?= e($banner[2]) ?></p>
    <?php if ($master): ?><p class="mt">Your Master Agent: <b><?= e($master['full_name']) ?></b> (<?= e($master['code']) ?>)</p><?php elseif ($a['status'] === 'approved'): ?><p class="mt">Master Agent · <?= $teamSize ?> Sub-Agent<?= $teamSize === 1 ? '' : 's' ?> · <a href="team.php">Invite or view your team</a></p><?php endif; ?></div></div>
<?php if ($a['status'] === 'approved'): ?>
<div class="grid kpis">
    <div class="card kpi hero"><small>Available balance</small><strong>₱<?= peso((int) $a['balance_centavos']) ?></strong><span><a href="payouts.php">Cash out</a> · min ₱<?= peso($st['payout_min']) ?></span></div>
    <div class="card kpi"><small>Pending earnings</small><strong>₱<?= peso($pending) ?></strong><span>Becomes available when completed</span></div>
    <div class="card kpi"><small>Earned this month</small><strong>₱<?= peso($month) ?></strong><span><?= $master === null && $teamMonth ? 'Incl. ₱' . peso($teamMonth) . ' team override' : e(date('F Y')) ?></span></div>
    <div class="card kpi"><small>Referred customers</small><strong><?= number_format($referrals) ?></strong><span><?= number_format($activeReferrals) ?> active · <?= number_format((int) $a['link_clicks']) ?> link clicks</span></div>
</div>
<div class="grid two mt">
    <section class="card share-card" data-agent-share data-link="<?= e($link) ?>" data-code="<?= e($a['code']) ?>">
        <h2>Your referral link</h2>
        <p class="muted">Send this link to friends, guests and groups. When they create an Ultimate App account with it, they are tagged to you<?= $st['earn_days'] ? ' and you earn on their activity for ' . number_format($st['earn_days']) . ' days' : ' and you earn on all their activity' ?>.</p>
        <div class="copy-row"><input type="text" readonly value="<?= e($link) ?>" aria-label="Referral link" data-share-link><button class="btn primary" type="button" data-copy="link">Copy link</button></div>
        <div class="code-row"><span>Referral code</span><strong data-share-code><?= e($a['code']) ?></strong><button class="btn small" type="button" data-copy="code">Copy code</button></div>
        <p class="muted small">Signing up in the app without the link? They type your code in the <b>Agent referral code</b> box on the Sign Up screen.</p>
        <div class="form-actions"><button class="btn" type="button" data-share>Share…</button>
            <a class="btn" target="_blank" rel="noopener" href="https://www.facebook.com/sharer/sharer.php?u=<?= rawurlencode($link) ?>">Facebook</a>
            <a class="btn" target="_blank" rel="noopener" href="https://wa.me/?text=<?= rawurlencode('Get Ultimate App Boracay for rides, food and tours: ' . $link) ?>">WhatsApp</a>
            <a class="btn" href="sms:?&amp;body=<?= rawurlencode('Get Ultimate App Boracay: ' . $link) ?>">SMS</a></div>
        <p class="table-meta" data-share-status role="status"></p>
    </section>
    <section class="card qr-share"><h2>Scan to sign up</h2><p class="muted">Show or print this QR. It opens your sign-up link.</p>
        <div class="qr-box light" data-share-qr role="img" aria-label="QR code of your referral link"></div>
        <div class="form-actions"><button class="btn" type="button" data-qr-download>Download QR</button><button class="btn" type="button" data-print>Print</button></div></section>
</div>
<section class="card mt"><h2>Earnings by service <a href="earnings.php">See all</a></h2>
    <div class="table-wrap"><table><thead><tr><th>Service</th><th>You earn</th><th class="hide-sm">When</th><th class="num">Activities</th><th class="num">Pending</th><th class="num">Earned</th></tr></thead><tbody>
    <?php foreach (agent_services() as $code => $svc): $t = $totals[$code]; ?>
        <tr><td><b><?= e($svc['label']) ?></b></td><td><?= e(agent_rate_label($code, $tier)) ?><small>of the <?= e($svc['base']) ?></small></td><td class="hide-sm"><?= e($svc['when']) ?></td><td class="num"><?= number_format($t['n']) ?></td><td class="num">₱<?= peso($t['pending']) ?></td><td class="num">₱<?= peso($t['earned']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<section class="card mt"><h2>Latest activity <a href="earnings.php">See all</a></h2>
    <div class="table-wrap"><table><thead><tr><th>Date</th><th>Customer</th><th>Service</th><th class="num">Amount</th><th class="num">Your commission</th><th>Status</th></tr></thead><tbody>
    <?php if (!$recent): ?><tr><td colspan="6" class="muted">No activity yet. Share your link to start earning.</td></tr><?php endif; ?>
    <?php foreach ($recent as $r): ?><tr><td><?= e(date('M j, g:i A', strtotime($r['created_at']))) ?><small><?= e($r['source_reference']) ?></small></td><td><?= e(agent_mask_name($r['full_name'])) ?></td><td><?= e($r['service_code']) ?></td><td class="num">₱<?= peso((int) $r['base_centavos']) ?></td><td class="num">₱<?= peso((int) $r['amount_centavos']) ?></td><td><?= agent_badge($r['status']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</section>
<?php else: ?>
<section class="card"><h2>Your referral code</h2><p class="code-big"><?= e($a['code']) ?></p><p class="muted">This code is reserved for you. It starts tagging new customers once your account is approved.</p>
    <h2 class="mt">What you will earn</h2>
    <div class="table-wrap"><table><thead><tr><th>Service</th><th>You earn</th><th>When</th></tr></thead><tbody>
    <?php foreach (agent_services() as $code => $svc): ?><tr><td><b><?= e($svc['label']) ?></b></td><td><?= e(agent_rate_label($code, $tier)) ?><small>of the <?= e($svc['base']) ?></small></td><td><?= e($svc['when']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="form-actions"><a class="btn primary" href="profile.php">Add payout account</a></div>
</section>
<?php endif; ?>
<?php agent_footer($a['status'] === 'approved' ? ['../assets/js/qrcode.min.js', 'assets/agent.js?v=2'] : []);
