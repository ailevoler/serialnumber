<?php
require __DIR__ . '/_admin.php';

if (is_post()) {
    csrf_check();
    $p = payment_get((int) ($_POST['id'] ?? 0));
    $action = $_POST['action'] ?? '';
    if ($p && $p['status'] === 'pending') {
        if ($action === 'mark_paid') {
            payment_mark_paid((int) $p['id'], null, 'Confirmed by ' . current_user()['username'] . ($p['payer_reference'] ? ' (bank ref ' . $p['payer_reference'] . ')' : ''));
            flash('success', $p['reference'] . ' marked as paid.');
        } elseif ($action === 'mark_failed') {
            q("UPDATE payments SET status = 'failed', admin_note = ? WHERE id = ?", ['Rejected by ' . current_user()['username'], $p['id']]);
            q("DELETE FROM event_registrations WHERE payment_id = ? AND status = 'pending'", [$p['id']]);
            if ($p['user_id']) notify((int) $p['user_id'], uid(), 'payment', 'We could not confirm your payment ' . $p['reference'] . '. Please contact the PCEC office.', 'payment.php?ref=' . $p['reference']);
            flash('info', $p['reference'] . ' marked as failed.');
        } elseif ($action === 'refresh') {
            $p = payment_refresh($p, true);
            flash('info', $p['reference'] . ' is ' . $p['status'] . ' on PayMongo.');
        }
    }
    redirect('admin/payments.php?' . http_build_query(array_intersect_key($_GET, array_flip(['q', 'status', 'purpose', 'method']))));
}

$where = ['1=1'];
$params = [];
$flt = ['q' => trim((string) ($_GET['q'] ?? '')), 'status' => (string) ($_GET['status'] ?? ''), 'purpose' => (string) ($_GET['purpose'] ?? ''), 'method' => (string) ($_GET['method'] ?? '')];
if ($flt['q'] !== '') {
    $where[] = "(p.reference LIKE ? OR p.description LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.email LIKE ? OR p.payer_reference LIKE ?)";
    array_push($params, ...array_fill(0, 5, '%' . $flt['q'] . '%'));
}
foreach (['status' => ['pending', 'paid', 'failed', 'expired', 'cancelled'], 'purpose' => ['donation', 'event'], 'method' => ['qrph', 'card', 'ewallet', 'bank']] as $k => $allowed) {
    if (in_array($flt[$k], $allowed, true)) { $where[] = "p.$k = ?"; $params[] = $flt[$k]; }
}
$sql = 'SELECT p.*, u.first_name, u.last_name, u.email, d.fund, d.gift_type, d.is_anonymous FROM payments p LEFT JOIN users u ON u.id = p.user_id
        LEFT JOIN donations d ON d.payment_id = p.id WHERE ' . implode(' AND ', $where) . ' ORDER BY p.created_at DESC';

if (($_GET['export'] ?? '') === 'csv') {
    $rows = array_map(fn($r) => [$r['reference'], $r['created_at'], $r['paid_at'], trim($r['first_name'] . ' ' . $r['last_name']), $r['email'],
        $r['purpose'], $r['description'], $r['fund'], method_label($r['method']), number_format($r['amount'] / 100, 2, '.', ''), $r['status'],
        $r['provider_payment_id'], $r['payer_reference'], $r['admin_note']], q_all($sql, $params));
    csv_out('pcec-payments-' . date('Ymd') . '.csv', ['Reference', 'Created', 'Paid at', 'Name', 'Email', 'Purpose', 'Description', 'Fund',
        'Method', 'Amount (PHP)', 'Status', 'PayMongo payment', 'Bank reference', 'Note'], $rows);
}
$rows = q_all($sql . ' LIMIT 300', $params);
$total = array_sum(array_map(fn($r) => $r['status'] === 'paid' ? (int) $r['amount'] : 0, $rows));

$pageTitle = 'Admin';
$activeNav = 'admin';
require __DIR__ . '/../includes/header.php';
admin_tabs('payments');
$sel = fn($k, $v) => $flt[$k] === $v ? 'selected' : '';
?>
<div class="admin-wrap">
  <form class="filter-bar card" method="get">
    <label class="search-bar"><?= icon('search') ?><input name="q" value="<?= e($flt['q']) ?>" placeholder="Reference, name, email, bank ref…"></label>
    <select name="status"><option value="">All status</option><?php foreach (['pending', 'paid', 'failed', 'cancelled'] as $v): ?><option value="<?= $v ?>" <?= $sel('status', $v) ?>><?= ucfirst($v) ?></option><?php endforeach; ?></select>
    <select name="purpose"><option value="">All types</option><option value="donation" <?= $sel('purpose', 'donation') ?>>Donations</option><option value="event" <?= $sel('purpose', 'event') ?>>Event fees</option></select>
    <select name="method"><option value="">All methods</option><?php foreach (['qrph', 'card', 'ewallet', 'bank'] as $v): ?><option value="<?= $v ?>" <?= $sel('method', $v) ?>><?= e(method_label($v)) ?></option><?php endforeach; ?></select>
    <button class="btn btn-gradient"><?= icon('search') ?> Filter</button>
    <a class="btn btn-outline" href="?<?= e(http_build_query($flt + ['export' => 'csv'])) ?>"><?= icon('download') ?> CSV</a>
  </form>
  <p class="muted"><?= count($rows) ?> payment(s) · <?= money($total) ?> paid in this view</p>

  <div class="admin-list">
    <?php foreach ($rows as $r): ?>
      <article class="card admin-item">
        <div class="admin-item-main">
          <div>
            <strong><?= e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'Guest') ?></strong><?= $r['is_anonymous'] ? ' <span class="tag tag-soft">Anonymous</span>' : '' ?>
            <small class="muted"><?= e($r['description']) ?></small>
            <small class="mono"><?= e($r['reference']) ?> · <?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?> · <?= e(method_label($r['method'])) ?><?= $r['livemode'] ? '' : ' · test' ?></small>
            <?php if ($r['payer_reference']): ?><small>Bank ref: <b><?= e($r['payer_reference']) ?></b></small><?php endif; ?>
            <?php if ($r['admin_note']): ?><small class="muted"><?= e($r['admin_note']) ?></small><?php endif; ?>
          </div>
          <div class="admin-item-amt"><b><?= money((int) $r['amount'], true) ?></b><?= payment_status_badge($r['status']) ?></div>
        </div>
        <?php if ($r['status'] === 'pending'): ?>
          <div class="admin-item-actions">
            <?php if ($r['intent_id'] || $r['checkout_id']): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="action" value="refresh"><button class="btn btn-sm btn-outline"><?= icon('refresh') ?> Check PayMongo</button></form>
            <?php endif; ?>
            <form method="post" data-confirm="Mark <?= e($r['reference']) ?> as PAID? Only do this after the money is in the account."><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="action" value="mark_paid"><button class="btn btn-sm btn-gradient"><?= icon('check') ?> Mark paid</button></form>
            <form method="post" data-confirm="Reject this payment?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="action" value="mark_failed"><button class="btn btn-sm btn-danger-outline"><?= icon('x') ?> Reject</button></form>
          </div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
    <?php if (!$rows) empty_state('receipt', 'No payments match these filters.'); ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
