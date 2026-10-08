<?php
declare(strict_types=1);

/**
 * Application settings stored in app_settings, edited in Admin > Settings.
 * Secret values (API keys, webhook secrets) are encrypted (AES-256-GCM via OpenSSL, or libsodium) using
 * the key in config/app_secret.php (created automatically, never web-readable).
 */

function settings_schema(): array
{
    $p2m = static fn(string $label): array => [
        'enabled' => ['type' => 'bool', 'label' => $label . ' payments enabled'],
        'percent_bp' => ['type' => 'int', 'min' => 0, 'max' => 2000, 'label' => $label . ' charge (%)'],
        'fixed_centavos' => ['type' => 'int', 'min' => 0, 'max' => 100000, 'label' => $label . ' fixed charge'],
        'bearer' => ['type' => 'enum', 'values' => ['customer', 'merchant'], 'label' => $label . ' charge paid by'],
    ];
    $schema = [
        'paymongo.mode' => ['type' => 'enum', 'values' => ['test', 'live'], 'label' => 'PayMongo mode'],
        'paymongo.test_public_key' => ['type' => 'key', 'prefix' => 'pk_test_', 'label' => 'Test public key'],
        'paymongo.test_secret_key' => ['type' => 'key', 'prefix' => 'sk_test_', 'secret' => true, 'label' => 'Test secret key'],
        'paymongo.test_webhook_secret' => ['type' => 'key', 'prefix' => 'whsk_', 'secret' => true, 'label' => 'Test webhook secret'],
        'paymongo.live_public_key' => ['type' => 'key', 'prefix' => 'pk_live_', 'label' => 'Live public key'],
        'paymongo.live_secret_key' => ['type' => 'key', 'prefix' => 'sk_live_', 'secret' => true, 'label' => 'Live secret key'],
        'paymongo.live_webhook_secret' => ['type' => 'key', 'prefix' => 'whsk_', 'secret' => true, 'label' => 'Live webhook secret'],
        'paymongo.app_url' => ['type' => 'url', 'label' => 'App URL (HTTPS)'],
        'paymongo.qr_flow' => ['type' => 'enum', 'values' => ['inapp', 'checkout'], 'label' => 'QR Ph display'],
        'fees.buy_credits_centavos' => ['type' => 'int', 'min' => 0, 'max' => 100000, 'label' => 'Buy Credits service fee'],
        'fees.mctc_conversion_credits' => ['type' => 'int', 'min' => 0, 'max' => 1000, 'label' => 'MCTC cash-out fee (Credits)'],
        'maps.browser_key' => ['type' => 'regex', 'pattern' => '/^[A-Za-z0-9_-]{20,80}$/D', 'label' => 'Google Maps browser key'],
        'maps.map_id' => ['type' => 'regex', 'pattern' => '/^[A-Za-z0-9_-]{1,64}$/D', 'label' => 'Google Maps Map ID'],
        'maps.default_lat' => ['type' => 'decimal', 'min' => -90, 'max' => 90, 'label' => 'Default map latitude'],
        'maps.default_lng' => ['type' => 'decimal', 'min' => -180, 'max' => 180, 'label' => 'Default map longitude'],
        'maps.default_zoom' => ['type' => 'int', 'min' => 3, 'max' => 20, 'label' => 'Default map zoom'],
        'p2m.min_centavos' => ['type' => 'int', 'min' => 100, 'max' => 100000000, 'label' => 'Minimum merchant payment'],
        'p2m.max_centavos' => ['type' => 'int', 'min' => 100, 'max' => 100000000, 'label' => 'Maximum merchant payment'],
    ];
    foreach (['credits' => 'Credits', 'boracay_cash' => 'Boracay Cash'] as $source => $label) {
        foreach ($p2m($label) as $key => $spec) $schema["p2m.$source.$key"] = $spec;
    }
    foreach (['balabag' => 'Balabag', 'manoc_manoc' => 'Manoc-Manoc', 'yapak' => 'Yapak'] as $slug => $label) {
        $schema["brgy.$slug.captain"] = ['type' => 'text', 'max' => 120, 'label' => "$label Punong Barangay"];
        $schema["brgy.$slug.secretary"] = ['type' => 'text', 'max' => 120, 'label' => "$label Barangay Secretary"];
        $schema["brgy.$slug.address"] = ['type' => 'text', 'max' => 200, 'label' => "$label barangay hall address"];
        $schema["brgy.$slug.contact"] = ['type' => 'text', 'max' => 80, 'label' => "$label contact number"];
    }
    // URide fare matrix and driver commission (Admin > Settings > URide).
    foreach (['e_trike' => 'E-Trike', 'motorcycle' => 'Motorcycle', 'car' => 'Car'] as $v => $label) {
        $schema["uride.$v.enabled"] = ['type' => 'bool', 'label' => "$label rides enabled"];
        $schema["uride.$v.base_centavos"] = ['type' => 'int', 'min' => 0, 'max' => 10000000, 'label' => "$label base fare"];
        $schema["uride.$v.included_km"] = ['type' => 'decimal', 'min' => 0, 'max' => 50, 'label' => "$label km included in base fare"];
        $schema["uride.$v.per_km_centavos"] = ['type' => 'int', 'min' => 0, 'max' => 1000000, 'label' => "$label per km"];
        $schema["uride.$v.minimum_centavos"] = ['type' => 'int', 'min' => 0, 'max' => 10000000, 'label' => "$label minimum fare"];
    }
    $schema += [
        'uride.enabled' => ['type' => 'bool', 'label' => 'URide bookings enabled'],
        'uride.credits_enabled' => ['type' => 'bool', 'label' => 'Pay rides with Credits'],
        'uride.cash_enabled' => ['type' => 'bool', 'label' => 'Pay rides with cash'],
        'uride.road_factor' => ['type' => 'decimal', 'min' => 1, 'max' => 3, 'label' => 'Road distance factor'],
        'uride.night_surcharge_bp' => ['type' => 'int', 'min' => 0, 'max' => 10000, 'label' => 'Night surcharge (%)'],
        'uride.night_start' => ['type' => 'int', 'min' => 0, 'max' => 23, 'label' => 'Night surcharge starts (hour)'],
        'uride.night_end' => ['type' => 'int', 'min' => 0, 'max' => 23, 'label' => 'Night surcharge ends (hour)'],
        'uride.commission_type' => ['type' => 'enum', 'values' => ['percent', 'fixed'], 'label' => 'Commission type'],
        'uride.commission_percent_bp' => ['type' => 'int', 'min' => 0, 'max' => 5000, 'label' => 'Commission (%)'],
        'uride.commission_fixed_centavos' => ['type' => 'int', 'min' => 0, 'max' => 1000000, 'label' => 'Commission per trip'],
        'uride.dispatch_radius_km' => ['type' => 'decimal', 'min' => 0.3, 'max' => 50, 'label' => 'Broadcast radius (km)'],
        'uride.request_timeout_min' => ['type' => 'int', 'min' => 1, 'max' => 60, 'label' => 'Request expires after (minutes)'],
        'uride.min_wallet_centavos' => ['type' => 'int', 'min' => 0, 'max' => 10000000, 'label' => 'Minimum driver wallet to go online'],
        'uride.topup_min_centavos' => ['type' => 'int', 'min' => 100, 'max' => 10000000, 'label' => 'Minimum driver top-up'],
        'uride.topup_max_centavos' => ['type' => 'int', 'min' => 100, 'max' => 10000000, 'label' => 'Maximum driver top-up'],
        'uride.topup_fee_centavos' => ['type' => 'int', 'min' => 0, 'max' => 100000, 'label' => 'Driver top-up service fee'],
        'uride.topup_qrph_enabled' => ['type' => 'bool', 'label' => 'Driver top-up with QR Ph'],
        'mctc.all_approved' => ['type' => 'bool', 'label' => 'Every approved merchant is an MCTC top-up center'],
        'uride.topup_mctc_enabled' => ['type' => 'bool', 'label' => 'Driver top-up at MCTC'],
        'uride.topup_bcash_enabled' => ['type' => 'bool', 'label' => 'Driver top-up with Boracay Cash'],
        'uride.payout_min_centavos' => ['type' => 'int', 'min' => 100, 'max' => 10000000, 'label' => 'Minimum driver payout'],
    ];
    // Agents Portal referral commissions (Admin > Agents > Commission rates).
    foreach (['uride' => 'URide', 'upass' => 'UPass', 'ugo' => 'UGo', 'ulocal' => 'ULocal', 'ueat' => 'UEat'] as $svc => $label) {
        $schema["agents.$svc.percent_bp"] = ['type' => 'int', 'min' => 0, 'max' => 5000, 'label' => "$label agent commission (%)"];
        $schema["agents.$svc.fixed_centavos"] = ['type' => 'int', 'min' => 0, 'max' => 10000000, 'label' => "$label agent commission per activity"];
    }
    $schema += [
        'agents.enabled' => ['type' => 'bool', 'label' => 'Agent commissions enabled'],
        'agents.earn_days' => ['type' => 'int', 'min' => 0, 'max' => 3650, 'label' => 'Agent earns for (days after sign-up, 0 = always)'],
        'agents.cookie_days' => ['type' => 'int', 'min' => 1, 'max' => 365, 'label' => 'Referral link remembered for (days)'],
        'agents.hold_days' => ['type' => 'int', 'min' => 0, 'max' => 60, 'label' => 'UEat commission holding period (days)'],
        'agents.payout_min_centavos' => ['type' => 'int', 'min' => 100, 'max' => 10000000, 'label' => 'Minimum agent payout'],
    ];
    return $schema;
}

