<?php
require_once __DIR__ . '/_bootstrap.php';
redirect(driver_current() ? 'dashboard.php' : 'login.php');
