<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

/**
 * Admin > Client > Payment Credentials (API + Webhook + Fees per client).
 *
 * GET  ?organizationId=ID                  -> gateways, providers, field schema, approval
 * POST {action:"save", organizationId, provider_code, ...}
 * POST {action:"approve", organizationId, docs_to_follow, notes}
 * POST {action:"revoke_approval", organizationId}
 * POST {action:"test", id}
 * POST {action:"register_webhook", id}
 * POST {action:"delete", id}
 */
$user = require_role(['Admin'], true);
$method = $_SERVER['REQUEST_METHOD'];

function cg_payload(int $orgId): array
{
    $org = sb_org_row($orgId);
    if (!$org) {
        json_response(['status' => 'error', 'message' => 'Client not found'], 404);
    }
    $providers = [];
    foreach (sb_providers() as $p) {
        $p['fields'] = sb_provider_fields($p['code']);
        $providers[] = $p;
    }
    return [
        'organization' => [
            'id' => (int) $org['id'],
            'organization_name' => $org['organization_name'],
            'client_code' => $org['client_code'],
            'onboarding_status' => $org['onboarding_status'],
            'approved_at' => $org['approved_at'],
            'approval_notes' => $org['approval_notes'],
            'is_approved' => sb_org_is_approved($org),
        ],
        'documents' => sb_org_document_summary($orgId),
        'providers' => $providers,
        'gateways' => array_map('sb_gateway_for_browser', sb_org_gateways($orgId)),
        'app_key_set' => (string) env('APP_KEY', '') !== '',
    ];
}