function settings_all(bool $refresh = false): array
{
    static $cache = null;
    global $pdo;
    if ($cache !== null && !$refresh) return $cache;
    $cache = [];
    if (!($pdo instanceof PDO)) return $cache;
    try {
        foreach ($pdo->query('SELECT setting_key, setting_value, is_secret FROM app_settings') as $row) {
            $value = $row['setting_value'];
            if ((int) $row['is_secret'] === 1 && is_string($value) && $value !== '') {
                try { $value = settings_decrypt($value); } catch (Throwable $error) {
                    error_log('Setting ' . $row['setting_key'] . ' could not be decrypted.');
                    $value = null;
                }
            }
            $cache[$row['setting_key']] = $value;
        }
    } catch (PDOException $error) {
        // app_settings not migrated yet: fall back to file configuration.
        $cache = [];
    }
    return $cache;
}

function setting(string $key, mixed $default = null): mixed
{
    $all = settings_all();
    return array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '' ? $all[$key] : $default;
}

function setting_int(string $key, int $default): int
{
    $value = setting($key);
    return is_numeric($value) ? (int) $value : $default;
}

/** Validates and saves one setting. Empty secrets are ignored (keeps the stored value). */
function setting_save(PDO $pdo, string $key, string $value, ?int $adminId): bool
{
    $schema = settings_schema();
    if (!isset($schema[$key])) throw new InvalidArgumentException('Unknown setting.');
    $spec = $schema[$key];
    $value = trim($value);
    $secret = !empty($spec['secret']);
    if ($secret && $value === '') return false;
    switch ($spec['type']) {
        case 'enum':
            if (!in_array($value, $spec['values'], true)) throw new InvalidArgumentException($spec['label'] . ' has an invalid value.');
            break;
        case 'bool':
            $value = $value === '1' ? '1' : '0';
            break;
        case 'int':
            if (!preg_match('/^\d{1,9}$/D', $value) || (int) $value < $spec['min'] || (int) $value > $spec['max']) {
                throw new InvalidArgumentException($spec['label'] . ' is out of range.');
            }
            $value = (string) (int) $value;
            break;
        case 'key':
            if ($value !== '' && (!str_starts_with($value, $spec['prefix']) || !preg_match('/^[A-Za-z0-9_]{12,200}$/D', $value))) {
                throw new InvalidArgumentException($spec['label'] . ' must start with ' . $spec['prefix'] . '.');
            }
            break;
        case 'text':
            $value = preg_replace('/\s+/u', ' ', $value) ?? '';
            if (mb_strlen($value) > $spec['max']) throw new InvalidArgumentException($spec['label'] . ' is too long.');
            break;
        case 'regex':
            if ($value !== '' && !preg_match($spec['pattern'], $value)) throw new InvalidArgumentException($spec['label'] . ' has an invalid format.');
            break;
        case 'decimal':
            if (!preg_match('/^-?\d{1,3}(?:\.\d{1,8})?$/D', $value) || (float) $value < $spec['min'] || (float) $value > $spec['max']) {
                throw new InvalidArgumentException($spec['label'] . ' is out of range.');
            }
            break;
        case 'url':
            if ($value !== '' && (parse_url($value, PHP_URL_SCHEME) !== 'https' || !filter_var($value, FILTER_VALIDATE_URL))) {
                throw new InvalidArgumentException($spec['label'] . ' must be an https:// URL.');
            }
            $value = app_base_url($value);
            break;
    }
    $stored = $secret && $value !== '' ? settings_encrypt($value) : $value;
    $stmt = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value, is_secret, updated_by) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_secret = VALUES(is_secret), updated_by = VALUES(updated_by)');
    $stmt->execute([$key, $stored, $secret ? 1 : 0, $adminId]);
    settings_all(true);
    return true;
}

