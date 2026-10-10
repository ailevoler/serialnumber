<?php
require_once __DIR__ . '/_bootstrap.php';
if ((admin_current()['role'] ?? '') === 'barangay_officer') redirect('brgy-applications.php');
$admin = admin_require('dashboard');
$one = static fn(string $sql, array $params = []) => (function () use ($sql, $params) { global $pdo; $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); })();

$k = [
    'customers' => (int) $one('SELECT COUNT(*) FROM users'),
    'customers_new' => (int) $one('SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL 7 DAY'),
    'merchants' => (int) $one("SELECT COUNT(*) FROM merchants WHERE status = 'approved'"),
    'merchants_queue' => (int) $one("SELECT COUNT(*) FROM merchants WHERE status IN ('pending','under_review','needs_info')"),
    'credits' => (int) round((float) $one('SELECT COALESCE(SUM(credits),0) FROM users') * 100),
    'cash' => (int) $one('SELECT COALESCE(SUM(balance_centavos),0) FROM boracay_cash_wallets'),
    'payable' => (int) $one('SELECT COALESCE(SUM(balance_centavos),0) FROM merchants'),
    'in_transit' => (int) $one("SELECT COALESCE(SUM(amount_centavos),0) FROM settlements WHERE status = 'pending'"),
    'p2m_today' => (int) $one("SELECT COALESCE(SUM(amount_centavos),0) FROM merchant_payments WHERE status = 'completed' AND created_at >= CURDATE()"),
    'p2m_30' => (int) $one("SELECT COALESCE(SUM(amount_centavos),0) FROM merchant_payments WHERE status = 'completed' AND created_at >= NOW() - INTERVAL 30 DAY"),
    'fees_mtd' => (int) $one("SELECT COALESCE(SUM(fee_centavos),0) FROM merchant_payments WHERE status = 'completed' AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
    'topups_30' => (int) $one("SELECT COALESCE(SUM(amount_centavos),0) FROM credit_topups WHERE status = 'paid' AND paid_at >= NOW() - INTERVAL 30 DAY"),
    'p2p_30' => (int) round((float) $one('SELECT COALESCE(SUM(credits),0) FROM qr_payments WHERE created_at >= NOW() - INTERVAL 30 DAY') * 100),
    'tickets' => (int) $one("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','pending')"),
];

// Daily merchant payment volume, last 14 days (zero-filled).
$rows = $pdo->query("SELECT DATE(created_at) d, SUM(amount_centavos) v, COUNT(*) n FROM merchant_payments WHERE status = 'completed' AND created_at >= CURDATE() - INTERVAL 13 DAY GROUP BY DATE(created_at)")->fetchAll();
$byDay = array_column($rows, null, 'd');
$series = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $series[] = ['d' => $d, 'v' => (int) ($byDay[$d]['v'] ?? 0), 'n' => (int) ($byDay[$d]['n'] ?? 0)];
}
$max = max(1, ...array_column($series, 'v'));
$nice = static function (int $v): int { $m = 10 ** max(0, strlen((string) $v) - 1); return (int) (ceil($v / $m) * $m); };
$top = max($nice($max), 100000);

