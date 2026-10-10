<?php
require __DIR__ . '/includes/bootstrap.php';
logout_user();
session_start();
flash('success', 'You have been logged out.');
redirect('login.php');
