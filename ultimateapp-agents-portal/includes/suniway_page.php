<?php
declare(strict_types=1);
// Shared page for ubills.php, uload.php and ucashin.php. Set $suniwayService before including.
if (!isset($suniwayService) || !isset(suniway_services()[$suniwayService])) { http_response_code(404); exit; }
$user = require_auth();
if (!suniway_tiles_visible()) redirect('dashboard.php');
header('Cache-Control: no-store');
$svc = suniway_services()[$suniwayService];
$self = $svc['page'];
$error = '';
$quote = null;
$token = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && suniway_enabled()) {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $post = static fn(string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
    try {
        if ($action === 'review') {
            rate_limit_enforce('suniway_quote', 'user:' . $user['id'], 40, 3600);
            $quote = suniway_quote($suniwayService, $post('provider_id'), $post('account_number'), $post('amount'));
            $token = bin2hex(random_bytes(32));
            $_SESSION['suniway_quote'] = array_slice(array_filter((array) ($_SESSION['suniway_quote'] ?? []), static fn($q) => ($q['expires'] ?? 0) > time()), -4, null, true);
            $_SESSION['suniway_quote'][$token] = $quote;
        } elseif ($action === 'pay') {
            rate_limit_enforce('suniway_pay', 'user:' . $user['id'], 20, 3600);
            $token = $post('token');
            $quote = $_SESSION['suniway_quote'][$token] ?? null;
            if (!is_array($quote) || $quote['service'] !== $suniwayService) {
                // Already paid in another tap? Show that receipt instead of paying twice.
                $s = $pdo->prepare('SELECT reference FROM suniway_transactions WHERE user_id = ? AND request_key = ?');
                $s->execute([$user['id'], preg_match('/^[a-f0-9]{64}$/D', $token) ? $token : '']);
                if ($ref = $s->fetchColumn()) redirect('suniway-receipt.php?ref=' . rawurlencode((string) $ref));
                throw new InvalidArgumentException('This review expired. Please enter the details again.');
            }
            $ref = suniway_pay($pdo, (int) $user['id'], $quote, $token);
            unset($_SESSION['suniway_quote'][$token]);
            redirect('suniway-receipt.php?ref=' . rawurlencode($ref));
        }
    } catch (InvalidArgumentException $ex) {
        $error = $ex->getMessage();
        $quote = $action === 'pay' ? $quote : null;
    } catch (SuniwayError $ex) {
        error_log('SUNIWAY page error: ' . $ex->getMessage());
        $error = 'SUNIWAY is not responding right now. Please try again in a few minutes.';
        $quote = null;
    }
}

$providers = [];
if (suniway_enabled() && !$quote) {
    try { $providers = suniway_providers($suniwayService); } catch (SuniwayError $ex) {
        error_log('SUNIWAY providers: ' . $ex->getMessage());
        $error = $error ?: 'We could not load the list of billers right now. Please try again in a few minutes.';
    }
}
$picks = $providers ? suniway_match_quick_picks($suniwayService, $providers) : [];
$recent = [];
if (suniway_table_ready()) $recent = $pdo->prepare('SELECT reference, provider_name, account_number, total_centavos, status, created_at FROM suniway_transactions WHERE user_id = ? AND service = ? ORDER BY id DESC LIMIT 10');
if ($recent) { try { $recent->execute([$user['id'], $suniwayService]); $recent = $recent->fetchAll(); } catch (PDOException $e) { $recent = []; } }
$val = static fn(string $k): string => e(is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
$pageTitle = $svc['label'];
require __DIR__ . '/header.php';
?>
<section class="screen seed-screen upay-screen">
    <header class="page-head"><a href="<?= $quote ? e($self) : 'dashboard.php' ?>" aria-label="Back"><span data-icon="arrow-left"></span></a><h1><?= e($svc['label']) ?></h1><span></span></header>
    <div class="seed-heading"><small>ULTIMATE APP · BORACAY</small><h2><?= e($svc['title']) ?></h2><p><?= e($svc['intro']) ?></p></div>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
    <?php if (!suniway_enabled()): ?>
        <div class="upay-soon"><img src="assets/images/services/<?= e($svc['image']) ?>" alt="" width="72" height="72"><h3>Coming soon</h3><p><?= e($svc['label']) ?> will be available here shortly. You will pay with your Credits.</p><a class="btn dark" href="dashboard.php">Back to Home</a></div>
    <?php elseif ($quote): ?>
        <div class="upay-review">
            <h3>Review payment</h3>
            <dl>
                <div><dt><?= $suniwayService === 'bills_pay' ? 'Biller' : ($suniwayService === 'eload' ? 'Network' : 'E-wallet') ?></dt><dd><?= e($quote['provider']['name']) ?></dd></div>
                <div><dt><?= e($svc['account']) ?></dt><dd><?= e($quote['account']) ?></dd></div>
                <div><dt>Amount</dt><dd>₱<?= peso($quote['amount']) ?></dd></div>
                <?php if ($quote['suniway_total'] - $quote['amount'] > 0): ?><div><dt>Provider fee</dt><dd>₱<?= peso($quote['suniway_total'] - $quote['amount']) ?></dd></div><?php endif; ?>
                <?php if ($quote['app_fee'] > 0): ?><div><dt>Convenience fee</dt><dd>₱<?= peso($quote['app_fee']) ?></dd></div><?php endif; ?>
                <div class="total"><dt>Total (Credits)</dt><dd><?= peso($quote['total']) ?></dd></div>
            </dl>
            <p class="upay-balance">Your Credits: <b><?= format_credits((float) $user['credits']) ?></b><?= (float) $user['credits'] * 100 < $quote['total'] ? ' · <span class="upay-short">not enough, please <a href="buy-credits.php">buy Credits</a></span>' : '' ?></p>
            <p class="seed-notice">Check the <?= e(mb_strtolower($svc['account'])) ?>. Payments sent to a wrong number cannot be reversed. If the provider does not accept it, your Credits are returned.</p>
            <form method="post" class="upay-confirm"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="pay"><input type="hidden" name="token" value="<?= e($token) ?>">
                <button class="btn dark" type="submit">Pay <?= peso($quote['total']) ?> Credits</button></form>
            <a class="btn light upay-edit" href="<?= e($self) ?>">Edit details</a>
        </div>
    <?php else: ?>
        <?php if ($picks): ?><div class="upay-picks" aria-label="Popular"><?php foreach ($picks as $label => $pid): ?><button type="button" data-pick="<?= e($pid) ?>"><?= e($label) ?></button><?php endforeach; ?></div><?php endif; ?>
        <form method="post" class="seed-form upay-form" data-upay-form><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="review">
            <label><?= $suniwayService === 'bills_pay' ? 'Biller' : ($suniwayService === 'eload' ? 'Network / product' : 'E-wallet') ?>
                <?php if (count($providers) > 12): ?><input type="search" placeholder="Search" data-provider-filter aria-label="Search the list"><?php endif; ?>
                <select name="provider_id" required data-provider-select><option value="">Choose…</option><?php foreach ($providers as $p): ?><option value="<?= e($p['id']) ?>" data-min="<?= $p['min'] !== null ? e(centavos_to_decimal($p['min'])) : '' ?>" data-max="<?= $p['max'] !== null ? e(centavos_to_decimal($p['max'])) : '' ?>"<?= ($_POST['provider_id'] ?? '') === $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
                <small data-provider-limits></small></label>
            <label><?= e($svc['account']) ?><input name="account_number" required maxlength="160" autocomplete="off" inputmode="<?= $suniwayService === 'bills_pay' ? 'text' : 'numeric' ?>" placeholder="<?= e($svc['placeholder']) ?>" value="<?= $val('account_number') ?>"></label>
            <label>Amount (PHP)<input name="amount" required inputmode="decimal" placeholder="0.00" value="<?= $val('amount') ?>" data-amount></label>
            <?php if ($suniwayService !== 'bills_pay'): ?><div class="upay-amounts"><?php foreach ($suniwayService === 'eload' ? [20, 50, 100, 300, 500] : [100, 500, 1000, 2000, 5000] as $amt): ?><button type="button" data-amount-pick="<?= $amt ?>">₱<?= number_format($amt) ?></button><?php endforeach; ?></div><?php endif; ?>
            <p class="upay-balance">Pay with Credits · Balance <b><?= format_credits((float) $user['credits']) ?></b><?= suniway_app_fee($suniwayService) > 0 ? ' · Convenience fee ₱' . peso(suniway_app_fee($suniwayService)) : '' ?></p>
            <button class="btn dark" type="submit">Review</button>
        </form>
    <?php endif; ?>
    <?php if ($recent): ?>
        <h3 class="upay-h">Recent</h3>
        <div class="seed-request-list"><?php foreach ($recent as $r): [$lbl] = suniway_status_label($r['status']); ?><a href="suniway-receipt.php?ref=<?= e($r['reference']) ?>"><span><small><?= e($r['reference']) ?> · <?= e(date('M j, g:i A', strtotime($r['created_at']))) ?></small><strong><?= e($r['provider_name']) ?> · <?= peso((int) $r['total_centavos']) ?></strong><small><?= e($r['account_number']) ?> · <?= e($lbl) ?></small></span></a><?php endforeach; ?></div>
    <?php endif; ?>
</section>
<script src="assets/js/upay.js?v=1" defer></script>
<?php require __DIR__ . '/nav.php'; ?>
<?php require __DIR__ . '/footer.php'; ?>
