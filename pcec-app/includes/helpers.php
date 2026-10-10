<?php
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Session expired. Please go back and try again.');
    }
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function old(string $key): string
{
    return e($_POST[$key] ?? '');
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function time_ago(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    $units = [31536000 => 'year', 2592000 => 'month', 604800 => 'week', 86400 => 'day', 3600 => 'hour', 60 => 'minute'];
    foreach ($units as $secs => $name) {
        if ($diff >= $secs) {
            $n = intdiv($diff, $secs);
            return $n . ' ' . $name . ($n > 1 ? 's' : '') . ' ago';
        }
    }
    return 'just now';
}

function display_name(array $u): string
{
    return trim(($u['title'] ? $u['title'] . ' ' : '') . $u['first_name'] . ' ' . $u['last_name']);
}

function initials(array $u): string
{
    return mb_strtoupper(mb_substr($u['first_name'], 0, 1) . mb_substr($u['last_name'], 0, 1));
}

/** Avatar image, or a coloured initials bubble when the user has no photo. */
function avatar(array $u, string $size = 'md'): string
{
    $cls = 'avatar avatar-' . $size;
    if (!empty($u['avatar'])) {
        return '<img class="' . $cls . '" src="' . e(url($u['avatar'])) . '" alt="' . e(display_name($u)) . '">';
    }
    $hue = (crc32($u['username'] ?? $u['first_name']) % 360);
    return '<span class="' . $cls . ' avatar-initials" style="--h:' . $hue . '">' . e(initials($u)) . '</span>';
}

/** Linkify URLs and keep line breaks in user text. */
function rich_text(?string $s): string
{
    $s = e($s);
    $s = preg_replace('~(https?://[^\s<]+)~i', '<a href="$1" target="_blank" rel="noopener">$1</a>', $s);
    return nl2br($s);
}

/**
 * Save an uploaded file into uploads/<folder>. Returns [relative path, kind, original name]
 * or null when nothing was uploaded. Throws RuntimeException on invalid files.
 */
function handle_upload(string $field, string $folder, array $allowedKinds = ['image', 'video', 'file']): ?array
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (code ' . $f['error'] . ').');
    }
    if ($f['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('File is too large (max 20 MB).');
    }
    $map = [
        'image/jpeg' => ['jpg', 'image'], 'image/png' => ['png', 'image'], 'image/gif' => ['gif', 'image'],
        'image/webp' => ['webp', 'image'],
        'video/mp4' => ['mp4', 'video'], 'video/webm' => ['webm', 'video'], 'video/quicktime' => ['mov', 'video'],
        'application/pdf' => ['pdf', 'file'],
        'application/msword' => ['doc', 'file'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx', 'file'],
        'application/vnd.ms-powerpoint' => ['ppt', 'file'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx', 'file'],
        'application/vnd.ms-excel' => ['xls', 'file'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx', 'file'],
        'text/plain' => ['txt', 'file'],
        'application/zip' => ['zip', 'file'],
    ];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!isset($map[$mime]) || !in_array($map[$mime][1], $allowedKinds, true)) {
        throw new RuntimeException('This file type is not allowed.');
    }
    [$ext, $kind] = $map[$mime];
    $dir = UPLOAD_DIR . '/' . $folder;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Could not save the uploaded file.');
    }
    return ['uploads/' . $folder . '/' . $name, $kind, mb_substr(basename($f['name']), 0, 255)];
}

function notify(int $userId, ?int $actorId, string $type, string $message, ?string $link = null): void
{
    if ($userId === $actorId) {
        return;
    }
    q('INSERT INTO notifications (user_id, actor_id, type, message, link) VALUES (?,?,?,?,?)',
        [$userId, $actorId, $type, $message, $link]);
}

/** Inline SVG icon from a small built-in set (keeps the app dependency-free). */
function icon(string $name, string $cls = ''): string
{
    static $paths = [
        'home' => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h2M14 14h2M8 17h2M14 17h2"/>',
        'plus-square' => '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M12 8v8M8 12h8"/>',
        'chat' => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.4A8 8 0 1 1 21 12z"/><circle cx="8.5" cy="12" r=".6" fill="currentColor"/><circle cx="12" cy="12" r=".6" fill="currentColor"/><circle cx="15.5" cy="12" r=".6" fill="currentColor"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'bell' => '<path d="M18 16V11a6 6 0 1 0-12 0v5l-2 2h16z"/><path d="M10 21a2 2 0 0 0 4 0"/>',
        'church' => '<path d="M12 2v4M10 4h4"/><path d="M6 21V11l6-5 6 5v10"/><path d="M3 21h18"/><path d="M10 21v-4a2 2 0 0 1 4 0v4"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-6 7-6s7 2.5 7 6"/><circle cx="17" cy="9" r="2.5"/><path d="M16 14c3.5 0 6 2 6 5"/>',
        'book' => '<path d="M2 5c3-1.5 6-1.5 10 1 4-2.5 7-2.5 10-1v14c-3-1.5-6-1.5-10 1-4-2.5-7-2.5-10-1z"/><path d="M12 6v14"/>',
        'pray' => '<path d="M12 3c-1 0-2 1-2 2.5V12l-4 4v5h4l2-3 2 3h4v-5l-4-4V5.5C14 4 13 3 12 3z"/>',
        'edit' => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M14 6l4 4"/>',
        'user-plus' => '<circle cx="9" cy="8" r="4"/><path d="M2 21c0-4 3-7 7-7s7 3 7 7"/><path d="M19 8v6M16 11h6"/>',
        'camera' => '<path d="M4 7h3l2-3h6l2 3h3a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1z"/><circle cx="12" cy="13" r="4"/>',
        'video' => '<rect x="2" y="6" width="14" height="12" rx="2"/><path d="M16 10l6-3v10l-6-3z"/>',
        'file' => '<path d="M6 2h8l6 6v14H6z"/><path d="M14 2v6h6M9 13h6M9 17h6"/>',
        'heart' => '<path d="M12 21s-8-5.2-8-11a4.5 4.5 0 0 1 8-2.8A4.5 4.5 0 0 1 20 10c0 5.8-8 11-8 11z"/>',
        'comment' => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.4A8 8 0 1 1 21 12z"/>',
        'share' => '<path d="M14 5l7 7-7 7v-4c-6 0-9 2-11 5 1-6 4-10 11-11z"/>',
        'bookmark' => '<path d="M6 3h12v18l-6-4-6 4z"/>',
        'dots' => '<circle cx="5" cy="12" r="1.5" fill="currentColor"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/><circle cx="19" cy="12" r="1.5" fill="currentColor"/>',
        'chevron-right' => '<path d="M9 5l7 7-7 7"/>',
        'chevron-down' => '<path d="M6 9l6 6 6-6"/>',
        'arrow-left' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'pin' => '<path d="M12 22s7-6.5 7-12a7 7 0 1 0-14 0c0 5.5 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'lock' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.5-7 8-7s8 3 8 7"/>',
        'eye' => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><path d="M3 3l18 18"/>',
        'login' => '<path d="M10 17l5-5-5-5M15 12H3"/><path d="M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/>',
        'logout' => '<path d="M14 17l5-5-5-5M19 12H8"/><path d="M10 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h5"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-5-5"/>',
        'send' => '<path d="M3 11l18-8-8 18-2-8z"/>',
        'download' => '<path d="M12 3v12M7 10l5 5 5-5M4 21h16"/>',
        'link' => '<path d="M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/>',
        'check' => '<path d="M5 12l5 5 9-10"/>',
        'x' => '<path d="M6 6l12 12M18 6L6 18"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'trash' => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        'gift' => '<path d="M12 21s-7-4.5-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 5.5-7 10-7 10z"/><path d="M12 8v6M9 11h6"/>',
        'bank' => '<path d="M3 10l9-6 9 6"/><path d="M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 21h18"/>',
        'card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>',
        'wallet' => '<path d="M3 7a2 2 0 0 1 2-2h12v4"/><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M16 13.5h2"/>',
        'qr' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3M21 14v.01M17 21h4v-4M14 18v3"/>',
        'copy' => '<rect x="8" y="8" width="13" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/>',
        'star' => '<path d="M12 3l2.8 5.7 6.2.9-4.5 4.4 1 6.2L12 17.3 6.5 20.2l1-6.2L3 9.6l6.2-.9z"/>',
        'music' => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
        'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'receipt' => '<path d="M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
        'repeat' => '<path d="M17 2l4 4-4 4"/><path d="M3 11V9a3 3 0 0 1 3-3h15M7 22l-4-4 4-4"/><path d="M21 13v2a3 3 0 0 1-3 3H3"/>',
        'target' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'mic' => '<rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10a7 7 0 0 0 14 0M12 17v5M8 22h8"/>',
        'tree' => '<path d="M12 2l6 9h-4l5 7H5l5-7H6z"/><path d="M12 18v4"/>',
        'refresh' => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
        'external' => '<path d="M14 3h7v7M21 3l-9 9"/><path d="M19 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h5"/>',
        'chart' => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
        'phone' => '<path d="M5 3h4l2 5-3 2a11 11 0 0 0 6 6l2-3 5 2v4a2 2 0 0 1-2 2A17 17 0 0 1 3 5a2 2 0 0 1 2-2z"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg class="icon ' . e($cls) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

function brand_icon(string $name): string
{
    if ($name === 'google') {
        return '<svg class="brand" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>';
    }
    return '<svg class="brand" viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="24" r="20" fill="#fff"/><path fill="#1877F2" d="M24 4C13 4 4 13 4 24c0 10 7.3 18.3 16.9 19.8V29.8h-5.1V24h5.1v-4.4c0-5 3-7.8 7.6-7.8 2.2 0 4.5.4 4.5.4v4.9h-2.5c-2.5 0-3.3 1.6-3.3 3.1V24h5.6l-.9 5.8h-4.7v14C36.7 42.3 44 34 44 24 44 13 35 4 24 4z"/></svg>';
}

/** PCEC wordmark: globe + church window outline. */
function pcec_logo(string $cls = ''): string
{
    return '<svg class="pcec-logo ' . e($cls) . '" viewBox="0 0 200 112" fill="none" stroke="currentColor" stroke-width="4.5" stroke-linejoin="round" role="img" aria-label="PCEC">'
        . '<ellipse cx="72" cy="38" rx="64" ry="32"/>'
        . '<path d="M8 38h110M30 18c28 9 60 9 88 0M30 58c28-9 60-9 88 0M52 8c-14 16-14 44 0 60M92 8c14 16 14 44 0 60"/>'
        . '<rect x="118" y="6" width="74" height="98" rx="3"/>'
        . '<path d="M118 38h74M155 38v66"/>'
        . '<text x="4" y="104" font-family="Inter,Segoe UI,Arial,sans-serif" font-size="38" font-weight="800" letter-spacing="-1" fill="currentColor" stroke="none">PCEC</text>'
        . '</svg>';
}

/** "QR Ph" wordmark used on payment options (generic ring mark + text, not the official logo file). */
function qrph_badge(string $cls = ''): string
{
    return '<span class="qrph ' . e($cls) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5" fill="none" stroke-width="4" stroke="url(#qrphg)"/><defs><linearGradient id="qrphg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#1a6cf0"/><stop offset=".35" stop-color="#e5383b"/><stop offset=".7" stop-color="#f5a623"/><stop offset="1" stop-color="#22a650"/></linearGradient></defs></svg><b>QR</b><i>Ph</i></span>';
}

function wants_json(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

/** URL to a static file with a version stamp so browsers and the service worker pick up changes. */
function asset(string $path): string
{
    $file = __DIR__ . '/../' . ltrim($path, '/');
    return url($path) . (is_file($file) ? '?v=' . filemtime($file) : '');
}

/** <head> tags that make every page installable as a PWA. */
function pwa_head(): string
{
    return '<link rel="manifest" href="' . e(url('manifest.json')) . '">'
        . '<meta name="base-url" content="' . e(BASE_URL) . '">'
        . '<link rel="icon" href="' . e(url('assets/img/favicon.svg')) . '" type="image/svg+xml">'
        . '<link rel="icon" href="' . e(url('assets/icons/favicon-32.png')) . '" type="image/png" sizes="32x32">'
        . '<link rel="apple-touch-icon" href="' . e(url('assets/icons/apple-touch-icon.png')) . '">'
        . '<meta name="mobile-web-app-capable" content="yes">'
        . '<meta name="apple-mobile-web-app-capable" content="yes">'
        . '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">'
        . '<meta name="apple-mobile-web-app-title" content="PCEC">'
        . '<meta name="application-name" content="PCEC">';
}
