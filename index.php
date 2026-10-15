<?php
require __DIR__ . '/includes/bootstrap.php';

$u = current_user();
if ($u) {
    redirect($u['role'] === 'admin' ? 'admin/dashboard.php' : 'member/dashboard.php');
}
redirect('login.php' . (isset($_GET['ref']) ? '?ref=' . urlencode((string)$_GET['ref']) : ''));
