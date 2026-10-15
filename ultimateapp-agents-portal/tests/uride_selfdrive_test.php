<?php
declare(strict_types=1);
// CLI only. Run against a COPY of the database after importing database/uride_rental_migration.sql and
// database/uride_selfdrive_migration.sql:  DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tests/uride_selfdrive_test.php
// Checks self-drive rentals: requirements, license, deposit hold, owner opt-in, hand-over, returns, late fee, damage claim.
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
require __DIR__ . '/../includes/rate_limit.php';
require __DIR__ . '/../includes/uride.php';
require_once __DIR__ . '/../includes/kyc.php';

$saved = [];
$set = static function (string $k, string $v) use ($pdo, &$saved): void {
    if (!array_key_exists($k, $saved)) $saved[$k] = $pdo->query('SELECT setting_value FROM app_settings WHERE setting_key = ' . $pdo->quote($k))->fetchColumn();
    setting_save($pdo, $k, $v, null);
};
foreach (['uride.enabled' => '1', 'uride.credits_enabled' => '1', 'uride.cash_enabled' => '1', 'uride.rental_enabled' => '1', 'uride.rental_day_hours' => '8', 'uride.rental_max_hours' => '12', 'uride.rental_schedule_days' => '7',
    'uride.self_drive_enabled' => '1', 'uride.self_drive_kyc' => '0', 'uride.self_drive_grace_min' => '15',
    'uride.e_trike.self_enabled' => '1', 'uride.e_trike.self_hour_centavos' => '35000', 'uride.e_trike.self_min_hours' => '2', 'uride.e_trike.self_day_centavos' => '200000', 'uride.e_trike.self_deposit_centavos' => '200000',
    'uride.car.self_enabled' => '1', 'uride.car.self_hour_centavos' => '60000', 'uride.car.self_min_hours' => '4', 'uride.car.self_day_centavos' => '350000', 'uride.car.self_deposit_centavos' => '500000',
    'uride.commission_type' => 'percent', 'uride.commission_percent_bp' => '1000', 'uride.rental_commission_bp' => '1500', 'uride.min_wallet_centavos' => '5000', 'uride.dispatch_radius_km' => '3', 'uride.request_timeout_min' => '5', 'kyc.required_user' => '0'] as $k => $v) $set($k, $v);
