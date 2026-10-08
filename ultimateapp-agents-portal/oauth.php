<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/apple_oidc.php';
require_once __DIR__ . '/includes/agents.php';

$provider = strtolower($_GET['provider'] ?? $_POST['provider'] ?? '');
$allowedProviders = ['google', 'facebook', 'apple'];
if (!in_array($provider, $allowedProviders, true)) {
    redirect('login.php?social_error=' . urlencode('Unsupported social login provider.'));
}

$isCallback = isset($_GET['callback']) || isset($_POST['callback']);
rate_limit_enforce($isCallback ? 'oauth_callback_ip' : 'oauth_start_ip',
    'ip:' . rate_limit_client_ip(), $isCallback ? 60 : 30, 600);
ensure_social_accounts_table();
if (!$isCallback) {
    start_social_login($provider);
}

handle_social_callback($provider);

function base_url(): string
{
    global $appUrl;
    if (!empty($appUrl)) {
        return $appUrl;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $scheme . '://' . $host . ($dir === '' ? '' : $dir);
}

function callback_url(string $provider): string
{
    return base_url() . '/oauth.php?provider=' . urlencode($provider) . '&callback=1';
}

function provider_config(string $provider): array
{
    global $oauthProviders;
    return $oauthProviders[$provider] ?? [];
}

function require_provider_config(string $provider, array $required): array
{
    $config = provider_config($provider);
    foreach ($required as $key) {
        if (empty($config[$key])) {
            redirect('login.php?social_error=' . urlencode(ucfirst($provider) . ' login is not configured yet.'));
        }
    }
    return $config;
}

function start_social_login(string $provider): never
{
    $state = bin2hex(random_bytes(24));
    $_SESSION['oauth_state_' . $provider] = $state;

    if ($provider === 'google') {
        $config = require_provider_config('google', ['client_id', 'client_secret']);
        $params = [
            'client_id' => $config['client_id'],
            'redirect_uri' => callback_url('google'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ];
        redirect('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
    }

    if ($provider === 'facebook') {
        $config = require_provider_config('facebook', ['client_id', 'client_secret']);
        $params = [
            'client_id' => $config['client_id'],
            'redirect_uri' => callback_url('facebook'),
            'response_type' => 'code',
            'scope' => 'email,public_profile',
            'state' => $state,
        ];
        redirect('https://www.facebook.com/v19.0/dialog/oauth?' . http_build_query($params));
    }

    $config = require_provider_config('apple', ['client_id', 'team_id', 'key_id', 'private_key_path']);
    $nonce = bin2hex(random_bytes(24));
    $_SESSION['oauth_nonce_apple'] = $nonce;
    // Apple returns to this endpoint using a cross-site form POST.
    apple_session_cookie('None');
    $params = [
        'client_id' => $config['client_id'],
        'redirect_uri' => callback_url('apple'),
        'response_type' => 'code',
        'response_mode' => 'form_post',
        'scope' => 'name email',
        'state' => $state,
        'nonce' => $nonce,
    ];
    redirect('https://appleid.apple.com/auth/authorize?' . http_build_query($params));
}

function handle_social_callback(string $provider): never
{
    $state = $_GET['state'] ?? $_POST['state'] ?? null;
    $expectedState = $_SESSION['oauth_state_' . $provider] ?? null;
    if (!is_string($state) || !is_string($expectedState) || $state === ''
        || $expectedState === '' || !hash_equals($expectedState, $state)) {
        if ($provider === 'apple') {
            unset($_SESSION['oauth_nonce_apple']);
            apple_session_cookie('Lax');
        }
        redirect('login.php?social_error=' . urlencode('Invalid social login session. Please try again.'));
    }
    unset($_SESSION['oauth_state_' . $provider]);

    $code = $_GET['code'] ?? $_POST['code'] ?? '';
    if (!is_string($code) || $code === '') {
        if ($provider === 'apple') {
            unset($_SESSION['oauth_nonce_apple']);
            apple_session_cookie('Lax');
        }
        redirect('login.php?social_error=' . urlencode('Social login was cancelled or denied.'));
    }

    try {
        $profile = fetch_social_profile($provider, $code);
        if ($provider === 'apple') {
            unset($_SESSION['oauth_nonce_apple']);
            apple_session_cookie('Lax');
        }
        $userId = social_login_user($provider, $profile);
        session_regenerate_id(true);
        if ($provider === 'apple') {
            apple_session_cookie('Lax');
        }
        $_SESSION['user_id'] = $userId;
        $return = $_SESSION['mctc_return'] ?? '';
        unset($_SESSION['mctc_return']);
        redirect(is_string($return) && preg_match('/^(mctc-approve|convert-approve|driver-topup-pay)[.]php[?]token=[a-f0-9]{64}$/D', $return) ? $return : 'dashboard.php');
    } catch (Throwable $e) {
        if ($provider === 'apple') {
            unset($_SESSION['oauth_nonce_apple']);
            apple_session_cookie('Lax');
        }
        redirect('login.php?social_error=' . urlencode($e->getMessage()));
    }
}

function fetch_social_profile(string $provider, string $code): array
{
    if ($provider === 'google') {
        $config = require_provider_config('google', ['client_id', 'client_secret']);
        $token = http_post('https://oauth2.googleapis.com/token', [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => callback_url('google'),
        ]);
        $profile = http_get_json('https://openidconnect.googleapis.com/v1/userinfo', [
            'Authorization: Bearer ' . ($token['access_token'] ?? ''),
        ]);
        return [
            'id' => (string) ($profile['sub'] ?? ''),
            'email' => (string) ($profile['email'] ?? ''),
            'email_verified' => ($profile['email_verified'] ?? false) === true,
            'name' => (string) ($profile['name'] ?? 'Google User'),
        ];
    }

    if ($provider === 'facebook') {
        $config = require_provider_config('facebook', ['client_id', 'client_secret']);
        $token = http_get_json('https://graph.facebook.com/v19.0/oauth/access_token?' . http_build_query([
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'code' => $code,
            'redirect_uri' => callback_url('facebook'),
        ]));
        $profile = http_get_json('https://graph.facebook.com/me?' . http_build_query([
            'fields' => 'id,name,email',
            'access_token' => $token['access_token'] ?? '',
        ]));
        return [
            'id' => (string) ($profile['id'] ?? ''),
            'email' => (string) ($profile['email'] ?? ''),
            'email_verified' => false,
            'name' => (string) ($profile['name'] ?? 'Facebook User'),
        ];
    }

    $config = require_provider_config('apple', ['client_id', 'team_id', 'key_id', 'private_key_path']);
    $token = http_post('https://appleid.apple.com/auth/token', [
        'client_id' => $config['client_id'],
        'client_secret' => apple_client_secret($config),
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => callback_url('apple'),
    ]);
    $nonce = $_SESSION['oauth_nonce_apple'] ?? null;
    if (!is_string($nonce) || $nonce === '') {
        throw new RuntimeException('Invalid social login session. Please try again.');
    }
    $claims = verify_apple_id_token(
        (string) ($token['id_token'] ?? ''),
        http_get_json('https://appleid.apple.com/auth/keys'),
        $config['client_id'],
        $nonce
    );
    $name = 'Apple User';
    if (!empty($_POST['user'])) {
        $appleUser = json_decode((string) $_POST['user'], true);
        $first = $appleUser['name']['firstName'] ?? '';
        $last = $appleUser['name']['lastName'] ?? '';
        $name = trim($first . ' ' . $last) ?: $name;
    }
    return [
        'id' => (string) ($claims['sub'] ?? ''),
        'email' => (string) ($claims['email'] ?? ''),
        'email_verified' => in_array($claims['email_verified'] ?? null, [true, 'true'], true),
        'name' => $name,
    ];
}

function social_login_user(string $provider, array $profile): int
{
    global $pdo;
    $providerUserId = trim((string) ($profile['id'] ?? ''));
    if ($providerUserId === '') {
        throw new RuntimeException('Provider did not return a user ID.');
    }

    $email = !empty($profile['email_verified']) ? trim((string) ($profile['email'] ?? '')) : '';
    $name = trim((string) ($profile['name'] ?? ''));
    if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 120
        || preg_match('/^\p{L}+(?: \p{L}+)*$/uD', $name) !== 1) {
        $name = ucfirst($provider) . ' User';
    }

    $stmt = $pdo->prepare('SELECT user_id FROM social_accounts WHERE provider = ? AND provider_user_id = ? LIMIT 1');
    $stmt->execute([$provider, $providerUserId]);
    $linked = $stmt->fetch();
    if ($linked) {
        return (int) $linked['user_id'];
    }

    $user = null;
    if ($email !== '') {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
    }

    if ($user) {
        // A provider email alone must not link an existing local account.
        throw new RuntimeException('An account with this email already exists. Sign in with your existing method.');
    } else {
        $emailForInsert = $email !== '' ? $email : $providerUserId . '@' . $provider . '.ultimate.local';
        $mobile = strtoupper($provider) . '-' . substr(hash('sha256', $providerUserId), 0, 18);
        $stmt = $pdo->prepare('INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES (?, ?, ?, ?, ?, 0)');
        $stmt->execute([
            $name,
            $mobile,
            $emailForInsert,
            password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'UA-' . strtoupper(bin2hex(random_bytes(5))),
        ]);
        $userId = (int) $pdo->lastInsertId();
        // New account from a shared agent link: tag the customer to that agent.
        agent_attach_referral($pdo, $userId, agent_pending_referral_code());
    }

    $stmt = $pdo->prepare('INSERT INTO social_accounts (user_id, provider, provider_user_id, email) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $provider, $providerUserId, $email ?: null]);
    return $userId;
}

function ensure_social_accounts_table(): void
{
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS social_accounts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        provider ENUM('google','facebook','apple') NOT NULL,
        provider_user_id VARCHAR(191) NOT NULL,
        email VARCHAR(160) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_provider_user (provider, provider_user_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
}

function http_post(string $url, array $data): array
{
    return http_json($url, [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
        'content' => http_build_query($data),
    ]);
}

function http_get_json(string $url, array $headers = []): array
{
    $header = "Accept: application/json\r\n" . implode("\r\n", $headers);
    return http_json($url, ['method' => 'GET', 'header' => $header]);
}

function http_json(string $url, array $options): array
{
    $context = stream_context_create(['http' => ['ignore_errors' => true] + $options]);
    $response = file_get_contents($url, false, $context);
    if ($response === false) {
        throw new RuntimeException('Unable to contact social login provider.');
    }
    $json = json_decode($response, true);
    if (!is_array($json)) {
        throw new RuntimeException('Invalid response from social login provider.');
    }
    if (!empty($json['error'])) {
        $message = is_array($json['error']) ? ($json['error']['message'] ?? 'Social login error.') : (string) $json['error'];
        throw new RuntimeException($message);
    }
    return $json;
}

function apple_client_secret(array $config): string
{
    if (!is_readable($config['private_key_path'])) {
        throw new RuntimeException('Apple private key file is not readable.');
    }
    $now = time();
    $header = ['alg' => 'ES256', 'kid' => $config['key_id']];
    $payload = [
        'iss' => $config['team_id'],
        'iat' => $now,
        'exp' => $now + 86400 * 30,
        'aud' => 'https://appleid.apple.com',
        'sub' => $config['client_id'],
    ];
    $unsigned = base64url_json($header) . '.' . base64url_json($payload);
    $key = openssl_pkey_get_private(file_get_contents($config['private_key_path']));
    if (!$key || !openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Unable to sign Apple client secret.');
    }
    return $unsigned . '.' . base64url_encode(ecdsa_der_to_jose($signature, 64));
}

function apple_session_cookie(string $sameSite): void
{
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        throw new RuntimeException('Apple login requires HTTPS.');
    }
    $params = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires' => 0,
        'path' => $params['path'] ?: '/',
        'domain' => $params['domain'],
        'secure' => true,
        'httponly' => true,
        'samesite' => $sameSite,
    ]);
}


function ecdsa_der_to_jose(string $der, int $signatureLength): string
{
    $offset = 0;
    if (ord($der[$offset++]) !== 0x30) {
        throw new RuntimeException('Invalid Apple signature format.');
    }
    read_der_length($der, $offset);
    if (ord($der[$offset++]) !== 0x02) {
        throw new RuntimeException('Invalid Apple signature format.');
    }
    $rLength = read_der_length($der, $offset);
    $r = substr($der, $offset, $rLength);
    $offset += $rLength;
    if (ord($der[$offset++]) !== 0x02) {
        throw new RuntimeException('Invalid Apple signature format.');
    }
    $sLength = read_der_length($der, $offset);
    $s = substr($der, $offset, $sLength);
    $partLength = intdiv($signatureLength, 2);
    return str_pad(ltrim($r, "\x00"), $partLength, "\x00", STR_PAD_LEFT)
        . str_pad(ltrim($s, "\x00"), $partLength, "\x00", STR_PAD_LEFT);
}

function read_der_length(string $der, int &$offset): int
{
    $length = ord($der[$offset++]);
    if ($length < 0x80) {
        return $length;
    }
    $bytes = $length & 0x7f;
    $length = 0;
    for ($i = 0; $i < $bytes; $i++) {
        $length = ($length << 8) | ord($der[$offset++]);
    }
    return $length;
}
function base64url_json(array $data): string
{
    return base64url_encode(json_encode($data, JSON_UNESCAPED_SLASHES));
}

function base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}
