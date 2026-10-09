<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/kyc_page.php';
$m = merchant_require();
kyc_send_csp();
$error = kyc_handle_post($pdo, 'merchant', (int) $m['id'], 'kyc.php');
portal_header('Verify ID (KYC)', 'kyc');
kyc_render($pdo, 'merchant', (int) $m['id'], (string) $m['owner_name'], '../', $error);
portal_footer();
