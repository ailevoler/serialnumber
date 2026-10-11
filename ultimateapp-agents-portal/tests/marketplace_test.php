<?php
declare(strict_types=1);
// CLI only. Run against a COPY of the database after importing database/marketplace_migration.sql:
//   DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tests/marketplace_test.php
// Checks UGo / ULocal listings and bookings (pay with Credits, capacity, decline/cancel refunds) and UEat orders.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$check = static function (bool $ok, string $msg): void { if (!$ok) throw new RuntimeException('FAILED: ' . $msg); echo "PASS: $msg\n"; };
$throws = static function (callable $fn, string $needle, string $msg) use ($check): void {
    try { $fn(); } catch (InvalidArgumentException $e) { $check(str_contains($e->getMessage(), $needle), $msg . ' (' . $e->getMessage() . ')'); return; }
    $check(false, $msg . ' — expected an error');
};
$pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4', getenv('DB_USER') ?: '', getenv('DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("SET time_zone = '+08:00'");
date_default_timezone_set('Asia/Manila');
$GLOBALS['pdo'] = $pdo;
$_SESSION = [];
require __DIR__ . '/../includes/rate_limit.php';
require __DIR__ . '/../includes/merchants.php';
require __DIR__ . '/../includes/marketplace.php';

$saved = [];
$set = static function (string $k, string $v) use ($pdo, &$saved): void {
    if (!array_key_exists($k, $saved)) $saved[$k] = $pdo->query('SELECT setting_value FROM app_settings WHERE setting_key = ' . $pdo->quote($k))->fetchColumn();
    setting_save($pdo, $k, $v, null);
};
$users = []; $merchants = []; $listings = []; $refs = [];
$credits = static fn(int $u): float => (float) $pdo->query("SELECT credits FROM users WHERE id = $u")->fetchColumn();
$balance = static fn(int $m): int => (int) $pdo->query("SELECT balance_centavos FROM merchants WHERE id = $m")->fetchColumn();
$key = static fn(): string => bin2hex(random_bytes(32));
try {
    $check(mk_ready(), 'marketplace tables are installed');
    // Charges: 1.5% + ₱2 paid by the customer (as in the P2M test).
    foreach (['p2m.credits.percent_bp' => '150', 'p2m.credits.fixed_centavos' => '200', 'p2m.credits.bearer' => 'customer', 'p2m.credits.enabled' => '1', 'market.credits_checkout' => '1', 'market.review_listings' => '1'] as $k => $v) $set($k, $v);
    settings_all(true);
    $mkUser = static function (string $name, int $credits) use ($pdo, &$users): int {
        $pdo->prepare("INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES (?, ?, ?, 'x', ?, ?)")->execute([$name, '0918' . random_int(1000000, 9999999), 'mk' . bin2hex(random_bytes(3)) . '@test.local', 'UA-MK' . strtoupper(bin2hex(random_bytes(4))), $credits]);
        return $users[] = (int) $pdo->lastInsertId();
    };
    $mkMerchant = static function (string $name, string $status = 'approved') use ($pdo, &$merchants): int {
        $code = merchant_new_code($pdo);
        $pdo->prepare("INSERT INTO merchants (code, business_name, category, owner_name, email, mobile, password_hash, address, barangay, status, bank_account_no) VALUES (?, ?, 'Tours & Activities', 'Owner', ?, '09170000000', 'x', 'Station 2', 'Balabag', ?, '1234567890')")->execute([$code, $name, strtolower($code) . '@test.local', $status]);
        return $merchants[] = (int) $pdo->lastInsertId();
    };
    $u = $mkUser('Mk Buyer', 20000); $other = $mkUser('Mk Other', 100);
    $tours = $mkMerchant('Test Tours'); $rival = $mkMerchant('Rival Tours');

    // ---------- listings
    $form = ['title' => 'Sunset Land Tour', 'category' => 'Land Tour', 'price' => '850', 'price_unit' => 'person', 'duration_minutes' => '240', 'time_slots' => '13:00, 8:00', 'capacity' => '6', 'min_qty' => '1', 'max_qty' => '6', 'location' => 'Boracay', 'cancel_hours' => '24'];
    $throws(fn() => mk_validate_listing(['title' => 'x'] + $form, 'ugo'), 'name', 'listing needs a name');
    $throws(fn() => mk_validate_listing(['category' => 'Spaceflight'] + $form, 'ugo'), 'category', 'unknown category refused');
    $throws(fn() => mk_validate_listing(['price' => '0'] + $form, 'ugo'), 'price', 'zero price refused');
    $throws(fn() => mk_validate_listing(['time_slots' => '25:00'] + $form, 'ugo'), 'Start times', 'bad start time refused');
    $data = mk_validate_listing($form, 'ugo');
    $check($data['time_slots'] === '08:00,13:00' && $data['price_centavos'] === 85000, 'start times sorted and padded; price in centavos');
    $listings[] = $tour = mk_listing_save($pdo, null, $data, $tours, null, null);
    $l = mk_listing($pdo, $tour);
    $check($l['status'] === 'pending' && !mk_listing_live($l), 'merchant listing waits for Admin review');
    $check(!in_array($tour, array_column(mk_browse($pdo, 'ugo'), 'id')), 'pending listing is not shown to customers');
    $throws(fn() => mk_listing_save($pdo, $tour, $data, $rival, null, null), 'not found', 'another merchant cannot edit it');
    $throws(fn() => mk_listing_review($pdo, $tour, 'rejected', ''), 'what to fix', 'rejecting needs a note');
    mk_listing_review($pdo, $tour, 'active', '');
    $check(in_array($tour, array_column(mk_browse($pdo, 'ugo', 'Land Tour', 'sunset'), 'id')), 'approved: shown in UGo, by category and search');
    mk_listing_toggle($pdo, $tours, $tour, true);
    $check(!in_array($tour, array_column(mk_browse($pdo, 'ugo'), 'id')), 'merchant paused it: hidden');
    mk_listing_toggle($pdo, $tours, $tour, false);
    $pdo->prepare('UPDATE mk_listings SET instant_confirm = 0 WHERE id = ?')->execute([$tour]);

    // ---------- booking with Credits
    $now = new DateTimeImmutable('2026-10-11 09:00:00');
    $base = ['date' => '2026-10-14', 'time' => '13:00', 'quantity' => 2, 'pay_method' => 'credits', 'contact_mobile' => '09171234567'];
    $throws(fn() => mk_book($pdo, $u, $tour, ['time' => '10:00'] + $base, $key(), $now), 'start times', 'time not in the start times refused');
    $throws(fn() => mk_book($pdo, $u, $tour, ['date' => '2026-10-10'] + $base, $key(), $now), 'from today', 'past date refused');
    $throws(fn() => mk_book($pdo, $u, $tour, ['quantity' => 9] + $base, $key(), $now), '1 to 6', 'too many guests refused');
    $throws(fn() => mk_book($pdo, $u, $tour, ['contact_mobile' => '123'] + $base, $key(), $now), 'mobile', 'mobile number required');
    $q = mk_booking_quote(mk_listing($pdo, $tour), 2, 'credits');
    $check($q['amount'] === 170000 && $q['fee'] === 2750 && $q['total'] === 172750, 'quote: 2 × ₱850 + 1.5% + ₱2 charge = ₱1,727.50');
    $c0 = $credits($u); $m0 = $balance($tours);
    $k1 = $key();
    $r1 = mk_book($pdo, $u, $tour, $base, $k1, $now); $refs[] = $r1;
    $check(mk_book($pdo, $u, $tour, $base, $k1, $now) === $r1, 'double tap = same booking');
    $b = mk_booking($pdo, $r1);
    $check($b['status'] === 'pending' && $b['payment_status'] === 'paid' && (int) $b['total_centavos'] === 172750, 'booked and paid, waiting for the merchant');
    $check(abs($c0 - $credits($u) - 1727.50) < 0.001 && $balance($tours) - $m0 === 170000, 'customer paid ₱1,727.50; merchant balance +₱1,700');
    $check(str_starts_with($r1, 'UG-'), 'UGo reference');

    // capacity 6 at 13:00: 2 taken
    $r2 = mk_book($pdo, $other, $tour, ['pay_method' => 'on_site', 'quantity' => 4] + $base, $key(), $now); $refs[] = $r2;
    $check(mk_booking($pdo, $r2)['pay_method'] === 'on_site' && mk_booking($pdo, $r2)['payment_status'] === 'unpaid', 'pay on the day: nothing charged');
    $throws(fn() => mk_book($pdo, $u, $tour, ['quantity' => 1] + $base, $key(), $now), 'fully booked', '13:00 is full (6 of 6)');
    $r3 = mk_book($pdo, $u, $tour, ['time' => '08:00', 'quantity' => 1] + $base, $key(), $now); $refs[] = $r3;
    $check((bool) $r3, 'other start time still open');

    // merchant actions
    $throws(fn() => mk_booking_update($pdo, $r1, 'confirm', 'merchant', $rival), 'not found', 'another merchant cannot touch the booking');
    $throws(fn() => mk_booking_update($pdo, $r1, 'decline', 'merchant', $tours, ''), 'why', 'declining needs a reason');
    mk_booking_update($pdo, $r1, 'confirm', 'merchant', $tours);
    $throws(fn() => mk_booking_update($pdo, $r1, 'complete', 'merchant', $tours, '', $now), 'on or after', 'cannot complete before the date');
    $throws(fn() => mk_booking_update($pdo, $r1, 'cancel', 'customer', $u, '', new DateTimeImmutable('2026-10-13 20:00:00')), 'Free cancellation ended', 'customer cannot cancel within 24 hours of the start');
    $c1 = $credits($u); $m1 = $balance($tours);
    $res = mk_booking_update($pdo, $r1, 'cancel', 'customer', $u, '', $now);
    $check($res['refunded'] && abs($credits($u) - $c1 - 1727.50) < 0.001 && $m1 - $balance($tours) === 170000, 'cancelled in time: full refund including the charge');
    $check(mk_booking($pdo, $r1)['payment_status'] === 'refunded', 'booking marked refunded');
    $r4 = mk_book($pdo, $u, $tour, $base + ['quantity' => 2], $key(), $now); $refs[] = $r4;
    $check((bool) $r4, 'cancelled seats are free again');
    $c2 = $credits($u);
    mk_booking_update($pdo, $r4, 'decline', 'merchant', $tours, 'Weather advisory');
    $b4 = mk_booking($pdo, $r4);
    $check($b4['status'] === 'declined' && $b4['status_note'] === 'Weather advisory' && $credits($u) > $c2, 'merchant declined: refunded with a reason');
    mk_booking_update($pdo, $r3, 'confirm', 'merchant', $tours);
    mk_booking_update($pdo, $r3, 'complete', 'merchant', $tours, '', new DateTimeImmutable('2026-10-14 18:00:00'));
    $check(mk_booking($pdo, $r3)['status'] === 'completed', 'completed after the tour');
    mk_booking_update($pdo, $r2, 'confirm', 'merchant', $tours);
    mk_booking_update($pdo, $r2, 'complete', 'merchant', $tours, '', new DateTimeImmutable('2026-10-14 18:00:00'));
    $check(mk_booking($pdo, $r2)['payment_status'] === 'paid', 'pay-on-the-day booking marked paid when completed');

    // Ultimate App listing (no merchant): request only
    $listings[] = $ua = mk_listing_save($pdo, null, mk_validate_listing(['title' => 'Island Massage', 'category' => 'Massage & Spa', 'price' => '1200', 'price_unit' => 'hour'], 'ulocal'), null, 1, null, 'active');
    $throws(fn() => mk_book($pdo, $u, $ua, ['date' => '2026-10-12', 'time' => '15:00', 'quantity' => 1, 'contact_mobile' => '09171234567'], $key(), $now), 'where the service', 'ULocal needs the address');
    $r5 = mk_book($pdo, $u, $ua, ['date' => '2026-10-12', 'time' => '15:00', 'quantity' => 1, 'contact_mobile' => '09171234567', 'address' => 'Hotel Sea Breeze room 12', 'pay_method' => 'credits'], $key(), $now); $refs[] = $r5;
    $b5 = mk_booking($pdo, $r5);
    $check($b5['pay_method'] === 'on_site' && str_starts_with($r5, 'UL-'), 'Ultimate App listing: pay on the day (no merchant to pay)');
    $throws(fn() => mk_booking_update($pdo, $r5, 'confirm', 'merchant', $tours), 'not found', 'merchants cannot manage Ultimate App bookings');
    mk_booking_update($pdo, $r5, 'confirm', 'admin', 1);
    $check(mk_booking($pdo, $r5)['status'] === 'confirmed', 'Admin confirms Ultimate App bookings');

    // ---------- UEat
    $kitchen = $mkMerchant('Test Kitchen');
    $store = ['display_name' => 'Test Kitchen', 'cuisine' => 'Filipino', 'prep_minutes' => '20', 'delivery_fee' => '49', 'min_order' => '200', 'offers_delivery' => '1', 'offers_pickup' => '1', 'opens_at' => '00:00', 'closes_at' => '23:59'];
    $throws(fn() => mk_store_save($pdo, $kitchen, ['cuisine' => 'Martian'] + $store, null, null), 'cuisine', 'unknown cuisine refused');
    mk_store_save($pdo, $kitchen, $store, null, null);
    $check(mk_store($pdo, $kitchen)['status'] === 'pending', 'new restaurant waits for review');
    $dish = static fn(string $t, string $p, string $c) => mk_validate_listing(['title' => $t, 'price' => $p, 'category' => $c], 'ueat');
    $listings[] = $sisig = mk_listing_save($pdo, null, $dish('Pork Sisig', '220', 'Best sellers'), $kitchen, 1, null, 'active');
    $listings[] = $rice = mk_listing_save($pdo, null, $dish('Garlic Rice', '45', 'Sides'), $kitchen, 1, null, 'active');
    $check(!in_array($kitchen, array_column(mk_restaurants($pdo), 'merchant_id')), 'restaurant hidden until approved');
    mk_store_save($pdo, $kitchen, $store, null, 1, 'active');
    $rest = array_values(array_filter(mk_restaurants($pdo, 'Filipino', 'sisig'), fn($r) => (int) $r['merchant_id'] === $kitchen));
    $check(count($rest) === 1 && (int) $rest[0]['items'] === 2 && $rest[0]['open_now'], 'approved: listed under Filipino, found by dish name, open now');
    $check(array_keys(mk_menu($pdo, $kitchen)) === ['Best sellers', 'Sides'], 'menu grouped by section');

    mk_cart_set($pdo, $u, $sisig, 1, true);
    $cart = mk_cart_set($pdo, $u, $rice, 2, true);
    $sum = mk_cart_summary($pdo, $cart, 'delivery');
    $check($sum['count'] === 3 && $sum['subtotal'] === 31000 && $sum['delivery_fee'] === 4900, 'basket: 1 sisig + 2 rice = ₱310 + ₱49 delivery');
    $listings[] = $foreign = mk_listing_save($pdo, null, $dish('Burger', '199', 'Mains'), $rival, 1, null, 'active');
    try { mk_cart_set($pdo, $u, $foreign, 1, true); $check(false, 'other restaurant should ask first'); } catch (LogicException $e) { $check($e->getMessage() === 'other-restaurant', 'adding from another restaurant asks to start a new basket'); }
    $cartLow = mk_cart_set($pdo, $u, $sisig, 0, false);
    $throws(fn() => mk_place_order($pdo, $u, $cartLow, ['fulfillment' => 'pickup', 'contact_mobile' => '09171234567'], $key()), 'Minimum order', 'minimum order ₱200');
    $cart = mk_cart_set($pdo, $u, $sisig, 1, true);
    $throws(fn() => mk_place_order($pdo, $u, $cart, ['fulfillment' => 'delivery', 'contact_mobile' => '09171234567'], $key()), 'delivery address', 'delivery needs an address');
    $pdo->prepare('UPDATE mk_stores SET is_open = 0 WHERE merchant_id = ?')->execute([$kitchen]);
    $throws(fn() => mk_place_order($pdo, $u, $cart, ['fulfillment' => 'pickup', 'contact_mobile' => '09171234567'], $key()), 'closed', 'closed restaurant refuses orders');
    $pdo->prepare('UPDATE mk_stores SET is_open = 1 WHERE merchant_id = ?')->execute([$kitchen]);
    $c3 = $credits($u); $k0 = $balance($kitchen);
    $sum = mk_cart_summary($pdo, $cart, 'delivery');
    $o1 = mk_place_order($pdo, $u, $cart, ['fulfillment' => 'delivery', 'address' => 'Hotel Sea Breeze room 12', 'contact_mobile' => '09171234567'], $key());
    $order = $pdo->query('SELECT * FROM ueat_orders WHERE reference = ' . $pdo->quote($o1))->fetch();
    $check($order['status'] === 'pending' && $order['payment_status'] === 'paid' && (int) $order['merchant_id'] === $kitchen, 'order placed and paid to the restaurant');
    $check(abs($c3 - $credits($u) - $sum['total'] / 100) < 0.001 && $balance($kitchen) - $k0 === 31000 + 4900, 'customer paid food + delivery + charge; restaurant got food + delivery');
    $check(mk_cart($pdo, $u)['items'] === [], 'basket emptied after ordering');
    $check((int) $pdo->query("SELECT COUNT(*) FROM ueat_order_items WHERE order_id = {$order['id']}")->fetchColumn() === 2, 'order lines saved');
    $throws(fn() => mk_order_update($pdo, $o1, 'completed', 'merchant', $kitchen), 'in order', 'kitchen steps must go in order');
    foreach (['accepted', 'preparing', 'on_the_way', 'completed'] as $step) mk_order_update($pdo, $o1, $step, 'merchant', $kitchen);
    $check($pdo->query('SELECT status FROM ueat_orders WHERE reference = ' . $pdo->quote($o1))->fetchColumn() === 'completed', 'accepted → preparing → on the way → delivered');

    $cart = mk_cart_set($pdo, $u, $sisig, 2, true);
    $o2 = mk_place_order($pdo, $u, $cart, ['fulfillment' => 'pickup', 'contact_mobile' => '09171234567'], $key());
    $c4 = $credits($u);
    mk_order_update($pdo, $o2, 'cancelled', 'customer', $u);
    $check($credits($u) > $c4 && $pdo->query('SELECT payment_status FROM ueat_orders WHERE reference = ' . $pdo->quote($o2))->fetchColumn() === 'refunded', 'customer cancelled before acceptance: refunded');
    $cart = mk_cart_set($pdo, $u, $sisig, 2, true);
    $o3 = mk_place_order($pdo, $u, $cart, ['fulfillment' => 'pickup', 'contact_mobile' => '09171234567'], $key());
    mk_order_update($pdo, $o3, 'accepted', 'merchant', $kitchen);
    $throws(fn() => mk_order_update($pdo, $o3, 'cancelled', 'customer', $u), 'already accepted', 'customer cannot cancel after the restaurant accepted');
    $cart = mk_cart_set($pdo, $u, $sisig, 1, true);
    $o4 = mk_place_order($pdo, $u, $cart, ['fulfillment' => 'pickup', 'contact_mobile' => '09171234567'], $key());
    $c5 = $credits($u);
    mk_order_update($pdo, $o4, 'declined', 'merchant', $kitchen, 'Sold out');
    $check($credits($u) > $c5, 'restaurant declined: refunded');
    $check(mk_merchant_waiting($pdo, $kitchen)['orders'] === 1, 'merchant sees 1 open order');
    echo "\nAll marketplace tests passed.\n";
} finally {
    $ids = static fn(array $a): string => $a ? implode(',', array_map('intval', $a)) : '0';
    $mids = $ids($merchants); $uids = $ids($users);
    $groups = $pdo->query("SELECT reference FROM merchant_payments WHERE merchant_id IN ($mids)")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($groups as $g) { $pdo->prepare('DELETE FROM ledger_entries WHERE entry_group IN (?, ?)')->execute([$g, 'RF-' . substr($g, 3)]); }
    $pdo->exec("DELETE FROM agent_commissions WHERE source_type = 'mk_booking' AND source_id IN (SELECT id FROM mk_bookings WHERE user_id IN ($uids))");
    $pdo->exec("DELETE FROM mk_bookings WHERE user_id IN ($uids)");
    $pdo->exec("DELETE FROM ueat_orders WHERE user_id IN ($uids)");
    $pdo->exec("DELETE FROM merchant_payments WHERE merchant_id IN ($mids)");
    $pdo->exec('DELETE FROM mk_listings WHERE id IN (' . $ids($listings) . ')');
    $pdo->exec("DELETE FROM mk_stores WHERE merchant_id IN ($mids)");
    $pdo->exec("DELETE FROM transactions WHERE user_id IN ($uids)");
    $pdo->exec("DELETE FROM merchants WHERE id IN ($mids)");
    $pdo->exec("DELETE FROM users WHERE id IN ($uids)");
    foreach ($saved as $k => $v) { if ($v === false) setting_clear($pdo, $k); else setting_save($pdo, $k, (string) $v, null); }
}
