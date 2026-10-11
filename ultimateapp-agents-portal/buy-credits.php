<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_auth();
$fees = app_fees();
$buyFee = (int) $fees['buy_credits_centavos'];
$packages = [
    ['credits' => 100, 'price' => 100],
    ['credits' => 500, 'price' => 500, 'popular' => true],
    ['credits' => 1000, 'price' => 1000],
    ['credits' => 2500, 'price' => 2500],
    ['credits' => 5000, 'price' => 5000],
    ['credits' => 10000, 'price' => 10000],
];
$methods = [
    ['code' => 'qrph', 'label' => 'QR Ph · GCash, Maya & bank apps', 'active' => true, 'icon' => 'qr-code'],
    ['code' => 'mctc', 'label' => 'Merchant Credits Top Up Center (MCTC)', 'active' => true, 'icon' => 'credit-card'],
    ['code' => 'card', 'label' => 'Credit / Debit Card', 'active' => false, 'icon' => 'credit-card'],
    ['code' => 'gcash', 'label' => 'GCash', 'active' => false, 'icon' => 'credit-card'],
    ['code' => 'maya', 'label' => 'Maya', 'active' => false, 'icon' => 'credit-card'],
    ['code' => 'bank', 'label' => 'Bank Transfer', 'active' => false, 'icon' => 'credit-card'],
];

require_once __DIR__ . '/includes/paymongo.php';
$error = '';
$config = pm_config();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('topup_create', 'user:' . $user['id'], 12, 3600);
    $credits = filter_var($_POST['credits'] ?? '', FILTER_VALIDATE_INT);
    $methodCode = is_string($_POST['method'] ?? null) ? $_POST['method'] : '';
    $requestToken = is_string($_POST['topup_token'] ?? null) ? $_POST['topup_token'] : '';
    $valid = in_array($credits, array_column($packages, 'credits'), true);
    if (!$valid || !in_array($methodCode, ['qrph', 'mctc'], true)) {
        $error = 'Choose a valid credit package and payment method.';
    } elseif (!$requestToken || !hash_equals($_SESSION['topup_token'] ?? '', $requestToken)) {
        $error = 'This request was already submitted or expired. Check your wallet before trying again.';
    } else {
        try {
            if ($methodCode === 'qrph') {
                pm_api_key($config);
            }
            unset($_SESSION['topup_token']);
            if ($methodCode === 'qrph') {
                require_once __DIR__ . '/includes/topup.php';
                redirect(topup_start_qrph($pdo, $user, $credits, $buyFee, $config));
            }
            $reference = 'UA-' . bin2hex(random_bytes(16));
            $mctcToken = bin2hex(random_bytes(32));
            $amountCentavos = $credits * 100 + $buyFee;
            $stmt = $pdo->prepare('INSERT INTO credit_topups (reference, user_id, credits, amount_centavos, fee_centavos, method, mode, mctc_qr_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$reference, $user['id'], $credits, $amountCentavos, $buyFee, $methodCode, $config['mode'], $mctcToken]);
            redirect('payment-status.php?reference=' . rawurlencode($reference));
        } catch (Throwable $exception) {
            $safeReason = $exception instanceof PDOException
                ? 'database_error'
                : preg_replace('/[^a-zA-Z0-9 ._()\-]/', '', $exception->getMessage());
            error_log('Top-up checkout unavailable: reference=' . ($reference ?? 'none')
                . ' type=' . get_class($exception) . ' code=' . $exception->getCode()
                . ' reason=' . $safeReason);
            $error = pm_checkout_user_message($exception, $config['mode']);
            if (isset($reference)) {
                $error .= ' Reference: ' . $reference . '.';
            }
        }
    }
}
if (empty($_SESSION['topup_token'])) {
    $_SESSION['topup_token'] = bin2hex(random_bytes(32));
}
$pageTitle = 'Buy Credits';
require __DIR__ . '/includes/header.php';
?>
<section class="screen buy-screen">
    <header class="page-head"><a href="dashboard.php"><span data-icon="arrow-left"></span></a><h1>Buy Credits</h1><span></span></header>
    <section class="mini-balance"><span class="brand-mark tiny">u</span><div><small>Current Balance</small><strong><?= format_credits((float) $user['credits']) ?></strong><p>Credits</p></div></section>
    <section class="credit-uses" aria-label="Where to use Credits"><h2 class="section-title">Use your Credits on</h2>
        <div class="credit-uses-row"><?php foreach (credits_uses() as $u): ?><a href="<?= e($u['href']) ?>"><span><?php if ($u['image']): ?><img src="assets/images/services/<?= e($u['image']) ?>?v=1" alt="" width="44" height="44"><?php else: ?><i data-icon="<?= e($u['icon']) ?>"></i><?php endif; ?></span><b><?= e($u['label']) ?></b><small><?= e($u['what']) ?></small></a><?php endforeach; ?></div>
    </section>
    <h2 class="section-title">Select Credit Package</h2>
    <p class="rate-note">1 Credit = ₱1.00</p>
    <p class="rate-note">Service fee: PHP <?= number_format($buyFee / 100, 2) ?> per top-up. Example: 100 Credits = PHP <?= number_format(100 + $buyFee / 100, 2) ?> total.</p>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
    <?php if ($config['mode'] === 'test'): ?><p class="environment-note">Test environment. QR Ph payments here are for testing only.</p><?php endif; ?>
    <form method="post">
        <input type="hidden" name="topup_token" value="<?= e($_SESSION['topup_token']) ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="package-grid">
            <?php foreach ($packages as $pkg): ?>
                <label class="package-card">
                    <?php if (!empty($pkg['popular'])): ?><em>Popular</em><?php endif; ?>
                    <input type="radio" name="credits" value="<?= e((string) $pkg['credits']) ?>" required>
                    <strong><?= number_format($pkg['credits']) ?></strong>
                    <span>Credits</span>
                    <b>₱<?= number_format($pkg['price'], 2) ?></b>
                </label>
            <?php endforeach; ?>
        </div>
        <h2 class="section-title">Payment Method</h2>
        <div class="method-list payment-methods">
            <?php foreach ($methods as $method): ?>
                <label class="<?= $method['active'] ? 'active-method' : 'disabled-method' ?>">
                    <input type="radio" name="method" value="<?= e($method['code']) ?>" <?= $method['active'] ? 'required' : 'disabled' ?>>
                    <span data-icon="<?= e($method['icon']) ?>"></span>
                    <b><?= e($method['label']) ?></b>
                    <?php if (!$method['active']): ?><em>Coming soon</em><?php endif; ?>
                    <span data-icon="chevron-right"></span>
                </label>
            <?php endforeach; ?>
        </div>
        <button class="btn dark sticky-action buy-accent-action" type="submit">Buy Credits Now</button>
    </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
