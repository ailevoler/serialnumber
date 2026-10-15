<?php
require_once __DIR__ . '/../../includes/core.php';
Permissions::requireAccess('topups');
$PAGE_TITLE = 'Wallet Top-Ups'; $ACTIVE_KEY = 'topups';

$ready = DB::tableExists('tl_topups');

// Re-check a QR Ph top-up against PayMongo (for "I paid but it didn't arrive" cases).
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'recheck') {
    if (!Session::verifyCsrf($_POST[CSRF_TOKEN_NAME] ?? '')) {
        Session::flash('tla_error', 'Invalid session token. Please refresh and try again.');
    } else {
        require_once __DIR__ . '/../../../app/trilink-pay/TopupService.php';
        $t = Topup::findById((int)($_POST['id'] ?? 0));
        if (!$t || $t['channel'] !== 'qrph') {
            Session::flash('tla_error', 'QR Ph top-up not found.');
        } else {
            $before = $t['status'];
            $t = Topup::syncWithProvider($t);
            logActivity('recheck', 'topups', "Re-checked {$t['topup_code']} with PayMongo: {$before} → {$t['status']}");
            Session::flash('tla_success', "{$t['topup_code']}: " . ($t['status'] === 'paid' ? 'paid and credited.' : "still {$t['status']} on PayMongo."));
        }
    }
    redirect('/modules/topups/index.php?' . http_build_query(array_intersect_key($_GET, array_flip(['q','status','channel','page']))));
}

$search  = trim($_GET['q'] ?? '');
$status  = $_GET['status'] ?? '';
$channel = $_GET['channel'] ?? '';
$rows = []; $total = 0; $p = paginate(0, 1); $paidToday = 0; $pendingNow = 0;

if ($ready) {
    $where = ['1=1']; $params = [];
    if ($search !== '') {
        $where[] = '(t.topup_code LIKE ? OR t.provider_intent_id LIKE ? OR d.full_name LIKE ? OR u.full_name LIKE ?)';
        array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
    }
    if (in_array($status, ['pending','paid','expired','cancelled','failed'], true)) { $where[] = 't.status=?'; $params[] = $status; }
    if (in_array($channel, ['qrph','teller'], true)) { $where[] = 't.channel=?'; $params[] = $channel; }
    $whereSql = implode(' AND ', $where);
    $from = "FROM tl_topups t
             LEFT JOIN tl_drivers d ON t.account_type='driver' AND d.id=t.account_id
             LEFT JOIN tl_users u ON t.account_type='passenger' AND u.id=t.account_id
             LEFT JOIN tl_load_centers lc ON lc.id=t.load_center_id";

    $total = (int)DB::val("SELECT COUNT(*) $from WHERE $whereSql", $params);
    $p = paginate($total, (int)($_GET['page'] ?? 1));
    $rows = DB::all("SELECT t.id, t.topup_code, t.account_type, t.amount, t.channel, t.status, t.provider_intent_id,
                            t.credited_txn_code, t.failure_reason, t.paid_at, t.created_at,
                            COALESCE(d.full_name, u.full_name) AS account_name, lc.center_name
                     $from WHERE $whereSql ORDER BY t.created_at DESC LIMIT {$p['perPage']} OFFSET {$p['offset']}", $params);
    $paidToday  = (float)DB::val("SELECT COALESCE(SUM(amount),0) FROM tl_topups WHERE status='paid' AND DATE(paid_at)=CURDATE()");
    $qrphToday  = (float)DB::val("SELECT COALESCE(SUM(amount),0) FROM tl_topups WHERE status='paid' AND channel='qrph' AND DATE(paid_at)=CURDATE()");
    $pendingNow = (int)DB::val("SELECT COUNT(*) FROM tl_topups WHERE status='pending' AND (expires_at IS NULL OR expires_at > NOW())");
}

include __DIR__ . '/../../includes/layout_head.php';
?>
<div class="page-head"><div><h1>Wallet Top-Ups</h1><p>QR Ph (PayMongo) and Teller cash top-ups for passengers and drivers</p></div></div>

<?php if (!$ready): ?>
<div class="card card-pad"><div class="empty-state"><i class="fa-solid fa-database"></i><p>Run <code>app/trilink-pay/migration_topups.sql</code> first.</p></div></div>
<?php else: ?>
<div class="grid grid-cols-3" style="margin-bottom:18px">
  <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Paid Today</span><div class="kpi-icon" style="background:var(--brand-green)"><i class="fa-solid fa-arrow-down"></i></div></div><div class="kpi-value"><?= peso($paidToday) ?></div></div>
  <div class="card kpi"><div class="kpi-top"><span class="kpi-label">QR Ph Today</span><div class="kpi-icon" style="background:var(--brand-blue)"><i class="fa-solid fa-qrcode"></i></div></div><div class="kpi-value"><?= peso($qrphToday) ?></div></div>
  <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Open Requests</span><div class="kpi-icon" style="background:var(--brand-red)"><i class="fa-solid fa-hourglass-half"></i></div></div><div class="kpi-value"><?= number_format($pendingNow) ?></div></div>
</div>

<div class="card">
  <div class="card-pad" style="padding-bottom:0">
    <form class="filters-bar" method="get">
      <input type="text" name="q" placeholder="Search code, intent, name…" value="<?= clean($search) ?>">
      <select name="status" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <?php foreach (['pending','paid','expired','cancelled','failed'] as $s): ?><option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
      </select>
      <select name="channel" onchange="this.form.submit()">
        <option value="">All Channels</option>
        <option value="qrph" <?= $channel==='qrph'?'selected':'' ?>>QR Ph</option>
        <option value="teller" <?= $channel==='teller'?'selected':'' ?>>Teller</option>
      </select>
      <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
    </form>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Code</th><th>Account</th><th>Channel</th><th>Amount</th><th>Status</th><th>Created</th><th>Paid</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="8"><div class="empty-state"><i class="fa-solid fa-qrcode"></i><p>No top-ups found.</p></div></td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="cell-strong"><?= clean($r['topup_code']) ?><?php if ($r['credited_txn_code']): ?><div class="cell-muted"><?= clean($r['credited_txn_code']) ?></div><?php endif; ?></td>
          <td><?= clean($r['account_name'] ?? '—') ?><div class="cell-muted"><?= $r['account_type'] === 'driver' ? 'Driver' : 'Passenger' ?></div></td>
          <td><span class="chip"><?= $r['channel'] === 'qrph' ? 'QR Ph' : 'Teller' ?></span><?php if ($r['center_name']): ?><div class="cell-muted"><?= clean($r['center_name']) ?></div><?php endif; ?></td>
          <td style="font-weight:700"><?= peso($r['amount']) ?></td>
          <td><?= badge($r['status']) ?><?php if ($r['failure_reason']): ?><div class="cell-muted" title="<?= clean($r['failure_reason']) ?>">error</div><?php endif; ?></td>
          <td class="cell-muted"><?= date('M j, g:i A', strtotime($r['created_at'])) ?></td>
          <td class="cell-muted"><?= $r['paid_at'] ? date('M j, g:i A', strtotime($r['paid_at'])) : '—' ?></td>
          <td>
            <?php if ($r['channel'] === 'qrph' && $r['status'] !== 'paid' && $r['provider_intent_id']): ?>
            <form method="post" style="margin:0"><?= csrfField() ?><input type="hidden" name="do" value="recheck"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm" type="submit" title="Ask PayMongo if this was paid"><i class="fa-solid fa-rotate"></i> Re-check</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?= pagerLinks($p['page'], $p['totalPages']) ?>
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/layout_foot.php'; ?>
