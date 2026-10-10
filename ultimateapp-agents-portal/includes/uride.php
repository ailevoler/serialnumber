<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/ledger.php';
require_once __DIR__ . '/agents.php';

/**
 * URide: passenger bookings, driver onboarding, nearby broadcast dispatch, fare matrix,
 * driver wallet (QR Ph top-up, per-trip commission), payouts.
 *
 * Money is integer centavos. Credits rides hold the fare at booking (uride_escrow) and pay the
 * driver's wallet on completion; cash rides only deduct the commission from the driver's wallet.
 */

function uride_vehicles(): array
{
    return [
        'e_trike' => ['label' => 'E-Trike', 'seats' => '3 passengers'],
        'motorcycle' => ['label' => 'Motorcycle', 'seats' => '1 passenger'],
        'car' => ['label' => 'Car', 'seats' => '4 passengers'],
    ];
}

function uride_distance(float $aLat, float $aLng, float $bLat, float $bLng): float
{
    $lat = deg2rad($bLat - $aLat);
    $lng = deg2rad($bLng - $aLng);
    $h = sin($lat / 2) ** 2 + cos(deg2rad($aLat)) * cos(deg2rad($bLat)) * sin($lng / 2) ** 2;
    return round(6371 * 2 * asin(min(1.0, sqrt($h))), 2);
}

function uride_coordinates(array $input, string $prefix): array
{
    $lat = filter_var($input[$prefix . '_lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($input[$prefix . '_lng'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lng === false || $lat < 4 || $lat > 22 || $lng < 116 || $lng > 127) {
        throw new InvalidArgumentException('Select both locations on the map within the Philippines.');
    }
    return [(float) $lat, (float) $lng];
}

/* ---------------------------------------------------------------- settings */

function uride_settings(): array
{
    $f = static fn(string $k, float $d): float => is_numeric(setting($k)) ? (float) setting($k) : $d;
    return [
        'enabled' => setting('uride.enabled', '1') === '1',
        'credits_enabled' => setting('uride.credits_enabled', '1') === '1',
        'cash_enabled' => setting('uride.cash_enabled', '1') === '1',
        'road_factor' => $f('uride.road_factor', 1.3),
        'night_bp' => setting_int('uride.night_surcharge_bp', 0),
        'night_start' => setting_int('uride.night_start', 22),
        'night_end' => setting_int('uride.night_end', 5),
        'commission_type' => setting('uride.commission_type', 'percent') === 'fixed' ? 'fixed' : 'percent',
        'commission_bp' => setting_int('uride.commission_percent_bp', 1000),
        'commission_fixed' => setting_int('uride.commission_fixed_centavos', 0),
        'radius_km' => $f('uride.dispatch_radius_km', 3.0),
        'timeout_min' => setting_int('uride.request_timeout_min', 5),
        'min_wallet' => setting_int('uride.min_wallet_centavos', 5000),
        'topup_min' => setting_int('uride.topup_min_centavos', 10000),
        'topup_max' => setting_int('uride.topup_max_centavos', 1000000),
        'topup_fee' => setting_int('uride.topup_fee_centavos', 0),
        'payout_min' => setting_int('uride.payout_min_centavos', 50000),
        'topup_methods' => array_values(array_filter(['qrph', 'mctc', 'boracay_cash'], 'uride_topup_method_enabled')),
    ];
}

function uride_fare_rule(string $vehicle): array
{
    if (!isset(uride_vehicles()[$vehicle])) throw new InvalidArgumentException('Unknown vehicle type.');
    $defaults = ['e_trike' => [3000, 1500, 3000], 'motorcycle' => [4000, 1200, 4000], 'car' => [10000, 2500, 10000]][$vehicle];
    return [
        'enabled' => setting("uride.$vehicle.enabled", '1') === '1',
        'base' => setting_int("uride.$vehicle.base_centavos", $defaults[0]),
        'included_km' => is_numeric(setting("uride.$vehicle.included_km")) ? (float) setting("uride.$vehicle.included_km") : 1.0,
        'per_km' => setting_int("uride.$vehicle.per_km_centavos", $defaults[1]),
        'minimum' => setting_int("uride.$vehicle.minimum_centavos", $defaults[2]),
    ];
}

function uride_is_night(int $hour, int $start, int $end): bool
{
    if ($start === $end) return false;
    return $start < $end ? ($hour >= $start && $hour < $end) : ($hour >= $start || $hour < $end);
}

/**
 * Server-side fare quote from the Admin fare matrix. Road distance = straight-line distance x road factor.
 * The fare is fixed at booking (upfront price) and rounded up to the whole peso.
 */
function uride_quote(string $vehicle, float $directKm, ?int $hour = null): array
{
    $s = uride_settings();
    $rule = uride_fare_rule($vehicle);
    $roadKm = round($directKm * $s['road_factor'], 2);
    $extraKm = max(0.0, $roadKm - $rule['included_km']);
    $distance = (int) ceil(round($extraKm * $rule['per_km'], 4));
    $subtotal = $rule['base'] + $distance;
    $hour ??= (int) date('G');
    $night = uride_is_night($hour, $s['night_start'], $s['night_end']) ? intdiv($subtotal * $s['night_bp'] + 5000, 10000) : 0;
    $fare = max($rule['minimum'], $subtotal + $night);
    $fare = (int) (ceil($fare / 100) * 100);
    return [
        'vehicle' => $vehicle, 'enabled' => $rule['enabled'], 'direct_km' => $directKm, 'road_km' => $roadKm,
        'base' => $rule['base'], 'distance' => $distance, 'night' => $night, 'minimum' => $rule['minimum'], 'fare' => $fare,
    ];
}

function uride_commission_for(int $fare): int
{
    $s = uride_settings();
    $c = $s['commission_type'] === 'fixed' ? $s['commission_fixed'] : intdiv($fare * $s['commission_bp'] + 5000, 10000);
    return max(0, min($c, $fare));
}

function uride_commission_label(): string
{
    $s = uride_settings();
    return $s['commission_type'] === 'fixed' ? 'PHP ' . peso($s['commission_fixed']) . ' per trip' : rtrim(rtrim(number_format($s['commission_bp'] / 100, 2, '.', ''), '0'), '.') . '% of fare';
}

/* ---------------------------------------------------------------- labels */

function uride_ride_statuses(): array
{
    return [
        'requested' => 'Finding a driver', 'accepted' => 'Driver on the way', 'arrived' => 'Driver has arrived',
        'in_progress' => 'On the trip', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'expired' => 'No driver found',
    ];
}

function uride_active_statuses(): array
{
    return ['requested', 'accepted', 'arrived', 'in_progress'];
}

function uride_driver_statuses(): array
{
    return ['pending' => 'Submitted', 'under_review' => 'Under review', 'needs_info' => 'Needs info', 'approved' => 'Approved', 'rejected' => 'Rejected', 'suspended' => 'Suspended'];
}

function uride_doc_types(): array
{
    return [
        'license' => ["Driver's license (front)", true],
        'license_back' => ["Driver's license (back)", false],
        'or_cr' => ['Vehicle OR/CR', true],
        'franchise' => ['Franchise / MTOP / TODA ID', false],
        'clearance' => ['NBI or Police clearance', false],
        'vehicle_photo' => ['Vehicle photo (plate visible)', false],
    ];
}

function uride_barangays(): array
{
    return ['Balabag', 'Manoc-Manoc', 'Yapak', 'Outside Boracay (Malay)'];
}

function uride_wallet_types(): array
{
    return ['topup' => 'Top-up', 'ride_fare' => 'Ride fare (Credits)', 'commission' => 'Commission', 'payout' => 'Payout', 'payout_returned' => 'Payout returned', 'adjustment' => 'Adjustment'];
}

function uride_payout_methods(): array
{
    return ['GCash', 'Maya', 'BDO', 'BPI', 'Landbank', 'Metrobank', 'PNB', 'UnionBank', 'Security Bank', 'RCBC', 'Other bank'];
}

/** "Juan D." — what passengers see of a driver's name (and drivers of a passenger's). */
function uride_short_name(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $first = $parts[0] ?? 'Driver';
    $last = count($parts) > 1 ? mb_strtoupper(mb_substr((string) end($parts), 0, 1)) . '.' : '';
    return trim($first . ' ' . $last);
}

function uride_rating(array $driver): ?float
{
    return (int) $driver['rating_count'] > 0 ? round((int) $driver['rating_sum'] / (int) $driver['rating_count'], 1) : null;
}

/* ---------------------------------------------------------------- driver accounts & documents */

function uride_driver_new_code(PDO $pdo): string
{
    for ($i = 0; $i < 5; $i++) {
        $code = 'UD-' . strtoupper(bin2hex(random_bytes(5)));
        $stmt = $pdo->prepare('SELECT 1 FROM uride_drivers WHERE code = ?');
        $stmt->execute([$code]);
        if (!$stmt->fetch()) return $code;
    }
    throw new RuntimeException('Could not allocate a driver code.');
}

function uride_storage_dir(): string
{
    return app_storage_dir('driver_docs');
}

function uride_detect_mime(string $path): string
{
    if (class_exists('finfo')) return (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $head = (string) file_get_contents($path, false, null, 0, 12);
    if (str_starts_with($head, '%PDF')) return 'application/pdf';
    $info = @getimagesize($path);
    return is_array($info) ? (string) $info['mime'] : '';
}

/** Stores one onboarding document (PDF/JPG/PNG/WEBP up to 6 MB). Returns false when no file was chosen. */
function uride_store_document(PDO $pdo, int $driverId, string $docType, array $file): bool
{
    if (!isset(uride_doc_types()[$docType])) throw new InvalidArgumentException('Unknown document type.');
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return false;
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('A document failed to upload. Please try again.');
    if ($file['size'] > 6 * 1024 * 1024) throw new InvalidArgumentException('Each document must be 6 MB or smaller.');
    $mime = uride_detect_mime($file['tmp_name']);
    $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if ($ext === null) throw new InvalidArgumentException('Upload PDF, JPG, PNG or WEBP files only.');
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], uride_storage_dir() . '/' . $stored)) throw new RuntimeException('Could not save the document.');
    $name = mb_substr(preg_replace('/[^\w .()-]+/u', '_', (string) $file['name']) ?: 'document', 0, 180);
    $pdo->prepare('INSERT INTO uride_driver_documents (driver_id, doc_type, original_name, stored_name, mime, size_bytes) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$driverId, $docType, $name, $stored, $mime, (int) $file['size']]);
    return true;
}

