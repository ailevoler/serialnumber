<?php
require_once __DIR__ . '/../includes/webauthn.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

function fail(string $msg, int $code = 200): never
{
    json_out(['success' => false, 'message' => $msg], $code);
}

try {
switch ($action) {

    // ---------- enrollment (logged in) ----------
    case 'register_options':
        require_login();
        $user = get_user(current_user_id());
        $mpin = trim((string)($input['mpin'] ?? ''));
        if ($mpin === '' || !password_verify($mpin, (string)$user['mpin_hash'])) {
            fail('Incorrect MPIN.');
        }
        json_out(['success' => true, 'publicKey' => [
            'challenge' => webauthn_new_challenge('register'),
            'rp'        => ['name' => APP_NAME, 'id' => webauthn_rp_id()],
            'user'      => [
                'id'          => b64url_encode(hex2bin(webauthn_user_handle((int)$user['id']))),
                'name'        => $user['mobile_number'],
                'displayName' => trim($user['first_name'] . ' ' . $user['last_name']),
            ],
            'pubKeyCredParams'       => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            'authenticatorSelection' => ['authenticatorAttachment' => 'platform', 'userVerification' => 'required', 'residentKey' => 'preferred'],
            'attestation'            => 'none',
            'timeout'                => WEBAUTHN_TIMEOUT_MS,
            'excludeCredentials'     => array_map(fn($c) => ['type' => 'public-key', 'id' => $c['credential_id']], webauthn_credentials((int)$user['id'])),
        ]]);
        break;

    case 'register_verify':
        require_login();
        $userId = current_user_id();
        $c = webauthn_take_challenge('register');
        $clientData = b64url_decode((string)($input['clientDataJSON'] ?? ''));
        webauthn_check_client_data($clientData, 'webauthn.create', $c['challenge']);

        $att = cbor_decode(b64url_decode((string)($input['attestationObject'] ?? '')));
        if (!is_array($att) || !isset($att['authData'])) {
            fail('Bad biometric registration.');
        }
        $ad = webauthn_parse_auth_data($att['authData'], true);
        [$pem] = cose_to_pem($ad['cose']);
        $credId = b64url_encode($ad['credential_id']);
        if ($credId !== ($input['id'] ?? '')) {
            fail('Credential mismatch.');
        }
        $label = trim(mb_substr((string)($input['label'] ?? 'This phone'), 0, 100)) ?: 'This phone';

        db()->prepare(
            "INSERT INTO rbnw_webauthn_credentials (user_id, credential_id, public_key_pem, sign_count, label) VALUES (?,?,?,?,?)"
        )->execute([$userId, $credId, $pem, $ad['sign_count'], $label]);
        try {
            push_notification($userId, 'Biometric login turned on', "You can now log in with fingerprint / face on {$label}.", 'security');
        } catch (Throwable $e) {
            error_log('[RBNW] notify failed: ' . $e->getMessage());
        }
        json_out(['success' => true, 'credential_id' => $credId]);
        break;

    case 'list':
        require_login();
        json_out(['success' => true, 'credentials' => array_map(fn($c) => [
            'id' => (int)$c['id'], 'credential_id' => $c['credential_id'], 'label' => $c['label'],
            'created' => date('M j, Y', strtotime($c['created_at'])),
            'last_used' => $c['last_used_at'] ? time_ago_ph($c['last_used_at']) : null,
        ], webauthn_credentials(current_user_id()))]);
        break;

    case 'remove':
        require_login();
        db()->prepare("DELETE FROM rbnw_webauthn_credentials WHERE id = ? AND user_id = ?")
            ->execute([(int)($input['id'] ?? 0), current_user_id()]);
        json_out(['success' => true]);
        break;

    // ---------- login (guest) ----------
    case 'login_options':
        $mobile = normalize_mobile((string)($input['mobile_number'] ?? ''));
        $stmt = db()->prepare("SELECT id, status, locked_until FROM rbnw_users WHERE mobile_number = ? LIMIT 1");
        $stmt->execute([$mobile]);
        $user = $stmt->fetch();
        $creds = $user ? webauthn_credentials((int)$user['id']) : [];
        if (!$creds) {
            fail('Biometric login is not set up for this number yet. Log in with your MPIN, then turn it on in Profile → Security.');
        }
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            fail('Account temporarily locked. Try again later.', 423);
        }
        json_out(['success' => true, 'publicKey' => [
            'challenge'        => webauthn_new_challenge('login', ['user_id' => (int)$user['id']]),
            'rpId'             => webauthn_rp_id(),
            'allowCredentials' => array_map(fn($c) => ['type' => 'public-key', 'id' => $c['credential_id'], 'transports' => ['internal', 'hybrid']], $creds),
            'userVerification' => 'required',
            'timeout'          => WEBAUTHN_TIMEOUT_MS,
        ]]);
        break;

    case 'login_verify':
        $c = webauthn_take_challenge('login');
        $credId = (string)($input['id'] ?? '');
        $stmt = db()->prepare("SELECT * FROM rbnw_webauthn_credentials WHERE credential_id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$credId, $c['user_id']]);
        $cred = $stmt->fetch();
        if (!$cred) {
            fail('This fingerprint / face key is not registered for this account.');
        }

        $clientData = b64url_decode((string)($input['clientDataJSON'] ?? ''));
        $authData   = b64url_decode((string)($input['authenticatorData'] ?? ''));
        $signature  = b64url_decode((string)($input['signature'] ?? ''));
        webauthn_check_client_data($clientData, 'webauthn.get', $c['challenge']);
        $ad = webauthn_parse_auth_data($authData, false);
        if (!webauthn_verify_signature($cred['public_key_pem'], $authData, $clientData, $signature)) {
            fail('Biometric verification failed.');
        }
        // A counter that goes backwards suggests a cloned key.
        if ($ad['sign_count'] > 0 && $cred['sign_count'] > 0 && $ad['sign_count'] <= $cred['sign_count']) {
            fail('This biometric key looks cloned. Log in with your MPIN and re-enable biometrics.');
        }

        $user = get_user((int)$c['user_id']);
        if ($user['status'] !== 'active') {
            fail('This account is ' . $user['status'] . '. Contact RBNW support.');
        }
        db()->prepare("UPDATE rbnw_webauthn_credentials SET sign_count = ?, last_used_at = NOW() WHERE id = ?")
            ->execute([$ad['sign_count'], $cred['id']]);
        db()->prepare("UPDATE rbnw_users SET failed_pin_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?")
            ->execute([$user['id']]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        json_out(['success' => true]);
        break;

    default:
        fail('Unknown action.', 404);
}
} catch (RuntimeException $e) {
    fail($e->getMessage());
}
