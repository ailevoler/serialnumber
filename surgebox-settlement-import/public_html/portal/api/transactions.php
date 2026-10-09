<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_login(true);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

[$page, $size, $offset] = paginate_params(10);

$where = [];
$params = [];

if ($user['user_type'] === 'Manager' && $user['organization_id']) {
    $where[] = 'organization_id = ?';
    $params[] = $user['organization_id'];
    if (function_exists('portal_branch_id') && ($pb = portal_branch_id())) {
        // V5.20: portal user assigned to one branch
        $where[] = 'branch_id = ?';
        $params[] = $pb;
    }
} elseif ($user['user_type'] === 'Viewer' && $user['branch_id']) {
    $where[] = 'branch_id = ?';
    $params[] = $user['branch_id'];
} elseif ($user['user_type'] === 'Admin' && !empty($_GET['organizationId'])) {
    // Admin viewing a specific organization's dashboard
    $where[] = 'organization_id = ?';
    $params[] = (int) $_GET['organizationId'];
}
// Admin with no organizationId filter: sees everything

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Pull the full scoped set (ascending) to compute running admin-fee balances,
// exactly like the original aggregation pipeline did.
$stmt = db()->prepare("SELECT * FROM sb_transactions $whereSql ORDER BY created_at ASC");
$stmt->execute($params);
$all = $stmt->fetchAll();

$cumulative = 0.0;
foreach ($all as &$t) {
    $totalFee = 0.0;
    if ($t['status'] === 'Completed') {
        if (!empty($t['provider'])) {
            // V5 client-gateway collection: the SurgeBox fee as configured per client
            $totalFee = (float) $t['fee'];
        } elseif ($t['type'] === 'Cash Out') {
            $totalFee = (float) $t['fee'] - 10;
        } elseif ($t['type'] === 'Cash In') {
            $totalFee = (float) $t['fee'] - ((float) $t['amount'] * 0.015);
        } elseif ($t['type'] === 'Cheque') {
            $totalFee = (float) $t['fee'];
        }
    }
    $t['admin_balance_before'] = $cumulative;
    $cumulative += $totalFee;
    $t['admin_balance_after'] = $cumulative;

    // Displayed fee mirrors the original $project pipeline (subtract the
    // platform's own cut so the branch/org only sees their portion)
    if (!empty($t['provider'])) {
        $t['display_fee'] = (float) $t['fee'];
    } elseif ($t['type'] === 'Cash In') {
        $t['display_fee'] = (float) $t['fee'] - ((float) $t['amount'] * 0.015);
    } elseif ($t['type'] === 'Cash Out') {
        $t['display_fee'] = (float) $t['fee'] - 10;
    } else {
        $t['display_fee'] = (float) $t['fee'];
    }
}
unset($t);

// V5.28: collections of gateways that do NOT credit the SurgeBox wallet (e.g. Nationlink, settled by
// the provider directly to the client) never move account_balance, so their stored balance_before/after
// are flat. Show their own running total instead (per client + provider, in transaction-date order),
// e.g. Nationlink: 0.00 -> 19.70 -> 118.20 ... The wallet balance itself is not changed.
$creditMap = [];
foreach (db()->query('SELECT id, credit_wallet FROM sb_client_gateways')->fetchAll() as $g) {
    $creditMap[(int) $g['id']] = (int) $g['credit_wallet'] === 1;
}
$direct = [];
foreach ($all as $i => $t) {
    if (!empty($t['gateway_id']) && isset($creditMap[(int) $t['gateway_id']]) && !$creditMap[(int) $t['gateway_id']]) {
        $direct[] = $i;
    }
}
usort($direct, fn($a, $b) => [$all[$a]['transaction_date'], (int) $all[$a]['id']] <=> [$all[$b]['transaction_date'], (int) $all[$b]['id']]);
$running = [];
foreach ($direct as $i) {
    $key = $all[$i]['organization_id'] . '|' . $all[$i]['provider'];
    $before = $running[$key] ?? 0.0;
    $after = $all[$i]['status'] === 'Completed' && $all[$i]['type'] === 'Cash In' ? round($before + (float) $all[$i]['net_amount'], 2) : $before;
    $running[$key] = $after;
    $all[$i]['balance_before'] = number_format($before, 2, '.', '');
    $all[$i]['balance_after'] = number_format($after, 2, '.', '');
    $all[$i]['balance_kind'] = 'collections';
}

