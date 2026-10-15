<?php
/**
 * Nexmerce — Trader dashboard widgets (CryptoPilot dashboard layout).
 *
 * Every number shown here comes from the trader's real records
 * (cash_wallets, cash_lots, cash_lot_earnings, cash_requests) or the
 * platform-generated market (platform_market_history). Charts are rendered
 * server-side as inline SVG so they work without any chart library.
 * Each loader fails soft (returns empty data) so a missing optional table
 * never breaks the dashboard.
 */

require_once __DIR__ . '/cash.php';

/** Plain-text USD (no data-usd-cents, so the currency script does not inject estimates). */
function nxd_usd(int $minor, bool $sign = false, int $decimals = 2): string
{
    $s = '$' . number_format(abs($minor) / 100, $decimals);
    if ($minor < 0) return '−' . $s;
    return ($sign && $minor > 0 ? '+' : '') . $s;
}

function nxd_compact_usd(float $dollars): string
{
    $a = abs($dollars);
    if ($a >= 1000000) return '$' . rtrim(rtrim(number_format($dollars / 1000000, 1), '0'), '.') . 'M';
    if ($a >= 1000) return '$' . rtrim(rtrim(number_format($dollars / 1000, 1), '0'), '.') . 'k';
    if ($a >= 100) return '$' . number_format($dollars, 0);
    return '$' . number_format($dollars, 2);
}

function nxd_pct(float $p, int $dec = 2): string
{
    return ($p > 0 ? '+' : ($p < 0 ? '−' : '')) . number_format(abs($p), $dec) . '%';
}

function nxd_try(callable $fn, $fallback)
{
    try { return $fn(); } catch (Throwable $e) { error_log('[dashboard] ' . $e->getMessage()); return $fallback; }
}

// ---------------------------------------------------------------- data

/** Trader's USD Lots with filled spaces and accrued earnings. */
function nxd_lots(int $uid): array
{
    return nxd_try(function () use ($uid) {
        $rows = cash_query(
            'SELECT l.*, (SELECT COUNT(*) FROM cash_lot_slots s WHERE s.lot_id=l.id) AS filled,
                    (SELECT COALESCE(SUM(e.amount_minor),0) FROM cash_lot_earnings e WHERE e.lot_id=l.id) AS earned_minor
               FROM cash_lots l WHERE l.user_id=? ORDER BY l.id DESC',
            [$uid]
        )->fetchAll();
        $now = time();
        foreach ($rows as &$r) {
            $start = strtotime((string)$r['earnings_started_at']) ?: $now;
            $end = strtotime((string)$r['earnings_ends_at']) ?: $now;
            $r['progress'] = $end > $start ? max(0, min(1, ($now - $start) / ($end - $start))) : 1;
            $r['completed'] = $now >= $end;
            $r['transferred'] = !empty($r['earnings_transferred_at']);
        }
        unset($r);
        return $rows;
    }, []);
}

/** Daily earnings for the last $days days (oldest first): [['Y-m-d', minor], ...]. */
function nxd_daily_earnings(int $uid, int $days): array
{
    $map = nxd_try(function () use ($uid, $days) {
        $out = [];
        foreach (cash_query(
            'SELECT DATE(earned_at) d, SUM(amount_minor) m FROM cash_lot_earnings
              WHERE user_id=? AND earned_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY DATE(earned_at)',
            [$uid, $days - 1]
        ) as $r) $out[$r['d']] = (int)$r['m'];
        return $out;
    }, []);
    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $series[] = [$d, (int)($map[$d] ?? 0)];
    }
    return $series;
}

/** Earnings accrued before a given date (for cumulative charts). */
function nxd_earnings_before(int $uid, string $day): int
{
    return (int)nxd_try(fn() => cash_query('SELECT COALESCE(SUM(amount_minor),0) FROM cash_lot_earnings WHERE user_id=? AND earned_at < ?', [$uid, $day . ' 00:00:00'])->fetchColumn(), 0);
}

/** Recent wallet activity (deposits, withdrawals, Lot purchases, transfers). */
function nxd_activity(int $uid, int $limit = 6): array
{
    return nxd_try(fn() => cash_query(
        "SELECT id,kind,amount,status,reference,created_at FROM cash_requests WHERE user_id=? AND kind NOT IN ('lot_earning','lot_earnings') ORDER BY id DESC LIMIT " . (int)$limit,
        [$uid]
    )->fetchAll(), []);
}

