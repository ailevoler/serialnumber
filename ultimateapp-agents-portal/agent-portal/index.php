<?php
require_once __DIR__ . '/_bootstrap.php';
redirect(agent_current() ? 'dashboard.php' : 'login.php');
