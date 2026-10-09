<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/kyc.php';
require_once __DIR__ . '/../includes/uride.php';
$admin = admin_require('drivers.view');
$id = (int) q('id');
$load = static function () use ($pdo, $id) {
    $s = $pdo->prepare('SELECT d.*, a.name reviewer FROM uride_drivers d LEFT JOIN admin_users a ON a.id = d.reviewed_by WHERE d.id = ?');
    $s->execute([$id]);
    return $s->fetch();
};
$d = $load();
if (!$d) { flash('error', 'Driver not found.'); redirect('drivers.php'); }
$tempPassword = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_guard();
    $action = p('action');
    try {
        if ($action === 'status') {
            if (!admin_can('drivers.review')) throw new InvalidArgumentException('Your role cannot review drivers.');
            $new = p('status');
            if (in_array($new, ['approved', 'rejected', 'suspended'], true) && !admin_can('drivers.approve')) throw new InvalidArgumentException('Only a Super Admin can approve, reject or suspend drivers.');
            if ($new === 'approved' && kyc_required('driver') && !kyc_is_verified($pdo, 'driver', $id)) throw new InvalidArgumentException('Verify this driver\'s identity first (KYC). Open Admin › KYC verification.');
            if ($new === 'approved' && strtotime($d['license_expiry']) < strtotime('today')) throw new InvalidArgumentException("The driver's license has expired. Ask for a renewed license first.");
            uride_review_driver($pdo, $id, $new, p('note'), (int) $admin['id']);
            admin_audit('driver_status', 'driver', $id, ['from' => $d['status'], 'to' => $new, 'note' => p('note')]);
            flash('success', 'Driver status updated to ' . (uride_driver_statuses()[$new] ?? $new) . '.');
        } elseif ($action === 'offline') {
            if (!admin_can('drivers.review')) throw new InvalidArgumentException('Your role cannot change this.');
            $pdo->prepare('UPDATE uride_drivers SET is_online = 0 WHERE id = ?')->execute([$id]);
            admin_audit('driver_forced_offline', 'driver', $id);
            flash('success', 'Driver set offline.');
        } elseif ($action === 'adjust') {
            if (!admin_can('drivers.wallet')) throw new InvalidArgumentException('Your role cannot adjust driver wallets.');
            $raw = trim(p('amount'));
            if (!preg_match('/^-?\d{1,7}(?:\.\d{1,2})?$/D', $raw)) throw new InvalidArgumentException('Enter an amount like 100 or -50.25.');
            $neg = str_starts_with($raw, '-');
            [$w, $f] = array_pad(explode('.', ltrim($raw, '-'), 2), 2, '');
            $cent = ((int) $w * 100 + (int) str_pad($f, 2, '0')) * ($neg ? -1 : 1);
            $ref = uride_admin_adjust($pdo, $id, $cent, p('memo'), (int) $admin['id']);
            admin_audit('driver_wallet_adjust', 'driver', $id, ['amount_centavos' => $cent, 'memo' => p('memo'), 'reference' => $ref]);
            flash('success', 'Wallet adjusted (' . $ref . ').');
        } elseif ($action === 'reset_password') {
            if (!admin_can('drivers.approve')) throw new InvalidArgumentException('Only a Super Admin can reset passwords.');
            $tempPassword = 'Ur' . substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 8) . random_int(10, 99);
            $pdo->prepare('UPDATE uride_drivers SET password_hash = ? WHERE id = ?')->execute([password_hash($tempPassword, PASSWORD_DEFAULT), $id]);
            admin_audit('driver_password_reset', 'driver', $id);
        }
    } catch (InvalidArgumentException $ex) {
        flash('error', $ex->getMessage());
    }
    if ($tempPassword === null) redirect('driver.php?id=' . $id);
    $d = $load();
}

