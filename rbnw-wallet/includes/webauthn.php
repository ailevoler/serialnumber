<?php
/**
 * Minimal WebAuthn (passkey) relying party for biometric login.
 *
 * The phone's fingerprint / Face ID / screen lock unlocks a private key
 * that never leaves the device; we store only its public key and verify
 * signatures over a fresh server challenge. Supports ES256 (-7) and
 * RS256 (-257), attestation "none" (we don't need device make/model).
 */

require_once __DIR__ . '/functions.php';

const WEBAUTHN_TIMEOUT_MS = 60000;

function b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64url_decode(string $s): string
{
    $bin = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    if ($bin === false) {
        throw new RuntimeException('Bad encoding.');
    }
    return $bin;
}

/** Relying party id = the host the app is served on (no port). */
function webauthn_rp_id(): string
{
    return strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost'));
}

function webauthn_origin(): string
{
    return (is_https() ? 'https' : 'http') . '://' . strtolower($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function webauthn_user_handle(int $userId): string
{
    $stmt = db()->prepare("SELECT webauthn_handle FROM rbnw_users WHERE id = ?");
    $stmt->execute([$userId]);
    $h = $stmt->fetchColumn();
    if (!$h) {
        $h = bin2hex(random_bytes(16));
        db()->prepare("UPDATE rbnw_users SET webauthn_handle = ? WHERE id = ? AND webauthn_handle IS NULL")->execute([$h, $userId]);
        return webauthn_user_handle($userId);
    }
    return $h;
}

function webauthn_credentials(int $userId): array
{
    $stmt = db()->prepare("SELECT * FROM rbnw_webauthn_credentials WHERE user_id = ? ORDER BY id");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function webauthn_new_challenge(string $purpose, array $extra = []): string
{
    $challenge = random_bytes(32);
    $_SESSION['webauthn'] = ['purpose' => $purpose, 'challenge' => b64url_encode($challenge), 'expires' => time() + 120] + $extra;
    return b64url_encode($challenge);
}

/** Pull (and clear) the pending challenge for this purpose. */
function webauthn_take_challenge(string $purpose): array
{
    $c = $_SESSION['webauthn'] ?? null;
    unset($_SESSION['webauthn']);
    if (!$c || $c['purpose'] !== $purpose || $c['expires'] < time()) {
        throw new RuntimeException('Biometric request expired. Please try again.');
    }
    return $c;
}

// ---------------------------------------------------------------------
// CBOR (RFC 8949) — just enough for attestation objects and COSE keys
// ---------------------------------------------------------------------
function cbor_decode(string $data)
{
    $pos = 0;
    $v = cbor_item($data, $pos);
    return $v;
}

function cbor_item(string $d, int &$p)
{
    if ($p >= strlen($d)) {
        throw new RuntimeException('Truncated CBOR.');
    }
    $ib = ord($d[$p++]);
    $major = $ib >> 5;
    $info = $ib & 0x1f;
    if ($major === 7) {
        return match ($info) { 20 => false, 21 => true, 22, 23 => null, default => throw new RuntimeException('Unsupported CBOR simple value.') };
    }
    $len = cbor_len($d, $p, $info);
    switch ($major) {
        case 0: return $len;
        case 1: return -1 - $len;
        case 2:
        case 3:
            $s = substr($d, $p, $len);
            if (strlen($s) !== $len) {
                throw new RuntimeException('Truncated CBOR string.');
            }
            $p += $len;
            return $s;
        case 4:
            $a = [];
            for ($i = 0; $i < $len; $i++) { $a[] = cbor_item($d, $p); }
            return $a;
        case 5:
            $m = [];
            for ($i = 0; $i < $len; $i++) { $k = cbor_item($d, $p); $m[$k] = cbor_item($d, $p); }
            return $m;
        default:
            throw new RuntimeException('Unsupported CBOR type.');
    }
}

function cbor_len(string $d, int &$p, int $info): int
{
    if ($info < 24) { return $info; }
    $n = [24 => 1, 25 => 2, 26 => 4, 27 => 8][$info] ?? throw new RuntimeException('Unsupported CBOR length.');
    $bytes = substr($d, $p, $n);
    $p += $n;
    return (int)hexdec(bin2hex($bytes));
}

// ---------------------------------------------------------------------
// COSE public key -> PEM
// ---------------------------------------------------------------------
function der_len(int $len): string
{
    if ($len < 128) { return chr($len); }
    $b = ltrim(pack('N', $len), "\0");
    return chr(0x80 | strlen($b)) . $b;
}
function der(int $tag, string $body): string { return chr($tag) . der_len(strlen($body)) . $body; }
function der_uint(string $bytes): string
{
    $bytes = ltrim($bytes, "\0");
    if ($bytes === '' || (ord($bytes[0]) & 0x80)) { $bytes = "\0" . $bytes; }
    return der(0x02, $bytes);
}

/** Returns [pem, alg]. */
function cose_to_pem(array $cose): array
{
    $kty = $cose[1] ?? null;
    $alg = $cose[3] ?? null;
    if ($kty === 2 && $alg === -7 && ($cose[-1] ?? null) === 1) {             // EC2 P-256, ES256
        $x = $cose[-2]; $y = $cose[-3];
        if (strlen($x) !== 32 || strlen($y) !== 32) { throw new RuntimeException('Bad EC key.'); }
        $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
    } elseif ($kty === 3 && $alg === -257) {                                 // RSA, RS256
        $rsa  = der(0x30, der_uint($cose[-1]) . der_uint($cose[-2]));
        $algo = der(0x30, der(0x06, hex2bin('2a864886f70d010101')) . "\x05\x00");
        $spki = der(0x30, $algo . der(0x03, "\0" . $rsa));
    } else {
        throw new RuntimeException('This device uses an unsupported key type.');
    }
    return ["-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n", $alg];
}

// ---------------------------------------------------------------------
// Ceremony checks
// ---------------------------------------------------------------------
function webauthn_check_client_data(string $clientDataJSON, string $type, string $challenge): void
{
    $c = json_decode($clientDataJSON, true);
    if (!is_array($c) || ($c['type'] ?? '') !== $type) {
        throw new RuntimeException('Unexpected biometric response.');
    }
    if (!hash_equals($challenge, (string)($c['challenge'] ?? ''))) {
        throw new RuntimeException('Biometric challenge mismatch. Please try again.');
    }
    if (($c['origin'] ?? '') !== webauthn_origin()) {
        throw new RuntimeException('Biometric request came from the wrong site.');
    }
}

/** Parse authenticator data; enforce RP id, user present + user verified. */
function webauthn_parse_auth_data(string $ad, bool $expectCredential): array
{
    if (strlen($ad) < 37) { throw new RuntimeException('Bad authenticator data.'); }
    if (!hash_equals(hash('sha256', webauthn_rp_id(), true), substr($ad, 0, 32))) {
        throw new RuntimeException('Biometric key belongs to another site.');
    }
    $flags = ord($ad[32]);
    if (!($flags & 0x01) || !($flags & 0x04)) {
        throw new RuntimeException('Fingerprint / face verification was not completed.');
    }
    $out = ['sign_count' => unpack('N', substr($ad, 33, 4))[1]];
    if ($expectCredential) {
        if (!($flags & 0x40)) { throw new RuntimeException('No credential in response.'); }
        $len = unpack('n', substr($ad, 53, 2))[1];
        $out['credential_id'] = substr($ad, 55, $len);
        $pos = 55 + $len;
        $out['cose'] = cbor_item($ad, $pos);
    }
    return $out;
}

function webauthn_verify_signature(string $pem, string $authData, string $clientDataJSON, string $sig): bool
{
    $key = openssl_pkey_get_public($pem);
    return $key && openssl_verify($authData . hash('sha256', $clientDataJSON, true), $sig, $key, OPENSSL_ALGO_SHA256) === 1;
}
