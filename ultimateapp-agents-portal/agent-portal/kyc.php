<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/kyc_page.php';
$a = agent_require();
kyc_send_csp();
$error = kyc_handle_post($pdo, 'agent', (int) $a['id'], 'kyc.php');
agent_header('Verify ID (KYC)', 'kyc');
kyc_render($pdo, 'agent', (int) $a['id'], (string) $a['full_name'], '../', $error);
agent_footer();
