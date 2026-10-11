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
        $r = mk_booking_update($pdo, $ref, 'cancel', 'customer', (int) $user['id']);
        $_SESSION['flash'][] = ['success', 'Booking cancelled.' . ($r['refunded'] ? ' Your payment was returned.' : '')];
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
<link rel="stylesheet" href="assets/css/market.css?v=2">
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
            <?php if (mk_booking_cancellable($b)): ?>
            <form method="post" data-confirm="Cancel this booking?<?= $b['payment_status'] === 'paid' ? ' Your payment will be returned.' : '' ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="mk-ghost" type="submit">Cancel booking<?= $b['payment_status'] === 'paid' ? ' and refund' : '' ?></button></form>
            <?php elseif ($b['status'] === 'confirmed'): ?><p class="mk-fine">Free cancellation ended <?= (int) $b['cancel_hours'] ?> hours before the start. Contact the provider for changes.</p><?php endif; ?>
        </div>
    </div>
    <a class="mk-cta" href="<?= e($cfg['page']) ?>">Explore more <?= e($cfg['plural']) ?></a>
</section>
<script defer src="assets/js/market.js?v=2"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
