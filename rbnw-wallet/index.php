<?php
require_once __DIR__ . '/config.php';
header('Location: ' . (current_user_id() ? 'dashboard.php' : 'login.php'));
exit;
