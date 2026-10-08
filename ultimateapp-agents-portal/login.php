<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/agents.php';
agent_capture_referral($pdo);
if (current_user()) {
    redirect('dashboard.php');
}

$error = '';
if (!empty($_GET['suspended'])) {
    $error = 'This account is suspended. Please contact Ultimate App support.';
}
if (!empty($_GET['social_error'])) {
    $error = (string) $_GET['social_error'];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $identifier = is_string($_POST['identifier'] ?? null) ? trim($_POST['identifier']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    rate_limit_enforce('login_ip', 'ip:' . rate_limit_client_ip(), 120, 900);
    rate_limit_enforce('login_identity', 'identity:' . strtolower($identifier), 12, 900);

    $validEmail = strlen($identifier) <= 160 && filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
    $validMobile = preg_match('/^(?:09[0-9]{9}|089[0-9]{8})$/D', $identifier) === 1;
    $user = false;
    if ($validEmail || $validMobile) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? OR mobile = ? LIMIT 1');
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();
    }

    if ($user && password_verify($password, $user['password_hash']) && ($user['status'] ?? 'active') !== 'active') {
        $error = 'This account is suspended. Please contact Ultimate App support.';
    } elseif ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $return = $_SESSION['mctc_return'] ?? '';
        unset($_SESSION['mctc_return']);
        redirect(is_string($return) && preg_match('/^(mctc-approve|convert-approve|driver-topup-pay)[.]php[?]token=[a-f0-9]{64}$/D', $return) ? $return : 'dashboard.php');
    } else {
        $error = 'Invalid email/mobile or password.';
    }
}

$pageTitle = 'Log In';
require __DIR__ . '/includes/header.php';
?>
<section class="auth-screen login-screen">
    <a class="back-link" href="index.php"><span data-icon="arrow-left"></span></a>
    <div class="auth-brand">
        <img src="assets/images/ultimate-logo-transparent.png" alt="Ultimate App">
    </div>
    <h1>Log In</h1>
    <p class="muted">Welcome back! Please log in to continue.</p>
    <?php if ($error): ?><p class="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" class="form-stack">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label class="field"><span data-icon="mail"></span><input name="identifier" required maxlength="160" autocomplete="username" placeholder="Email or 11-digit PH Mobile Number"></label>
        <label class="field"><span data-icon="lock"></span><input name="password" type="password" required autocomplete="current-password" placeholder="Password"><button class="icon-button reveal" type="button"><span data-icon="eye"></span></button></label>
        <a class="forgot" href="#">Forgot Password?</a>
        <button class="btn dark" type="submit">Log In</button>
    </form>
    <div class="divider"><span>OR</span></div>
    <div class="social-stack">
        <a href="oauth.php?provider=google">G&nbsp;&nbsp; Continue with Google</a>
        <a href="oauth.php?provider=apple">&nbsp;&nbsp; Continue with Apple</a>
        <a href="oauth.php?provider=facebook">f&nbsp;&nbsp; Continue with Facebook</a>
    </div>
    <p class="switch">Don't have an account? <a href="register.php">Sign Up</a></p>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
