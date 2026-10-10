<?php
declare(strict_types=1);

// Form-token failures redirect back with a message (see csrf_failed()).
const UA_FLASH_PORTAL = true;

// Admin uses its own session cookie (scoped to /admin/) so an app login never grants admin access.
if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    session_name('UA_ADMIN');
    session_set_cookie_params([
        'path' => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/x')), '/') . '/',
        'httponly' => true, 'secure' => $https, 'samesite' => 'Strict',
    ]);
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ledger.php';
header('Cache-Control: no-store, private');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https://*.googleapis.com https://*.gstatic.com https://*.google.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'self' https://maps.googleapis.com https://maps.gstatic.com; connect-src 'self' https://*.googleapis.com https://*.gstatic.com; frame-ancestors 'none'; form-action 'self'");

const ADMIN_ROLES = ['super_admin' => 'Super Admin', 'finance' => 'Finance', 'support' => 'Support', 'barangay_officer' => 'Barangay Officer'];

function admin_permissions(): array
{
    return [
        'finance' => ['dashboard', 'customers.view', 'customers.adjust', 'merchants.view', 'payments.view', 'payments.refund',
            'finance.view', 'settlements.manage', 'reports.view', 'crm.notes', 'drivers.view', 'drivers.wallet', 'rides.view', 'driver_payouts.manage',
            'agents.view', 'agents.commissions', 'agent_payouts.manage', 'service_requests.view', 'kyc.view'],
        'support' => ['dashboard', 'customers.view', 'customers.suspend', 'merchants.view', 'merchants.review', 'payments.view',
            'tickets.manage', 'crm.notes', 'drivers.view', 'drivers.review', 'rides.view', 'rides.cancel',
            'agents.view', 'agents.review', 'service_requests.view', 'service_requests.manage', 'kyc.view', 'kyc.review'],
        'barangay_officer' => ['barangay.manage'],
    ];
}

function admin_current(): ?array
{
    global $pdo;
    static $admin = false;
    if ($admin !== false) return $admin;
    if (empty($_SESSION['admin_id'])) return $admin = null;
    $stmt = $pdo->prepare("SELECT id, name, email, role, status, barangay FROM admin_users WHERE id = ? AND status = 'active'");
    $stmt->execute([$_SESSION['admin_id']]);
    return $admin = ($stmt->fetch() ?: null);
}

function admin_can(string $permission): bool
{
    $admin = admin_current();
    if (!$admin) return false;
    if ($admin['role'] === 'super_admin') return true;
    return in_array($permission, admin_permissions()[$admin['role']] ?? [], true);
}

function admin_require(?string $permission = null): array
{
    $admin = admin_current();
    if (!$admin) {
        $_SESSION = [];
        redirect('login.php');
    }
    // Idle timeout: 60 minutes.
    if (time() - (int) ($_SESSION['admin_seen'] ?? 0) > 3600) {
        session_fresh([]);
        redirect('login.php?expired=1');
    }
    $_SESSION['admin_seen'] = time();
    if ($permission !== null && !admin_can($permission)) {
        http_response_code(403);
        admin_header('Access denied', '');
        echo '<div class="empty"><h2>Access denied</h2><p>Your role (' . e(ADMIN_ROLES[$admin['role']]) . ') cannot open this page.</p><a class="btn" href="index.php">Back to dashboard</a></div>';
        admin_footer();
        exit;
    }
    return $admin;
}

function admin_audit(string $action, ?string $entityType = null, int|string|null $entityId = null, array|string|null $details = null): void
{
    global $pdo;
    try {
        $pdo->prepare('INSERT INTO admin_audit_log (admin_id, action, entity_type, entity_id, details, ip) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([admin_current()['id'] ?? null, $action, $entityType, $entityId === null ? null : (string) $entityId,
                is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $details, rate_limit_client_ip()]);
    } catch (Throwable $error) {
        error_log('Audit log failed: ' . $error->getMessage());
    }
}

