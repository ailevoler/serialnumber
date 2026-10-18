<?php
declare(strict_types=1);

function nav_items(string $role): array
{
    $cms = [
        ['admin/cms.php', 'Website Overview', 'home'],
        ['admin/cms_section.php', 'Page Sections', 'layers'],
        ['admin/cms_items.php', 'Lists & Testimonials', 'list'],
        ['admin/cms_media.php', 'Media Library', 'box'],
        ['home.php', 'View Website', 'eye'],
    ];
    if ($role === 'cms') {
        return ['Website CMS' => $cms, 'Account' => [['admin/cms_account.php', 'My Account', 'shield']]];
    }
    if ($role === 'admin') {
        $cms[] = ['admin/cms_users.php', 'Content Managers', 'users'];
        return [
            'Overview' => [
                ['admin/dashboard.php', 'Dashboard', 'home'],
                ['admin/reports.php', 'Reports', 'chart'],
            ],
            'Network' => [
                ['admin/members.php', 'Members', 'users'],
                ['admin/genealogy.php', 'Genealogy', 'tree'],
                ['admin/register_member.php', 'Register Member', 'user-plus'],
                ['admin/upgrades.php', 'Package Upgrades', 'arrow-up'],
                ['admin/codes.php', 'Code Generator', 'key'],
                ['admin/packages.php', 'Packages', 'package'],
            ],
            'Commerce' => [
                ['admin/products.php', 'Products', 'box'],
                ['admin/orders.php', 'Orders', 'cart'],
            ],
            'Finance' => [
                ['admin/payouts.php', 'Payout Requests', 'cash'],
                ['admin/earnings.php', 'Commissions', 'trending'],
                ['admin/wallet.php', 'Wallet Ledger', 'wallet'],
                ['admin/payments.php', 'Online Payments', 'card'],
            ],
            'Support & CRM' => [
                ['admin/support.php', 'Live Chat Support', 'message'],
                ['admin/crm.php', 'CRM Leads & Pipeline', 'briefcase'],
            ],
            'ERP Finance' => [
                ['admin/erp.php', 'Finance Dashboard', 'layers'],
                ['admin/erp_journal.php', 'Journal Entries', 'book'],
                ['admin/erp_ledger.php', 'General Ledger', 'list'],
                ['admin/erp_reports.php', 'Financial Statements', 'chart'],
                ['admin/erp_expenses.php', 'Expenses', 'cash'],
                ['admin/erp_accounts.php', 'Chart of Accounts', 'settings'],
            ],
            'Website CMS' => $cms,
            'System' => [
                ['admin/announcements.php', 'Announcements', 'megaphone'],
                ['admin/compensation.php', 'Compensation Plan', 'trending'],
                ['admin/paymongo.php', 'PayMongo Settings', 'card'],
                ['admin/simulation.php', 'Simulation & Reset', 'chart'],
                ['admin/settings.php', 'General Settings', 'settings'],
                ['admin/profile.php', 'My Account', 'shield'],
            ],
        ];
    }
    return [
        'Main' => [
            ['member/dashboard.php', 'Dashboard', 'home'],
            ['member/earnings.php', 'My Earnings', 'trending'],
        ],
        'My Network' => [
            ['member/genealogy.php', 'Binary Genealogy', 'tree'],
            ['member/referrals.php', 'Direct Referrals', 'users'],
            ['member/register.php', 'Register Member', 'user-plus'],
            ['member/codes.php', 'My Codes', 'key'],
            ['member/buy_codes.php', 'Buy Codes', 'card'],
            ['member/upgrade.php', 'Upgrade Package', 'arrow-up'],
        ],
        'Shop' => [
            ['member/shop.php', 'Products', 'box'],
            ['member/cart.php', 'My Cart', 'cart'],
            ['member/orders.php', 'My Orders', 'list'],
        ],
        'Wallet' => [
            ['member/wallet.php', 'E-Wallet', 'wallet'],
            ['member/payouts.php', 'Payouts', 'cash'],
        ],
        'Support' => [
            ['member/prospects.php', 'My Prospects (CRM)', 'briefcase'],
            ['member/support.php', 'Live Chat Support', 'message'],
        ],
        'Account' => [
            ['member/profile.php', 'My Profile', 'user'],
        ],
    ];
}

