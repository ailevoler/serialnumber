<?php
declare(strict_types=1);

/**
 * URide Self-Drive rentals. The vehicle owner (an approved URide driver who offers self-drive) brings the
 * vehicle, checks the customer's driver's license and ID, and hands it over. The customer drives and returns
 * it to the pick-up point. The rental price and a security deposit are held in Credits:
 * - price: paid to the owner's wallet at return, less the usual URide commission;
 * - late return (after the grace minutes): per started hour at the self-drive hourly rate, taken from the deposit;
 * - damage: the owner files a claim (up to what is left of the deposit); Admin decides; the rest is refunded.
 */

const URIDE_LICENSE_PATTERN = '/^[A-Z0-9][A-Z0-9-]{4,24}$/D';

function uride_licenses_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $pdo->query('SELECT 1 FROM user_driver_licenses LIMIT 0'); $pdo->query('SELECT deposit_status FROM uride_requests LIMIT 0'); $ready = true; } catch (Throwable) { $ready = false; }
    }
    return $ready;
}

/** The customer's current license (latest upload). */
function uride_user_license(PDO $pdo, int $userId): ?array
{
    if (!uride_licenses_ready($pdo)) return null;
    $stmt = $pdo->prepare('SELECT * FROM user_driver_licenses WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function uride_license_allows(array $license, string $vehicle): bool
{
    $class = uride_license_class($vehicle);
    if ($class === 'none') return true;
    return $class === 'car' ? (int) $license['can_car'] === 1 : (int) $license['can_motorcycle'] === 1;
}

function uride_license_label(array $license): string
{
    $c = array_filter([(int) $license['can_motorcycle'] ? 'Motorcycle / tricycle (A, A1)' : null, (int) $license['can_car'] ? 'Car (B)' : null]);
    return $license['license_no'] . ' · ' . implode(' + ', $c) . ' · expires ' . date('M j, Y', strtotime((string) $license['expiry']));
}

/** Saves an uploaded license photo as a clean JPEG (EXIF removed) in private storage. */
function uride_license_store_upload(?array $file): string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new InvalidArgumentException('Take or choose a photo of the front of your license.');
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name']) && PHP_SAPI !== 'cli') throw new InvalidArgumentException('The license photo did not upload. Please try again.');
    if ((int) $file['size'] > 8 * 1024 * 1024) throw new InvalidArgumentException('The license photo is too large (8 MB max).');
    $bytes = (string) file_get_contents((string) $file['tmp_name']);
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) throw new InvalidArgumentException('The license photo must be a JPG, PNG or WebP image.');
    if (min($info[0], $info[1]) < 400) throw new InvalidArgumentException('The license photo is too small. Take a clearer photo.');
    $name = bin2hex(random_bytes(16)) . '.jpg';
    $path = app_storage_dir('licenses') . '/' . $name;
    $img = function_exists('imagecreatefromstring') ? @imagecreatefromstring($bytes) : false;
    if ($img) {
        $w = imagesx($img); $h = imagesy($img); $max = 1600;
        if (max($w, $h) > $max) {
            $scale = $max / max($w, $h);
            $dst = imagecreatetruecolor((int) round($w * $scale), (int) round($h * $scale));
            imagecopyresampled($dst, $img, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
            imagedestroy($img); $img = $dst;
        }
        $ok = imagejpeg($img, $path, 85);
        imagedestroy($img);
        if (!$ok) throw new RuntimeException('Could not save the license photo.');
    } else {
        if ($info['mime'] !== 'image/jpeg') throw new InvalidArgumentException('The license photo must be a JPG on this server.');
        if (file_put_contents($path, $bytes, LOCK_EX) === false) throw new RuntimeException('Could not save the license photo.');
    }
    return $name;
}

/** Adds the customer's driver's license (pending review; owners also check the card at hand-over). */
function uride_save_license(PDO $pdo, int $userId, array $in, ?array $file): int
{
    $no = strtoupper(preg_replace('/\s+/', '', (string) ($in['license_no'] ?? '')) ?? '');
    $expiry = (string) ($in['expiry'] ?? '');
    $moto = ($in['can_motorcycle'] ?? '') === '1';
    $car = ($in['can_car'] ?? '') === '1';
    if (!preg_match(URIDE_LICENSE_PATTERN, $no)) throw new InvalidArgumentException('Enter the license number as printed (e.g. N01-12-345678).');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $expiry) || !strtotime($expiry)) throw new InvalidArgumentException('Enter the expiry date.');
    if ($expiry < date('Y-m-d')) throw new InvalidArgumentException('This license has expired.');
    if (!$moto && !$car) throw new InvalidArgumentException('Tick what your license allows you to drive.');
    $photo = uride_license_store_upload($file);
    $pdo->prepare('INSERT INTO user_driver_licenses (user_id, license_no, expiry, can_motorcycle, can_car, photo_file) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$userId, $no, $expiry, $moto ? 1 : 0, $car ? 1 : 0, $photo]);
    return (int) $pdo->lastInsertId();
}

