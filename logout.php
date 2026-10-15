<?php
require __DIR__ . '/includes/bootstrap.php';

if ($u = current_user()) {
    log_activity((int)$u['id'], 'Logged out');
}
logout_user();
redirect('login.php');
