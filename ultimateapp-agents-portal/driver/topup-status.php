<?php
// JSON status for the driver's in-app QR page (polled every few seconds).
require_once __DIR__ . '/_bootstrap.php';
header('Content-Type: application/json');
$driver = driver_current();
if (!$driver) { http_response_code(401); exit(json_encode(['status' => 'signed_out'])); }
session_write_close();
$stmt = $pdo->prepare('SELECT * FROM uride_driver_topups WHERE reference = ? AND driver_id = ?');
$stmt->execute([(string) ($_GET['reference'] ?? ''), $driver['id']]);
$order = $stmt->fetch();
if (!$order) { http_response_code(404); exit(json_encode(['status' => 'not_found'])); }
if ($order['status'] === 'paid') exit(json_encode(['status' => 'paid']));
if ($order['method'] !== 'qrph') {
    // MCTC / BCash are settled inside Ultimate App: just report the stored state.
    exit(json_encode(['status' => $order['status'] === 'cancelled' ? 'cancelled' : (uride_topup_is_expired($order) ? 'expired' : 'pending')]));
}
// Ask PayMongo at most every ~8 seconds per order; otherwise report what the webhook has recorded.
$take = rate_limit_take($pdo, 'driver_topup_poll', 'order:' . $order['id'], 1, 8);
if ($take['allowed']) {
    try {
        if (uride_topup_reconcile($pdo, $order) === 'paid') exit(json_encode(['status' => 'paid']));
    } catch (Throwable $e) {
        error_log('Driver top-up poll failed: ' . get_class($e) . ' ' . $e->getMessage());
    }
}
$expired = $order['qr_expires_at'] && strtotime((string) $order['qr_expires_at']) <= time();
echo json_encode(['status' => $expired ? 'expired' : 'pending']);
