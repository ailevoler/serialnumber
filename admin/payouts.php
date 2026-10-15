<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

if (is_post()) {
    csrf_check();
    $id = input_int('id');
    $action = input('action');
    $note = input('admin_note');
    try {
        tx(function () use ($id, $action, $note, $me) {
            $po = q_row("SELECT * FROM payouts WHERE id = ? AND status = 'pending' FOR UPDATE", [$id]);
            if (!$po) {
                throw new RuntimeException('Payout request not found or already processed.');
            }
            if ($action === 'approve') {
                q("UPDATE payouts SET status = 'approved', admin_note = ?, processed_by = ?, processed_at = NOW() WHERE id = ?", [$note, $me['id'], $id]);
                erp_post('ZP', "Payout #{$id} released via {$po['method']}" . ($note ? " ({$note})" : ''), [
                    ['acct_payouts_payable', $po['amount'], 0, null, $po['user_id']],
                    ['acct_payout_source', 0, $po['net_amount'], null, $po['user_id']],
                    ['acct_payout_fees', 0, $po['fee'], null, $po['user_id']],
                ], 'payout', $id);
            } elseif ($action === 'reject') {
                q("UPDATE payouts SET status = 'rejected', admin_note = ?, processed_by = ?, processed_at = NOW() WHERE id = ?", [$note ?: 'Rejected', $me['id'], $id]);
                wallet_credit((int)$po['user_id'], 'payout_refund', (float)$po['amount'], 'Refund: payout request #' . $id . ' rejected', 'PO' . $id);
                erp_post('ZP', "Payout #{$id} rejected – returned to wallet", [
                    ['acct_payouts_payable', $po['amount'], 0, null, $po['user_id']],
                    ['acct_wallet_payable', 0, $po['amount'], null, $po['user_id']],
                ], 'payout', $id);
            } else {
                throw new RuntimeException('Invalid action.');
            }
            log_activity((int)$me['id'], ucfirst($action) . "d payout #{$id}");
        });
        flash('success', 'Payout #' . $id . ' ' . ($action === 'approve' ? 'approved.' : 'rejected and refunded to wallet.'));
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('admin/payouts.php?' . http_build_query(array_intersect_key($_GET, array_flip(['status', 'q']))));
}

$status = in_array(input('status'), ['pending', 'approved', 'rejected'], true) ? input('status') : 'pending';
$search = input('q');
$where = ['p.status = ?'];
$params = [$status];
if ($search !== '') { $where[] = '(u.username LIKE ? OR p.account_number LIKE ?)'; array_push($params, "%$search%", "%$search%"); }
$w = implode(' AND ', $where);
$sql = "FROM payouts p JOIN users u ON u.id = p.user_id WHERE $w";

if (input('export') === 'csv') {
    $rows = q_all("SELECT p.id, u.username, CONCAT(u.first_name,' ',u.last_name), p.method, p.account_name, p.account_number, p.amount, p.fee, p.net_amount, p.status, p.created_at, p.processed_at $sql ORDER BY p.id", $params);
    csv_download("xgen-payouts-{$status}-" . date('Ymd') . '.csv', ['ID', 'Username', 'Name', 'Method', 'Account Name', 'Account No.', 'Amount', 'Fee', 'Net', 'Status', 'Requested', 'Processed'], $rows);
}
$p = paginate("SELECT COUNT(*) $sql", $params);
$rows = q_all("SELECT p.*, u.username, u.first_name, u.last_name $sql ORDER BY p.id " . ($status === 'pending' ? 'ASC' : 'DESC') . " LIMIT {$p['limit']} OFFSET {$p['offset']}", $params);
$totals = q_row("SELECT COALESCE(SUM(p.amount),0) amount, COALESCE(SUM(p.net_amount),0) net, COALESCE(SUM(p.fee),0) fee $sql", $params);
$counts = array_column(q_all('SELECT status, COUNT(*) n FROM payouts GROUP BY status'), 'n', 'status');

page_header('Payout Requests', $me, 'Review and release member withdrawals');
?>
<div class="tabs">
  <?php foreach (['pending', 'approved', 'rejected'] as $s): ?><a href="?status=<?= $s ?>" class="<?= $status === $s ? 'active' : '' ?>"><?= ucfirst($s) ?> (<?= (int)($counts[$s] ?? 0) ?>)</a><?php endforeach; ?>
</div>
<div class="stats">
  <?= stat_card('Gross Amount', money($totals['amount']), 'cash') ?>
  <?= stat_card('Fees', money($totals['fee']), 'tag', 'gold') ?>
  <?= stat_card('Net to Release', money($totals['net']), 'send', 'dark') ?>
</div>
<div class="card">
  <div class="card-header">
    <form class="filters" method="get"><input type="hidden" name="status" value="<?= e($status) ?>"><input class="input" name="q" value="<?= e($search) ?>" placeholder="Username or account no."><button class="btn btn-primary btn-sm">Search</button>
      <button class="btn btn-light btn-sm" name="export" value="csv"><?= icon('download', 14) ?> CSV</button></form>
  </div>
  <div class="card-body p-0 table-wrap">
    <table class="table stack">
      <thead><tr><th>#</th><th>Member</th><th>Method / Account</th><th>Amount</th><th>Fee</th><th>Net</th><th>Requested</th><th><?= $status === 'pending' ? 'Action' : 'Note' ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><a href="<?= e(url('admin/member_view.php?id=' . $r['user_id'])) ?>"><?= e(full_name($r)) ?></a><br><small class="text-muted">@<?= e($r['username']) ?></small></td>
          <td><strong><?= e($r['method']) ?></strong><br><small><?= e($r['account_name']) ?> · <span class="mono"><?= e($r['account_number']) ?></span></small></td>
          <td><?= money($r['amount']) ?></td><td><?= money($r['fee']) ?></td><td class="text-green"><strong><?= money($r['net_amount']) ?></strong></td>
          <td class="nowrap"><?= fdatetime($r['created_at']) ?></td>
          <td>
            <?php if ($r['status'] === 'pending'): ?>
              <form method="post" class="filters" style="flex-wrap:nowrap">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input class="input" name="admin_note" placeholder="Note / ref no." style="min-width:120px;padding:6px 8px">
                <button class="btn btn-xs btn-primary" name="action" value="approve" data-confirm="Approve and mark this payout as released?">Approve</button>
                <button class="btn btn-xs btn-danger" name="action" value="reject" data-confirm="Reject this payout? The amount will be returned to the member's wallet.">Reject</button>
              </form>
            <?php else: ?>
              <?= e($r['admin_note']) ?><br><small class="text-muted"><?= fdatetime($r['processed_at']) ?></small>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows) echo empty_row(8); ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($p) ?>
</div>
<?php page_footer();
