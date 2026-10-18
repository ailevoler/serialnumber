<?php
// Expects $activeNav to be set by the including page: home|transactions|scan|notifications|profile
$activeNav = $activeNav ?? 'home';
if (!function_exists('nav_class')) {
    function nav_class(string $key, string $active): string {
        return 'nav-item' . ($key === $active ? ' active' : '');
    }
}
// Unread badge on Inbox (the dashboard no longer has its own bell)
$navUnread = 0;
if (current_user_id()) {
    $stmt = db()->prepare("SELECT COUNT(*) c FROM rbnw_notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([current_user_id()]);
    $navUnread = (int)$stmt->fetch()['c'];
}
$navItems = [
    'home'          => ['dashboard.php',     'fa-house',             'Home'],
    'transactions'  => ['transactions.php',  'fa-clock-rotate-left', 'History'],
    'scan'          => ['scan-qr.php',       'fa-qrcode',            'Scan QR'],
    'notifications' => ['notifications.php', 'fa-bell',              'Inbox'],
    'profile'       => ['profile.php',       'fa-user',              'Profile'],
];
?>
<nav class="bottom-nav" aria-label="Main">
  <?php foreach ($navItems as $key => [$href, $icon, $label]): ?>
    <a href="<?= $href ?>" class="<?= nav_class($key, $activeNav) ?>"<?= $key === $activeNav ? ' aria-current="page"' : '' ?>>
      <span class="nav-icon"><i class="fa-solid <?= $icon ?>"></i><?php if ($key === 'notifications' && $navUnread > 0): ?><span class="nav-badge"><?= $navUnread > 9 ? '9+' : $navUnread ?></span><?php endif; ?></span>
      <span class="nav-label"><?= $label ?></span>
    </a>
  <?php endforeach; ?>
</nav>
