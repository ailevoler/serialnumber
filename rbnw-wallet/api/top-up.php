<?php
/**
 * RBNW WALLET — Cash In via PayMongo QR Ph (API)
 * Ported from ServiPay's topup_process.php / topup_status.php, adapted
 * to RBNW's wallet ledger (rbnw_wallet_transactions via credit_cash_in()).
 */
ob_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/paymongo.php';
ob_clean();

header('Content-Type: application/json; charset=utf-8');

// Guarantee valid JSON even on a PHP fatal error (missing file, bad SQL,
// undefined function, etc.) — otherwise a blank body breaks the
// front-end's JSON.parse and shows up there as a generic "Network error".
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Server error: ' . $e['message']]);
    }
});

require_login();

$action = $_GET['action'] ?? '';
$userId = current_user_id();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

try {

switch ($action) {

    // Create a PayMongo QR Ph Payment Intent for the requested amount.
    case 'generate':
        $amount = round((float)($input['amount'] ?? 0), 2);

        if ($amount < CASH_IN_MIN_AMOUNT) {
            json_out(['success' => false, 'message' => 'Minimum cash in is ' . peso(CASH_IN_MIN_AMOUNT) . '.']);
        }
        if ($amount > CASH_IN_MAX_AMOUNT) {
            json_out(['success' => false, 'message' => 'Maximum cash in is ' . peso(CASH_IN_MAX_AMOUNT) . '.']);
        }

        $wallet = get_wallet($userId);
        if (!$wallet) {
            json_out(['success' => false, 'message' => 'Wallet setup error. Please contact support.'], 500);
        }

        $fees      = calc_cash_in_fees($amount);
        $reference = generate_reference('CI');
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . CASH_IN_QR_EXPIRY_MINS . ' minutes'));

        db()->prepare(
            "INSERT INTO rbnw_cash_in_requests
                (user_id, wallet_id, reference_no, amount, fee_paymongo, fee_rbnw, total_charged, status, expires_at)
             VALUES (?,?,?,?,?,?,?, 'pending', ?)"
        )->execute([
            $userId, $wallet['id'], $reference,
            $fees['amount'], $fees['fee_paymongo'], $fees['fee_rbnw'], $fees['total'],
            $expiresAt,
        ]);
        $requestId = (int)db()->lastInsertId();

        $intent = pm_create_qrph_intent($fees['total'], $reference, $requestId);

        if (!$intent['ok']) {
            db()->prepare("UPDATE rbnw_cash_in_requests SET status = 'failed' WHERE id = ?")
                ->execute([$requestId]);
            json_out(['success' => false, 'message' => $intent['message']]);
        }

        db()->prepare("UPDATE rbnw_cash_in_requests SET paymongo_payment_intent_id = ?, qr_image = ? WHERE id = ?")
            ->execute([$intent['pi_id'], $intent['qr_image'], $requestId]);

        json_out([
            'success'      => true,
            'reference'    => $reference,
            'pi_id'        => $intent['pi_id'],
            'amount'       => $fees['amount'],
            'fee_paymongo' => $fees['fee_paymongo'],
            'fee_rbnw'     => $fees['fee_rbnw'],
            'total'        => $fees['total'],
            'qr_image'     => $intent['qr_image'], // base64 PNG — use as <img src="...">
            'expires_in'   => CASH_IN_QR_EXPIRY_MINS * 60,
        ]);
        break;

    // Poll a pending Cash In request for payment confirmation.
    case 'status':
        $reference = trim($_GET['ref'] ?? '');
        if (!$reference) {
            json_out(['status' => 'invalid']);
        }

        $stmt = db()->prepare("SELECT * FROM rbnw_cash_in_requests WHERE reference_no = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$reference, $userId]);
        $req = $stmt->fetch();

        if (!$req) {
            json_out(['status' => 'not_found']);
        }

        if ($req['status'] === 'paid') {
            json_out(['status' => 'paid', 'amount' => number_format((float)$req['amount'], 2)]);
        }

        if (strtotime($req['expires_at']) < time()) {
            db()->prepare("UPDATE rbnw_cash_in_requests SET status = 'expired' WHERE id = ?")->execute([$req['id']]);
            json_out(['status' => 'expired']);
        }

        if (!empty($req['paymongo_payment_intent_id'])) {
            $piStatus = pm_get_intent_status($req['paymongo_payment_intent_id']);
            if ($piStatus === 'succeeded') {
                credit_cash_in($req);
                json_out(['status' => 'paid', 'amount' => number_format((float)$req['amount'], 2)]);
            }
        }

        json_out(['status' => $req['status']]);
        break;

    default:
        json_out(['success' => false, 'message' => 'Unknown action.'], 404);
}

} catch (Throwable $e) {
    json_out(['success' => false, 'status' => 'error', 'message' => 'Error: ' . $e->getMessage()], 500);
}
