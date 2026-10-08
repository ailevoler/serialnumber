<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/agents.php';
agent_capture_referral($pdo);
if (current_user()) {
    redirect('dashboard.php');
}
$pageTitle = 'Welcome';
require __DIR__ . '/includes/header.php';
?>
<section class="welcome-screen exact-welcome">
    <div class="hero-photo">
        <img class="welcome-logo" src="assets/images/ultimate-logo-transparent.png" alt="Ultimate App">
        <strong class="welcome-boracay">BORACAY</strong>
        <h2>One App. Many Possibilities.</h2>
        <p>Credits for a Smarter, Easier Everyday.</p>
    </div>
    <div class="welcome-actions">
        <a class="btn light" href="login.php">Log In</a>
        <a class="btn outline-light" href="register.php<?= agent_pending_referral_code() ? '?ref=' . e(agent_pending_referral_code()) : '' ?>">Create an Account</a>
        <a class="explore" href="dashboard.php">Explore More <span data-icon="chevron-right"></span></a><a class="explore" href="install.php">Install App</a>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
