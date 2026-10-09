<?php
declare(strict_types=1);
/**
 * Shared header/sidebar. Expects $pageTitle to be set by the including page.
 * Requires bootstrap.php + require_login()/require_role() to already have run.
 */
$__user = current_user();
$__base = base_path();
$__current = basename($_SERVER['SCRIPT_NAME']);
$__displayName = $__user ? display_name($__user) : '';

function nav_item(string $href, string $label, string $svg, string $current): string
{
    $active = ($current === $href) ? ' active' : '';
    return "<a href=\"$href\" class=\"nav-item$active\">$svg<span class=\"nav-label\">" . h($label) . "</span></a>";
}

$icons = [
    'dashboard' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>',
    'branches' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="6" cy="6" r="3"/><circle cx="18" cy="6" r="3"/><circle cx="12" cy="18" r="3"/><path d="M6 9v2a4 4 0 004 4M18 9v2a4 4 0 01-4 4"/></svg>',
    'manage' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
    'cashout' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v14M6 10l6 6 6-6"/><path d="M4 20h16"/></svg>',
    'checks' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h3"/></svg>',
    'settings' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06A1.65 1.65 0 005 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06A1.65 1.65 0 009 4.6a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>',
    'pledges' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.8 4.6c-1.6-1.6-4.1-1.6-5.7 0L12 7.7l-3.1-3.1c-1.6-1.6-4.1-1.6-5.7 0-1.6 1.6-1.6 4.1 0 5.7L12 19l8.8-8.7c1.6-1.6 1.6-4.1 0-5.7z"/></svg>',
    'qr' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3M14 21h3M21 14v3M21 21h.01"/></svg>',
    'logout' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>',
    'services' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M2 12h20"/><circle cx="12" cy="12" r="9"/></svg>',
    'prereg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M16 11h6"/></svg>',
    'transactions' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/><circle cx="7" cy="6" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="17" cy="18" r="1"/></svg>',
    'webhook' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 16.5a4.5 4.5 0 10-4.4-5.5H9.4A4.5 4.5 0 105 16.5"/><path d="M9 11l3 3 3-3"/></svg>',
    'gateway' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/></svg>',
    'fees' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 5L5 19"/><circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="17" r="2.5"/></svg>',
    'settlement' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="M18.7 8l-5.1 5.1-3-3L7 13.4"/></svg>',
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle ?? 'SurgeBox') ?></title>
<link rel="stylesheet" href="<?= h($__base) ?>/assets/css/style.css?v=<?= h(asset_version('css/style.css')) ?>">
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
<script src="<?= h(base_path()) ?>/assets/js/app.js?v=<?= h(asset_version('js/app.js')) ?>"></script>
</head>
<?php
// V5.20: view-only pages for portal roles without write access (the API gate is the real enforcement).
$__ro = false;
if (is_org_portal()) {
    $__ro = match ($__current) {
        'portal-branches.php', 'portal-services.php' => !portal_can('branches.manage'),
        'portal-organization.php' => !portal_can('org.manage'),
        'portal-check-request.php' => !portal_can('cheque.create'),
        default => false,
    };
}
?>
<body class="<?= $__ro ? 'sb-readonly' : '' ?><?= is_org_portal() && !portal_can('export') ? ' sb-noexport' : '' ?>">
<div class="app-shell">
<?php if ($__user): ?>
  <div class="mobile-appbar">
    <button type="button" class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open navigation">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <img src="<?= h($__base) ?>/assets/img/logo.png" alt="SurgeBox">
    <span><?= is_org_portal() ? 'Organization Portal' : 'SurgeBox' ?></span>
  </div>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-toggle" id="sidebarToggle">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#666" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
    </div>
    <div class="sidebar-logo-wrap">
      <img src="<?= h($__base) ?>/assets/img/logo.png" alt="SurgeBox" class="sidebar-logo-img">
      <div class="sidebar-merchant-name"><?= h($__displayName) ?></div>
      <?php if (is_org_portal()): ?><div class="form-hint" style="padding:0 12px 8px"><?= portal_user_id() ? h((string) ($_SESSION['portal_org_name'] ?? 'Organization Portal')) . '<br>' : 'Organization Portal<br>' ?><span class="pill" style="margin-top:4px;background:#eef2ff;color:#3730a3"><?= h((string) portal_role()) ?></span></div><?php endif; ?>
    </div>
    <nav class="sidebar-nav">
      <?php if ($__user['user_type'] === 'Admin'): ?>
        <?= nav_item('dashboard.php', 'Dashboard', $icons['dashboard'], $__current) ?>
        <?= nav_item('manage.php', 'Manage', $icons['manage'], $__current) ?>
        <?= nav_item('pre-registrations.php', 'Pre-Registrations', $icons['prereg'], $__current) ?>
        <?= nav_item('payment-providers.php', 'Payment Providers', $icons['gateway'], $__current) ?>
        <?= nav_item('client-fees.php', 'Client Fees', $icons['fees'], $__current) ?>
        <?= nav_item('services.php', 'Services', $icons['services'], $__current) ?>
        <?= nav_item('transaction-ledger.php', 'Transactions', $icons['transactions'], $__current) ?>
        <?= nav_item('webhook-monitor.php', 'Webhook Monitor', $icons['webhook'], $__current) ?>
        <?= nav_item('cashout.php', 'Cashout', $icons['cashout'], $__current) ?>
        <?= nav_item('check-requests.php', 'Check Requests', $icons['checks'], $__current) ?>
        <?= nav_item('kyb-submissions.php', 'KYB Submissions', $icons['pledges'], $__current) ?>
        <?= nav_item('settlement-report.php', 'Settlement Report', $icons['settlement'], $__current) ?>
        <?= nav_item('settlement-import.php', 'Settlement Import', $icons['cashout'], $__current) ?>
        <?= nav_item('account-settings.php', 'Settings', $icons['settings'], $__current) ?>
      <?php elseif ($__user['user_type'] === 'Manager'): ?>
        <?php if (is_org_portal()): ?>
          <?= nav_item('portal-dashboard.php', 'Dashboard', $icons['dashboard'], $__current) ?>
          <?= portal_can('qr.view') ? nav_item('portal-my-qr.php', 'My QR Ph', $icons['qr'], $__current) : '' ?>
          <?= portal_can('settlement') ? nav_item('portal-settlement.php', 'Settlement', $icons['settlement'], $__current) : '' ?>
          <?= portal_can('cashout.view') ? nav_item('portal-cashout.php', 'Cash Out', $icons['cashout'], $__current) : '' ?>
          <?= portal_can('branches.view') ? nav_item('portal-branches.php', 'Branches', $icons['branches'], $__current) : '' ?>
          <?= portal_can('branches.view') ? nav_item('portal-services.php', 'Services', $icons['services'], $__current) : '' ?>
          <?= nav_item('transaction-ledger.php', 'Transactions', $icons['transactions'], $__current) ?>
          <?= portal_can('org.view') ? nav_item('portal-organization.php', 'Organization Info', $icons['settlement'], $__current) : '' ?>
          <?= portal_can('cheque.view') ? nav_item('portal-check-request.php', 'Check Request', $icons['checks'], $__current) : '' ?>
          <?= portal_can('users') ? nav_item('portal-users.php', 'Users & Roles', $icons['prereg'], $__current) : '' ?>
          <?= nav_item('portal-profile.php', 'Settings', $icons['settings'], $__current) ?>
        <?php else: ?>
          <?= nav_item('dashboard.php', 'Dashboard', $icons['dashboard'], $__current) ?>
          <?= nav_item('branches.php', 'Branches', $icons['branches'], $__current) ?>
          <?= nav_item('services.php', 'Services', $icons['services'], $__current) ?>
          <?= nav_item('transaction-ledger.php', 'Transactions', $icons['transactions'], $__current) ?>
          <?= nav_item('check-request.php', 'Check Request', $icons['checks'], $__current) ?>
          <?= nav_item('profile-settings.php', 'Settings', $icons['settings'], $__current) ?>
        <?php endif; ?>
      <?php else: ?>
        <?= nav_item('dashboard.php', 'Dashboard', $icons['dashboard'], $__current) ?>
        <?= nav_item('pledges.php', 'My Pledges & Tithes', $icons['pledges'], $__current) ?>
        <?= nav_item('profile-settings.php', 'Settings', $icons['settings'], $__current) ?>
      <?php endif; ?>
    </nav>
    <div class="sidebar-bottom">
      <a href="<?= is_org_portal() ? 'portal-my-qr.php' : 'my-qr.php' ?>" class="sidebar-bottom-btn qr"><?= $icons['qr'] ?><span class="sidebar-bottom-label">My QR</span></a>
      <a href="<?= is_org_portal() ? 'portal-logout.php' : 'logout.php' ?>" class="sidebar-bottom-btn logout"><?= $icons['logout'] ?><span class="sidebar-bottom-label">Logout</span></a>
    </div>
  </aside>
<?php endif; ?>
  <main class="main<?= $__user ? '' : ' expanded' ?>" id="mainContent" style="<?= $__user ? '' : 'margin-left:0' ?>">
    <?php if (APP_SANDBOX_MODE): ?><div class="alert" style="background:#fff7ed;color:#9a3412;border:1px solid #fdba74">SurgeBox Sandbox mode — only TEST credentials are used (e.g. PayMongo sk_test_ keys). Set APP_SANDBOX_MODE=0 for production.</div><?php endif; ?>
    <?php if ($__ro): ?><div class="alert" style="background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe">View only — your role (<?= h((string) portal_role()) ?>) cannot make changes on this page.</div><?php endif; ?>
    <?php if ($msg = flash_get('success')): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
    <?php if ($msg = flash_get('error')): ?><div class="alert alert-error"><?= h($msg) ?></div><?php endif; ?>
