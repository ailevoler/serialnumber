<?php
// Local stand-in for the public rate APIs, for tests only:  php -S 127.0.0.1:8097 tests/fx_fake_api.php
//   /frankfurter/58.25[/eur/0.85] -> Frankfurter reply (units per 1 USD; ~30 major currencies)
//   /currency/58.25[/kwd/0.31]    -> currency-api reply (150+ currencies)      /down -> HTTP 503
// Optional extra path pairs override one currency, e.g. to simulate a jump.
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
$parts = array_values(array_filter(explode('/', $path)));
$over = [];
for ($i = 2; $i + 1 < count($parts); $i += 2) $over[strtoupper($parts[$i])] = (float) $parts[$i + 1];
if (($parts[0] ?? '') === 'frankfurter' && is_numeric($parts[1] ?? null)) {
    $rates = array_merge(['PHP' => (float) $parts[1], 'EUR' => 0.8, 'JPY' => 146.0, 'GBP' => 0.78, 'KRW' => 1400.0, 'AUD' => 1.5, 'CAD' => 1.36, 'SGD' => 1.3], $over);
    echo json_encode(['amount' => 1.0, 'base' => 'USD', 'date' => '2026-10-09', 'rates' => $rates]); return true;
}
if (($parts[0] ?? '') === 'currency' && is_numeric($parts[1] ?? null)) {
    $rates = array_merge(['PHP' => (float) $parts[1], 'EUR' => 0.8, 'JPY' => 146.0, 'GBP' => 0.78, 'KRW' => 1400.0, 'AUD' => 1.5, 'CAD' => 1.36, 'SGD' => 1.3,
        'AED' => 3.6725, 'KWD' => 0.3125, 'VND' => 25000.0, 'IDR' => 16000.0, 'SAR' => 3.75, 'BTC' => 0.00001], $over);
    echo json_encode(['date' => '2026-10-10', 'usd' => array_change_key_case($rates, CASE_LOWER)]); return true;
}
http_response_code(503);
echo '{"error":"down"}';
return true;