/** Barangay an officer is limited to; null = all barangays (Super Admin). */
function admin_brgy_scope(): ?string
{
    $admin = admin_current();
    if (!$admin || $admin['role'] !== 'barangay_officer') return null;
    return (string) ($admin['barangay'] ?? '__none__');
}

function brgy_badge(string $status): string
{
    $tone = ['for_review' => 'info', 'pending_payment' => 'warn', 'needs_info' => 'warn', 'approved' => 'good', 'rejected' => 'bad', 'cancelled' => 'muted'][$status] ?? 'muted';
    $label = ['for_review' => 'For review', 'pending_payment' => 'Awaiting payment', 'needs_info' => 'Needs info', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'][$status] ?? $status;
    return '<span class="badge ' . $tone . '">' . e($label) . '</span>';
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [$type, $message];
}

function admin_post_guard(): void
{
    verify_csrf();
    rate_limit_enforce('admin_write', 'admin:' . (admin_current()['id'] ?? 0), 240, 600);
}

function q(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function p(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? $value : $default;
}

function status_badge(string $status): string
{
    $tone = [
        'active' => 'good', 'approved' => 'good', 'completed' => 'good', 'paid' => 'good', 'resolved' => 'good',
        'pending' => 'warn', 'under_review' => 'info', 'needs_info' => 'warn', 'open' => 'info',
        'suspended' => 'bad', 'rejected' => 'bad', 'disabled' => 'bad', 'refunded' => 'muted', 'cancelled' => 'muted', 'closed' => 'muted',
        'urgent' => 'bad', 'high' => 'warn', 'normal' => 'muted', 'low' => 'muted',
    ][$status] ?? 'muted';
    return '<span class="badge ' . $tone . '">' . e(ucwords(str_replace('_', ' ', $status))) . '</span>';
}

/** Returns [page, perPage, offset]. */
function paging(int $perPage = 25): array
{
    $page = max(1, (int) q('page', '1'));
    return [$page, $perPage, ($page - 1) * $perPage];
}

function pager(int $page, int $perPage, int $total): string
{
    $pages = max(1, (int) ceil($total / $perPage));
    if ($pages <= 1) return '<p class="table-meta">' . number_format($total) . ' record' . ($total === 1 ? '' : 's') . '</p>';
    $params = $_GET;
    $link = static function (int $n) use ($params): string { $params['page'] = $n; return '?' . http_build_query($params); };
    $html = '<nav class="pager" aria-label="Pages"><span>' . number_format($total) . ' records · page ' . $page . ' of ' . $pages . '</span>';
    if ($page > 1) $html .= '<a href="' . e($link($page - 1)) . '">&larr; Prev</a>';
    if ($page < $pages) $html .= '<a href="' . e($link($page + 1)) . '">Next &rarr;</a>';
    return $html . '</nav>';
}

function csv_download(string $filename, array $header, iterable $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header);
    foreach ($rows as $row) {
        // Neutralise spreadsheet formulas in user-supplied text.
        fputcsv($out, array_map(static fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) && !is_numeric($v) ? "'" . $v : $v, array_values($row)));
    }
    fclose($out);
    exit;
}

function icon(string $name): string
{
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'users' => '<circle cx="9" cy="8" r="4"/><path d="M2 21c1-4 4-6 7-6s6 2 7 6"/><path d="M16 4a4 4 0 0 1 0 8M22 21c-.6-2.6-2-4.4-4-5.3"/>',
        'store' => '<path d="M3 9 5 3h14l2 6"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M5 12v9h14v-9"/><path d="M10 21v-5h4v5"/>',
        'ticket' => '<path d="M3 8a2 2 0 0 0 0 4v5h18v-5a2 2 0 0 0 0-4V3H3z" transform="translate(0 2)"/><path d="M13 5v14" stroke-dasharray="2 2.5"/>',
        'card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>',
        'finance' => '<path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-6"/>',
        'book' => '<path d="M4 4h11a3 3 0 0 1 3 3v14H7a3 3 0 0 1-3-3z"/><path d="M4 18a3 3 0 0 1 3-3h11"/>',
        'bank' => '<path d="m3 9 9-6 9 6"/><path d="M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 21h18"/>',
        'report' => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 17v-3M12 17v-6M16 17v-2"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'log' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'car' => '<path d="M5 17h14v-5l-2-5H7l-2 5z"/><path d="M5 12h14"/><circle cx="8" cy="17" r="2"/><circle cx="16" cy="17" r="2"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
    ];
    return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

