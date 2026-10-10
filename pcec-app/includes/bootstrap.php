<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/auth.php';

auth_bootstrap();
