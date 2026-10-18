<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_member();

$pay = q_row('SELECT * FROM payments WHERE reference = ? AND user_id = ?', [input('ref'), $me['id']]);
if (!$pay) {
    flash('error', 'Payment not found.');
    redirect('member/dashboard.php');
}
$back = ['order' => 'member/orders.php', 'upgrade' => 'member/upgrade.php'][$pay['purpose']] ?? 'member/codes.php';

if ($pay['status'] === 'paid') {
    flash('success', 'Payment ' . $pay['reference'] . ' is already confirmed.');
    redirect($back);
}

if (input('result') === 'cancel') {
    if ($pay['status'] === 'pending') {
        q("UPDATE payments SET status = 'cancelled' WHERE id = ? AND status = 'pending'", [$pay['id']]);
        if ($pay['order_id']) {
            cancel_order((int)$pay['order_id']);
        }
    }
    flash('warning', 'Payment was cancelled.' . ($pay['order_id'] ? ' The order has been cancelled.' : ''));
    redirect($back);
}

// Success redirect: confirm with PayMongo directly (the webhook may also arrive later).
try {
    if ($pay['checkout_id'] && paymongo_session_paid($pay['checkout_id'])) {
        fulfill_payment((int)$pay['id']);
        flash('success', 'Payment received! ' . (['order' => 'Your order is now paid.', 'upgrade' => 'Your package has been upgraded.'][$pay['purpose']] ?? 'Your activation codes are ready.'));
    } else {
        flash('info', 'We are waiting for PayMongo to confirm your payment. This page will update automatically once confirmed.');
    }
} catch (RuntimeException $ex) {
    flash('info', 'Payment is being processed. It will be confirmed automatically.');
}
redirect($back);
