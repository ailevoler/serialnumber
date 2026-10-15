<?php
// ============================================================
// TRILINK DISPATCH – config.php
// ============================================================
define('APP_NAME', 'TriLink Dispatch');

$isLocal = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', '::1'])
           || substr($_SERVER['HTTP_HOST'] ?? '', 0, 9) === 'localhost';

define('APP_URL', $isLocal ? 'http://localhost/trilink-dispatch' : 'https://app.trilink.site/trilink-dispatch');
define('APP_ENV', $isLocal ? 'development' : 'production');

// Same database as the other TriLink apps
define('DB_HOST', 'localhost');
define('DB_NAME', 'u718203951_trilinkdb');
define('DB_USER', 'u718203951_trilinkuser');
define('DB_PASS', '__DB_PASS__');

define('SESSION_NAME',     'tl_dispatch_sess');
define('CSRF_TOKEN_NAME',  'tl_dispatch_csrf');
define('DB_CHARSET',       'utf8mb4');
define('MAX_PIN_ATTEMPTS', 5);
define('PIN_LOCK_MINUTES', 30);

if (APP_ENV === 'development') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}
