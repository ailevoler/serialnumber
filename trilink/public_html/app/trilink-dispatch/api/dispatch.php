<?php
// ============================================================
// TRILINK DISPATCH – api/dispatch.php
// state | add_driver | remove | move_end | dispatch | release | cancel
// ============================================================
require_once __DIR__ . '/../includes/core.php';
$me = dispatcherCheck(true);
$terminalId = (int)$me['terminal_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'state') {
    $queue = Dispatch::queue($terminalId);
    $minBalance = (float)setting('driver_min_wallet_balance', 20.00);
    foreach ($queue as &$q) {
        $q['low_balance'] = (float)$q['wallet_balance'] < $minBalance;
        unset($q['wallet_balance']);
    }
    unset($q);
    jsonResponse(true, 'OK', [
        'queue'    => $queue,
        'bookings' => Dispatch::pendingBookings($terminalId),
        'recent'   => Dispatch::recentDispatches($terminalId),
        'today'    => (int)DB::val("SELECT COUNT(*) FROM tl_bookings WHERE terminal_id=? AND dispatched_at IS NOT NULL AND DATE(dispatched_at)=CURDATE()", [$terminalId]),
        'time'     => date('h:i:s A'),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Method not allowed', null, 405);
if (!verifyCsrf($_POST[CSRF_TOKEN_NAME] ?? '')) jsonResponse(false, 'Invalid session. I-refresh ang page.');

try {
    switch ($action) {
        case 'add_driver':
            $driver = Dispatch::findDriver((string)($_POST['q'] ?? ''));
            if (!$driver) jsonResponse(false, 'Walang driver na tugma sa code / phone / plate na iyan.');
            $m = Dispatch::setMode((int)$driver['id'], 'terminal', $terminalId, true);
            jsonResponse(true, "{$driver['full_name']} — #{$m['queue']['position']} sa pila.");

        case 'remove':
            Dispatch::removeFromQueue((int)($_POST['queue_id'] ?? 0), $terminalId);
            jsonResponse(true, 'Inalis sa pila.');

        case 'move_end':
            Dispatch::moveToEnd((int)($_POST['queue_id'] ?? 0), $terminalId);
            jsonResponse(true, 'Inilipat sa hulihan ng pila.');

        case 'dispatch':
            $r = Dispatch::dispatch((int)($_POST['booking_id'] ?? 0), (int)($_POST['queue_id'] ?? 0), $terminalId, (int)$me['id']);
            jsonResponse(true, "#{$r['booking_code']} → {$r['driver_name']}" . ($r['plate_number'] ? " ({$r['plate_number']})" : '') . '. Naabisuhan na ang driver at pasahero.');

        case 'release':
            Dispatch::releaseToStreet((int)($_POST['booking_id'] ?? 0), $terminalId);
            jsonResponse(true, 'Na-release — makikita na ito ng mga driver sa labas ng terminal.');

        case 'cancel':
            Dispatch::cancelBooking((int)($_POST['booking_id'] ?? 0), $terminalId, trim((string)($_POST['reason'] ?? '')));
            jsonResponse(true, 'Na-cancel ang booking. Na-refund kung wallet ang bayad.');
    }
} catch (DispatchException $e) {
    jsonResponse(false, $e->getMessage());
} catch (Exception $e) {
    error_log('[TriLink Dispatch] ' . $action . ': ' . $e->getMessage());
    jsonResponse(false, 'Nagkaproblema. Subukan ulit.');
}

jsonResponse(false, 'Unknown action.', null, 400);
