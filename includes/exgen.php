<?php
declare(strict_types=1);

/*
 * EXGEN Bonus (Balik Puhunan).
 *  - Each package has an EXGEN value per direct (Silver 250, Gold 1,000, Platinum 1,750, Diamond 3,000).
 *  - Rule "same" (default): only paid directs on the SAME package as the member count (a Silver counts
 *    Silver directs only, a Diamond counts Diamond directs only), each at the package's EXGEN value.
 *    Rule "capped": every paid direct counts the member's value, capped at the direct's package value.
 *  - Target = own value x minimum directs (8): Silver 2,000, Gold 8,000, Platinum 14,000, Diamond 24,000.
 *  - Mode "complete": paid once the member has at least the minimum directs AND the counted value reaches
 *    the target. Mode "per_direct": credited as each direct comes in, up to the target.
 *  - After an upgrade the target rises; only the difference to what was already paid is credited.
 *  - Time limit (exgen_window_months, default 2): only directs who joined inside the current window count.
 *    If the target is not reached in time the progress resets and a new window starts. The first window
 *    starts at activation (or at the latest package upgrade).
 */

/** Current EXGEN window for a member: [start, end|null] as 'Y-m-d H:i:s' (DB clock, so simulations work). */
function exgen_window(array $m): array
{
    $anchor = (string)(q_val('SELECT MAX(created_at) FROM package_upgrades WHERE user_id = ?', [(int)$m['id']]) ?: $m['created_at']);
    $months = max(0, (int)setting('exgen_window_months', 2));
    if ($months === 0) {
        return [$anchor, null];
    }
    $now = new DateTime((string)q_val('SELECT NOW()'));
    $start = new DateTime($anchor);
    $end = (clone $start)->modify("+{$months} months");
    $guard = 0;
    while ($now >= $end && $guard++ < 1000) {
        $start = $end;
        $end = (clone $start)->modify("+{$months} months");
    }
    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

function exgen_progress(int $userId): array
{
    $m = q_row('SELECT u.id, u.username, u.status, u.package_id, u.created_at, p.name package_name, COALESCE(p.exgen_bonus, 0) rate
                FROM users u LEFT JOIN packages p ON p.id = u.package_id WHERE u.id = ?', [$userId]);
    $min = max(1, (int)setting('exgen_min_directs', 8));
    $rate = (float)($m['rate'] ?? 0);
    [$from, $to] = $m ? exgen_window($m) : [null, null];
    $directs = q_all("SELECT d.id, d.username, d.created_at, d.package_id, p.name package_name, COALESCE(p.exgen_bonus, 0) value
                      FROM users d JOIN packages p ON p.id = d.package_id
                      WHERE d.sponsor_id = ? AND d.role = 'member' AND d.created_at >= ?" . ($to ? ' AND d.created_at < ?' : '') . "
                        AND EXISTS (SELECT 1 FROM codes c WHERE c.used_by = d.id AND c.code_type = 'paid')
                      ORDER BY d.id", array_merge([$userId, (string)$from], $to ? [$to] : []));
    $same = setting('exgen_count_rule', 'same') !== 'capped';
    $total = 0.0;
    $count = 0;
    foreach ($directs as &$d) {
        $d['counted'] = $same
            ? ((int)$d['package_id'] === (int)($m['package_id'] ?? 0) ? $rate : 0.0)
            : min($rate, (float)$d['value']);
        $total += $d['counted'];
        $count += $d['counted'] > 0 ? 1 : 0;
    }
    unset($d);
    $target = round($rate * $min, 2);
    $paid = (float)q_val("SELECT COALESCE(SUM(amount), 0) FROM earnings WHERE user_id = ? AND type = 'exgen'", [$userId]);
    $paidWindow = (float)q_val("SELECT COALESCE(SUM(amount), 0) FROM earnings WHERE user_id = ? AND type = 'exgen' AND created_at >= ?", [$userId, (string)$from]);
    $qualified = $rate > 0 && $count >= $min && $total >= $target;
    return [
        'package' => $m['package_name'] ?? null, 'rate' => $rate, 'target' => $target, 'min' => $min,
        'directs' => $directs, 'count' => $count, 'total' => round($total, 2), 'progress' => round(min($total, $target), 2),
        'paid' => $paid, 'paid_window' => $paidWindow, 'qualified' => $qualified, 'done' => $target > 0 && $paid >= $target,
        'window_start' => $from, 'window_end' => $to,
        'days_left' => $to ? max(0, (int)ceil((strtotime($to) - strtotime((string)q_val('SELECT NOW()'))) / 86400)) : null, 'percent' => $target > 0 ? min(100, round($total / $target * 100, 1)) : 0,
    ];
}

/** Credit any EXGEN Bonus now due to $userId. Returns the amount credited. */
function exgen_check(int $userId, ?int $sourceId = null): float
{
    if (!plan_on('exgen') || !$userId) {
        return 0.0;
    }
    return tx(function () use ($userId, $sourceId) {
        $u = q_row("SELECT id, status FROM users WHERE id = ? AND role = 'member' FOR UPDATE", [$userId]);
        if (!$u || $u['status'] !== 'active') {
            return 0.0;
        }
        $pg = exgen_progress($userId);
        if ($pg['target'] <= 0) {
            return 0.0;
        }
        if (setting('exgen_mode', 'complete') === 'per_direct') {
            // credited as directs come in during this window, never above the target overall
            $due = min(min($pg['total'], $pg['target']) - $pg['paid_window'], $pg['target'] - $pg['paid']);
        } else {
            $due = $pg['qualified'] ? $pg['target'] - $pg['paid'] : 0.0;
        }
        $due = round($due, 2);
        if ($due <= 0) {
            return 0.0;
        }
        $desc = $pg['qualified']
            ? "EXGEN Bonus (balik puhunan): {$pg['package']} " . money($pg['target']) . " – {$pg['count']} direct(s)"
            : "EXGEN Bonus: {$pg['package']} " . money($pg['progress']) . ' of ' . money($pg['target']);
        credit_earning($userId, 'exgen', $due, $sourceId, $desc);
        return $due;
    });
}

/** Progress card body (member dashboard and admin member view). */
function exgen_widget(array $pg, bool $admin = false): string
{
    $done = $pg['done'];
    $pct = $done ? 100 : $pg['percent'];
    $h = '<div class="exgen">';
    $h .= '<div class="exgen-head"><div><span class="badge badge-gold">' . e($pg['package']) . '</span> <strong class="exgen-amt">'
        . money($done ? $pg['target'] : $pg['progress']) . '</strong> <span class="text-muted">of ' . money($pg['target']) . '</span></div>'
        . '<span class="exgen-pct">' . num($pct, 0) . '%</span></div>';
    $h .= '<div class="progress exgen-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int)$pct . '"><span style="width:' . $pct . '%"></span></div>';
    if ($done) {
        $h .= '<p class="exgen-note text-green"><strong>Completed</strong> – ' . money($pg['paid']) . ' credited to ' . ($admin ? 'the' : 'your') . ' E-Wallet.</p>';
    } else {
        $h .= '<p class="exgen-note">' . num($pg['count']) . ' of ' . num($pg['min']) . ' ' . e($pg['package']) . ' directs · ' . money($pg['rate']) . ' each';
        if ($pg['window_end']) {
            $h .= ' · until <strong>' . e(fdate($pg['window_end'])) . '</strong> (' . num($pg['days_left']) . ' day(s) left)';
        }
        $h .= '</p>';
        if ($pg['paid'] > 0) {
            $h .= '<p class="exgen-note text-muted">Already received: ' . money($pg['paid']) . '. Only the difference is paid after an upgrade.</p>';
        }
        if ($pg['window_end']) {
            $h .= '<p class="exgen-note text-muted small">' . (setting('exgen_count_rule', 'same') !== 'capped'
                ? 'Only ' . e($pg['package']) . ' directs count. Get ' . num($pg['min']) . ' before the deadline, or the progress resets.'
                : 'Reach ' . money($pg['target']) . ' with at least ' . num($pg['min']) . ' directs before the deadline, or the progress resets.') . '</p>';
        }
    }
    if ($pg['directs']) {
        $h .= '<details class="exgen-list"><summary>Directs in this period (' . count($pg['directs']) . ')</summary><ul>';
        foreach ($pg['directs'] as $d) {
            $h .= '<li><span>@' . e($d['username']) . ' · ' . e($d['package_name']) . '</span>'
                . ($d['counted'] > 0 ? '<b>' . money($d['counted']) . '</b>' : '<span class="text-muted">not counted (other package)</span>') . '</li>';
        }
        $h .= '</ul></details>';
    }
    return $h . '</div>';
}
