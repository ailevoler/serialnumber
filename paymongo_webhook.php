<?php
/**
 * PayMongo webhook endpoint. Register this URL in Admin → PayMongo Settings.
 * Handles: checkout_session.payment.paid
 */
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json');
$payload = (string)file_get_contents('php://input');

if (!paymongo_verify_signature($payload, $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '')) {
    http_response_code(401);
    exit(json_encode(['error' => 'invalid signature']));
}

$event = json_decode($payload, true);
$type = $event['data']['attributes']['type'] ?? '';
$session = $event['data']['attributes']['data'] ?? [];

if ($type === 'checkout_session.payment.paid') {
    $checkoutId = (string)($session['id'] ?? '');
    $ref = (string)($session['attributes']['reference_number'] ?? '');
    $pay = q_row('SELECT id FROM payments WHERE checkout_id = ? OR (reference = ? AND ? <> \'\')', [$checkoutId, $ref, $ref]);
    if ($pay) {
        fulfill_payment((int)$pay['id']);
    }
}

http_response_code(200);
echo json_encode(['received' => true]);
