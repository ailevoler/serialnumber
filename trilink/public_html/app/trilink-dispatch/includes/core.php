<?php
// ============================================================
// TRILINK DISPATCH – includes/core.php
// ============================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Dispatch.php';

class DB {
    public static function get(): PDO { return Dispatch::db(); }
    public static function run($sql, $p = []) { $s = self::get()->prepare($sql); $s->execute($p); return $s; }
    public static function row($sql, $p = []) { $r = self::run($sql, $p)->fetch(); return $r ?: null; }
    public static function all($sql, $p = []) { return self::run($sql, $p)->fetchAll(); }
    public static function val($sql, $p = []) { return self::run($sql, $p)->fetchColumn(); }
}

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 86400 * 7, 'path' => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}

function csrfToken(): string {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    return $_SESSION[CSRF_TOKEN_NAME];
}
function verifyCsrf($t): bool { return hash_equals($_SESSION[CSRF_TOKEN_NAME] ?? '', (string)$t); }
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function jsonResponse($ok, $msg = '', $data = null, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $ok, 'message' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function setting($k, $d = null) {
    $v = DB::val("SELECT `value` FROM tl_settings WHERE `key`=?", [$k]);
    return $v !== false ? $v : $d;
}
function normalizePhone($p) {
    $p = preg_replace('/\D/', '', (string)$p);
    if (strlen($p) === 10 && $p[0] === '9') return '0' . $p;
    if (strlen($p) === 12 && substr($p, 0, 2) === '63') return '0' . substr($p, 2);
    return $p;
}

/** Logged-in dispatcher (with terminal), or null. Suspended accounts / inactive terminals are logged out. */
function currentDispatcher(): ?array {
    $id = (int)($_SESSION['tl_dispatcher_id'] ?? 0);
    if (!$id) return null;
    $d = DB::row(
        "SELECT ds.id, ds.dispatcher_code, ds.full_name, ds.phone, ds.status, ds.terminal_id,
                t.name AS terminal_name, t.address AS terminal_address, t.terminal_code, t.is_active AS terminal_active
         FROM tl_dispatchers ds JOIN tl_terminals t ON t.id=ds.terminal_id WHERE ds.id=?",
        [$id]
    );
    if (!$d || $d['status'] !== 'active' || !(int)$d['terminal_active']) return null;
    return $d;
}
function dispatcherCheck(bool $json = false): array {
    $d = currentDispatcher();
    if (!$d) {
        if ($json) jsonResponse(false, 'session_expired', null, 401);
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
    return $d;
}
function dispatcherLogout() {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . APP_URL . '/login.php');
    exit;
}
