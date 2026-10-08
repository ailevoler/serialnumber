<?php
require_once __DIR__ . '/_bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $_SESSION = [];
    session_regenerate_id(true);
    session_destroy();
}
redirect('login.php');
