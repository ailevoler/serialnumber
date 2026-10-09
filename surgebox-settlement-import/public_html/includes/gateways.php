<?php
declare(strict_types=1);

/**
 * SurgeBox V5 - Client accounts + Payment Third-Party integrations.
 *
 *  - sb_payment_providers : catalog (PayMongo, Nationlink, OptekPay/Pay8, SwiftPay)
 *  - sb_client_gateways   : per-client credentials (API keys + webhook) and fees
 *  - Client approval gate : credentials can only be configured once the client
 *                           is Approved (documents approved) or
 *                           "Approved - Documents to Follow".
 *
 * Secrets are encrypted at rest with AES-256-GCM using APP_KEY from .env.
 */

class GatewayException extends RuntimeException {}

// ---------------------------------------------------------------------
// Encryption helpers
// ---------------------------------------------------------------------
function sb_app_key(): string
{
    $k = (string) env('APP_KEY', '');
    if ($k === '') {
        throw new GatewayException('APP_KEY is missing in .env - it is required to encrypt client API credentials. Add a long random APP_KEY (never change it once credentials are saved).');
    }
    return hash('sha256', $k, true);
}

function sb_encrypt_array(array $data): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt(json_encode($data, JSON_UNESCAPED_SLASHES), 'aes-256-gcm', sb_app_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new GatewayException('Could not encrypt credentials.');
    }
    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

function sb_decrypt_array(?string $blob): array
{
    if (!$blob) {
        return [];
    }
    if (!str_starts_with($blob, 'v1:')) {
        return [];
    }
    $raw = base64_decode(substr($blob, 3), true);
    if ($raw === false || strlen($raw) < 29) {
        return [];
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', sb_app_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) {
        throw new GatewayException('Stored credentials could not be decrypted - APP_KEY in .env was changed. Re-enter this client\'s credentials.');
    }
    $d = json_decode($plain, true);
    return is_array($d) ? $d : [];
}

function sb_mask_secret(?string $v): string
{
    $v = (string) $v;
    if ($v === '') {
        return '';
    }
    $len = strlen($v);
    if ($len <= 8) {
        return str_repeat('•', $len);
    }
    $prefix = '';
    if (preg_match('/^((?:sk|pk|whsk)_(?:test|live)_|whsk_)/', $v, $m)) {
        $prefix = $m[1];
    }
    return $prefix . '••••••••' . substr($v, -4);
}

// ---------------------------------------------------------------------
// Provider catalog + credential field schema
// ---------------------------------------------------------------------
/**
 * Field schema per provider. 'secret' => stored encrypted and never sent back
 * to the browser in plain text (only masked).
 */
function sb_provider_fields(string $code): array
{
    $map = [
        'paymongo' => [
            ['key' => 'public_key', 'label' => 'Public Key', 'placeholder' => 'pk_live_... / pk_test_...', 'secret' => false, 'required' => false],
            ['key' => 'secret_key', 'label' => 'Secret Key', 'placeholder' => 'sk_live_... / sk_test_...', 'secret' => true, 'required' => true],
            ['key' => 'webhook_secret', 'label' => 'Webhook Signing Secret', 'placeholder' => 'whsk_... (filled automatically by "Register Webhook")', 'secret' => true, 'required' => false],
            ['key' => 'qr_display_name', 'label' => 'Name shown in GCash/Maya (max 22)', 'placeholder' => 'e.g. PCEC (blank = Client Code)', 'secret' => false, 'required' => false],
            ['key' => 'qr_mobile_number', 'label' => 'QR Ph Notification Mobile (optional)', 'placeholder' => '+639XXXXXXXXX', 'secret' => false, 'required' => false],
        ],
        'nationlink' => [
            // V5.15: all optional - Nationlink is added so Admin can attach Nationlink-issued Static QR Ph.
            ['key' => 'institution_id', 'label' => 'Institution ID (optional)', 'placeholder' => '10-character InstitutionID', 'secret' => false, 'required' => false],
            ['key' => 'member_id', 'label' => 'Default MemberID (optional)', 'placeholder' => 'e.g. A10000 - payments to it are recorded for this client', 'secret' => false, 'required' => false],
            ['key' => 'endpoint', 'label' => 'API Endpoint (optional)', 'placeholder' => 'https://api.nationlink.ph:8686/Service.svc/json/NationlinkRequest', 'secret' => false, 'required' => false],
            ['key' => 'bridge_key', 'label' => 'Bridge / API Key (optional)', 'placeholder' => '', 'secret' => true, 'required' => false],
            ['key' => 'webhook_secret', 'label' => 'Webhook Secret-Key (optional)', 'placeholder' => 'if set, Nationlink must send it in the Secret-Key header', 'secret' => true, 'required' => false],
        ],
        'pay8' => [
            ['key' => 'partner_id', 'label' => 'Partner ID (Unique ID)', 'placeholder' => '', 'secret' => false, 'required' => true],
            ['key' => 'md_key', 'label' => 'MD / Secret Key', 'placeholder' => '', 'secret' => true, 'required' => true],
            ['key' => 'base_url', 'label' => 'Middleware Base URL', 'placeholder' => 'https://prod-middleware.surgeinnovate.com', 'secret' => false, 'required' => false],
        ],
        'swiftpay' => [
            ['key' => 'merchant_id', 'label' => 'Merchant ID', 'placeholder' => '', 'secret' => false, 'required' => true],
            ['key' => 'api_key', 'label' => 'API Key', 'placeholder' => '', 'secret' => true, 'required' => true],
            ['key' => 'api_secret', 'label' => 'API Secret', 'placeholder' => '', 'secret' => true, 'required' => false],
            ['key' => 'base_url', 'label' => 'API Base URL', 'placeholder' => '', 'secret' => false, 'required' => false],
            ['key' => 'webhook_secret', 'label' => 'Webhook Secret', 'placeholder' => '', 'secret' => true, 'required' => false],
        ],
    ];
    return $map[$code] ?? [];
}

