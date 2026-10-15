<?php
// ============================================================
// TRILINK ADMIN SUITE – includes/core.php
// Database, Session, Auth, Permissions, Helpers — include everywhere
// ============================================================

require_once __DIR__ . '/../config.php';

// ── PDO Database Connection (Singleton) ───────────────────────
class DB {
    private static ?PDO $pdo = null;

    public static function connect(): PDO {
        if (self::$pdo === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ];
            try {
                self::$pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                if (APP_ENV === 'development') {
                    die('DB Connection Failed: ' . $e->getMessage());
                }
                die('Service temporarily unavailable.');
            }
        }
        return self::$pdo;
    }

    public static function get(): PDO { return self::connect(); }

    public static function run(string $sql, array $params = []): \PDOStatement {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function row(string $sql, array $params = []): ?array {
        $r = self::run($sql, $params)->fetch();
        return $r ?: null;
    }

    public static function all(string $sql, array $params = []): array {
        return self::run($sql, $params)->fetchAll();
    }

    public static function val(string $sql, array $params = []) {
        return self::run($sql, $params)->fetchColumn();
    }

    public static function lastId(): string {
        return self::connect()->lastInsertId();
    }

    /** Does a table exist? (used so pages degrade gracefully before migrations run) */
    public static function tableExists(string $table): bool {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];
        $r = self::val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?", [$table]);
        return $cache[$table] = ((int)$r > 0);
    }
}

// ── Session Manager ───────────────────────────────────────────
class Session {
    public static function start(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            session_start();
        }
    }
    public static function set(string $key, $val): void { $_SESSION[$key] = $val; }
    public static function get(string $key, $default = null) { return $_SESSION[$key] ?? $default; }
    public static function has(string $key): bool { return isset($_SESSION[$key]); }
    public static function remove(string $key): void { unset($_SESSION[$key]); }
    public static function destroy(): void { $_SESSION = []; session_destroy(); }

    public static function flash(string $key, $val = null) {
        if ($val !== null) { $_SESSION['_flash'][$key] = $val; return null; }
        $v = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $v;
    }

    public static function csrfToken(): string {
        if (empty($_SESSION[CSRF_TOKEN_NAME])) {
            $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        }
        return $_SESSION[CSRF_TOKEN_NAME];
    }
    public static function verifyCsrf(string $token): bool {
        return hash_equals($_SESSION[CSRF_TOKEN_NAME] ?? '', $token);
    }
}
Session::start();

// ── Admin Auth ─────────────────────────────────────────────────
class AdminAuth {
    public static function attempt(string $username, string $password): array {
        $admin = DB::row("SELECT * FROM tl_admins WHERE (username=? OR email=?) LIMIT 1", [$username, $username]);
        if (!$admin) return ['ok' => false, 'msg' => 'Account not found.'];

        if (!empty($admin['locked_until']) && strtotime($admin['locked_until']) > time()) {
            $mins = ceil((strtotime($admin['locked_until']) - time()) / 60);
            return ['ok' => false, 'msg' => "Account locked. Try again in {$mins} min(s)."];
        }
        if ((int)$admin['is_active'] !== 1) {
            return ['ok' => false, 'msg' => 'This account has been deactivated.'];
        }
        if (!password_verify($password, $admin['password'])) {
            $attempts = (int)($admin['login_attempts'] ?? 0) + 1;
            $lockUntil = null;
            if ($attempts >= MAX_LOGIN_ATTEMPTS) {
                $lockUntil = date('Y-m-d H:i:s', time() + LOGIN_LOCK_MINUTES * 60);
            }
            DB::run("UPDATE tl_admins SET login_attempts=?, locked_until=? WHERE id=?", [$attempts, $lockUntil, $admin['id']]);
            return ['ok' => false, 'msg' => 'Incorrect password.'];
        }

        DB::run("UPDATE tl_admins SET login_attempts=0, locked_until=NULL, last_login=NOW() WHERE id=?", [$admin['id']]);
        self::login($admin);
        return ['ok' => true, 'admin' => $admin];
    }

    public static function login(array $admin): void {
        Session::start();
        session_regenerate_id(true);
        Session::set('tla_admin_id',   $admin['id']);
        Session::set('tla_username',   $admin['username']);
        Session::set('tla_full_name',  $admin['full_name']);
        Session::set('tla_role',       $admin['role']);
        Session::set('tla_logged_in',  true);
        Session::set('tla_login_time', time());
    }