function admin_nav(): array
{
    return [
        'Overview' => [['index.php', 'dashboard', 'Dashboard', 'dashboard']],
        'CRM' => [
            ['customers.php', 'users', 'Customers', 'customers.view'],
            ['merchants.php', 'store', 'Merchants', 'merchants.view'],
            ['tickets.php', 'ticket', 'Support tickets', 'tickets.manage'],
            ['kyc.php', 'shield', 'KYC verification', 'kyc.view'],
        ],
        'ERP' => [
            ['payments.php', 'card', 'Payments', 'payments.view'],
            ['suniway.php', 'card', 'Bills & Load (SUNIWAY)', 'payments.view'],
            ['fx.php', 'finance', 'Currency exchange', 'finance.view'],
            ['finance.php', 'finance', 'Finance', 'finance.view'],
            ['ledger.php', 'book', 'General ledger', 'finance.view'],
            ['settlements.php', 'bank', 'Settlements', 'settlements.manage'],
            ['reports.php', 'report', 'Reports', 'reports.view'],
        ],
        'URide' => [
            ['drivers.php', 'users', 'Drivers', 'drivers.view'],
            ['rides.php', 'car', 'Rides', 'rides.view'],
            ['driver-topups.php', 'card', 'Driver top-ups', 'drivers.view'],
            ['driver-payouts.php', 'bank', 'Driver payouts', 'driver_payouts.manage'],
        ],
        'Agents' => [
            ['agents.php', 'users', 'Agents', 'agents.view'],
            ['agent-payouts.php', 'bank', 'Agent payouts', 'agent_payouts.manage'],
            ['service-requests.php', 'ticket', 'UPass / UGo / ULocal', 'service_requests.view'],
        ],
        'UBarangay' => [
            ['brgy-applications.php', 'ticket', 'Permit applications', 'barangay.manage'],
            ['brgy-posts.php', 'report', 'News & activities', 'barangay.manage'],
            ['brgy-types.php', 'settings', 'Permit types & officials', 'barangay.settings'],
        ],
        'System' => [
            ['settings.php', 'settings', 'Settings', 'settings'],
            ['storage.php', 'report', 'Files & storage', 'settings'],
            ['admins.php', 'shield', 'Admin users', 'admins'],
            ['audit.php', 'log', 'Audit log', 'audit'],
        ],
    ];
}

