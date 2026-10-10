<?php
// Application configuration. Override any value with an environment variable
// of the same name (e.g. DB_PASS) so secrets stay out of version control.

function env_or(string $key, string $default): string
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

define('APP_NAME', 'PCEC Community Platform');
define('APP_ORG', 'Philippine Council of Evangelical Churches');
define('APP_TAGLINE', 'Working Together With God');

define('DB_HOST', env_or('DB_HOST', '127.0.0.1'));
define('DB_PORT', env_or('DB_PORT', '3306'));
define('DB_NAME', env_or('DB_NAME', 'pcec_app'));
define('DB_USER', env_or('DB_USER', 'root'));
define('DB_PASS', env_or('DB_PASS', ''));

// Base URL path where the app is served, without trailing slash ('' for web root,
// '/pcec-app' if it lives in a sub-folder of htdocs).
define('BASE_URL', rtrim(env_or('BASE_URL', ''), '/'));

define('UPLOAD_DIR', __DIR__ . '/../uploads');
define('MAX_UPLOAD_BYTES', 20 * 1024 * 1024); // 20 MB

// Social login: fill in OAuth credentials to enable. Buttons show a notice while empty.
define('GOOGLE_CLIENT_ID', env_or('GOOGLE_CLIENT_ID', ''));
define('FACEBOOK_APP_ID', env_or('FACEBOOK_APP_ID', ''));

date_default_timezone_set('Asia/Manila');
