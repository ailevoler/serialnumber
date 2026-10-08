<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/agents.php';
agent_capture_referral($pdo);
if (current_user()) {
    redirect('dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('register_ip', 'ip:' . rate_limit_client_ip(), 20, 3600);
    $name = is_string($_POST['full_name'] ?? null) ? trim($_POST['full_name']) : '';
    $mobile = is_string($_POST['mobile'] ?? null) ? trim($_POST['mobile']) : '';
    $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
    $terms = ($_POST['terms'] ?? null) === 'on';
    $referral = is_string($_POST['referral_code'] ?? null) ? trim($_POST['referral_code']) : '';

    if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 120
        || preg_match('/^\p{L}+(?: \p{L}+)*$/uD', $name) !== 1) {
        $error = 'Name must be 2–120 letters and spaces only.';
    } elseif (!preg_match('/^(?:09[0-9]{9}|089[0-9]{8})$/D', $mobile)) {
        $error = 'Enter an 11-digit Philippine mobile number beginning with 09 or 089.';
    } elseif (strlen($email) > 160 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $error = 'Enter a valid email address (up to 160 characters).';
    } elseif (mb_strlen($password, 'UTF-8') < 8 || strlen($password) > 72) {
        $error = 'Password must be at least 8 characters and no more than 72 bytes.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (!$terms) {
        $error = 'Please agree to the terms and privacy policy.';
    } elseif ($referral !== '' && !agent_find_active_by_code($pdo, $referral)) {
        $error = 'That referral code was not found. Check it with your agent, or leave it blank.';
    } else {
        try {
            $stmt = $pdo->prepare('INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES (?, ?, ?, ?, ?, 0)');
            $stmt->execute([$name, $mobile, $email, password_hash($password, PASSWORD_DEFAULT), 'UA-' . strtoupper(bin2hex(random_bytes(5)))]);
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $pdo->lastInsertId();
            agent_attach_referral($pdo, (int) $_SESSION['user_id'], $referral !== '' ? $referral : agent_pending_referral_code());
            redirect('dashboard.php');
        } catch (PDOException $e) {
            $error = 'An account already exists with that email or mobile number.';
        }
    }
}

$pageTitle = 'Create Account';
require __DIR__ . '/includes/header.php';
?>
<section class="auth-screen register-screen">
    <a class="back-link" href="index.php"><span data-icon="arrow-left"></span></a>
    <div class="auth-brand">
        <img src="assets/images/ultimate-logo-transparent.png" alt="Ultimate App">
    </div>
    <h1>Create an Account</h1>
    <p class="muted">Join Ultimate App and unlock more possibilities.</p>
    <?php if ($error): ?><p class="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" class="form-stack">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label class="field"><span data-icon="user"></span><input name="full_name" required minlength="2" maxlength="120" pattern="[\p{L}]+( [\p{L}]+)*" title="Letters and spaces only" autocomplete="name" placeholder="Full Name"></label>
        <label class="field"><span data-icon="phone"></span><input name="mobile" type="tel" inputmode="numeric" required minlength="11" maxlength="11" pattern="(?:09[0-9]{9}|089[0-9]{8})" title="11 digits, starting with 09 or 089" autocomplete="tel-national" placeholder="PH Mobile Number (11 digits)"></label>
        <label class="field"><span data-icon="mail"></span><input name="email" type="email" required maxlength="160" autocomplete="email" placeholder="Email Address"></label>
        <label class="field"><span data-icon="lock"></span><input name="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password" placeholder="Create Password"><button class="icon-button reveal" type="button"><span data-icon="eye"></span></button></label>
        <label class="field"><span data-icon="lock"></span><input name="confirm_password" type="password" required minlength="8" maxlength="72" autocomplete="new-password" placeholder="Confirm Password"><button class="icon-button reveal" type="button"><span data-icon="eye"></span></button></label>
        <?php $refValue = is_string($_POST['referral_code'] ?? null) ? $_POST['referral_code'] : agent_pending_referral_code(); ?>
        <label class="field"><span data-icon="user"></span><input name="referral_code" maxlength="12" autocapitalize="characters" autocomplete="off" value="<?= e($refValue) ?>" placeholder="Agent referral code (optional)" aria-label="Agent referral code (optional)"></label>
        <label class="check-row"><input name="terms" type="checkbox" required> <span>I agree to the <a href="#">Terms and Conditions</a> and <a href="#">Privacy Policy</a>.</span></label>
        <button class="btn dark" type="submit">Sign Up</button>
    </form>
    <p class="switch">Already have an account? <a href="login.php">Log In</a></p>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
