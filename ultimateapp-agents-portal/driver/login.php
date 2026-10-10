<?php
require_once __DIR__ . '/_bootstrap.php';
if (driver_current()) redirect('dashboard.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $login = strtolower(trim(dp('login')));
    $mobile = preg_replace('/\D+/', '', $login);
    if (str_starts_with((string) $mobile, '63')) $mobile = '0' . substr($mobile, 2);
    rate_limit_enforce('driver_login_ip', 'ip:' . rate_limit_client_ip(), 60, 900);
    rate_limit_enforce('driver_login_id', 'id:' . $login, 10, 900);
    $stmt = $pdo->prepare('SELECT id, password_hash FROM uride_drivers WHERE email = ? OR (? <> \'\' AND mobile = ?) LIMIT 1');
    $stmt->execute([$login, $mobile, $mobile]);
    $d = $stmt->fetch();
    if ($d && password_verify(dp('password'), $d['password_hash'])) {
        session_fresh(['driver_id' => (int) $d['id']]);
        $pdo->prepare('UPDATE uride_drivers SET last_login_at = NOW() WHERE id = ?')->execute([$d['id']]);
        redirect('dashboard.php');
    }
    $error = 'Incorrect email/mobile or password.';
}
driver_header('Driver sign in');
?>
<form class="auth-card" method="post">
    <div class="brand"><span class="brand-mark"></span><span><b>URIDE DRIVER</b><small>Ultimate App Boracay</small></span></div>
    <h1>Drive with URide</h1><p>Sign in to go online, accept nearby rides and manage your wallet.</p>
    <?php if ($error): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="field"><label for="login">Email or mobile number</label><input id="login" name="login" type="text" autocomplete="username" required value="<?= e(dp('login')) ?>"></div>
    <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
    <button class="btn grad" type="submit">Sign in</button>
    <p class="links">Not a driver yet? <a href="register.php">Apply to drive</a><br><small>Forgot your password? Contact Ultimate App support to reset it.</small><br><a href="install.php">Install the URide Driver app</a></p>
</form>
<?php driver_footer();
