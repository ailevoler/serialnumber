<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_crm.php';
require_once __DIR__ . '/../includes/p2m.php';
admin_require('customers.view');
$id = (int) q('id');
$load = static function () use ($pdo, $id) {
    $s = $pdo->prepare('SELECT u.*, COALESCE(w.balance_centavos,0) cash FROM users u LEFT JOIN boracay_cash_wallets w ON w.user_id = u.id WHERE u.id = ?');
    $s->execute([$id]);
    return $s->fetch();
};
$u = $load();
if (!$u) { http_response_code(404); admin_header('Customer not found', 'customers'); echo '<div class="empty"><h2>Customer not found</h2></div>'; admin_footer(); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_guard();
    if (!crm_handle_post($pdo, 'user', $id)) {
        $action = p('action');
        try {
            if ($action === 'status' && admin_can('customers.suspend')) {
                $new = p('status') === 'suspended' ? 'suspended' : 'active';
                $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$new, $id]);
                admin_audit('customer_' . $new, 'user', $id, p('reason'));
                flash('success', $new === 'suspended' ? 'Customer suspended. They are signed out and cannot pay.' : 'Customer re-activated.');
            } elseif ($action === 'role' && admin_can('customers.roles')) {
                $new = p('role') === 'mctc' ? 'mctc' : 'customer';
                if ($new === 'mctc' && $u['status'] !== 'active') throw new InvalidArgumentException('Only an active account can be an MCTC agent.');
                $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$new, $id]);
                admin_audit($new === 'mctc' ? 'mctc_role_granted' : 'mctc_role_removed', 'user', $id, p('reason'));
                flash('success', $new === 'mctc' ? 'MCTC agent role given. The customer now sees MCTC Scanner in Profile and can approve top-ups.' : 'MCTC agent role removed.');
            } elseif ($action === 'adjust' && admin_can('customers.adjust')) {
                $amount = p2m_amount(ltrim(p('amount'), '-'));
                $signed = p('direction') === 'debit' ? -$amount : $amount;
                $ref = admin_adjust_credits($pdo, $id, $signed, p('reason'), (int) admin_current()['id']);
                admin_audit('credits_adjusted', 'user', $id, ['reference' => $ref, 'centavos' => $signed, 'reason' => p('reason')]);
                flash('success', 'Adjustment ' . $ref . ' posted.');
            } else {
                flash('error', 'You do not have permission for that action.');
            }
        } catch (InvalidArgumentException $ex) {
            flash('error', $ex->getMessage());
        }
    }
    redirect('customer.php?id=' . $id);
}

$q = static function (string $sql) use ($pdo, $id) { $s = $pdo->prepare($sql); $s->execute([$id]); return $s->fetchAll(); };
$transactions = $q('SELECT * FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT 25');
$p2m = $q('SELECT mp.*, m.business_name FROM merchant_payments mp JOIN merchants m ON m.id = mp.merchant_id WHERE mp.user_id = ? ORDER BY mp.id DESC LIMIT 15');
$p2p = $pdo->prepare('SELECT q.*, a.full_name payer, b.full_name payee FROM qr_payments q JOIN users a ON a.id = q.payer_id JOIN users b ON b.id = q.payee_id WHERE q.payer_id = ? OR q.payee_id = ? ORDER BY q.id DESC LIMIT 15');
$p2p->execute([$id, $id]); $p2p = $p2p->fetchAll();
$topups = $q('SELECT * FROM credit_topups WHERE user_id = ? ORDER BY id DESC LIMIT 10');
$tickets = $q("SELECT * FROM support_tickets WHERE requester_type = 'user' AND requester_id = ? ORDER BY id DESC LIMIT 10");
$stats = $pdo->prepare("SELECT (SELECT COALESCE(SUM(amount_centavos),0) FROM merchant_payments WHERE user_id = ? AND status='completed') spent, (SELECT COUNT(*) FROM merchant_payments WHERE user_id = ? AND status='completed') n, (SELECT COALESCE(SUM(amount_centavos),0) FROM credit_topups WHERE user_id = ? AND status='paid') topped");
$stats->execute([$id, $id, $id]); $stats = $stats->fetch();

