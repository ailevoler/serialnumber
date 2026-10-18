<?php
// Expects $activeNav to be set by the including page: home|transactions|scan|notifications|profile
$activeNav = $activeNav ?? 'home';
if (!function_exists('nav_class')) {
    function nav_class(string $key, string $active): string {
        return 'nav-item' . ($key === $active ? ' active' : '');
    }
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
    <?php if ($key === 'scan'): ?>
      <a href="<?= $href ?>" class="nav-item nav-scan<?= $activeNav === 'scan' ? ' active' : '' ?>" aria-label="<?= $label ?>">
        <span class="scan-fab"><i class="fa-solid <?= $icon ?>"></i></span>
        <span class="nav-label"><?= $label ?></span>
      </a>
    <?php else: ?>
      <a href="<?= $href ?>" class="<?= nav_class($key, $activeNav) ?>"<?= $key === $activeNav ? ' aria-current="page"' : '' ?>>
        <span class="nav-icon"><i class="fa-solid <?= $icon ?>"></i></span>
        <span class="nav-label"><?= $label ?></span>
      </a>
    <?php endif; ?>
  <?php endforeach; ?>
</nav>
