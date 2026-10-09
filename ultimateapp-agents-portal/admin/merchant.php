<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_crm.php';
require_once __DIR__ . '/../includes/merchants.php';
require_once __DIR__ . '/../includes/p2m.php';
require_once __DIR__ . '/../includes/kyc.php';
admin_require('merchants.view');
$id = (int) q('id');
$stmt = $pdo->prepare('SELECT m.*, a.name approver FROM merchants m LEFT JOIN admin_users a ON a.id = m.approved_by WHERE m.id = ?');
$stmt->execute([$id]);
$m = $stmt->fetch();
if (!$m) { http_response_code(404); admin_header('Merchant not found', 'merchants'); echo '<div class="empty"><h2>Merchant not found</h2></div>'; admin_footer(); exit; }
$statuses = merchant_statuses();
$tempPassword = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_guard();
    if (!crm_handle_post($pdo, 'merchant', $id)) {
        $action = p('action');
        try {
            if ($action === 'status') {
                $new = p('status');
                $note = trim(p('note'));
                $needsSuper = in_array($new, ['approved', 'rejected', 'suspended'], true);
                if (!isset($statuses[$new]) || $new === 'pending') throw new InvalidArgumentException('Choose a valid status.');
                if ($needsSuper ? !admin_can('merchants.approve') : !admin_can('merchants.review')) throw new InvalidArgumentException('Your role cannot set this status.');
                if (in_array($new, ['needs_info', 'rejected', 'suspended'], true) && mb_strlen($note) < 5) throw new InvalidArgumentException('Add a note for the merchant explaining this decision.');
                if ($new === 'approved') {
                    if (kyc_required('merchant') && !kyc_is_verified($pdo, 'merchant', $id)) throw new InvalidArgumentException('Verify this merchant owner\'s identity first (KYC). Open Admin › KYC verification.');
                    $pdo->prepare('UPDATE merchants SET status = ?, status_note = ?, approved_at = COALESCE(approved_at, NOW()), approved_by = COALESCE(approved_by, ?) WHERE id = ?')->execute([$new, $note ?: null, admin_current()['id'], $id]);
                } else {
                    $pdo->prepare('UPDATE merchants SET status = ?, status_note = ? WHERE id = ?')->execute([$new, $note ?: null, $id]);
                }
                $pdo->prepare('INSERT INTO crm_notes (entity_type, entity_id, admin_id, body) VALUES (\'merchant\', ?, ?, ?)')->execute([$id, admin_current()['id'], 'Status → ' . $statuses[$new] . ($note ? ': ' . $note : '')]);
                admin_audit('merchant_status', 'merchant', $id, ['from' => $m['status'], 'to' => $new, 'note' => $note]);
                flash('success', 'Status changed to ' . $statuses[$new] . '.');
            } elseif ($action === 'bank' && admin_can('settlements.manage')) {
                $name = mb_substr(trim(p('bank_name')), 0, 80); $acctName = mb_substr(trim(p('bank_account_name')), 0, 120);
                $acct = preg_replace('/[^0-9A-Za-z-]/', '', p('bank_account_no'));
                if ($name === '' || $acctName === '' || strlen($acct) < 6) throw new InvalidArgumentException('Enter bank, account name and a valid account number.');
                $pdo->prepare('UPDATE merchants SET bank_name = ?, bank_account_name = ?, bank_account_no = ? WHERE id = ?')->execute([$name, $acctName, mb_substr($acct, 0, 40), $id]);
                admin_audit('merchant_bank_updated', 'merchant', $id, ['bank' => $name, 'last4' => substr($acct, -4)]);
                flash('success', 'Bank details updated.');
            } elseif ($action === 'settle' && admin_can('settlements.manage')) {
                $ref = settlement_create($pdo, $id, (int) admin_current()['id']);
                admin_audit('settlement_created', 'merchant', $id, $ref);
                flash('success', str_starts_with($ref, 'ST-') ? 'Settlement ' . $ref . ' created. Transfer the money, then mark it paid in Settlements.' : 'No bank transfer needed: the balance was ' . $ref . '.');
            } elseif ($action === 'mctc' && admin_can('merchants.approve')) {
                $v = p('enabled');
                $flag = $v === '1' ? 1 : ($v === '0' ? 0 : null);
                if ($flag === 1 && $m['status'] !== 'approved') throw new InvalidArgumentException('Approve the merchant before enabling MCTC top-up.');
                $pdo->prepare('UPDATE merchants SET mctc_enabled = ? WHERE id = ?')->execute([$flag, $id]);
                admin_audit('mctc_' . ($flag === null ? 'default' : ($flag ? 'enabled' : 'disabled')), 'merchant', $id);
                flash('success', $flag === 0 ? 'MCTC top-up turned off for this merchant.' : ($flag === 1 ? 'MCTC Top-up Center turned on for this merchant.' : 'MCTC now follows the default for verified merchants.'));
            } elseif ($action === 'mctc_remit' && admin_can('settlements.manage')) {
                require_once __DIR__ . '/../includes/mctc_merchant.php';
                $ref = mctc_record_remittance($pdo, $id, p2m_amount(p('amount')), p('note'), (int) admin_current()['id']);
                admin_audit('mctc_remittance', 'merchant', $id, ['reference' => $ref, 'amount' => p('amount'), 'note' => p('note')]);
                flash('success', 'MCTC remittance ' . $ref . ' recorded.');
            } elseif ($action === 'reset_password' && admin_can('merchants.approve')) {
                $tempPassword = 'Ua-' . substr(strtr(base64_encode(random_bytes(9)), '+/', 'Xy'), 0, 10) . random_int(10, 99);
                $pdo->prepare('UPDATE merchants SET password_hash = ? WHERE id = ?')->execute([password_hash($tempPassword, PASSWORD_DEFAULT), $id]);
                admin_audit('merchant_password_reset', 'merchant', $id);
            } else {
                throw new InvalidArgumentException('You do not have permission for that action.');
            }
        } catch (InvalidArgumentException $ex) {
            flash('error', $ex->getMessage());
        }
    }
    if ($tempPassword === null) redirect('merchant.php?id=' . $id);
    $stmt->execute([$id]); $m = $stmt->fetch();
}

