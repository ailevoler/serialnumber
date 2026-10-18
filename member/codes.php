<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_member();
$uid = (int)$me['id'];

if (is_post() && input('action') === 'transfer') {
    csrf_check();
    $ids = array_map('intval', (array)($_POST['ids'] ?? []));
    $to = find_member_by_username(strtolower(input('to_username')));
    if (!$ids) {
        flash('error', 'Select at least one code to transfer.');
    } elseif (!$to || (int)$to['id'] === $uid || $to['status'] !== 'active') {
        flash('error', 'Recipient username not found or invalid.');
    } else {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $n = q("UPDATE codes SET owner_id = ? WHERE owner_id = ? AND status = 'unused' AND id IN ($in)", array_merge([$to['id'], $uid], $ids))->rowCount();
        log_activity($uid, "Transferred {$n} code(s) to {$to['username']}");
        flash('success', "{$n} code(s) transferred to @{$to['username']}.");
    }
    redirect('member/codes.php');
}

$status = in_array(input('status'), ['unused', 'used'], true) ? input('status') : 'unused';
$p = paginate('SELECT COUNT(*) FROM codes WHERE owner_id = ? AND status = ?', [$uid, $status]);
$rows = q_all("SELECT c.*, p.name package_name, u.username used_username FROM codes c JOIN packages p ON p.id = c.package_id
               LEFT JOIN users u ON u.id = c.used_by WHERE c.owner_id = ? AND c.status = ? ORDER BY c.id DESC LIMIT {$p['limit']} OFFSET {$p['offset']}", [$uid, $status]);
$counts = array_column(q_all('SELECT status, COUNT(*) n FROM codes WHERE owner_id = ? GROUP BY status', [$uid]), 'n', 'status');

page_header('My Activation Codes', $me, 'Codes you can use to register new members');
?>
<div class="tabs">
  <a href="?status=unused" class="<?= $status === 'unused' ? 'active' : '' ?>">Unused (<?= (int)($counts['unused'] ?? 0) ?>)</a>
  <a href="?status=used" class="<?= $status === 'used' ? 'active' : '' ?>">Used (<?= (int)($counts['used'] ?? 0) ?>)</a>
</div>
<form method="post" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="transfer">
  <div class="card-header"><h3><?= icon('key') ?> <?= ucfirst($status) ?> Codes</h3>
    <?php if ($status === 'unused' && $rows): ?>
    <div class="actions filters">
      <input class="input" name="to_username" placeholder="Recipient username" required>
      <button class="btn btn-gold btn-sm" type="submit" data-confirm="Transfer the selected codes? This cannot be undone."><?= icon('send', 14) ?> Transfer Selected</button>
    </div>
    <?php endif; ?>
  </div>
  <div class="card-body p-0 table-wrap">
    <table class="table stack">
      <thead><tr><?php if ($status === 'unused'): ?><th><input type="checkbox" data-check-all=".code-check" aria-label="Select all"></th><?php endif; ?><th>Code</th><th>PIN</th><th>Package</th><th>Type</th><th><?= $status === 'used' ? 'Used by' : 'Created' ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <?php if ($status === 'unused'): ?><td><input type="checkbox" class="code-check" name="ids[]" value="<?= (int)$r['id'] ?>"></td><?php endif; ?>
          <td><span class="code-chip mono"><?= e($r['code']) ?></span></td>
          <td class="mono"><?= e($r['pin']) ?></td>
          <td><?= e($r['package_name']) ?></td>
          <td><?= code_type_badge($r['code_type']) ?></td>
          <td><?= $status === 'used' ? '@' . e($r['used_username']) . ' · ' . fdate($r['used_at']) : fdate($r['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows) echo empty_row(6, 'No codes found.'); ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($p) ?>
</form>
<?php page_footer();
