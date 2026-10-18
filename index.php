<?php
require __DIR__ . '/includes/bootstrap.php';

$u = current_user();
if ($u) {
    redirect(home_for($u));
}
if (!isset($_GET['ref'])) {
    $mode = home_mode();
    if ($mode === 'coming_soon') {
        require __DIR__ . '/coming-soon.php';
        exit;
    }
    if ($mode === 'landing') {
        require __DIR__ . '/home.php';
        exit;
    }
}
redirect('login.php' . (isset($_GET['ref']) ? '?ref=' . urlencode((string)$_GET['ref']) : ''));
