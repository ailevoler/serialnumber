<?php
declare(strict_types=1);

// Form-token failures redirect back with a message (see csrf_failed()).
const UA_FLASH_PORTAL = true;

// Merchant Portal has its own session cookie scoped to /merchant-portal/.
if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    session_name('UA_MERCHANT');
    session_set_cookie_params([
        'path' => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/merchant-portal/x')), '/') . '/',
        'httponly' => true, 'secure' => $https, 'samesite' => 'Lax',
    ]);
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/merchants.php';
require_once __DIR__ . '/../includes/ledger.php';
header('Cache-Control: no-store, private');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https://*.googleapis.com https://*.gstatic.com https://*.google.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'self' https://maps.googleapis.com https://maps.gstatic.com; connect-src 'self' https://*.googleapis.com https://*.gstatic.com; frame-ancestors 'none'; form-action 'self'");

function merchant_current(bool $refresh = false): ?array
{
    global $pdo;
    static $m = false;
    if ($m !== false && !$refresh) return $m;
    if (empty($_SESSION['merchant_id'])) return $m = null;
    $stmt = $pdo->prepare('SELECT * FROM merchants WHERE id = ?');
    $stmt->execute([$_SESSION['merchant_id']]);
    return $m = ($stmt->fetch() ?: null);
}

function merchant_require(): array
{
    $m = merchant_current();
    if (!$m) { $_SESSION = []; redirect('login.php'); }
    return $m;
}

function mflash(string $type, string $message): void { $_SESSION['flash'][] = [$type, $message]; }
function mp(string $key): string { $v = $_POST[$key] ?? ''; return is_string($v) ? $v : ''; }
function mq(string $key): string { $v = $_GET[$key] ?? ''; return is_string($v) ? trim($v) : ''; }

function portal_badge(string $status): string
{
    $tone = ['approved' => 'good', 'completed' => 'good', 'paid' => 'good', 'resolved' => 'good', 'pending' => 'warn', 'under_review' => 'info', 'needs_info' => 'warn', 'open' => 'info', 'rejected' => 'bad', 'suspended' => 'bad', 'refunded' => 'muted', 'cancelled' => 'muted', 'closed' => 'muted'][$status] ?? 'muted';
    $label = merchant_statuses()[$status] ?? ucwords(str_replace('_', ' ', $status));
    return '<span class="badge ' . $tone . '">' . e($label) . '</span>';
}

function portal_header(string $title, string $active = ''): void
{
    $m = merchant_current();
    $nav = ['dashboard' => 'Dashboard', 'qr' => 'My QR', 'payments' => 'Payments', 'settlements' => 'Payouts', 'profile' => 'Business', 'kyc' => 'Verify ID', 'support' => 'Support'];
    if ($m && merchant_is_mctc($m)) $nav = array_slice($nav, 0, 3, true) + ['mctc' => 'MCTC Top-up'] + array_slice($nav, 3, null, true);
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · Ultimate App Merchant Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/base.css?v=5"><link rel="stylesheet" href="assets/portal.css?v=2">
<link rel="icon" href="../assets/images/pwa/merchant-192.png"><meta name="theme-color" content="#0b1015">
<link rel="manifest" href="manifest.webmanifest"><link rel="apple-touch-icon" href="../assets/images/pwa/merchant-180.png"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="UA Merchant"><meta name="apple-mobile-web-app-status-bar-style" content="black">
<script defer src="../assets/js/pwa.js?v=1"></script>
</head><body class="portal">
<?php if ($m): ?>
<header class="p-top">
    <div class="p-top-inner">
        <a class="brand" href="dashboard.php"><span class="brand-mark"></span><span><b>ULTIMATE APP</b><small>Merchant Portal</small></span></a>
        <div class="p-me"><span><b><?= e($m['business_name']) ?></b><small><?= e($m['code']) ?></small></span>
            <form method="post" action="logout.php"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="btn small" type="submit">Log out</button></form></div>
    </div>
    <nav class="p-nav" aria-label="Merchant Portal"><?php foreach ($nav as $k => $label): ?><a href="<?= $k ?>.php" class="<?= $active === $k ? 'active' : '' ?>"<?= $active === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach; ?></nav>
</header>
<main class="p-main">
<h1 class="p-title"><?= e($title) ?></h1>
<?php foreach ($_SESSION['flash'] ?? [] as [$type, $message]): ?><div class="flash <?= e($type) ?>" role="status"><?= e($message) ?></div><?php endforeach; unset($_SESSION['flash']); ?>
<?php else: ?>
<div class="auth-wrap p-auth">
<?php foreach ($_SESSION['flash'] ?? [] as [$type, $message]): ?><div class="flash auth-flash <?= e($type) ?>" role="status"><?= e($message) ?></div><?php endforeach; unset($_SESSION['flash']); ?>
<?php endif;
}

function portal_footer(array $scripts = []): void
{
    echo merchant_current() ? '</main><footer class="p-foot">Ultimate App Boracay · Merchant Portal · <a href="support.php">Need help?</a></footer>' : '</div>';
    foreach (array_merge(['assets/portal.js?v=1'], $scripts) as $src) echo '<script src="' . e($src) . '"></script>';
    echo '</body></html>';
}
