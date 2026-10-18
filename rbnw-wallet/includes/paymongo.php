<?php
/**
 * RBNW WALLET — PayMongo integration helpers (Cash In via QR Ph)
 *
 * Same 3-step Payment Intent flow used by ServiPay's topup_process.php:
 *   1. POST /v1/payment_intents   (payment_method_allowed: ['qrph'])
 *   2. POST /v1/payment_methods   (type: qrph)
 *   3. POST /v1/payment_intents/{id}/attach
 *      → PayMongo returns next_action.code.image_url (base64 PNG of the QR)
 *
 * NOTE: /v1/sources?type=qrph does NOT generate a scannable QR Ph code —
 *       use the Payment Intent flow above, not the older Sources API.
 */

require_once __DIR__ . '/../config.php';

/**
 * Fee breakdown for a Cash In request.
 * The wallet is always credited the full `amount` — fees ride on top of
 * what the payer sends via QR Ph and are never deducted from the wallet.
 */
function calc_cash_in_fees(float $amount): array
{
    $feePaymongo = round($amount * (CASH_IN_FEE_PAYMONGO_PCT / 100), 2);
    $feeRbnw     = round($amount * (CASH_IN_FEE_RBNW_PCT / 100), 2);
    $total       = round($amount + $feePaymongo + $feeRbnw, 2);

    return [
        'amount'       => $amount,
        'fee_paymongo' => $feePaymongo,
        'fee_rbnw'     => $feeRbnw,
        'total'        => $total,
    ];
}

/**
 * Create a PayMongo QR Ph Payment Intent for the given total amount and
 * return the base64 QR image, or a failure message.
 */
function pm_create_qrph_intent(float $totalAmount, string $reference, int $requestId): array
{
    $amountCents = (int) round($totalAmount * 100);

    // ── STEP 1: Create Payment Intent ──
    [$c1, $d1] = pm_post('payment_intents', [
        'data' => ['attributes' => [
            'amount'                 => $amountCents,
            'payment_method_allowed' => ['qrph'],
            'currency'               => 'PHP',
            'capture_type'           => 'automatic',
            'description'            => 'RBNW Wallet Cash In | Ref: ' . $reference,
            'statement_descriptor'   => 'RBNW Wallet',
            'metadata'               => [
                'ref'        => $reference,
                'request_id' => (string)$requestId,
            ],
        ]],
    ]);
    if ($c1 !== 200 || empty($d1['data']['id'])) {
        return ['ok' => false, 'message' => 'Step 1 failed: ' . pm_err($d1, $c1)];
    }
    $piId     = $d1['data']['id'];
    $piSecret = $d1['data']['attributes']['client_key'] ?? '';

    // ── STEP 2: Create Payment Method (qrph) ──
    [$c2, $d2] = pm_post('payment_methods', [
        'data' => ['attributes' => ['type' => 'qrph']],
    ]);
    if ($c2 !== 200 || empty($d2['data']['id'])) {
        return ['ok' => false, 'message' => 'Step 2 failed: ' . pm_err($d2, $c2) . ' — make sure QR Ph is enabled in the PayMongo Dashboard under Payment Methods.'];
    }
    $pmId = $d2['data']['id'];

    // ── STEP 3: Attach Payment Method → get QR image ──
    [$c3, $d3] = pm_post('payment_intents/' . $piId . '/attach', [
        'data' => ['attributes' => [
            'payment_method' => $pmId,
            'client_key'     => $piSecret,
        ]],
    ]);
    if ($c3 !== 200) {
        return ['ok' => false, 'message' => 'Step 3 failed: ' . pm_err($d3, $c3)];
    }

    $nextAction = $d3['data']['attributes']['next_action'] ?? [];

    // PayMongo returns: next_action.code.image_url = "data:image/png;base64,..."
    $qrImage = $nextAction['code']['image_url'] ?? null;
    if (!$qrImage && !empty($nextAction['display'])) {
        foreach (['qr_image', 'qr_string', 'qr_code', 'qr', 'data'] as $f) {
            if (!empty($nextAction['display'][$f])) { $qrImage = $nextAction['display'][$f]; break; }
        }
    }

    if (!$qrImage) {
        return ['ok' => false, 'message' => 'PayMongo did not return a QR image. Confirm QR Ph is activated for this PayMongo account.'];
    }

    return ['ok' => true, 'pi_id' => $piId, 'qr_image' => $qrImage];
}

/**
 * Look up a Payment Intent's current status directly from PayMongo.
 * Statuses: awaiting_payment_method, processing, succeeded, awaiting_capture, payment_error
 */
function pm_get_intent_status(string $piId): ?string
{
    [$code, $data] = pm_get('payment_intents/' . $piId);
    if ($code !== 200) return null;
    return $data['data']['attributes']['status'] ?? null;
}

/**
 * Low-level PayMongo API POST call. Returns [http_status_code, decoded_response].
 */
function pm_post(string $endpoint, array $payload): array
{
    $ch = curl_init('https://api.paymongo.com/v1/' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false, // some Hostinger shared-hosting setups need this
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false) {
        return [0, ['errors' => [['detail' => 'cURL error: ' . $err]]]];
    }
    return [$code, json_decode($res, true) ?: []];
}

/**
 * Low-level PayMongo API GET call (used for status polling).
 */
function pm_get(string $endpoint): array
{
    $ch = curl_init('https://api.paymongo.com/v1/' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        ],
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$code, json_decode((string)$res, true) ?: []];
}

function pm_err(array $decoded, int $httpCode): string
{
    return $decoded['errors'][0]['detail'] ?? $decoded['errors'][0]['code'] ?? ('PayMongo HTTP ' . $httpCode);
}
