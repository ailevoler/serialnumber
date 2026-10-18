<?php
/**
 * RBNW WALLET - Core Configuration
 * Rural Bank of New Washington - Digital Wallet
 * Stack: PHP 8+, MySQL, vanilla HTML/CSS/JS
 *
 * NOTE (Hostinger shared hosting):
 * - Secrets (DB password, PayMongo keys) live in config.local.php, which is
 *   NOT committed to git. Copy config.local.example.php -> config.local.php
 *   and fill in the real values from hPanel / PayMongo dashboard.
 * - APP_URL is only used where an absolute link is truly needed (shareable
 *   request links, PayMongo callbacks). Page links, assets, and redirects
 *   use app-relative paths (see app_base()), so the app keeps working
 *   whether it's opened via http/https, www, a temp domain, or a subfolder.
 *   Hardcoding APP_URL into asset/redirect URLs was why login/register
 *   silently did nothing whenever the browser's host didn't match exactly.
 * - DB connection forces Asia/Manila (+08:00) on the PDO session,
 *   not just on the PHP side, so NOW()/CURRENT_TIMESTAMP in MySQL
 *   match what the app displays.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0'); // flip to '1' only while debugging locally
ini_set('log_errors', '1');

date_default_timezone_set('Asia/Manila');

define('APP_ROOT', __DIR__);

// Local secrets/overrides (gitignored). Anything defined there wins.
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

// ---------------------------------------------------------
// APP SETTINGS
// ---------------------------------------------------------
defined('APP_NAME')    || define('APP_NAME', 'RBNW Wallet');
defined('APP_URL')     || define('APP_URL', 'https://rbnw.surgemobileaccess.com');
defined('APP_VERSION') || define('APP_VERSION', filemtime(__FILE__)); // cache-busting seed for asset URLs

// ---------------------------------------------------------
// DATABASE SETTINGS - real values go in config.local.php
// ---------------------------------------------------------
defined('DB_HOST')    || define('DB_HOST', 'localhost');
defined('DB_NAME')    || define('DB_NAME', 'u718203951_rbnwdb');
defined('DB_USER')    || define('DB_USER', 'u718203951_rbnwuser');
defined('DB_PASS')    || define('DB_PASS', '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');

// ---------------------------------------------------------
// PAYMONGO SETTINGS (Cash In via QR Ph) - real keys go in config.local.php
// Use RBNW / Surge Connect's OWN PayMongo account. Never reuse another
// product's keys here — settlement follows whoever owns these keys.
// ---------------------------------------------------------
defined('PAYMONGO_SECRET_KEY')     || define('PAYMONGO_SECRET_KEY', '');
defined('PAYMONGO_PUBLIC_KEY')     || define('PAYMONGO_PUBLIC_KEY', '');
defined('PAYMONGO_WEBHOOK_SECRET') || define('PAYMONGO_WEBHOOK_SECRET', '');

defined('CASH_IN_FEE_PAYMONGO_PCT') || define('CASH_IN_FEE_PAYMONGO_PCT', 1.50);   // PayMongo's QR Ph processing fee
defined('CASH_IN_FEE_RBNW_PCT')     || define('CASH_IN_FEE_RBNW_PCT', 0.00);       // RBNW's own convenience fee, if any
defined('CASH_IN_MIN_AMOUNT')       || define('CASH_IN_MIN_AMOUNT', 50.00);
defined('CASH_IN_MAX_AMOUNT')       || define('CASH_IN_MAX_AMOUNT', 50000.00);
defined('CASH_IN_QR_EXPIRY_MINS')   || define('CASH_IN_QR_EXPIRY_MINS', 15);

// ---------------------------------------------------------
// LOANS (GLoan-style instant cash loan). These are placeholders: set the
// rates, fees and limits RBNW has approved before going live.
// Rate is an add-on rate per month on the original principal.
// ---------------------------------------------------------
defined('LOAN_MIN_AMOUNT')         || define('LOAN_MIN_AMOUNT', 1000.00);
defined('LOAN_MONTHLY_RATE_PCT')   || define('LOAN_MONTHLY_RATE_PCT', 1.99);
defined('LOAN_PROCESSING_FEE_PCT') || define('LOAN_PROCESSING_FEE_PCT', 2.00); // deducted from proceeds
defined('LOAN_TERMS')              || define('LOAN_TERMS', [3, 6, 9, 12]);
defined('LOAN_LIMIT_BY_LEVEL')     || define('LOAN_LIMIT_BY_LEVEL', [1 => 5000.00, 2 => 25000.00, 3 => 50000.00]);
defined('LOAN_REQUIRE_KYC')        || define('LOAN_REQUIRE_KYC', true); // set false in config.local.php to test

// ---------------------------------------------------------
// SECURITY SETTINGS
// ---------------------------------------------------------
define('MPIN_LENGTH', 6);
define('MAX_PIN_ATTEMPTS', 5);
define('LOCKOUT_MINUTES', 15);
define('SESSION_NAME', 'rbnw_wallet_session');

// ---------------------------------------------------------
// REQUEST HELPERS
// ---------------------------------------------------------
function is_https(): bool
{
    // Hostinger/LiteSpeed sometimes sets HTTPS='off' or only forwards the
    // scheme via a proxy header, so isset($_SERVER['HTTPS']) isn't enough.
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        return true;
    }
    return (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/**
 * URL path of the app root as the browser sees it, e.g. '' when the app is
 * at the domain root, or '/rbnw' when it was uploaded into a subfolder.
 * Works from both root pages (login.php) and nested ones (api/auth.php).
 */
