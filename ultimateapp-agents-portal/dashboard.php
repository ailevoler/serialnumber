<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/notifications.php';
$user = require_auth();
rate_limit_enforce('dashboard_view', 'user:' . $user['id'], 120, 60);
$active = 'home';

// Verify one recent checkout when a customer returns without a webhook update.
try {
    require_once __DIR__ . '/includes/paymongo.php';
    $paymongo = pm_config();
    $pendingStmt = $pdo->prepare("SELECT * FROM credit_topups WHERE user_id = ? AND method = 'qrph' AND status = 'pending' AND checkout_session_id IS NOT NULL AND mode = ? ORDER BY created_at DESC LIMIT 5");
    $pendingStmt->execute([$user['id'], $paymongo['mode']]);
    foreach ($pendingStmt->fetchAll() as $pendingOrder) {
        $checkedAt = $_SESSION['paymongo_checked'][$pendingOrder['reference']] ?? 0;
        if (time() - $checkedAt < 15) {
            continue;
        }
        if (!rate_limit_take($pdo, 'dashboard_reconcile', 'user:' . $user['id'], 12, 60)['allowed']) {
            break;
        }
        $_SESSION['paymongo_checked'][$pendingOrder['reference']] = time();
        pm_reconcile_order($pdo, $pendingOrder, $paymongo);
        break;
    }
    $user = current_user();
} catch (Throwable $error) {
    error_log('Dashboard PayMongo reconciliation failed: ' . $error->getMessage());
}

$pendingStmt = $pdo->prepare("SELECT reference, credits, method, created_at FROM credit_topups WHERE user_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 3");
$pendingStmt->execute([$user['id']]);
$pendingTopups = $pendingStmt->fetchAll();

$tx = $pdo->prepare('SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 3');
$tx->execute([$user['id']]);
$latest = $tx->fetchAll();

$cashStmt = $pdo->prepare('SELECT balance_centavos FROM boracay_cash_wallets WHERE user_id = ?');
$cashStmt->execute([$user['id']]);
$cashCentavos = (int) ($cashStmt->fetch()['balance_centavos'] ?? 0);
$cashConfig = require __DIR__ . '/config/boracay_cash.php';
// Live USD/PHP rate and the customer's USD wallet (USD ⇄ PHP exchange). The home screen never waits for the rate API.
require_once __DIR__ . '/includes/fx.php';
$fxRate = null; $usdCents = 0;
try { $fxRate = fx_current($pdo, false); $usdCents = fx_usd_balance($pdo, (int) $user['id']); } catch (Throwable $e) { error_log('Dashboard FX failed: ' . $e->getMessage()); }
$usdRate = $fxRate ? number_format($fxRate['micro'] / 1000000, 4, '.', '') : ($cashConfig['usd_php_rate'] === null ? '' : (string) $cashConfig['usd_php_rate']);
$unread = unread_notification_count(user_notifications($pdo, (int) $user['id']));
$unreadNews = unread_news_count($pdo, (int) $user['id']);

