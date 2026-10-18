<?php
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$input  = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST; // plain form posts still work
}

switch ($action) {

    case 'register':
        $fullName = preg_replace('/\s+/u', ' ', trim((string)($input['full_name'] ?? '')));
        $mobile   = normalize_mobile((string)($input['mobile_number'] ?? ''));
        $email    = strtolower(trim((string)($input['email'] ?? ''))) ?: null;
        $mpin     = trim((string)($input['mpin'] ?? ''));
        $mpinConfirm = trim((string)($input['mpin_confirm'] ?? ''));

        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 200) {
            json_out(['success' => false, 'message' => 'Please enter your full name.']);
        }
        if (strlen($mobile) !== 11 || !str_starts_with($mobile, '09')) {
            json_out(['success' => false, 'message' => 'Please enter a valid 11-digit mobile number.']);
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_out(['success' => false, 'message' => 'Please enter a valid email address, or leave it blank.']);
        }
        if (strlen($mpin) !== MPIN_LENGTH || !ctype_digit($mpin)) {
            json_out(['success' => false, 'message' => 'MPIN must be exactly 6 digits.']);
        }
        if (in_array($mpin, ['000000', '111111', '123456', '654321', '121212', '112233', '123123'], true)) {
            json_out(['success' => false, 'message' => 'That MPIN is too easy to guess. Try another.']);
        }
        if ($mpin !== $mpinConfirm) {
            json_out(['success' => false, 'message' => 'MPIN confirmation does not match.']);
        }

        $pdo = db();

        $stmt = $pdo->prepare("SELECT id FROM rbnw_users WHERE mobile_number = ? LIMIT 1");
        $stmt->execute([$mobile]);
        if ($stmt->fetch()) {
            json_out(['success' => false, 'message' => 'This mobile number is already registered. Please log in instead.']);
        }

        if ($email !== null) {
            $stmt = $pdo->prepare("SELECT id FROM rbnw_users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                json_out(['success' => false, 'message' => 'This email is already used by another account.']);
            }
        }

        // "Juan Dela Cruz" -> first "Juan Dela", last "Cruz"; single word -> last name blank
        $nameParts = explode(' ', $fullName);
        $lastName  = count($nameParts) > 1 ? array_pop($nameParts) : '';
        $firstName = implode(' ', $nameParts);

        try {
            $pdo->beginTransaction();

            $pdo->prepare(
                "INSERT INTO rbnw_users (wallet_code, mobile_number, email, mpin_hash, first_name, last_name, wallet_level, kyc_status, created_at)
                 VALUES (?,?,?,?,?,?,1,'unverified',NOW())"
            )->execute([
                // unique placeholder (wallet_code is UNIQUE) until we know the new id
                'TMP' . bin2hex(random_bytes(8)), $mobile, $email, password_hash($mpin, PASSWORD_DEFAULT), $firstName, $lastName,
            ]);

            $userId = (int)$pdo->lastInsertId();
            $walletCode = generate_wallet_code($userId);
            $pdo->prepare("UPDATE rbnw_users SET wallet_code = ? WHERE id = ?")->execute([$walletCode, $userId]);

            $pdo->prepare("INSERT INTO rbnw_wallets (user_id, balance, currency) VALUES (?, 0.00, 'PHP')")
                ->execute([$userId]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[RBNW] register failed: ' . $e->getMessage());
            $msg = 'Registration failed. Please try again.';
            if ($e instanceof PDOException && $e->getCode() === '23000') {
                $msg = 'This mobile number or email is already registered.';
            } elseif ($e instanceof PDOException && in_array($e->getCode(), ['42S02', '42S22'], true)) {
                $msg = 'Database tables are missing or outdated. Import database/schema.sql in phpMyAdmin.';
            }
            json_out(['success' => false, 'message' => $msg], 500);
        }

        // A failed welcome notification must never block a created account
        try {
            push_notification($userId, 'Welcome to RBNW Wallet!', 'Your account has been created. Verify your ID to unlock higher limits.', 'welcome');
        } catch (Throwable $e) {
            error_log('[RBNW] welcome notification failed: ' . $e->getMessage());
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        json_out(['success' => true, 'message' => 'Account created successfully.', 'wallet_code' => $walletCode]);
        break;

    case 'login':
        $mobile = normalize_mobile((string)($input['mobile_number'] ?? ''));
        $mpin   = trim((string)($input['mpin'] ?? ''));

        if (strlen($mobile) !== 11 || $mpin === '') {
            json_out(['success' => false, 'message' => 'Please enter your mobile number and MPIN.']);
        }

        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM rbnw_users WHERE mobile_number = ? LIMIT 1");
        $stmt->execute([$mobile]);
        $user = $stmt->fetch();

        if (!$user) {
            json_out(['success' => false, 'message' => 'No account found for that mobile number. Sign up first.']);
        }

        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $mins = ceil((strtotime($user['locked_until']) - time()) / 60);
            json_out(['success' => false, 'message' => "Account temporarily locked. Try again in {$mins} min(s)."], 423);
        }

        if (empty($user['mpin_hash']) || !password_verify($mpin, $user['mpin_hash'])) {
            $attempts = (int)$user['failed_pin_attempts'] + 1;
            $lockUntil = null;
            if ($attempts >= MAX_PIN_ATTEMPTS) {
                $lockUntil = date('Y-m-d H:i:s', time() + LOCKOUT_MINUTES * 60);
                $attempts = 0;
            }
            $pdo->prepare("UPDATE rbnw_users SET failed_pin_attempts = ?, locked_until = ? WHERE id = ?")
                ->execute([$attempts, $lockUntil, $user['id']]);

            $left = MAX_PIN_ATTEMPTS - $attempts;
            $msg = $lockUntil
                ? 'Too many failed attempts. Account locked for ' . LOCKOUT_MINUTES . ' minutes.'
                : "Incorrect MPIN. {$left} attempt(s) left.";
            json_out(['success' => false, 'message' => $msg]);
        }

        if ($user['status'] !== 'active') {
            json_out(['success' => false, 'message' => 'This account is ' . $user['status'] . '. Contact RBNW support.']);
        }

        $pdo->prepare("UPDATE rbnw_users SET failed_pin_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?")
            ->execute([$user['id']]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        json_out(['success' => true, 'message' => 'Login successful.']);
        break;

    case 'logout':
        $_SESSION = [];
        session_destroy();
        json_out(['success' => true, 'message' => 'Logged out.']);
        break;

    default:
        json_out(['success' => false, 'message' => 'Unknown action.'], 404);
}
