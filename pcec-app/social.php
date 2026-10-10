<?php
// Placeholder for Google / Facebook OAuth. Add credentials in config/config.php and
// implement the provider redirect + callback here (e.g. with league/oauth2-client).
require __DIR__ . '/includes/bootstrap.php';

$provider = ($_GET['p'] ?? '') === 'facebook' ? 'Facebook' : 'Google';
$configured = $provider === 'Google' ? GOOGLE_CLIENT_ID !== '' : FACEBOOK_APP_ID !== '';

flash('info', $configured
    ? "$provider sign-in is configured but the callback is not implemented yet."
    : "$provider sign-in is not set up yet. Please use your email and password for now.");
redirect('login.php');
