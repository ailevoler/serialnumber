<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/merchants.php';
admin_require('merchants.view');
$statuses = merchant_statuses();
$view = q('view') === 'board' ? 'board' : 'list';
$counts = array_fill_keys(array_keys($statuses), 0);
foreach ($pdo->query('SELECT status, COUNT(*) n FROM merchants GROUP BY status') as $r) $counts[$r['status']] = (int) $r['n'];

if (q('view') === 'map') {
    $pts = [];
    foreach ($pdo->query("SELECT id, business_name, category, status, latitude, longitude FROM merchants WHERE latitude IS NOT NULL AND status IN ('approved','pending','under_review','needs_info')") as $r) {
        $pts[] = ['lat' => (float) $r['latitude'], 'lng' => (float) $r['longitude'], 'title' => $r['business_name'], 'sub' => $r['category'] . ' · ' . (merchant_statuses()[$r['status']] ?? $r['status'])];
    }
    admin_header('Merchants', 'merchants');
    ?>
    <div class="tabs"><a href="merchants.php">List</a><a href="merchants.php?view=board">Onboarding pipeline</a><a class="active" href="merchants.php?view=map">Map</a></div>
    <section class="card" <?= google_maps_attrs() ?>><h2>Merchant locations <small><?= count($pts) ?> pinned</small></h2>
        <div class="map-box tall" data-map-view data-markers="<?= e(json_encode($pts)) ?>"><?= google_maps_settings()['enabled'] ? 'Loading map…' : 'Add a Google Maps browser key in Settings → Google Maps to see the map.' ?></div><p class="table-meta" data-map-status></p></section>
    <?php admin_footer(['../assets/js/gmaps.js?v=2']); exit;
}
if ($view === 'board') {
    $cols = ['pending', 'under_review', 'needs_info', 'approved', 'rejected'];
    $cards = [];
    $stmt = $pdo->query("SELECT id, business_name, category, barangay, status, created_at, updated_at FROM merchants WHERE status IN ('pending','under_review','needs_info','approved','rejected') ORDER BY updated_at DESC");
    foreach ($stmt as $m) $cards[$m['status']][] = $m;
    admin_header('Merchants', 'merchants');
    ?>
    <div class="tabs"><a href="merchants.php">List</a><a class="active" href="merchants.php?view=board">Onboarding pipeline</a><a href="merchants.php?view=map">Map</a></div>
    <div class="board">
    <?php foreach ($cols as $c): ?>
        <div class="board-col"><h3><?= e($statuses[$c]) ?><span><?= $counts[$c] ?></span></h3>
            <?php foreach (array_slice($cards[$c] ?? [], 0, 30) as $m): ?>
                <a class="board-card" href="merchant.php?id=<?= (int) $m['id'] ?>"><b><?= e($m['business_name']) ?></b><small><?= e($m['category']) ?> · <?= e($m['barangay']) ?></small><small>Updated <?= e(date('M j', strtotime($m['updated_at']))) ?></small></a>
            <?php endforeach; ?>
            <?php if (empty($cards[$c])): ?><p class="muted">Empty</p><?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
    <?php admin_footer(); exit;
}

$search = q('q'); $status = q('status'); $category = q('category');
$where = ['1=1']; $params = [];
if ($search !== '') { $where[] = '(business_name LIKE ? OR owner_name LIKE ? OR email LIKE ? OR code LIKE ? OR mobile LIKE ?)'; array_push($params, ...array_fill(0, 5, '%' . $search . '%')); }
if (isset($statuses[$status])) { $where[] = 'status = ?'; $params[] = $status; }
if (in_array($category, merchant_categories(), true)) { $where[] = 'category = ?'; $params[] = $category; }
$sql = ' FROM merchants WHERE ' . implode(' AND ', $where);
if (q('export') === 'csv') {
    $stmt = $pdo->prepare('SELECT id, code, business_name, category, owner_name, email, mobile, barangay, status, balance_centavos/100, created_at' . $sql . ' ORDER BY id DESC');
    $stmt->execute($params);
    admin_audit('export_merchants');
    csv_download('merchants-' . date('Ymd') . '.csv', ['ID', 'Code', 'Business', 'Category', 'Owner', 'Email', 'Mobile', 'Barangay', 'Status', 'Balance', 'Registered'], $stmt);
}
[$page, $per, $offset] = paging();
$c = $pdo->prepare('SELECT COUNT(*)' . $sql); $c->execute($params); $total = (int) $c->fetchColumn();
$stmt = $pdo->prepare('SELECT *, (SELECT COALESCE(SUM(amount_centavos),0) FROM merchant_payments mp WHERE mp.merchant_id = merchants.id AND mp.status = \'completed\' AND mp.created_at >= NOW() - INTERVAL 30 DAY) sales30' . $sql . " ORDER BY id DESC LIMIT $per OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();
admin_header('Merchants', 'merchants');
?>
<div class="tabs"><a class="active" href="merchants.php">List</a><a href="merchants.php?view=board">Onboarding pipeline</a><a href="merchants.php?view=map">Map</a></div>
<div class="tabs"><a class="<?= $status === '' ? 'active' : '' ?>" href="merchants.php">All<span><?= array_sum($counts) ?></span></a><?php foreach ($statuses as $k => $label): ?><a class="<?= $status === $k ? 'active' : '' ?>" href="?status=<?= e($k) ?>"><?= e($label) ?><span><?= $counts[$k] ?></span></a><?php endforeach; ?></div>
<form class="toolbar" method="get">
    <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Business, owner, email, code" aria-label="Search">
    <select name="category" data-autosubmit aria-label="Category"><option value="">All categories</option><?php foreach (merchant_categories() as $cat): ?><option<?= $category === $cat ? ' selected' : '' ?>><?= e($cat) ?></option><?php endforeach; ?></select>
    <button class="btn" type="submit">Filter</button><span class="spacer"></span>
    <a class="btn" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>"><?= icon('download') ?> Export CSV</a>
</form>
<div class="table-wrap"><table>
    <thead><tr><th>Business</th><th>Category</th><th>Owner</th><th>Barangay</th><th class="num">Sales 30d</th><th class="num">Balance</th><th>Status</th><th>Registered</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="8" class="muted">No merchants yet. Share the Merchant Portal sign-up link: /merchant-portal/register.php</td></tr><?php endif; ?>
    <?php foreach ($rows as $m): ?>
        <tr><td><a class="row-link" href="merchant.php?id=<?= (int) $m['id'] ?>"><?= e($m['business_name']) ?></a><small><?= e($m['code']) ?></small></td><td><?= e($m['category']) ?></td><td><?= e($m['owner_name']) ?><small><?= e($m['email']) ?></small></td><td><?= e($m['barangay']) ?></td><td class="num">₱<?= peso((int) $m['sales30']) ?></td><td class="num">₱<?= peso((int) $m['balance_centavos']) ?></td><td><?= status_badge($m['status']) ?></td><td><?= e(date('M j, Y', strtotime($m['created_at']))) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?= pager($page, $per, $total) ?>
<?php admin_footer();
