<?php
// PayMongo webhook receiver. Register this URL in Admin → PayMongo.
// Events handled: payment.paid, payment.failed, checkout_session.payment.paid
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/paymongo.php';
// payments.php needs these from auth.php without starting a session.
function uid(): int { return 0; }
function is_admin(): bool { return false; }
function current_user(): ?array { return null; }
require_once __DIR__ . '/../includes/payments.php';

function respond(int $code, string $msg): never
{
    http_response_code($code);
    header('Content-Type: text/plain');
    exit($msg);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, 'POST only');

$raw = file_get_contents('php://input') ?: '';
$event = json_decode($raw, true);
$attr = $event['data']['attributes'] ?? null;
if (!$attr) respond(400, 'Invalid payload');

$live = !empty($attr['livemode']);
$secret = setting_secret('paymongo_' . ($live ? 'live' : 'test') . '_webhook_secret');
if (!PayMongo::verifySignature($_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '', $raw, $secret, $live)) {
    respond(401, 'Invalid signature');
}

$type = $attr['type'] ?? '';
$obj = $attr['data'] ?? [];
$oa = $obj['attributes'] ?? [];

// Locate our payment: metadata first, then provider ids.
$payment = null;
$ref = $oa['metadata']['reference'] ?? $oa['reference_number'] ?? null;
if ($ref) $payment = q_one('SELECT * FROM payments WHERE reference = ?', [$ref]);
if (!$payment && !empty($oa['payment_intent_id'])) $payment = q_one('SELECT * FROM payments WHERE intent_id = ?', [$oa['payment_intent_id']]);
if (!$payment && str_starts_with((string) ($obj['id'] ?? ''), 'cs_')) $payment = q_one('SELECT * FROM payments WHERE checkout_id = ?', [$obj['id']]);
if (!$payment) respond(200, 'Ignored: unknown payment');

// Never trust an amount we did not ask for.
$amount = (int) ($oa['amount'] ?? $oa['payment_intent']['attributes']['amount'] ?? $payment['amount']);
if ($amount !== (int) $payment['amount']) {
    error_log("PayMongo webhook amount mismatch for {$payment['reference']}: $amount");
    respond(200, 'Ignored: amount mismatch');
}

switch ($type) {
    case 'payment.paid':
        payment_mark_paid((int) $payment['id'], $obj['id'] ?? null);
        break;
    case 'checkout_session.payment.paid':
        payment_mark_paid((int) $payment['id'], $oa['payments'][0]['id'] ?? null);
        break;
    case 'payment.failed':
        // The intent can still be retried, so only record the failure note.
        q('UPDATE payments SET admin_note = ? WHERE id = ? AND status = ?', [mb_substr('Last attempt failed: ' . ($oa['failed_message'] ?? 'unknown'), 0, 255), $payment['id'], 'pending']);
        break;
}
respond(200, 'OK');
