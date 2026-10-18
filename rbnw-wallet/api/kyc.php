<?php
require_once __DIR__ . '/../includes/kyc.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$userId = current_user_id();

switch ($action) {

    case 'submit':
        $user = get_user($userId);
        if ($user['kyc_status'] === 'verified') {
            json_out(['success' => false, 'message' => 'Your account is already verified.']);
        }
        $latest = kyc_latest($userId);
        if ($latest && $latest['status'] === 'pending') {
            json_out(['success' => false, 'message' => 'Your verification is already under review.']);
        }

        $idType   = trim((string)($_POST['id_type'] ?? ''));
        $idNumber = strtoupper(trim(preg_replace('/\s+/', ' ', (string)($_POST['id_number'] ?? ''))));
        $birth    = trim((string)($_POST['birthdate'] ?? ''));
        $address  = trim(preg_replace('/\s+/', ' ', (string)($_POST['home_address'] ?? '')));
        $liveness = json_decode((string)($_POST['liveness'] ?? ''), true);

        if (!array_key_exists($idType, KYC_ID_TYPES)) {
            json_out(['success' => false, 'message' => 'Choose a valid ID type.']);
        }
        if (!preg_match('/^[A-Z0-9\- ]{4,40}$/', $idNumber)) {
            json_out(['success' => false, 'message' => 'Enter the ID number exactly as shown on your ID.']);
        }
        $bd = DateTime::createFromFormat('Y-m-d', $birth);
        if (!$bd || $bd->format('Y-m-d') !== $birth || $bd > new DateTime('-18 years') || $bd < new DateTime('-120 years')) {
            json_out(['success' => false, 'message' => 'Enter a valid birthdate. You must be at least 18 years old.']);
        }
        if (mb_strlen($address) < 10 || mb_strlen($address) > 255) {
            json_out(['success' => false, 'message' => 'Enter your complete home address.']);
        }
        $required = ['left', 'right', 'up', 'down', 'blink'];
        if (!is_array($liveness) || array_diff($required, (array)($liveness['passed'] ?? []))) {
            json_out(['success' => false, 'message' => 'Please complete the selfie liveness check.']);
        }

        try {
            $front  = kyc_store_image($_FILES['id_front'] ?? [], $userId, 'ID front');
            $back   = KYC_ID_TYPES[$idType] ? kyc_store_image($_FILES['id_back'] ?? [], $userId, 'ID back') : null;
            $selfie = kyc_store_image($_FILES['selfie'] ?? [], $userId, 'selfie');
        } catch (RuntimeException $e) {
            json_out(['success' => false, 'message' => $e->getMessage()]);
        }

        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO rbnw_kyc_submissions (user_id, id_type, id_number, id_front_path, id_back_path, selfie_path, liveness)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([$userId, $idType, $idNumber, $front, $back, $selfie, json_encode([
            'passed'      => array_values(array_intersect($required, (array)$liveness['passed'])),
            'order'       => array_slice((array)($liveness['order'] ?? []), 0, 5),
            'duration_ms' => (int)($liveness['duration_ms'] ?? 0),
            'user_agent'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
        ])]);
        $pdo->prepare("UPDATE rbnw_users SET kyc_status = 'pending', birthdate = ?, home_address = ? WHERE id = ?")
            ->execute([$birth, $address, $userId]);
        $pdo->commit();

        try {
            push_notification($userId, 'Verification submitted', "We're reviewing your ID. This usually takes 1 banking day.", 'kyc');
        } catch (Throwable $e) {
            error_log('[RBNW] kyc notification failed: ' . $e->getMessage());
        }
        json_out(['success' => true]);
        break;

    default:
        json_out(['success' => false, 'message' => 'Unknown action.'], 404);
}
