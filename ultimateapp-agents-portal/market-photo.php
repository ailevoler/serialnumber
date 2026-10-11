<?php
// Listing and restaurant photos (public). Names are random, so they can be cached for a long time.
declare(strict_types=1);
$f = is_string($_GET['f'] ?? null) ? $_GET['f'] : '';
$path = __DIR__ . '/storage/market/' . $f;
if (!preg_match('/^[a-f0-9]{32}\.jpg$/D', $f) || !is_file($path)) { http_response_code(404); exit('Not found.'); }
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
readfile($path);
