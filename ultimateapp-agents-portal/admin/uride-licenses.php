<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/uride.php';
$admin = admin_require('rides.view');
$view = q('view') === 'claims' ? 'claims' : 'licenses';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_guard();
    try {
        if (!admin_can('drivers.review')) throw new InvalidArgumentException('Your role cannot review licenses.');
        uride_license_review($pdo, (int) p('id'), p('decision'), p('note'), (int) $admin['id']);
        admin_audit('license_' . p('decision'), 'user_driver_license', (int) p('id'), ['note' => p('note')]);
        flash('success', 'License ' . (p('decision') === 'approved' ? 'approved' : 'rejected') . '.');
    } catch (InvalidArgumentException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('uride-licenses.php?' . http_build_query(['status' => q('status')]));
}
admin_header('Self-drive', 'uride-licenses');
if (!uride_licenses_ready($pdo)) {
    echo '<div class="flash error" role="status">Import <b>database/uride_selfdrive_migration.sql</b> once in phpMyAdmin, then reload this page.</div>';
    admin_footer();
    exit;
}
$claims = (int) $pdo->query("SELECT COUNT(*) FROM uride_requests WHERE deposit_status = 'claimed'")->fetchColumn();
$pendingN = (int) $pdo->query("SELECT COUNT(*) FROM user_driver_licenses l WHERE status = 'pending' AND id = (SELECT MAX(id) FROM user_driver_licenses x WHERE x.user_id = l.user_id)")->fetchColumn();
?>
<div class="tabs"><a class="<?= $view === 'licenses' ? 'active' : '' ?>" href="uride-licenses.php">Driver's licenses<?= $pendingN ? ' (' . $pendingN . ')' : '' ?></a><a class="<?= $view === 'claims' ? 'active' : '' ?>" href="uride-licenses.php?view=claims">Damage claims<?= $claims ? ' (' . $claims . ')' : '' ?></a></div>
<?php if ($view === 'claims'):
    $rows = $pdo->query("SELECT r.id, r.code, r.vehicle_type, r.deposit_centavos, r.late_fee_centavos, r.claim_centavos, r.claim_note, r.returned_at, u.full_name customer, d.full_name owner, d.plate_no FROM uride_requests r JOIN users u ON u.id = r.user_id LEFT JOIN uride_drivers d ON d.id = r.driver_id WHERE r.deposit_status = 'claimed' ORDER BY r.returned_at")->fetchAll(); ?>
    <p class="muted">The owner reported damage when the vehicle came back. The deposit stays on hold until you decide in the rental. Ask both sides for photos before deciding.</p>
    <div class="table-wrap"><table><thead><tr><th>Rental</th><th>Customer</th><th>Owner</th><th class="num">Deposit left</th><th class="num">Claim</th><th>What happened</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted">No open claims.</td></tr><?php endif; ?>
    <?php foreach ($rows as $c): ?><tr>
        <td><a href="ride.php?id=<?= (int) $c['id'] ?>"><b><?= e($c['code']) ?></b></a><small><?= e(uride_vehicles()[$c['vehicle_type']]['label'] ?? '') ?> · returned <?= e((string) $c['returned_at']) ?></small></td>
        <td><?= e($c['customer']) ?></td><td><?= e((string) $c['owner']) ?><small><?= e((string) $c['plate_no']) ?></small></td>
        <td class="num">₱<?= peso((int) $c['deposit_centavos'] - (int) $c['late_fee_centavos']) ?></td><td class="num">₱<?= peso((int) $c['claim_centavos']) ?></td><td><?= e((string) $c['claim_note']) ?></td>
    </tr><?php endforeach; ?></tbody></table></div>
<?php else:
    $status = in_array(q('status'), ['pending', 'approved', 'rejected'], true) ? q('status') : 'pending';
    [$page, $per, $offset] = paging();
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM user_driver_licenses WHERE status = ?'); $cnt->execute([$status]); $total = (int) $cnt->fetchColumn();
    $s = $pdo->prepare("SELECT l.*, u.full_name, u.mobile FROM user_driver_licenses l JOIN users u ON u.id = l.user_id WHERE l.status = ? ORDER BY l.id DESC LIMIT $per OFFSET $offset");
    $s->execute([$status]); $rows = $s->fetchAll(); ?>
    <p class="muted">Customers add their license before a self-drive rental. Owners also check the physical card at hand-over. Approve when the photo is readable, valid and matches the customer's verified ID.</p>
    <div class="tabs mt"><?php foreach (['pending' => 'To review', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $k => $l): ?><a class="<?= $status === $k ? 'active' : '' ?>" href="?status=<?= $k ?>"><?= $l ?></a><?php endforeach; ?></div>
    <div class="table-wrap"><table><thead><tr><th>Customer</th><th>License</th><th>Photo</th><th>Decision</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="4" class="muted">Nothing here.</td></tr><?php endif; ?>
    <?php foreach ($rows as $l): ?><tr>
        <td><a href="customer.php?id=<?= (int) $l['user_id'] ?>"><?= e($l['full_name']) ?></a><small><?= e((string) $l['mobile']) ?> · <?= e(date('M j, g:i A', strtotime((string) $l['created_at']))) ?></small></td>
        <td><b><?= e($l['license_no']) ?></b><small><?= (int) $l['can_motorcycle'] ? 'A/A1 ' : '' ?><?= (int) $l['can_car'] ? 'B ' : '' ?>· expires <?= e($l['expiry']) ?><?= $l['expiry'] < date('Y-m-d') ? ' (expired)' : '' ?></small><?= $l['review_note'] ? '<small>' . e($l['review_note']) . '</small>' : '' ?></td>
        <td><a href="license-photo.php?id=<?= (int) $l['id'] ?>" target="_blank" rel="noopener"><img src="license-photo.php?id=<?= (int) $l['id'] ?>" alt="License photo" width="120" style="border-radius:6px;border:1px solid #e3e7ec"></a></td>
        <td><?php if (admin_can('drivers.review') && $l['status'] === 'pending'): ?><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
            <input name="note" placeholder="Reason if rejecting" aria-label="Note"><button class="btn small primary" name="decision" value="approved" type="submit">Approve</button><button class="btn small danger" name="decision" value="rejected" type="submit">Reject</button></form>
            <?php else: ?><?= status_badge($l['status']) ?><?php endif; ?></td>
    </tr><?php endforeach; ?></tbody></table></div>
    <?= pager($page, $per, $total) ?>
<?php endif; ?>
<?php admin_footer(); ?>
