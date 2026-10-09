<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

/**
 * Admin > Client Fees (MDR) billing.
 * GET  ?from&to                                   -> per-client fee summary
 * POST {organizationId, from, to, status}          -> mark fees Billed / Paid / Waived
 *      (Unbilled -> Billed/Waived, Billed -> Paid)
 */
$user = require_role(['Admin'], true);
$method = $_SERVER['REQUEST_METHOD'];
$src = $method === 'POST' ? json_body() : $_GET;
$from = (string) ($src['from'] ?? date('Y-m-01'));
$to = (string) ($src['to'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    json_response(['status' => 'error', 'message' => 'Dates must be YYYY-MM-DD'], 400);
}

if ($method === 'GET' && isset($_GET['setup'])) {
    // V5.26: every client's own fee setup (one row per client x provider) - each client can differ.
    $rows = db()->query("SELECT g.*, o.organization_name, o.client_code, p.display_name provider_name
        FROM sb_client_gateways g JOIN sb_organization o ON o.id = g.organization_id
        JOIN sb_payment_providers p ON p.code = g.provider_code
        ORDER BY o.organization_name, p.is_priority DESC, p.sort_order")->fetchAll();
    $out = array_map(fn($g) => [
        'gateway_id' => (int) $g['id'],
        'organization_id' => (int) $g['organization_id'],
        'organization_name' => $g['organization_name'],
        'client_code' => $g['client_code'],
        'provider_code' => $g['provider_code'],
        'provider_name' => $g['provider_name'],
        'status' => $g['status'],
        'environment' => $g['environment'],
        'fee_type' => $g['fee_type'],
        'fee_mode' => $g['fee_mode'],
        'fee_label' => sb_fee_label($g),
        'examples' => array_map(fn($a) => ['amount' => $a, 'fee' => sb_compute_client_fee($g, (float) $a)], [100, 1000, 10000]),
    ], $rows);
    json_response(['status' => 'success', 'data' => $out]);
}

if ($method === 'GET') {
    $s = db()->prepare("SELECT o.id organization_id, o.organization_name, o.client_code,
            COUNT(t.id) tx_count,
            COALESCE(SUM(t.amount),0) gross,
            COALESCE(SUM(CASE WHEN t.fee_status='Deducted' THEN t.fee END),0) fee_deducted,
            COALESCE(SUM(CASE WHEN t.fee_status='Unbilled' THEN t.fee END),0) fee_unbilled,
            COALESCE(SUM(CASE WHEN t.fee_status='Billed' THEN t.fee END),0) fee_billed,
            COALESCE(SUM(CASE WHEN t.fee_status='Paid' THEN t.fee END),0) fee_paid,
            COALESCE(SUM(CASE WHEN t.fee_status='Waived' THEN t.fee END),0) fee_waived
        FROM sb_transactions t JOIN sb_organization o ON o.id = t.organization_id
        WHERE t.provider IS NOT NULL AND t.status = 'Completed' AND DATE(t.transaction_date) BETWEEN ? AND ?
        GROUP BY o.id ORDER BY fee_unbilled DESC, o.organization_name");
    $s->execute([$from, $to]);
    json_response(['status' => 'success', 'data' => ['from' => $from, 'to' => $to, 'clients' => $s->fetchAll()]]);
}

if ($method === 'POST') {
    $orgId = (int) ($src['organizationId'] ?? 0);
    $status = (string) ($src['status'] ?? '');
    $fromStatus = match ($status) {
        'Billed', 'Waived' => ['Unbilled'],
        'Paid' => ['Billed', 'Unbilled'],
        default => null,
    };
    if (!$orgId || !$fromStatus) {
        json_response(['status' => 'error', 'message' => 'organizationId and a valid status (Billed, Paid, Waived) are required'], 400);
    }
    $in = implode(',', array_fill(0, count($fromStatus), '?'));
    $u = db()->prepare("UPDATE sb_transactions SET fee_status = ?, fee_billed_at = COALESCE(fee_billed_at, NOW()) WHERE organization_id = ? AND provider IS NOT NULL AND fee_status IN ($in) AND DATE(transaction_date) BETWEEN ? AND ?");
    $u->execute(array_merge([$status, $orgId], $fromStatus, [$from, $to]));
    json_response(['status' => 'success', 'message' => $u->rowCount() . " fee line(s) marked {$status}."]);
}

json_response(['status' => 'error', 'message' => 'Method not allowed'], 405);
