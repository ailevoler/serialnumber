<?php
/** Key/value settings stored in the `settings` table, plus encryption for secrets. */

// Fallbacks for installs whose config/config.php predates these settings.
if (!defined('APP_KEY')) define('APP_KEY', (string) getenv('APP_KEY'));
if (!defined('APP_KEY_FILE')) define('APP_KEY_FILE', __DIR__ . '/../config/app.key');

function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (q_all('SELECT k, v FROM settings') as $r) {
            $cache[$r['k']] = $r['v'];
        }
    }
    if (func_num_args() === 3) { // internal: refresh cache entry
        $cache[$key] = $default;
    }
    return $cache[$key] ?? $default;
}

function setting_set(string $key, ?string $value): void
{
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$key, $value]);
    setting($key, $value, true);
}

function app_key(): string
{
    if (APP_KEY !== '') {
        return hash('sha256', APP_KEY, true);
    }
    if (!is_file(APP_KEY_FILE)) {
        if (@file_put_contents(APP_KEY_FILE, bin2hex(random_bytes(32))) === false) {
            throw new RuntimeException('Cannot create ' . basename(dirname(APP_KEY_FILE)) . '/app.key. Make the config folder writable, or set APP_KEY in config/config.php.');
        }
        @chmod(APP_KEY_FILE, 0600);
    }
    $key = trim((string) file_get_contents(APP_KEY_FILE));
    if ($key === '') {
        throw new RuntimeException('config/app.key is empty. Delete it so a new key can be generated.');
    }
    return hash('sha256', $key, true);
}

function secret_encrypt(string $plain): string
{
    $iv = random_bytes(12);
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'enc:' . base64_encode($iv . $tag . $cipher);
}

function secret_decrypt(?string $stored): string
{
    if (!$stored) return '';
    if (!str_starts_with($stored, 'enc:')) return $stored;
    $raw = base64_decode(substr($stored, 4));
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}

function setting_secret(string $key): string
{
    return secret_decrypt(setting($key));
}

function setting_list(string $key, string $default = ''): array
{
    return array_values(array_filter(array_map('trim', explode(',', (string) setting($key, $default))), 'strlen'));
}

/** Format centavos as pesos, e.g. 50000 -> ₱500 or ₱500.50 */
function money(int $centavos, bool $forceDecimals = false): string
{
    $pesos = $centavos / 100;
    $dec = ($forceDecimals || $centavos % 100) ? 2 : 0;
    return '₱' . number_format($pesos, $dec);
}

function mask_secret(string $s): string
{
    return $s === '' ? '' : substr($s, 0, 8) . str_repeat('•', 8) . substr($s, -4);
}