function uride_license_review(PDO $pdo, int $licenseId, string $status, string $note, int $adminId): void
{
    if (!in_array($status, ['approved', 'rejected'], true)) throw new InvalidArgumentException('Choose approve or reject.');
    if ($status === 'rejected' && mb_strlen(trim($note)) < 4) throw new InvalidArgumentException('Tell the customer why (e.g. "photo is blurry", "expired").');
    $stmt = $pdo->prepare('UPDATE user_driver_licenses SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?');
    $stmt->execute([$status, mb_substr(trim($note), 0, 255) ?: null, $adminId, $licenseId]);
    if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('License not found.');
}

/** Returns part or all of a held deposit to the customer. Caller holds the ride lock. */
function uride_deposit_refund(PDO $pdo, array $ride, int $amount, string $why, string $status = 'refunded'): void
{
    if ($amount > 0) {
        $source = ($ride['payment_method'] ?? 'credits') === 'bcash' ? 'bcash' : 'credits';
        wallet_credit_tx($pdo, (int) $ride['user_id'], $source, $amount, 'Self-drive deposit returned ' . $ride['code'] . ' (' . $why . ')');
        ledger_post($pdo, 'DR-' . substr((string) $ride['code'], 3), 'uride_deposit_refund', [
            ['uride_deposit', $amount, 0, 'Deposit returned: ' . $why],
            [wallet_account($source, (int) $ride['user_id']), 0, $amount, 'Deposit returned'],
        ]);
    }
    $pdo->prepare('UPDATE uride_requests SET deposit_status = ? WHERE id = ?')->execute([$status, $ride['id']]);
}

/** Pays part of a deposit to the owner's wallet (late fee less commission, or an approved damage claim). */
function uride_deposit_pay_owner(PDO $pdo, array $ride, int $driverId, int $amount, string $type, string $memo, int $commission = 0): void
{
    if ($amount <= 0) return;
    $commission = max(0, min($commission, $amount));
    uride_wallet_change($pdo, $driverId, $amount - $commission, $type, (string) $ride['code'], (int) $ride['id'], $memo);
    $lines = [['uride_deposit', $amount, 0, $memo], ['driver_wallet:' . $driverId, 0, $amount - $commission, $memo]];
    if ($commission > 0) $lines[] = ['fee_revenue:uride', 0, $commission, 'URide commission on ' . strtolower($memo)];
    ledger_post($pdo, ($type === 'late_fee' ? 'LF-' : 'DC-') . substr((string) $ride['code'], 3), 'uride_' . $type, $lines);
}

/** Late fee for a return at $returnedTs: per started hour after the grace minutes, at the self-drive hourly rate. */
function uride_selfdrive_late_fee(array $ride, int $returnedTs): array
{
    if (empty($ride['ends_at'])) return [0, 0];
    $grace = setting_int('uride.self_drive_grace_min', 15);
    $lateMin = intdiv($returnedTs - strtotime((string) $ride['ends_at']), 60) - $grace;
    if ($lateMin <= 0) return [0, 0];
    $hours = (int) ceil($lateMin / 60);
    $rate = (int) (json_decode((string) $ride['fare_breakdown'], true)['hour_rate'] ?? uride_self_rule((string) $ride['vehicle_type'])['hour']);
    return [$hours, $hours * $rate];
}

/**
 * The owner confirms the vehicle is back. Pays the rental (like a completed trip), takes any late fee from the
 * deposit, and either refunds the rest or holds it for Admin when the owner reports damage ($claim > 0).
 */