$recent = $pdo->query("(SELECT 'P2M' kind, mp.reference, mp.amount_centavos amt, mp.created_at, u.full_name who, m.business_name target, mp.status FROM merchant_payments mp JOIN users u ON u.id = mp.user_id JOIN merchants m ON m.id = mp.merchant_id ORDER BY mp.id DESC LIMIT 8)
    UNION ALL (SELECT 'P2P', q.reference, ROUND(q.credits * 100), q.created_at, a.full_name, b.full_name, q.status FROM qr_payments q JOIN users a ON a.id = q.payer_id JOIN users b ON b.id = q.payee_id ORDER BY q.id DESC LIMIT 8)
    ORDER BY created_at DESC LIMIT 8")->fetchAll();
$queue = $pdo->query("SELECT id, business_name, category, status, created_at FROM merchants WHERE status IN ('pending','under_review','needs_info') ORDER BY created_at LIMIT 6")->fetchAll();
$tickets = $pdo->query("SELECT id, reference, subject, priority, status, updated_at FROM support_tickets WHERE status IN ('open','pending') ORDER BY FIELD(priority,'urgent','high','normal','low'), updated_at DESC LIMIT 6")->fetchAll();

admin_header('Dashboard', 'index');
?>
<div class="grid kpis">
    <div class="card kpi hero"><small>Customer Credits outstanding</small><strong>₱<?= peso($k['credits']) ?></strong><span>+ ₱<?= peso($k['cash']) ?> BCash held</span></div>
    <div class="card kpi"><small>Merchant payments · today</small><strong>₱<?= peso($k['p2m_today']) ?></strong><span>₱<?= peso($k['p2m_30']) ?> last 30 days</span></div>
    <div class="card kpi"><small>Charge revenue · this month</small><strong>₱<?= peso($k['fees_mtd']) ?></strong><span>From P2M charges</span></div>
    <div class="card kpi"><small>Owed to merchants</small><strong>₱<?= peso($k['payable'] + $k['in_transit']) ?></strong><span>₱<?= peso($k['in_transit']) ?> in payout</span></div>
</div>
<div class="grid kpis mt">
    <div class="card kpi"><small>Customers</small><strong><?= number_format($k['customers']) ?></strong><span>+<?= number_format($k['customers_new']) ?> this week</span></div>
    <div class="card kpi"><small>Active merchants</small><strong><?= number_format($k['merchants']) ?></strong><span><a href="merchants.php?view=board"><?= number_format($k['merchants_queue']) ?> in onboarding</a></span></div>
    <div class="card kpi"><small>Top-ups · 30 days</small><strong>₱<?= peso($k['topups_30']) ?></strong><span>P2P sent ₱<?= peso($k['p2p_30']) ?></span></div>
    <div class="card kpi"><small>Open tickets</small><strong><?= number_format($k['tickets']) ?></strong><span><a href="tickets.php">Go to support</a></span></div>
</div>

<div class="grid two mt">
    <section class="card">
        <h2>Merchant payment volume <small>Last 14 days · PHP</small></h2>
        <?php $w = 700; $h = 220; $l = 52; $b = 26; $bw = ($w - $l - 10) / 14; ?>
        <svg class="chart" viewBox="0 0 <?= $w ?> <?= $h ?>" role="img" aria-label="Daily merchant payment volume for the last 14 days">
            <?php for ($g = 0; $g <= 4; $g++): $y = 10 + ($h - $b - 10) * (1 - $g / 4); ?>
                <line class="grid-line" x1="<?= $l ?>" x2="<?= $w - 6 ?>" y1="<?= $y ?>" y2="<?= $y ?>"/>
                <text x="<?= $l - 8 ?>" y="<?= $y + 4 ?>" text-anchor="end"><?= number_format($top / 100 * $g / 4) ?></text>
            <?php endfor; ?>
            <?php foreach ($series as $i => $pt): $bh = ($h - $b - 10) * $pt['v'] / $top; $x = $l + 6 + $i * $bw; $y = $h - $b - $bh; ?>
                <?php if ($pt['v'] > 0): ?><path class="bar" d="M<?= $x ?>,<?= $h - $b ?>V<?= $y + 4 ?>q0,-4 4,-4h<?= $bw - 14 ?>q4,0 4,4V<?= $h - $b ?>Z"><title><?= e(date('M j', strtotime($pt['d']))) ?>: ₱<?= peso($pt['v']) ?> · <?= $pt['n'] ?> payment<?= $pt['n'] === 1 ? '' : 's' ?></title></path><?php endif; ?>
                <?php if ($i % 2 === 1 || $i === 13): ?><text x="<?= $x + ($bw - 6) / 2 ?>" y="<?= $h - 8 ?>" text-anchor="middle"><?= e(date('M j', strtotime($pt['d']))) ?></text><?php endif; ?>
            <?php endforeach; ?>
        </svg>
        <?php if (array_sum(array_column($series, 'v')) === 0): ?><p class="muted">No merchant payments yet. Approve a merchant and the first payments will appear here.</p><?php endif; ?>
    </section>
    <section class="card">
        <h2>Onboarding queue <a href="merchants.php?view=board">Pipeline</a></h2>
        <?php if (!$queue): ?><p class="muted">No applications waiting.</p><?php endif; ?>
        <?php foreach ($queue as $m): ?>
            <div class="kv"><span><a class="row-link" href="merchant.php?id=<?= (int) $m['id'] ?>"><?= e($m['business_name']) ?></a><small class="sub"><?= e($m['category']) ?> · <?= e(date('M j', strtotime($m['created_at']))) ?></small></span><?= status_badge($m['status']) ?></div>
        <?php endforeach; ?>
    </section>
</div>

<div class="grid two mt">
    <section class="card">
        <h2>Latest payments <a href="payments.php">All payments</a></h2>
        <div class="table-wrap"><table>
            <thead><tr><th>Type</th><th>Reference</th><th>From → To</th><th class="num">Amount</th><th>When</th></tr></thead>
            <tbody>
            <?php if (!$recent): ?><tr><td colspan="5" class="muted">No payments yet.</td></tr><?php endif; ?>
            <?php foreach ($recent as $r): ?>
                <tr><td><span class="badge <?= $r['kind'] === 'P2M' ? 'info' : 'muted' ?>"><?= e($r['kind']) ?></span></td><td class="nowrap"><?= e($r['reference']) ?></td><td><?= e($r['who']) ?> <span class="muted">→</span> <?= e($r['target']) ?></td><td class="num">₱<?= peso((int) $r['amt']) ?></td><td><?= e(date('M j, g:i A', strtotime($r['created_at']))) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
    </section>
    <section class="card">
        <h2>Support <a href="tickets.php">All tickets</a></h2>
        <?php if (!$tickets): ?><p class="muted">Inbox zero. No open tickets.</p><?php endif; ?>
        <?php foreach ($tickets as $t): ?>
            <div class="kv"><span><a class="row-link" href="ticket.php?id=<?= (int) $t['id'] ?>"><?= e($t['subject']) ?></a><small class="sub"><?= e($t['reference']) ?> · <?= e(date('M j, g:i A', strtotime($t['updated_at']))) ?></small></span><?= status_badge($t['priority']) ?></div>
        <?php endforeach; ?>
    </section>
</div>
<?php admin_footer();