function setting_clear(PDO $pdo, string $key): void
{
    if (!isset(settings_schema()[$key])) throw new InvalidArgumentException('Unknown setting.');
    $pdo->prepare('DELETE FROM app_settings WHERE setting_key = ?')->execute([$key]);
    settings_all(true);
}

function settings_mask(?string $value): string
{
    if ($value === null || $value === '') return 'Not set';
    return substr($value, 0, 8) . str_repeat('•', 6) . substr($value, -4);
}

/* ---------- Encryption ---------- */

function settings_secret_key(): string
{
    static $key = null;
    if ($key !== null) return $key;
    $env = getenv('APP_SECRET_KEY');
    if (is_string($env) && strlen($env) === 64 && ctype_xdigit($env)) return $key = hex2bin($env);
    $file = __DIR__ . '/../config/app_secret.php';
    if (is_file($file)) {
        $hex = require $file;
        if (is_string($hex) && strlen($hex) === 64 && ctype_xdigit($hex)) return $key = hex2bin($hex);
        throw new RuntimeException('config/app_secret.php is invalid.');
    }
    $hex = bin2hex(random_bytes(32));
    $php = "<?php\n// Encryption key for secret settings. Keep this file; losing it means re-entering API keys.\nreturn '" . $hex . "';\n";
    if (@file_put_contents($file, $php, LOCK_EX) === false) {
        throw new RuntimeException('Cannot create config/app_secret.php. Make the config folder writable or set APP_SECRET_KEY.');
    }
    @chmod($file, 0600);
    return $key = hex2bin($hex);
}