/** Saves the driver's selfie from a camera capture (data URL) or an uploaded photo. Returns the stored name, or null if none given. */
function uride_save_selfie(string $dataUrl, ?array $upload): ?string
{
    $bytes = null;
    if ($dataUrl !== '') {
        if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#D', $dataUrl, $m)) throw new InvalidArgumentException('The selfie could not be read. Please take it again.');
        $bytes = base64_decode($m[2], true);
    } elseif ($upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($upload['tmp_name'])) {
        if ($upload['size'] > 6 * 1024 * 1024) throw new InvalidArgumentException('The selfie photo must be 6 MB or smaller.');
        $bytes = (string) file_get_contents($upload['tmp_name']);
    } else {
        return null;
    }
    if (!$bytes || strlen($bytes) > 6 * 1024 * 1024) throw new InvalidArgumentException('The selfie photo is missing or too large.');
    $info = @getimagesizefromstring($bytes);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if (!$ext || $info[0] < 160 || $info[1] < 160) throw new InvalidArgumentException('The selfie must be a clear photo (JPG, PNG or WEBP).');
    $name = 'selfie-' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (file_put_contents(uride_storage_dir() . '/' . $name, $bytes, LOCK_EX) === false) throw new RuntimeException('Could not save the selfie.');
    return $name;
}

/** Streams a stored driver file (document or selfie) to an authorised viewer. */
function uride_send_file(string $storedName, string $mime, string $downloadName): never
{
    $path = uride_storage_dir() . '/' . basename($storedName);
    if (!is_file($path)) { http_response_code(404); exit('File not found.'); }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $downloadName) . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    readfile($path);
    exit;
}

function uride_selfie_mime(string $name): string
{
    return ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
}

