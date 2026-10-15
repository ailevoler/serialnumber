<?php
declare(strict_types=1);

function current_user(bool $refresh = false): ?array
{
    static $user = false;
    if (empty($_SESSION['uid'])) {
        return null;
    }
    if ($user === false || $refresh) {
        $user = q_row('SELECT u.*, p.name AS package_name FROM users u LEFT JOIN packages p ON p.id = u.package_id WHERE u.id = ?', [$_SESSION['uid']]);
        if (!$user || $user['status'] === 'blocked') {
            logout_user();
            return null;
        }
    }
    return $user;
}

function attempt_login(string $username, string $password, string $role): ?string
{
    $key = 'login_fail_' . md5(strtolower($username) . client_ip());
    $fails = $_SESSION[$key] ?? ['n' => 0, 't' => 0];
    if ($fails['n'] >= 5 && time() - $fails['t'] < 300) {
        return 'Too many failed attempts. Please wait 5 minutes and try again.';
    }

    $u = q_row('SELECT * FROM users WHERE (username = ? OR email = ?) AND role = ? LIMIT 1', [$username, $username, $role]);
    if (!$u || !password_verify($password, $u['password'])) {
        $_SESSION[$key] = ['n' => $fails['n'] + 1, 't' => time()];
        return 'Invalid username or password.';
    }
    if ($u['status'] === 'blocked') {
        return 'Your account has been blocked. Please contact support.';
    }
    unset($_SESSION[$key]);

    if (password_needs_rehash($u['password'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['role'] = $u['role'];
    q('UPDATE users SET last_login = NOW() WHERE id = ?', [$u['id']]);
    log_activity((int)$u['id'], 'Logged in');
    return null;
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function require_role(string $role): array
{
    $u = current_user();
    if (!$u) {
        redirect($role === 'admin' ? 'admin/login.php' : 'login.php');
    }
    if ($u['role'] !== $role) {
        http_response_code(403);
        exit('Access denied.');
    }
    return $u;
}

function require_member(): array
{
    return require_role('member');
}

function require_admin(): array
{
    return require_role('admin');
}

function validate_password(string $pw): ?string
{
    if (strlen($pw) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
        return 'Password must contain letters and numbers.';
    }
    return null;
}
