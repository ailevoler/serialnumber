<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_auth();
rate_limit_enforce('transactions_view', 'user:' . $user['id'], 60, 60);
$active = 'transactions';
$stmt = $pdo->prepare('SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$user['id']]);
$items = $stmt->fetchAll();
// Merchant payments made with BCash are not Credits movements, so they come from merchant_payments.
try {
    $cashStmt = $pdo->prepare("SELECT mp.reference, mp.customer_debit_centavos, mp.status, mp.created_at, m.business_name FROM merchant_payments mp JOIN merchants m ON m.id = mp.merchant_id WHERE mp.user_id = ? AND mp.source = 'boracay_cash' ORDER BY mp.created_at DESC LIMIT 100");
    $cashStmt->execute([$user['id']]);
    foreach ($cashStmt->fetchAll() as $row) {
        $items[] = ['type' => 'payment', 'title' => 'Paid ' . $row['business_name'] . ' ' . $row['reference'] . ($row['status'] === 'refunded' ? ' (refunded)' : ''), 'amount' => -$row['customer_debit_centavos'] / 100, 'status' => 'BCash', 'created_at' => $row['created_at'], 'cash' => true];
    }
    usort($items, static fn($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));
} catch (Throwable $e) {}
$pendingStmt = $pdo->prepare("SELECT reference, credits, method, created_at FROM credit_topups WHERE user_id = ? AND status = 'pending' ORDER BY created_at DESC");
$pendingStmt->execute([$user['id']]);
$pendingTopups = $pendingStmt->fetchAll();
$pageTitle = 'Transactions';
require __DIR__ . '/includes/header.php';
?>
<section class="screen with-nav">
    <header class="page-head"><a href="dashboard.php"><span data-icon="arrow-left"></span></a><h1>Transactions</h1><span></span></header>
    <section class="update-list roomy">
        <?php foreach ($pendingTopups as $topup): ?>
            <a class="update-item pending-topup" href="payment-status.php?reference=<?= e($topup['reference']) ?>">
                <span data-icon="clock"></span>
                <div><strong><?= $topup['method'] === 'qrph' ? 'QR Ph' : 'MCTC' ?> top-up pending</strong><p><?= e($topup['reference']) ?></p></div>
                <b><?= format_credits((float) $topup['credits']) ?></b>
            </a>
        <?php endforeach; ?>
        <?php foreach ($items as $item): ?>
            <article class="update-item">
                <span data-icon="<?= $item['type'] === 'purchase' ? 'shopping-cart' : 'receipt' ?>"></span>
                <div><strong><?= e($item['title']) ?></strong><p><?= e(ucfirst($item['type'])) ?><?= !empty($item['cash']) ? ' · BCash' : '' ?> · <?= e($item['created_at']) ?></p></div>
                <b><?= !empty($item['cash']) ? 'PHP ' : '' ?><?= $item['amount'] >= 0 ? '+' : '' ?><?= format_credits((float) $item['amount']) ?></b>
            </article>
        <?php endforeach; ?>
    </section>
    <?php require __DIR__ . '/includes/nav.php'; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