$docs = $pdo->prepare('SELECT * FROM uride_driver_documents WHERE driver_id = ? ORDER BY id');
$docs->execute([$id]); $docs = $docs->fetchAll();
$rides = $pdo->prepare('SELECT r.*, u.full_name passenger FROM uride_requests r JOIN users u ON u.id = r.user_id WHERE r.driver_id = ? ORDER BY r.id DESC LIMIT 15');
$rides->execute([$id]); $rides = $rides->fetchAll();
$tx = $pdo->prepare('SELECT * FROM uride_wallet_tx WHERE driver_id = ? ORDER BY id DESC LIMIT 20');
$tx->execute([$id]); $tx = $tx->fetchAll();
$st = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(fare_centavos),0) fares, COALESCE(SUM(commission_centavos),0) commission FROM uride_requests WHERE driver_id = ? AND status = 'completed'");
$st->execute([$id]); $st = $st->fetch();
$tops = $pdo->prepare('SELECT t.*, a.full_name agent, pu.full_name payer FROM uride_driver_topups t LEFT JOIN users a ON a.id = t.mctc_user_id LEFT JOIN users pu ON pu.id = t.payer_user_id WHERE t.driver_id = ? ORDER BY t.id DESC LIMIT 10');
$tops->execute([$id]); $tops = $tops->fetchAll();
$docTypes = uride_doc_types();
$wtypes = uride_wallet_types();
$live = uride_driver_is_live($d);
$rating = uride_rating($d);
admin_header($d['full_name'], 'drivers');
?>
<?php if ($tempPassword): ?><div class="flash info" role="status">Temporary password for <?= e($d['email']) ?>: <b><?= e($tempPassword) ?></b> — share it privately. It is shown only once.</div><?php endif; ?>
<section class="card">
    <div class="profile-head">
        <?php $ddir = app_storage_root() . '/driver_docs/'; $selfieOk = $d['selfie_file'] && is_file($ddir . basename((string) $d['selfie_file'])); ?><?php if ($selfieOk): ?><img class="avatar-lg selfie" src="driver-file.php?id=<?= $id ?>&amp;selfie=1" alt="Driver selfie" width="64" height="64"><?php else: ?><span class="avatar-lg"><?= e(mb_strtoupper(mb_substr($d['full_name'], 0, 1))) ?></span><?php endif; ?>
        <div><h2><?= e($d['full_name']) ?></h2><span class="muted"><?= e($d['code']) ?> · <?= e(uride_vehicles()[$d['vehicle_type']]['label'] ?? '') ?> · <?= e($d['plate_no']) ?> · <?= e($d['barangay']) ?></span> <?= status_badge($d['status']) ?> <?= $live ? '<span class="badge good">Online now</span>' : '<span class="badge muted">Offline</span>' ?>
            <?php if ($d['review_note']): ?><p class="muted">Note to driver: <?= e($d['review_note']) ?></p><?php endif; ?></div>
        <div class="actions">
            <?php if ((int) $d['is_online'] === 1 && admin_can('drivers.review')): ?><form method="post" data-confirm="Set this driver offline?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="offline"><button class="btn" type="submit">Set offline</button></form><?php endif; ?>
            <?php if (admin_can('drivers.approve')): ?><form method="post" data-confirm="Generate a new temporary password for this driver?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reset_password"><button class="btn" type="submit">Reset password</button></form><?php endif; ?>
        </div>
    </div>
</section>
<div class="grid kpis mt">
    <div class="card kpi"><small>Wallet balance</small><strong>₱<?= peso((int) $d['wallet_centavos']) ?></strong><span>Min to go online ₱<?= peso(uride_settings()['min_wallet']) ?></span></div>
    <div class="card kpi"><small>Completed trips</small><strong><?= (int) $st['n'] ?></strong><span>Fares ₱<?= peso((int) $st['fares']) ?></span></div>
    <div class="card kpi"><small>Commission earned</small><strong>₱<?= peso((int) $st['commission']) ?></strong><span>From this driver</span></div>
    <div class="card kpi"><small>Rating</small><strong><?= $rating !== null ? '★ ' . number_format($rating, 1) : '—' ?></strong><span><?= (int) $d['rating_count'] ?> ratings</span></div>