/** V5.25: true once migration_v5_25_fee_brackets.sql has added sb_client_gateways.fee_brackets. */
function cg_has_brackets_column(): bool
{
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool) db()->query("SHOW COLUMNS FROM sb_client_gateways LIKE 'fee_brackets'")->fetch();
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/** Re-apply a gateway's CURRENT fee settings to all its existing collections. */
function cg_recalc_fees(array $gw): array
{
    $gwId = (int) $gw['id'];
    // Re-apply this gateway's CURRENT fee settings to its existing collections
    // (e.g. payments received before the PHP10 fee was configured).
    $pdo = db();
    $s = $pdo->prepare("SELECT * FROM sb_transactions WHERE gateway_id = ? AND type = 'Cash In' AND status = 'Completed' ORDER BY id");
    $s->execute([$gwId]);
    $rows = $s->fetchAll();
    $orgId = (int) $gw['organization_id'];
    $changed = 0;
    $walletDelta = 0.0;
    $adminDelta = 0.0;
    $deltaById = [];
    $pdo->beginTransaction();
    try {
        $u = $pdo->prepare('UPDATE sb_transactions SET fee = ?, fee_mode = ?, fee_status = ?, net_amount = ? WHERE id = ?');
        foreach ($rows as $t) {
            $amount = (float) $t['amount'];
            $fee = sb_compute_client_fee($gw, $amount);
            $mode = $gw['fee_mode'] === 'separate' ? 'separate' : 'deduct';
            $net = $mode === 'separate' ? $amount : round($amount - $fee, 2);
            $status = $fee <= 0 ? null : ($mode === 'deduct' ? 'Deducted' : (in_array($t['fee_status'], ['Billed', 'Paid', 'Waived'], true) ? $t['fee_status'] : 'Unbilled'));
            if (abs($fee - (float) $t['fee']) < 0.005 && abs($net - (float) $t['net_amount']) < 0.005 && $status === $t['fee_status'] && ($status === null || $t['fee_mode'] === $mode)) {
                continue;
            }
            $u->execute([$fee, $status ? $mode : null, $status, $net, $t['id']]);
            $changed++;
            $credited = abs((float) $t['balance_after'] - (float) $t['balance_before'] - (float) $t['net_amount']) < 0.005 && abs((float) $t['balance_after'] - (float) $t['balance_before']) > 0.004;
            if ($credited) {
                $d = round($net - (float) $t['net_amount'], 2);
                $deltaById[(int) $t['id']] = $d;
                $walletDelta += $d;
                $oldDeductedFee = $t['fee_status'] === 'Deducted' ? (float) $t['fee'] : 0.0;
                $newDeductedFee = $status === 'Deducted' ? $fee : 0.0;
                $adminDelta += $newDeductedFee - $oldDeductedFee;
            }
        }
        if ($deltaById) {
            // Shift the running balance of this org's ledger from the first changed row onwards.
            $all = $pdo->prepare('SELECT id, balance_before, balance_after FROM sb_transactions WHERE organization_id = ? AND id >= ? ORDER BY id');
            $all->execute([$orgId, min(array_keys($deltaById))]);
            $ub = $pdo->prepare('UPDATE sb_transactions SET balance_before = ?, balance_after = ? WHERE id = ?');
            $cum = 0.0;
            foreach ($all->fetchAll() as $r) {
                $before = (float) $r['balance_before'] + $cum;
                $cum += $deltaById[(int) $r['id']] ?? 0.0;
                $after = (float) $r['balance_after'] + $cum;
                $ub->execute([round($before, 2), round($after, 2), $r['id']]);
            }
            $pdo->prepare('UPDATE sb_organization SET account_balance = account_balance + ? WHERE id = ?')->execute([round($walletDelta, 2), $orgId]);
            if (abs($adminDelta) > 0.004) {
                adjust_admin_balance(round($adminDelta, 2));
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['changed' => $changed, 'total' => count($rows), 'wallet_delta' => round($walletDelta, 2)];
}

if ($method === 'GET') {
    $orgId = (int) ($_GET['organizationId'] ?? 0);
    if (!$orgId) {
        json_response(['status' => 'error', 'message' => 'organizationId is required'], 400);
    }
    json_response(['status' => 'success', 'data' => cg_payload($orgId)]);
}

if ($method !== 'POST') {
    json_response(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

$body = json_body();
$action = (string) ($body['action'] ?? '');

if ($action === 'approve') {
    $orgId = (int) ($body['organizationId'] ?? 0);
    try {
        $status = sb_approve_org($orgId, (int) $user['id'], !empty($body['docs_to_follow']), trim((string) ($body['notes'] ?? '')));
    } catch (RuntimeException $e) {
        json_response(['status' => 'error', 'message' => $e->getMessage()], 400);
    }
    json_response(['status' => 'success', 'message' => "Client approved ($status). Payment credentials can now be configured.", 'data' => cg_payload($orgId)]);
}

if ($action === 'revoke_approval') {
    $orgId = (int) ($body['organizationId'] ?? 0);
    db()->prepare("UPDATE sb_organization SET onboarding_status = 'Pending' WHERE id = ?")->execute([$orgId]);
    db()->prepare("UPDATE sb_client_gateways SET status = 'Disabled' WHERE organization_id = ? AND status = 'Active'")->execute([$orgId]);
    json_response(['status' => 'success', 'message' => 'Approval revoked. Active gateways were disabled.', 'data' => cg_payload($orgId)]);
}

if ($action === 'cashout_settings') {
    // V5.21: enable PayMongo Cash Out (Send Money) for this client + optional limits
    $gwId = (int) ($body['id'] ?? 0);
    $gw = sb_gateway_by_id($gwId);
    if (!$gw || $gw['provider_code'] !== 'paymongo') {
        json_response(['status' => 'error', 'message' => 'Cash Out is available for PayMongo only.'], 400);
    }
    try {
        db()->query('SELECT cashout_enabled FROM sb_client_gateways LIMIT 1');
    } catch (Throwable $e) {
        json_response(['status' => 'error', 'message' => 'Run database/migration_v5_21_paymongo_cashout.sql first.'], 503);
    }
    $lim = fn($v) => ($v === '' || $v === null) ? null : round(max(0, (float) $v), 2);
    $max = $lim($body['max_per_txn'] ?? null);
    $daily = $lim($body['daily_limit'] ?? null);
    if ($max !== null && $daily !== null && $daily > 0 && $max > $daily) {
        json_response(['status' => 'error', 'message' => 'Per-transfer limit cannot be bigger than the daily limit.'], 400);
    }
    db()->prepare('UPDATE sb_client_gateways SET cashout_enabled = ?, cashout_max_per_txn = ?, cashout_daily_limit = ?, updated_by = ? WHERE id = ?')
        ->execute([!empty($body['enabled']) ? 1 : 0, $max ?: null, $daily ?: null, $user['id'], $gwId]);
    json_response(['status' => 'success', 'message' => !empty($body['enabled']) ? 'Cash Out enabled for this client.' : 'Cash Out disabled for this client.', 'data' => cg_payload((int) $gw['organization_id'])]);
}

if ($action === 'save') {
    $orgId = (int) ($body['organizationId'] ?? 0);
    $org = sb_org_row($orgId);
    if (!$org) {
        json_response(['status' => 'error', 'message' => 'Client not found'], 404);
    }
    if (!sb_org_is_approved($org)) {
        json_response(['status' => 'error', 'message' => 'Approve this client first (documents approved, or "Approve - Documents to Follow") before setting up payment credentials.'], 409);
    }
    $code = (string) ($body['provider_code'] ?? '');
    $provider = sb_provider($code);
    if (!$provider) {
        json_response(['status' => 'error', 'message' => 'Unknown payment provider'], 400);
    }
    $env = ($body['environment'] ?? 'test') === 'live' ? 'live' : 'test';
    $status = (string) ($body['status'] ?? 'Draft');
    $status = in_array($status, ['Draft', 'Active', 'Disabled'], true) ? $status : 'Draft';
    $feeType = (string) ($body['fee_type'] ?? 'Fixed');
    $feeType = in_array($feeType, ['None', 'Fixed', 'Percentage', 'Fixed + Percentage', 'Bracket'], true) ? $feeType : 'Fixed';
    $feeFixed = round(max(0, (float) ($body['fee_fixed'] ?? 0)), 2);
    $feePercent = round(max(0, (float) ($body['fee_percent'] ?? 0)), 3);
    $feeMin = ($body['fee_min'] ?? '') === '' || $body['fee_min'] === null ? null : round(max(0, (float) $body['fee_min']), 2);
    $feeMax = ($body['fee_max'] ?? '') === '' || $body['fee_max'] === null ? null : round(max(0, (float) $body['fee_max']), 2);
    $feeMode = ($body['fee_mode'] ?? 'deduct') === 'separate' ? 'separate' : 'deduct';
    if ($feePercent > 100) {
        json_response(['status' => 'error', 'message' => 'Percentage fee cannot exceed 100%.'], 400);
    }
    if ($feeMin !== null && $feeMax !== null && $feeMax > 0 && $feeMin > $feeMax) {
        json_response(['status' => 'error', 'message' => 'Minimum fee cannot be greater than the maximum fee.'], 400);
    }
    // V5.25: amount brackets (Fixed + MDR % per bracket)
    $feeBrackets = null;
    if ($feeType === 'Bracket') {
        [$rows, $err] = sb_fee_brackets_validate($body['fee_brackets'] ?? null);
        if ($err) {
            json_response(['status' => 'error', 'message' => $err], 400);
        }
        $feeBrackets = json_encode($rows);
    }
    if (!cg_has_brackets_column() && $feeType === 'Bracket') {
        json_response(['status' => 'error', 'message' => 'Run database/migration_v5_25_fee_brackets.sql first to enable Bracket fees.'], 409);
    }
    if ($status === 'Active' && !(int) $provider['is_enabled']) {
        json_response(['status' => 'error', 'message' => $provider['display_name'] . ' is disabled globally (Payment Providers). Enable it first.'], 400);
    }

    $s = db()->prepare('SELECT * FROM sb_client_gateways WHERE organization_id = ? AND provider_code = ?');
    $s->execute([$orgId, $code]);
    $existing = $s->fetch() ?: null;

    try {
        $secrets = $existing ? sb_decrypt_array($existing['secret_config_enc']) : [];
    } catch (GatewayException $e) {
        $secrets = []; // APP_KEY changed - allow re-entering everything
    }
    $public = $existing ? (json_decode((string) $existing['public_config'], true) ?: []) : [];
    $input = is_array($body['fields'] ?? null) ? $body['fields'] : [];
    foreach (sb_provider_fields($code) as $f) {
        $v = trim((string) ($input[$f['key']] ?? ''));
        if ($f['secret']) {
            if ($v !== '') {
                $secrets[$f['key']] = $v; // blank = keep current secret
            }
            if (!empty($input['__clear_' . $f['key']])) {
                unset($secrets[$f['key']]);
            }
        } else {
            $public[$f['key']] = $v;
        }
    }
    // Validation for activation
    if ($status === 'Active') {
        foreach (sb_provider_fields($code) as $f) {
            $have = $f['secret'] ? ($secrets[$f['key']] ?? '') : ($public[$f['key']] ?? '');
            if ($f['required'] && $have === '') {
                json_response(['status' => 'error', 'message' => $f['label'] . ' is required before this gateway can be Active.'], 400);
            }
        }
    }
    if ($code === 'nationlink') {
        $nlMid = sb_nl_member_normalize((string) ($public['member_id'] ?? ''));
        $public['member_id'] = $nlMid;
        if ($nlMid !== '' && !sb_nl_member_valid($nlMid)) {
            json_response(['status' => 'error', 'message' => 'Nationlink MemberID must be 6 letters/digits (e.g. A10000), or leave it blank.'], 400);
        }
        if ($nlMid !== '' && ($own = sb_nl_member_owner($nlMid, $orgId))) {
            json_response(['status' => 'error', 'message' => "MemberID {$nlMid} is already used by {$own['organization_name']} ({$own['where']})."], 409);
        }
    }
    if ($code === 'paymongo') {
        $sk = (string) ($secrets['secret_key'] ?? '');
        $pk = (string) ($public['public_key'] ?? '');
        if ($sk !== '' && !preg_match('/^sk_(test|live)_[A-Za-z0-9]+$/', $sk)) {
            json_response(['status' => 'error', 'message' => 'PayMongo secret key must start with sk_test_ or sk_live_.'], 400);
        }
        if ($pk !== '' && !preg_match('/^pk_(test|live)_[A-Za-z0-9]+$/', $pk)) {
            json_response(['status' => 'error', 'message' => 'PayMongo public key must start with pk_test_ or pk_live_.'], 400);
        }
        if ($sk !== '') {
            $keyEnv = str_starts_with($sk, 'sk_live_') ? 'live' : 'test';
            if ($keyEnv !== $env) {
                json_response(['status' => 'error', 'message' => "The secret key is a {$keyEnv} key but Environment is set to {$env}. Make them match."], 400);
            }
        }
        if ($pk !== '' && $sk !== '' && (str_starts_with($pk, 'pk_live_') !== str_starts_with($sk, 'sk_live_'))) {
            json_response(['status' => 'error', 'message' => 'Public and secret keys must both be test keys or both be live keys.'], 400);
        }
        if (mb_strlen((string) ($public['qr_display_name'] ?? '')) > 22) {
            json_response(['status' => 'error', 'message' => 'Name shown in GCash/Maya must be 22 characters or less (e.g. PCEC).'], 400);
        }
        if (($public['qr_mobile_number'] ?? '') !== '' && !sb_pm_mobile($public['qr_mobile_number'])) {
            json_response(['status' => 'error', 'message' => 'QR Ph notification mobile must be 09XXXXXXXXX or +639XXXXXXXXX.'], 400);
        }
    }

    try {
        $secretBlob = $secrets ? sb_encrypt_array($secrets) : null;
    } catch (GatewayException $e) {
        json_response(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
    $isDefault = !empty($body['is_default']) ? 1 : 0;
    $credit = !empty($body['credit_wallet']) ? 1 : 0;

    $pdo = db();
    $pdo->beginTransaction();
    if ($isDefault) {
        $pdo->prepare('UPDATE sb_client_gateways SET is_default = 0 WHERE organization_id = ?')->execute([$orgId]);
    }
    if ($existing) {
        $pdo->prepare('UPDATE sb_client_gateways SET environment=?, public_config=?, secret_config_enc=?, status=?, is_default=?, credit_wallet=?, fee_type=?, fee_fixed=?, fee_percent=?, fee_min=?, fee_max=?, fee_mode=?, updated_by=? WHERE id=?')
            ->execute([$env, json_encode($public, JSON_UNESCAPED_SLASHES), $secretBlob, $status, $isDefault, $credit, $feeType, $feeFixed, $feePercent, $feeMin, $feeMax, $feeMode, $user['id'], $existing['id']]);
        $gwId = (int) $existing['id'];
        if (cg_has_brackets_column()) {
            $pdo->prepare('UPDATE sb_client_gateways SET fee_brackets = ? WHERE id = ?')->execute([$feeBrackets, $gwId]);
        }
    } else {
        $hasDefault = (int) $pdo->query('SELECT COUNT(*) FROM sb_client_gateways WHERE is_default = 1 AND organization_id = ' . $orgId)->fetchColumn();
        $pdo->prepare('INSERT INTO sb_client_gateways (organization_id, provider_code, environment, public_config, secret_config_enc, webhook_token, status, is_default, credit_wallet, fee_type, fee_fixed, fee_percent, fee_min, fee_max, fee_mode, created_by, updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$orgId, $code, $env, json_encode($public, JSON_UNESCAPED_SLASHES), $secretBlob, bin2hex(random_bytes(20)), $status, ($isDefault || !$hasDefault) ? 1 : 0, $credit, $feeType, $feeFixed, $feePercent, $feeMin, $feeMax, $feeMode, $user['id'], $user['id']]);
        $gwId = (int) $pdo->lastInsertId();
        if (cg_has_brackets_column()) {
            $pdo->prepare('UPDATE sb_client_gateways SET fee_brackets = ? WHERE id = ?')->execute([$feeBrackets, $gwId]);
        }
    }
    $pdo->commit();

    $note = '';
    if ($provider['integration_status'] !== 'Live') {
        $note = $code === 'nationlink'
            ? ' You can now attach the Nationlink-issued Static QR Ph in the "Nationlink QR Ph" card.'
            : ' Note: ' . $provider['display_name'] . ' credentials are stored, but QR generation for this provider is not live yet - clients will use PayMongo in the meantime.';
    }
    if (!empty($body['apply_existing'])) {
        $r = cg_recalc_fees(sb_gateway_by_id($gwId));
        $note .= " Fee applied to existing transactions: {$r['changed']} of {$r['total']} updated.";
    }
    json_response(['status' => 'success', 'message' => 'Credentials saved.' . $note, 'data' => cg_payload($orgId), 'gateway_id' => $gwId]);
}

$gwId = (int) ($body['id'] ?? 0);
$gw = $gwId ? sb_gateway_by_id($gwId) : null;
if (!$gw) {
    json_response(['status' => 'error', 'message' => 'Gateway not found'], 404);
}

if ($action === 'delete') {
    $used = db()->prepare('SELECT COUNT(*) FROM sb_transactions WHERE gateway_id = ?');
    $used->execute([$gwId]);
    if ((int) $used->fetchColumn() > 0) {
        db()->prepare("UPDATE sb_client_gateways SET status = 'Disabled' WHERE id = ?")->execute([$gwId]);
        json_response(['status' => 'success', 'message' => 'This gateway already has transactions, so it was Disabled instead of deleted.', 'data' => cg_payload((int) $gw['organization_id'])]);
    }
    db()->prepare('DELETE FROM sb_client_gateways WHERE id = ?')->execute([$gwId]);
    json_response(['status' => 'success', 'message' => 'Gateway removed.', 'data' => cg_payload((int) $gw['organization_id'])]);
}

if ($action === 'set_deduct') {
    // One click: switch this gateway to "Deduct from settlement" and re-apply to all past payments.
    $feeType = $gw['fee_type'] === 'None' ? 'Fixed' : $gw['fee_type'];
    db()->prepare("UPDATE sb_client_gateways SET fee_mode = 'deduct', fee_type = ?, fee_fixed = CASE WHEN ? = 'Fixed' AND fee_fixed <= 0 THEN 10 ELSE fee_fixed END, updated_by = ? WHERE id = ?")
        ->execute([$feeType, $feeType, $user['id'], $gwId]);
    $gw = sb_gateway_by_id($gwId);
    $r = cg_recalc_fees($gw);
    json_response(['status' => 'success', 'message' => 'Fee is now DEDUCTED from the client (' . sb_fee_label($gw) . "). {$r['changed']} of {$r['total']} existing transaction(s) updated" . ($r['wallet_delta'] ? ', client balance adjusted by ' . number_format($r['wallet_delta'], 2) : '') . '.', 'data' => cg_payload((int) $gw['organization_id'])]);
}

if ($action === 'recalc_fees') {
    $r = cg_recalc_fees($gw);
    json_response(['status' => 'success', 'message' => 'Fees recalculated with the current setting (' . sb_fee_label($gw) . "): {$r['changed']} of {$r['total']} transaction(s) updated" . ($r['wallet_delta'] ? ', wallet balance adjusted by ' . number_format($r['wallet_delta'], 2) : '') . '.', 'data' => cg_payload((int) $gw['organization_id'])]);
}

if ($action === 'test') {
    if ($gw['provider_code'] !== 'paymongo') {
        json_response(['status' => 'error', 'message' => 'Connection test is available for PayMongo only right now.'], 400);
    }
    try {
        $res = sb_pm_request($gw, 'GET', '/webhooks');
        $hooks = $res['data'] ?? [];
        $mine = array_values(array_filter($hooks, fn($w) => ($w['attributes']['url'] ?? '') === sb_gateway_webhook_url($gw)));
        $result = 'OK - PayMongo keys valid (' . $gw['environment'] . '). ' . count($hooks) . ' webhook(s) on account' . ($mine ? ', SurgeBox webhook registered (' . ($mine[0]['attributes']['status'] ?? '') . ').' : ', SurgeBox webhook NOT registered yet.');
        $ok = true;
    } catch (Throwable $e) {
        $result = 'FAILED - ' . $e->getMessage();
        $ok = false;
    }
    db()->prepare('UPDATE sb_client_gateways SET last_tested_at = NOW(), last_test_result = ? WHERE id = ?')->execute([mb_substr($result, 0, 255), $gwId]);
    json_response(['status' => $ok ? 'success' : 'error', 'message' => $result, 'data' => cg_payload((int) $gw['organization_id'])], $ok ? 200 : 502);
}

if ($action === 'register_webhook') {
    if ($gw['provider_code'] !== 'paymongo') {
        json_response(['status' => 'error', 'message' => 'Automatic webhook registration is available for PayMongo only. Copy the webhook URL into the provider dashboard.'], 400);
    }
    try {
        $r = sb_pm_register_webhook($gw);
    } catch (Throwable $e) {
        json_response(['status' => 'error', 'message' => 'Webhook registration failed: ' . $e->getMessage()], 502);
    }
    $msg = 'Webhook registered on PayMongo (' . $r['id'] . ') for ' . implode(', ', $r['events']) . '.' . ($r['secret_saved'] ? ' Signing secret saved automatically.' : ' PayMongo did not return the signing secret - paste it manually from the PayMongo dashboard.');
    json_response(['status' => 'success', 'message' => $msg, 'data' => cg_payload((int) $gw['organization_id'])]);
}

json_response(['status' => 'error', 'message' => 'Unknown action'], 400);
