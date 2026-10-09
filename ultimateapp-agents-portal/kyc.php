<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/kyc_page.php';
$user = require_auth();
kyc_send_csp();
header('Cache-Control: no-store');
$error = kyc_handle_post($pdo, 'user', (int) $user['id'], 'kyc.php');
$active = 'profile';
$pageTitle = 'Verify my identity';
require __DIR__ . '/includes/header.php';
?>
<section class="screen with-nav">
    <header class="page-head"><a href="profile.php" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>Verify my identity</h1><span></span></header>
    <?php foreach ($_SESSION['flash'] ?? [] as [$type, $message]): ?><p class="alert <?= $type === 'success' ? 'ok' : '' ?>" role="status"><?= e($message) ?></p><?php endforeach; unset($_SESSION['flash']); ?>
    <?php kyc_render($pdo, 'user', (int) $user['id'], (string) $user['full_name'], '', $error); ?>
    <?php require __DIR__ . '/includes/nav.php'; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
