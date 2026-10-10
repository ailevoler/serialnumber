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
} catch (Throwable $e) {}
// BCash Send / Receive between people.
try {
    require_once __DIR__ . '/includes/bcash.php';
    foreach (bcash_transfer_history($pdo, (int) $user['id']) as $row) {
        $items[] = ['type' => 'transfer', 'title' => $row['title'], 'amount' => $row['amount_centavos'] / 100, 'status' => 'BCash', 'created_at' => $row['created_at'], 'cash' => true, 'href' => 'bcash-pay-receipt.php?reference=' . rawurlencode($row['reference'])];
    }
} catch (Throwable $e) {
    error_log('BCash history failed: ' . $e->getMessage());
}
$bcashOnly = ($_GET['wallet'] ?? '') === 'bcash';
if ($bcashOnly) $items = array_values(array_filter($items, static fn($i) => !empty($i['cash']) || ($i['type'] ?? '') === 'conversion'));
usort($items, static fn($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));
$pendingStmt = $pdo->prepare("SELECT reference, credits, method, created_at FROM credit_topups WHERE user_id = ? AND status = 'pending' ORDER BY created_at DESC");
$pendingStmt->execute([$user['id']]);
$pendingTopups = $pendingStmt->fetchAll();
$pageTitle = 'Transactions';
require __DIR__ . '/includes/header.php';
?>
<section class="screen with-nav">
    <header class="page-head"><a href="dashboard.php<?= $bcashOnly ? '?wallet=cash' : '' ?>"><span data-icon="arrow-left"></span></a><h1><?= $bcashOnly ? 'BCash history' : 'Transactions' ?></h1><span></span></header>
    <nav class="segmented tx-filter" aria-label="Wallet"><a class="<?= $bcashOnly ? '' : 'active' ?>" href="transactions.php">All</a><a class="<?= $bcashOnly ? 'active' : '' ?>" href="transactions.php?wallet=bcash">BCash</a></nav>
    <section class="update-list roomy">
        <?php if ($bcashOnly && !$items): ?><p class="muted">No BCash activity yet.</p><?php endif; ?>
        <?php foreach ($bcashOnly ? [] : $pendingTopups as $topup): ?>
            <a class="update-item pending-topup" href="payment-status.php?reference=<?= e($topup['reference']) ?>">
                <span data-icon="clock"></span>
                <div><strong><?= $topup['method'] === 'qrph' ? 'QR Ph' : 'MCTC' ?> top-up pending</strong><p><?= e($topup['reference']) ?></p></div>
                <b><?= format_credits((float) $topup['credits']) ?></b>
            </a>
        <?php endforeach; ?>
        <?php foreach ($items as $item): ?>
            <<?= isset($item['href']) ? 'a href="' . e($item['href']) . '"' : 'article' ?> class="update-item">
                <span data-icon="<?= $item['type'] === 'purchase' ? 'shopping-cart' : 'receipt' ?>"></span>
                <div><strong><?= e($item['title']) ?></strong><p><?= e(ucfirst($item['type'])) ?><?= !empty($item['cash']) ? ' · BCash' : '' ?> · <?= e($item['created_at']) ?></p></div>
                <b><?= !empty($item['cash']) ? 'PHP ' : '' ?><?= $item['amount'] >= 0 ? '+' : '' ?><?= format_credits((float) $item['amount']) ?></b>
            </<?= isset($item['href']) ? 'a' : 'article' ?>>
        <?php endforeach; ?>
    </section>
    <?php require __DIR__ . '/includes/nav.php'; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
