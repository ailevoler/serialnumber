<?php
declare(strict_types=1);

// Form-token failures redirect back with a message (see csrf_failed()).
const UA_FLASH_PORTAL = true;

// URide Driver App has its own session cookie scoped to /driver/.
if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    session_name('UA_DRIVER');
    session_set_cookie_params([
        'path' => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/driver/x')), '/') . '/',
        'httponly' => true, 'secure' => $https, 'samesite' => 'Lax', 'lifetime' => 60 * 60 * 24 * 14,
    ]);
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/uride.php';
header('Cache-Control: no-store, private');
header('Permissions-Policy: geolocation=(self), camera=(self)');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https://*.googleapis.com https://*.gstatic.com https://*.google.com https://*.ggpht.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'self' https://maps.googleapis.com https://maps.gstatic.com; connect-src 'self' https://*.googleapis.com https://*.gstatic.com; frame-ancestors 'none'; form-action 'self' https://checkout.paymongo.com");

function driver_current(bool $refresh = false): ?array
{
    global $pdo;
    static $d = false;
    if ($d !== false && !$refresh) return $d;
    if (empty($_SESSION['driver_id'])) return $d = null;
    $stmt = $pdo->prepare('SELECT * FROM uride_drivers WHERE id = ?');
    $stmt->execute([$_SESSION['driver_id']]);
    return $d = ($stmt->fetch() ?: null);
}

function driver_require(): array
{
    $d = driver_current();
    if (!$d) { $_SESSION = []; redirect('login.php'); }
    return $d;
}

function dflash(string $type, string $message): void { $_SESSION['flash'][] = [$type, $message]; }
function dp(string $key): string { $v = $_POST[$key] ?? ''; return is_string($v) ? $v : ''; }
function dq(string $key): string { $v = $_GET[$key] ?? ''; return is_string($v) ? trim($v) : ''; }

function driver_badge(string $status): string
{
    $tone = ['approved' => 'good', 'completed' => 'good', 'paid' => 'good', 'pending' => 'warn', 'under_review' => 'info', 'needs_info' => 'warn', 'requested' => 'info',
        'accepted' => 'info', 'arrived' => 'info', 'in_progress' => 'info', 'rejected' => 'bad', 'suspended' => 'bad', 'cancelled' => 'muted', 'expired' => 'muted'][$status] ?? 'muted';
    $label = uride_driver_statuses()[$status] ?? (uride_ride_statuses()[$status] ?? ucwords(str_replace('_', ' ', $status)));
    if ($status === 'requested') $label = 'Requested';
    return '<span class="badge ' . $tone . '">' . e($label) . '</span>';
}

function driver_icon(string $name): string
{
    $p = [
        'drive' => '<path d="M5 17h14M6 17l1.5-6h9L18 17"/><circle cx="8" cy="17" r="2"/><circle cx="16" cy="17" r="2"/><path d="M9 11V8h6v3"/>',
        'trips' => '<path d="M4 6h16M4 12h10M4 18h7"/><circle cx="18" cy="16" r="3"/>',
        'wallet' => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18M16 14h2"/>',
        'account' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
        'pin' => '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'flag' => '<path d="M5 21V4M5 4h11l-2 4 2 4H5"/>',
        'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
        'nav' => '<path d="m3 11 18-8-8 18-2-8z"/>',
        'star' => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>',
        'power' => '<path d="M12 3v9M6.3 6.3a8 8 0 1 0 11.4 0"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    ];
    return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

function driver_header(string $title, string $active = '', string $bodyClass = ''): void
{
    $d = driver_current();
    $nav = ['dashboard' => ['Drive', 'drive'], 'trips' => ['Trips', 'trips'], 'wallet' => ['Wallet', 'wallet'], 'account' => ['Account', 'account']];
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?> · URide Driver</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../merchant-portal/assets/base.css?v=5"><link rel="stylesheet" href="assets/driver.css?v=7">
<link rel="icon" href="../assets/images/pwa/driver-192.png"><meta name="theme-color" content="#0b1015"><meta name="mobile-web-app-capable" content="yes">
<link rel="manifest" href="manifest.webmanifest"><link rel="apple-touch-icon" href="../assets/images/pwa/driver-180.png"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="URide Driver"><meta name="apple-mobile-web-app-status-bar-style" content="black">
<script defer src="../assets/js/pwa.js?v=1"></script>
</head><body class="drv <?= e($bodyClass) ?>">
<?php if ($d): ?>
<header class="d-top">
    <a class="brand" href="dashboard.php"><span class="brand-mark"></span><span><b>URIDE DRIVER</b><small>Ultimate App Boracay</small></span></a>
    <a class="d-wallet-chip" href="wallet.php" aria-label="Wallet balance"><?= driver_icon('wallet') ?>₱<?= peso((int) $d['wallet_centavos']) ?></a>
</header>
<main class="d-main">
<?php if ($title !== ''): ?><h1 class="d-title"><?= e($title) ?></h1><?php endif; ?>
<?php foreach ($_SESSION['flash'] ?? [] as [$type, $message]): ?><div class="flash <?= e($type) ?>" role="status"><?= e($message) ?></div><?php endforeach; unset($_SESSION['flash']); ?>
<?php else: ?>
<div class="auth-wrap d-auth">
<?php foreach ($_SESSION['flash'] ?? [] as [$type, $message]): ?><div class="flash auth-flash <?= e($type) ?>" role="status"><?= e($message) ?></div><?php endforeach; unset($_SESSION['flash']); ?>
<?php endif;
    $GLOBALS['__driver_nav'] = [$nav, $active];
}

function driver_footer(array $scripts = []): void
{
    if (driver_current()) {
        [$nav, $active] = $GLOBALS['__driver_nav'];
        echo '</main><nav class="d-tabs" aria-label="Driver app">';
        foreach ($nav as $k => [$label, $ic]) {
            echo '<a href="' . $k . '.php" class="' . ($active === $k ? 'active' : '') . '"' . ($active === $k ? ' aria-current="page"' : '') . '>' . driver_icon($ic) . '<span>' . e($label) . '</span></a>';
        }
        echo '</nav>';
    } else {
        echo '</div>';
    }
    foreach ($scripts as $src) echo '<script src="' . e($src) . '"></script>';
    echo '</body></html>';
}

/** Short-lived JSON helper for driver/api.php. */
function driver_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
