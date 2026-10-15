<?php
// ============================================================
// TRILINK PAY – webhook.php
// PayMongo webhook endpoint. Register in the PayMongo dashboard:
//   URL:    https://app.trilink.site/trilink-pay/webhook.php
//   Events: payment.paid, payment.failed, qrph.expired
// ============================================================
require_once __DIR__ . '/TopupService.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

if (!Topup::verifySignature($raw, $sig)) {
    error_log('[TriLink Topup] Webhook rejected: bad signature');
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid signature']);
    exit;
}

$event = json_decode($raw, true);
if (!is_array($event)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid body']);
    exit;
}

try {
    $result = Topup::handleWebhook($event);
    error_log('[TriLink Topup] Webhook ' . $result);
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    // 500 makes PayMongo retry the delivery later.
    error_log('[TriLink Topup] Webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