function settings_encrypt(string $plain): string
{
    $key = settings_secret_key();
    // AES-256-GCM via OpenSSL works on shared hosting without the sodium extension.
    if (function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'ua-settings', 16);
        if ($cipher === false) throw new RuntimeException('Could not encrypt the secret.');
        return 'enc:v2:' . base64_encode($iv . $tag . $cipher);
    }
    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(24);
        return 'enc:v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }
    throw new RuntimeException('This server has neither OpenSSL AES-GCM nor sodium, so secret keys cannot be stored securely. Keep them in config/paymongo.php instead.');
}

function settings_decrypt(string $stored): string
{
    if (str_starts_with($stored, 'enc:v2:')) {
        $raw = base64_decode(substr($stored, 7), true);
        if ($raw === false || strlen($raw) <= 28) throw new RuntimeException('Bad secret.');
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', settings_secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'ua-settings');
        if ($plain === false) throw new RuntimeException('Secret could not be decrypted.');
        return $plain;
    }
    if (str_starts_with($stored, 'enc:v1:')) {
        if (!function_exists('sodium_crypto_secretbox_open')) throw new RuntimeException('Secret was saved with sodium, which this server lacks. Re-enter it.');
        $raw = base64_decode(substr($stored, 7), true);
        if ($raw === false || strlen($raw) <= 24) throw new RuntimeException('Bad secret.');
        $plain = sodium_crypto_secretbox_open(substr($raw, 24), substr($raw, 0, 24), settings_secret_key());
        if ($plain === false) throw new RuntimeException('Secret could not be decrypted.');
        return $plain;
    }
    return $stored;
}

/* ---------- Fee configuration used across the app ---------- */

/** Top-up and MCTC fees: config/fees.php defaults, overridden by Admin settings. */
function app_fees(): array
{
    $fees = require __DIR__ . '/../config/fees.php';
    $fees['buy_credits_centavos'] = setting_int('fees.buy_credits_centavos', (int) $fees['buy_credits_centavos']);
    $fees['mctc_conversion_credits'] = setting_int('fees.mctc_conversion_credits', (int) $fees['mctc_conversion_credits']);
    return $fees;
}

