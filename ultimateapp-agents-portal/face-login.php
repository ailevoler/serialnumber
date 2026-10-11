<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/face_login.php';
if (current_user()) redirect('dashboard.php');
kyc_send_csp();
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    verify_csrf();
    rate_limit_enforce('face_login_ip', 'ip:' . rate_limit_client_ip(), 40, 900);
    try {
        if (($_POST['action'] ?? '') === 'challenge') {
            echo json_encode(['ok' => true] + face_login_challenge($pdo));
            exit;
        }
        if (($_POST['action'] ?? '') === 'verify') {
            $user = face_login_verify($pdo, $_POST);
            session_fresh(['user_id' => (int) $user['id']]);
            echo json_encode(['ok' => true, 'redirect' => 'dashboard.php']);
            exit;
        }
        throw new InvalidArgumentException('Unknown request.');
    } catch (InvalidArgumentException $ex) {
        echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
        exit;
    }
}

$device = face_login_enabled() ? face_login_device($pdo, true) : null;
$pageTitle = 'Log in with Face';
require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="assets/css/kyc.css?v=3">
<section class="auth-screen login-screen face-login">
    <a class="back-link" href="login.php" aria-label="Back"><span data-icon="arrow-left"></span></a>
    <h1>Log in with Face</h1>
    <?php if (!face_login_enabled()): ?>
        <p class="alert">Face Login is turned off. Please log in with your password.</p>
        <a class="btn dark" href="login.php">Log in with password</a>
    <?php elseif (!$device): ?>
        <p class="muted">Face Login is not set up on this phone. Log in with your password, then go to <b>Profile › Face Login</b> to turn it on. You need a verified ID first.</p>
        <a class="btn dark" href="login.php">Log in with password</a>
    <?php elseif ($device['locked_at'] !== null): ?>
        <p class="alert">Face Login is locked on this phone after too many tries. Log in with your password to unlock it.</p>
        <a class="btn dark" href="login.php">Log in with password</a>
    <?php else: ?>
        <section class="kyc">
        <p class="kyc-hint">Hi <b><?= e(explode(' ', trim((string) $device['full_name']))[0] ?: 'there') ?></b>! Follow the steps. The side of the frame to turn to <b class="kyc-red">flashes red</b> and turns <b class="kyc-green">green</b> when you do it right. The steps change every time.</p>
        <div data-face-login="login" data-csrf="<?= e(csrf_token()) ?>" data-endpoint="face-login.php">
            <div class="kyc-cam">
                <video playsinline muted autoplay data-video></video>
                <canvas class="kyc-overlay" aria-hidden="true" data-overlay></canvas>
                <p class="kyc-say" data-say role="status" aria-live="polite">Tap Start to turn on the front camera.</p>
            </div>
            <ol class="face-steps" data-steps aria-label="Steps"></ol>
            <div class="kyc-match" data-match role="status" aria-live="polite" hidden></div>
            <div class="kyc-actions"><button type="button" class="kyc-btn primary" data-start>Start</button></div>
        </div>
        </section>
        <p class="switch"><a href="login.php">Use password instead</a></p>
        <script src="assets/js/face-engine.js?v=2" data-vendor="assets/vendor/" defer></script>
        <script src="assets/js/face-login.js?v=1" defer></script>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
