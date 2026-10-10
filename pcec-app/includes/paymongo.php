<?php
/**
 * Minimal PayMongo REST client (https://developers.paymongo.com).
 * Uses the secret key server-side only. Amounts are in centavos.
 */
class PayMongoException extends RuntimeException {}

class PayMongo
{
    public function __construct(private string $secretKey, private string $base = PAYMONGO_API_BASE) {}

    public static function fromSettings(): ?self
    {
        $mode = setting('paymongo_mode', 'test') === 'live' ? 'live' : 'test';
        $key = setting_secret("paymongo_{$mode}_secret");
        return $key === '' ? null : new self($key);
    }

    public static function isLive(): bool
    {
        return setting('paymongo_mode', 'test') === 'live';
    }

    public function request(string $method, string $path, ?array $attributes = null): array
    {
        $ch = curl_init($this->base . $path);
        $headers = ['Accept: application/json', 'Authorization: Basic ' . base64_encode($this->secretKey . ':')];
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10];
        if ($attributes !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode(['data' => ['attributes' => $attributes]]);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new PayMongoException('Could not reach PayMongo: ' . $err);
        }
        $json = json_decode($body, true) ?: [];
        if ($code >= 400) {
            $detail = $json['errors'][0]['detail'] ?? ('HTTP ' . $code);
            throw new PayMongoException('PayMongo: ' . $detail, $code);
        }
        return $json;
    }

    /** Create a QR Ph payment and return [intent_id, qr data URI, expires_at timestamp]. */
    public function createQrph(int $amount, string $description, array $billing, array $metadata = []): array
    {
        $intent = $this->request('POST', '/payment_intents', [
            'amount' => $amount,
            'currency' => 'PHP',
            'payment_method_allowed' => ['qrph'],
            'description' => $description,
            'statement_descriptor' => 'PCEC',
            'metadata' => array_map('strval', $metadata),
        ]);
        $intentId = $intent['data']['id'];
        $pm = $this->request('POST', '/payment_methods', ['type' => 'qrph', 'billing' => $billing, 'metadata' => array_map('strval', $metadata)]);
        $attached = $this->request('POST', "/payment_intents/$intentId/attach", ['payment_method' => $pm['data']['id']]);
        $next = $attached['data']['attributes']['next_action'] ?? [];
        $image = $next['code']['image_url'] ?? null;
        if (!$image) {
            throw new PayMongoException('PayMongo did not return a QR code.');
        }
        if (!str_starts_with($image, 'data:') && !str_starts_with($image, 'http')) {
            $image = 'data:image/png;base64,' . $image;
        }
        return [$intentId, $image, time() + 30 * 60]; // QR Ph codes expire after 30 minutes by default
    }

    /** Hosted checkout for card / e-wallet / online banking. Returns [checkout_id, checkout_url]. */
    public function createCheckout(int $amount, string $name, string $description, array $methods, string $successUrl, string $cancelUrl, string $reference, array $billing, array $metadata = []): array
    {
        $res = $this->request('POST', '/checkout_sessions', [
            'line_items' => [['amount' => $amount, 'currency' => 'PHP', 'name' => $name, 'quantity' => 1]],
            'payment_method_types' => array_values($methods),
            'description' => $description,
            'reference_number' => $reference,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'billing' => $billing,
            'send_email_receipt' => true,
            'show_description' => true,
            'show_line_items' => true,
            'metadata' => array_map('strval', $metadata),
        ]);
        return [$res['data']['id'], $res['data']['attributes']['checkout_url']];
    }

    public function getIntent(string $id): array
    {
        return $this->request('GET', "/payment_intents/$id")['data'];
    }

    public function getCheckout(string $id): array
    {
        return $this->request('GET', "/checkout_sessions/$id")['data'];
    }

    public function listWebhooks(): array
    {
        return $this->request('GET', '/webhooks')['data'] ?? [];
    }

    public function createWebhook(string $url, array $events): array
    {
        return $this->request('POST', '/webhooks', ['url' => $url, 'events' => $events])['data'];
    }

    /** Verify the Paymongo-Signature header: "t=<ts>,te=<test sig>,li=<live sig>". */
    public static function verifySignature(string $header, string $payload, string $secret, bool $livemode, int $tolerance = 600): bool
    {
        $parts = [];
        foreach (explode(',', $header) as $kv) {
            [$k, $v] = array_map('trim', explode('=', $kv, 2) + ['', '']);
            $parts[$k] = $v;
        }
        $sig = $livemode ? ($parts['li'] ?? '') : ($parts['te'] ?? '');
        if (empty($parts['t']) || $sig === '' || $secret === '') return false;
        if ($tolerance && abs(time() - (int) $parts['t']) > $tolerance) return false;
        return hash_equals(hash_hmac('sha256', $parts['t'] . '.' . $payload, $secret), $sig);
    }
}