function app_base(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $scriptDir  = str_replace('\\', '/', dirname(realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: APP_ROOT));
    $appRoot    = str_replace('\\', '/', realpath(APP_ROOT) ?: APP_ROOT);
    $subPath    = str_starts_with($scriptDir, $appRoot) ? substr($scriptDir, strlen($appRoot)) : '';
    $urlDir     = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));

    if ($subPath !== '' && str_ends_with($urlDir, $subPath)) {
        $urlDir = substr($urlDir, 0, -strlen($subPath));
    }

    $base = rtrim($urlDir, '/');
    return $base;
}

function app_url(string $path = ''): string
{
    return app_base() . '/' . ltrim($path, '/');
}

function is_api_request(): bool
{
    $scriptDir = realpath(dirname($_SERVER['SCRIPT_FILENAME'] ?? ''));
    return $scriptDir !== false && $scriptDir === realpath(APP_ROOT . '/api');
}

// ---------------------------------------------------------
// SESSION
// ---------------------------------------------------------
session_name(SESSION_NAME);
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------------------------------------------------------
// PDO CONNECTION (singleton)
// ---------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Force MySQL session timezone to match PH time regardless of server default
        $pdo->exec("SET time_zone = '+08:00'");
    } catch (PDOException $e) {
        error_log('[RBNW] DB connection failed: ' . $e->getMessage());
        $msg = 'Database connection failed. Please check DB_NAME / DB_USER / DB_PASS in config.local.php.';
        if (is_api_request()) {
            json_out(['success' => false, 'message' => $msg], 500);
        }
        http_response_code(500);
        exit($msg);
    }

    return $pdo;
}

// ---------------------------------------------------------
// DEFENSIVE JSON RESPONSE HELPER (used by every /api endpoint)
// ---------------------------------------------------------
function json_out(array $payload, int $statusCode = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean(); // wipe any stray warning/notice output before it corrupts the JSON
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// Any uncaught error inside an /api endpoint still answers with JSON, so the
// front-end shows a real message instead of a blank 500 ("Network error").
if (is_api_request()) {
    ob_start();
    set_exception_handler(function (Throwable $e): void {
        error_log('[RBNW] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        $msg = 'Something went wrong on the server. Please try again.';
        if ($e instanceof PDOException && in_array($e->getCode(), ['42S02', '42S22'], true)) {
            $msg = 'Database tables are missing or outdated. Import database/schema.sql in phpMyAdmin.';
        }
        json_out(['success' => false, 'message' => $msg], 500);
    });
}

// ---------------------------------------------------------
// AUTH HELPERS
// ---------------------------------------------------------
function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function require_login(): void
{
    if (!current_user_id()) {
        if (is_api_request()) {
            json_out(['success' => false, 'message' => 'Your session has expired. Please log in again.'], 401);
        }
        header('Location: ' . app_url('login.php'));
        exit;
    }
}

function require_guest(): void
{
    if (current_user_id()) {
        header('Location: ' . app_url('dashboard.php'));
        exit;
    }
}

// ---------------------------------------------------------
// SMALL UTILITIES
// ---------------------------------------------------------
function generate_reference(string $prefix = 'RBNW'): string
{
    return $prefix . date('Ymd') . strtoupper(bin2hex(random_bytes(4)));
}

function generate_wallet_code(int $userId): string
{
    return 'RBNW-' . str_pad((string)$userId, 6, '0', STR_PAD_LEFT);
}

function peso(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

function asset_url(string $path): string
{
    $file = APP_ROOT . '/' . ltrim($path, '/');
    $ver  = is_file($file) ? filemtime($file) : APP_VERSION;
    return app_url($path) . '?v=' . $ver;
}

/**
 * Normalizes PH mobile input (09XX..., 9XX..., 639XX..., +63 9XX...) to the
 * 11-digit 09XXXXXXXXX format stored in rbnw_users.mobile_number.
 */
function normalize_mobile(string $raw): string
{
    $d = preg_replace('/\D/', '', $raw);
    if (str_starts_with($d, '63') && strlen($d) === 12) {
        $d = '0' . substr($d, 2);
    } elseif (str_starts_with($d, '9') && strlen($d) === 10) {
        $d = '0' . $d;
    }
    return $d;
}