$docs = $pdo->prepare('SELECT * FROM merchant_documents WHERE merchant_id = ? ORDER BY id');
$docs->execute([$id]); $docs = $docs->fetchAll();
$pay = $pdo->prepare('SELECT mp.*, u.full_name FROM merchant_payments mp JOIN users u ON u.id = mp.user_id WHERE mp.merchant_id = ? ORDER BY mp.id DESC LIMIT 20');
$pay->execute([$id]); $pay = $pay->fetchAll();
$sets = $pdo->prepare('SELECT * FROM settlements WHERE merchant_id = ? ORDER BY id DESC LIMIT 10');
$sets->execute([$id]); $sets = $sets->fetchAll();
$st = $pdo->prepare("SELECT COALESCE(SUM(amount_centavos),0) gross, COALESCE(SUM(CASE WHEN fee_bearer='merchant' THEN fee_centavos ELSE 0 END),0) mfees, COUNT(*) n, COUNT(DISTINCT user_id) customers FROM merchant_payments WHERE merchant_id = ? AND status = 'completed'");
$st->execute([$id]); $st = $st->fetch();
$tickets = $pdo->prepare("SELECT * FROM support_tickets WHERE requester_type = 'merchant' AND requester_id = ? ORDER BY id DESC LIMIT 10");
$tickets->execute([$id]); $tickets = $tickets->fetchAll();
$types = merchant_business_types(); $docTypes = merchant_document_types();

admin_header($m['business_name'], 'merchants');
?>
<?php if ($tempPassword): ?><div class="flash info" role="status">Temporary password for <?= e($m['email']) ?>: <b><?= e($tempPassword) ?></b> — share it privately. It is shown only once.</div><?php endif; ?>
<section class="card">
    <div class="profile-head">
        <span class="avatar-lg"><?= e(mb_strtoupper(mb_substr($m['business_name'], 0, 1))) ?></span>
        <div><h2><?= e($m['business_name']) ?></h2><span class="muted"><?= e($m['code']) ?> · <?= e($m['category']) ?> · <?= e($m['barangay']) ?></span> <?= status_badge($m['status']) ?>
            <?php if ($m['status_note']): ?><p class="muted">Note to merchant: <?= e($m['status_note']) ?></p><?php endif; ?></div>
        <div class="actions">
            <?php if (admin_can('merchants.approve')): ?><form method="post" data-confirm="Generate a new temporary password for this merchant?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reset_password"><button class="btn" type="submit">Reset password</button></form><?php endif; ?>
        </div>
    </div>