admin_header($u['full_name'], 'customers');
?>
<section class="card">
    <div class="profile-head">
        <span class="avatar-lg"><?= e(mb_strtoupper(mb_substr($u['full_name'], 0, 1))) ?></span>
        <div><h2><?= e($u['full_name']) ?></h2><span class="muted"><?= e($u['qr_code']) ?> · Customer since <?= e(date('M j, Y', strtotime($u['created_at']))) ?></span> <?= status_badge($u['status']) ?><?= $u['role'] === 'mctc' ? ' ' . status_badge('open') . ' MCTC' : '' ?></div>
        <div class="actions">
            <?php if (admin_can('customers.roles')): $isMctc = ($u['role'] ?? 'customer') === 'mctc'; ?>
            <form method="post" data-confirm="<?= $isMctc ? 'Remove the MCTC agent role? They can no longer approve top-ups.' : 'Make this customer an MCTC agent? They will be able to confirm cash top-ups for customers and URide drivers. Verify their identity first.' ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="role"><input type="hidden" name="role" value="<?= $isMctc ? 'customer' : 'mctc' ?>"><button class="btn" type="submit"><?= $isMctc ? 'Remove MCTC agent' : 'Make MCTC agent' ?></button></form>
            <?php endif; ?>
            <?php if (admin_can('customers.suspend')): ?>
            <form method="post" data-confirm="<?= $u['status'] === 'active' ? 'Suspend this customer? They will be signed out and blocked from paying.' : 'Re-activate this customer?' ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="status" value="<?= $u['status'] === 'active' ? 'suspended' : 'active' ?>"><button class="btn <?= $u['status'] === 'active' ? 'danger' : '' ?>" type="submit"><?= $u['status'] === 'active' ? 'Suspend' : 'Re-activate' ?></button></form>
            <?php endif; ?>
        </div>
    </div>
</section>
<div class="grid kpis mt">
    <div class="card kpi"><small>Credits</small><strong><?= number_format((float) $u['credits'], 2) ?></strong><span>Spendable balance</span></div>
    <div class="card kpi"><small>BCash</small><strong>₱<?= peso((int) $u['cash']) ?></strong><span>PHP wallet</span></div>
    <div class="card kpi"><small>Spent at merchants</small><strong>₱<?= peso((int) $stats['spent']) ?></strong><span><?= (int) $stats['n'] ?> payments</span></div>
    <div class="card kpi"><small>Topped up</small><strong>₱<?= peso((int) $stats['topped']) ?></strong><span>Paid top-ups, incl. fees</span></div>