/** Platform market: latest price and change vs. ~24h earlier, plus a short history for sparklines. */
function nxd_market(int $points = 24): array
{
    return nxd_try(function () use ($points) {
        $latest = (int)cash_query('SELECT MAX(observed_at) FROM platform_market_history')->fetchColumn();
        if (!$latest) return [];
        $prev = (int)cash_query('SELECT MAX(observed_at) FROM platform_market_history WHERE observed_at <= ?', [$latest - 86400])->fetchColumn();
        if (!$prev) $prev = (int)cash_query('SELECT MIN(observed_at) FROM platform_market_history')->fetchColumn();
        $rows = cash_query(
            'SELECT p.id,p.name,p.symbol,p.category,h1.price AS price,h0.price AS prev
               FROM platform_market_history h1
               JOIN sim_products p ON p.id=h1.product_id AND p.active=1
               LEFT JOIN platform_market_history h0 ON h0.product_id=h1.product_id AND h0.observed_at=?
              WHERE h1.observed_at=?',
            [$prev, $latest]
        )->fetchAll();
        $stamps = array_map('intval', cash_query('SELECT DISTINCT observed_at FROM platform_market_history ORDER BY observed_at DESC LIMIT ' . (int)$points)->fetchAll(PDO::FETCH_COLUMN));
        $hist = [];
        if ($stamps) {
            $in = implode(',', $stamps);
            foreach (cash_query("SELECT product_id,observed_at,price FROM platform_market_history WHERE observed_at IN ($in) ORDER BY observed_at") as $h) {
                $hist[(int)$h['product_id']][] = (float)$h['price'];
            }
        }
        foreach ($rows as &$r) {
            $r['price'] = (float)$r['price'];
            $r['prev'] = (float)($r['prev'] ?: $r['price']);
            $r['change'] = $r['prev'] > 0 ? ($r['price'] / $r['prev'] - 1) * 100 : 0;
            $r['history'] = $hist[(int)$r['id']] ?? [];
        }
        unset($r);
        return $rows;
    }, []);
}

// ------------------------------------------------------------- renderers

/** Area chart. $series = [[label, value], ...]; values in dollars. */
function nxd_area_chart(array $series, string $id, string $label = 'Value'): string
{
    $W = 760; $H = 300; $L = 52; $R = 12; $T = 14; $B = 28;
    if (!$series) return '<p class="empty">No data yet.</p>';
    $vals = array_map(fn($p) => (float)$p[1], $series);
    $min = min($vals); $max = max($vals);
    if ($max - $min < 0.01) { $max += 1; $min = max(0, $min - 1); }
    $pad = ($max - $min) * 0.12; $max += $pad; $min = max(0, $min - $pad);
    $n = count($series);
    $x = fn($i) => $L + ($n > 1 ? $i * ($W - $L - $R) / ($n - 1) : ($W - $L - $R) / 2);
    $y = fn($v) => $T + ($H - $T - $B) * (1 - ($v - $min) / ($max - $min));
    $pts = [];
    foreach ($vals as $i => $v) $pts[] = [$x($i), $y($v)];
    // smooth path (Catmull-Rom → Bézier)
    $d = sprintf('M%.1f %.1f', $pts[0][0], $pts[0][1]);
    for ($i = 0; $i < $n - 1; $i++) {
        $p0 = $pts[max(0, $i - 1)]; $p1 = $pts[$i]; $p2 = $pts[$i + 1]; $p3 = $pts[min($n - 1, $i + 2)];
        $d .= sprintf(' C%.1f %.1f %.1f %.1f %.1f %.1f',
            $p1[0] + ($p2[0] - $p0[0]) / 6, $p1[1] + ($p2[1] - $p0[1]) / 6,
            $p2[0] - ($p3[0] - $p1[0]) / 6, $p2[1] - ($p3[1] - $p1[1]) / 6, $p2[0], $p2[1]);
    }
    $area = $d . sprintf(' L%.1f %.1f L%.1f %.1f Z', $pts[$n - 1][0], $H - $B, $pts[0][0], $H - $B);
    $svg = '<svg class="nxd-chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($label) . ' chart"><defs><linearGradient id="g-' . e($id) . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="var(--nxt-primary)" stop-opacity=".22"/><stop offset="1" stop-color="var(--nxt-primary)" stop-opacity="0"/></linearGradient></defs>';
    for ($g = 0; $g <= 4; $g++) {
        $v = $min + ($max - $min) * $g / 4; $gy = $y($v);
        $svg .= sprintf('<line x1="%d" x2="%d" y1="%.1f" y2="%.1f" class="nxd-grid"/><text x="%d" y="%.1f" class="nxd-axis" text-anchor="end">%s</text>', $L, $W - $R, $gy, $gy, $L - 8, $gy + 4, e(nxd_compact_usd($v)));
    }
    $step = max(1, (int)ceil($n / 7));
    foreach ($series as $i => $p) {
        if ($i % $step !== 0 && $i !== $n - 1) continue;
        $svg .= sprintf('<text x="%.1f" y="%d" class="nxd-axis" text-anchor="middle">%s</text>', $x($i), $H - 8, e($p[0]));
    }
    $svg .= '<path d="' . $area . '" fill="url(#g-' . e($id) . ')"/><path d="' . $d . '" class="nxd-line"/>';
    $last = $pts[$n - 1];
    $svg .= sprintf('<circle cx="%.1f" cy="%.1f" r="4.5" class="nxd-dot"/>', $last[0], $last[1]);
    foreach ($series as $i => $p) {
        $svg .= sprintf('<circle cx="%.1f" cy="%.1f" r="9" class="nxd-hit"><title>%s · %s</title></circle>', $pts[$i][0], $pts[$i][1], e($p[0]), e(nxd_compact_usd((float)$p[1])));
    }
    return $svg . '</svg>';
}

