<?php
/**
 * XGEN LIFE Intl - Main configuration.
 *
 * For production, copy config/config.local.sample.php to config/config.local.php
 * and put your real database credentials there (that file is git-ignored).
 */

if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

defined('DB_HOST')    || define('DB_HOST', '127.0.0.1');
defined('DB_PORT')    || define('DB_PORT', 3306);
defined('DB_NAME')    || define('DB_NAME', 'xgenlife');
defined('DB_USER')    || define('DB_USER', 'root');
defined('DB_PASS')    || define('DB_PASS', '');

// Leave empty to auto-detect (works in a sub-folder or at the web root).
defined('BASE_URL')   || define('BASE_URL', '');

defined('APP_DEBUG')  || define('APP_DEBUG', false);
defined('APP_TIMEZONE') || define('APP_TIMEZONE', 'Asia/Manila');

define('APP_ROOT', dirname(__DIR__));
define('UPLOAD_DIR', APP_ROOT . '/uploads');
