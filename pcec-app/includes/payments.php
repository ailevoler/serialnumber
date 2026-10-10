<?php
/** Payment records + PayMongo flows shared by donations and event registration. */

const MIN_PAYMENT = 2000; // ₱20.00 — PayMongo's minimum charge

function abs_url(string $path): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url($path);
}

/** Which payment options the donor can pick right now. */
function payment_options(): array
{
    $pm = PayMongo::fromSettings() !== null;
    $enabled = setting_list('paymongo_methods');
    $wallets = array_values(array_intersect($enabled, ['gcash', 'paymaya', 'grab_pay', 'shopee_pay']));
    return [
        'qrph' => $pm && in_array('qrph', $enabled, true),
        'card' => $pm && in_array('card', $enabled, true),
        'ewallet' => $pm && (bool) $wallets,
        'bank' => setting('bank_enabled', '0') === '1' || ($pm && in_array('dob', $enabled, true)),
        'wallet_types' => $wallets,
    ];
}

function method_label(string $m): string
{
    return ['qrph' => 'QR Ph', 'card' => 'Credit / Debit Card', 'ewallet' => 'E-Wallet', 'bank' => 'Bank Transfer'][$m] ?? ucfirst($m);
}

function new_reference(): string
{
    return 'PCEC-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function payment_create(string $purpose, string $description, int $amount, string $method): array
{
    $ref = new_reference();
    q('INSERT INTO payments (reference, user_id, purpose, description, amount, method, livemode) VALUES (?,?,?,?,?,?,?)',
        [$ref, uid() ?: null, $purpose, mb_substr($description, 0, 255), $amount, $method, PayMongo::isLive() ? 1 : 0]);
    return payment_get((int) db()->lastInsertId());
}

function payment_get(int $id): ?array
{
    return q_one('SELECT * FROM payments WHERE id = ?', [$id]);
}

function payment_by_ref(string $ref): ?array
{
    $p = q_one('SELECT * FROM payments WHERE reference = ?', [$ref]);
    return ($p && ((int) $p['user_id'] === uid() || is_admin())) ? $p : null;
}

function payment_billing(): array
{
    $u = current_user();
    return ['name' => trim($u['first_name'] . ' ' . $u['last_name']), 'email' => $u['email']];
}

/**
 * Begin collecting a pending payment with the chosen method.
 * Returns a URL to redirect to (hosted checkout) or null when the payment page handles it (QR / bank).
 */
function payment_start(array $p, string $method): ?string
{
    $opts = payment_options();
    if (empty($opts[$method])) {
        throw new RuntimeException(method_label($method) . ' is not available right now.');
    }
    q('UPDATE payments SET method = ?, intent_id = NULL, checkout_id = NULL, qr_image = NULL, qr_expires_at = NULL WHERE id = ? AND status = ?',
        [$method, $p['id'], 'pending']);
    $meta = ['reference' => $p['reference'], 'payment_id' => $p['id'], 'purpose' => $p['purpose']];

    if ($method === 'bank') {
        if (setting('bank_enabled', '0') !== '1') { // only PayMongo online banking is enabled
            return payment_checkout($p, ['dob'], $meta);
        }
        return null;
    }
    if ($method === 'qrph') {
        [$intent, $image, $expires] = PayMongo::fromSettings()->createQrph($p['amount'], $p['description'], payment_billing(), $meta);
        q('UPDATE payments SET intent_id = ?, qr_image = ?, qr_expires_at = ? WHERE id = ?', [$intent, $image, date('Y-m-d H:i:s', $expires), $p['id']]);
        return null;
    }
    $types = $method === 'card' ? ['card'] : $opts['wallet_types'];
    return payment_checkout($p, $types, $meta);
}

function payment_checkout(array $p, array $types, array $meta): string
{
    $back = 'payment.php?ref=' . urlencode($p['reference']);
    [$id, $checkoutUrl] = PayMongo::fromSettings()->createCheckout(
        $p['amount'], $p['description'], setting('giving_payee', APP_ORG) . ' — ' . $p['reference'], $types,
        abs_url($back . '&return=1'), abs_url($back . '&cancelled=1'), $p['reference'], payment_billing(), $meta);
    q('UPDATE payments SET checkout_id = ? WHERE id = ?', [$id, $p['id']]);
    return $checkoutUrl;
}

/** Ask PayMongo for the latest status of a pending payment (rate-limited). Returns the fresh row. */
function payment_refresh(array $p, bool $force = false): array
{
    if ($p['status'] !== 'pending' || (!$p['intent_id'] && !$p['checkout_id'])) {
        return $p;
    }
    if (!$force && $p['checked_at'] && time() - strtotime($p['checked_at']) < 4) {
        return $p;
    }
    q('UPDATE payments SET checked_at = NOW() WHERE id = ?', [$p['id']]);
    $pm = PayMongo::fromSettings();
    if (!$pm) return $p;
    try {
        if ($p['intent_id']) {
            $intent = $pm->getIntent($p['intent_id']);
            if (($intent['attributes']['status'] ?? '') === 'succeeded') {
                payment_mark_paid((int) $p['id'], $intent['attributes']['payments'][0]['id'] ?? null);
            }
        } elseif ($p['checkout_id']) {
            $cs = $pm->getCheckout($p['checkout_id']);
            $paid = null;
            foreach ($cs['attributes']['payments'] ?? [] as $pay) {
                if (($pay['attributes']['status'] ?? '') === 'paid') $paid = $pay['id'];
            }
            if ($paid || ($cs['attributes']['payment_intent']['attributes']['status'] ?? '') === 'succeeded') {
                payment_mark_paid((int) $p['id'], $paid);
            }
        }
    } catch (PayMongoException $e) {
        error_log('PayMongo refresh failed for ' . $p['reference'] . ': ' . $e->getMessage());
    }
    return payment_get((int) $p['id']);
}

/** Mark a payment as paid exactly once and run the side effects for its purpose. */
function payment_mark_paid(int $id, ?string $providerPaymentId = null, ?string $note = null): bool
{
    $st = q("UPDATE payments SET status = 'paid', paid_at = NOW(), provider_payment_id = COALESCE(?, provider_payment_id),
             admin_note = COALESCE(?, admin_note) WHERE id = ? AND status <> 'paid'", [$providerPaymentId, $note, $id]);
    if ($st->rowCount() === 0) {
        return false; // already processed (webhook and polling can both arrive)
    }
    $p = payment_get($id);
    $uid = $p['user_id'] ? (int) $p['user_id'] : null;
    if ($p['purpose'] === 'event') {
        $reg = q_one('SELECT * FROM event_registrations WHERE payment_id = ?', [$id]);
        if ($reg) {
            q("UPDATE event_registrations SET status = 'confirmed' WHERE id = ?", [$reg['id']]);
            q('INSERT IGNORE INTO event_rsvps (event_id, user_id) VALUES (?,?)', [$reg['event_id'], $reg['user_id']]);
            $ev = q_one('SELECT title, user_id FROM events WHERE id = ?', [$reg['event_id']]);
            if ($uid) notify($uid, null, 'payment', 'Payment received — you are registered for ' . $ev['title'] . '.', 'event.php?id=' . $reg['event_id']);
            notify((int) $ev['user_id'], $uid, 'rsvp', 'New paid registration for ' . $ev['title'] . ' (' . money((int) $p['amount']) . ').', 'event.php?id=' . $reg['event_id']);
        }
    } else {
        if ($uid) notify($uid, null, 'payment', 'Thank you! We received your gift of ' . money((int) $p['amount']) . '. God bless you.', 'payment.php?ref=' . $p['reference']);
        foreach (q_all("SELECT id FROM users WHERE role = 'admin'") as $a) {
            notify((int) $a['id'], $uid, 'payment', 'New donation received: ' . money((int) $p['amount']) . ' (' . $p['reference'] . ').', 'admin/payments.php?q=' . $p['reference']);
        }
    }
    return true;
}

function payment_status_badge(string $status): string
{
    $labels = ['pending' => 'Pending', 'paid' => 'Paid', 'failed' => 'Failed', 'expired' => 'Expired', 'cancelled' => 'Cancelled'];
    return '<span class="status status-' . e($status) . '">' . e($labels[$status] ?? $status) . '</span>';
}