function uride_selfdrive_return(PDO $pdo, int $driverId, int $rideId, int $claim, string $note): array
{
    return uride_tx($pdo, function () use ($pdo, $driverId, $rideId, $claim, $note) {
        $driver = uride_lock_driver($pdo, $driverId);
        $ride = uride_lock_ride($pdo, $rideId);
        if ((int) $ride['driver_id'] !== $driverId) throw new InvalidArgumentException('This rental is not assigned to you.');
        if (!uride_is_self_drive($ride) || $ride['status'] !== 'in_progress') throw new InvalidArgumentException('Only a self-drive rental in progress can be returned.');
        $note = mb_substr(trim(preg_replace('/\s+/u', ' ', $note) ?? ''), 0, 300);
        $deposit = (int) $ride['deposit_centavos'];
        [$lateHours, $lateFee] = uride_selfdrive_late_fee($ride, time());
        $lateFee = min($lateFee, $deposit);
        $left = $deposit - $lateFee;
        if ($claim < 0 || $claim > $left) throw new InvalidArgumentException('A damage claim can be up to PHP ' . peso($left) . ' (what is left of the deposit).');
        if ($claim > 0 && mb_strlen($note) < 10) throw new InvalidArgumentException('Describe the damage (what, where) so Ultimate App can review the claim.');

        uride_settle_completed($pdo, $driver, $ride);
        if ($lateFee > 0) {
            uride_deposit_pay_owner($pdo, $ride, $driverId, $lateFee, 'late_fee', 'Late return ' . $lateHours . ' h', uride_commission_for($lateFee, 'rental'));
        }
        $pdo->prepare('UPDATE uride_requests SET returned_at = NOW(), late_fee_centavos = ?, claim_centavos = ?, claim_note = ? WHERE id = ?')
            ->execute([$lateFee, $claim, $claim > 0 ? $note : ($note ?: null), $rideId]);
        $ride = uride_lock_ride($pdo, $rideId);
        if ($claim > 0) {
            $pdo->prepare("UPDATE uride_requests SET deposit_status = 'claimed' WHERE id = ?")->execute([$rideId]);
        } elseif ($deposit > 0) {
            uride_deposit_refund($pdo, $ride, $left, $lateFee > 0 ? 'returned ' . $lateHours . ' h late' : 'vehicle returned', $lateFee > 0 ? 'settled' : 'refunded');
        }
        return uride_lock_ride($pdo, $rideId);
    });
}

/** Admin decides a damage claim: pays up to the claim to the owner and returns the rest of the deposit. */
function uride_deposit_decide(PDO $pdo, int $rideId, int $approved, string $note, int $adminId): array
{
    return uride_tx($pdo, function () use ($pdo, $rideId, $approved, $note, $adminId) {
        $ride = uride_lock_ride($pdo, $rideId);
        if ($ride['deposit_status'] !== 'claimed') throw new InvalidArgumentException('There is no open damage claim on this rental.');
        $left = (int) $ride['deposit_centavos'] - (int) $ride['late_fee_centavos'];
        if ($approved < 0 || $approved > (int) $ride['claim_centavos'] || $approved > $left) throw new InvalidArgumentException('Approve from PHP 0 up to the claim (PHP ' . peso(min((int) $ride['claim_centavos'], $left)) . ').');
        if (mb_strlen(trim($note)) < 4) throw new InvalidArgumentException('Add a short reason for the decision.');
        if ($approved > 0) {
            uride_lock_driver($pdo, (int) $ride['driver_id']);
            uride_deposit_pay_owner($pdo, $ride, (int) $ride['driver_id'], $approved, 'damage_claim', 'Damage claim approved');
        }
        uride_deposit_refund($pdo, $ride, $left - $approved, $approved > 0 ? 'claim decided' : 'claim not approved', 'settled');
        $pdo->prepare('UPDATE uride_requests SET claim_approved_centavos = ?, claim_decided_by = ?, claim_decided_at = NOW(), claim_note = CONCAT(COALESCE(claim_note, \'\'), ?) WHERE id = ?')
            ->execute([$approved, $adminId, ' | Admin: ' . mb_substr(trim($note), 0, 120), $rideId]);
        return uride_lock_ride($pdo, $rideId);
    });
}

function uride_deposit_status_label(array $ride): string
{
    $d = (int) $ride['deposit_centavos'];
    return match ($ride['deposit_status']) {
        'held' => 'PHP ' . peso($d) . ' deposit on hold',
        'refunded' => 'PHP ' . peso($d) . ' deposit returned',
        'claimed' => 'Deposit on hold: the owner reported damage (PHP ' . peso((int) $ride['claim_centavos']) . '). Ultimate App is reviewing it.',
        'settled' => 'Deposit settled: ' . ((int) $ride['late_fee_centavos'] ? 'late fee PHP ' . peso((int) $ride['late_fee_centavos']) . ', ' : '') . ($ride['claim_approved_centavos'] !== null ? 'damage PHP ' . peso((int) $ride['claim_approved_centavos']) . ', ' : '') . 'PHP ' . peso($d - (int) $ride['late_fee_centavos'] - (int) ($ride['claim_approved_centavos'] ?? 0)) . ' returned',
        default => '',
    };
}

/** Streams a license photo (callers check who may see it). */
function uride_license_send(string $file): never
{
    if (!preg_match('/^[a-f0-9]{32}\.jpg$/D', $file)) { http_response_code(404); exit('Not found.'); }
    $path = app_storage_dir('licenses') . '/' . $file;
    if (!is_file($path)) { http_response_code(404); exit('Not found.'); }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox");
    readfile($path);
    exit;
}
