<?php
// GET ?ref=<reference>  →  {status} — refreshes from PayMongo while pending (used for polling).
require __DIR__ . '/../includes/bootstrap.php';
require_login();
$p = payment_by_ref((string) ($_GET['ref'] ?? ''));
if (!$p) json_out(['error' => 'Not found'], 404);
$p = payment_refresh($p);
json_out([
    'status' => $p['status'],
    'expired' => $p['method'] === 'qrph' && $p['qr_expires_at'] && strtotime($p['qr_expires_at']) < time(),
    'receipt' => url('payment.php?ref=' . urlencode($p['reference'])),
]);
