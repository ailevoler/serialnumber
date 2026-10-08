<?php
declare(strict_types=1);

// Configure session protection before config.php starts the session.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    ini_set('session.cookie_secure', $https ? '1' : '0');
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

require_once __DIR__ . '/../config/config.php';

// One clock for the whole app: Philippine time in PHP and in MySQL (TIMESTAMP columns convert per session).
date_default_timezone_set('Asia/Manila');
try {
    $pdo->exec("SET time_zone = '+08:00'");
} catch (Throwable $error) {
    error_log('Could not set database time zone: ' . get_class($error));
}
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/settings.php';

if (!empty($_SESSION['user_id'])) {
    header('Cache-Control: no-store, private');
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header("Location: {$path}");
    exit;
}

function current_user(): ?array
{
    global $pdo;
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function require_auth(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    if (($user['status'] ?? 'active') !== 'active') {
        $_SESSION = [];
        session_regenerate_id(true);
        redirect('login.php?suspended=1');
    }
    return $user;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf'] ?? null;
        $expected = $_SESSION['csrf'] ?? null;
        if (!is_string($token) || !is_string($expected) || $token === ''
            || $expected === '' || !hash_equals($expected, $token)) {
            csrf_failed(is_string($token) && $token !== '', is_string($expected) && $expected !== '');
        }
    }
}

/**
 * Starts a fresh signed-in session (new session id) but keeps the form token, so forms that are already open in
 * other tabs still work after signing in again.
 */
function session_fresh(array $data): void
{
    $csrf = $_SESSION['csrf'] ?? null;
    session_regenerate_id(true);
    $_SESSION = $data;
    if (is_string($csrf) && $csrf !== '') $_SESSION['csrf'] = $csrf;
}

/**
 * A form was posted with a missing or outdated token (page left open, signed in again in another tab, session
 * expired, or a real cross-site request). Nothing is saved. Portals with on-page messages send the user back to the
 * same page with an explanation instead of a blank error page.
 */
function csrf_failed(bool $sentToken, bool $sessionToken): never
{
    error_log('CSRF token rejected on ' . preg_replace('/[^\w\/.?=&-]/', '', (string) ($_SERVER['REQUEST_URI'] ?? '')) . ' (form token ' . ($sentToken ? 'sent' : 'missing') . ', session token ' . ($sessionToken ? 'present' : 'missing') . ')');
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    $ajax = str_contains($accept, 'application/json') || !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (!$ajax && defined('UA_FLASH_PORTAL') && session_status() === PHP_SESSION_ACTIVE && preg_match('#^/(?![/\\\\])[^\s]*$#D', $uri)) {
        csrf_token();
        $_SESSION['flash'][] = ['error', 'Nothing was saved because the page had expired (it was open for a long time, or you signed in again). Please enter your changes again and save.'];
        header('Location: ' . $uri, true, 303);
        exit;
    }
    http_response_code(419);
    if ($ajax) { header('Content-Type: application/json'); exit('{"error":"invalid_token"}'); }
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>Please try again</title><div style="font:16px system-ui,sans-serif;max-width:480px;margin:15vh auto;padding:0 20px"><h1 style="font-size:22px">Please try again</h1><p>This form had expired, so nothing was saved. Go back, reload the page and submit it again.</p><p><a href="javascript:history.back()">Go back</a></p></div>');
}

function format_credits(float $value): string
{
    return number_format($value, 2);
}

function add_transaction(int $userId, string $type, string $title, float $amount, string $status = 'Completed'): void
{
    global $pdo;
    $stmt = $pdo->prepare('INSERT INTO transactions (user_id, type, title, amount, status) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $type, $title, $amount, $status]);
}

/** UBarangay on/off (Admin > Permit types & officials). Off by default; issued certificates stay printable and verifiable. */
function ubarangay_enabled(): bool
{
    return setting('ubarangay.enabled', '0') === '1';
}

function service_catalog(): array
{
    // Active services only. UStay, UFly and UMart were retired on 2026-09-28.
    $services = [
        ['code' => 'URide', 'label' => 'URide', 'image' => 'uride.png', 'href' => 'uride.php'],
        ['code' => 'UPass', 'label' => 'UPass', 'image' => 'upass.png', 'href' => 'service.php?service=UPass'],
        ['code' => 'UGo', 'label' => 'UGo', 'image' => 'ugo.png', 'href' => 'service.php?service=UGo'],
        ['code' => 'ULocal', 'label' => 'ULocal', 'image' => 'ulocal.png', 'href' => 'service.php?service=ULocal'],
        ['code' => 'UEat', 'label' => 'UEat', 'image' => 'ueat.png', 'href' => 'ueat.php'],
        ['code' => 'UBarangay', 'label' => 'UBarangay', 'image' => 'ubarangay.png', 'href' => 'ubarangay.php'],
    ];
    if (!ubarangay_enabled()) $services = array_values(array_filter($services, static fn($s) => $s['code'] !== 'UBarangay'));
    // UBills, ULoad and UCash In (SUNIWAY) are always listed; their pages say "coming soon" until Admin turns payments on.
    require_once __DIR__ . '/suniway.php';
    foreach (suniway_services() as $svc) $services[] = ['code' => $svc['code'], 'label' => $svc['label'], 'image' => $svc['image'], 'href' => $svc['page']];
    return $services;
}

/** Home screen tiles: URide, UPass, UGo, ULocal, UEat, (UBarangay), (UBills, ULoad, UCash In), News/Updates, More. */
function dashboard_tiles(): array
{
    $tiles = service_catalog();
    $tiles[] = ['code' => 'News', 'label' => 'News/Updates', 'short' => 'News', 'image' => 'news.png', 'href' => 'news.php'];
    $tiles[] = ['code' => 'More', 'label' => 'More', 'image' => 'more.png', 'href' => null];
    return $tiles;
}