</div>
<div class="grid two mt">
    <div class="stack">
        <section class="card"><h2>Profile</h2>
            <dl class="facts"><div><dt>Email</dt><dd><?= e($u['email']) ?></dd></div><div><dt>Mobile</dt><dd><?= e($u['mobile']) ?></dd></div><div><dt>QR ID</dt><dd><?= e($u['qr_code']) ?></dd></div><div><dt>Role</dt><dd><?= $u['role'] === 'mctc' ? 'MCTC agent' : 'Customer' ?></dd></div></dl>
        </section>
        <section class="card"><h2>Credits activity <small>Latest 25</small></h2>
            <div class="table-wrap"><table><thead><tr><th>Description</th><th>Type</th><th class="num">Credits</th><th>Date</th></tr></thead><tbody>
            <?php if (!$transactions): ?><tr><td colspan="4" class="muted">No activity.</td></tr><?php endif; ?>
            <?php foreach ($transactions as $t): ?><tr><td><?= e($t['title']) ?></td><td><?= e(ucfirst($t['type'])) ?></td><td class="num <?= $t['amount'] < 0 ? 'neg' : 'pos' ?>"><?= $t['amount'] >= 0 ? '+' : '' ?><?= number_format((float) $t['amount'], 2) ?></td><td><?= e(date('M j, Y g:i A', strtotime($t['created_at']))) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>
        <section class="card"><h2>Merchant payments (P2M)</h2>
            <div class="table-wrap"><table><thead><tr><th>Reference</th><th>Merchant</th><th>Source</th><th class="num">Amount</th><th class="num">Charge</th><th>Status</th></tr></thead><tbody>
            <?php if (!$p2m): ?><tr><td colspan="6" class="muted">No merchant payments.</td></tr><?php endif; ?>
            <?php foreach ($p2m as $r): ?><tr><td><a class="row-link" href="payments.php?q=<?= e($r['reference']) ?>"><?= e($r['reference']) ?></a><small><?= e(date('M j, g:i A', strtotime($r['created_at']))) ?></small></td><td><?= e($r['business_name']) ?></td><td><?= e(p2m_source_label($r['source'])) ?></td><td class="num">₱<?= peso((int) $r['amount_centavos']) ?></td><td class="num">₱<?= peso((int) $r['fee_centavos']) ?><small><?= e($r['fee_bearer']) ?></small></td><td><?= status_badge($r['status']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>
        <section class="card"><h2>Person-to-person (P2P)</h2>
            <div class="table-wrap"><table><thead><tr><th>Reference</th><th>Direction</th><th class="num">Credits</th><th>Date</th></tr></thead><tbody>
            <?php if (!$p2p): ?><tr><td colspan="4" class="muted">No P2P transfers.</td></tr><?php endif; ?>
            <?php foreach ($p2p as $r): $out = (int) $r['payer_id'] === $id; ?><tr><td><?= e($r['reference']) ?></td><td><?= $out ? 'Sent to ' . e($r['payee']) : 'Received from ' . e($r['payer']) ?></td><td class="num <?= $out ? 'neg' : 'pos' ?>"><?= $out ? '-' : '+' ?><?= number_format((float) $r['credits'], 2) ?></td><td><?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>
        <section class="card"><h2>Top-ups</h2>
            <div class="table-wrap"><table><thead><tr><th>Reference</th><th>Method</th><th class="num">Credits</th><th class="num">Paid</th><th>Status</th></tr></thead><tbody>
            <?php if (!$topups): ?><tr><td colspan="5" class="muted">No top-ups.</td></tr><?php endif; ?>
            <?php foreach ($topups as $t): ?><tr><td><?= e($t['reference']) ?><small><?= e(strtoupper($t['mode'])) ?> · <?= e(date('M j, Y', strtotime($t['created_at']))) ?></small></td><td><?= e(strtoupper($t['method'])) ?></td><td class="num"><?= number_format((float) $t['credits']) ?></td><td class="num">₱<?= peso((int) $t['amount_centavos']) ?></td><td><?= status_badge($t['status']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>
    </div>
    <div class="stack">
        <?php if (admin_can('customers.adjust')): ?>
        <section class="card"><h2>Adjust Credits</h2>
            <form method="post" data-confirm="Post this Credits adjustment? It is recorded in the ledger and audit log.">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="adjust">
                <div class="form-grid"><div class="field"><label for="dir">Type</label><select id="dir" name="direction"><option value="credit">Add Credits</option><option value="debit">Deduct Credits</option></select></div>
                <div class="field"><label for="amt">Amount</label><input id="amt" name="amount" type="text" inputmode="decimal" required placeholder="0.00"></div></div>
                <div class="field mt"><label for="reason">Reason</label><input id="reason" name="reason" type="text" required maxlength="150" placeholder="e.g. Goodwill for failed top-up UA-…"></div>
                <div class="form-actions"><button class="btn primary" type="submit">Post adjustment</button></div>
            </form>
        </section>
        <?php endif; ?>
        <?php crm_panel($pdo, 'user', $id); ?>
        <section class="card"><h2>Tickets</h2>
            <?php if (!$tickets): ?><p class="muted">No tickets.</p><?php endif; ?>
            <?php foreach ($tickets as $t): ?><div class="kv"><span><a class="row-link" href="ticket.php?id=<?= (int) $t['id'] ?>"><?= e($t['subject']) ?></a><small class="sub"><?= e($t['reference']) ?></small></span><?= status_badge($t['status']) ?></div><?php endforeach; ?>
        </section>
    </div>
</div>
<?php admin_footer();