/** Vertical bar chart (supports negatives). $series = [[label, dollars], ...]. */
function nxd_bar_chart(array $series): string
{
    $W = 340; $H = 190; $L = 40; $R = 6; $T = 10; $B = 24;
    $vals = array_map(fn($p) => (float)$p[1], $series);
    $max = max(1, max($vals ?: [0])); $min = min(0, min($vals ?: [0]));
    $span = $max - $min;
    $y = fn($v) => $T + ($H - $T - $B) * (1 - ($v - $min) / $span);
    $n = max(1, count($series)); $slot = ($W - $L - $R) / $n; $bw = min(22, $slot * 0.55);
    $svg = '<svg class="nxd-chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Daily earnings chart">';
    for ($g = 0; $g <= 3; $g++) {
        $v = $min + $span * $g / 3; $gy = $y($v);
        $svg .= sprintf('<line x1="%d" x2="%d" y1="%.1f" y2="%.1f" class="nxd-grid"/><text x="%d" y="%.1f" class="nxd-axis" text-anchor="end">%s</text>', $L, $W - $R, $gy, $gy, $L - 6, $gy + 4, e(nxd_compact_usd($v)));
    }
    foreach ($series as $i => [$lab, $v]) {
        $cx = $L + $slot * ($i + 0.5);
        $y0 = $y(0); $y1 = $y((float)$v);
        $top = min($y0, $y1); $h = max(1.5, abs($y1 - $y0));
        $svg .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="3" class="%s"><title>%s · %s</title></rect>', $cx - $bw / 2, $top, $bw, $h, $v < 0 ? 'nxd-bar-neg' : 'nxd-bar', e($lab), e(nxd_compact_usd((float)$v)));
        $svg .= sprintf('<text x="%.1f" y="%d" class="nxd-axis" text-anchor="middle">%s</text>', $cx, $H - 7, e($lab));
    }
    return $svg . '</svg>';
}

/** Donut chart. $segments = [[label, value, cssColor], ...]. */
function nxd_donut(array $segments, string $centerValue, string $centerLabel): string
{
    $total = array_sum(array_map(fn($s) => (float)$s[1], $segments));
    $r = 70; $c = 2 * M_PI * $r; $off = 0;
    $svg = '<svg class="nxd-donut" viewBox="0 0 200 200" role="img" aria-label="Allocation chart"><circle cx="100" cy="100" r="' . $r . '" class="nxd-donut-track"/>';
    if ($total > 0) {
        foreach ($segments as [$lab, $v, $col]) {
            $len = $c * (float)$v / $total;
            $gap = count($segments) > 1 ? min(3, $len * 0.3) : 0;
            $svg .= sprintf('<circle cx="100" cy="100" r="%d" fill="none" stroke="%s" stroke-width="26" stroke-dasharray="%.2f %.2f" stroke-dashoffset="%.2f" transform="rotate(-90 100 100)"><title>%s</title></circle>', $r, $col, max(0, $len - $gap), $c, -$off, e($lab));
            $off += $len;
        }
    }
    $svg .= '<text x="100" y="98" text-anchor="middle" class="nxd-donut-value">' . e($centerValue) . '</text><text x="100" y="118" text-anchor="middle" class="nxd-donut-label">' . e($centerLabel) . '</text></svg>';
    return $svg;
}

