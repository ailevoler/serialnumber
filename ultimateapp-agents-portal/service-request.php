<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/service-seeds.php';
require_once __DIR__ . '/includes/agents.php';
$user = require_auth();
header('Cache-Control: no-store');
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$service = is_string($input['service'] ?? null) ? $input['service'] : '';
$itemId = is_string($input['item'] ?? null) ? $input['item'] : '';
$item = service_seed_item($service, $itemId);
if (!$item || $service === 'UEat' || !service_is_active($service)) { http_response_code(404); exit('Listing not found.'); }
$intent = in_array($service, ['UPass','UGo'], true) ? 'booking' : 'inquiry';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('service_request', 'user:' . $user['id'], 20, 3600);
    $requestKey = is_string($_POST['request_key'] ?? null) ? $_POST['request_key'] : '';
    $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
    $date = is_string($_POST['preferred_date'] ?? null) ? $_POST['preferred_date'] : '';
    $dateObject = $date === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $notes = is_string($_POST['notes'] ?? null) ? trim($_POST['notes']) : '';
    if (!$requestKey || !hash_equals($_SESSION['service_request_key'] ?? '', $requestKey)) {
        $error = 'This request was already submitted. Check your recent requests.';
    } elseif ($quantity === false || $quantity < 1 || $quantity > 20) {
        $error = 'Choose a quantity from 1 to 20.';
    } elseif ($date !== '' && (!$dateObject || $dateObject->format('Y-m-d') !== $date || $date < date('Y-m-d'))) {
        $error = 'Choose a valid future date.';
    } elseif (strlen($notes) > 1000) {
        $error = 'Keep your note under 1,000 characters.';
    } else {
        try {
            $reference = 'SR-' . strtoupper(bin2hex(random_bytes(8)));
            $stmt = $pdo->prepare('INSERT INTO service_requests (reference, request_key, user_id, service_code, item_id, intent, quantity, preferred_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$reference, $requestKey, $user['id'], $service, $itemId, $intent, $quantity, $date !== '' ? $date : null, $notes ?: null]);
            // Referral agent commission on the listed value; pending until Admin marks the request completed.
            if (isset(agent_services()[$service]) && isset($item['price'])) {
                agent_record_commission($pdo, (int) $user['id'], $service, 'service_request', (int) $pdo->lastInsertId(), $reference, (int) $item['price'] * 100 * $quantity, false);
            }
            unset($_SESSION['service_request_key']);
            redirect('service-request-status.php?reference=' . rawurlencode($reference));
        } catch (Throwable $exception) {
            error_log('Service request failed: ' . get_class($exception));
            $error = 'Could not save your request. Please try again.';
        }
    }
}
if (empty($_SESSION['service_request_key'])) $_SESSION['service_request_key'] = bin2hex(random_bytes(32));
$pageTitle = 'Request ' . $item['name'];
require __DIR__ . '/includes/header.php';
?>
<section class="screen seed-screen">
    <header class="page-head"><a href="service.php?service=<?= e($service) ?>" aria-label="Back"><span data-icon="arrow-left"></span></a><h1><?= $intent === 'order' ? 'Order request' : ($intent === 'booking' ? 'Booking request' : 'Inquire') ?></h1><span></span></header>
    <div class="seed-heading"><small><?= e($service) ?> · BORACAY</small><h2><?= e($item['name']) ?></h2><p><?= e($item['detail']) ?></p></div>
    <p class="seed-notice">Phase 1 sample. This request does not reserve a slot, notify a provider, place a paid order, or deduct Credits.</p>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" class="seed-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="request_key" value="<?= e($_SESSION['service_request_key']) ?>"><input type="hidden" name="service" value="<?= e($service) ?>"><input type="hidden" name="item" value="<?= e($itemId) ?>">
        <label>Quantity / guests<input type="number" name="quantity" min="1" max="20" value="1" required></label>
        <label>Preferred date <small>(optional)</small><input type="date" name="preferred_date" min="<?= date('Y-m-d') ?>"></label>
        <label>Details <small>(optional)</small><textarea name="notes" rows="4" maxlength="1000" placeholder="Tell us what you need"></textarea></label>
        <button class="btn dark" type="submit">Send request</button>
    </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
