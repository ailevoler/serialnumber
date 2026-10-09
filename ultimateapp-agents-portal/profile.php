<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/kyc.php';
$user = require_auth();
$active = 'profile';
$pageTitle = 'Profile';
require __DIR__ . '/includes/header.php';
?>
<section class="screen with-nav">
    <header class="page-head"><a href="dashboard.php"><span data-icon="arrow-left"></span></a><h1>Profile</h1><form method="post" action="logout.php"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button type="submit" aria-label="Log out"><span data-icon="log-out"></span></button></form></header>
    <section class="profile-card">
        <div class="avatar large"><span data-icon="user"></span></div>
        <h2><?= e($user['full_name']) ?></h2>
        <p><?= e($user['email']) ?></p>
        <strong><?= format_credits((float) $user['credits']) ?> Credits</strong>
    </section>
    <div class="method-list">
        <?php if (($user['role'] ?? 'customer') === 'mctc'): ?><a href="merchant.php"><span data-icon="wallet"></span> Merchant Portal <span data-icon="chevron-right"></span></a><a href="mctc.php"><span data-icon="qr-code"></span> MCTC Scanner <span data-icon="chevron-right"></span></a><?php endif; ?><a href="install.php"><span data-icon="smartphone"></span> Install Ultimate App <span data-icon="chevron-right"></span></a>
        <?php $kycState = kyc_status($pdo, 'user', (int) $user['id']); ?><a href="kyc.php"><span data-icon="user"></span> Verify my identity · <?= e(kyc_statuses()[$kycState]) ?> <span data-icon="chevron-right"></span></a>
        <a href="transactions.php"><span data-icon="receipt"></span> Transaction History <span data-icon="chevron-right"></span></a>
        <a href="qr.php"><span data-icon="qr-code"></span> My QR Code <span data-icon="chevron-right"></span></a>
        <a href="rewards.php"><span data-icon="gift"></span> Rewards <span data-icon="chevron-right"></span></a>
        <form method="post" action="logout.php"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button type="submit"><span data-icon="log-out"></span> Log Out <span data-icon="chevron-right"></span></button></form>
    </div>
    <?php require __DIR__ . '/includes/nav.php'; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
