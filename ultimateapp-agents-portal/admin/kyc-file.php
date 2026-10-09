<?php
// Streams one KYC photo to signed-in Admin users who can view KYC.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/kyc.php';
admin_require('kyc.view');
$f = basename(q('f'));
$s = $pdo->prepare('SELECT 1 FROM kyc_submissions WHERE id_front = ? OR id_back = ? OR selfie = ? OR liveness_frames LIKE ? LIMIT 1');
$s->execute([$f, $f, $f, '%"' . $f . '"%']);
if (!$s->fetchColumn()) { http_response_code(404); exit('Not found.'); }
kyc_send_file($f);
