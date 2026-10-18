<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

date_default_timezone_set(APP_TIMEZONE);
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('XGENSESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/compensation.php';
require_once __DIR__ . '/upgrade.php';
require_once __DIR__ . '/paymongo.php';
require_once __DIR__ . '/erp.php';
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/cms.php';
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/simulation.php';
require_once __DIR__ . '/layout.php';