$users = []; $drivers = []; $photos = [];
$key = static fn(): string => bin2hex(random_bytes(32));
$jpeg = static function (): array {
    $im = imagecreatetruecolor(900, 560); imagefill($im, 0, 0, imagecolorallocate($im, 220, 230, 240));
    $tmp = tempnam(sys_get_temp_dir(), 'lic'); imagejpeg($im, $tmp, 80); imagedestroy($im);
    return ['name' => 'license.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
};
$base = ['pickup_address' => "D'Mall Boracay", 'pickup_lat' => '11.9667000', 'pickup_lng' => '121.9246000', 'vehicle_type' => 'e_trike', 'payment_method' => 'credits', 'rental_hours' => '4', 'start_mode' => 'now', 'rental_mode' => 'self', 'self_terms' => '1'];
try {
    // ---------- pricing
    $q = uride_rental_quote('e_trike', '4', 'self');
    $check($q['fare'] === 140000 && $q['deposit'] === 200000 && $q['mode'] === 'self', 'self-drive E-Trike 4 h x PHP 350 = PHP 1,400 + PHP 2,000 deposit');
    $q = uride_rental_quote('e_trike', '1', 'self');
    $check($q['fare'] === 70000 && $q['billed_hours'] === 2, 'self-drive minimum 2 hours');
    $q = uride_rental_quote('e_trike', '8', 'self');
    $check($q['fare'] === 200000 && $q['capped'], '8 hours capped at the PHP 2,000 self-drive day price');
    $check(uride_rental_quote('e_trike', '4')['deposit'] === 0, 'rentals with a driver have no deposit');

    // ---------- requirements
    $pdo->prepare("INSERT INTO users (full_name, mobile, email, password_hash, qr_code, credits) VALUES ('Self Drive Test', ?, ?, 'x', ?, 10000)")->execute(['0918' . random_int(1000000, 9999999), 'sd' . bin2hex(random_bytes(4)) . '@test.local', 'UA-' . strtoupper(bin2hex(random_bytes(5)))]);
    $users[] = $u = (int) $pdo->lastInsertId();
    $credits = static fn(): int => (int) round((float) $pdo->query("SELECT credits FROM users WHERE id = $u")->fetchColumn() * 100);
    // ---------- owners
    $mk = static function (bool $selfOk) use ($pdo, &$drivers): int {
        $code = uride_driver_new_code($pdo);
        $pdo->prepare("INSERT INTO uride_drivers (code, full_name, email, mobile, password_hash, birthdate, address, barangay, license_no, license_expiry, vehicle_type, plate_no, vehicle_model, vehicle_color, status, self_drive_ok)
            VALUES (?, 'Owner Test', ?, ?, 'x', '1990-01-01', 'Station 2', 'Balabag', ?, '2030-01-01', 'e_trike', ?, 'Bemac', 'Green', 'approved', ?)")
            ->execute([$code, strtolower($code) . '@test.local', '0917' . random_int(1000000, 9999999), 'N01-' . random_int(10000, 99999), 'T' . random_int(10000, 99999), $selfOk ? 1 : 0]);
        $id = (int) $pdo->lastInsertId();
        $drivers[] = $id;
        uride_tx($pdo, fn() => uride_wallet_change($pdo, $id, 100000, 'adjustment', 'TEST-' . $id, null, 'test float'));
        uride_set_online($pdo, $id, true);
        uride_heartbeat($pdo, $id, 11.9660, 121.9250);
        if ($selfOk) $pdo->prepare("INSERT INTO uride_fleet (driver_id, vehicle_type, units) VALUES (?, 'e_trike', 1)")->execute([$id]);
        return $id;
    };
    $plain = $mk(false); $owner = $mk(true);
    $set('uride.self_drive_kyc', '1');
    $throws(fn() => uride_book_rental($pdo, $u, $base, $key()), 'Verify your ID', 'Admin KYC rule on: unverified customer cannot self-drive');
    $set('uride.self_drive_kyc', '0');
    $throws(fn() => uride_book_rental($pdo, $u, $base, $key()), 'driver\'s license', 'no license on file: refused');
    $throws(fn() => uride_save_license($pdo, $u, ['license_no' => 'N01-12-345678', 'expiry' => date('Y-m-d', time() - 86400), 'can_motorcycle' => '1'], $jpeg()), 'expired', 'expired license refused');
    $throws(fn() => uride_save_license($pdo, $u, ['license_no' => 'N01-12-345678', 'expiry' => date('Y-m-d', time() + 400 * 86400)], $jpeg()), 'Tick', 'license must say what it allows');
    $lid = uride_save_license($pdo, $u, ['license_no' => 'n01-12-345678', 'expiry' => date('Y-m-d', time() + 400 * 86400), 'can_motorcycle' => '1'], $jpeg());
    $lic = uride_user_license($pdo, $u); $photos[] = $lic['photo_file'];
    $check($lic['license_no'] === 'N01-12-345678' && $lic['status'] === 'pending' && is_file(app_storage_dir('licenses') . '/' . $lic['photo_file']), 'license saved (upper case) with a private photo, pending review');
    $throws(fn() => uride_book_rental($pdo, $u, ['vehicle_type' => 'car'] + $base, $key()), 'code B', 'A/A1 license cannot self-drive a car');
    $throws(fn() => uride_book_rental($pdo, $u, ['payment_method' => 'cash'] + $base, $key()), 'Credits', 'self-drive is Credits only');
    $throws(fn() => uride_book_rental($pdo, $u, ['self_terms' => ''] + $base, $key()), 'rules', 'customer must agree to the self-drive rules');
    $pdo->exec("UPDATE users SET credits = 30 WHERE id = $u");
    $throws(fn() => uride_book_rental($pdo, $u, $base, $key()), 'refundable deposit', 'needs Credits for price + deposit');
    $pdo->exec("UPDATE users SET credits = 10000 WHERE id = $u");

    // ---------- booking + cancel
    $code = uride_book_rental($pdo, $u, $base, $key());
    $r = $pdo->query('SELECT * FROM uride_requests WHERE code = ' . $pdo->quote($code))->fetch();
    $check($r['rental_mode'] === 'self' && (int) $r['held_centavos'] === 140000 && (int) $r['deposit_centavos'] === 200000 && $r['deposit_status'] === 'held' && (int) $r['license_id'] === $lid, 'booked: PHP 1,400 + PHP 2,000 deposit held, license linked');
    $check($credits() === 1000000 - 340000, 'PHP 3,400 taken from Credits');
    uride_cancel_by_passenger($pdo, $u, (int) $r['id'], 'test');
    $r = $pdo->query('SELECT * FROM uride_requests WHERE id = ' . (int) $r['id'])->fetch();
    $check($credits() === 1000000 && $r['deposit_status'] === 'refunded', 'cancel returns the price and the deposit');

    $row = static fn(int $id): array => $pdo->query("SELECT * FROM uride_drivers WHERE id = $id")->fetch();
    $ride = static fn(string $c): array => $pdo->query('SELECT * FROM uride_requests WHERE code = ' . $pdo->quote($c))->fetch();

    // On-time return, no damage.
    $c1 = uride_book_rental($pdo, $u, $base, $key()); $r1 = $ride($c1);
    $check(!in_array($c1, array_column(uride_feed($pdo, $row($plain)), 'code'), true), 'drivers who do not offer self-drive do not see it');
    $f = array_values(array_filter(uride_feed($pdo, $row($owner)), static fn($x) => $x['code'] === $c1))[0] ?? null;
    $check($f && $f['self_drive'] && str_contains($f['trip_label'], 'self-drive') && str_contains($f['trip_label'], 'deposit'), 'owner who offers self-drive sees "4-hour self-drive · you hand over the vehicle · deposit"');
    $throws(fn() => uride_accept($pdo, $plain, (int) $r1['id']), 'self-drive', 'a driver without the opt-in cannot accept');
    uride_accept($pdo, $owner, (int) $r1['id']);
    uride_driver_action($pdo, $owner, (int) $r1['id'], 'arrived');
    $throws(fn() => uride_driver_action($pdo, $owner, (int) $r1['id'], 'start'), 'license', 'hand-over needs the license check');
    uride_driver_action($pdo, $owner, (int) $r1['id'], 'start', 'license_checked');
    $r1 = $ride($c1);
    $check(abs(strtotime($r1['ends_at']) - (time() + 4 * 3600)) < 5, 'rental clock starts at hand-over: return by now + 4 h');
    $throws(fn() => uride_driver_action($pdo, $owner, (int) $r1['id'], 'complete'), 'Vehicle returned', 'self-drive uses "Vehicle returned", not "Complete trip"');
    $w0 = uride_wallet_balance($pdo, $owner);
    uride_selfdrive_return($pdo, $owner, (int) $r1['id'], 0, '');
    $r1 = $ride($c1);
    $check($r1['status'] === 'completed' && $r1['deposit_status'] === 'refunded' && (int) $r1['late_fee_centavos'] === 0, 'on-time return: completed, deposit returned');
    $check(uride_wallet_balance($pdo, $owner) === $w0 + 140000 - 21000 && $credits() === 1000000 - 140000, 'owner gets PHP 1,400 less 15% (PHP 210); customer paid only the price');

    // Late return: 1 h 45 min late -> 2 started hours after 15 min grace = PHP 700 from the deposit.
    $c2 = uride_book_rental($pdo, $u, $base, $key()); $r2 = $ride($c2);
    uride_accept($pdo, $owner, (int) $r2['id']); uride_driver_action($pdo, $owner, (int) $r2['id'], 'start', 'license_checked');
    $pdo->exec("UPDATE uride_requests SET ends_at = NOW() - INTERVAL 105 MINUTE WHERE id = " . (int) $r2['id']);
    $c0 = $credits(); $w1 = uride_wallet_balance($pdo, $owner);
    uride_selfdrive_return($pdo, $owner, (int) $r2['id'], 0, '');
    $r2 = $ride($c2);
    $check((int) $r2['late_fee_centavos'] === 70000 && $r2['deposit_status'] === 'settled', 'late by 1 h 45 min: PHP 700 late fee (2 started hours after the 15 min grace)');
    $check($credits() === $c0 + 130000 && uride_wallet_balance($pdo, $owner) === $w1 + 140000 - 21000 + 70000 - 10500, 'customer gets PHP 1,300 back; owner gets price and late fee, less 15% commission');

    // Damage claim decided by Admin.
    $c3 = uride_book_rental($pdo, $u, $base, $key()); $r3 = $ride($c3);
    uride_accept($pdo, $owner, (int) $r3['id']); uride_driver_action($pdo, $owner, (int) $r3['id'], 'start', 'license_checked');
    $throws(fn() => uride_selfdrive_return($pdo, $owner, (int) $r3['id'], 250000, 'Cracked mirror and scratches'), 'up to PHP 2,000', 'claim cannot exceed the deposit');
    $throws(fn() => uride_selfdrive_return($pdo, $owner, (int) $r3['id'], 150000, 'bad'), 'Describe', 'claim needs a description');
    $c0 = $credits();
    uride_selfdrive_return($pdo, $owner, (int) $r3['id'], 150000, 'Cracked left mirror, photos sent');
    $r3 = $ride($c3);
    $check($r3['status'] === 'completed' && $r3['deposit_status'] === 'claimed' && $credits() === $c0, 'damage reported: deposit stays on hold for Admin');
    $throws(fn() => uride_deposit_decide($pdo, (int) $r3['id'], 160000, 'too much', 1), 'up to the claim', 'Admin cannot approve more than the claim');
    $w2 = uride_wallet_balance($pdo, $owner);
    uride_deposit_decide($pdo, (int) $r3['id'], 100000, 'Photos confirm the mirror; repair quote PHP 1,000', 1);
    $r3 = $ride($c3);
    $check($r3['deposit_status'] === 'settled' && (int) $r3['claim_approved_centavos'] === 100000 && uride_wallet_balance($pdo, $owner) === $w2 + 100000 && $credits() === $c0 + 100000, 'Admin approves PHP 1,000 to the owner; PHP 1,000 back to the customer');
    $throws(fn() => uride_deposit_decide($pdo, (int) $r3['id'], 0, 'again', 1), 'no open', 'a claim is decided only once');

    $sfx = array_map(static fn($c) => substr($c, 3), [$c1, $c2, $c3]);
    $groups = [];
    foreach ($sfx as $x) foreach (['DP-', 'DR-', 'LF-', 'DC-'] as $p) $groups[] = $pdo->quote($p . $x);
    $net = (int) $pdo->query("SELECT COALESCE(SUM(credit_centavos - debit_centavos),0) FROM ledger_entries WHERE account = 'uride_deposit' AND entry_group IN (" . implode(',', $groups) . ')')->fetchColumn();
    $bal = $pdo->query("SELECT SUM(debit_centavos) d, SUM(credit_centavos) c FROM ledger_entries WHERE entry_group IN (" . implode(',', $groups) . ')')->fetch();
    $check($net === 0 && (int) $bal['d'] === (int) $bal['c'], 'deposit books balance and nothing is left in deposits held');
    uride_license_review($pdo, $lid, 'rejected', 'Photo is blurry', 1);
    $throws(fn() => uride_book_rental($pdo, $u, $base, $key()), 'not accepted', 'a rejected license blocks self-drive until a new one is added');
    echo "\nAll self-drive tests passed.\n";
} finally {
    foreach ($drivers as $d) $pdo->exec("DELETE FROM uride_fleet WHERE driver_id = $d");
    foreach ($drivers as $d) $pdo->exec("UPDATE uride_drivers SET is_online = 0, status = 'rejected', self_drive_ok = 0, review_note = 'Automated test driver' WHERE id = $d");
    if ($users) {
        $ids = implode(',', $users);
        $codes = $pdo->query("SELECT code FROM uride_requests WHERE user_id IN ($ids)")->fetchAll(PDO::FETCH_COLUMN);
        if ($codes) {
            $g = [];
            foreach ($codes as $c) { $g[] = $pdo->quote($c); foreach (['RF-', 'DP-', 'DR-', 'LF-', 'DC-'] as $p) $g[] = $pdo->quote($p . substr($c, 3)); }
            $pdo->exec('DELETE FROM ledger_entries WHERE entry_group IN (' . implode(',', $g) . ')');
        }
        try { $pdo->exec("DELETE FROM agent_commissions WHERE source_type = 'uride' AND source_id IN (SELECT id FROM uride_requests WHERE user_id IN ($ids))"); } catch (Throwable) {}
        $pdo->exec("DELETE FROM uride_requests WHERE user_id IN ($ids)");
        foreach ($pdo->query("SELECT photo_file FROM user_driver_licenses WHERE user_id IN ($ids)")->fetchAll(PDO::FETCH_COLUMN) as $f) @unlink(app_storage_dir('licenses') . '/' . $f);
        $pdo->exec("DELETE FROM user_driver_licenses WHERE user_id IN ($ids)");
        $pdo->exec("DELETE FROM transactions WHERE user_id IN ($ids)");
        $pdo->exec("DELETE FROM users WHERE id IN ($ids)");
    }
    foreach ($saved as $k => $v) { if ($v === false) setting_clear($pdo, $k); else setting_save($pdo, $k, (string) $v, null); }
}
