<?php
require __DIR__ . '/includes/bootstrap.php';

$u = current_user();
if ($u) {
    redirect($u['role'] === 'admin' ? 'admin/dashboard.php' : 'member/dashboard.php');
}
if (setting('coming_soon_enabled', '0') === '1' && !isset($_GET['ref'])) {
    require __DIR__ . '/coming-soon.php';
    exit;
}
redirect('login.php' . (isset($_GET['ref']) ? '?ref=' . urlencode((string)$_GET['ref']) : ''));
