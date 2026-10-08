<?php
// Fake SUNIWAY Partner API for tests only:  php -S 127.0.0.1:8098 tests/suniway_fake_api.php
// Account numbers drive the outcome: 0000 = rejected (400), 5555 = server error (500), 1111 = FAILED at once,
// 2222 = FAILED on status check, anything else = PENDING then SUCCESS on status check.
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
header('Content-Type: application/json');
$out = static function (int $code, array $body): never { http_response_code($code); echo json_encode($body); exit; };
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer test-key-123') $out(401, ['message' => 'Invalid API key']);
$path = preg_replace('#^.*/partner-api#', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$db = sys_get_temp_dir() . '/suniway-fake.json';
$state = is_file($db) ? json_decode(file_get_contents($db), true) : [];
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
if ($path === '/providers') $out(200, [
    'bills' => [['billerId' => 'AKE01', 'name' => 'AKELCO - Aklan Electric Cooperative', 'providerType' => 'BILLS', 'minAmount' => 50, 'maxAmount' => 50000],
                ['billerId' => 'PLDT1', 'name' => 'PLDT Home', 'providerType' => 'BILLS', 'minAmount' => 1, 'maxAmount' => 100000]],
    'eload' => [['billerId' => 'GLB', 'name' => 'Globe Regular Load', 'providerType' => 'ELOAD'], ['billerId' => 'SMT', 'name' => 'Smart Regular Load', 'providerType' => 'ELOAD']],
    'ecCash' => [['billerId' => 'GC', 'name' => 'GCash Cash In', 'providerType' => 'ECASH', 'maxAmount' => 10000], ['billerId' => 'MY', 'name' => 'Maya Cash In', 'providerType' => 'ECASH']],
]);
if ($path === '/transactions/preview') {
    $fee = $body['providerType'] === 'BILLS' ? 15 : ($body['providerType'] === 'ECASH' ? 10 : 0);
    $out(200, ['data' => ['amount' => $body['amount'], 'serviceFee' => 0, 'providerFee' => $fee, 'total' => $body['amount'] + $fee]]);
}
if ($path === '/transactions' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $acct = (string) ($body['accountNumber'] ?? '');
    if ($acct === '0000') $out(400, ['message' => 'Invalid account number']);
    if ($acct === '5555') $out(500, ['message' => 'Upstream timeout']);
    $id = 'tx' . bin2hex(random_bytes(4));
    $state[$id] = ['acct' => $acct];
    file_put_contents($db, json_encode($state));
    $out(201, ['data' => ['id' => $id, 'status' => $acct === '1111' ? 'FAILED' : 'PENDING', 'referenceNumber' => 'SWREF' . strtoupper(substr($id, 2))]]);
}
if (preg_match('#^/transactions/(tx[a-f0-9]+)$#', $path, $m)) {
    if (!isset($state[$m[1]])) $out(404, ['message' => 'Not found']);
    $out(200, ['data' => ['id' => $m[1], 'status' => $state[$m[1]]['acct'] === '2222' ? 'FAILED' : 'SUCCESS', 'referenceNumber' => 'SWREF' . strtoupper(substr($m[1], 2))]]);
}
$out(404, ['message' => 'Unknown path ' . $path]);
