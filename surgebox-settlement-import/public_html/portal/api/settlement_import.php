<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/settlement-import.php';

/**
 * V5.24 - Nationlink Settlement Report import (Admin).
 *   GET                         -> import history
 *   POST multipart action=preview, file, organizationId? -> parsed + matched lines (nothing saved)
 *   POST JSON {action:"commit", token}                   -> records the "New" lines
 */
$user = sb_stl_require_admin(true); // Admin only - never the client portal
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $rows = db()->query('SELECT i.*, o.organization_name, CONCAT(u.first_name, \' \', u.last_name) imported_by_name FROM sb_settlement_imports i LEFT JOIN sb_organization o ON o.id = i.organization_id LEFT JOIN sb_users u ON u.id = i.imported_by ORDER BY i.id DESC LIMIT 50')->fetchAll();
        json_response(['status' => 'success', 'data' => $rows]);
    } catch (Throwable $e) {
        json_response(['status' => 'success', 'data' => [], 'notice' => 'Run database/migration_v5_24_settlement_import.sql to keep an import history.']);
    }
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
csrf_verify();

$body = str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') ? json_body() : $_POST;
$action = (string) ($body['action'] ?? 'preview');

if ($action === 'preview') {
    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        $code = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
        json_response(['status' => 'error', 'message' => in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'The file is too large.' : 'Choose the Nationlink settlement report file (PDF, Excel or CSV).'], 400);
    }
    if ((int) $f['size'] > 10 * 1024 * 1024) {
        json_response(['status' => 'error', 'message' => 'The file is too large (max 10 MB).'], 400);
    }
    $orgId = (int) ($body['organizationId'] ?? 0) ?: null;
    if ($orgId && !sb_nl_gateway($orgId)) {
        json_response(['status' => 'error', 'message' => 'That client has no Nationlink gateway. Add it under the client\'s Payment Credentials first.'], 422);
    }
    $name = basename((string) $f['name']);
    try {
        [$rows, $format] = sb_stl_read_file($f['tmp_name'], $name);
        $parsed = sb_stl_parse_rows($rows, $name, $format);
    } catch (RuntimeException $e) {
        json_response(['status' => 'error', 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        error_log('[SurgeBox settlement import] ' . $e->getMessage());
        json_response(['status' => 'error', 'message' => 'The file could not be read. Make sure it is the Nationlink QR Transactions (DTQR) report in PDF, Excel (.xlsx) or CSV.'], 422);
    }
    if (!$parsed['lines']) {
        json_response(['status' => 'error', 'message' => 'No transaction lines were found in this file. Expected columns: TIME STAMP, TRACE NO., SEQ NO., SOURCE ACCOUNT NO., TRAN AMOUNT, TAG, RATE, DISCOUNT, NET SETTLEMENT (grouped by MemberID, or with a MEMBER ID column).'], 422);
    }
    $sha = hash_file('sha256', $f['tmp_name']);
    $preview = sb_stl_preview($parsed, $orgId);
    try {
        $s = db()->prepare('SELECT id, created_at FROM sb_settlement_imports WHERE file_sha256 = ? ORDER BY id DESC LIMIT 1');
        $s->execute([$sha]);
        if ($prev = $s->fetch()) {
            array_unshift($preview['warnings'], 'This same file was already imported on ' . $prev['created_at'] . ' (import #' . $prev['id'] . '). Lines already recorded will be skipped.');
        }
    } catch (Throwable $e) {
    }

    $token = bin2hex(random_bytes(16));
    $_SESSION['sb_stl_import'] = [
        'token' => $token,
        'parsed' => $parsed,
        'org' => $orgId,
        'file' => ['name' => $name, 'format' => $format, 'sha256' => $sha],
        'at' => time(),
    ];
    json_response(['status' => 'success', 'token' => $token, 'file' => ['name' => $name, 'format' => $format], 'data' => $preview]);
}

if ($action === 'commit') {
    $pending = $_SESSION['sb_stl_import'] ?? null;
    $token = (string) ($body['token'] ?? '');
    if (!$pending || $token === '' || !hash_equals($pending['token'], $token) || time() - (int) $pending['at'] > 3600) {
        json_response(['status' => 'error', 'message' => 'The preview expired. Upload the file again.'], 409);
    }
    unset($_SESSION['sb_stl_import']); // one commit per preview
    $result = sb_stl_commit($pending['parsed'], $pending['org'], $pending['file'], (int) $user['id']);
    json_response(['status' => 'success', 'file' => $pending['file'], 'data' => $result]);
}

json_response(['status' => 'error', 'message' => 'Unknown action'], 400);