    public static function check(): bool {
        return Session::get('tla_logged_in') === true && Session::has('tla_admin_id');
    }

    public static function id(): ?int {
        return self::check() ? (int) Session::get('tla_admin_id') : null;
    }

    public static function role(): string {
        return (string) Session::get('tla_role', 'admin');
    }

    public static function isSuperAdmin(): bool {
        return self::role() === 'super_admin';
    }

    public static function user(): ?array {
        if (!self::check()) return null;
        return DB::row("SELECT id,username,full_name,email,phone,role,department,avatar,last_login FROM tl_admins WHERE id=?", [self::id()]);
    }

    public static function requireLogin(): void {
        if (!self::check()) {
            redirect('/login.php');
        }
    }

    public static function requireSuperAdmin(): void {
        self::requireLogin();
        if (!self::isSuperAdmin()) {
            Session::flash('tla_error', 'Super Administrator access only.');
            redirect('/dashboard.php');
        }
    }

    public static function logout(): void {
        if (self::check()) {
            logActivity('logout', 'auth', 'Admin logged out: ' . Session::get('tla_username'));
        }
        Session::destroy();
        redirect('/login.php');
    }
}

// ── Permissions (role -> module capability matrix, DB-driven) ──
class Permissions {
    /** Hardcoded fallback matrix used if the permissions tables are empty. */
    private static array $fallback = [
        'super_admin' => '*',
        'admin'       => ['dashboard','users','drivers','agents','merchants','trips','deliveries','orders','terminals','transactions','topups','payouts','promos','banners','support','fare','settings',
                          'crm_leads','crm_contacts','crm_accounts','crm_deals','crm_activities','crm_tasks','crm_campaigns','crm_reports',
                          'erp_financial','erp_sales','erp_purchasing','erp_inventory','erp_hr','erp_payroll','erp_projects','erp_assets','erp_maintenance','erp_reports'],
        'manager'     => ['dashboard','users','drivers','agents','merchants','trips','deliveries','orders','terminals','transactions','topups','promos','banners','support',
                          'crm_leads','crm_contacts','crm_accounts','crm_deals','crm_activities','crm_tasks','crm_reports',
                          'erp_sales','erp_inventory','erp_projects','erp_reports'],
        'support'     => ['dashboard','support','crm_leads','crm_contacts','crm_activities','crm_tasks'],
    ];

    public static function can(string $moduleKey): bool {
        if (AdminAuth::isSuperAdmin()) return true;
        $role = AdminAuth::role();

        // Built-in roles use the fast hardcoded matrix.
        if (isset(self::$fallback[$role])) {
            $allowed = self::$fallback[$role];
            if ($allowed === '*') return true;
            return in_array($moduleKey, $allowed, true);
        }

        // Custom roles (created via Super Admin > Roles) are DB-driven.
        $admin = AdminAuth::user();
        if (!$admin || !DB::tableExists('tl_admin_role_permissions')) return false;
        $roleRow = DB::row("SELECT id FROM tl_admin_roles WHERE slug=?", [$role]);
        if (!$roleRow) return false;
        $ok = DB::val(
            "SELECT rp.can_view FROM tl_admin_role_permissions rp
             JOIN tl_admin_permissions p ON p.id = rp.permission_id
             WHERE rp.role_id=? AND p.module_key=?",
            [$roleRow['id'], $moduleKey]
        );
        return (bool)$ok;
    }

    public static function requireAccess(string $moduleKey): void {
        AdminAuth::requireLogin();
        if (!self::can($moduleKey)) {
            Session::flash('tla_error', 'You do not have access to that module.');
            redirect('/dashboard.php');
        }
    }

    /** For api.php endpoints — returns JSON 403 instead of redirecting. */
    public static function requireAccessJson(string $moduleKey): void {
        if (!AdminAuth::check()) jsonResponse(false, 'Session expired. Please log in again.', null, 401);
        if (!self::can($moduleKey)) jsonResponse(false, 'You do not have access to this action.', null, 403);
    }
}

