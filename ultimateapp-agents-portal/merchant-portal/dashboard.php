<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/kyc.php';
$m = merchant_require();
$id = (int) $m['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && mp('action') === 'resubmit') {
    verify_csrf();
    if (in_array($m['status'], ['needs_info', 'rejected'], true)) {
        $pdo->prepare("UPDATE merchants SET status = 'pending' WHERE id = ?")->execute([$id]);
        $pdo->prepare("INSERT INTO crm_notes (entity_type, entity_id, admin_id, body) VALUES ('merchant', ?, NULL, 'Merchant resubmitted the application from the portal.')")->execute([$id]);
        mflash('success', 'Resubmitted. We will review your updated application.');
    }
    redirect('dashboard.php');
}
$docs = $pdo->prepare('SELECT doc_type FROM merchant_documents WHERE merchant_id = ?');
$docs->execute([$id]); $docTypes = array_unique($docs->fetchAll(PDO::FETCH_COLUMN));
$steps = [
    ['Business profile submitted', 'Name, category, address and owner', true],
    ['Registration numbers', 'TIN, DTI/SEC/CDA and mayor\'s permit numbers', $m['tin'] && ($m['registration_no'] || $m['mayors_permit_no'])],
    ['Documents uploaded', 'Business permit and owner ID at minimum', in_array('permit', $docTypes, true) && in_array('id', $docTypes, true)],
    ['Payout account', 'Bank or e-wallet for settlements', (bool) $m['bank_account_no']],
    ['Identity verified (KYC)', 'Owner\'s valid ID front and back, and a selfie check · <a href="kyc.php">Verify now</a>', kyc_is_verified($pdo, 'merchant', $id)],
    ['Ultimate App review', 'We verify your details and approve your QR', $m['status'] === 'approved'],
];
$banner = [
    'pending' => ['info', 'Application submitted', 'Our team will review it soon. Complete any missing steps below to speed things up.'],
    'under_review' => ['info', 'Under review', 'A reviewer is checking your application now.'],
    'needs_info' => ['warn', 'We need a few more details', $m['status_note'] ?: 'Please update your business details or documents, then resubmit.'],
    'approved' => ['good', 'You are live', 'Customers can now pay you by scanning your Ultimate App QR.'],
    'rejected' => ['bad', 'Application not approved', $m['status_note'] ?: 'Contact support for more information.'],
    'suspended' => ['bad', 'Account suspended', ($m['status_note'] ?: 'Payments to your QR are paused.') . ' Contact support to resolve this.'],
][$m['status']];
$sum = static function (string $since) use ($pdo, $id): array {
    $s = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(amount_centavos),0) v, COALESCE(SUM(merchant_net_centavos),0) net FROM merchant_payments WHERE merchant_id = ? AND status = 'completed' AND created_at >= $since");
    $s->execute([$id]); return $s->fetch();
};
$today = $sum('CURDATE()'); $week = $sum('CURDATE() - INTERVAL 6 DAY'); $month = $sum('CURDATE() - INTERVAL 29 DAY');
$pending = $pdo->prepare("SELECT COALESCE(SUM(amount_centavos),0) FROM settlements WHERE merchant_id = ? AND status = 'pending'"); $pending->execute([$id]); $pending = (int) $pending->fetchColumn();
$recent = $pdo->prepare('SELECT mp.*, u.full_name FROM merchant_payments mp JOIN users u ON u.id = mp.user_id WHERE mp.merchant_id = ? ORDER BY mp.id DESC LIMIT 8');
$recent->execute([$id]); $recent = $recent->fetchAll();
portal_header('Hello, ' . explode(' ', $m['owner_name'])[0], 'dashboard');
?>
<div class="status-banner <?= e($banner[0]) ?>"><div><h2><?= e($banner[1]) ?> <?= portal_badge($m['status']) ?></h2><p><?= e($banner[2]) ?></p>
    <?php if (in_array($m['status'], ['needs_info', 'rejected'], true)): ?><form method="post" class="mt"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="resubmit"><a class="btn small" href="profile.php">Update details</a> <button class="btn small primary" type="submit">Resubmit for review</button></form><?php endif; ?>
</div></div>
<?php if ($m['status'] === 'approved'): ?>
<div class="grid kpis">
    <div class="card kpi hero"><small>Available balance</small><strong>₱<?= peso((int) $m['balance_centavos']) ?></strong><span><?= $pending ? '₱' . peso($pending) . ' payout on the way' : 'Paid out by Ultimate App' ?></span></div>
    <div class="card kpi"><small>Sales today</small><strong>₱<?= peso((int) $today['v']) ?></strong><span><?= (int) $today['n'] ?> payment<?= (int) $today['n'] === 1 ? '' : 's' ?></span></div>
    <div class="card kpi"><small>Last 7 days</small><strong>₱<?= peso((int) $week['v']) ?></strong><span>Net ₱<?= peso((int) $week['net']) ?></span></div>
    <div class="card kpi"><small>Last 30 days</small><strong>₱<?= peso((int) $month['v']) ?></strong><span><?= (int) $month['n'] ?> payments</span></div>
</div>
<div class="grid two mt">
    <section class="card"><h2>Latest payments <a href="payments.php">See all</a></h2>
        <div class="table-wrap"><table><thead><tr><th>Reference</th><th>Customer</th><th>Paid with</th><th class="num">Amount</th><th class="num">You receive</th></tr></thead><tbody>
        <?php if (!$recent): ?><tr><td colspan="5" class="muted">No payments yet. Put your QR on the counter to start.</td></tr><?php endif; ?>
        <?php foreach ($recent as $r): ?><tr><td><b><?= e($r['reference']) ?></b><small><?= e(date('M j, g:i A', strtotime($r['created_at']))) ?></small></td><td><?= e(explode(' ', $r['full_name'])[0]) ?></td><td><?= $r['source'] === 'credits' ? 'Credits' : 'BCash' ?></td><td class="num">₱<?= peso((int) $r['amount_centavos']) ?></td><td class="num"><?= $r['status'] === 'refunded' ? portal_badge('refunded') : '₱' . peso((int) $r['merchant_net_centavos']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
    <section class="card"><h2>Your payment QR</h2><p class="muted">Customers scan it with Ultimate App, choose Credits or BCash, and you see the payment here instantly.</p><a class="btn grad" href="qr.php">Show / print my QR</a></section>
</div>
<?php else: ?>
<section class="card"><h2>Onboarding checklist</h2>
    <ol class="steps"><?php $now = false; foreach ($steps as $i => [$title, $hint, $done]): $cls = $done ? 'done' : (!$now ? 'now' : ''); if (!$done) $now = true; ?><li class="<?= $cls ?>"><i><?= $done ? '✓' : $i + 1 ?></i><span><b><?= e($title) ?></b><small><?= strip_tags($hint, '<a>') ?></small></span></li><?php endforeach; ?></ol>
    <div class="form-actions"><a class="btn primary" href="profile.php">Complete business details</a><a class="btn" href="support.php">Ask a question</a></div>
</section>
<?php endif; ?>
<?php portal_footer();
