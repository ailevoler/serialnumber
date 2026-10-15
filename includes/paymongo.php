<?php
declare(strict_types=1);

/**
 * PayMongo integration (Checkout Sessions + webhooks).
 * Docs: https://developers.paymongo.com/reference/checkout-session-resource
 *
 * Flow: create a `payments` row → create a PayMongo checkout session → redirect the member →
 * on success (return page check or `checkout_session.payment.paid` webhook) → fulfill_payment().
 */

const PAYMONGO_API = 'https://api.paymongo.com/v1';

function paymongo_method_options(): array
{
    return [
        'gcash'    => 'GCash',
        'paymaya'  => 'Maya',
        'card'     => 'Credit / Debit Card',
        'grab_pay' => 'GrabPay',
        'qrph'     => 'QR Ph',
        'dob'      => 'Online Banking (BPI / UBP)',
        'billease' => 'BillEase',
    ];
}

function paymongo_enabled(): bool
{
    return setting('paymongo_enabled', '0') === '1' && trim((string)setting('paymongo_secret_key', '')) !== '';
}

function paymongo_request(string $method, string $path, ?array $body = null): array
{
    $secret = trim((string)setting('paymongo_secret_key', ''));
    if ($secret === '') {
        throw new RuntimeException('PayMongo secret key is not configured.');
    }
    $ch = curl_init(PAYMONGO_API . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($secret . ':'),
        ],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $res = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) {
        throw new RuntimeException('Could not reach PayMongo: ' . $err);
    }
    $json = json_decode((string)$res, true) ?: [];
    if ($status >= 400) {
        $detail = $json['errors'][0]['detail'] ?? ('HTTP ' . $status);
        throw new RuntimeException('PayMongo error: ' . $detail);
    }
    return $json;
}

/**
 * Create a pending payment + PayMongo checkout session. Returns the checkout URL to redirect to.
 * $items: list of ['name' => string, 'amount' => float (pesos), 'quantity' => int]
 */
function paymongo_start_checkout(int $userId, string $purpose, array $items, string $description, ?int $orderId = null, ?int $packageId = null, int $qty = 0): string
{
    $amount = 0.0;
    foreach ($items as $it) {
        $amount += $it['amount'] * $it['quantity'];
    }
    if ($amount < 20) {
        throw new RuntimeException('PayMongo requires a minimum payment of ₱20.00.');
    }
    $ref = 'PM' . date('ymdHis') . random_string(4, '0123456789');
    q('INSERT INTO payments (reference, user_id, purpose, order_id, package_id, qty, amount) VALUES (?,?,?,?,?,?,?)',
        [$ref, $userId, $purpose, $orderId, $packageId, $qty, round($amount, 2)]);
    $paymentId = (int)db()->lastInsertId();

    $methods = setting_list('paymongo_methods') ?: ['gcash', 'paymaya', 'card'];
    $user = q_row('SELECT first_name, last_name, email, mobile FROM users WHERE id = ?', [$userId]);
    $res = paymongo_request('POST', '/checkout_sessions', ['data' => ['attributes' => [
        'line_items' => array_map(fn($it) => [
            'currency' => 'PHP',
            'amount'   => (int)round($it['amount'] * 100),
            'name'     => mb_substr($it['name'], 0, 255),
            'quantity' => (int)$it['quantity'],
        ], $items),
        'payment_method_types' => array_values($methods),
        'description'          => $description,
        'reference_number'     => $ref,
        'send_email_receipt'   => true,
        'show_description'     => true,
        'show_line_items'      => true,
        'billing'              => array_filter([
            'name'  => full_name($user),
            'email' => $user['email'],
            'phone' => $user['mobile'] ?: null,
        ]),
        'success_url' => absolute_url('member/payment_return.php?ref=' . $ref . '&result=success'),
        'cancel_url'  => absolute_url('member/payment_return.php?ref=' . $ref . '&result=cancel'),
    ]]]);

    $checkoutId = $res['data']['id'] ?? '';
    $url = $res['data']['attributes']['checkout_url'] ?? '';
    if (!$checkoutId || !$url) {
        throw new RuntimeException('PayMongo did not return a checkout URL.');
    }
    q('UPDATE payments SET checkout_id = ?, checkout_url = ? WHERE id = ?', [$checkoutId, $url, $paymentId]);
    return $url;
}

/** Ask PayMongo whether a checkout session has been paid. */
function paymongo_session_paid(string $checkoutId): bool
{
    $res = paymongo_request('GET', '/checkout_sessions/' . rawurlencode($checkoutId));
    $attr = $res['data']['attributes'] ?? [];
    foreach ($attr['payments'] ?? [] as $p) {
        if (($p['attributes']['status'] ?? '') === 'paid') {
            return true;
        }
    }
    return ($attr['payment_intent']['attributes']['status'] ?? '') === 'succeeded';
}

/** Mark a payment as paid and deliver what was bought. Idempotent; returns true if it fulfilled now. */
function fulfill_payment(int $paymentId): bool
{
    return tx(function () use ($paymentId) {
        $pay = q_row('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
        if (!$pay || $pay['status'] !== 'pending') {
            return false;
        }
        q("UPDATE payments SET status = 'paid', paid_at = NOW() WHERE id = ?", [$paymentId]);
        if ($pay['purpose'] === 'order' && $pay['order_id']) {
            q("UPDATE orders SET status = 'paid', paid_at = NOW(), payment_ref = ? WHERE id = ? AND status = 'pending'", [$pay['reference'], $pay['order_id']]);
            process_order_commission((int)$pay['order_id']);
        } elseif ($pay['purpose'] === 'codes' && $pay['package_id']) {
            generate_codes((int)$pay['package_id'], (int)$pay['qty'], 'paid', (int)$pay['user_id']);
            erp_post_code_sale('paymongo', (float)$pay['amount'], "Code sale {$pay['reference']} (PayMongo)", (int)$pay['user_id'], 'payment', (int)$pay['id']);
        }
        log_activity((int)$pay['user_id'], "PayMongo payment {$pay['reference']} paid (" . $pay['amount'] . ')');
        return true;
    });
}

/** Verify the Paymongo-Signature header: t=<ts>,te=<test sig>,li=<live sig> */
function paymongo_verify_signature(string $payload, string $header): bool
{
    $secret = trim((string)setting('paymongo_webhook_secret', ''));
    if ($secret === '' || $header === '') {
        return false;
    }
    $parts = [];
    foreach (explode(',', $header) as $kv) {
        [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
        $parts[$k] = $v;
    }
    if (empty($parts['t'])) {
        return false;
    }
    $expected = hash_hmac('sha256', $parts['t'] . '.' . $payload, $secret);
    $given = setting('paymongo_mode', 'test') === 'live' ? ($parts['li'] ?? '') : ($parts['te'] ?? '');
    return $given !== '' && hash_equals($expected, $given);
}
