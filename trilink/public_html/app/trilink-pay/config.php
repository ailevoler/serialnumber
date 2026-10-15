<?php
// ============================================================
// TRILINK PAY – config.php
// Shared wallet top-up settings (QR Ph via PayMongo + Teller).
//
// DO NOT put live keys in this file. Copy config.local.sample.php
// to config.local.php on the server and put the keys there
// (or set them as environment variables).
// ============================================================

// DB constants come from the app that includes us. When this file is
// loaded on its own (webhook.php) borrow the driver app's DB config.
if (!defined('DB_HOST')) {
    require_once __DIR__ . '/../trilink-driver/config.php';
}

$__tlpLocal = is_file(__DIR__ . '/config.local.php') ? require __DIR__ . '/config.local.php' : [];
$__tlpGet = function ($key, $default = '') use ($__tlpLocal) {
    if (isset($__tlpLocal[$key]) && $__tlpLocal[$key] !== '') return $__tlpLocal[$key];
    $env = getenv($key);
    return ($env !== false && $env !== '') ? $env : $default;
};

// PayMongo secret key: sk_live_... for production, sk_test_... for testing.
define('PAYMONGO_SECRET_KEY',     $__tlpGet('PAYMONGO_SECRET_KEY'));
// Webhook signing secret (whsk_...) shown when you create the webhook.
define('PAYMONGO_WEBHOOK_SECRET', $__tlpGet('PAYMONGO_WEBHOOK_SECRET'));
define('PAYMONGO_API_BASE',       $__tlpGet('PAYMONGO_API_BASE', 'https://api.paymongo.com/v1'));
// Email used on the QR Ph billing record when the customer has none.
define('PAYMONGO_FALLBACK_EMAIL', $__tlpGet('PAYMONGO_FALLBACK_EMAIL', 'payments@trilink.site'));

// QR Ph codes are valid for 30 minutes on PayMongo's side.
define('TOPUP_QRPH_EXPIRY_MINUTES', 30);

unset($__tlpLocal, $__tlpGet);
