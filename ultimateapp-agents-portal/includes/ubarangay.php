<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/ledger.php';

/**
 * UBarangay: permit applications (form + selfie + requirements), payment with Credits,
 * BCash or QR Ph, barangay review, numbered certificates and public verification.
 */

function brgy_barangays(): array
{
    return [
        'Balabag' => ['slug' => 'balabag', 'prefix' => 'BAL'],
        'Manoc-Manoc' => ['slug' => 'manoc_manoc', 'prefix' => 'MAN'],
        'Yapak' => ['slug' => 'yapak', 'prefix' => 'YAP'],
    ];
}

function brgy_valid(string $barangay): bool
{
    return isset(brgy_barangays()[$barangay]);
}

function brgy_officials(string $barangay): array
{
    $slug = brgy_barangays()[$barangay]['slug'] ?? 'balabag';
    return [
        'captain' => (string) setting("brgy.$slug.captain", ''),
        'secretary' => (string) setting("brgy.$slug.secretary", ''),
        'address' => (string) setting("brgy.$slug.address", 'Barangay Hall, ' . $barangay . ', Boracay Island, Malay, Aklan'),
        'contact' => (string) setting("brgy.$slug.contact", ''),
    ];
}

function brgy_statuses(): array
{
    return [
        'pending_payment' => 'Awaiting payment', 'for_review' => 'For barangay review', 'needs_info' => 'Needs more info',
        'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled',
    ];
}

function brgy_payment_label(?string $method): string
{
    return ['credits' => 'Credits', 'boracay_cash' => 'BCash', 'qrph' => 'QR Ph', 'free' => 'Free'][$method ?? ''] ?? '—';
}

function brgy_types(PDO $pdo, bool $activeOnly = true): array
{
    return $pdo->query('SELECT * FROM brgy_permit_types' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, name')->fetchAll();
}

function brgy_type(PDO $pdo, int|string $idOrCode): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM brgy_permit_types WHERE ' . (is_int($idOrCode) ? 'id' : 'code') . ' = ?');
    $stmt->execute([$idOrCode]);
    return $stmt->fetch() ?: null;
}

function brgy_requirements(array $type): array
{
    $list = json_decode((string) $type['requirements'], true);
    if (!is_array($list)) return [];
    return array_values(array_filter($list, static fn($r) => is_array($r) && isset($r['key'], $r['label']) && preg_match('/^[a-z0-9_]{2,40}$/D', (string) $r['key'])));
}

function brgy_storage_dir(int $applicationId): string
{
    return app_storage_dir('brgy/' . $applicationId);
}

function brgy_detect_mime(string $path): string
{
    if (class_exists('finfo')) return (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $head = (string) file_get_contents($path, false, null, 0, 12);
    if (str_starts_with($head, '%PDF')) return 'application/pdf';
    $info = @getimagesize($path);
    return is_array($info) ? (string) $info['mime'] : '';
}

/** Saves the selfie from a camera capture (data URL) or an uploaded photo. Returns the stored file name. */
function brgy_save_selfie(int $applicationId, string $dataUrl, ?array $upload): string
{
    $bytes = null;
    if ($dataUrl !== '') {
        if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#D', $dataUrl, $m)) throw new InvalidArgumentException('The selfie could not be read. Please take it again.');
        $bytes = base64_decode($m[2], true);
    } elseif ($upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($upload['tmp_name'])) {
        if ($upload['size'] > 6 * 1024 * 1024) throw new InvalidArgumentException('The selfie photo must be 6 MB or smaller.');
        $bytes = (string) file_get_contents($upload['tmp_name']);
    }
    if (!$bytes) throw new InvalidArgumentException('Please take a selfie photo.');
    if (strlen($bytes) > 6 * 1024 * 1024) throw new InvalidArgumentException('The selfie photo is too large.');
    $info = @getimagesizefromstring($bytes);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if (!$ext || $info[0] < 160 || $info[1] < 160) throw new InvalidArgumentException('The selfie must be a clear photo (JPG, PNG or WEBP).');
    $name = 'selfie-' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (file_put_contents(brgy_storage_dir($applicationId) . '/' . $name, $bytes, LOCK_EX) === false) throw new RuntimeException('Could not save the selfie.');
    return $name;
}

function brgy_store_requirement(PDO $pdo, int $applicationId, string $key, array $file): bool
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return false;
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('A requirement failed to upload. Please try again.');
    if ($file['size'] > 6 * 1024 * 1024) throw new InvalidArgumentException('Each requirement must be 6 MB or smaller.');
    $mime = brgy_detect_mime($file['tmp_name']);
    $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext) throw new InvalidArgumentException('Upload requirements as photos (JPG/PNG/WEBP) or PDF.');
    $stored = $key . '-' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], brgy_storage_dir($applicationId) . '/' . $stored)) throw new RuntimeException('Could not save the requirement.');
    $name = mb_substr(preg_replace('/[^\w .()-]+/u', '_', (string) $file['name']) ?: 'file', 0, 180);
    $pdo->prepare('INSERT INTO brgy_application_files (application_id, requirement_key, original_name, stored_name, mime, size_bytes) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$applicationId, $key, $name, $stored, $mime, (int) $file['size']]);
    return true;
}