$pageTitle = 'Home';
require __DIR__ . '/includes/header.php';
?>
<section class="screen with-nav dashboard-screen">
    <header class="topbar">
        <button class="icon-button" type="button" data-menu-open aria-controls="app-menu" aria-expanded="false" aria-label="Open menu" title="Menu"><span data-icon="menu"></span></button>
        <div class="brand-swap"><div class="reference-wordmark" aria-label="Ultimate App Boracay"><span class="brand-symbol" aria-hidden="true"></span><span class="brand-name">ULTIMATE APP<strong>BORACAY</strong></span></div><img class="bcash-wordmark" src="assets/images/bcash/bcash-logo.png?v=1" alt="" width="720" height="223" aria-hidden="true"></div>
        <a class="icon-button notif-link" href="notifications.php" aria-label="Notifications<?= $unread ? ': ' . $unread . ' unread' : '' ?>" title="Notifications"><span data-icon="bell"></span><?php if ($unread): ?><i><?= $unread > 9 ? '9+' : $unread ?></i><?php endif; ?></a>
    </header>
    <?php require __DIR__ . '/includes/menu.php'; ?>
    <div class="hello-row">
        <div class="avatar"><span data-icon="user"></span></div>
        <div><small>Hello,</small><strong><?= e($user['full_name']) ?></strong></div>
    </div>
    <div class="wallet-carousel home-wallets" id="wallet-carousel" aria-label="Wallets">
        <section class="home-wallet home-wallet-credits wallet-slide" aria-label="Credits wallet">
            <span class="wallet-watermark brand-symbol" aria-hidden="true"></span>
            <div class="hw-brand"><span>ULTIMATE APP <b>BORACAY</b></span><em>CREDITS WALLET</em></div>
            <div class="hw-balance">
                <div><small>Your Credits</small><h1 data-balance-amount><?= format_credits((float) $user['credits']) ?></h1><span class="hw-unit">Credits</span></div>
                <button type="button" class="hw-eye" data-balance-toggle aria-label="Hide balance" title="Hide balance" aria-pressed="false"><span data-icon="eye"></span></button>
            </div>
            <nav class="hw-actions" aria-label="Credits actions">
                <a href="buy-credits.php" class="primary"><i><span data-icon="shopping-cart"></span></i>Buy</a>
                <a href="convert.php"><i><span data-icon="refresh-cw"></span></i>Convert</a>
                <a href="transactions.php"><i><span data-icon="receipt"></span></i>History</a>
                <a href="qr.php"><i><span data-icon="qr-code"></span></i>Pay QR</a>
            </nav>
        </section>
        <section class="home-wallet home-wallet-cash wallet-slide" aria-label="BCash wallet" data-cash-centavos="<?= $cashCentavos ?>" data-usd-rate="<?= e($usdRate) ?>" data-usd-cents="<?= $usdCents ?>">
            <div class="hw-brand"><span class="bcash-brand"><img src="assets/images/bcash/bcash-icon-96.png?v=1" alt="" width="24" height="24">BCash</span><em>ULTIMATE APP BORACAY</em></div>
            <div class="hw-balance">
                <div><small>Your BCash</small><h1 id="boracay-cash-balance">PHP <?= number_format($cashCentavos / 100, 2) ?></h1><span class="hw-unit" id="boracay-cash-note">PHP wallet balance</span></div>
                <div class="hw-side">
                    <button type="button" class="hw-eye" data-cash-balance-toggle aria-label="Hide BCash balance" title="Hide balance" aria-pressed="false"><span data-icon="eye"></span></button>
                    <div class="cash-currency hw-currency" role="group" aria-label="Display currency"><button type="button" class="active" data-currency="PHP" aria-pressed="true">PHP</button><button type="button" data-currency="USD" aria-pressed="false">USD</button></div><a class="hw-fx-link" href="bcash-exchange.php">USD ⇄ PHP</a>
                </div>
            </div>
            <nav class="hw-actions" aria-label="BCash actions">
                <a href="bcash-qr.php" class="primary"><i><span data-icon="arrow-right"></span></i>Send</a>
                <a href="bcash-qr.php?tab=receive"><i><span data-icon="qr-code"></span></i>Receive</a>
                <a href="boracay-cash.php"><i><span data-icon="refresh-cw"></span></i>Convert</a>
                <a href="transactions.php?wallet=bcash"><i><span data-icon="receipt"></span></i>History</a>
            </nav>
        </section>
    </div>
    <div class="wallet-pager hw-pager" role="group" aria-label="Choose wallet"><button type="button" class="active" data-wallet-page="0" aria-label="Show Credits wallet" aria-pressed="true"></button><button type="button" data-wallet-page="1" aria-label="Show BCash wallet" aria-pressed="false"></button></div>
    <section class="home-section-head"><h2 class="tiles-title-main">Services</h2><h2 class="tiles-title-bcash">BCash</h2></section>
    <section class="service-grid home-tiles tiles-bcash" aria-label="BCash quick actions">
        <?php foreach (bcash_dashboard_tiles() as $tile): ?>
            <a href="<?= e($tile['href']) ?>" class="service-tile" aria-label="<?= e($tile['label']) ?>">
                <span class="tile-icon"><img src="assets/images/services/<?= e($tile['image']) ?>?v=1" alt="" width="68" height="68"></span><span class="tile-label"><?= e($tile['short'] ?? $tile['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </section>
    <section class="service-grid home-tiles tiles-main" aria-label="Services">
        <?php foreach (dashboard_tiles() as $tile): $badge = $tile['code'] === 'News' ? $unreadNews : 0; ?>
            <?php if ($tile['href'] === null): ?>
            <button type="button" class="service-tile" data-menu-open aria-controls="app-menu" aria-expanded="false">
                <span class="tile-icon"><img src="assets/images/services/<?= e($tile['image']) ?>?v=20260928-2" alt="" width="68" height="68"></span><span class="tile-label"><?= e($tile['short'] ?? $tile['label']) ?></span>
            </button>
            <?php else: ?>
            <a href="<?= e($tile['href']) ?>" class="service-tile"<?= $badge ? ' aria-label="' . e($tile['label']) . ', ' . $badge . ' new"' : '' ?>>
                <span class="tile-icon"><img src="assets/images/services/<?= e($tile['image']) ?>?v=20260928-2" alt="" width="68" height="68"></span><?php if ($badge): ?><b class="tile-badge" aria-hidden="true"><?= $badge > 9 ? '9+' : $badge ?></b><?php endif; ?><span class="tile-label"><?= e($tile['short'] ?? $tile['label']) ?></span>
            </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </section>
    <section class="banner-slider image-slider" aria-label="Promotions">
        <div class="banner-track">
            <article class="banner-slide active discover-slide"><a href="service.php?service=UGo"><img src="assets/images/welcome-beach-clean.png" alt="Turquoise island water and a white sand beach"><span class="discover-copy"><span>Discover</span><strong>BORACAY</strong></span></a></article>
            <article class="banner-slide"><img src="assets/images/banners/banner-credits.png?v=20260923-17" alt="More Credits. More Possibilities."></article>
            <article class="banner-slide"><img src="assets/images/banners/banner-qr.png?v=20260928-2" alt="Scan QR. Pay Faster."></article>
            <article class="banner-slide"><img src="assets/images/banners/banner-services.png?v=20260928-2" alt="One Wallet. Many Services."></article>
        </div>
        <div class="banner-dots" aria-label="Banner controls">
            <button class="active" type="button" aria-label="Show banner 1"></button>
            <button type="button" aria-label="Show banner 2"></button>
            <button type="button" aria-label="Show banner 3"></button>
            <button type="button" aria-label="Show banner 4"></button>
        </div>
    </section>
    <section class="list-head"><h2>Latest Updates</h2><a href="transactions.php">View All</a></section>
    <section class="update-list">
        <?php foreach ($pendingTopups as $topup): ?>
            <a class="update-item pending-topup" href="payment-status.php?reference=<?= e($topup['reference']) ?>">
                <span data-icon="clock"></span>
                <div><strong><?= $topup['method'] === 'qrph' ? 'QR Ph' : 'MCTC' ?> top-up pending</strong><p><?= e($topup['reference']) ?></p></div>
                <b><?= format_credits((float) $topup['credits']) ?></b>
            </a>
        <?php endforeach; ?>
        <?php foreach ($latest as $item): ?>
            <article class="update-item">
                <span data-icon="megaphone"></span>
                <div><strong><?= e($item['title']) ?></strong><p><?= e($item['status']) ?> · <?= e($item['created_at']) ?></p></div>
                <b><?= $item['amount'] >= 0 ? '+' : '' ?><?= format_credits((float) $item['amount']) ?></b>
            </article>
        <?php endforeach; ?>
    </section>
    <?php require __DIR__ . '/includes/nav.php'; ?>
</section>
<script defer src="assets/js/boracay-cash.js?v=6"></script>
<script defer src="assets/js/menu.js?v=2"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
