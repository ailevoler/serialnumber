<?php
// POST event_id, action=register|cancel
require __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_post()) redirect('events.php');
csrf_check();

$ev = q_one('SELECT * FROM events WHERE id = ?', [(int) ($_POST['event_id'] ?? 0)]);
if (!$ev) {
    flash('error', 'Event not found.');
    redirect('events.php');
}
$back = 'event.php?id=' . $ev['id'];
$reg = q_one('SELECT * FROM event_registrations WHERE event_id = ? AND user_id = ?', [$ev['id'], uid()]);

if (($_POST['action'] ?? '') === 'cancel') {
    if ($reg && ($reg['status'] !== 'confirmed' || (int) $ev['fee'] === 0)) {
        q('DELETE FROM event_registrations WHERE id = ?', [$reg['id']]);
        q('DELETE FROM event_rsvps WHERE event_id = ? AND user_id = ?', [$ev['id'], uid()]);
        if ($reg['payment_id']) q("UPDATE payments SET status = 'cancelled' WHERE id = ? AND status = 'pending'", [$reg['payment_id']]);
        flash('info', 'Your registration was cancelled.');
    } elseif ($reg) {
        flash('info', 'Paid registrations can be cancelled by contacting the organizer.');
    }
    redirect($back);
}

if (strtotime($ev['starts_at']) < strtotime('today')) {
    flash('error', 'Registration for this event is closed.');
    redirect($back);
}
if ($reg && $reg['status'] === 'confirmed') redirect($back);
if ($ev['capacity'] && (int) q_val('SELECT COUNT(*) FROM event_rsvps WHERE event_id = ?', [$ev['id']]) >= (int) $ev['capacity']) {
    flash('error', 'Sorry, this event is full.');
    redirect($back);
}

// Free event: confirm right away.
if ((int) $ev['fee'] === 0) {
    q("INSERT INTO event_registrations (event_id, user_id, status) VALUES (?,?,'confirmed') ON DUPLICATE KEY UPDATE status = 'confirmed'", [$ev['id'], uid()]);
    q('INSERT IGNORE INTO event_rsvps (event_id, user_id) VALUES (?,?)', [$ev['id'], uid()]);
    notify((int) $ev['user_id'], uid(), 'rsvp', display_name(current_user()) . ' registered for ' . $ev['title'] . '.', $back);
    flash('success', "You're registered for " . $ev['title'] . '!');
    redirect($back);
}

// Paid event: reuse a pending payment if one exists, otherwise create one.
$p = $reg && $reg['payment_id'] ? payment_get((int) $reg['payment_id']) : null;
if (!$p || $p['status'] !== 'pending') {
    $opts = payment_options();
    $method = (string) ($_POST['method'] ?? '');
    if (empty($opts[$method])) $method = $opts['qrph'] ? 'qrph' : ($opts['bank'] ? 'bank' : ($opts['card'] ? 'card' : ($opts['ewallet'] ? 'ewallet' : '')));
    if ($method === '') {
        flash('error', 'Online payment is not available yet. Please contact the organizer.');
        redirect($back);
    }
    $p = payment_create('event', 'Registration — ' . $ev['title'], (int) $ev['fee'], $method);
    q("INSERT INTO event_registrations (event_id, user_id, status, payment_id) VALUES (?,?,'pending',?)
       ON DUPLICATE KEY UPDATE status = 'pending', payment_id = VALUES(payment_id)", [$ev['id'], uid(), $p['id']]);
    try {
        $to = payment_start($p, $method);
        if ($to) { header('Location: ' . $to); exit; }
    } catch (Throwable $e) {
        q("UPDATE payments SET status = 'cancelled', admin_note = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 255), $p['id']]);
        q("DELETE FROM event_registrations WHERE event_id = ? AND user_id = ? AND status = 'pending'", [$ev['id'], uid()]);
        flash('error', 'We could not start the payment' . ($e instanceof PayMongoException ? ': ' . $e->getMessage() : '.'));
        redirect($back);
    }
}
redirect('payment.php?ref=' . urlencode($p['reference']));
