<?php
/**
 * RBNW WALLET — PayMongo Webhook (Cash In via QR Ph)
 *
 * Register in the PayMongo Dashboard:
 *   URL:    https://rbnw.surgemobileaccess.com/topup-webhook.php
 *   Events: payment.paid, payment.failed
 *
 * This is a safety net alongside the client-side status poll in
 * api/top-up.php — if the user closes the app right after paying, this
 * still credits the wallet server-to-server. credit_cash_in() is
 * idempotent, so it's safe for both to fire for the same payment.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/paymongo.php';

header('Content-Type: application/json');

$rawBody   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

// Verify signature
if ($sigHeader && PAYMONGO_WEBHOOK_SECRET) {
    $parts = [];
    foreach (explode(',', $sigHeader) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) === 2) $parts[$kv[0]] = $kv[1];
    }
    $timestamp = $parts['t']  ?? '';
    $signature = $parts['li'] ?? $parts['te'] ?? ''; // li=live, te=test
    $expected  = hash_hmac('sha256', $timestamp . '.' . $rawBody, PAYMONGO_WEBHOOK_SECRET);

    if ($timestamp && $signature && !hash_equals($expected, $signature)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
}

$event = json_decode($rawBody, true);
$type  = $event['data']['attributes']['type'] ?? '';
$data  = $event['data']['attributes']['data'] ?? [];

// ── payment.paid — triggered when a QR Ph Payment Intent succeeds ──
if ($type === 'payment.paid') {
    $piId = $data['attributes']['payment_intent_id'] ?? null;
    $ref  = $data['attributes']['metadata']['ref'] ?? null;

    $req = null;
    if ($piId) {
        $stmt = db()->prepare("SELECT * FROM rbnw_cash_in_requests WHERE paymongo_payment_intent_id = ? AND status = 'pending' LIMIT 1");
        $stmt->execute([$piId]);
        $req = $stmt->fetch();
    }
    if (!$req && $ref) {
        $stmt = db()->prepare("SELECT * FROM rbnw_cash_in_requests WHERE reference_no = ? AND status = 'pending' LIMIT 1");
        $stmt->execute([$ref]);
        $req = $stmt->fetch();
    }

    if ($req) {
        try {
            credit_cash_in($req);
        } catch (Throwable $e) {
            error_log('RBNW cash-in webhook credit error: ' . $e->getMessage());
        }
    }
}

// ── payment.failed ──
if ($type === 'payment.failed') {
    $piId = $data['attributes']['payment_intent_id'] ?? null;
    if ($piId) {
        db()->prepare("UPDATE rbnw_cash_in_requests SET status = 'failed' WHERE paymongo_payment_intent_id = ? AND status = 'pending'")
            ->execute([$piId]);
    }
}

http_response_code(200);
echo json_encode(['received' => true]);
