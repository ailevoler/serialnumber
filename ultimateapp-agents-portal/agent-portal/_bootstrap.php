<?php
declare(strict_types=1);

// Form-token failures redirect back with a message (see csrf_failed()).
const UA_FLASH_PORTAL = true;

// Agents Portal has its own session cookie scoped to /agent-portal/ (an app or merchant login never grants access).
if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    session_name('UA_AGENT');
    session_set_cookie_params([
        'path' => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/agent-portal/x')), '/') . '/',
        'httponly' => true, 'secure' => $https, 'samesite' => 'Lax',
    ]);
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/agents.php';
header('Cache-Control: no-store, private');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self'");

function agent_current(bool $refresh = false): ?array
{
    global $pdo;
    static $a = false;
    if ($a !== false && !$refresh) return $a;
    if (empty($_SESSION['agent_id'])) return $a = null;
    $stmt = $pdo->prepare('SELECT * FROM agents WHERE id = ?');
    $stmt->execute([$_SESSION['agent_id']]);
    return $a = ($stmt->fetch() ?: null);
}

function agent_require(): array
{
    $a = agent_current();
    if (!$a) { $_SESSION = []; redirect('login.php'); }
    return $a;
}

function aflash(string $type, string $message): void { $_SESSION['flash'][] = [$type, $message]; }
function ap(string $key): string { $v = $_POST[$key] ?? ''; return is_string($v) ? $v : ''; }
function aq(string $key): string { $v = $_GET[$key] ?? ''; return is_string($v) ? trim($v) : ''; }

/** Public sign-up link for this agent: <app root>/register.php?ref=CODE */
function agent_referral_link(array $agent): string
{
    $base = app_base_url((string) setting('paymongo.app_url', ''));
    if ($base === '') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        $host = preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $root = rtrim(dirname(rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/agent-portal/x')), '/')), '/');
        $base = ($https ? 'https://' : 'http://') . $host . ($root === '.' ? '' : $root);
    }
    return $base . '/register.php?ref=' . rawurlencode($agent['code']);
}

/** $kind: 'account' (agent status), 'commission' or 'payout'. */
function agent_badge(string $status, string $kind = 'commission'): string
{
    $tone = ['approved' => 'good', 'paid' => 'good', 'pending' => 'warn', 'requested' => 'info', 'rejected' => 'bad', 'suspended' => 'bad', 'reversed' => 'muted'][$status] ?? 'muted';
    $labels = ['account' => agent_statuses(), 'commission' => agent_commission_statuses(), 'payout' => ['requested' => 'Processing', 'paid' => 'Paid', 'rejected' => 'Returned']][$kind] ?? [];
    return '<span class="badge ' . $tone . '">' . e($labels[$status] ?? ucwords(str_replace('_', ' ', $status))) . '</span>';
}

function agent_header(string $title, string $active = ''): void
{
    $a = agent_current();
    $nav = ['dashboard' => 'Dashboard', 'referrals' => 'My referrals', 'earnings' => 'Earnings', 'payouts' => 'Payouts', 'profile' => 'Profile', 'kyc' => 'Verify ID'];
    if ($a && empty($a['parent_agent_id'])) $nav = array_slice($nav, 0, 2, true) + ['team' => 'My team'] + array_slice($nav, 2, null, true);
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · Ultimate App Agents Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../merchant-portal/assets/base.css?v=5"><link rel="stylesheet" href="../merchant-portal/assets/portal.css?v=2"><link rel="stylesheet" href="assets/agent.css?v=2">
<link rel="icon" href="../assets/images/pwa/icon-192.png"><meta name="theme-color" content="#0b1015">
<link rel="manifest" href="manifest.webmanifest"><link rel="apple-touch-icon" href="../assets/images/pwa/icon-180.png"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="UA Agent">
</head><body class="portal">
<?php if ($a): ?>
<header class="p-top">
    <div class="p-top-inner">
        <a class="brand" href="dashboard.php"><span class="brand-mark"></span><span><b>ULTIMATE APP</b><small>Agents Portal</small></span></a>
        <div class="p-me"><span><b><?= e($a['full_name']) ?></b><small><?= e(agent_role($a)) ?> · <?= e($a['code']) ?></small></span>
            <form method="post" action="logout.php"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="btn small" type="submit">Log out</button></form></div>
    </div>
    <nav class="p-nav" aria-label="Agents Portal"><?php foreach ($nav as $k => $label): ?><a href="<?= $k ?>.php" class="<?= $active === $k ? 'active' : '' ?>"<?= $active === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach; ?></nav>
</header>
<main class="p-main">
<h1 class="p-title"><?= e($title) ?></h1>
<?php foreach ($_SESSION['flash'] ?? [] as [$type, $message]): ?><div class="flash <?= e($type) ?>" role="status"><?= e($message) ?></div><?php endforeach; unset($_SESSION['flash']); ?>
<?php else: ?>
<div class="auth-wrap p-auth">
<?php foreach ($_SESSION['flash'] ?? [] as [$type, $message]): ?><div class="flash auth-flash <?= e($type) ?>" role="status"><?= e($message) ?></div><?php endforeach; unset($_SESSION['flash']); ?>
<?php endif;
}

function agent_footer(array $scripts = []): void
{
    echo agent_current() ? '</main><footer class="p-foot">Ultimate App Boracay · Agents Portal</footer>' : '</div>';
    foreach (array_merge(['../merchant-portal/assets/portal.js?v=1'], $scripts) as $src) echo '<script src="' . e($src) . '"></script>';
    echo '</body></html>';
}

/** The rate tier this agent earns on their own customers. */
function agent_own_tier(array $a): string
{
    return empty($a['parent_agent_id']) ? 'direct' : 'sub';
}

function agent_master_of(array $a): ?array
{
    global $pdo;
    if (empty($a['parent_agent_id'])) return null;
    $s = $pdo->prepare('SELECT id, code, full_name, mobile, status FROM agents WHERE id = ?');
    $s->execute([$a['parent_agent_id']]);
    return $s->fetch() ?: null;
}

/** Shared "not active yet" panel for pages that need an approved agent. */
function agent_locked_panel(array $a): void
{
    echo '<section class="card lock"><h2>Available once your agent account is active</h2><p class="muted">Status: ' . agent_badge($a['status'], 'account') . '</p>'
        . ($a['status_note'] ? '<p>' . e($a['status_note']) . '</p>' : '') . '<a class="btn primary" href="dashboard.php">Back to dashboard</a></section>';
}