function sb_providers(bool $enabledOnly = false): array
{
    $sql = 'SELECT * FROM sb_payment_providers' . ($enabledOnly ? ' WHERE is_enabled = 1' : '') . ' ORDER BY is_priority DESC, sort_order ASC';
    return db()->query($sql)->fetchAll();
}

function sb_provider(string $code): ?array
{
    $s = db()->prepare('SELECT * FROM sb_payment_providers WHERE code = ?');
    $s->execute([$code]);
    return $s->fetch() ?: null;
}

// ---------------------------------------------------------------------
// Client approval
// ---------------------------------------------------------------------
function sb_org_row(int $orgId): ?array
{
    $s = db()->prepare('SELECT * FROM sb_organization WHERE id = ?');
    $s->execute([$orgId]);
    return $s->fetch() ?: null;
}

/** Summary of merchant-document requirements for an organization. */
function sb_org_document_summary(int $orgId): array
{
    $s = db()->prepare("SELECT COUNT(*) total,
        SUM(CASE WHEN (file_path IS NOT NULL AND file_path <> '') OR status IN ('Submitted','Verified') THEN 1 ELSE 0 END) submitted,
        SUM(CASE WHEN status = 'Verified' THEN 1 ELSE 0 END) verified
        FROM sb_org_requirements WHERE organization_id = ? AND category = 'Merchant Document'");
    $s->execute([$orgId]);
    $r = $s->fetch() ?: [];
    $total = (int) ($r['total'] ?? 0);
    $submitted = (int) ($r['submitted'] ?? 0);
    return [
        'total' => $total,
        'submitted' => $submitted,
        'verified' => (int) ($r['verified'] ?? 0),
        'complete' => $total > 0 && $submitted >= $total,
    ];
}

function sb_org_is_approved(?array $org): bool
{
    return $org && in_array($org['onboarding_status'] ?? 'Pending', ['Approved', 'Approved - Documents to Follow'], true);
}

/**
 * Approve a client. $docsToFollow=false requires every Merchant Document to be
 * submitted; $docsToFollow=true lets Admin approve now with documents to follow.
 */
function sb_approve_org(int $orgId, int $adminId, bool $docsToFollow, string $notes = ''): string
{
    $org = sb_org_row($orgId);
    if (!$org) {
        throw new RuntimeException('Client not found.');
    }
    sb_seed_org_requirements($orgId);
    $docs = sb_org_document_summary($orgId);
    if (!$docsToFollow && !$docs['complete']) {
        throw new RuntimeException("Only {$docs['submitted']} of {$docs['total']} merchant documents have been submitted. Use \"Approve - Documents to Follow\" to approve without complete documents.");
    }
    $status = ($docsToFollow && !$docs['complete']) ? 'Approved - Documents to Follow' : 'Approved';
    db()->prepare('UPDATE sb_organization SET onboarding_status = ?, approved_at = NOW(), approved_by = ?, approval_notes = ? WHERE id = ?')
        ->execute([$status, $adminId ?: null, $notes !== '' ? mb_substr($notes, 0, 500) : null, $orgId]);
    db()->prepare("UPDATE sb_pre_registrations SET status = CASE WHEN status IN ('Pre-Registered','For Verification') THEN 'Approved' ELSE status END, documents_to_follow = ?, reviewed_by = COALESCE(reviewed_by, ?), reviewed_at = COALESCE(reviewed_at, NOW()) WHERE organization_id = ?")
        ->execute([$status === 'Approved - Documents to Follow' ? 1 : 0, $adminId ?: null, $orgId]);
    return $status;
}

/** When documents arrive later, flip "Documents to Follow" to fully Approved. */
function sb_org_refresh_docs_status(int $orgId): void
{
    $org = sb_org_row($orgId);
    if ($org && $org['onboarding_status'] === 'Approved - Documents to Follow' && sb_org_document_summary($orgId)['complete']) {
        db()->prepare("UPDATE sb_organization SET onboarding_status = 'Approved' WHERE id = ?")->execute([$orgId]);
        db()->prepare('UPDATE sb_pre_registrations SET documents_to_follow = 0 WHERE organization_id = ?')->execute([$orgId]);
    }
}

// ---------------------------------------------------------------------
// Client gateways
// ---------------------------------------------------------------------
function sb_gateway_by_id(int $id): ?array
{
    $s = db()->prepare('SELECT g.*, p.display_name provider_name, p.integration_status, p.supports_static_qr, p.supports_dynamic_qr, p.is_enabled provider_enabled FROM sb_client_gateways g JOIN sb_payment_providers p ON p.code = g.provider_code WHERE g.id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function sb_gateway_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
        return null;
    }
    $s = db()->prepare('SELECT id FROM sb_client_gateways WHERE webhook_token = ?');
    $s->execute([$token]);
    $id = $s->fetchColumn();
    return $id ? sb_gateway_by_id((int) $id) : null;
}

function sb_org_gateways(int $orgId): array
{
    $s = db()->prepare('SELECT g.*, p.display_name provider_name, p.integration_status, p.supports_static_qr, p.supports_dynamic_qr, p.is_enabled provider_enabled FROM sb_client_gateways g JOIN sb_payment_providers p ON p.code = g.provider_code WHERE g.organization_id = ? ORDER BY g.is_default DESC, p.is_priority DESC, p.sort_order');
    $s->execute([$orgId]);
    return $s->fetchAll();
}

/**
 * The gateway a client should use for QR generation: Active, provider enabled
 * and Live, supports the QR kind; default first, then priority provider.
 */
function sb_org_active_qr_gateway(int $orgId, string $kind = 'static'): ?array
{
    foreach (sb_org_gateways($orgId) as $g) {
        if ($g['status'] !== 'Active' || !(int) $g['provider_enabled'] || $g['integration_status'] !== 'Live') {
            continue;
        }
        if ($kind === 'static' && !(int) $g['supports_static_qr']) {
            continue;
        }
        if ($kind === 'dynamic' && !(int) $g['supports_dynamic_qr']) {
            continue;
        }
        return $g;
    }
    return null;
}

function sb_gateway_public(array $g): array
{
    $d = json_decode((string) ($g['public_config'] ?? ''), true);
    return is_array($d) ? $d : [];
}

function sb_gateway_secrets(array $g): array
{
    return sb_decrypt_array($g['secret_config_enc'] ?? null);
}

function sb_gateway_webhook_url(array $g): string
{
    $base = rtrim(ADMIN_URL !== '' ? ADMIN_URL : APP_URL, '/');
    $script = match ($g['provider_code']) {
        'paymongo' => '/api/paymongo_webhook.php',
        default => '/api/gateway_webhook.php',
    };
    return $base . $script . '?t=' . $g['webhook_token'];
}

/** Browser-safe representation (secrets masked). */
function sb_gateway_for_browser(array $g): array
{
    $secrets = [];
    try {
        $secrets = sb_gateway_secrets($g);
    } catch (Throwable $e) {
        $secrets = ['__error' => $e->getMessage()];
    }
    $masked = [];
    foreach (sb_provider_fields($g['provider_code']) as $f) {
        if ($f['secret']) {
            $masked[$f['key']] = sb_mask_secret($secrets[$f['key']] ?? '');
        }
    }
    $out = $g;
    unset($out['secret_config_enc']);
    $out['public_config'] = sb_gateway_public($g);
    $out['secrets_masked'] = $masked;
    $out['secrets_error'] = $secrets['__error'] ?? null;
    $out['webhook_url'] = sb_gateway_webhook_url($g);
    $out['fee_label'] = sb_fee_label($g);
    return $out;
}

// ---------------------------------------------------------------------
// Fees / MDR
// ---------------------------------------------------------------------
/**
 * V5.25: amount brackets for fee_type "Bracket" - each bracket has its own Fixed fee and MDR %.
 * Stored as JSON: [{"from":0.01,"to":100,"fixed":10,"percent":0}, {"from":100.01,"to":null,"fixed":0,"percent":1.5}]
 * ("to" null = and above). Sorted by "from".
 */
function sb_fee_brackets(array $g): array
{
    $list = json_decode((string) ($g['fee_brackets'] ?? ''), true);
    if (!is_array($list)) {
        return [];
    }
    $out = [];
    foreach ($list as $b) {
        if (!is_array($b) || !isset($b['from'])) {
            continue;
        }
        $out[] = [
            'from' => round((float) $b['from'], 2),
            'to' => isset($b['to']) && $b['to'] !== '' && $b['to'] !== null ? round((float) $b['to'], 2) : null,
            'fixed' => round(max(0.0, (float) ($b['fixed'] ?? 0)), 2),
            'percent' => round(max(0.0, (float) ($b['percent'] ?? 0)), 3),
        ];
    }
    usort($out, fn($a, $b) => $a['from'] <=> $b['from']);
    return $out;
}

/** The bracket an amount falls in (null when no bracket covers it). */
function sb_fee_bracket_for(array $g, float $amount): ?array
{
    $amount = round($amount, 2);
    foreach (sb_fee_brackets($g) as $b) {
        if ($amount >= $b['from'] && ($b['to'] === null || $amount <= $b['to'])) {
            return $b;
        }
    }
    return null;
}

/**
 * Validate brackets posted by Admin. Returns [brackets, error|null].
 * Rules: at least one row; from >= 0; to empty (and above) or >= from; no overlaps; MDR % <= 100.
 */
function sb_fee_brackets_validate($rows): array
{
    if (!is_array($rows) || !$rows) {
        return [[], 'Add at least one amount bracket.'];
    }
    $out = [];
    foreach (array_values($rows) as $i => $r) {
        $n = $i + 1;
        $from = trim((string) ($r['from'] ?? ''));
        $to = trim((string) ($r['to'] ?? ''));
        if ($from === '' || !is_numeric($from) || (float) $from < 0) {
            return [[], "Bracket {$n}: enter a valid From amount."];
        }
        if ($to !== '' && (!is_numeric($to) || (float) $to < (float) $from)) {
            return [[], "Bracket {$n}: To amount must be empty (and above) or not lower than From."];
        }
        $pct = (float) ($r['percent'] ?? 0);
        if ($pct < 0 || $pct > 100) {
            return [[], "Bracket {$n}: MDR % must be between 0 and 100."];
        }
        $out[] = [
            'from' => round((float) $from, 2),
            'to' => $to === '' ? null : round((float) $to, 2),
            'fixed' => round(max(0.0, (float) ($r['fixed'] ?? 0)), 2),
            'percent' => round($pct, 3),
        ];
    }
    usort($out, fn($a, $b) => $a['from'] <=> $b['from']);
    for ($i = 1, $c = count($out); $i < $c; $i++) {
        $prevTo = $out[$i - 1]['to'];
        if ($prevTo === null || $out[$i]['from'] <= $prevTo) {
            return [[], 'Amount brackets overlap (' . number_format($out[$i - 1]['from'], 2) . ' and ' . number_format($out[$i]['from'], 2) . '). Each amount must fall in only one bracket.'];
        }
    }
    return [$out, null];
}

function sb_compute_client_fee(array $g, float $amount): float
{
    $bracket = ($g['fee_type'] ?? '') === 'Bracket' ? sb_fee_bracket_for($g, $amount) : null;
    $fee = match ($g['fee_type'] ?? 'None') {
        'Fixed' => (float) $g['fee_fixed'],
        'Percentage' => $amount * (float) $g['fee_percent'] / 100,
        'Fixed + Percentage' => (float) $g['fee_fixed'] + $amount * (float) $g['fee_percent'] / 100,
        // V5.25: Fixed + MDR % of the bracket the amount falls in (no bracket = no fee)
        'Bracket' => $bracket ? $bracket['fixed'] + $amount * $bracket['percent'] / 100 : 0.0,
        default => 0.0,
    };
    if ($g['fee_min'] !== null && $g['fee_min'] !== '' && $fee < (float) $g['fee_min'] && ($g['fee_type'] ?? 'None') !== 'None') {
        $fee = (float) $g['fee_min'];
    }
    if ($g['fee_max'] !== null && $g['fee_max'] !== '' && (float) $g['fee_max'] > 0 && $fee > (float) $g['fee_max']) {
        $fee = (float) $g['fee_max'];
    }
    $fee = round(max(0.0, $fee), 2);
    // In "deduct" mode the fee can never exceed the payment itself.
    if (($g['fee_mode'] ?? 'deduct') === 'deduct' && $fee > $amount) {
        $fee = round($amount, 2);
    }
    return $fee;
}

function sb_fee_label(array $g): string
{
    $pct = rtrim(rtrim(number_format((float) $g['fee_percent'], 3), '0'), '.');
    if (($g['fee_type'] ?? '') === 'Bracket') {
        $parts = [];
        foreach (sb_fee_brackets($g) as $b) {
            $bp = rtrim(rtrim(number_format($b['percent'], 3), '0'), '.');
            $rate = array_filter([$b['fixed'] > 0 ? '₱' . number_format($b['fixed'], 2) : null, $b['percent'] > 0 ? $bp . '%' : null]);
            $parts[] = '₱' . number_format($b['from'], 2) . ($b['to'] === null ? '+' : '–₱' . number_format($b['to'], 2)) . ': ' . ($rate ? implode(' + ', $rate) : 'no fee');
        }
        $pct = '';
    }
    $base = match ($g['fee_type'] ?? 'None') {
        'Bracket' => 'Bracket (' . ($parts ? implode('; ', $parts) : 'no brackets set') . ')',
        'Fixed' => '₱' . number_format((float) $g['fee_fixed'], 2) . ' fixed',
        'Percentage' => $pct . '%',
        'Fixed + Percentage' => '₱' . number_format((float) $g['fee_fixed'], 2) . ' + ' . $pct . '%',
        default => 'No fee',
    };
    $caps = [];
    if ($g['fee_min'] !== null && (float) $g['fee_min'] > 0) {
        $caps[] = 'min ₱' . number_format((float) $g['fee_min'], 2);
    }
    if ($g['fee_max'] !== null && (float) $g['fee_max'] > 0) {
        $caps[] = 'max ₱' . number_format((float) $g['fee_max'], 2);
    }
    if ($caps) {
        $base .= ' (' . implode(', ', $caps) . ')';
    }
    if (($g['fee_type'] ?? 'None') === 'None') {
        return $base;
    }
    return $base . (($g['fee_mode'] ?? 'deduct') === 'separate' ? ' · billed separately (not deducted)' : ' · deducted from settlement');
}

// ---------------------------------------------------------------------
// PayMongo (client-scoped)
// ---------------------------------------------------------------------
function sb_pm_secret_key(array $g): string
{
    $key = (string) (sb_gateway_secrets($g)['secret_key'] ?? '');
    if ($key === '') {
        throw new GatewayException('This client has no PayMongo secret key saved yet.');
    }
    return $key;
}

function sb_pm_request(array $g, string $method, string $path, ?array $attributes = null): array
{
    return paymongo_request($method, $path, $attributes, sb_pm_secret_key($g));
}

/** PayMongo wants E.164-ish +63 numbers. */
function sb_pm_mobile(?string $m): ?string
{
    $m = preg_replace('/[^0-9+]/', '', (string) $m);
    if ($m === '') {
        return null;
    }
    if (preg_match('/^09\d{9}$/', $m)) {
        return '+63' . substr($m, 1);
    }
    if (preg_match('/^639\d{9}$/', $m)) {
        return '+' . $m;
    }
    if (preg_match('/^\+639\d{9}$/', $m)) {
        return $m;
    }
    return null;
}

/**
 * Static store-front QR Ph (reusable, payer enters the amount).
 * POST /v1/qrph/generate  { kind: "instore", mobile_number?, name?, notes? }
 * @return array{id:string, qr_image:string, raw:array}
 */
function sb_pm_create_static_qr(array $g, string $label, ?string $notes, ?string $mobile): array
{
    $attrs = ['kind' => 'instore'];
    if ($mobile = sb_pm_mobile($mobile)) {
        $attrs['mobile_number'] = $mobile;
    }
    if ($notes !== null && $notes !== '') {
        $attrs['notes'] = mb_substr($notes, 0, 255);
    }
    $withName = $attrs + ['name' => mb_substr($label, 0, 100)];
    try {
        $res = sb_pm_request($g, 'POST', '/qrph/generate', $withName);
    } catch (PaymongoException $e) {
        // Older API versions don't accept "name" - retry once without it.
        if (stripos($e->getMessage(), 'name') === false) {
            throw $e;
        }
        $res = sb_pm_request($g, 'POST', '/qrph/generate', $attrs);
    }
    $data = $res['data'] ?? [];
    $a = $data['attributes'] ?? [];
    $image = (string) ($a['qr_image'] ?? $a['image_url'] ?? '');
    if ($image !== '' && !str_starts_with($image, 'data:') && !str_starts_with($image, 'http')) {
        $image = 'data:image/png;base64,' . $image;
    }
    if (empty($data['id']) || $image === '') {
        throw new GatewayException('PayMongo did not return a QR Ph code. Make sure QR Ph is activated on this PayMongo account.');
    }
    return ['id' => (string) $data['id'], 'qr_image' => $image, 'raw' => $data];
}

/**
 * Dynamic QR Ph (single use, exact amount, expires).
 * Payment Intent (qrph) -> Payment Method (qrph) -> Attach -> next_action.code.image_url
 * @return array{payment_intent_id:string, code_id:?string, qr_image:string, expires_at:string}
 */
function sb_pm_create_dynamic_qr(array $g, float $amount, string $description, array $metadata, int $expirySeconds = 1800): array
{
    if ($amount < 1) {
        throw new GatewayException('Amount must be at least ₱1.00.');
    }
    $expirySeconds = max(60, min(9000, $expirySeconds));
    $meta = [];
    foreach ($metadata as $k => $v) {
        $meta[(string) $k] = (string) $v;
    }
    $piAttrs = [
        'amount' => (int) round($amount * 100),
        'currency' => 'PHP',
        'payment_method_allowed' => ['qrph'],
        'capture_type' => 'automatic',
        'description' => mb_substr($description, 0, 255),
        'metadata' => $meta,
    ];
    $descriptor = sb_qr_display_name($g);
    if ($descriptor !== '') {
        $piAttrs['statement_descriptor'] = $descriptor;
    }
    try {
        $pi = sb_pm_request($g, 'POST', '/payment_intents', $piAttrs);
    } catch (PaymongoException $e) {
        if (!isset($piAttrs['statement_descriptor']) || stripos($e->getMessage(), 'descriptor') === false) {
            throw $e;
        }
        unset($piAttrs['statement_descriptor']); // account doesn't allow it - retry without
        $pi = sb_pm_request($g, 'POST', '/payment_intents', $piAttrs);
    }
    $piId = (string) ($pi['data']['id'] ?? '');
    $clientKey = (string) ($pi['data']['attributes']['client_key'] ?? '');
    if ($piId === '') {
        throw new GatewayException('PayMongo did not return a payment intent.');
    }
    $pm = sb_pm_request($g, 'POST', '/payment_methods', [
        'type' => 'qrph',
        'expiry_seconds' => $expirySeconds,
    ]);
    $pmId = (string) ($pm['data']['id'] ?? '');
    if ($pmId === '') {
        throw new GatewayException('PayMongo did not return a QR Ph payment method.');
    }
    $attach = ['payment_method' => $pmId];
    if ($clientKey !== '') {
        $attach['client_key'] = $clientKey;
    }
    $res = sb_pm_request($g, 'POST', '/payment_intents/' . rawurlencode($piId) . '/attach', $attach);
    $code = $res['data']['attributes']['next_action']['code'] ?? [];
    $image = (string) ($code['image_url'] ?? '');
    if ($image !== '' && !str_starts_with($image, 'data:') && !str_starts_with($image, 'http')) {
        $image = 'data:image/png;base64,' . $image;
    }
    if ($image === '') {
        throw new GatewayException('PayMongo did not return a QR image. Make sure QR Ph is activated on this PayMongo account.');
    }
    return [
        'payment_intent_id' => $piId,
        'code_id' => isset($code['id']) ? (string) $code['id'] : null,
        'qr_image' => $image,
        'expires_at' => date('Y-m-d H:i:s', time() + $expirySeconds),
    ];
}

function sb_pm_retrieve_intent(array $g, string $piId): array
{
    $r = sb_pm_request($g, 'GET', '/payment_intents/' . rawurlencode($piId));
    return $r['data'] ?? [];
}

const SB_PM_WEBHOOK_EVENTS = ['payment.paid', 'payment.failed', 'qrph.expired'];

/** Register (or re-use) this client's webhook on PayMongo and store its signing secret. */
function sb_pm_register_webhook(array $g): array
{
    $url = sb_gateway_webhook_url($g);
    $existing = null;
    try {
        $list = sb_pm_request($g, 'GET', '/webhooks');
        foreach (($list['data'] ?? []) as $w) {
            if (($w['attributes']['url'] ?? '') === $url) {
                $existing = $w;
                break;
            }
        }
    } catch (PaymongoException $e) {
        // listing is best-effort
    }
    if ($existing) {
        $events = $existing['attributes']['events'] ?? [];
        if (array_diff(SB_PM_WEBHOOK_EVENTS, $events)) {
            $upd = sb_pm_request($g, 'PUT', '/webhooks/' . rawurlencode($existing['id']), ['events' => array_values(array_unique(array_merge($events, SB_PM_WEBHOOK_EVENTS)))]);
            $existing = $upd['data'] ?? $existing;
        }
        if (($existing['attributes']['status'] ?? '') === 'disabled') {
            sb_pm_request($g, 'POST', '/webhooks/' . rawurlencode($existing['id']) . '/enable');
        }
        $hook = $existing;
    } else {
        $res = sb_pm_request($g, 'POST', '/webhooks', ['url' => $url, 'events' => SB_PM_WEBHOOK_EVENTS]);
        $hook = $res['data'] ?? [];
    }
    $secret = (string) ($hook['attributes']['secret_key'] ?? '');
    $secrets = sb_gateway_secrets($g);
    if ($secret !== '') {
        $secrets['webhook_secret'] = $secret;
    }
    db()->prepare('UPDATE sb_client_gateways SET webhook_remote_id = ?, secret_config_enc = ? WHERE id = ?')
        ->execute([(string) ($hook['id'] ?? ''), sb_encrypt_array($secrets), $g['id']]);
    return ['id' => (string) ($hook['id'] ?? ''), 'url' => $url, 'secret_saved' => $secret !== '', 'events' => $hook['attributes']['events'] ?? SB_PM_WEBHOOK_EVENTS];
}

// ---------------------------------------------------------------------
// Ledger: record a gateway payment (shared by webhook, status polling and
// the test-mode simulator). Idempotent on the provider payment id.
// ---------------------------------------------------------------------
/**
 * @param array $p normalized payment: id, amount (pesos), provider_fee?, provider_net?,
 *                 payer_name?, payment_intent_id?, code_id?, source_type?, paid_at?
 * @return array{created:bool, transaction_id:int}
 */
function sb_record_gateway_payment(array $g, array $p, ?array $qr, array $rawPayload): array
{
    $pdo = db();
    $ref = (string) $p['id'];
    $s = $pdo->prepare('SELECT id FROM sb_transactions WHERE reference_no = ?');
    $s->execute([$ref]);
    if ($id = $s->fetchColumn()) {
        return ['created' => false, 'transaction_id' => (int) $id];
    }

    $orgId = (int) $g['organization_id'];
    $amount = round((float) $p['amount'], 2);
    $fee = sb_compute_client_fee($g, $amount);
    $mode = $g['fee_mode'] === 'separate' ? 'separate' : 'deduct';
    $net = $mode === 'separate' ? $amount : round($amount - $fee, 2);
    $feeStatus = $fee <= 0 ? null : ($mode === 'separate' ? 'Unbilled' : 'Deducted');

    $pdo->beginTransaction();
    try {
        $b = $pdo->prepare('SELECT account_balance FROM sb_organization WHERE id = ? FOR UPDATE');
        $b->execute([$orgId]);
        $before = (float) $b->fetchColumn();
        $credit = (int) $g['credit_wallet'] === 1;
        $after = $credit ? $before + $net : $before;

        $pdo->prepare('INSERT INTO sb_transactions (transaction_date, type, provider, gateway_id, reference_no, qr_ph_trace_no, organization_id, branch_id, service_id, qr_id, status, pledge_name, pledge_id, amount, fee, fee_mode, fee_status, net_amount, provider_fee, provider_net, balance_before, balance_after, payload) VALUES (?, "Cash In", ?, ?, ?, ?, ?, ?, NULL, ?, "Completed", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $p['paid_at'] ?? date('Y-m-d H:i:s'),
                $g['provider_code'],
                $g['id'],
                $ref,
                $p['trace_no'] ?? ($p['payment_intent_id'] ?? $p['code_id'] ?? null),
                $orgId,
                $qr['branch_id'] ?? null,
                $qr['id'] ?? null,
                $qr ? $qr['display_name'] : ($p['label'] ?? 'QR Ph Payment'),
                $qr ? $qr['unique_id'] : ($p['label_ref'] ?? ($p['code_id'] ?? $ref)),
                $amount,
                $fee,
                $feeStatus ? $mode : null,
                $feeStatus,
                $net,
                isset($p['provider_fee']) ? round((float) $p['provider_fee'], 2) : null,
                isset($p['provider_net']) ? round((float) $p['provider_net'], 2) : null,
                $before,
                $after,
                json_encode([
                    'provider' => $g['provider_code'],
                    'payer_name' => $p['payer_name'] ?? null,
                    'source_type' => $p['source_type'] ?? null,
                    'environment' => $g['environment'],
                    'simulated' => !empty($p['simulated']),
                    'nl_qr_id' => $p['nl_qr_id'] ?? null,
                    'recorded_via' => $p['recorded_via'] ?? 'webhook',
                    'event' => $rawPayload,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        $txId = (int) $pdo->lastInsertId();

        if ($credit) {
            $pdo->prepare('UPDATE sb_organization SET account_balance = account_balance + ? WHERE id = ?')->execute([$net, $orgId]);
            if ($mode === 'deduct' && $fee > 0) {
                adjust_admin_balance($fee);
            }
        }
        if ($qr && $qr['qr_kind'] === 'dynamic') {
            $pdo->prepare("UPDATE sb_staticQR SET payment_status = 'Paid', paid_at = NOW(), status = 'Disabled' WHERE id = ?")->execute([$qr['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && $e->getCode() === '23000') {
            $s->execute([$ref]);
            return ['created' => false, 'transaction_id' => (int) $s->fetchColumn()];
        }
        throw $e;
    }
    return ['created' => true, 'transaction_id' => $txId];
}

/** Normalize a PayMongo payment resource ({id, attributes}) into sb_record_gateway_payment() input. */
function sb_pm_normalize_payment(array $payment): array
{
    $a = $payment['attributes'] ?? [];
    $src = $a['source'] ?? [];
    return [
        'id' => (string) ($payment['id'] ?? ''),
        'amount' => ((int) ($a['amount'] ?? 0)) / 100,
        'provider_fee' => isset($a['fee']) ? ((int) $a['fee']) / 100 : null,
        'provider_net' => isset($a['net_amount']) ? ((int) $a['net_amount']) / 100 : null,
        'payer_name' => sb_find_sender_name($payment),
        'payment_intent_id' => $a['payment_intent_id'] ?? null,
        'code_id' => $src['provider']['code_id'] ?? ($src['code_id'] ?? null),
        'trace_no' => $src['provider']['id'] ?? ($a['external_reference_number'] ?? null),
        'source_type' => $src['type'] ?? null,
        'status' => $a['status'] ?? null,
        'paid_at' => isset($a['paid_at']) && is_numeric($a['paid_at']) ? date('Y-m-d H:i:s', (int) $a['paid_at']) : null,
    ];
}

/**
 * V5.17: sender / payer name from any provider payload (PayMongo payment or webhook event,
 * Nationlink P2M notification, Pay8). Known paths first, then a recursive search for
 * name-like keys. Returns null when the provider did not send a name.
 */
function sb_find_sender_name(array $p): ?string
{
    $clean = function ($v): ?string {
        if (!is_string($v)) {
            return null;
        }
        $v = trim(preg_replace('/\s+/', ' ', $v) ?? '');
        if ($v === '' || mb_strlen($v) > 120 || preg_match('/^(n\/?a|null|none|-+)$/i', $v)) {
            return null;
        }
        return $v;
    };
    // Unwrap PayMongo webhook event -> payment resource
    $res = $p['data']['attributes']['data'] ?? ($p['event']['data']['attributes']['data'] ?? null);
    $pay = is_array($res) ? $res : $p;
    $a = $pay['attributes'] ?? $pay;
    $src = $a['source'] ?? [];
    $known = [
        $p['payer_name'] ?? null,
        $p['source_account_name'] ?? null,
        $p['SourceAcctName'] ?? null,
        $p['raw']['SourceAcctName'] ?? null,
        $p['event']['SourceAcctName'] ?? null,
        $a['billing']['name'] ?? null,
        $src['provider']['account_name'] ?? null,
        $src['provider']['sender_name'] ?? null,
        $src['provider']['name'] ?? null,
        $src['account_name'] ?? null,
        $src['sender_name'] ?? null,
        $src['details']['account_name'] ?? null,
        $a['metadata']['sender_name'] ?? null,
        $a['metadata']['payer_name'] ?? null,
        $p['data']['bankData']['DbtrNm'] ?? null,
        $p['event']['data']['bankData']['DbtrNm'] ?? null,
    ];
    foreach ($known as $v) {
        if ($n = $clean($v)) {
            return $n;
        }
    }
    // Recursive search for name-like keys (provider payloads vary).
    $want = '/^(sender|payer|debtor|source_?acct|source_?account|account_?holder|customer|originator|remitter)_?(full_?)?name$|^dbtrnm$/i';
    $stack = [$p];
    $guard = 0;
    while ($stack && $guard++ < 2000) {
        $node = array_pop($stack);
        foreach ($node as $k => $v) {
            if (is_array($v)) {
                $stack[] = $v;
            } elseif (is_string($k) && preg_match($want, $k) && ($n = $clean($v))) {
                return $n;
            }
        }
    }
    return null;
}

/** V5.17: sender account / mobile (masked) when the provider sends it. */
function sb_find_sender_account(array $p): ?string
{
    $res = $p['data']['attributes']['data'] ?? ($p['event']['data']['attributes']['data'] ?? null);
    $a = (is_array($res) ? $res : $p)['attributes'] ?? [];
    foreach ([$p['source_account_no_masked'] ?? null, $p['SourceAcctNo'] ?? null, $p['raw']['SourceAcctNo'] ?? null, $p['event']['SourceAcctNo'] ?? null, $a['billing']['phone'] ?? null, $a['source']['provider']['account_number'] ?? null] as $v) {
        $v = is_string($v) || is_numeric($v) ? trim((string) $v) : '';
        if ($v !== '') {
            return str_contains($v, '*') || strlen($v) < 6 ? $v : str_repeat('*', max(0, strlen($v) - 4)) . substr($v, -4);
        }
    }
    return null;
}

/** Find the SurgeBox QR a PayMongo payment belongs to (dynamic by intent id, static by code id). */
function sb_pm_find_qr(array $g, array $np): ?array
{
    if (!empty($np['payment_intent_id'])) {
        $s = db()->prepare('SELECT * FROM sb_staticQR WHERE pm_payment_intent_id = ? AND gateway_id = ? LIMIT 1');
        $s->execute([$np['payment_intent_id'], $g['id']]);
        if ($r = $s->fetch()) {
            return $r;
        }
    }
    if (!empty($np['code_id'])) {
        $s = db()->prepare('SELECT * FROM sb_staticQR WHERE pm_code_id = ? AND organization_id = ? LIMIT 1');
        $s->execute([$np['code_id'], $g['organization_id']]);
        if ($r = $s->fetch()) {
            return $r;
        }
    }
    return null;
}

/** Scope helper for client-portal endpoints: returns the org id or fails. */
function sb_require_client_org(array $user, bool $allowAdmin = true, $orgParam = null): int
{
    if ($user['user_type'] === 'Admin' && $allowAdmin) {
        $id = (int) ($orgParam ?? 0);
        if ($id <= 0) {
            json_response(['status' => 'error', 'message' => 'organizationId is required'], 400);
        }
        return $id;
    }
    if ($user['user_type'] === 'Manager' && !empty($user['organization_id'])) {
        return (int) $user['organization_id'];
    }
    json_response(['status' => 'error', 'message' => 'Access denied.'], 403);
}

/**
 * V5.1 - SurgeBox fees (MDR) are visible to Admin only. Clients (Organization
 * Portal) and staff Managers/Viewers see collections at their NET amount only,
 * e.g. payer sends ₱1,010.00, fee ₱10.00 -> client sees ₱1,000.00.
 */
function sb_fees_visible(?array $user = null): bool
{
    $u = $user ?? current_user();
    return ($u['user_type'] ?? '') === 'Admin' && !is_org_portal();
}

/**
 * V5.5 - Provider names (PayMongo, Nationlink...) are Admin-only. Clients see a
 * neutral "QR Ph" brand. Returns the name to show to the current viewer.
 */
function sb_provider_label(array $g, ?array $user = null): string
{
    // V5.16: the provider (PayMongo / Nationlink) is shown to both Admin and the client.
    return (string) ($g['provider_name'] ?? sb_provider_display($g['provider_code'] ?? null) ?? '');
}

/** V5.16: display name for a provider code (null when unknown / not a gateway transaction). */
function sb_provider_display(?string $code): ?string
{
    return match ((string) $code) {
        'paymongo', 'paymongo_qrph' => 'PayMongo',
        'nationlink' => 'Nationlink',
        'pay8' => 'OptekPay (Pay8)',
        'swiftpay' => 'SwiftPay',
        'sandbox' => 'Sandbox',
        default => null,
    };
}

/** V5.16: provider code of a transaction row (new gateway column, else inferred from the legacy payload). */
function sb_tx_provider_code(array $t, ?array $payload = null): ?string
{
    if (!empty($t['provider'])) {
        return (string) $t['provider'];
    }
    $payload = $payload ?? (json_decode((string) ($t['payload'] ?? ''), true) ?: []);
    if (!empty($payload['provider']) && is_string($payload['provider'])) {
        return $payload['provider'];
    }
    if (($t['type'] ?? '') !== 'Cash In') {
        return null;
    }
    if (isset($payload['tran_code']) || isset($payload['SourceAcctName']) || isset($payload['source_account_name'])) {
        return 'nationlink';
    }
    if (isset($payload['data']['bankData'])) {
        return 'pay8';
    }
    return null;
}

/** Error text safe to show a client (no provider names); full detail is logged. */
function sb_client_safe_error(Throwable $e, ?array $user = null): string
{
    if (sb_fees_visible($user)) {
        return 'PayMongo: ' . $e->getMessage();
    }
    error_log('[SurgeBox QR Ph] ' . get_class($e) . ': ' . $e->getMessage());
    return 'The QR Ph service could not complete this request right now. Please try again in a few minutes or contact SurgeBox support.';
}

/**
 * V5.10 - Short merchant name for the payer's app (GCash/Maya show max ~25 chars).
 * Gateway setting "Name shown in GCash/Maya" -> else the Client Code -> else ''.
 */
function sb_qr_display_name(array $g): string
{
    $name = trim((string) (sb_gateway_public($g)['qr_display_name'] ?? ''));
    if ($name === '') {
        $s = db()->prepare('SELECT client_code FROM sb_organization WHERE id = ?');
        $s->execute([(int) $g['organization_id']]);
        $name = trim((string) $s->fetchColumn());
    }
    $name = preg_replace('/[^A-Za-z0-9 .&\-]/', '', $name) ?? '';
    return mb_substr(strtoupper($name), 0, 22);
}

/**
 * V5.25 - PayMongo QR Ph does not send the payer's name; it sends the payer's bank / e-wallet
 * (source.provider.bank_institution_code, a BIC) and the InstaPay reference (source.provider.id).
 */
function sb_bic_name(string $bic): string
{
    $map = [
        'GXCHPHM2' => 'GCash', 'PAPHPHM1' => 'Maya', 'BNORPHMM' => 'BDO', 'BOPIPHMM' => 'BPI', 'MBTCPHMM' => 'Metrobank',
        'TLBPPHMM' => 'Landbank', 'PNBMPHMM' => 'PNB', 'UBPHPHMM' => 'UnionBank', 'RCBCPHMM' => 'RCBC', 'CHBKPHMM' => 'China Bank',
        'SETCPHMM' => 'Security Bank', 'EWBCPHMM' => 'EastWest Bank', 'DBPHPHMM' => 'DBP', 'AUBKPHMM' => 'AUB', 'PSBCPHMM' => 'PSBank',
        'ROBPPHMQ' => 'Robinsons Bank', 'CIPHPHMM' => 'CIMB Bank', 'PAEYPHM2' => 'PayMongo',
    ];
    $k = strtoupper(substr(trim($bic), 0, 8));
    return $map[$k] ?? strtoupper(trim($bic));
}

/** Payer's bank / e-wallet and bank reference from a stored payload, or null. */
function sb_find_sender_bank(array $p): ?array
{
    $res = $p['data']['attributes']['data'] ?? ($p['event']['data']['attributes']['data'] ?? null);
    $a = (is_array($res) ? $res : $p)['attributes'] ?? [];
    $prov = $a['source']['provider'] ?? [];
    $bic = (string) ($prov['bank_institution_code'] ?? ($p['source_bank'] ?? ''));
    if ($bic === '' && preg_match('/^\d{8}([A-Z0-9]{11})/', (string) ($prov['id'] ?? ''), $m)) {
        $bic = $m[1]; // InstaPay reference = yyyymmdd + BIC + sequence
    }
    if ($bic === '') {
        return null;
    }
    return ['bic' => $bic, 'name' => sb_bic_name($bic), 'ref' => (string) ($prov['id'] ?? ($a['source']['provider_id'] ?? ''))];
}