function page_header(string $title, ?array $user = null, string $subtitle = ''): void
{
    $role = $user['role'] ?? 'member';
    $current = ltrim(str_replace(base_url(), '', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? ''), '/');
    $badges = ['member/cart.php' => (int)array_sum($_SESSION['cart'] ?? [])];
    if ($user && $role === 'admin') {
        $badges['admin/support.php'] = chat_unread_threads_admin();
        $badges['admin/payouts.php'] = (int)q_val("SELECT COUNT(*) FROM payouts WHERE status = 'pending'");
        $badges['admin/orders.php'] = (int)q_val("SELECT COUNT(*) FROM orders WHERE status = 'pending'");
        $badges['admin/crm.php'] = (int)q_val("SELECT COUNT(*) FROM crm_leads WHERE owner_user_id IS NULL AND next_follow_up <= CURDATE() AND status NOT IN ('converted','lost')");
    } elseif ($user && $role === 'member') {
        $badges['member/support.php'] = chat_unread_member((int)$user['id']);
        $badges['member/prospects.php'] = (int)q_val("SELECT COUNT(*) FROM crm_leads WHERE owner_user_id = ? AND next_follow_up <= CURDATE() AND status NOT IN ('converted','lost')", [$user['id']]);
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(setting('site_name', 'XGEN LIFE Intl')) ?></title>
<link rel="icon" type="image/png" href="<?= e(asset('img/favicon.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body class="app role-<?= e($role) ?>">
<div class="sidebar-backdrop" data-close-sidebar></div>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <img src="<?= e(asset('img/logo-256.png')) ?>" alt="XGEN LIFE Intl logo">
    <div>
      <strong>XGEN <span>LIFE</span></strong>
      <small><?= ['admin' => 'Administrator Backoffice', 'cms' => 'Content Manager'][$role] ?? 'Member Portal' ?></small>
    </div>
    <button class="icon-btn sidebar-close" data-close-sidebar aria-label="Close menu"><?= icon('x') ?></button>
  </div>
  <nav class="sidebar-nav">
    <?php foreach (nav_items($role) as $group => $items): ?>
      <div class="nav-group"><?= e($group) ?></div>
      <?php foreach ($items as [$href, $label, $ic]): ?>
        <a href="<?= e(url($href)) ?>" class="nav-link <?= $current === $href ? 'active' : '' ?>">
          <?= icon($ic) ?><span><?= e($label) ?></span>
          <?php if (!empty($badges[$href])): ?><em class="nav-count" <?= $href === 'member/support.php' || $href === 'admin/support.php' ? 'data-chat-navbadge' : '' ?>><?= (int)$badges[$href] ?></em><?php endif; ?>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <a href="<?= e(url($role === 'member' ? 'logout.php' : 'admin/logout.php')) ?>" class="nav-link nav-logout"><?= icon('logout') ?><span>Logout</span></a>
  </nav>
  <div class="sidebar-foot"><?= e(setting('site_tagline')) ?></div>
</aside>

<div class="main">
  <header class="topbar">
    <button class="icon-btn menu-toggle" data-open-sidebar aria-label="Open menu"><?= icon('menu', 22) ?></button>
    <div class="topbar-title">
      <h1><?= e($title) ?></h1>
      <?php if ($subtitle): ?><p><?= e($subtitle) ?></p><?php endif; ?>
    </div>
    <?php if ($user): ?>
    <div class="topbar-user">
      <?php if ($role === 'member'): ?>
        <a class="wallet-chip" href="<?= e(url('member/wallet.php')) ?>" title="Wallet balance"><?= icon('wallet', 16) ?> <?= money($user['wallet_balance']) ?></a>
      <?php endif; ?>
      <div class="avatar" title="<?= e(full_name($user)) ?>"><?= e(initials($user)) ?></div>
      <div class="topbar-name"><strong><?= e(full_name($user)) ?></strong><small>@<?= e($user['username']) ?></small></div>
    </div>
    <?php endif; ?>
  </header>
  <main class="content">
    <?php foreach (flashes() as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?>" data-dismissible><?= e($f['message']) ?><button type="button" class="alert-close" aria-label="Dismiss">&times;</button></div>
    <?php endforeach; ?>
<?php
}

function page_footer(): void
{
    ?>
  </main>
  <?php if (($u = current_user()) && !str_ends_with(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/member/support.php')) echo chat_widget($u); ?>
  <footer class="footer">&copy; <?= date('Y') ?> <?= e(setting('site_name', 'XGEN LIFE Intl')) ?> · <?= e(setting('site_tagline')) ?></footer>
</div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/chat.js')) ?>"></script>
</body>
</html>
<?php
}

/** Public (auth) pages: login, register, forgot. */
function auth_header(string $title): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(setting('site_name', 'XGEN LIFE Intl')) ?></title>
<link rel="icon" type="image/png" href="<?= e(asset('img/favicon.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body class="auth">
<div class="auth-wrap">
  <div class="auth-hero">
    <img src="<?= e(asset('img/logo-256.png')) ?>" alt="XGEN LIFE Intl logo">
    <h2>XGEN <span>LIFE</span> <small>INTERNATIONAL</small></h2>
    <p><?= e(setting('site_tagline')) ?></p>
    <ul class="hero-points">
      <li><?= icon('tag', 16) ?> Product Discounts</li>
      <li><?= icon('user-plus', 16) ?> Direct &amp; Indirect Referral</li>
      <li><?= icon('arrow-up', 16) ?> Australian Pass Up</li>
      <li><?= icon('git-merge', 16) ?> Pairing / Matching (Binary)</li>
      <li><?= icon('repeat', 16) ?> Unilevel Bonus</li>
      <li><?= icon('wallet', 16) ?> E-Wallet &amp; Payouts</li>
    </ul>
  </div>
  <div class="auth-panel">
    <?php foreach (flashes() as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
<?php
}

function auth_footer(): void
{
    ?>
  </div>
</div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
<?php
}

/** Stat card helper. */
function stat_card(string $label, string $value, string $ic, string $variant = '', string $hint = ''): string
{
    return '<div class="stat-card ' . e($variant) . '"><div class="stat-icon">' . icon($ic, 22) . '</div><div class="stat-body"><span>'
        . e($label) . '</span><strong>' . e($value) . '</strong>' . ($hint ? '<small>' . e($hint) . '</small>' : '') . '</div></div>';
}

function empty_row(int $cols, string $msg = 'No records found.'): string
{
    return '<tr><td colspan="' . $cols . '" class="empty">' . e($msg) . '</td></tr>';
}

/** Recursive binary genealogy renderer. $linkBase: page to re-root the tree on click. */
/** $register: false, or the page that registers a new member into an empty slot (e.g. 'member/register.php'). */
function render_tree_node(?array $n, string $linkBase, ?array $parent = null, string $side = '', bool|string $register = false, int $depth = 0): string
{
    if (!$n) {
        $inner = '<div class="node node-empty"><span>' . ($side === 'L' ? 'Left' : 'Right') . '</span>';
        if ($register && $parent) {
            $page = is_string($register) ? $register : 'member/register.php';
            $inner .= '<a class="btn btn-xs btn-outline" href="' . e(url($page . '?placement=' . urlencode($parent['username']) . '&position=' . $side)) . '">+ Add</a>';
        }
        return '<li>' . $inner . '</div></li>';
    }
    $h = '<li><a class="node ' . ($n['status'] !== 'active' ? 'node-inactive' : '') . '" href="' . e($linkBase . (str_contains($linkBase, '?') ? '&' : '?') . 'root=' . $n['id']) . '">'
        . '<span class="node-avatar">' . e(initials($n)) . '</span>'
        . '<strong>' . e($n['username']) . '</strong>'
        . '<small>' . e(full_name($n)) . '</small>'
        . '<em class="node-pkg">' . e($n['package_name'] ?? '—') . '</em>'
        . '<span class="node-stats"><b>L ' . (int)$n['left_count'] . '</b><b>R ' . (int)$n['right_count'] . '</b></span>'
        . '</a>';
    if ($depth < 3) {
        $h .= '<ul>' . render_tree_node($n['children']['L'] ?? null, $linkBase, $n, 'L', $register, $depth + 1)
            . render_tree_node($n['children']['R'] ?? null, $linkBase, $n, 'R', $register, $depth + 1) . '</ul>';
    }
    return $h . '</li>';
}