function admin_header(string $title, string $active): void
{
    global $pdo;
    $admin = admin_current();
    $counts = ['merchants' => 0, 'tickets' => 0];
    if ($admin) {
        try {
            $counts['merchants'] = (int) $pdo->query("SELECT COUNT(*) FROM merchants WHERE status IN ('pending','under_review')")->fetchColumn();
            $counts['tickets'] = (int) $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status = 'open'")->fetchColumn();
            $scope = admin_brgy_scope();
            $s = $pdo->prepare("SELECT COUNT(*) FROM brgy_applications WHERE status = 'for_review'" . ($scope ? ' AND barangay = ?' : ''));
            $s->execute($scope ? [$scope] : []);
            $counts['brgy'] = (int) $s->fetchColumn();
            $counts['drivers'] = (int) $pdo->query("SELECT COUNT(*) FROM uride_drivers WHERE status IN ('pending','under_review')")->fetchColumn();
            $counts['driver-topups'] = (int) $pdo->query("SELECT COUNT(*) FROM uride_driver_topups WHERE method = 'mctc' AND status = 'pending' AND expires_at > NOW()")->fetchColumn();
            $counts['driver-payouts'] = (int) $pdo->query("SELECT COUNT(*) FROM uride_payouts WHERE status = 'requested'")->fetchColumn();
        } catch (Throwable $e) {}
        try {
            $counts['agents'] = (int) $pdo->query("SELECT COUNT(*) FROM agents WHERE status = 'pending'")->fetchColumn();
            $counts['agent-payouts'] = (int) $pdo->query("SELECT COUNT(*) FROM agent_payouts WHERE status = 'requested'")->fetchColumn();
            $counts['suniway'] = (int) $pdo->query("SELECT COUNT(*) FROM suniway_transactions WHERE status IN ('unknown','submitting')")->fetchColumn();
            $counts['service-requests'] = (int) $pdo->query("SELECT COUNT(*) FROM service_requests WHERE status = 'pending' AND service_code IN ('UPass','UGo','ULocal')")->fetchColumn();
        } catch (Throwable $e) {}
        try { $counts['kyc'] = (int) $pdo->query("SELECT COUNT(*) FROM kyc_submissions WHERE status = 'pending'")->fetchColumn(); } catch (Throwable $e) {}
    }
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · Ultimate App Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/admin.css?v=10">
<link rel="icon" href="../assets/images/pwa/icon-192.png">
</head>
<body>
<?php if ($admin): ?>
<div class="shell">
<aside class="sidebar" id="sidebar">
    <a class="brand" href="index.php"><span class="brand-mark"></span><span><b>ULTIMATE APP</b><small>Boracay · Admin</small></span></a>
    <nav>
    <?php foreach (admin_nav() as $group => $items): $visible = array_filter($items, static fn($i) => admin_can($i[3])); if (!$visible) continue; ?>
        <p class="nav-group"><?= e($group) ?></p>
        <?php foreach ($visible as [$href, $ic, $label, $perm]): $key = basename($href, '.php'); $badge = $key === 'merchants' ? $counts['merchants'] : ($key === 'tickets' ? $counts['tickets'] : ($key === 'brgy-applications' ? ($counts['brgy'] ?? 0) : ($counts[$key] ?? 0))); ?>
            <a href="<?= e($href) ?>" class="<?= $active === $key ? 'active' : '' ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><?= icon($ic) ?><span><?= e($label) ?></span><?php if ($badge): ?><em><?= $badge ?></em><?php endif; ?></a>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </nav>
    <div class="me"><span class="avatar"><?= e(mb_strtoupper(mb_substr($admin['name'], 0, 1))) ?></span><span><b><?= e($admin['name']) ?></b><small><?= e(ADMIN_ROLES[$admin['role']]) ?></small></span>
        <form method="post" action="logout.php"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button type="submit" title="Log out" aria-label="Log out"><?= icon('logout') ?></button></form></div>
</aside>
<div class="main">
<header class="topbar"><button class="menu-btn" type="button" data-sidebar-toggle aria-controls="sidebar" aria-label="Menu"><?= icon('menu') ?></button><h1><?= e($title) ?></h1>
    <form class="global-search" action="customers.php" method="get" role="search"><?= icon('search') ?><input name="q" type="search" placeholder="Search customers, email, mobile, QR ID" aria-label="Search customers"></form></header>
<main class="content">
<?php foreach ($_SESSION['flash'] ?? [] as [$type, $message]): ?><div class="flash <?= e($type) ?>" role="status"><?= e($message) ?></div><?php endforeach; unset($_SESSION['flash']); ?>
<?php else: ?>
<div class="auth-wrap">
<?php foreach ($_SESSION['flash'] ?? [] as [$type, $message]): ?><div class="flash auth-flash <?= e($type) ?>" role="status"><?= e($message) ?></div><?php endforeach; unset($_SESSION['flash']); ?>
<?php endif;
}

function admin_footer(array $scripts = []): void
{
    $admin = admin_current();
    if ($admin) echo "</main></div></div>\n"; else echo "</div>\n";
    foreach (array_merge(['assets/admin.js?v=2'], $scripts) as $src) echo '<script src="' . e($src) . '"></script>';
    echo '</body></html>';
}