/** Validates the driver registration / profile form; returns clean values. */
function uride_validate_driver(array $in, bool $withPassword): array
{
    $s = static fn(string $k, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', is_string($in[$k] ?? null) ? $in[$k] : '') ?? ''), 0, $max);
    $d = [
        'full_name' => $s('full_name', 120), 'email' => strtolower($s('email', 160)), 'mobile' => preg_replace('/\D+/', '', $s('mobile', 30)),
        'birthdate' => $s('birthdate', 10), 'address' => $s('address', 255), 'barangay' => $s('barangay', 40),
        'license_no' => strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', $s('license_no', 30))), 'license_expiry' => $s('license_expiry', 10),
        'vehicle_type' => $s('vehicle_type', 20), 'plate_no' => strtoupper(preg_replace('/[^A-Za-z0-9 -]/', '', $s('plate_no', 20))),
        'vehicle_model' => $s('vehicle_model', 80), 'vehicle_color' => $s('vehicle_color', 40),
        'franchise_no' => $s('franchise_no', 60), 'toda_name' => $s('toda_name', 120),
    ];
    if (str_starts_with($d['mobile'], '63')) $d['mobile'] = '0' . substr($d['mobile'], 2);
    if (mb_strlen($d['full_name']) < 4 || !str_contains($d['full_name'], ' ')) throw new InvalidArgumentException('Enter your full name (first and last name).');
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
    if (!preg_match('/^09\d{9}$/D', $d['mobile'])) throw new InvalidArgumentException('Enter a valid PH mobile number (09XXXXXXXXX).');
    $birth = DateTimeImmutable::createFromFormat('!Y-m-d', $d['birthdate']);
    if (!$birth || $birth->format('Y-m-d') !== $d['birthdate'] || $birth > new DateTimeImmutable('-18 years') || $birth < new DateTimeImmutable('-80 years')) {
        throw new InvalidArgumentException('Enter a valid birthdate. Drivers must be at least 18 years old.');
    }
    if (mb_strlen($d['address']) < 5) throw new InvalidArgumentException('Enter your home address.');
    if (!in_array($d['barangay'], uride_barangays(), true)) throw new InvalidArgumentException('Choose your barangay.');
    if (!preg_match('/^[A-Z0-9-]{6,20}$/D', $d['license_no'])) throw new InvalidArgumentException("Enter your driver's license number.");
    $expiry = DateTimeImmutable::createFromFormat('!Y-m-d', $d['license_expiry']);
    if (!$expiry || $expiry->format('Y-m-d') !== $d['license_expiry']) throw new InvalidArgumentException("Enter your license's expiry date.");
    if ($expiry < new DateTimeImmutable('today')) throw new InvalidArgumentException("Your driver's license has expired. Renew it before applying.");
    if (!isset(uride_vehicles()[$d['vehicle_type']])) throw new InvalidArgumentException('Choose your vehicle type.');
    if (!preg_match('/^[A-Z0-9][A-Z0-9 -]{2,11}$/D', $d['plate_no'])) throw new InvalidArgumentException('Enter the plate number (e.g. 123 ABC or AB 1234).');
    if (mb_strlen($d['vehicle_model']) < 2) throw new InvalidArgumentException('Enter the vehicle make and model.');
    if (mb_strlen($d['vehicle_color']) < 3) throw new InvalidArgumentException('Enter the vehicle color.');
    foreach (['franchise_no', 'toda_name'] as $k) if ($d[$k] === '') $d[$k] = null;
    if ($withPassword) {
        $password = is_string($in['password'] ?? null) ? $in['password'] : '';
        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) throw new InvalidArgumentException('Password must be at least 8 characters with letters and numbers.');
        if (!hash_equals($password, is_string($in['password_confirm'] ?? null) ? $in['password_confirm'] : '')) throw new InvalidArgumentException('Passwords do not match.');
        $d['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    }
    return $d;
}

/** Registers a driver with selfie and documents. Returns the new driver id. */
function uride_register_driver(PDO $pdo, array $data, string $selfieData, ?array $selfieUpload, array $files): int
{
    $selfie = uride_save_selfie($selfieData, $selfieUpload);
    if ($selfie === null) throw new InvalidArgumentException('Please take a selfie photo.');
    foreach (uride_doc_types() as $key => [$label, $required]) {
        if ($required && (($files["doc_$key"]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) throw new InvalidArgumentException("Upload your $label.");
    }
    $pdo->beginTransaction();
    try {
        foreach (['email' => 'This email', 'mobile' => 'This mobile number', 'plate_no' => 'This plate number'] as $col => $label) {
            $stmt = $pdo->prepare("SELECT 1 FROM uride_drivers WHERE $col = ?");
            $stmt->execute([$data[$col]]);
            if ($stmt->fetch()) throw new InvalidArgumentException("$label is already registered. Sign in instead.");
        }
        $data['code'] = uride_driver_new_code($pdo);
        $data['selfie_file'] = $selfie;
        $cols = array_keys($data);
        $pdo->prepare('INSERT INTO uride_drivers (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($data));
        $id = (int) $pdo->lastInsertId();
        foreach (array_keys(uride_doc_types()) as $key) {
            if (isset($files["doc_$key"])) uride_store_document($pdo, $id, $key, $files["doc_$key"]);
        }
        $pdo->commit();
        return $id;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        @unlink(uride_storage_dir() . '/' . $selfie);
        throw $error;
    }
}

/* ---------------------------------------------------------------- driver wallet */

/**
 * Changes a driver's wallet and writes the statement line. Caller holds the driver row lock
 * (SELECT ... FOR UPDATE) inside a transaction and posts the matching ledger entry.
 */
function uride_wallet_change(PDO $pdo, int $driverId, int $delta, string $type, string $reference, ?int $rideId, string $memo): int
{
    if (!isset(uride_wallet_types()[$type])) throw new InvalidArgumentException('Unknown wallet entry.');
    if ($delta === 0) return uride_wallet_balance($pdo, $driverId);
    $stmt = $pdo->prepare('UPDATE uride_drivers SET wallet_centavos = wallet_centavos + ? WHERE id = ? AND wallet_centavos + ? >= 0');
    $stmt->execute([$delta, $driverId, $delta]);
    if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Insufficient driver wallet balance.');
    $balance = uride_wallet_balance($pdo, $driverId);
    $pdo->prepare('INSERT INTO uride_wallet_tx (driver_id, type, amount_centavos, balance_after, reference, ride_id, memo) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$driverId, $type, $delta, $balance, $reference, $rideId, mb_substr($memo, 0, 190)]);
    return $balance;
}

function uride_wallet_balance(PDO $pdo, int $driverId): int
{
    $stmt = $pdo->prepare('SELECT wallet_centavos FROM uride_drivers WHERE id = ?');
    $stmt->execute([$driverId]);
    return (int) $stmt->fetchColumn();
}

function uride_lock_driver(PDO $pdo, int $driverId): array
{
    $stmt = $pdo->prepare('SELECT * FROM uride_drivers WHERE id = ? FOR UPDATE');
    $stmt->execute([$driverId]);
    $driver = $stmt->fetch();
    if (!$driver) throw new InvalidArgumentException('Driver not found.');
    return $driver;
}

function uride_lock_ride(PDO $pdo, int $rideId): array
{
    $stmt = $pdo->prepare('SELECT * FROM uride_requests WHERE id = ? FOR UPDATE');
    $stmt->execute([$rideId]);
    $ride = $stmt->fetch();
    if (!$ride) throw new InvalidArgumentException('Ride not found.');
    return $ride;
}

function uride_tx(PDO $pdo, callable $fn): mixed
{
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/* ---------------------------------------------------------------- passenger bookings */

/** Books a ride. Credits rides hold the fare now. Returns the ride code. */
function uride_book(PDO $pdo, int $userId, array $in, string $requestKey): string
{
    $s = uride_settings();
    if (!$s['enabled']) throw new InvalidArgumentException('URide bookings are paused right now.');
    if (!preg_match('/^[a-f0-9]{64}$/D', $requestKey)) throw new InvalidArgumentException('Invalid booking request.');
    $vehicle = (string) ($in['vehicle_type'] ?? '');
    $payment = (string) ($in['payment_method'] ?? '');
    $pickup = mb_substr(trim((string) ($in['pickup_address'] ?? '')), 0, 255);
    $dropoff = mb_substr(trim((string) ($in['dropoff_address'] ?? '')), 0, 255);
    $note = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($in['passenger_note'] ?? '')) ?? ''), 0, 200);
    if (!isset(uride_vehicles()[$vehicle]) || !in_array($payment, ['credits', 'cash'], true) || mb_strlen($pickup) < 3 || mb_strlen($dropoff) < 3) {
        throw new InvalidArgumentException('Complete the locations, vehicle, and payment method.');
    }
    if (!$s[$payment . '_enabled']) throw new InvalidArgumentException(($payment === 'credits' ? 'Credits' : 'Cash') . ' payment for rides is turned off right now.');
    [$aLat, $aLng] = uride_coordinates($in, 'pickup');
    [$bLat, $bLng] = uride_coordinates($in, 'dropoff');
    $km = uride_distance($aLat, $aLng, $bLat, $bLng);
    if ($km < 0.1 || $km > 150) throw new InvalidArgumentException('Choose two different locations within the service area.');
    $quote = uride_quote($vehicle, $km);
    if (!$quote['enabled']) throw new InvalidArgumentException(uride_vehicles()[$vehicle]['label'] . ' rides are not available right now.');
    $fare = $quote['fare'];

    return uride_tx($pdo, function () use ($pdo, $userId, $requestKey, $vehicle, $payment, $pickup, $dropoff, $note, $aLat, $aLng, $bLat, $bLng, $km, $quote, $fare, $s) {
        $stmt = $pdo->prepare('SELECT id, credits, status FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || ($user['status'] ?? 'active') !== 'active') throw new InvalidArgumentException('Your account cannot book rides right now.');
        $stmt = $pdo->prepare('SELECT code FROM uride_requests WHERE request_key = ? AND user_id = ?');
        $stmt->execute([$requestKey, $userId]);
        if ($existing = $stmt->fetchColumn()) return (string) $existing;
        $stmt = $pdo->prepare("SELECT id FROM uride_requests WHERE user_id = ? AND status IN ('requested','accepted','arrived','in_progress') LIMIT 1");
        $stmt->execute([$userId]);
        if ($stmt->fetch()) throw new InvalidArgumentException('You already have an active ride. Finish or cancel it before booking another.');
        $code = 'UR-' . strtoupper(bin2hex(random_bytes(6)));
        $held = 0;
        if ($payment === 'credits') {
            $stmt = $pdo->prepare('UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?');
            $stmt->execute([centavos_to_decimal($fare), $userId, centavos_to_decimal($fare)]);
            if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Not enough Credits for this ride (PHP ' . peso($fare) . '). Buy Credits or choose cash.');
            $held = $fare;
        }
        $breakdown = json_encode(['base' => $quote['base'], 'distance' => $quote['distance'], 'night' => $quote['night'], 'minimum' => $quote['minimum'], 'road_km' => $quote['road_km']]);
        $pdo->prepare("INSERT INTO uride_requests (user_id, request_key, code, vehicle_type, pickup_address, pickup_lat, pickup_lng, dropoff_address, dropoff_lat, dropoff_lng, distance_km, road_km, estimated_fare, fare_centavos, fare_breakdown, payment_method, held_centavos, passenger_note, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$userId, $requestKey, $code, $vehicle, $pickup, $aLat, $aLng, $dropoff, $bLat, $bLng, $km, $quote['road_km'], centavos_to_decimal($fare), $fare, $breakdown, $payment, $held, $note ?: null, date('Y-m-d H:i:s', time() + 60 * $s['timeout_min'])]);
        if ($held > 0) {
            $pdo->prepare("INSERT INTO transactions (user_id, type, title, amount, status) VALUES (?, 'payment', ?, ?, 'Completed')")
                ->execute([$userId, 'URide fare on hold ' . $code, '-' . centavos_to_decimal($held)]);
            ledger_post($pdo, $code, 'uride_hold', [
                ['user_credits:' . $userId, $held, 0, 'Fare held for ' . $code],
                ['uride_escrow', 0, $held, 'Ride fare on hold'],
            ]);
        }
        return $code;
    });
}

/** Returns a held Credits fare to the passenger. Caller holds the ride lock. */
function uride_release_hold(PDO $pdo, array $ride, string $why): void
{
    $held = (int) $ride['held_centavos'];
    if ($held <= 0) return;
    $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')->execute([centavos_to_decimal($held), $ride['user_id']]);
    $pdo->prepare('UPDATE uride_requests SET held_centavos = 0 WHERE id = ?')->execute([$ride['id']]);
    $pdo->prepare("INSERT INTO transactions (user_id, type, title, amount, status) VALUES (?, 'payment', ?, ?, 'Completed')")
        ->execute([$ride['user_id'], 'URide refund ' . $ride['code'] . ' (' . $why . ')', centavos_to_decimal($held)]);
    ledger_post($pdo, 'RF-' . substr($ride['code'], 3), 'uride_release', [
        ['uride_escrow', $held, 0, 'Released: ' . $why],
        ['user_credits:' . $ride['user_id'], 0, $held, 'Fare returned'],
    ]);
}

/** Marks requests nobody accepted in time as expired and refunds their holds. */
function uride_expire_stale(PDO $pdo): int
{
    $ids = $pdo->query("SELECT id FROM uride_requests WHERE status = 'requested' AND expires_at IS NOT NULL AND expires_at <= NOW() LIMIT 50")->fetchAll(PDO::FETCH_COLUMN);
    $n = 0;
    foreach ($ids as $id) {
        try {
            $n += (int) uride_tx($pdo, function () use ($pdo, $id) {
                $ride = uride_lock_ride($pdo, (int) $id);
                if ($ride['status'] !== 'requested' || strtotime((string) $ride['expires_at']) > time()) return 0;
                $pdo->prepare("UPDATE uride_requests SET status = 'expired', cancelled_by = 'system', cancelled_at = NOW(), cancel_reason = 'No driver accepted in time' WHERE id = ?")->execute([$ride['id']]);
                uride_release_hold($pdo, $ride, 'no driver found');
                return 1;
            });
        } catch (Throwable $e) {
            error_log('URide expiry failed for ride ' . $id . ': ' . $e->getMessage());
        }
    }
    return $n;
}

function uride_cancel_by_passenger(PDO $pdo, int $userId, int $rideId, string $reason): void
{
    uride_tx($pdo, function () use ($pdo, $userId, $rideId, $reason) {
        $ride = uride_lock_ride($pdo, $rideId);
        if ((int) $ride['user_id'] !== $userId) throw new InvalidArgumentException('Ride not found.');
        if (!in_array($ride['status'], ['requested', 'accepted', 'arrived'], true)) throw new InvalidArgumentException('This ride can no longer be cancelled.');
        $pdo->prepare("UPDATE uride_requests SET status = 'cancelled', cancelled_by = 'passenger', cancelled_at = NOW(), cancel_reason = ? WHERE id = ?")
            ->execute([mb_substr(trim($reason), 0, 200) ?: null, $rideId]);
        uride_release_hold($pdo, $ride, 'cancelled');
    });
}

function uride_admin_cancel(PDO $pdo, int $rideId, string $reason): array
{
    return uride_tx($pdo, function () use ($pdo, $rideId, $reason) {
        $ride = uride_lock_ride($pdo, $rideId);
        if (!in_array($ride['status'], uride_active_statuses(), true)) throw new InvalidArgumentException('Only active rides can be cancelled.');
        if (mb_strlen(trim($reason)) < 4) throw new InvalidArgumentException('Give the reason for cancelling.');
        $pdo->prepare("UPDATE uride_requests SET status = 'cancelled', cancelled_by = 'admin', cancelled_at = NOW(), cancel_reason = ? WHERE id = ?")->execute([mb_substr(trim($reason), 0, 200), $rideId]);
        uride_release_hold($pdo, $ride, 'cancelled by Ultimate App');
        return $ride;
    });
}

function uride_rate(PDO $pdo, int $userId, int $rideId, int $rating, string $comment): void
{
    if ($rating < 1 || $rating > 5) throw new InvalidArgumentException('Choose 1 to 5 stars.');
    uride_tx($pdo, function () use ($pdo, $userId, $rideId, $rating, $comment) {
        $ride = uride_lock_ride($pdo, $rideId);
        if ((int) $ride['user_id'] !== $userId || $ride['status'] !== 'completed' || !$ride['driver_id']) throw new InvalidArgumentException('Only completed rides can be rated.');
        if ($ride['rating'] !== null) throw new InvalidArgumentException('You already rated this ride.');
        $pdo->prepare('UPDATE uride_requests SET rating = ?, rating_comment = ? WHERE id = ?')->execute([$rating, mb_substr(trim($comment), 0, 300) ?: null, $rideId]);
        $pdo->prepare('UPDATE uride_drivers SET rating_sum = rating_sum + ?, rating_count = rating_count + 1 WHERE id = ?')->execute([$rating, $ride['driver_id']]);
    });
}

/* ---------------------------------------------------------------- dispatch (driver side) */

/** A driver can receive requests when approved, online, and their location is fresh (last 2 minutes). */
function uride_driver_is_live(array $driver): bool
{
    return $driver['status'] === 'approved' && (int) $driver['is_online'] === 1 && $driver['lat'] !== null
        && $driver['location_at'] !== null && strtotime((string) $driver['location_at']) >= time() - 120;
}

function uride_driver_active_ride(PDO $pdo, int $driverId): ?array
{
    $stmt = $pdo->prepare("SELECT r.*, u.full_name passenger_name, u.mobile passenger_mobile FROM uride_requests r JOIN users u ON u.id = r.user_id WHERE r.driver_id = ? AND r.status IN ('accepted','arrived','in_progress') ORDER BY r.id DESC LIMIT 1");
    $stmt->execute([$driverId]);
    return $stmt->fetch() ?: null;
}

function uride_set_online(PDO $pdo, int $driverId, bool $online): void
{
    uride_tx($pdo, function () use ($pdo, $driverId, $online) {
        $driver = uride_lock_driver($pdo, $driverId);
        if ($online) {
            if ($driver['status'] !== 'approved') throw new InvalidArgumentException('Your account must be approved before you can go online.');
            $min = uride_settings()['min_wallet'];
            if ((int) $driver['wallet_centavos'] < $min) throw new InvalidArgumentException('Top up your wallet to at least PHP ' . peso($min) . ' to go online.');
        }
        $pdo->prepare('UPDATE uride_drivers SET is_online = ? WHERE id = ?')->execute([$online ? 1 : 0, $driverId]);
    });
}

function uride_heartbeat(PDO $pdo, int $driverId, float $lat, float $lng): void
{
    if ($lat < 4 || $lat > 22 || $lng < 116 || $lng > 127) throw new InvalidArgumentException('Location outside the service area.');
    $pdo->prepare('UPDATE uride_drivers SET lat = ?, lng = ?, location_at = NOW() WHERE id = ?')->execute([round($lat, 7), round($lng, 7), $driverId]);
}

/** Open requests near the driver, nearest first. */
function uride_feed(PDO $pdo, array $driver): array
{
    if (!uride_driver_is_live($driver)) return [];
    $s = uride_settings();
    $lat = (float) $driver['lat']; $lng = (float) $driver['lng'];
    $dLat = $s['radius_km'] / 111.0;
    $dLng = $s['radius_km'] / max(1.0, 111.0 * cos(deg2rad($lat)));
    $stmt = $pdo->prepare("SELECT r.id, r.code, r.vehicle_type, r.pickup_address, r.pickup_lat, r.pickup_lng, r.dropoff_address, r.dropoff_lat, r.dropoff_lng, r.road_km, r.fare_centavos, r.payment_method, r.passenger_note, r.expires_at, r.created_at, u.full_name
        FROM uride_requests r JOIN users u ON u.id = r.user_id
        WHERE r.status = 'requested' AND r.vehicle_type = ? AND r.expires_at > NOW()
          AND r.pickup_lat BETWEEN ? AND ? AND r.pickup_lng BETWEEN ? AND ?
          AND NOT EXISTS (SELECT 1 FROM uride_ride_skips k WHERE k.ride_id = r.id AND k.driver_id = ?)
        ORDER BY r.created_at LIMIT 30");
    $stmt->execute([$driver['vehicle_type'], $lat - $dLat, $lat + $dLat, $lng - $dLng, $lng + $dLng, $driver['id']]);
    $out = [];
    foreach ($stmt as $r) {
        $away = uride_distance($lat, $lng, (float) $r['pickup_lat'], (float) $r['pickup_lng']);
        if ($away > $s['radius_km']) continue;
        $fare = (int) $r['fare_centavos'];
        $commission = uride_commission_for($fare);
        $out[] = [
            'id' => (int) $r['id'], 'code' => $r['code'], 'passenger' => uride_short_name((string) $r['full_name']),
            'pickup' => $r['pickup_address'], 'dropoff' => $r['dropoff_address'], 'pickup_km' => $away, 'trip_km' => (float) $r['road_km'],
            'fare' => peso($fare), 'commission' => peso($commission), 'net' => peso($fare - $commission),
            'payment' => $r['payment_method'], 'note' => $r['passenger_note'], 'expires_in' => max(0, strtotime((string) $r['expires_at']) - time()),
        ];
    }
    usort($out, static fn($a, $b) => $a['pickup_km'] <=> $b['pickup_km']);
    return $out;
}

/** First driver to accept gets the ride (row locks make this race-safe). */
function uride_accept(PDO $pdo, int $driverId, int $rideId): array
{
    return uride_tx($pdo, function () use ($pdo, $driverId, $rideId) {
        $driver = uride_lock_driver($pdo, $driverId);
        if (!uride_driver_is_live($driver)) throw new InvalidArgumentException('Go online with location turned on to accept rides.');
        if (uride_driver_active_ride($pdo, $driverId)) throw new InvalidArgumentException('Finish your current ride first.');
        $ride = uride_lock_ride($pdo, $rideId);
        if ($ride['status'] !== 'requested' || strtotime((string) $ride['expires_at']) <= time()) throw new InvalidArgumentException('Sorry, this ride was taken by another driver or is no longer available.');
        if ($ride['vehicle_type'] !== $driver['vehicle_type']) throw new InvalidArgumentException('This request is for a different vehicle type.');
        $s = uride_settings();
        $away = uride_distance((float) $driver['lat'], (float) $driver['lng'], (float) $ride['pickup_lat'], (float) $ride['pickup_lng']);
        if ($away > $s['radius_km'] + 0.5) throw new InvalidArgumentException('You are too far from this pick-up.');
        $commission = uride_commission_for((int) $ride['fare_centavos']);
        $need = max($s['min_wallet'], $ride['payment_method'] === 'cash' ? $commission : 0);
        if ((int) $driver['wallet_centavos'] < $need) throw new InvalidArgumentException('Your wallet needs at least PHP ' . peso($need) . ' to accept this ride. Top up first.');
        $pdo->prepare("UPDATE uride_requests SET status = 'accepted', driver_id = ?, accepted_at = NOW(), commission_centavos = ? WHERE id = ?")->execute([$driverId, $commission, $rideId]);
        return uride_lock_ride($pdo, $rideId);
    });
}

function uride_skip(PDO $pdo, int $driverId, int $rideId): void
{
    $pdo->prepare('INSERT IGNORE INTO uride_ride_skips (ride_id, driver_id) VALUES (?, ?)')->execute([$rideId, $driverId]);
}

/**
 * Driver trip actions: arrived, start, complete, release (give the ride back before pick-up).
 * Completion pays the driver (Credits rides) and deducts the commission from the wallet.
 */
function uride_driver_action(PDO $pdo, int $driverId, int $rideId, string $action, string $reason = ''): array
{
    return uride_tx($pdo, function () use ($pdo, $driverId, $rideId, $action, $reason) {
        $driver = uride_lock_driver($pdo, $driverId);
        $ride = uride_lock_ride($pdo, $rideId);
        if ((int) $ride['driver_id'] !== $driverId) throw new InvalidArgumentException('This ride is not assigned to you.');
        switch ($action) {
            case 'arrived':
                if ($ride['status'] !== 'accepted') throw new InvalidArgumentException('Mark arrival only while heading to the pick-up.');
                $pdo->prepare("UPDATE uride_requests SET status = 'arrived', arrived_at = NOW() WHERE id = ?")->execute([$rideId]);
                break;
            case 'start':
                if (!in_array($ride['status'], ['accepted', 'arrived'], true)) throw new InvalidArgumentException('This trip cannot be started.');
                $pdo->prepare("UPDATE uride_requests SET status = 'in_progress', started_at = NOW(), arrived_at = COALESCE(arrived_at, NOW()) WHERE id = ?")->execute([$rideId]);
                break;
            case 'release':
                if (!in_array($ride['status'], ['accepted', 'arrived'], true)) throw new InvalidArgumentException('A trip in progress cannot be given back.');
                $timeout = uride_settings()['timeout_min'];
                $pdo->prepare("UPDATE uride_requests SET status = 'requested', driver_id = NULL, accepted_at = NULL, arrived_at = NULL, commission_centavos = NULL, expires_at = ? WHERE id = ?")
                    ->execute([date('Y-m-d H:i:s', time() + 60 * $timeout), $rideId]);
                uride_skip($pdo, $driverId, $rideId);
                break;
            case 'complete':
                if ($ride['status'] !== 'in_progress') throw new InvalidArgumentException('Start the trip before completing it.');
                uride_settle_completed($pdo, $driver, $ride);
                break;
            default:
                throw new InvalidArgumentException('Unknown action.');
        }
        return uride_lock_ride($pdo, $rideId);
    });
}

/** Money movements when a trip completes. Caller holds driver and ride locks. */
function uride_settle_completed(PDO $pdo, array $driver, array $ride): void
{
    $driverId = (int) $driver['id'];
    $fare = (int) $ride['fare_centavos'];
    $commission = (int) ($ride['commission_centavos'] ?? uride_commission_for($fare));
    $lines = [];
    if ($ride['payment_method'] === 'credits') {
        $held = (int) $ride['held_centavos'];
        if ($held !== $fare) throw new RuntimeException('Held Credits do not match the fare for ' . $ride['code'] . '.');
        uride_wallet_change($pdo, $driverId, $fare, 'ride_fare', $ride['code'], (int) $ride['id'], 'Credits fare from passenger');
        $pdo->prepare('UPDATE uride_requests SET held_centavos = 0 WHERE id = ?')->execute([$ride['id']]);
        $pdo->prepare("INSERT INTO transactions (user_id, type, title, amount, status) VALUES (?, 'payment', ?, 0, 'Completed')")
            ->execute([$ride['user_id'], 'URide trip completed ' . $ride['code'] . ' · PHP ' . peso($fare) . ' paid']);
        $lines[] = ['uride_escrow', $fare, 0, 'Fare released to driver'];
        $lines[] = ['driver_wallet:' . $driverId, 0, $fare - $commission, 'Fare less commission'];
    } else {
        // Cash was paid to the driver directly. If an admin adjustment left the wallet short, take what is there.
        $commission = min($commission, uride_wallet_balance($pdo, $driverId));
        $lines[] = ['driver_wallet:' . $driverId, $commission, 0, 'Commission on cash trip'];
    }
    if ($commission > 0) {
        uride_wallet_change($pdo, $driverId, -$commission, 'commission', $ride['code'], (int) $ride['id'], 'Commission ' . uride_commission_label());
        $lines[] = ['fee_revenue:uride', 0, $commission, 'URide commission'];
        ledger_post($pdo, $ride['code'], 'uride_trip', $lines);
    } elseif ($ride['payment_method'] === 'credits') {
        ledger_post($pdo, $ride['code'], 'uride_trip', $lines);
    }
    $pdo->prepare("UPDATE uride_requests SET status = 'completed', completed_at = NOW(), commission_centavos = ? WHERE id = ?")->execute([$commission, $ride['id']]);
    $pdo->prepare('UPDATE uride_drivers SET trips_completed = trips_completed + 1 WHERE id = ?')->execute([$driverId]);
    // Referral agent of the passenger earns on the fare (paid by Ultimate App; the driver's net is unchanged).
    agent_record_commission($pdo, (int) $ride['user_id'], 'URide', 'uride', (int) $ride['id'], $ride['code'], $fare, true);
}

/* ---------------------------------------------------------------- driver top-ups (QR Ph) */

/** Top-up methods for the driver wallet. */
function uride_topup_methods(): array
{
    return [
        'qrph' => ['QR Ph', 'GCash, Maya or any bank app'],
        'mctc' => ['MCTC', 'Pay cash at an MCTC top-up center'],
        'boracay_cash' => ['BCash', 'Pay from an Ultimate App account'],
    ];
}

function uride_topup_method_enabled(string $method): bool
{
    $key = ['qrph' => 'uride.topup_qrph_enabled', 'mctc' => 'uride.topup_mctc_enabled', 'boracay_cash' => 'uride.topup_bcash_enabled'][$method] ?? null;
    return $key !== null && setting($key, '1') === '1';
}

/** Service fee for a top-up method (BCash is an in-app transfer: no fee). */
function uride_topup_fee(string $method): int
{
    return $method === 'boracay_cash' ? 0 : uride_settings()['topup_fee'];
}

/** A pending MCTC / BCash request that is past its time limit. */
function uride_topup_is_expired(array $order): bool
{
    return $order['status'] === 'pending' && $order['method'] !== 'qrph' && $order['expires_at'] !== null && strtotime((string) $order['expires_at']) <= time();
}

/**
 * Starts a driver wallet top-up and returns the page to open.
 * qrph: PayMongo QR Ph (in-app QR, or hosted checkout fallback).
 * mctc: a QR the MCTC agent scans after receiving cash (valid 24 hours).
 * boracay_cash: a QR / link that an Ultimate App user opens to pay from BCash (valid 30 minutes).
 */
function uride_topup_start(PDO $pdo, array $driver, int $amount, array $config, string $method = 'qrph'): string
{
    require_once __DIR__ . '/paymongo.php';
    $s = uride_settings();
    if (!isset(uride_topup_methods()[$method]) || !uride_topup_method_enabled($method)) throw new InvalidArgumentException('This top-up method is not available right now.');
    if ($amount < $s['topup_min'] || $amount > $s['topup_max']) throw new InvalidArgumentException('Top up from PHP ' . peso($s['topup_min']) . ' to PHP ' . peso($s['topup_max']) . '.');
    if ($amount % 100 !== 0) throw new InvalidArgumentException('Enter a whole peso amount.');
    if (!in_array($config['mode'] ?? '', ['test', 'live'], true)) throw new RuntimeException('Payment environment unavailable.');
    $reference = 'DT-' . bin2hex(random_bytes(16));
    $fee = uride_topup_fee($method);
    if ($method !== 'qrph') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM uride_driver_topups WHERE driver_id = ? AND method <> 'qrph' AND status = 'pending' AND expires_at > NOW()");
        $stmt->execute([$driver['id']]);
        if ((int) $stmt->fetchColumn() >= 3) throw new InvalidArgumentException('You have 3 open top-up requests. Use or cancel one first.');
        $minutes = $method === 'mctc' ? 24 * 60 : 30;
        $pdo->prepare('INSERT INTO uride_driver_topups (reference, driver_id, method, amount_centavos, fee_centavos, mode, request_token, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$reference, $driver['id'], $method, $amount, $fee, $config['mode'], bin2hex(random_bytes(32)), date('Y-m-d H:i:s', time() + 60 * $minutes)]);
        return 'topup-request.php?reference=' . rawurlencode($reference);
    }
    pm_api_key($config);
    $pdo->prepare('INSERT INTO uride_driver_topups (reference, driver_id, method, amount_centavos, fee_centavos, mode) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$reference, $driver['id'], 'qrph', $amount, $fee, $config['mode']]);
    if (setting('paymongo.qr_flow', 'inapp') === 'inapp') {
        try {
            $email = filter_var($driver['email'], FILTER_VALIDATE_EMAIL) ? $driver['email'] : null;
            $phone = preg_match('/^09\d{9}$/D', (string) $driver['mobile']) ? '+63' . substr((string) $driver['mobile'], 1) : null;
            $qr = pm_create_qrph_qr($config, $amount + $fee, 'URide driver wallet top-up ' . $driver['code'], $reference, ['name' => $driver['full_name'], 'email' => $email, 'phone' => $phone]);
            $pdo->prepare('UPDATE uride_driver_topups SET payment_intent_id = ?, qr_image = ?, qr_expires_at = ? WHERE reference = ?')
                ->execute([$qr['intent_id'], $qr['image'], $qr['expires_at'], $reference]);
            return 'topup-qr.php?reference=' . rawurlencode($reference);
        } catch (Throwable $error) {
            error_log('Driver in-app QR Ph unavailable, using checkout: ' . get_class($error) . ' code=' . $error->getCode());
        }
    }
    $items = [['name' => 'URide driver wallet top-up', 'amount' => $amount, 'currency' => 'PHP', 'quantity' => 1]];
    if ($fee > 0) $items[] = ['name' => 'Top-up service fee', 'amount' => $fee, 'currency' => 'PHP', 'quantity' => 1];
    $base = rtrim($config['app_url'], '/');
    $session = pm_checkout_request($config, $reference, $items, $base . '/driver/wallet.php?topup=' . rawurlencode($reference));
    $pdo->prepare('UPDATE uride_driver_topups SET checkout_session_id = ?, checkout_url = ? WHERE reference = ?')->execute([$session['id'], $session['url'], $reference]);
    return $session['url'];
}

/**
 * Credits the driver's wallet once for a paid top-up. Caller holds the top-up row lock.
 * $source is the ledger account the money came from (PayMongo clearing, an MCTC agent's cash, or a customer's BCash).
 */
function uride_topup_credit(PDO $pdo, array $order, string $paymentId, string $source = 'paymongo_clearing'): void
{
    $driverId = (int) $order['driver_id'];
    uride_lock_driver($pdo, $driverId);
    $label = uride_topup_methods()[$order['method'] ?? 'qrph'][0] ?? 'QR Ph';
    $pdo->prepare("UPDATE uride_driver_topups SET status = 'paid', payment_id = ?, paid_at = NOW() WHERE id = ?")->execute([$paymentId, $order['id']]);
    uride_wallet_change($pdo, $driverId, (int) $order['amount_centavos'], 'topup', substr($order['reference'], 0, 40), null, ($order['mode'] === 'test' ? '[TEST] ' : '') . $label . ' top-up');
    ledger_post($pdo, substr($order['reference'], 0, 40), 'uride_topup', [
        [$source, (int) $order['amount_centavos'] + (int) $order['fee_centavos'], 0, $label . ' ' . $paymentId],
        ['driver_wallet:' . $driverId, 0, (int) $order['amount_centavos'], 'Driver wallet top-up'],
        ['fee_revenue:driver_topup', 0, (int) $order['fee_centavos'], 'Top-up service fee'],
    ]);
}

/** MCTC agent confirms they received cash for a driver top-up. Idempotent per request. */
function uride_topup_mctc_settle(PDO $pdo, string $token, int $agentId, string $receipt, string $mode): string
{
    $receipt = trim($receipt);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{3,79}$/D', $receipt)) throw new InvalidArgumentException('Enter a valid payment receipt and try again.');
    return uride_tx($pdo, function () use ($pdo, $token, $agentId, $receipt, $mode) {
        $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
        $stmt->execute([$agentId]);
        if (($stmt->fetchColumn() ?: '') !== 'mctc') throw new InvalidArgumentException('MCTC merchant access required.');
        $stmt = $pdo->prepare("SELECT * FROM uride_driver_topups WHERE request_token = ? AND method = 'mctc' FOR UPDATE");
        $stmt->execute([$token]);
        $order = $stmt->fetch();
        if (!$order) throw new InvalidArgumentException('MCTC request not found.');
        if ($order['status'] === 'paid') return 'already_paid';
        if ($order['mode'] !== $mode) throw new InvalidArgumentException('This request belongs to a different payment environment.');
        if ($order['status'] !== 'pending' || uride_topup_is_expired($order)) throw new InvalidArgumentException('This top-up request has expired or was cancelled. Ask the driver to create a new one.');
        $pdo->prepare('UPDATE uride_driver_topups SET mctc_user_id = ?, mctc_receipt = ? WHERE id = ?')->execute([$agentId, $receipt, $order['id']]);
        uride_topup_credit($pdo, $order, 'MCTC-' . $agentId . '-' . $receipt, 'mctc_collections:' . $agentId);
        return 'approved';
    });
}

/** An admin confirms cash received for a pending MCTC request (e.g. paid at the Ultimate App office). */
function uride_topup_admin_approve(PDO $pdo, int $topupId, int $adminId, string $receipt): array
{
    $receipt = trim($receipt);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{3,79}$/D', $receipt)) throw new InvalidArgumentException('Enter the official receipt / reference number (4–80 letters or numbers).');
    return uride_tx($pdo, function () use ($pdo, $topupId, $adminId, $receipt) {
        $stmt = $pdo->prepare("SELECT * FROM uride_driver_topups WHERE id = ? AND method = 'mctc' FOR UPDATE");
        $stmt->execute([$topupId]);
        $order = $stmt->fetch();
        if (!$order) throw new InvalidArgumentException('Top-up request not found.');
        if ($order['status'] === 'paid') throw new InvalidArgumentException('This top-up was already paid.');
        if ($order['status'] !== 'pending' || uride_topup_is_expired($order)) throw new InvalidArgumentException('This request has expired or was cancelled. Ask the driver to create a new one.');
        $dup = $pdo->prepare("SELECT 1 FROM uride_driver_topups WHERE approved_by_admin IS NOT NULL AND mctc_receipt = ? AND id <> ?");
        $dup->execute([$receipt, $topupId]);
        if ($dup->fetch()) throw new InvalidArgumentException('This receipt number was already used for another top-up.');
        $pdo->prepare('UPDATE uride_driver_topups SET approved_by_admin = ?, mctc_receipt = ? WHERE id = ?')->execute([$adminId, $receipt, $topupId]);
        uride_topup_credit($pdo, $order, 'ADMIN-' . $adminId . '-' . $receipt, 'office_cash');
        return $order;
    });
}

/** An Ultimate App user pays a driver's top-up request from their BCash. */
function uride_topup_bcash_pay(PDO $pdo, int $userId, string $token): array
{
    return uride_tx($pdo, function () use ($pdo, $userId, $token) {
        $stmt = $pdo->prepare('SELECT id, status FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || ($user['status'] ?? 'active') !== 'active') throw new InvalidArgumentException('Your account cannot make payments right now.');
        $stmt = $pdo->prepare("SELECT * FROM uride_driver_topups WHERE request_token = ? AND method = 'boracay_cash' FOR UPDATE");
        $stmt->execute([$token]);
        $order = $stmt->fetch();
        if (!$order) throw new InvalidArgumentException('Top-up request not found.');
        if ($order['status'] === 'paid') {
            if ((int) $order['payer_user_id'] === $userId) return $order;
            throw new InvalidArgumentException('This top-up request was already paid.');
        }
        if ($order['status'] !== 'pending' || uride_topup_is_expired($order)) throw new InvalidArgumentException('This top-up request has expired or was cancelled. Ask the driver for a new QR.');
        $amount = (int) $order['amount_centavos'] + (int) $order['fee_centavos'];
        $stmt = $pdo->prepare('UPDATE boracay_cash_wallets SET balance_centavos = balance_centavos - ? WHERE user_id = ? AND balance_centavos >= ?');
        $stmt->execute([$amount, $userId, $amount]);
        if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Not enough BCash for this top-up (PHP ' . peso($amount) . ').');
        $pdo->prepare('UPDATE uride_driver_topups SET payer_user_id = ? WHERE id = ?')->execute([$userId, $order['id']]);
        uride_topup_credit($pdo, $order, 'BC-' . $userId . '-' . substr($order['reference'], 3, 12), 'user_cash:' . $userId);
        $stmt = $pdo->prepare('SELECT * FROM uride_driver_topups WHERE id = ?');
        $stmt->execute([$order['id']]);
        return $stmt->fetch();
    });
}

/** Driver cancels an unpaid MCTC / BCash request. */
function uride_topup_cancel(PDO $pdo, int $driverId, string $reference): void
{
    uride_tx($pdo, function () use ($pdo, $driverId, $reference) {
        $stmt = $pdo->prepare("SELECT * FROM uride_driver_topups WHERE reference = ? AND driver_id = ? AND method <> 'qrph' FOR UPDATE");
        $stmt->execute([$reference, $driverId]);
        $order = $stmt->fetch();
        if (!$order || $order['status'] !== 'pending') throw new InvalidArgumentException('Only an unpaid request can be cancelled.');
        $pdo->prepare("UPDATE uride_driver_topups SET status = 'cancelled' WHERE id = ?")->execute([$order['id']]);
    });
}

/** In-app QR Ph (Payment Intent) settlement for driver top-ups. Idempotent. */
function uride_topup_settle_intent(PDO $pdo, array $config, array $intent): string
{
    require_once __DIR__ . '/paymongo.php';
    return uride_tx($pdo, function () use ($pdo, $config, $intent) {
        $stmt = $pdo->prepare('SELECT * FROM uride_driver_topups WHERE payment_intent_id = ? FOR UPDATE');
        $stmt->execute([$intent['id']]);
        $order = $stmt->fetch();
        if (!$order) return 'ignored';
        if ($order['mode'] !== $config['mode']) throw new UnexpectedValueException('Driver top-up mode mismatch.');
        $paymentId = pm_intent_paid_payment($intent, (int) $order['amount_centavos'] + (int) $order['fee_centavos']);
        if ($paymentId === null) return 'pending';
        if ($order['status'] === 'paid') {
            if ($order['payment_id'] !== $paymentId) throw new UnexpectedValueException('Payment ID mismatch.');
            return 'already_processed';
        }
        uride_topup_credit($pdo, $order, $paymentId);
        return 'processed';
    });
}

/** Hosted checkout settlement for driver top-ups (reference DT-…). Idempotent. */
function uride_topup_settle_checkout(PDO $pdo, array $session, string $mode): string
{
    require_once __DIR__ . '/paymongo.php';
    return uride_tx($pdo, function () use ($pdo, $session, $mode) {
        $stmt = $pdo->prepare('SELECT * FROM uride_driver_topups WHERE reference = ? FOR UPDATE');
        $stmt->execute([(string) ($session['attributes']['reference_number'] ?? '')]);
        $order = $stmt->fetch();
        if (!$order) return 'ignored';
        if ($order['mode'] !== $mode || ($session['type'] ?? '') !== 'checkout_session' || ($session['id'] ?? '') !== $order['checkout_session_id']) {
            throw new UnexpectedValueException('Driver top-up checkout mismatch.');
        }
        $paymentId = pm_paid_payment($session, ['amount_centavos' => (int) $order['amount_centavos'] + (int) $order['fee_centavos']]);
        if ($paymentId === null) throw new UnexpectedValueException('Paid amount does not match the driver top-up.');
        if ($order['status'] === 'paid') {
            if ($order['payment_id'] !== $paymentId) throw new UnexpectedValueException('Payment ID mismatch.');
            return 'already_processed';
        }
        uride_topup_credit($pdo, $order, $paymentId);
        return 'processed';
    });
}

/** Asks PayMongo about a pending top-up (driver is waiting on the QR page). */
function uride_topup_reconcile(PDO $pdo, array $order): string
{
    require_once __DIR__ . '/paymongo.php';
    if ($order['status'] === 'paid') return 'paid';
    $config = pm_config();
    if ($order['mode'] !== $config['mode']) return 'pending';
    if ($order['payment_intent_id']) {
        $intent = pm_api($config, 'GET', '/v1/payment_intents/' . rawurlencode($order['payment_intent_id']));
        if (($intent['id'] ?? '') !== $order['payment_intent_id'] || ($intent['attributes']['livemode'] ?? null) !== ($config['mode'] === 'live')) {
            throw new UnexpectedValueException('Payment intent lookup mismatch.');
        }
        $r = uride_topup_settle_intent($pdo, $config, $intent);
    } elseif ($order['checkout_session_id']) {
        $session = pm_retrieve_checkout($config, $order['checkout_session_id']);
        if (pm_paid_payment($session, ['amount_centavos' => (int) $order['amount_centavos'] + (int) $order['fee_centavos']]) === null) return 'pending';
        $r = uride_topup_settle_checkout($pdo, $session, $config['mode']);
    } else {
        return 'pending';
    }
    return in_array($r, ['processed', 'already_processed'], true) ? 'paid' : 'pending';
}

/* ---------------------------------------------------------------- payouts & adjustments */

function uride_payout_request(PDO $pdo, int $driverId, int $amount): string
{
    return uride_tx($pdo, function () use ($pdo, $driverId, $amount) {
        $driver = uride_lock_driver($pdo, $driverId);
        $s = uride_settings();
        if (!in_array($driver['status'], ['approved', 'suspended'], true)) throw new InvalidArgumentException('Payouts are available to approved drivers.');
        if (!$driver['payout_method'] || !$driver['payout_account_no']) throw new InvalidArgumentException('Add your payout account first.');
        if ($amount < $s['payout_min']) throw new InvalidArgumentException('Minimum payout is PHP ' . peso($s['payout_min']) . '.');
        if (uride_driver_active_ride($pdo, $driverId)) throw new InvalidArgumentException('Finish your current ride before requesting a payout.');
        $stmt = $pdo->prepare("SELECT 1 FROM uride_payouts WHERE driver_id = ? AND status = 'requested'");
        $stmt->execute([$driverId]);
        if ($stmt->fetch()) throw new InvalidArgumentException('You already have a payout being processed.');
        if ($amount > (int) $driver['wallet_centavos']) throw new InvalidArgumentException('Amount is more than your wallet balance.');
        $reference = 'DP-' . strtoupper(bin2hex(random_bytes(6)));
        uride_wallet_change($pdo, $driverId, -$amount, 'payout', $reference, null, 'Payout to ' . $driver['payout_method']);
        $pdo->prepare('INSERT INTO uride_payouts (reference, driver_id, amount_centavos, payout_method, payout_account_name, payout_account_no) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$reference, $driverId, $amount, $driver['payout_method'], $driver['payout_account_name'], $driver['payout_account_no']]);
        ledger_post($pdo, $reference, 'uride_payout_request', [
            ['driver_wallet:' . $driverId, $amount, 0, 'Payout requested'],
            ['settlement_payable:driver_' . $driverId, 0, $amount, 'Owed to driver'],
        ]);
        return $reference;
    });
}

function uride_payout_decide(PDO $pdo, int $payoutId, string $action, string $bankRef, string $note, int $adminId): array
{
    return uride_tx($pdo, function () use ($pdo, $payoutId, $action, $bankRef, $note, $adminId) {
        $stmt = $pdo->prepare('SELECT * FROM uride_payouts WHERE id = ? FOR UPDATE');
        $stmt->execute([$payoutId]);
        $p = $stmt->fetch();
        if (!$p) throw new InvalidArgumentException('Payout not found.');
        if ($p['status'] !== 'requested') throw new InvalidArgumentException('This payout was already processed.');
        $amount = (int) $p['amount_centavos'];
        $driverId = (int) $p['driver_id'];
        if ($action === 'paid') {
            $bankRef = trim($bankRef);
            if (!preg_match('/^[A-Za-z0-9 ._\/-]{4,80}$/D', $bankRef)) throw new InvalidArgumentException('Enter the bank / e-wallet transfer reference.');
            $pdo->prepare("UPDATE uride_payouts SET status = 'paid', bank_reference = ?, note = ?, processed_by = ?, processed_at = NOW() WHERE id = ?")->execute([$bankRef, mb_substr(trim($note), 0, 300) ?: null, $adminId, $payoutId]);
            ledger_post($pdo, $p['reference'], 'uride_payout_paid', [
                ['settlement_payable:driver_' . $driverId, $amount, 0, 'Paid ' . $bankRef],
                ['bank_clearing', 0, $amount, 'Driver payout'],
            ]);
        } elseif ($action === 'rejected') {
            if (mb_strlen(trim($note)) < 4) throw new InvalidArgumentException('Give the reason for returning this payout.');
            uride_lock_driver($pdo, $driverId);
            $pdo->prepare("UPDATE uride_payouts SET status = 'rejected', note = ?, processed_by = ?, processed_at = NOW() WHERE id = ?")->execute([mb_substr(trim($note), 0, 300), $adminId, $payoutId]);
            uride_wallet_change($pdo, $driverId, $amount, 'payout_returned', $p['reference'], null, 'Payout returned: ' . trim($note));
            ledger_post($pdo, 'RV-' . substr($p['reference'], 3), 'uride_payout_returned', [
                ['settlement_payable:driver_' . $driverId, $amount, 0, 'Payout returned'],
                ['driver_wallet:' . $driverId, 0, $amount, 'Back to wallet'],
            ]);
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
        return $p;
    });
}

function uride_admin_adjust(PDO $pdo, int $driverId, int $delta, string $memo, int $adminId): string
{
    if ($delta === 0) throw new InvalidArgumentException('Enter a non-zero amount.');
    if (mb_strlen(trim($memo)) < 4) throw new InvalidArgumentException('Give the reason for the adjustment.');
    return uride_tx($pdo, function () use ($pdo, $driverId, $delta, $memo, $adminId) {
        uride_lock_driver($pdo, $driverId);
        $reference = 'DA-' . strtoupper(bin2hex(random_bytes(6)));
        uride_wallet_change($pdo, $driverId, $delta, 'adjustment', $reference, null, trim($memo) . ' (admin #' . $adminId . ')');
        $abs = abs($delta);
        ledger_post($pdo, $reference, 'uride_adjustment', $delta > 0
            ? [['adjustments', $abs, 0, trim($memo)], ['driver_wallet:' . $driverId, 0, $abs, 'Admin credit']]
            : [['driver_wallet:' . $driverId, $abs, 0, 'Admin debit'], ['adjustments', 0, $abs, trim($memo)]]);
        return $reference;
    });
}

/** Driver review decision by an admin. */
function uride_review_driver(PDO $pdo, int $driverId, string $status, string $note, int $adminId): void
{
    if (!isset(uride_driver_statuses()[$status]) || $status === 'pending') throw new InvalidArgumentException('Choose a decision.');
    if (in_array($status, ['needs_info', 'rejected', 'suspended'], true) && mb_strlen(trim($note)) < 5) throw new InvalidArgumentException('Add a note the driver will see.');
    uride_tx($pdo, function () use ($pdo, $driverId, $status, $note, $adminId) {
        $driver = uride_lock_driver($pdo, $driverId);
        if ($status !== 'approved' && uride_driver_active_ride($pdo, $driverId) && in_array($status, ['suspended', 'rejected'], true)) {
            throw new InvalidArgumentException('This driver has a ride in progress. Cancel or finish it first.');
        }
        $online = $status === 'approved' ? (int) $driver['is_online'] : 0;
        $pdo->prepare('UPDATE uride_drivers SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW(), is_online = ? WHERE id = ?')
            ->execute([$status, trim($note) ?: null, $adminId, $online, $driverId]);
    });
}