function brgy_files(PDO $pdo, int $applicationId): array
{
    $stmt = $pdo->prepare('SELECT * FROM brgy_application_files WHERE application_id = ? ORDER BY id');
    $stmt->execute([$applicationId]);
    return $stmt->fetchAll();
}

function brgy_send_file(int $applicationId, string $storedName, string $mime, string $downloadName): never
{
    $path = brgy_storage_dir($applicationId) . '/' . basename($storedName);
    if (!is_file($path)) { http_response_code(404); exit('File not found.'); }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $downloadName) . '"');
    header('Cache-Control: private, no-store');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    readfile($path);
    exit;
}

function brgy_data_uri(int $applicationId, ?string $storedName): ?string
{
    if (!$storedName) return null;
    $path = brgy_storage_dir($applicationId) . '/' . basename($storedName);
    if (!is_file($path)) return null;
    $mime = brgy_detect_mime($path);
    return str_starts_with($mime, 'image/') ? 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path)) : null;
}

/** Validates the application form. */
function brgy_validate_form(array $in, array $type): array
{
    $s = static fn(string $k, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', is_string($in[$k] ?? null) ? $in[$k] : '') ?? ''), 0, $max);
    $d = [
        'full_name' => $s('full_name', 120), 'birthdate' => $s('birthdate', 10), 'sex' => $s('sex', 6), 'civil_status' => $s('civil_status', 20),
        'address' => $s('address', 255), 'purok' => $s('purok', 60), 'years_residency' => $s('years_residency', 3), 'contact' => preg_replace('/\D+/', '', $s('contact', 30)),
        'purpose' => $s('purpose', 200), 'business_name' => $s('business_name', 160), 'business_address' => $s('business_address', 255), 'business_nature' => $s('business_nature', 120),
    ];
    if (mb_strlen($d['full_name']) < 4) throw new InvalidArgumentException('Enter your complete name (First, Middle, Last).');
    $dob = DateTimeImmutable::createFromFormat('!Y-m-d', $d['birthdate']);
    if (!$dob || $dob->format('Y-m-d') !== $d['birthdate'] || $dob > new DateTimeImmutable('-1 year') || $dob < new DateTimeImmutable('-120 years')) throw new InvalidArgumentException('Enter a valid birthdate.');
    if (!in_array($d['sex'], ['female', 'male'], true)) throw new InvalidArgumentException('Choose your sex as indicated on your ID.');
    if (!in_array($d['civil_status'], ['single', 'married', 'widowed', 'separated'], true)) throw new InvalidArgumentException('Choose your civil status.');
    if (mb_strlen($d['address']) < 5) throw new InvalidArgumentException('Enter your house number / street / sitio.');
    if ($d['years_residency'] !== '' && (!ctype_digit($d['years_residency']) || (int) $d['years_residency'] > 120)) throw new InvalidArgumentException('Years of residency must be a number.');
    if (!preg_match('/^(09\d{9}|639\d{9})$/D', $d['contact'])) throw new InvalidArgumentException('Enter a valid mobile number (09XXXXXXXXX).');
    if (mb_strlen($d['purpose']) < 3) throw new InvalidArgumentException('Enter the purpose of this request.');
    if ((int) $type['needs_business'] === 1) {
        if (mb_strlen($d['business_name']) < 2 || mb_strlen($d['business_address']) < 5 || mb_strlen($d['business_nature']) < 3) {
            throw new InvalidArgumentException('Enter the business name, address and nature of business.');
        }
    } else {
        $d['business_name'] = $d['business_address'] = $d['business_nature'] = '';
    }
    foreach ($d as $k => $v) if ($v === '') $d[$k] = null;
    $d['years_residency'] = $d['years_residency'] === null ? null : (int) $d['years_residency'];
    return $d;
}

/** Creates an application with selfie and requirements. Free permits go straight to review. */
function brgy_create_application(PDO $pdo, int $userId, array $type, string $barangay, array $form, string $selfieData, ?array $selfieUpload, array $uploads): string
{
    if (!brgy_valid($barangay)) throw new InvalidArgumentException('Choose your barangay.');
    $reqs = brgy_requirements($type);
    foreach ($reqs as $r) {
        if (!empty($r['required']) && (($uploads['req_' . $r['key']]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) {
            throw new InvalidArgumentException('Please upload: ' . $r['label'] . '.');
        }
    }
    $pdo->beginTransaction();
    $dir = null;
    try {
        $reference = 'BP-' . strtoupper(bin2hex(random_bytes(8)));
        $fee = (int) $type['fee_centavos'];
        $cols = array_merge($form, [
            'reference' => $reference, 'user_id' => $userId, 'permit_type_id' => (int) $type['id'], 'barangay' => $barangay,
            'fee_centavos' => $fee, 'verify_token' => bin2hex(random_bytes(16)),
            'status' => $fee === 0 ? 'for_review' : 'pending_payment',
        ]);
        if ($fee === 0) { $cols['payment_method'] = 'free'; }
        $pdo->prepare('INSERT INTO brgy_applications (' . implode(', ', array_keys($cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
        $id = (int) $pdo->lastInsertId();
        $dir = brgy_storage_dir($id);
        $selfie = brgy_save_selfie($id, $selfieData, $selfieUpload);
        $pdo->prepare('UPDATE brgy_applications SET selfie_file = ?, paid_at = IF(payment_method = \'free\', NOW(), paid_at) WHERE id = ?')->execute([$selfie, $id]);
        foreach ($reqs as $r) {
            if (isset($uploads['req_' . $r['key']])) brgy_store_requirement($pdo, $id, $r['key'], $uploads['req_' . $r['key']]);
        }
        $pdo->commit();
        return $reference;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($dir && is_dir($dir)) { array_map('unlink', glob($dir . '/*') ?: []); @rmdir($dir); }
        throw $error;
    }
}

/** Resubmits after "Needs more info": updated details, optional new selfie and extra files. No new payment. */
function brgy_resubmit(PDO $pdo, array $app, array $type, array $form, string $selfieData, ?array $selfieUpload, array $uploads): void
{
    if ($app['status'] !== 'needs_info') throw new InvalidArgumentException('This application cannot be edited now.');
    $pdo->beginTransaction();
    try {
        $set = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($form)));
        $pdo->prepare("UPDATE brgy_applications SET $set, status = 'for_review' WHERE id = ?")->execute([...array_values($form), $app['id']]);
        if ($selfieData !== '' || (($selfieUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
            $pdo->prepare('UPDATE brgy_applications SET selfie_file = ? WHERE id = ?')->execute([brgy_save_selfie((int) $app['id'], $selfieData, $selfieUpload), $app['id']]);
        }
        foreach (brgy_requirements($type) as $r) {
            if (isset($uploads['req_' . $r['key']])) brgy_store_requirement($pdo, (int) $app['id'], $r['key'], $uploads['req_' . $r['key']]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** Pays an application from the resident's Credits or BCash (1 Credit = PHP 1). */
function brgy_pay_wallet(PDO $pdo, int $userId, string $reference, string $method): void
{
    if (!in_array($method, ['credits', 'boracay_cash'], true)) throw new InvalidArgumentException('Choose a payment method.');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT a.*, t.name type_name FROM brgy_applications a JOIN brgy_permit_types t ON t.id = a.permit_type_id WHERE a.reference = ? AND a.user_id = ? FOR UPDATE');
        $stmt->execute([$reference, $userId]);
        $app = $stmt->fetch();
        if (!$app) throw new InvalidArgumentException('Application not found.');
        if ($app['status'] !== 'pending_payment') { $pdo->commit(); return; } // already paid: idempotent
        $fee = (int) $app['fee_centavos'];
        $stmt = $pdo->prepare('SELECT status FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        if (($stmt->fetchColumn() ?: 'active') !== 'active') throw new InvalidArgumentException('Your account cannot make payments right now.');
        if ($method === 'credits') {
            $stmt = $pdo->prepare('UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?');
            $stmt->execute([centavos_to_decimal($fee), $userId, centavos_to_decimal($fee)]);
            if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Insufficient Credits. Top up or choose another payment method.');
            $pdo->prepare("INSERT INTO transactions (user_id, type, title, amount, status) VALUES (?, 'payment', ?, ?, 'Completed')")
                ->execute([$userId, $app['type_name'] . ' fee ' . $reference, '-' . centavos_to_decimal($fee)]);
        } else {
            $stmt = $pdo->prepare('UPDATE boracay_cash_wallets SET balance_centavos = balance_centavos - ? WHERE user_id = ? AND balance_centavos >= ?');
            $stmt->execute([$fee, $userId, $fee]);
            if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Insufficient BCash. Choose another payment method.');
        }
        $pdo->prepare("UPDATE brgy_applications SET status = 'for_review', payment_method = ?, payment_reference = ?, paid_at = NOW() WHERE id = ?")
            ->execute([$method, $reference, $app['id']]);
        ledger_post($pdo, $reference, 'brgy_fee', [
            [($method === 'credits' ? 'user_credits:' : 'user_cash:') . $userId, $fee, 0, $app['type_name']],
            ['brgy_payable:' . brgy_barangays()[$app['barangay']]['slug'], 0, $fee, 'Fee collected for Barangay ' . $app['barangay']],
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** Starts (or reuses) a PayMongo QR Ph checkout for the application fee. */
function brgy_start_qrph(PDO $pdo, array $app, string $typeName): string
{
    require_once __DIR__ . '/paymongo.php';
    $config = pm_config();
    if ($app['checkout_session_id'] && $app['pm_mode'] === $config['mode'] && $app['checkout_url']) return (string) $app['checkout_url'];
    $return = $config['app_url'] . '/brgy-application.php?ref=' . rawurlencode($app['reference']);
    $session = pm_checkout_request($config, $app['reference'], [[
        'name' => $typeName . ' (Barangay ' . $app['barangay'] . ')', 'amount' => (int) $app['fee_centavos'], 'currency' => 'PHP', 'quantity' => 1,
    ]], $return);
    $pdo->prepare("UPDATE brgy_applications SET checkout_session_id = ?, checkout_url = ?, pm_mode = ? WHERE id = ? AND status = 'pending_payment'")
        ->execute([$session['id'], $session['url'], $config['mode'], $app['id']]);
    return $session['url'];
}

/** Settles a paid QR Ph checkout (webhook or return-page reconciliation). */
function brgy_settle_qrph(PDO $pdo, array $session, string $mode): string
{
    require_once __DIR__ . '/paymongo.php';
    $reference = (string) ($session['attributes']['reference_number'] ?? '');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM brgy_applications WHERE reference = ? FOR UPDATE');
        $stmt->execute([$reference]);
        $app = $stmt->fetch();
        if (!$app) { $pdo->rollBack(); return 'ignored'; }
        if (($session['type'] ?? '') !== 'checkout_session' || ($session['id'] ?? '') !== $app['checkout_session_id'] || $app['pm_mode'] !== $mode) {
            throw new UnexpectedValueException('Barangay checkout session mismatch.');
        }
        $paymentId = pm_paid_payment($session, ['amount_centavos' => (int) $app['fee_centavos']]);
        if ($paymentId === null) throw new UnexpectedValueException('Paid QR Ph amount does not match the barangay fee.');
        if ($app['payment_method'] === 'qrph' && $app['paid_at'] !== null) {
            if ($app['payment_reference'] !== $paymentId) throw new UnexpectedValueException('Payment ID mismatch.');
            $pdo->commit();
            return 'already_processed';
        }
        if ($app['status'] !== 'pending_payment') throw new UnexpectedValueException('Application is no longer awaiting payment.');
        $pdo->prepare("UPDATE brgy_applications SET status = 'for_review', payment_method = 'qrph', payment_reference = ?, paid_at = NOW() WHERE id = ?")->execute([$paymentId, $app['id']]);
        ledger_post($pdo, $reference, 'brgy_fee', [
            ['paymongo_clearing', (int) $app['fee_centavos'], 0, 'QR Ph ' . $paymentId],
            ['brgy_payable:' . brgy_barangays()[$app['barangay']]['slug'], 0, (int) $app['fee_centavos'], 'Fee collected for Barangay ' . $app['barangay']],
        ]);
        $pdo->commit();
        return 'processed';
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** Checks PayMongo when the resident returns from checkout (webhook may be delayed). */
function brgy_reconcile_qrph(PDO $pdo, array $app): void
{
    if ($app['status'] !== 'pending_payment' || !$app['checkout_session_id']) return;
    require_once __DIR__ . '/paymongo.php';
    $config = pm_config();
    if ($app['pm_mode'] !== $config['mode']) return;
    $session = pm_retrieve_checkout($config, $app['checkout_session_id']);
    if (pm_paid_payment($session, ['amount_centavos' => (int) $app['fee_centavos']]) !== null) brgy_settle_qrph($pdo, $session, $config['mode']);
}

/** Barangay decision. Approve issues a numbered certificate; reject refunds wallet payments automatically. */
function brgy_review(PDO $pdo, int $applicationId, string $action, string $note, int $adminId): array
{
    $note = trim($note);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT a.*, t.validity_days, t.name type_name FROM brgy_applications a JOIN brgy_permit_types t ON t.id = a.permit_type_id WHERE a.id = ? FOR UPDATE');
        $stmt->execute([$applicationId]);
        $app = $stmt->fetch();
        if (!$app) throw new InvalidArgumentException('Application not found.');
        if (!in_array($app['status'], ['for_review', 'needs_info'], true)) throw new InvalidArgumentException('Only applications for review can be decided.');
        $result = ['status' => $action];
        if ($action === 'approve') {
            $year = (int) date('Y');
            $pdo->prepare('INSERT INTO brgy_certificate_counters (barangay, year, last_no) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE last_no = last_no + 1')->execute([$app['barangay'], $year]);
            $counter = $pdo->prepare('SELECT last_no FROM brgy_certificate_counters WHERE barangay = ? AND year = ? FOR UPDATE');
            $counter->execute([$app['barangay'], $year]);
            $no = (int) $counter->fetchColumn();
            $certificate = brgy_barangays()[$app['barangay']]['prefix'] . '-' . $year . '-' . str_pad((string) $no, 5, '0', STR_PAD_LEFT);
            $valid = (new DateTimeImmutable('today'))->modify('+' . (int) $app['validity_days'] . ' days')->format('Y-m-d');
            $pdo->prepare("UPDATE brgy_applications SET status = 'approved', certificate_no = ?, issued_at = NOW(), valid_until = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
                ->execute([$certificate, $valid, $note ?: null, $adminId, $applicationId]);
            $result['certificate_no'] = $certificate;
        } elseif ($action === 'needs_info') {
            if (mb_strlen($note) < 5) throw new InvalidArgumentException('Tell the resident what is missing.');
            $pdo->prepare("UPDATE brgy_applications SET status = 'needs_info', review_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")->execute([$note, $adminId, $applicationId]);
        } elseif ($action === 'reject') {
            if (mb_strlen($note) < 5) throw new InvalidArgumentException('Give the reason for rejecting.');
            $refund = 'none';
            $fee = (int) $app['fee_centavos'];
            $slug = brgy_barangays()[$app['barangay']]['slug'];
            if ($fee > 0 && in_array($app['payment_method'], ['credits', 'boracay_cash'], true)) {
                if ($app['payment_method'] === 'credits') {
                    $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')->execute([centavos_to_decimal($fee), $app['user_id']]);
                    $pdo->prepare("INSERT INTO transactions (user_id, type, title, amount, status) VALUES (?, 'payment', ?, ?, 'Completed')")->execute([$app['user_id'], 'Refund ' . $app['type_name'] . ' ' . $app['reference'], centavos_to_decimal($fee)]);
                } else {
                    $pdo->prepare('INSERT IGNORE INTO boracay_cash_wallets (user_id) VALUES (?)')->execute([$app['user_id']]);
                    $pdo->prepare('UPDATE boracay_cash_wallets SET balance_centavos = balance_centavos + ? WHERE user_id = ?')->execute([$fee, $app['user_id']]);
                }
                ledger_post($pdo, 'RF-' . substr($app['reference'], 3), 'brgy_refund', [
                    ['brgy_payable:' . $slug, $fee, 0, 'Rejected ' . $app['reference']],
                    [($app['payment_method'] === 'credits' ? 'user_credits:' : 'user_cash:') . $app['user_id'], 0, $fee, 'Refund to resident'],
                ]);
                $refund = 'refunded';
            } elseif ($fee > 0 && $app['payment_method'] === 'qrph') {
                $refund = 'manual_pending';
            }
            $pdo->prepare("UPDATE brgy_applications SET status = 'rejected', refund_status = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")->execute([$refund, $note, $adminId, $applicationId]);
            $result['refund'] = $refund;
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** Revokes an issued certificate (e.g. issued in error). Verification will show it as revoked. */
function brgy_revoke(PDO $pdo, int $applicationId, string $note, int $adminId): void
{
    if (mb_strlen(trim($note)) < 5) throw new InvalidArgumentException('Give the reason for revoking.');
    $stmt = $pdo->prepare("UPDATE brgy_applications SET revoked_at = NOW(), review_note = ?, reviewed_by = ? WHERE id = ? AND status = 'approved' AND revoked_at IS NULL");
    $stmt->execute([trim($note), $adminId, $applicationId]);
    if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Only active certificates can be revoked.');
}

function brgy_certificate_body(array $app, array $type): string
{
    $map = [
        '{name}' => mb_strtoupper($app['full_name']), '{address}' => $app['address'] . ($app['purok'] ? ', ' . $app['purok'] : ''),
        '{barangay}' => $app['barangay'], '{purpose}' => $app['purpose'], '{civil_status}' => (string) $app['civil_status'],
        '{years_residency}' => (string) ($app['years_residency'] ?? '—'), '{birthdate}' => $app['birthdate'] ? date('F j, Y', strtotime($app['birthdate'])) : '—',
        '{business_name}' => mb_strtoupper((string) $app['business_name']), '{business_address}' => (string) $app['business_address'], '{business_nature}' => (string) $app['business_nature'],
    ];
    return strtr((string) $type['certificate_text'], $map);
}

function brgy_certificate_state(array $app): string
{
    if ($app['status'] !== 'approved' || !$app['certificate_no']) return 'not_issued';
    if ($app['revoked_at']) return 'revoked';
    if ($app['valid_until'] && $app['valid_until'] < date('Y-m-d')) return 'expired';
    return 'valid';
}
