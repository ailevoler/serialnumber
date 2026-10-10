<?php
require_once __DIR__ . '/includes/functions.php';
$user = require_auth();
header('Cache-Control: no-store');
$reference = is_string($_GET['reference'] ?? null) ? $_GET['reference'] : '';
$stmt = $pdo->prepare('SELECT * FROM boracay_cash_conversions WHERE reference = ? AND user_id = ?');
$stmt->execute([$reference, $user['id']]);
$conversion = $stmt->fetch();
if (!$conversion) { http_response_code(404); exit('Conversion not found.'); }
$toCredits = $conversion['direction'] === 'cash_to_credits';
$pageTitle = 'BCash Receipt';
require __DIR__ . '/includes/header.php';
?>
<section class="screen cash-screen">
    <header class="page-head"><a href="boracay-cash.php" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>Receipt</h1><span></span></header>
    <div class="cash-receipt-head"><span data-icon="check-circle"></span><h2>Conversion complete</h2><strong><?= $toCredits ? number_format((float) $conversion['credits'], 2) . ' Credits' : 'PHP ' . number_format($conversion['cash_centavos'] / 100, 2) ?></strong><p>Added to <?= $toCredits ? 'Credits wallet' : 'BCash' ?></p></div>
    <div class="cash-breakdown"><div><span>Reference</span><strong><?= e($conversion['reference']) ?></strong></div><div><span><?= $toCredits ? 'BCash deducted' : 'Credits deducted' ?></span><strong><?= $toCredits ? 'PHP ' . number_format($conversion['cash_centavos'] / 100, 2) : number_format((float) $conversion['credits'], 2) . ' Credits' ?></strong></div><div><span><?= $toCredits ? 'Credits added' : 'BCash added' ?></span><strong><?= $toCredits ? number_format((float) $conversion['credits'], 2) . ' Credits' : 'PHP ' . number_format($conversion['cash_centavos'] / 100, 2) ?></strong></div><div><span>Completed</span><strong><?= e($conversion['created_at']) ?></strong></div></div>
    <p class="cash-note">USD is only an estimated display of the PHP wallet balance when a rate is configured. No USD balance or payout was created.</p>
    <a class="btn dark" href="dashboard.php<?= $toCredits ? '' : '?wallet=cash' ?>">Back to wallet</a>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
