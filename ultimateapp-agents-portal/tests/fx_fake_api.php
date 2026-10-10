<?php
// Local stand-in for the public rate APIs, for tests only:  php -S 127.0.0.1:8097 tests/fx_fake_api.php
//   /frankfurter/58.25 -> Frankfurter reply   /currency/58.25 -> currency-api reply   /down -> HTTP 503
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if (preg_match('#^/frankfurter/([\d.]+)$#', $path, $m)) { echo json_encode(['amount' => 1.0, 'base' => 'USD', 'date' => '2026-10-09', 'rates' => ['PHP' => (float) $m[1]]]); return true; }
if (preg_match('#^/currency/([\d.]+)$#', $path, $m)) { echo json_encode(['date' => '2026-10-10', 'usd' => ['eur' => 0.91, 'php' => (float) $m[1]]]); return true; }
http_response_code(503);
echo '{"error":"down"}';
return true;