/** P2M charge rule for a funding source ('credits' or 'boracay_cash'). */
function p2m_fee_rule(string $source): array
{
    if (!in_array($source, ['credits', 'boracay_cash'], true)) throw new InvalidArgumentException('Unknown funding source.');
    return [
        'enabled' => setting("p2m.$source.enabled", '1') === '1',
        'percent_bp' => setting_int("p2m.$source.percent_bp", 0),
        'fixed_centavos' => setting_int("p2m.$source.fixed_centavos", 0),
        'bearer' => setting("p2m.$source.bearer", 'customer') === 'merchant' ? 'merchant' : 'customer',
    ];
}

/** Charge for an amount, rounded half-up to the centavo. */
function p2m_fee_for(int $amountCentavos, array $rule): int
{
    return intdiv($amountCentavos * $rule['percent_bp'] + 5000, 10000) + $rule['fixed_centavos'];
}

/** PayMongo configuration: config/paymongo.php (or environment) overridden by Admin > Settings > Payments. */
function paymongo_settings(): array
{
    $config = require __DIR__ . '/../config/paymongo.php';
    foreach (['mode', 'test_secret_key', 'test_public_key', 'live_secret_key', 'live_public_key', 'test_webhook_secret', 'live_webhook_secret', 'app_url'] as $key) {
        $value = setting('paymongo.' . $key);
        if (is_string($value) && $value !== '') $config[$key] = $value;
    }
    $config['app_url'] = app_base_url((string) $config['app_url']);
    return $config;
}

/** Base URL of the app: drops a pasted file name such as /webhook.php or /index.php, query and trailing slash. */
function app_base_url(string $url): string
{
    $url = trim(preg_replace('/[?#].*$/s', '', $url) ?? '');
    $url = preg_replace('#/[^/]+\.php(?:/.*)?$#i', '', $url) ?? $url;
    return rtrim($url, '/');
}

/** Google Maps: config/google_maps.php (or environment) overridden by Admin > Settings > Maps. */
function google_maps_settings(): array
{
    $config = require __DIR__ . '/../config/google_maps.php';
    $key = (string) setting('maps.browser_key', $config['browser_key'] ?? '');
    return [
        'browser_key' => $key,
        'map_id' => (string) setting('maps.map_id', $config['map_id'] ?? 'DEMO_MAP_ID'),
        'enabled' => (bool) preg_match('/^[A-Za-z0-9_-]{20,80}$/D', $key) && !str_starts_with($key, 'YOUR_'),
        'lat' => (float) setting('maps.default_lat', '11.9674'),
        'lng' => (float) setting('maps.default_lng', '121.9248'),
        'zoom' => setting_int('maps.default_zoom', 14),
    ];
}

/** data-* attributes for assets/js/gmaps.js */
function google_maps_attrs(): string
{
    $g = google_maps_settings();
    return 'data-gm-key="' . htmlspecialchars($g['enabled'] ? $g['browser_key'] : '', ENT_QUOTES) . '" data-gm-map-id="' . htmlspecialchars($g['map_id'], ENT_QUOTES)
        . '" data-gm-lat="' . $g['lat'] . '" data-gm-lng="' . $g['lng'] . '" data-gm-zoom="' . $g['zoom'] . '"';
}

/**
 * Folder for uploaded files (selfies, IDs, permits, driver documents).
 * Default: <app>/storage. To keep uploads safe when the app folder is replaced on deploy, or to share them
 * between two domains that use the same database, set UAPP_STORAGE_DIR or create config/storage.php
 * returning an absolute path (ideally outside public_html).
 */
function app_storage_root(): string
{
    static $root = null;
    if ($root !== null) return $root;
    $path = getenv('UAPP_STORAGE_DIR') ?: '';
    if ($path === '' && is_file(__DIR__ . '/../config/storage.php')) {
        $cfg = require __DIR__ . '/../config/storage.php';
        if (is_string($cfg)) $path = $cfg;
    }
    return $root = rtrim($path !== '' ? $path : __DIR__ . '/../storage', '/\\');
}

function app_storage_dir(string $sub): string
{
    if (!preg_match('#^[a-z_]+(?:/[0-9]+)?$#D', $sub)) throw new InvalidArgumentException('Invalid storage folder.');
    $root = app_storage_root();
    if (!is_dir($root) && @mkdir($root, 0750, true)) @file_put_contents($root . '/.htaccess', "Require all denied\n");
    $dir = $root . '/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Cannot create storage folder ' . $sub . '.');
    return $dir;
}
