<?php
// Copy this file to config.local.php (same folder) on the server and fill in
// your PayMongo keys from https://dashboard.paymongo.com/developers
// config.local.php must never be committed or shared.
return [
    'PAYMONGO_SECRET_KEY'     => 'PASTE_YOUR_PAYMONGO_SECRET_KEY_HERE',
    'PAYMONGO_WEBHOOK_SECRET' => 'PASTE_YOUR_WEBHOOK_SIGNING_SECRET_HERE',
    'PAYMONGO_FALLBACK_EMAIL' => 'payments@trilink.site',
];
