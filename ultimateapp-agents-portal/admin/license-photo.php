<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/uride.php';
admin_require('rides.view');
$stmt = $pdo->prepare('SELECT photo_file FROM user_driver_licenses WHERE id = ?');
$stmt->execute([(int) q('id')]);
$file = $stmt->fetchColumn();
if (!$file) { http_response_code(404); exit('Not found.'); }
uride_license_send((string) $file);
