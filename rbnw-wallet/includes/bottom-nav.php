<?php
// Expects $activeNav to be set by the including page: home|transactions|scan|notifications|profile
$activeNav = $activeNav ?? 'home';
function nav_class(string $key, string $active): string {
    return 'nav-item' . ($key === $active ? ' active' : '');
}
?>
<nav class="bottom-nav">
  <a href="dashboard.php" class="<?= nav_class('home', $activeNav) ?>">
    <i class="fa-solid fa-house"></i><span>Home</span>
  </a>
  <a href="transactions.php" class="<?= nav_class('transactions', $activeNav) ?>">
    <i class="fa-solid fa-list-ul"></i><span>Transactions</span>
  </a>
  <a href="scan-qr.php" class="nav-item">
    <div class="scan-fab"><i class="fa-solid fa-qrcode"></i></div>
  </a>
  <a href="notifications.php" class="<?= nav_class('notifications', $activeNav) ?>">
    <i class="fa-solid fa-bell"></i><span>Notifications</span>
  </a>
  <a href="profile.php" class="<?= nav_class('profile', $activeNav) ?>">
    <i class="fa-solid fa-user"></i><span>Profile</span>
  </a>
</nav>
