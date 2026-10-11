<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/market_page.php';
$user = require_auth();
header('Cache-Control: no-store');
mk_service_page($pdo, $user, 'ugo');
