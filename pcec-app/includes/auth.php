<?php
const REMEMBER_COOKIE = 'pcec_remember';
const REMEMBER_DAYS = 30;

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = empty($_SESSION['uid']) ? null
            : q_one('SELECT * FROM users WHERE id = ?', [$_SESSION['uid']]);
    }
    return $user;
}

function uid(): int
{
    return (int) ($_SESSION['uid'] ?? 0);
}

function require_login(): array
{
    $u = current_user();
    if ($u && $u['status'] !== 'approved') {
        // Suspended (or not yet confirmed) accounts can't use the app.
        $msg = $u['status'] === 'suspended'
            ? 'This account is suspended. Please contact the PCEC office if you think this is a mistake.'
            : 'Please confirm your email address first. We sent you a link when you signed up.';
        logout_user();
        session_start();
        flash('error', $msg);
        $u = null;
    }
    if (!$u) {
        if (str_contains($_SERVER['SCRIPT_NAME'], '/api/')) {
            json_out(['error' => 'Not logged in'], 401);
        }
        redirect('login.php');
    }
    return $u;
}

/** Members can use the app once their email is confirmed and they are not suspended. */
function can_use_app(?array $u): bool
{
    return $u !== null && $u['status'] === 'approved';
}

/** "New Member" for the first 30 days, until an admin marks them as reviewed. */
function is_new_member(array $u): bool
{
    return !$u['reviewed_at'] && strtotime((string) $u['created_at']) > time() - 30 * 86400;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

function is_leader(): bool
{
    return in_array(current_user()['role'] ?? '', ['admin', 'leader'], true);
}

function login_user(int $userId, bool $remember): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $userId;
    if ($remember) {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        q('INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at) VALUES (?,?,?, NOW() + INTERVAL ' . REMEMBER_DAYS . ' DAY)',
            [$userId, $selector, hash('sha256', $validator)]);
        setcookie(REMEMBER_COOKIE, $selector . ':' . $validator, [
            'expires' => time() + REMEMBER_DAYS * 86400, 'path' => '/', 'httponly' => true,
            'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']),
        ]);
    }
}

function logout_user(): void
{
    if (!empty($_COOKIE[REMEMBER_COOKIE])) {
        [$selector] = explode(':', $_COOKIE[REMEMBER_COOKIE]) + [''];
        q('DELETE FROM remember_tokens WHERE selector = ?', [$selector]);
        setcookie(REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
    }
    $_SESSION = [];
    session_destroy();
}

/** Restore a session from the remember-me cookie and track last activity. */
function auth_bootstrap(): void
{
    if (empty($_SESSION['uid']) && !empty($_COOKIE[REMEMBER_COOKIE])) {
        [$selector, $validator] = explode(':', (string) $_COOKIE[REMEMBER_COOKIE]) + ['', ''];
        $row = q_one('SELECT * FROM remember_tokens WHERE selector = ? AND expires_at > NOW()', [$selector]);
        if ($row && hash_equals($row['token_hash'], hash('sha256', $validator))) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int) $row['user_id'];
        } else {
            setcookie(REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
        }
    }
    if (!empty($_SESSION['uid']) && (empty($_SESSION['seen']) || time() - $_SESSION['seen'] > 60)) {
        $_SESSION['seen'] = time();
        q('UPDATE users SET last_seen = NOW() WHERE id = ?', [$_SESSION['uid']]);
    }
}
