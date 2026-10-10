<?php
// Streams the signed-in driver's own selfie or documents.
require_once __DIR__ . '/_bootstrap.php';
$driver = driver_require();
if (dq('selfie') === '1') {
    if (!$driver['selfie_file']) { http_response_code(404); exit; }
    uride_send_file($driver['selfie_file'], uride_selfie_mime($driver['selfie_file']), 'selfie.' . pathinfo($driver['selfie_file'], PATHINFO_EXTENSION));
}
$stmt = $pdo->prepare('SELECT * FROM uride_driver_documents WHERE id = ? AND driver_id = ?');
$stmt->execute([(int) dq('doc'), $driver['id']]);
$doc = $stmt->fetch();
if (!$doc) { http_response_code(404); exit('Not found.'); }
uride_send_file($doc['stored_name'], $doc['mime'], $doc['original_name']);