function nxd_sparkline(array $vals, bool $up): string
{
    if (count($vals) < 2) return '';
    $W = 90; $H = 30; $min = min($vals); $max = max($vals); $span = ($max - $min) ?: 1; $n = count($vals);
    $d = '';
    foreach ($vals as $i => $v) $d .= ($i ? ' L' : 'M') . sprintf('%.1f %.1f', $i * $W / ($n - 1), 3 + ($H - 6) * (1 - ($v - $min) / $span));
    return '<svg class="nxd-spark ' . ($up ? 'is-up' : 'is-down') . '" viewBox="0 0 ' . $W . ' ' . $H . '" aria-hidden="true"><path d="' . $d . '"/></svg>';
}

/** Heatmap tile colour class from a % change. */
function nxd_heat_class(float $chg): string
{
    if ($chg >= 3) return 'h-up3';
    if ($chg >= 1) return 'h-up2';
    if ($chg > 0.005) return 'h-up1';
    if ($chg <= -3) return 'h-dn3';
    if ($chg <= -1) return 'h-dn2';
    if ($chg < -0.005) return 'h-dn1';
    return 'h-flat';
}

/** Two-letter avatar for a product/lot. */
function nxd_badge(string $name, int $seed = 0): string
{
    $tones = ['t-blue', 't-cyan', 't-amber', 't-green', 't-rose', 't-violet'];
    $words = preg_split('/\s+/', trim($name)) ?: ['?'];
    $ini = mb_strtoupper(mb_substr($words[0], 0, 1) . (isset($words[1]) ? mb_substr($words[1], 0, 1) : mb_substr($words[0], 1, 1)));
    return '<span class="nxd-avatar ' . $tones[abs($seed) % count($tones)] . '" aria-hidden="true">' . e($ini) . '</span>';
}

function nxd_request_meta(string $kind): array
{
    return match ($kind) {
        'deposit' => ['Deposit', 'plus', 't-green', 1],
        'withdraw' => ['Withdrawal', 'arrow-up-right', 't-rose', -1],
        'lot_purchase' => ['Lot purchase', 'lots', 't-blue', -1],
        'lot_transfer', 'earnings_transfer' => ['Earnings transfer', 'transactions', 't-violet', 1],
        'referral' => ['Referral bonus', 'users', 't-cyan', 1],
        'incentive' => ['Lot incentive', 'users', 't-green', 1],
        default => [ucwords(str_replace('_', ' ', $kind)), 'transactions', 't-cyan', 1],
    };
}

function nxd_status_pill(string $status): string
{
    $map = ['paid' => 'done', 'credited' => 'done', 'approved' => 'done', 'completed' => 'done', 'confirmed' => 'done', 'pending' => 'pending', 'rejected' => 'failed', 'cancelled' => 'failed'];
    $cls = $map[$status] ?? 'neutral';
    $label = in_array($status, ['paid', 'credited'], true) ? 'Done' : ucwords(str_replace('_', ' ', $status));
    return '<span class="nxd-pill nxd-pill-' . $cls . '">' . e($label) . '</span>';
}

/** Topbar counters (real data): pending payment requests and support replies. */
function nxt_topbar_counts(array $user, string $mode): array
{
    $out = ['bell' => 0, 'chat' => 0, 'msg' => 0];
    if (!isset($user['id'])) return $out;
    try {
        require_once __DIR__ . '/chat.php';
        chat_ensure_schema();
        if ($mode === 'trader') $out['msg'] = chat_unread_total((int)$user['id']);
        elseif ($mode === 'admin' && chat_is_moderator($user)) $out['msg'] = (int)chat_q("SELECT COUNT(*) FROM chat_reports WHERE status='open'")->fetchColumn();
    } catch (Throwable $e) { /* chat is optional */ }
    try {
        if ($mode === 'trader') {
            $out['bell'] = (int)cash_query("SELECT COUNT(*) FROM cash_requests WHERE user_id=? AND status='pending'", [(int)$user['id']])->fetchColumn();
            try { $out['chat'] = (int)cash_query("SELECT COUNT(*) FROM support_tickets WHERE user_id=? AND status IN ('in_progress','resolved') AND admin_reply IS NOT NULL", [(int)$user['id']])->fetchColumn(); } catch (Throwable $e) {}
        } elseif ($mode === 'admin') {
            if (in_array($user['role'], ['finance', 'super_admin'], true)) {
                $out['bell'] = (int)cash_query("SELECT COUNT(*) FROM cash_requests WHERE status='pending' AND kind IN ('deposit','withdraw')")->fetchColumn();
            }
            try { $out['chat'] = (int)cash_query("SELECT COUNT(*) FROM support_tickets WHERE status='open'")->fetchColumn(); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) { /* counters are optional */ }
    return $out;
}
