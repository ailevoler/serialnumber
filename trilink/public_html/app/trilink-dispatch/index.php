<?php
require_once __DIR__ . '/includes/core.php';
header('Location: ' . APP_URL . (currentDispatcher() ? '/home.php' : '/login.php'));
