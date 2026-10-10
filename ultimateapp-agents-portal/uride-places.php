<?php
// JSON place suggestions for URide pick-up / drop-off (landmarks, merchants, recent places).
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/uride.php';
require_once __DIR__ . '/includes/uride_places.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
$user = current_user();
if (!$user) { http_response_code(401); echo json_encode(['error' => 'Sign in again.']); exit; }
if (!rate_limit_take($pdo, 'uride_places', 'user:' . $user['id'], 240, 600)['allowed']) { http_response_code(429); echo json_encode(['error' => 'Too many searches. Wait a moment.']); exit; }
$q = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
if (isset($_GET['lat'], $_GET['lng']) && is_numeric($_GET['lat']) && is_numeric($_GET['lng'])) {
    $near = uride_place_nearest($pdo, (float) $_GET['lat'], (float) $_GET['lng']);
    echo json_encode(['near' => $near ? $near['name'] : null]);
    exit;
}
echo json_encode(['places' => uride_place_search($pdo, (int) $user['id'], $q)], JSON_UNESCAPED_UNICODE);
