<?php
require_once __DIR__ . '/_bootstrap.php';
admin_require('customers.view');
$search = q('q'); $status = q('status'); $tag = q('tag');
$where = ['1=1']; $params = [];
if ($search !== '') { $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.mobile LIKE ? OR u.qr_code LIKE ?)'; array_push($params, ...array_fill(0, 4, '%' . $search . '%')); }
if (in_array($status, ['active', 'suspended'], true)) { $where[] = 'u.status = ?'; $params[] = $status; }
if ($status === 'mctc') $where[] = "u.role = 'mctc'";
if ($tag !== '') { $where[] = "EXISTS (SELECT 1 FROM crm_tags t WHERE t.entity_type = 'user' AND t.entity_id = u.id AND t.tag = ?)"; $params[] = $tag; }
$sql = ' FROM users u LEFT JOIN boracay_cash_wallets w ON w.user_id = u.id WHERE ' . implode(' AND ', $where);
$sort = ['newest' => 'u.id DESC', 'credits' => 'u.credits DESC', 'name' => 'u.full_name'][q('sort', 'newest')] ?? 'u.id DESC';
if (q('export') === 'csv') {
    $stmt = $pdo->prepare('SELECT u.id, u.full_name, u.email, u.mobile, u.qr_code, u.credits, COALESCE(w.balance_centavos,0)/100 cash, u.status, u.created_at' . $sql . ' ORDER BY ' . $sort);
    $stmt->execute($params);
    admin_audit('export_customers');
    csv_download('customers-' . date('Ymd') . '.csv', ['ID', 'Name', 'Email', 'Mobile', 'QR ID', 'Credits', 'BCash', 'Status', 'Joined'], $stmt);
}
[$page, $per, $offset] = paging();
$count = $pdo->prepare('SELECT COUNT(*)' . $sql); $count->execute($params); $total = (int) $count->fetchColumn();
$stmt = $pdo->prepare('SELECT u.*, COALESCE(w.balance_centavos,0) cash' . $sql . " ORDER BY $sort LIMIT $per OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$tags = $pdo->query("SELECT tag, COUNT(*) n FROM crm_tags WHERE entity_type = 'user' GROUP BY tag ORDER BY tag")->fetchAll();
admin_header('Customers', 'customers');
?>
<form class="toolbar" method="get">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, email, mobile or QR ID" aria-label="Search">
    <select name="status" data-autosubmit aria-label="Status"><option value="">All statuses</option><option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option><option value="suspended"<?= $status === 'suspended' ? ' selected' : '' ?>>Suspended</option><option value="mctc"<?= $status === 'mctc' ? ' selected' : '' ?>>MCTC agents</option></select>
    <select name="tag" data-autosubmit aria-label="Tag"><option value="">All tags</option><?php foreach ($tags as $t): ?><option value="<?= e($t['tag']) ?>"<?= $tag === $t['tag'] ? ' selected' : '' ?>><?= e($t['tag']) ?> (<?= (int) $t['n'] ?>)</option><?php endforeach; ?></select>
    <select name="sort" data-autosubmit aria-label="Sort"><option value="newest">Newest</option><option value="credits"<?= q('sort') === 'credits' ? ' selected' : '' ?>>Highest Credits</option><option value="name"<?= q('sort') === 'name' ? ' selected' : '' ?>>Name A–Z</option></select>
    <button class="btn" type="submit">Filter</button>
    <span class="spacer"></span>
    <a class="btn" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>"><?= icon('download') ?> Export CSV</a>
</form>
<div class="table-wrap"><table>
    <thead><tr><th>Customer</th><th>Mobile</th><th>QR ID</th><th class="num">Credits</th><th class="num">BCash</th><th>Status</th><th>Joined</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">No customers match.</td></tr><?php endif; ?>
    <?php foreach ($rows as $u): ?>
        <tr><td><a class="row-link" href="customer.php?id=<?= (int) $u['id'] ?>"><?= e($u['full_name']) ?></a><small><?= e($u['email']) ?></small></td><td><?= e($u['mobile']) ?></td><td><?= e($u['qr_code']) ?></td><td class="num"><?= number_format((float) $u['credits'], 2) ?></td><td class="num">₱<?= peso((int) $u['cash']) ?></td><td><?= status_badge($u['status']) ?></td><td><?= e(date('M j, Y', strtotime($u['created_at']))) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?= pager($page, $per, $total) ?>
<?php admin_footer();
