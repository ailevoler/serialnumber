<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/kyc_page.php';
$d = driver_require();
kyc_send_csp();
$error = kyc_handle_post($pdo, 'driver', (int) $d['id'], 'kyc.php');
driver_header('Verify ID (KYC)', 'account');
kyc_render($pdo, 'driver', (int) $d['id'], (string) $d['full_name'], '../', $error);
driver_footer();
