<?php
require_once __DIR__ . '/_bootstrap.php';
if (agent_current()) redirect('dashboard.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim(ap('email')));
    rate_limit_enforce('agent_login_ip', 'ip:' . rate_limit_client_ip(), 60, 900);
    rate_limit_enforce('agent_login_id', 'id:' . $email, 10, 900);
    $stmt = $pdo->prepare('SELECT id, password_hash FROM agents WHERE email = ?');
    $stmt->execute([$email]);
    $a = $stmt->fetch();
    if ($a && password_verify(ap('password'), $a['password_hash'])) {
        session_fresh(['agent_id' => (int) $a['id']]);
        $pdo->prepare('UPDATE agents SET last_login_at = NOW() WHERE id = ?')->execute([$a['id']]);
        redirect('dashboard.php');
    }
    $error = 'Incorrect email or password.';
}
agent_header('Agent sign in');
?>
<form class="auth-card" method="post">
    <div class="brand"><span class="brand-mark"></span><span><b>ULTIMATE APP</b><small>Boracay · Agents Portal</small></span></div>
    <h1>Welcome back, Agent</h1><p>Sign in to get your referral link and see your earnings.</p>
    <?php if ($error): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="username" required value="<?= e(ap('email')) ?>"></div>
    <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
    <button class="btn grad" type="submit">Sign in</button>
    <p class="links">Not an agent yet? <a href="register.php">Become an Ultimate App Agent</a><br><small>Forgot your password? Contact Ultimate App support to reset it.</small></p>
</form>
<?php agent_footer();