// Sort descending (most recent first) then paginate
// V5.28: newest payment first by transaction date (imported settlement lines keep their real payment time)
usort($all, fn($a, $b) => [$b['transaction_date'], (int) $b['id']] <=> [$a['transaction_date'], (int) $a['id']]);
$total = count($all);
$paged = array_slice($all, $offset, $size);

// Attach organization / branch names + sender name from payload
$orgCache = [];
$branchCache = [];
foreach ($paged as &$t) {
    $orgName = null;
    $branchName = null;
    if ($t['organization_id']) {
        if (!isset($orgCache[$t['organization_id']])) {
            $s = db()->prepare('SELECT organization_name FROM sb_organization WHERE id = ?');
            $s->execute([$t['organization_id']]);
            $r = $s->fetch();
            $orgCache[$t['organization_id']] = $r['organization_name'] ?? null;
        }
        $orgName = $orgCache[$t['organization_id']];
    }
    if ($t['branch_id']) {
        if (!isset($branchCache[$t['branch_id']])) {
            $s = db()->prepare('SELECT branch_name FROM sb_branches WHERE id = ?');
            $s->execute([$t['branch_id']]);
            $r = $s->fetch();
            $branchCache[$t['branch_id']] = $r['branch_name'] ?? null;
        }
        $branchName = $branchCache[$t['branch_id']];
    }

    $payload = json_decode((string) $t['payload'], true) ?: [];
    $senderName = null;
    $senderAcct = null;
    if ($t['type'] === 'Cash In') {
        // V5.17: PayMongo / Nationlink / Pay8 - visible to Admin and the client.
        $senderName = sb_find_sender_name($payload);
        $senderAcct = sb_find_sender_account($payload);
        if ($senderName === null && ($bank = sb_find_sender_bank($payload))) {
            // V5.25: PayMongo QR Ph sends the payer's bank / e-wallet, not the name
            $senderName = 'via ' . $bank['name'];
            $senderAcct = $bank['ref'] !== '' ? 'Ref …' . substr($bank['ref'], -8) : $senderAcct;
        }
    } elseif ($t['type'] === 'Cash Out' && !empty($payload['cashout_id'])) {
        // V5.21: PayMongo Cash Out - show the recipient
        $senderName = 'To: ' . (string) ($payload['payer_name'] ?? '');
        $senderAcct = trim((string) (($payload['destination']['institution'] ?? '') . ' ' . ($payload['destination']['account'] ?? '')));
    }

    $t['provider_code'] = sb_tx_provider_code($t, is_array($payload) ? $payload : []);
    $t['provider_name'] = sb_provider_display($t['provider_code']);
    $t['organization_name'] = $orgName;
    $t['branch_name'] = $branchName;
    $t['sender_name'] = $senderName;
    $t['sender_account'] = $senderAcct;
    $t['reference_no_short'] = $t['reference_no'];
    if (!sb_fees_visible($user)) {
        // Fees are Admin-only: collections are shown to the client at their net amount.
        unset($t['provider'], $t['gateway_id'], $t['admin_balance_before'], $t['admin_balance_after'], $t['provider_fee'], $t['provider_net'], $t['fee_mode'], $t['fee_status'], $t['fee_billed_at']);
        if ($t['type'] === 'Cash In') {
            $t['amount'] = $t['net_amount'];
            $t['fee'] = 0;
            $t['display_fee'] = 0;
        }
    }
    $t['qr_trace_short'] = $t['qr_ph_trace_no'] ? substr($t['qr_ph_trace_no'], -5) : null;
    unset($t['payload']); // don't ship the raw payload to the browser
}
unset($t);

json_response([
    'status' => 'success',
    'results' => count($paged),
    'totalPages' => max(1, (int) ceil($total / $size)),
    'data' => $paged,
]);
