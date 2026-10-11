<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/marketplace.php';
$user = require_auth();
header('Cache-Control: no-store');
$ref = is_string($_GET['ref'] ?? null) ? $_GET['ref'] : '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('mk_cancel', 'user:' . $user['id'], 20, 3600);
    try {
        $r = mk_booking_update($pdo, $ref, 'cancel', 'customer', (int) $user['id'], cancel_reason('booking', (string) ($_POST['reason'] ?? ''), (string) ($_POST['reason_other'] ?? '')));
        $_SESSION['flash'][] = ['success', 'Booking cancelled.' . ($r['refund'] > 0 ? ' ' . mk_money($r['refund']) . ' was returned to your wallet.' : '') . ($r['fee'] > 0 ? ' Late cancellation fee: ' . mk_money($r['fee']) . '.' : '')];
        redirect('mk-booking.php?ref=' . rawurlencode($ref));
    } catch (InvalidArgumentException $ex) {
        $error = $ex->getMessage();
    }
}
$b = mk_ready() ? mk_booking($pdo, $ref) : null;
if (!$b || (int) $b['user_id'] !== (int) $user['id']) { http_response_code(404); exit('Booking not found.'); }
$cfg = mk_services()[$b['service']];
$steps = ['pending' => 'Requested', 'confirmed' => 'Confirmed', 'completed' => 'Completed'];
$order = array_keys($steps);
$at = array_search($b['status'], $order, true);
$pageTitle = 'Booking ' . $b['reference'];
require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="assets/css/market.css?v=3">
<section class="screen mk-screen">
    <header class="page-head"><a href="mk-bookings.php?s=<?= e($b['service']) ?>" aria-label="Back"><span data-icon="arrow-left"></span></a><h1>Booking</h1><span></span></header>
    <?php foreach ($_SESSION['flash'] ?? [] as [$type, $message]): ?><p class="alert <?= $type === 'success' ? 'ok' : '' ?>" role="status"><?= e($message) ?></p><?php endforeach; unset($_SESSION['flash']); ?>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
    <div class="mk-ticket">
        <div class="mk-ticket-top" style="<?= mk_img_style($b['photo'], $b['service'], $b['category']) ?>"><span class="mk-status s-<?= e($b['status']) ?>"><?= e(mk_booking_statuses()[$b['status']]) ?></span></div>
        <div class="mk-ticket-body">
            <small><?= e($cfg['label']) ?> · <?= e($b['reference']) ?></small>
            <h2><?= e($b['title']) ?></h2>
            <p class="mk-by">by <b><?= e($b['business_name'] ?: 'Ultimate App') ?></b></p>
            <?php if (!in_array($b['status'], ['cancelled', 'declined'], true)): ?>
            <ol class="mk-progress"><?php foreach ($steps as $k => $label): $i = array_search($k, $order, true); ?><li class="<?= $at !== false && $i <= $at ? 'done' : '' ?>"><?= e($label) ?></li><?php endforeach; ?></ol>
            <?php endif; ?>
            <?php if (!empty($_GET['new']) && $b['status'] === 'pending'): ?><p class="mk-note">Request sent! <?= e($b['business_name'] ?: 'Our team') ?> will confirm soon. You will see it here and in Notifications.</p><?php endif; ?>
            <?php if ($b['status_note']): ?><p class="mk-note"><b><?= $b['status'] === 'declined' ? 'Reason' : 'Note' ?>:</b> <?= e($b['status_note']) ?></p><?php endif; ?>
            <dl class="mk-dl">
                <div><dt>Date</dt><dd><?= e(date('D, M j, Y', strtotime($b['booking_date']))) ?><?= $b['booking_time'] ? ' · ' . e(date('g:i A', strtotime($b['booking_time']))) : '' ?></dd></div>
                <div><dt><?= $cfg['guests'] ? 'Guests' : 'Quantity' ?></dt><dd><?= (int) $b['quantity'] ?></dd></div>
                <?php if ($b['duration_minutes']): ?><div><dt>Duration</dt><dd><?= e(mk_duration_label((int) $b['duration_minutes'])) ?></dd></div><?php endif; ?>
                <?php if ($b['meeting_point'] || $b['location']): ?><div><dt>Where</dt><dd><?= e($b['address'] ?: ($b['meeting_point'] ?: $b['location'])) ?></dd></div><?php elseif ($b['address']): ?><div><dt>Where</dt><dd><?= e($b['address']) ?></dd></div><?php endif; ?>
                <div><dt>Total</dt><dd><b><?= e(mk_money((int) $b['total_centavos'])) ?></b></dd></div>
                <div><dt>Payment</dt><dd><?= $b['pay_method'] !== 'on_site' ? e(wallet_label($b['pay_method'])) . ' · ' . e(ucfirst($b['payment_status'])) : 'Pay on the day' . ($b['payment_status'] === 'paid' ? ' · Paid' : '') ?></dd></div>
                <?php if ($b['notes']): ?><div><dt>Your notes</dt><dd><?= e($b['notes']) ?></dd></div><?php endif; ?>
                <?php if ($b['merchant_mobile'] && in_array($b['status'], ['pending', 'confirmed'], true)): ?><div><dt>Provider</dt><dd><a href="tel:<?= e($b['merchant_mobile']) ?>"><?= e($b['merchant_mobile']) ?></a></dd></div><?php endif; ?>
            </dl>
            <?php if ($b['status'] === 'cancelled' && $b['cancel_reason']): ?><p class="mk-note"><b>Cancelled:</b> <?= e($b['cancel_reason']) ?><?= (int) $b['cancel_fee_centavos'] ? ' · fee ' . e(mk_money((int) $b['cancel_fee_centavos'])) : '' ?></p><?php endif; ?>
            <?php $terms = mk_booking_cancel_terms($b); if ($terms['allowed']): $paidIn = $b['payment_status'] === 'paid' ? (int) $b['total_centavos'] : 0; ?>
            <details class="mk-cancel"><summary>Cancel booking</summary>
            <form method="post" data-confirm="Cancel this booking?<?= $terms['fee'] ? ' A ' . e(mk_money($terms['fee'])) . ' late cancellation fee applies.' : '' ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <p class="mk-fine"><?php if ($terms['fee']): ?>Late cancellation: fee <b><?= e(mk_money($terms['fee'])) ?></b> (<?= e(cancel_pct_label('booking')) ?>) goes to the provider. You get back <?= e(mk_money($paidIn - $terms['fee'])) ?>.<?php elseif ($terms['late']): ?>Late cancellation. Please let the provider know.<?php else: ?>Free cancellation<?= $paidIn ? ': ' . e(mk_money($paidIn)) . ' comes back to your wallet' : '' ?>.<?= $b['status'] === 'confirmed' && (int) $b['cancel_hours'] ? ' Free until ' . e(mk_booking_starts($b)->modify('-' . (int) $b['cancel_hours'] . ' hours')->format('M j, g:i A')) . '; after that a ' . e(cancel_pct_label('booking')) . ' fee applies.' : '' ?><?php endif; ?></p>
                <select name="reason" required data-reason aria-label="Reason"><option value="">Why are you cancelling?</option><?php foreach (cancel_reasons('booking') as $rs): ?><option><?= e($rs) ?></option><?php endforeach; ?></select>
                <input name="reason_other" maxlength="150" placeholder="Tell us more" data-reason-other hidden aria-label="Other reason">
                <button class="mk-ghost" type="submit">Cancel booking<?= $terms['fee'] ? ' (' . e(mk_money($terms['fee'])) . ' fee)' : '' ?></button></form>
            </details>
            <?php elseif ($b['status'] === 'confirmed'): ?><p class="mk-fine">This booking has started. Contact the provider for changes.</p><?php endif; ?>
        </div>
    </div>
    <a class="mk-cta" href="<?= e($cfg['page']) ?>">Explore more <?= e($cfg['plural']) ?></a>
</section>
<script defer src="assets/js/market.js?v=2"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