</section>
<div class="grid kpis mt">
    <div class="card kpi"><small>Available balance</small><strong>₱<?= peso((int) $m['balance_centavos']) ?></strong><span>Ready for settlement</span></div>
    <div class="card kpi"><small>Gross sales</small><strong>₱<?= peso((int) $st['gross']) ?></strong><span><?= (int) $st['n'] ?> payments · <?= (int) $st['customers'] ?> customers</span></div>
    <div class="card kpi"><small>Charges paid by merchant</small><strong>₱<?= peso((int) $st['mfees']) ?></strong><span>Merchant-borne P2M charges</span></div>
    <div class="card kpi"><small>Approved</small><strong><?= $m['approved_at'] ? e(date('M j, Y', strtotime($m['approved_at']))) : '—' ?></strong><span><?= $m['approver'] ? 'by ' . e($m['approver']) : 'Not yet approved' ?></span></div>
</div>
<div class="grid two mt">
    <div class="stack">
        <section class="card"><h2>Business profile</h2>
            <dl class="facts">
                <div><dt>Business type</dt><dd><?= e($types[$m['business_type']] ?? $m['business_type']) ?></dd></div>
                <div><dt>Owner</dt><dd><?= e($m['owner_name']) ?></dd></div>
                <div><dt>Email</dt><dd><?= e($m['email']) ?></dd></div>
                <div><dt>Mobile</dt><dd><?= e($m['mobile']) ?></dd></div>
                <div><dt>Address</dt><dd><?= e($m['address']) ?>, <?= e($m['barangay']) ?></dd></div>
                <div><dt>TIN</dt><dd><?= e($m['tin'] ?? '—') ?></dd></div>
                <div><dt>DTI / SEC / CDA no.</dt><dd><?= e($m['registration_no'] ?? '—') ?></dd></div>
                <div><dt>Mayor's permit no.</dt><dd><?= e($m['mayors_permit_no'] ?? '—') ?></dd></div>
                <div><dt>Registered</dt><dd><?= e(date('M j, Y g:i A', strtotime($m['created_at']))) ?></dd></div>
                <div><dt>Last portal login</dt><dd><?= $m['last_login_at'] ? e(date('M j, Y g:i A', strtotime($m['last_login_at']))) : 'Never' ?></dd></div>
            </dl>
        </section>
        <section class="card"><h2>Onboarding documents <small><?= count($docs) ?> file<?= count($docs) === 1 ? '' : 's' ?></small></h2>
            <?php if (!$docs): ?><p class="muted">No documents uploaded.</p><?php endif; ?>
            <?php foreach ($docs as $d): ?><div class="kv"><span><?= e($docTypes[$d['doc_type']] ?? $d['doc_type']) ?><small class="sub"><?= e($d['original_name']) ?> · <?= number_format($d['size_bytes'] / 1024) ?> KB</small></span><a class="btn small" href="merchant-doc.php?id=<?= (int) $d['id'] ?>" target="_blank" rel="noopener">View</a></div><?php endforeach; ?>
        </section>
        <section class="card"><h2>Recent payments</h2>
            <div class="table-wrap"><table><thead><tr><th>Reference</th><th>Customer</th><th>Source</th><th class="num">Amount</th><th class="num">Net</th><th>Status</th></tr></thead><tbody>
            <?php if (!$pay): ?><tr><td colspan="6" class="muted">No payments yet.</td></tr><?php endif; ?>
            <?php foreach ($pay as $r): ?><tr><td><a class="row-link" href="payments.php?q=<?= e($r['reference']) ?>"><?= e($r['reference']) ?></a><small><?= e(date('M j, g:i A', strtotime($r['created_at']))) ?></small></td><td><?= e($r['full_name']) ?></td><td><?= e(p2m_source_label($r['source'])) ?></td><td class="num">₱<?= peso((int) $r['amount_centavos']) ?></td><td class="num">₱<?= peso((int) $r['merchant_net_centavos']) ?></td><td><?= status_badge($r['status']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>
        <section class="card"><h2>Settlements</h2>
            <div class="table-wrap"><table><thead><tr><th>Reference</th><th class="num">Amount</th><th>Status</th><th>Bank ref</th><th>Created</th></tr></thead><tbody>
            <?php if (!$sets): ?><tr><td colspan="5" class="muted">No settlements yet.</td></tr><?php endif; ?>
            <?php foreach ($sets as $s): ?><tr><td><?= e($s['reference']) ?></td><td class="num">₱<?= peso((int) $s['amount_centavos']) ?></td><td><?= status_badge($s['status']) ?></td><td><?= e($s['payout_reference'] ?? '—') ?></td><td><?= e(date('M j, Y', strtotime($s['created_at']))) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>
    </div>
    <div class="stack">
        <?php if (admin_can('merchants.review')): ?>
        <section class="card"><h2>Onboarding decision</h2>
            <form method="post" data-confirm="Update this merchant's status?">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="status">
                <div class="field"><label for="status">New status</label><select id="status" name="status">
                    <?php foreach ($statuses as $k => $label): if ($k === 'pending') continue; $super = in_array($k, ['approved', 'rejected', 'suspended'], true); if ($super && !admin_can('merchants.approve')) continue; ?>
                        <option value="<?= e($k) ?>"<?= $m['status'] === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?></select></div>
                <div class="field mt"><label for="note">Note to merchant</label><textarea id="note" name="note" rows="3" maxlength="500" placeholder="Required for Needs info, Rejected and Suspended. Shown in the Merchant Portal."></textarea></div>
                <div class="form-actions"><button class="btn primary" type="submit">Update status</button></div>
                <?php if (!admin_can('merchants.approve')): ?><p class="muted">Only a Super Admin can approve, reject or suspend.</p><?php endif; ?>
            </form>
        </section>
        <?php endif; ?>
        <?php $isMctc = merchant_is_mctc($m); $flag = $m['mctc_enabled']; $allOn = setting('mctc.all_approved', '1') === '1'; ?>
        <section class="card"><h2>MCTC Top-up Center <?= $isMctc ? '<span class="badge good">Active</span>' : '<span class="badge muted">Off</span>' ?></h2>
            <p class="muted">MCTC merchants collect cash and confirm customer Buy Credits (MCTC) and URide driver wallet top-ups in the Merchant Portal. The cash is owed to Ultimate App and is deducted from their next payout.</p>
            <div class="kv"><span>Setting</span><b><?= $flag === null ? ($allOn ? 'Default — all verified merchants' : 'Default — off (Settings)') : ((int) $flag === 1 ? 'Turned on for this merchant' : 'Turned off for this merchant') ?></b></div>
            <div class="kv"><span>MCTC cash due</span><b>₱<?= peso((int) $m['mctc_due_centavos']) ?></b></div>
            <?php $mr = $pdo->prepare('SELECT * FROM mctc_remittances WHERE merchant_id = ? ORDER BY id DESC LIMIT 5'); $mr->execute([$id]); foreach ($mr->fetchAll() as $r): ?><div class="kv stmt"><span><?= $r['method'] === 'payout_offset' ? 'Deducted from payout' : 'Cash remitted' ?><small class="sub"><?= e($r['reference']) ?> · <?= e(date('M j, Y', strtotime($r['created_at']))) ?><?= $r['method'] === 'cash_remittance' && $r['note'] ? ' · ' . e($r['note']) : '' ?></small></span><b>₱<?= peso((int) $r['amount_centavos']) ?></b></div><?php endforeach; ?>
            <?php if (admin_can('merchants.approve')): $btn = static fn(string $v, string $label, string $cls = '') => '<form method="post" data-confirm="' . e($label) . '?"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="mctc"><input type="hidden" name="enabled" value="' . $v . '"><button class="btn small ' . $cls . '" type="submit">' . e($label) . '</button></form>'; ?>
                <div class="form-actions mt">
                    <?= $isMctc ? $btn('0', 'Turn off MCTC for this merchant') : ($m['status'] === 'approved' ? $btn('1', 'Turn on MCTC for this merchant', 'grad') : '') ?>
                    <?= $flag !== null ? $btn('default', 'Use the default') : '' ?>
                </div>
            <?php endif; ?>
            <?php if (admin_can('settlements.manage') && (int) $m['mctc_due_centavos'] > 0): ?>
            <details class="mt"><summary>Record cash remittance</summary>
                <form method="post" class="mt" data-confirm="Record this MCTC remittance?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="mctc_remit">
                    <div class="field"><label for="mr_amount">Amount received (PHP)</label><input id="mr_amount" name="amount" type="text" inputmode="decimal" required value="<?= e(centavos_to_decimal((int) $m['mctc_due_centavos'])) ?>"></div>
                    <div class="field mt"><label for="mr_note">Deposit / receipt reference</label><input id="mr_note" name="note" type="text" required maxlength="200" placeholder="e.g. BDO deposit 123456"></div>
                    <div class="form-actions"><button class="btn" type="submit">Record remittance</button></div></form>
            </details>
            <?php endif; ?>
        </section>
        <?php if (admin_can('settlements.manage')): ?>
        <section class="card"><h2>Payout</h2>
            <div class="kv"><span>Bank</span><b><?= e($m['bank_name'] ?? 'Not set') ?></b></div>
            <div class="kv"><span>Account name</span><b><?= e($m['bank_account_name'] ?? '—') ?></b></div>
            <div class="kv"><span>Account no.</span><b><?= e(merchant_mask_account($m['bank_account_no'])) ?></b></div>
            <?php if ((int) $m['mctc_due_centavos'] > 0 && (int) $m['balance_centavos'] > 0): ?><p class="alert info">₱<?= peso(min((int) $m['mctc_due_centavos'], max(0, (int) $m['balance_centavos']))) ?> MCTC cash due will be deducted from this payout.</p><?php endif; ?>
            <form method="post" class="mt" data-confirm="Move the full available balance (₱<?= peso((int) $m['balance_centavos']) ?>) into a pending payout?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="settle"><button class="btn grad" type="submit"<?= (int) $m['balance_centavos'] <= 0 ? ' disabled' : '' ?>>Settle ₱<?= peso((int) $m['balance_centavos']) ?></button></form>
            <details class="mt"><summary>Edit bank details</summary>
                <form method="post" class="mt"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="bank">
                    <div class="field"><label for="bn">Bank / e-wallet</label><input id="bn" name="bank_name" type="text" value="<?= e($m['bank_name'] ?? '') ?>" required></div>
                    <div class="field mt"><label for="ban">Account name</label><input id="ban" name="bank_account_name" type="text" value="<?= e($m['bank_account_name'] ?? '') ?>" required></div>
                    <div class="field mt"><label for="bno">Account number</label><input id="bno" name="bank_account_no" type="text" required placeholder="Enter full number to change"></div>
                    <div class="form-actions"><button class="btn" type="submit">Save bank details</button></div></form>
            </details>
        </section>
        <?php endif; ?>
        <section class="card" <?= google_maps_attrs() ?>><h2>Location <?php if ($m['latitude'] !== null): ?><a href="https://www.google.com/maps/search/?api=1&amp;query=<?= e($m['latitude'] . ',' . $m['longitude']) ?>" target="_blank" rel="noopener">Open in Google Maps</a><?php endif; ?></h2>
            <?php if ($m['latitude'] !== null): ?><div class="map-box" data-map-view data-markers="<?= e(json_encode([['lat' => (float) $m['latitude'], 'lng' => (float) $m['longitude'], 'title' => $m['business_name'], 'sub' => $m['address']]])) ?>">Map shows here once a Google Maps key is set in Settings.</div><p class="table-meta" data-map-status><?= e($m['latitude'] . ', ' . $m['longitude']) ?></p>
            <?php else: ?><p class="muted">The merchant has not pinned a location yet.</p><?php endif; ?>
        </section>
        <?php crm_panel($pdo, 'merchant', $id); ?>
        <section class="card"><h2>Tickets</h2>
            <?php if (!$tickets): ?><p class="muted">No tickets.</p><?php endif; ?>
            <?php foreach ($tickets as $t): ?><div class="kv"><span><a class="row-link" href="ticket.php?id=<?= (int) $t['id'] ?>"><?= e($t['subject']) ?></a><small class="sub"><?= e($t['reference']) ?></small></span><?= status_badge($t['status']) ?></div><?php endforeach; ?>
        </section>
    </div>
</div>
<?php admin_footer(['../assets/js/gmaps.js?v=1']);
