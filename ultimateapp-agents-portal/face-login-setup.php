<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/face_login.php';
$user = require_auth();
$uid = (int) $user['id'];
$kyc = face_login_ready() ? face_login_kyc($pdo, $uid) : null;

// The person's own approved KYC selfie, only for older checks that have no face numbers yet (made on the phone).
if (($_GET['kyc_selfie'] ?? '') === '1') {
    if (!$kyc || $kyc['face_descriptor']) { http_response_code(404); exit('Not found.'); }
    kyc_send_file((string) $kyc['selfie']);
}

kyc_send_csp();
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'enroll') {
        header('Content-Type: application/json');
        rate_limit_enforce('face_enroll', 'user:' . $uid, 10, 3600);
        try {
            face_login_enroll($pdo, $uid, $_POST);
            $_SESSION['flash'][] = ['success', 'Face Login is on for this phone. Next time, tap "Log in with Face".'];
            echo json_encode(['ok' => true, 'redirect' => 'face-login-setup.php']);
        } catch (InvalidArgumentException $ex) {
            echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
        }
        exit;
    }
    if ($action === 'revoke') {
        $n = face_login_revoke($pdo, $uid, ($_POST['device'] ?? '') === 'all' ? null : max(1, (int) ($_POST['device'] ?? 0)));
        $_SESSION['flash'][] = ['success', $n ? 'Face Login turned off.' : 'Nothing to turn off.'];
        redirect('face-login-setup.php');
    }
    redirect('face-login-setup.php');
}

$devices = face_login_devices($pdo, $uid);
$thisDevice = face_login_device($pdo, true);
$thisIsMine = $thisDevice && (int) $thisDevice['user_id'] === $uid;
$active = 'profile';
$pageTitle = 'Face Login';
require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="assets/css/kyc.css?v=3">
<section class="screen with-nav">
    <header class="page-head"><a href="profile.php" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>Face Login</h1><span></span></header>
    <?php foreach ($_SESSION['flash'] ?? [] as [$type, $message]): ?><p class="alert <?= $type === 'success' ? 'ok' : '' ?>" role="status"><?= e($message) ?></p><?php endforeach; unset($_SESSION['flash']); ?>
    <section class="kyc">
    <?php if (!face_login_enabled()): ?>
        <div class="kyc-status"><h2>Face Login is not available</h2><p>Face Login is turned off for now. You can log in with your password.</p></div>
    <?php elseif (!$kyc): ?>
        <div class="kyc-status"><h2>Verify your identity first</h2><p>Face Login uses the face from your approved ID check, so only you can log in with your face.</p><p><a class="kyc-btn primary" href="kyc.php">Verify my identity</a></p></div>
    <?php else: ?>
        <div class="kyc-status <?= $thisIsMine ? 'kyc-approved' : '' ?>"><h2>This phone <?= $thisIsMine ? ($thisDevice['locked_at'] ? '<span class="badge warn">Locked</span>' : '<span class="badge good">Face Login on</span>') : '<span class="badge">Off</span>' ?></h2>
            <p><?= $thisIsMine ? 'On the log-in page, tap <b>Log in with Face</b>. Your password still works too.' : 'Log in with your face on this phone, without typing your password. We check that it is really you (liveness) and that your face matches your approved ID check.' ?></p>
            <?php if ($thisIsMine && $thisDevice['locked_at']): ?><p>It was locked after too many tries. Set it up again below to unlock it.</p><?php endif; ?>
        </div>
        <div class="kyc-form" data-face-login="enroll" data-csrf="<?= e(csrf_token()) ?>" data-endpoint="face-login-setup.php" data-max-distance="<?= e((string) kyc_face_max_distance()) ?>"<?= $kyc['face_descriptor'] ? '' : ' data-kyc-selfie="face-login-setup.php?kyc_selfie=1"' ?>>
            <p class="kyc-hint"><b><?= $thisIsMine ? 'Set up again' : 'Turn on for this phone' ?>:</b> look straight, turn LEFT, turn RIGHT, then BLINK. The side to turn to <b class="kyc-red">flashes red</b> and turns <b class="kyc-green">green</b> when you do it right. Use this only on your own phone.</p>
            <div class="kyc-cam">
                <video playsinline muted autoplay data-video></video>
                <canvas class="kyc-overlay" aria-hidden="true" data-overlay></canvas>
                <p class="kyc-say" data-say role="status" aria-live="polite">Tap Start to turn on the front camera.</p>
            </div>
            <ol class="face-steps" data-steps aria-label="Steps"></ol>
            <div class="kyc-match" data-match role="status" aria-live="polite" hidden></div>
            <div class="kyc-actions"><button type="button" class="kyc-btn primary" data-start>Start</button></div>
        </div>
        <script src="assets/js/face-engine.js?v=2" data-vendor="assets/vendor/" defer></script>
        <script src="assets/js/face-login.js?v=1" defer></script>
    <?php endif; ?>
    <?php if ($devices): ?>
        <div class="kyc-status"><h2>Phones with Face Login</h2>
            <ul class="face-devices">
            <?php foreach ($devices as $d): ?>
                <li><span><b><?= e((string) $d['label']) ?></b><?= $thisIsMine && (int) $thisDevice['id'] === (int) $d['id'] ? ' (this phone)' : '' ?><?= $d['locked_at'] ? ' <span class="badge warn">Locked</span>' : '' ?><br><small>Added <?= e(date('M j, Y', strtotime($d['created_at']))) ?> · Last used <?= $d['last_used_at'] ? e(date('M j, Y g:i A', strtotime($d['last_used_at']))) : 'never' ?></small></span>
                    <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="device" value="<?= (int) $d['id'] ?>"><button class="kyc-btn" type="submit">Turn off</button></form></li>
            <?php endforeach; ?>
            </ul>
            <?php if (count($devices) > 1): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="device" value="all"><button class="kyc-btn" type="submit">Turn off on all phones</button></form><?php endif; ?>
            <p class="kyc-hint">Lost your phone? Turn it off here. Face Login also locks itself after 5 wrong tries.</p>
        </div>
    <?php endif; ?>
    </section>
    <?php require __DIR__ . '/includes/nav.php'; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
