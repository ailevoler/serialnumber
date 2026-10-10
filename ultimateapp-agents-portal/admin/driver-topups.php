<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/uride.php';
$admin = admin_require('drivers.view');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_guard();
    try {
        if (!admin_can('drivers.wallet')) throw new InvalidArgumentException('Only Finance or a Super Admin can approve top-ups.');
        if (p('payment_received') !== '1') throw new InvalidArgumentException('Tick the box to confirm the cash was received.');
        $o = uride_topup_admin_approve($pdo, (int) p('id'), (int) $admin['id'], p('receipt'));
        admin_audit('driver_topup_approved', 'driver_topup', (int) p('id'), ['reference' => $o['reference'], 'receipt' => p('receipt'), 'amount_centavos' => (int) $o['amount_centavos']]);
        flash('success', 'Top-up approved. ₱' . peso((int) $o['amount_centavos']) . ' was added to the driver wallet.');
    } catch (InvalidArgumentException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('driver-topups.php?' . http_build_query(array_filter(['status' => q('status'), 'method' => q('method'), 'q' => q('q')])));
}
$methods = uride_topup_methods();
$status = in_array(q('status'), ['pending', 'paid', 'expired', 'cancelled'], true) ? q('status') : '';
$method = isset($methods[q('method')]) ? q('method') : '';
$search = q('q');
$where = ['1=1']; $params = [];
if ($status === 'pending') $where[] = "t.status = 'pending' AND (t.expires_at IS NULL OR t.expires_at > NOW())";
elseif ($status === 'expired') $where[] = "(t.status = 'expired' OR (t.status = 'pending' AND t.expires_at <= NOW()))";
elseif ($status) { $where[] = 't.status = ?'; $params[] = $status; }
if ($method) { $where[] = 't.method = ?'; $params[] = $method; }
if ($search !== '') { $where[] = '(t.reference LIKE ? OR d.full_name LIKE ? OR d.code LIKE ? OR d.mobile LIKE ? OR t.mctc_receipt LIKE ?)'; array_push($params, ...array_fill(0, 5, '%' . $search . '%')); }
$sql = ' FROM uride_driver_topups t JOIN uride_drivers d ON d.id = t.driver_id LEFT JOIN users a ON a.id = t.mctc_user_id LEFT JOIN users p ON p.id = t.payer_user_id WHERE ' . implode(' AND ', $where);
if (q('export') === 'csv') {
    $stmt = $pdo->prepare('SELECT t.reference, t.created_at, t.method, t.status, d.code, d.full_name, t.amount_centavos/100, t.fee_centavos/100, a.full_name, t.mctc_receipt, p.full_name, t.paid_at, t.mode' . $sql . ' ORDER BY t.id DESC');
    $stmt->execute($params);
    admin_audit('export_driver_topups');
    csv_download('uride-driver-topups-' . date('Ymd') . '.csv', ['Reference', 'Created', 'Method', 'Status', 'Driver code', 'Driver', 'Amount', 'Fee', 'MCTC agent', 'MCTC receipt', 'BCash payer', 'Paid at', 'Mode'], $stmt);
}
[$page, $per, $offset] = paging(30);
$c = $pdo->prepare('SELECT COUNT(*)' . $sql); $c->execute($params); $total = (int) $c->fetchColumn();
$stmt = $pdo->prepare('SELECT t.*, d.full_name driver, d.code driver_code, a.full_name agent, p.full_name payer, (SELECT name FROM admin_users x WHERE x.id = t.approved_by_admin) approver, (SELECT business_name FROM merchants mm WHERE mm.id = t.mctc_merchant_id) mctc_merchant' . $sql . " ORDER BY t.id DESC LIMIT $per OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$kpi = $pdo->query("SELECT
    COALESCE(SUM(CASE WHEN status='paid' AND paid_at >= CURDATE() THEN amount_centavos END),0) today,
    COALESCE(SUM(CASE WHEN status='paid' AND paid_at >= CURDATE() AND method='qrph' THEN amount_centavos END),0) today_qrph,
    COALESCE(SUM(CASE WHEN status='paid' AND paid_at >= CURDATE() AND method='mctc' THEN amount_centavos END),0) today_mctc,
    COALESCE(SUM(CASE WHEN status='paid' AND paid_at >= CURDATE() AND method='boracay_cash' THEN amount_centavos END),0) today_bcash,
    SUM(status='pending' AND method='mctc' AND expires_at > NOW()) mctc_open,
    COALESCE(SUM(CASE WHEN status='pending' AND method='mctc' AND expires_at > NOW() THEN amount_centavos + fee_centavos END),0) mctc_open_amt
    FROM uride_driver_topups")->fetch();
$agents = $pdo->query("SELECT CAST(SUBSTRING_INDEX(l.account, ':', -1) AS UNSIGNED) uid, u.full_name, u.email, SUM(l.debit_centavos - l.credit_centavos) held, COUNT(DISTINCT l.entry_group) n
    FROM ledger_entries l LEFT JOIN users u ON u.id = CAST(SUBSTRING_INDEX(l.account, ':', -1) AS UNSIGNED)
    WHERE l.account LIKE 'mctc_collections:%' GROUP BY uid, u.full_name, u.email HAVING held <> 0 ORDER BY held DESC")->fetchAll();
$label = static function (array $t): array {
    if ($t['status'] === 'pending' && uride_topup_is_expired($t)) return ['closed', 'Expired'];
    return [['pending' => 'pending', 'paid' => 'paid', 'expired' => 'closed', 'cancelled' => 'cancelled'][$t['status']] ?? 'closed', ['pending' => 'Pending', 'paid' => 'Paid', 'expired' => 'Expired', 'cancelled' => 'Cancelled'][$t['status']] ?? $t['status']];
};
require_once __DIR__ . '/../includes/merchants.php';
$mctcMerchants = array_values(array_filter($pdo->query("SELECT id, business_name, code, status, mctc_enabled, mctc_due_centavos FROM merchants WHERE status = 'approved' OR mctc_due_centavos <> 0 ORDER BY mctc_due_centavos DESC, business_name")->fetchAll(), static fn($r) => merchant_is_mctc($r) || (int) $r['mctc_due_centavos'] !== 0));
admin_header('Driver top-ups', 'driver-topups');
?>
<div class="grid kpis">
    <div class="card kpi hero"><small>Topped up today</small><strong>₱<?= peso((int) $kpi['today']) ?></strong><span>QR Ph ₱<?= peso((int) $kpi['today_qrph']) ?> · MCTC ₱<?= peso((int) $kpi['today_mctc']) ?> · BCash ₱<?= peso((int) $kpi['today_bcash']) ?></span></div>
    <div class="card kpi"><small>MCTC requests waiting</small><strong><?= (int) $kpi['mctc_open'] ?></strong><span>₱<?= peso((int) $kpi['mctc_open_amt']) ?> to be paid at MCTC</span></div>
    <div class="card kpi"><small>MCTC cash to collect</small><strong>₱<?= peso((int) array_sum(array_column($agents, 'held')) + (int) array_sum(array_column($mctcMerchants, 'mctc_due_centavos'))) ?></strong><span>Held by MCTC merchants and agents</span></div>
</div>
<div class="tabs mt"><a class="<?= $status === '' ? 'active' : '' ?>" href="?<?= e(http_build_query(array_filter(['method' => $method]))) ?>">All</a><?php foreach (['pending' => 'Pending', 'paid' => 'Paid', 'expired' => 'Expired', 'cancelled' => 'Cancelled'] as $k => $l): ?><a class="<?= $status === $k ? 'active' : '' ?>" href="?<?= e(http_build_query(array_filter(['status' => $k, 'method' => $method]))) ?>"><?= $l ?></a><?php endforeach; ?></div>
<form class="toolbar" method="get">
    <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Reference, driver, code, receipt" aria-label="Search top-ups">
    <select name="method" data-autosubmit aria-label="Method"><option value="">All methods</option><?php foreach ($methods as $k => [$l]): ?><option value="<?= e($k) ?>"<?= $method === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <button class="btn" type="submit">Filter</button><span class="spacer"></span>
    <a class="btn" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>"><?= icon('download') ?> Export CSV</a>
</form>
<div class="table-wrap"><table>
    <thead><tr><th>Reference</th><th>Driver</th><th>Method</th><th class="num">Amount</th><th class="num">Fee</th><th>Paid via</th><th>Status</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">No top-ups match.</td></tr><?php endif; ?>
    <?php foreach ($rows as $t): [$tone, $st] = $label($t); ?>
        <tr><td><?= e(substr($t['reference'], 0, 15)) ?>…<small><?= e(date('M j, g:i A', strtotime($t['created_at']))) ?><?= $t['mode'] === 'test' ? ' · TEST' : '' ?></small></td>
            <td><a class="row-link" href="driver.php?id=<?= (int) $t['driver_id'] ?>"><?= e($t['driver']) ?></a><small><?= e($t['driver_code']) ?></small></td>
            <td><?= e($methods[$t['method']][0] ?? $t['method']) ?></td>
            <td class="num">₱<?= peso((int) $t['amount_centavos']) ?></td><td class="num">₱<?= peso((int) $t['fee_centavos']) ?></td>
            <td><?php if ($t['method'] === 'mctc' && $t['mctc_merchant']): ?><?= '<a href="merchant.php?id=' . (int) $t['mctc_merchant_id'] . '">' . e($t['mctc_merchant']) . '</a><small>MCTC merchant · Receipt ' . e((string) $t['mctc_receipt']) . '</small>' ?>
                <?php elseif ($t['method'] === 'mctc' && $t['approver']): ?><?= 'Ultimate App office<small>' . e($t['approver']) . ' · Receipt ' . e((string) $t['mctc_receipt']) . '</small>' ?>
                <?php elseif ($t['method'] === 'mctc'): ?><?= $t['agent'] ? e($t['agent']) . '<small>Receipt ' . e((string) $t['mctc_receipt']) . '</small>' : ($st === 'Pending' ? '<small>Waiting for an MCTC agent to scan.<br>Valid until ' . e(date('M j, g:i A', strtotime((string) $t['expires_at']))) . '</small>' : '—') ?>
                <?php elseif ($t['method'] === 'boracay_cash'): ?><?= $t['payer'] ? e($t['payer']) . '<small>BCash</small>' : '—' ?>
                <?php else: ?><?= $t['payment_id'] ? '<small>' . e((string) $t['payment_id']) . '</small>' : '—' ?><?php endif; ?></td>
            <td><?= '<span class="badge ' . ['pending' => 'warn', 'paid' => 'good', 'cancelled' => 'muted', 'closed' => 'muted'][$tone] . '">' . e($st) . '</span>' ?>
                <?php if ($st === 'Pending' && $t['method'] === 'mctc' && admin_can('drivers.wallet')): ?>
                <form method="post" class="topup-approve" data-confirm="Approve this top-up? Only do this if the cash (₱<?= peso((int) $t['amount_centavos'] + (int) $t['fee_centavos']) ?>) was received. ₱<?= peso((int) $t['amount_centavos']) ?> goes to <?= e($t['driver']) ?>'s wallet.">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                    <input type="text" name="receipt" required minlength="4" maxlength="80" placeholder="OR / receipt no." aria-label="Receipt number">
                    <label class="topup-check"><input type="checkbox" name="payment_received" value="1" required> Cash received</label>
                    <button class="btn small primary" type="submit">Approve</button>
                </form>
                <?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?= pager($page, $per, $total) ?>
<section class="card mt"><h2>MCTC merchants — cash due <small>customer + driver top-ups</small></h2>
    <?php if (!$mctcMerchants): ?><p class="muted">No merchant is an MCTC top-up center. Turn on "Every approved merchant is an MCTC top-up center" in Settings › Charges &amp; limits, or turn it on per merchant.</p><?php endif; ?>
    <?php foreach ($mctcMerchants as $mm): ?><div class="kv stmt"><span><a href="merchant.php?id=<?= (int) $mm['id'] ?>"><?= e($mm['business_name']) ?></a><small class="sub"><?= e($mm['code']) ?> · deducted from their next payout</small></span><b>₱<?= peso((int) $mm['mctc_due_centavos']) ?></b></div><?php endforeach; ?>
</section>
<section class="card mt"><h2>MCTC agents (app accounts) — cash from driver top-ups <small>ledger: Cash held by MCTC agent</small></h2>
    <?php if (!$agents): ?><p class="muted">No MCTC driver top-ups yet.</p><?php endif; ?>
    <?php foreach ($agents as $a): ?><div class="kv stmt"><span><a href="customer.php?id=<?= (int) $a['uid'] ?>"><?= e($a['full_name'] ?? ('User #' . $a['uid'])) ?></a><small class="sub"><?= e((string) $a['email']) ?> · <?= (int) $a['n'] ?> top-up(s)</small></span><b>₱<?= peso((int) $a['held']) ?></b></div><?php endforeach; ?>
    <p class="table-meta">Cash received directly by the Ultimate App team (Approve button above) is recorded as "Cash received at office", not under an agent.</p>
    <p class="table-meta">MCTC agents are Ultimate App accounts with the MCTC role. Give or remove the role in CRM › Customers › open the customer › "MCTC agent".</p>
</section>
<?php admin_footer();