// ── Activity Logger ─────────────────────────────────────────────
function logActivity(string $action, string $module, string $description = ''): void {
    if (!DB::tableExists('tl_admin_activity_logs')) return;
    DB::run("INSERT INTO tl_admin_activity_logs (admin_id, admin_name, action, module, description, ip_address) VALUES (?,?,?,?,?,?)", [
        AdminAuth::id(),
        Session::get('tla_full_name', 'System'),
        $action,
        $module,
        $description,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

// ── Code Generators ───────────────────────────────────────────
function generateCode(string $prefix, string $table, string $column = 'id', int $pad = 6): string {
    $last = (int) (DB::val("SELECT MAX($column) FROM $table") ?? 0);
    return $prefix . str_pad((string)($last + 1), $pad, '0', STR_PAD_LEFT);
}
function generateUniqueCode(string $prefix, string $table, string $codeColumn): string {
    do {
        $code = $prefix . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    } while ((int) DB::val("SELECT COUNT(*) FROM $table WHERE $codeColumn=?", [$code]) > 0);
    return $code;
}

// ── General Helpers ───────────────────────────────────────────
function clean(?string $val): string {
    return htmlspecialchars(trim($val ?? ''), ENT_QUOTES, 'UTF-8');
}
function peso($amount): string {
    return '₱' . number_format((float)$amount, 2);
}
function timeAgo(?string $datetime): string {
    if (!$datetime) return '—';
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', strtotime($datetime));
}
function jsonResponse(bool $success, string $message = '', $data = null, int $httpCode = 200): void {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function csrfField(): string {
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . Session::csrfToken() . '">';
}
function requireCsrfOrJson(): void {
    $token = $_POST[CSRF_TOKEN_NAME] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!Session::verifyCsrf($token)) {
        jsonResponse(false, 'Invalid session token. Please refresh and try again.', null, 419);
    }
}
function redirect(string $path): void {
    header('Location: ' . APP_URL . $path);
    exit;
}
function currentPage(): string {
    return basename($_SERVER['SCRIPT_NAME'] ?? '');
}
function badge(string $status): string {
    $map = [
        'active'=>'success','completed'=>'success','won'=>'success','approved'=>'success','paid'=>'success','done'=>'success','delivered'=>'success','resolved'=>'success','online'=>'success',
        'pending'=>'warning','new'=>'warning','draft'=>'warning','scheduled'=>'warning','proposal'=>'warning','open'=>'warning','processing'=>'warning','todo'=>'warning',
        'suspended'=>'danger','banned'=>'danger','cancelled'=>'danger','rejected'=>'danger','lost'=>'danger','overdue'=>'danger','failed'=>'danger','terminated'=>'danger',
        'inactive'=>'muted','closed'=>'muted','disposed'=>'muted','offline'=>'muted',
        'contacted'=>'info','qualified'=>'info','in_progress'=>'info','on_the_way'=>'info','picked_up'=>'info','searching'=>'info','accepted'=>'info','on_leave'=>'info','on_hold'=>'info','planning'=>'info',
    ];
    $cls = $map[$status] ?? 'muted';
    $label = ucwords(str_replace('_', ' ', $status));
    return "<span class=\"badge badge-{$cls}\">{$label}</span>";
}

/** Simple GET-based pager */
function paginate(int $totalRows, int $page, int $perPage = PAGE_SIZE): array {
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;
    return compact('totalRows', 'totalPages', 'page', 'perPage', 'offset');
}
function pagerLinks(int $page, int $totalPages): string {
    if ($totalPages <= 1) return '';
    $qs = $_GET; $html = '<div class="pager">';
    $qs['page'] = max(1, $page - 1);
    $html .= '<a class="pg-btn' . ($page<=1?' disabled':'') . '" href="?' . http_build_query($qs) . '"><i class="fa-solid fa-chevron-left"></i></a>';
    $start = max(1, $page - 2); $end = min($totalPages, $page + 2);
    for ($i = $start; $i <= $end; $i++) {
        $qs['page'] = $i;
        $html .= '<a class="pg-btn' . ($i==$page?' active':'') . '" href="?' . http_build_query($qs) . '">' . $i . '</a>';
    }
    $qs['page'] = min($totalPages, $page + 1);
    $html .= '<a class="pg-btn' . ($page>=$totalPages?' disabled':'') . '" href="?' . http_build_query($qs) . '"><i class="fa-solid fa-chevron-right"></i></a>';
    $html .= '</div>';
    return $html;
}

function setting(string $key, $default = null) {
    if (!DB::tableExists('tl_settings')) return $default;
    $val = DB::val("SELECT `value` FROM tl_settings WHERE `key`=?", [$key]);
    return $val !== false ? $val : $default;
}
