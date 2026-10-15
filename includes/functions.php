<?php
declare(strict_types=1);

/* ---------- URLs ---------- */

function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    if (BASE_URL !== '') {
        return $base = rtrim(BASE_URL, '/');
    }
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $appRoot = realpath(APP_ROOT) ?: APP_ROOT;
    if ($docRoot !== '' && str_starts_with($appRoot, $docRoot)) {
        $base = str_replace('\\', '/', substr($appRoot, strlen($docRoot)));
    } else {
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $base = preg_replace('#/(member|admin)$#', '', $dir);
    }
    return $base = rtrim((string)$base, '/');
}

function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

function absolute_url(string $path = ''): string
{
    $u = url($path);
    if (preg_match('#^https?://#', $u)) {
        return $u;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $u;
}

function asset(string $path): string
{
    $file = APP_ROOT . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? filemtime($file) : 1;
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function redirect(string $path): never
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

/* ---------- Output helpers ---------- */

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money($amount, bool $withSymbol = true): string
{
    $n = number_format((float)$amount, 2);
    return $withSymbol ? setting('currency', '₱') . $n : $n;
}

function num($n, int $dec = 0): string
{
    return number_format((float)$n, $dec);
}

function fdate(?string $dt, string $fmt = 'M d, Y'): string
{
    return $dt ? date($fmt, strtotime($dt)) : '—';
}

function fdatetime(?string $dt): string
{
    return fdate($dt, 'M d, Y h:i A');
}

function full_name(array $u): string
{
    return trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
}

function initials(array $u): string
{
    return strtoupper(mb_substr($u['first_name'] ?? '?', 0, 1) . mb_substr($u['last_name'] ?? '', 0, 1));
}

function badge(string $status): string
{
    $map = [
        'active' => 'success', 'approved' => 'success', 'paid' => 'success', 'completed' => 'success',
        'published' => 'success', 'unused' => 'success', 'credit' => 'success',
        'pending' => 'warning', 'shipped' => 'info', 'used' => 'muted', 'draft' => 'muted',
        'inactive' => 'muted', 'blocked' => 'danger', 'rejected' => 'danger', 'cancelled' => 'danger',
        'debit' => 'danger',
    ];
    $cls = $map[$status] ?? 'muted';
    return '<span class="badge badge-' . $cls . '">' . e(ucfirst($status)) . '</span>';
}

/* ---------- Settings ---------- */

function settings_all(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        foreach (q_all('SELECT skey, svalue FROM settings') as $r) {
            $cache[$r['skey']] = $r['svalue'];
        }
    }
    return $cache;
}

function setting(string $key, $default = null)
{
    $all = settings_all();
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

function setting_list(string $key): array
{
    $raw = (string)setting($key, '');
    return array_values(array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== ''));
}

function save_setting(string $key, string $value): void
{
    q('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [$key, $value]);
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $token = $_POST['_csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Session expired or invalid request. Please go back and try again.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/* ---------- Flash messages ---------- */

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

/* ---------- Input ---------- */

function input(string $key, $default = ''): string
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : (string)$default;
}

function input_int(string $key, int $default = 0): int
{
    return (int)filter_var(input($key, (string)$default), FILTER_VALIDATE_INT, ['options' => ['default' => $default]]);
}

function input_float(string $key, float $default = 0.0): float
{
    $v = filter_var(input($key, (string)$default), FILTER_VALIDATE_FLOAT);
    return $v === false ? $default : (float)$v;
}

function form_values(array $keys): array
{
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = input($k);
    }
    return $out;
}

/* ---------- Pagination ---------- */

function paginate(string $countSql, array $params, int $perPage = 20): array
{
    $total = (int)q_val($countSql, $params);
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min(max(1, input_int('page', 1)), $pages);
    return ['total' => $total, 'pages' => $pages, 'page' => $page, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage];
}

function pagination_links(array $p): string
{
    if ($p['pages'] <= 1) {
        return '';
    }
    $qs = $_GET;
    $html = '<nav class="pagination">';
    $start = max(1, $p['page'] - 2);
    $end = min($p['pages'], $p['page'] + 2);
    $link = function (int $n, string $label, bool $active = false) use ($qs) {
        $qs['page'] = $n;
        return '<a class="' . ($active ? 'active' : '') . '" href="?' . e(http_build_query($qs)) . '">' . $label . '</a>';
    };
    if ($p['page'] > 1) {
        $html .= $link($p['page'] - 1, '&lsaquo;');
    }
    for ($i = $start; $i <= $end; $i++) {
        $html .= $link($i, (string)$i, $i === $p['page']);
    }
    if ($p['page'] < $p['pages']) {
        $html .= $link($p['page'] + 1, '&rsaquo;');
    }
    return $html . '</nav>';
}

/* ---------- Misc ---------- */

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function log_activity(?int $userId, string $action): void
{
    q('INSERT INTO activity_logs (user_id, action, ip) VALUES (?, ?, ?)', [$userId, mb_substr($action, 0, 255), client_ip()]);
}

function random_string(int $len, string $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'): string
{
    $out = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $len; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}

function earning_types(): array
{
    return [
        'discount' => ['label' => 'Product Discount',          'icon' => 'tag',       'hint' => 'Savings / retail profit'],
        'direct'   => ['label' => 'Direct Referral Bonus',     'icon' => 'user-plus', 'hint' => 'Personally sponsored'],
        'indirect' => ['label' => 'Indirect Bonus',            'icon' => 'users',     'hint' => 'Up to ' . count(setting_list('indirect_levels')) . ' levels'],
        'passup'   => ['label' => 'Pass Up Bonus',             'icon' => 'arrow-up',  'hint' => 'Australian Pass Up'],
        'pairing'  => ['label' => 'Pairing / Matching Bonus',  'icon' => 'git-merge', 'hint' => 'Binary'],
        'unilevel' => ['label' => 'Unilevel Bonus',            'icon' => 'repeat',    'hint' => 'Repeat purchase'],
    ];
}

/** Minimal inline SVG icon set (Feather-style strokes). */
function icon(string $name, int $size = 18): string
{
    static $paths = [
        'home'      => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
        'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user'      => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6M23 11h-6"/>',
        'git-merge' => '<circle cx="18" cy="18" r="3"/><circle cx="6" cy="6" r="3"/><path d="M6 21V9a9 9 0 0 0 9 9"/>',
        'tree'      => '<rect x="9" y="2" width="6" height="5" rx="1"/><rect x="2" y="17" width="6" height="5" rx="1"/><rect x="16" y="17" width="6" height="5" rx="1"/><path d="M12 7v5M5 17v-5h14v5"/>',
        'wallet'    => '<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/>',
        'cash'      => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/>',
        'cart'      => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
        'box'       => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12"/>',
        'list'      => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'key'       => '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.78 7.78 5.5 5.5 0 0 1 7.78-7.78zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
        'chart'     => '<path d="M18 20V10M12 20V4M6 20v-6"/>',
        'settings'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68 1.65 1.65 0 0 0 10 3.17V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'menu'      => '<path d="M3 12h18M3 6h18M3 18h18"/>',
        'x'         => '<path d="M18 6L6 18M6 6l12 12"/>',
        'tag'       => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><path d="M7 7h.01"/>',
        'arrow-up'  => '<path d="M12 19V5M5 12l7-7 7 7"/>',
        'repeat'    => '<path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
        'copy'      => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'bell'      => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0"/>',
        'send'      => '<path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/>',
        'download'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
        'plus'      => '<path d="M12 5v14M5 12h14"/>',
        'search'    => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>',
        'megaphone' => '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'shield'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'package'   => '<path d="M16.5 9.4L7.5 4.21M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12"/>',
        'eye'       => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'card'      => '<rect x="1" y="4" width="22" height="16" rx="2"/><path d="M1 10h22"/>',
        'trending'  => '<path d="M23 6l-9.5 9.5-5-5L1 18"/><path d="M17 6h6v6"/>',
    ];
    $p = $paths[$name] ?? $paths['list'];
    return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

function csv_download(string $filename, array $header, iterable $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header);
    foreach ($rows as $r) {
        fputcsv($out, array_values($r));
    }
    fclose($out);
    exit;
}

/** Save an uploaded image; returns the relative path under uploads/ or null. */
function upload_image(string $field, string $subdir): ?string
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 3 * 1024 * 1024) {
        throw new RuntimeException('Image upload failed (max 3MB).');
    }
    $info = @getimagesize($f['tmp_name']);
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($allowed[$info[2]])) {
        throw new RuntimeException('Only JPG, PNG, WEBP or GIF images are allowed.');
    }
    $dir = UPLOAD_DIR . '/' . $subdir;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $name = bin2hex(random_bytes(10)) . '.' . $allowed[$info[2]];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Could not save uploaded image.');
    }
    return $subdir . '/' . $name;
}

function product_image(?string $path): string
{
    return $path ? url('uploads/' . $path) : asset('img/logo-256.png');
}