</div>
<div class="grid two mt">
    <div class="stack">
        <section class="card"><h2>Driver &amp; vehicle</h2>
            <dl class="facts">
                <div><dt>Mobile</dt><dd><?= e($d['mobile']) ?></dd></div>
                <div><dt>Email</dt><dd><?= e($d['email']) ?></dd></div>
                <div><dt>Birthdate</dt><dd><?= e(date('M j, Y', strtotime($d['birthdate']))) ?></dd></div>
                <div><dt>Address</dt><dd><?= e($d['address']) ?>, <?= e($d['barangay']) ?></dd></div>
                <div><dt>License no.</dt><dd><?= e($d['license_no']) ?></dd></div>
                <div><dt>License expiry</dt><dd><?= e(date('M j, Y', strtotime($d['license_expiry']))) ?><?= strtotime($d['license_expiry']) < strtotime('today') ? ' <span class="badge bad">Expired</span>' : '' ?></dd></div>
                <div><dt>Vehicle</dt><dd><?= e($d['vehicle_color'] . ' ' . $d['vehicle_model']) ?></dd></div>
                <div><dt>Plate</dt><dd><?= e($d['plate_no']) ?></dd></div>
                <div><dt>Franchise / MTOP</dt><dd><?= e($d['franchise_no'] ?? '—') ?></dd></div>
                <div><dt>TODA</dt><dd><?= e($d['toda_name'] ?? '—') ?></dd></div>
                <div><dt>Payout account</dt><dd><?= $d['payout_account_no'] ? e($d['payout_method'] . ' · ' . $d['payout_account_name'] . ' · ••••' . substr((string) $d['payout_account_no'], -4)) : 'Not set' ?></dd></div>
                <div><dt>Applied</dt><dd><?= e(date('M j, Y g:i A', strtotime($d['created_at']))) ?></dd></div>
                <div><dt>Reviewed</dt><dd><?= $d['reviewed_at'] ? e(date('M j, Y g:i A', strtotime($d['reviewed_at']))) . ($d['reviewer'] ? ' by ' . e($d['reviewer']) : '') : '—' ?></dd></div>
                <div><dt>Last login</dt><dd><?= $d['last_login_at'] ? e(date('M j, Y g:i A', strtotime($d['last_login_at']))) : 'Never' ?></dd></div>
            </dl>
        </section>
        <section class="card"><h2>Documents <small><?= count($docs) ?> file<?= count($docs) === 1 ? '' : 's' ?></small></h2>
            <?php if ($d['selfie_file']): ?><div class="kv stmt"><span>Selfie photo</span><?= $selfieOk ? '<a class="btn small" href="driver-file.php?id=' . $id . '&amp;selfie=1" target="_blank" rel="noopener">View</a>' : '<span class="badge bad">Missing on server</span>' ?></div><?php endif; ?>
            <?php foreach ($docs as $doc): ?><div class="kv stmt"><span><?= e($docTypes[$doc['doc_type']][0] ?? $doc['doc_type']) ?><small class="sub"><?= e($doc['original_name']) ?> · <?= number_format($doc['size_bytes'] / 1024) ?> KB · <?= e(date('M j', strtotime($doc['created_at']))) ?></small></span><?= is_file($ddir . basename((string) $doc['stored_name'])) ? '<a class="btn small" href="driver-file.php?id=' . $id . '&amp;doc=' . (int) $doc['id'] . '" target="_blank" rel="noopener">View</a>' : '<span class="badge bad">Missing on server</span>' ?></div><?php endforeach; ?>
            <?php $missing = array_filter($docTypes, static fn($t, $k) => $t[1] && !in_array($k, array_column($docs, 'doc_type'), true), ARRAY_FILTER_USE_BOTH); if ($missing): ?><p class="alert">Missing required: <?= e(implode(', ', array_map(static fn($t) => $t[0], $missing))) ?></p><?php endif; ?>
        </section>
        <section class="card"><h2>Recent rides</h2>
            <div class="table-wrap"><table><thead><tr><th>Ride</th><th>Passenger</th><th>Payment</th><th class="num">Fare</th><th class="num">Commission</th><th>Status</th></tr></thead><tbody>
            <?php if (!$rides): ?><tr><td colspan="6" class="muted">No rides yet.</td></tr><?php endif; ?>
            <?php foreach ($rides as $r): ?><tr><td><a class="row-link" href="ride.php?id=<?= (int) $r['id'] ?>"><?= e($r['code']) ?></a><small><?= e(date('M j, g:i A', strtotime($r['created_at']))) ?></small></td><td><?= e($r['passenger']) ?></td><td><?= e(ucfirst($r['payment_method'])) ?></td><td class="num">₱<?= peso((int) $r['fare_centavos']) ?></td><td class="num"><?= $r['commission_centavos'] !== null ? '₱' . peso((int) $r['commission_centavos']) : '—' ?></td><td><?= status_badge($r['status']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>
    </div>
    <div class="stack">
        <?php if (admin_can('drivers.review')): ?>
        <section class="card"><h2>Onboarding decision</h2>
            <form method="post" data-confirm="Update this driver's status?">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="status">
                <div class="field"><label for="status">New status</label><select id="status" name="status">
                    <?php foreach (uride_driver_statuses() as $k => $label): if ($k === 'pending') continue; if (in_array($k, ['approved', 'rejected', 'suspended'], true) && !admin_can('drivers.approve')) continue; ?>
                        <option value="<?= e($k) ?>"<?= $d['status'] === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?></select></div>
                <div class="field mt"><label for="note">Note to driver</label><textarea id="note" name="note" rows="3" maxlength="500" placeholder="Required for Needs info, Rejected and Suspended. Shown in the Driver App."></textarea></div>
                <div class="form-actions"><button class="btn primary" type="submit">Update status</button></div>
                <?php if (!admin_can('drivers.approve')): ?><p class="muted">Only a Super Admin can approve, reject or suspend.</p><?php endif; ?>
            </form>
        </section>
        <?php endif; ?>
        <?php if (admin_can('drivers.wallet')): ?>
        <section class="card"><h2>Wallet adjustment</h2>
            <form method="post" data-confirm="Post this wallet adjustment? It is recorded in the general ledger.">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="adjust">
                <div class="field"><label for="amount">Amount (PHP)</label><input id="amount" name="amount" type="text" inputmode="decimal" placeholder="100 to credit, -50 to debit" required></div>
                <div class="field mt"><label for="memo">Reason</label><input id="memo" name="memo" type="text" maxlength="150" required placeholder="e.g. Cash top-up at office, OR #1234"></div>
                <div class="form-actions"><button class="btn" type="submit">Post adjustment</button></div>
            </form>
        </section>
        <?php endif; ?>
        <section class="card"><h2>Top-up requests <a href="driver-topups.php?q=<?= e($d['code']) ?>">All</a></h2>
            <?php if (!$tops): ?><p class="muted">No top-ups yet.</p><?php endif; ?>
            <?php foreach ($tops as $t): $exp = uride_topup_is_expired($t); $st = $exp ? 'Expired' : ucfirst($t['status']); ?><div class="kv stmt"><span><?= e(uride_topup_methods()[$t['method']][0] ?? $t['method']) ?> · ₱<?= peso((int) $t['amount_centavos']) ?><small class="sub"><?= e(date('M j, g:i A', strtotime($t['created_at']))) ?><?= $t['agent'] ? ' · MCTC ' . e($t['agent']) . ' #' . e((string) $t['mctc_receipt']) : '' ?><?= $t['payer'] ? ' · paid by ' . e($t['payer']) : '' ?></small></span><span class="badge <?= ['Paid' => 'good', 'Pending' => 'warn'][$st] ?? 'muted' ?>"><?= e($st) ?></span></div><?php endforeach; ?>
        </section>
        <section class="card"><h2>Wallet statement</h2>
            <?php if (!$tx): ?><p class="muted">No wallet activity.</p><?php endif; ?>
            <?php foreach ($tx as $t): $amt = (int) $t['amount_centavos']; ?><div class="kv stmt"><span><?= e($wtypes[$t['type']] ?? $t['type']) ?><small class="sub"><?= e($t['reference']) ?> · <?= e(date('M j, g:i A', strtotime($t['created_at']))) ?><?= $t['memo'] ? ' · ' . e($t['memo']) : '' ?></small></span><b class="<?= $amt < 0 ? 'neg' : 'pos' ?>"><?= $amt < 0 ? '−' : '+' ?>₱<?= peso(abs($amt)) ?></b></div><?php endforeach; ?>
        </section>
    </div>
</div>
<?php admin_footer();
