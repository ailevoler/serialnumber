<?php
// The assigned owner sees the customer's license photo for a self-drive rental (from acceptance until a day after the return).
require_once __DIR__ . '/_bootstrap.php';
$driver = driver_require();
$stmt = $pdo->prepare("SELECT l.photo_file FROM uride_requests r JOIN user_driver_licenses l ON l.id = r.license_id
    WHERE r.id = ? AND r.driver_id = ? AND r.rental_mode = 'self' AND (r.status IN ('accepted','arrived','in_progress') OR (r.status = 'completed' AND r.completed_at > NOW() - INTERVAL 1 DAY))");
$stmt->execute([(int) dq('ride'), (int) $driver['id']]);
$file = $stmt->fetchColumn();
if (!$file) { http_response_code(404); exit('Not found.'); }
uride_license_send((string) $file);
