<?php
declare(strict_types=1);

/*
 * Plan Simulator (actuarial stress test) + System Reset + Admin Action PIN.
 *
 * The simulator registers real test members (username sim{run}_{n}) through the normal
 * compensation engine, spread over N simulated days (MySQL SET TIMESTAMP moves NOW()/CURDATE()
 * so the daily pair cap resets per day), adds repeat purchases, then measures revenue against
 * every peso of commission to show whether the plan makes or loses money.
 */

/* ---------- Admin Action PIN ---------- */

function admin_pin_is_set(): bool
{
    return (string)setting('admin_action_pin', '') !== '';
}

function admin_pin_set(string $pin): void
{
    if (!preg_match('/^\d{4,8}$/', $pin)) {
        throw new RuntimeException('The PIN must be 4 to 8 digits.');
    }
    save_setting('admin_action_pin', password_hash($pin, PASSWORD_DEFAULT));
    settings_all(true);
}

/** Throws when the PIN is missing, wrong, or locked after 5 wrong tries in 15 minutes. */
function admin_pin_check(string $pin): void
{
    if (!admin_pin_is_set()) {
        throw new RuntimeException('Set the Admin Action PIN first.');
    }
    $now = time();
    $fails = array_values(array_filter($_SESSION['pin_fails'] ?? [], fn($t) => $t > $now - 900));
    if (count($fails) >= 5) {
        throw new RuntimeException('Too many wrong PIN attempts. Try again in ' . ceil(($fails[0] + 900 - $now) / 60) . ' minute(s).');
    }
    if (!password_verify($pin, (string)setting('admin_action_pin'))) {
        $fails[] = $now;
        $_SESSION['pin_fails'] = $fails;
        throw new RuntimeException('Wrong PIN. ' . (5 - count($fails)) . ' attempt(s) left.');
    }
    $_SESSION['pin_fails'] = [];
}

/** The top (root) member account: no placement upline, lowest id. */
function system_root_id(): int
{
    return (int)q_val("SELECT id FROM users WHERE role = 'member' AND placement_id IS NULL ORDER BY id LIMIT 1");
}

/* ---------- Simulation ---------- */

function sim_defaults(): array
{
    $mix = [];
    foreach (q_all("SELECT id FROM packages WHERE status = 'active' ORDER BY price") as $p) {
        $mix[(int)$p['id']] = 0;
    }
    $ids = array_keys($mix);
    $n = count($ids);
    foreach ($ids as $i => $id) {
        $mix[$id] = $n ? (int)floor(100 / $n) + ($i < 100 % $n ? 1 : 0) : 0;
    }
    $top = q_val('SELECT username FROM users WHERE id = ?', [system_root_id()]);
    return [
        'members' => 100, 'levels' => 5, 'invites' => 0, 'placement' => 'auto', 'left_percent' => 50,
        'pair_cap' => (int)setting('pair_daily_cap', 0), 'days' => 30, 'repeat_units' => 2,
        'cogs_percent' => 35, 'package_cost_percent' => 35, 'opex_percent' => 10,
        'top' => (string)$top, 'seed' => '', 'mix' => $mix,
    ];
}

/** Clean and bound the form input. */
function sim_params(array $in): array
{
    $d = sim_defaults();
    $clamp = fn($v, $min, $max, $def) => is_numeric($v) ? max($min, min($max, (int)$v)) : $def;
    $pct = fn($v, $def) => is_numeric($v) ? max(0.0, min(100.0, round((float)$v, 2))) : $def;
    $p = [
        'members'      => $clamp($in['members'] ?? null, 1, 2000, $d['members']),
        'levels'       => $clamp($in['levels'] ?? null, 1, 30, $d['levels']),
        'invites'      => $clamp($in['invites'] ?? null, 0, 100, 0),
        'placement'    => in_array($in['placement'] ?? '', ['auto', 'ratio'], true) ? $in['placement'] : 'auto',
        'left_percent' => $clamp($in['left_percent'] ?? null, 0, 100, 50),
        'pair_cap'     => $clamp($in['pair_cap'] ?? null, 0, 1000, $d['pair_cap']),
        'days'         => $clamp($in['days'] ?? null, 1, 365, $d['days']),
        'repeat_units' => $clamp($in['repeat_units'] ?? null, 0, 100, $d['repeat_units']),
        'cogs_percent' => $pct($in['cogs_percent'] ?? null, $d['cogs_percent']),
        'package_cost_percent' => $pct($in['package_cost_percent'] ?? null, $d['package_cost_percent']),
        'opex_percent' => $pct($in['opex_percent'] ?? null, $d['opex_percent']),
        'top'          => strtolower(trim((string)($in['top'] ?? ''))) ?: $d['top'],
        'seed'         => preg_replace('/\D/', '', (string)($in['seed'] ?? '')),
        'mix'          => [],
    ];
    foreach ($d['mix'] as $id => $def) {
        $p['mix'][$id] = $pct($in['mix'][$id] ?? null, 0);
    }
    if (array_sum($p['mix']) <= 0) {
        throw new RuntimeException('Set a package mix (at least one package above 0%).');
    }
    return $p;
}

/** Invites per member needed so $levels levels hold $members. */
function sim_auto_invites(int $members, int $levels): int
{
    for ($k = 1; $k < 1000; $k++) {
        $cap = 0;
        $pow = 1;
        for ($i = 1; $i <= $levels; $i++) {
            $pow *= $k;
            $cap += $pow;
            if ($cap >= $members) {
                return $k;
            }
        }
    }
    return 1000;
}

function sim_pick(array $weights): int
{
    $total = array_sum($weights);
    $r = mt_rand() / mt_getrandmax() * $total;
    foreach ($weights as $id => $w) {
        if ($w <= 0) {
            continue;
        }
        $r -= $w;
        if ($r <= 0) {
            return (int)$id;
        }
    }
    return (int)array_key_last(array_filter($weights));
}

function sim_set_clock(int $ts): void
{
    db()->exec('SET TIMESTAMP = ' . $ts);
    $GLOBALS['sim_today'] = date('Y-m-d', $ts);
}

function sim_reset_clock(): void
{
    db()->exec('SET TIMESTAMP = DEFAULT');
    unset($GLOBALS['sim_today']);
}

/** Run a simulation. Returns the simulation_runs id (status completed or failed). */
function run_simulation(array $p, int $adminId, ?string $backupFile = null): int
{
    @set_time_limit(0);
    ignore_user_abort(true);

    $top = find_member_by_username($p['top']);
    if (!$top || $top['status'] !== 'active') {
        throw new RuntimeException('Top sponsor not found or not active.');
    }
    $packages = [];
    foreach (q_all("SELECT * FROM packages WHERE status = 'active'") as $pk) {
        $packages[(int)$pk['id']] = $pk;
    }
    $products = q_all("SELECT id, stock FROM products WHERE status = 'active'");
    if ($p['repeat_units'] > 0 && !$products) {
        throw new RuntimeException('There are no active products for repeat purchases. Set repeat units to 0 or add a product.');
    }

    q('INSERT INTO simulation_runs (status, params, backup_file, created_by) VALUES (?,?,?,?)',
        ['running', json_encode($p), $backupFile, $adminId]);
    $runId = (int)db()->lastInsertId();
    $before = [];
    foreach (['users', 'earnings', 'orders', 'activity_logs', 'codes', 'journal_entries'] as $t) {
        $before[$t] = (int)q_val("SELECT COALESCE(MAX(id),0) FROM {$t}");
    }

    // ---- Plan the sponsor tree: breadth-first, $invites recruits per member, up to $levels deep.
    $invites = $p['invites'] ?: sim_auto_invites($p['members'], $p['levels']);
    $plan = [];            // [sponsorIndex (-1 = top), depth]
    $frontier = [-1];
    for ($depth = 1; $depth <= $p['levels'] && count($plan) < $p['members']; $depth++) {
        $next = [];
        foreach ($frontier as $s) {
            for ($k = 0; $k < $invites && count($plan) < $p['members']; $k++) {
                $plan[] = [$s, $depth];
                $next[] = count($plan) - 1;
            }
        }
        $frontier = $next;
    }

    mt_srand($p['seed'] !== '' ? (int)$p['seed'] : $runId * 7919);
    $GLOBALS['setting_overrides'] = [
        'pair_daily_cap' => (string)$p['pair_cap'],
        'order_payment_methods' => implode(',', array_unique(array_merge(setting_list('order_payment_methods'), ['cash']))),
    ];
    $hash = password_hash('Sim' . bin2hex(random_bytes(6)) . '9', PASSWORD_DEFAULT);
    $startTs = strtotime(date('Y-m-d') . ' 00:00:00') - ($p['days'] - 1) * 86400;
    $total = count($plan);
    $perDay = [];
    foreach (array_keys($plan) as $i) {
        $perDay[intdiv($i * $p['days'], max(1, $total))][] = $i;
    }
    q("UPDATE products SET stock = stock + 1000000 WHERE status = 'active'"); // simulated sales never use up real stock

    $members = [];         // index => [id, username, day]
    $error = null;
    try {
        foreach ($perDay as $day => $idxs) {
            foreach ($idxs as $slot => $i) {
                [$sIdx] = $plan[$i];
                $sponsorId = $sIdx < 0 ? (int)$top['id'] : $members[$sIdx]['id'];
                $sponsorName = $sIdx < 0 ? $top['username'] : $members[$sIdx]['username'];
                sim_set_clock($startTs + $day * 86400 + 8 * 3600 + intdiv($slot * 12 * 3600, count($idxs)));

                $placement = '';
                $position = '';
                if ($p['placement'] === 'ratio') {
                    $position = mt_rand(1, 100) <= $p['left_percent'] ? 'L' : 'R';
                    $child = q_val('SELECT id FROM users WHERE placement_id = ? AND position = ?', [$sponsorId, $position]);
                    [$plId, $position] = $child ? find_first_free_slot((int)$child) : [$sponsorId, $position];
                    $placement = (string)q_val('SELECT username FROM users WHERE id = ?', [$plId]);
                }
                $pkgId = sim_pick($p['mix']);
                $username = "sim{$runId}_" . ($i + 1);
                $id = tx(function () use ($pkgId, $packages, $username, $sponsorName, $placement, $position, $hash) {
                    $code = generate_codes($pkgId, 1, 'paid', null)[0];
                    $codeRow = q_row('SELECT id, pin FROM codes WHERE code = ?', [$code]);
                    erp_post_code_sale('cash', (float)$packages[$pkgId]['price'], "Simulation code sale {$code}: {$packages[$pkgId]['name']} for @{$username}", null, 'codes', (int)$codeRow['id']);
                    return register_member([
                        'username' => $username, 'email' => $username . '@simulation.test', '_password_hash' => $hash,
                        'first_name' => 'Sim', 'last_name' => 'Member ' . strtoupper($username), 'mobile' => '', 'address' => 'Simulation',
                        'sponsor' => $sponsorName, 'placement' => $placement, 'position' => $position,
                        'code' => $code, 'pin' => $codeRow['pin'],
                    ]);
                });
                $members[$i] = ['id' => $id, 'username' => $username, 'day' => $day];
            }
        }

        // ---- Repeat purchases: each member buys N units on a random day after joining.
        if ($p['repeat_units'] > 0) {
            foreach ($members as $m) {
                $cart = [];
                for ($u = 0; $u < $p['repeat_units']; $u++) {
                    $pid = (int)$products[mt_rand(0, count($products) - 1)]['id'];
                    $cart[$pid] = ($cart[$pid] ?? 0) + 1;
                }
                $day = mt_rand($m['day'], $p['days'] - 1);
                sim_set_clock($startTs + $day * 86400 + 20 * 3600 + mt_rand(0, 3 * 3600));
                tx(function () use ($m, $cart) {
                    $orderId = create_order($m['id'], $cart, 'cash', 'Simulation order');
                    q("UPDATE orders SET status = 'paid', paid_at = NOW() WHERE id = ?", [$orderId]);
                    process_order_commission($orderId);
                });
            }
        }
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    } finally {
        sim_reset_clock();
        unset($GLOBALS['setting_overrides']);
        foreach ($products as $pr) {
            q('UPDATE products SET stock = ? WHERE id = ?', [$pr['stock'], $pr['id']]);
        }
    }

    $results = sim_compute_results($runId, $p, $before, $startTs, count($members), $invites);
    q('UPDATE simulation_runs SET status = ?, results = ?, members_created = ?, net_profit = ?, payout_ratio = ?, error = ?, finished_at = NOW() WHERE id = ?', [
        $error ? 'failed' : 'completed', json_encode($results), count($members), $results['profit']['net'],
        $results['ratios']['payout'], $error ? mb_substr($error, 0, 255) : null, $runId,
    ]);
    log_activity($adminId, "Ran plan simulation #{$runId}: " . count($members) . ' members' . ($error ? " (stopped: {$error})" : ''));
    return $runId;
}

/** Actuarial summary of everything a run created. */
function sim_compute_results(int $runId, array $p, array $b, int $startTs, int $created, int $invites): array
{
    $like = "sim{$runId}\\_%";
    $pkgRows = q_all("SELECT pk.id, pk.name, pk.price, COUNT(u.id) n, COALESCE(SUM(pk.price),0) revenue FROM users u JOIN packages pk ON pk.id = u.package_id
                      WHERE u.id > ? AND u.username LIKE ? GROUP BY pk.id ORDER BY pk.price", [$b['users'], $like]);
    $orders = q_row("SELECT COUNT(*) n, COALESCE(SUM(total),0) total, COALESCE(SUM(subtotal_srp),0) srp, COALESCE(SUM(discount),0) discount
                     FROM orders WHERE id > ? AND status = 'paid'", [$b['orders']]);
    $units = (int)q_val('SELECT COALESCE(SUM(qty),0) FROM order_items WHERE order_id > ?', [$b['orders']]);

    $byType = [];
    foreach (earning_types() as $k => $t) {
        $byType[$k] = ['label' => $t['label'], 'count' => 0, 'amount' => 0.0, 'pairs' => 0, 'paid_to' => []];
    }
    foreach (q_all('SELECT type, paid_to, COUNT(*) n, SUM(amount) amt, SUM(pairs) pairs FROM earnings WHERE id > ? GROUP BY type, paid_to', [$b['earnings']]) as $r) {
        $byType[$r['type']]['count'] += (int)$r['n'];
        $byType[$r['type']]['amount'] += (float)$r['amt'];
        $byType[$r['type']]['pairs'] += (int)$r['pairs'];
        $byType[$r['type']]['paid_to'][$r['paid_to']] = round((float)$r['amt'], 2);
    }
    $paidTo = ['ewallet' => 0.0, 'product' => 0.0, 'outright' => 0.0, 'none' => 0.0];
    foreach (q_all('SELECT paid_to, SUM(amount) amt FROM earnings WHERE id > ? GROUP BY paid_to', [$b['earnings']]) as $r) {
        $paidTo[$r['paid_to']] = (float)$r['amt'];
    }

    // Flushed pairing (daily cap reached)
    $flushedEvents = 0;
    $flushedValue = 0.0;
    $rate = (float)setting('pair_rate', 100) / 100;
    foreach (q_all("SELECT action FROM activity_logs WHERE id > ? AND action LIKE 'Pairing of % flushed%'", [$b['activity_logs']]) as $r) {
        if (preg_match('/Pairing of ([\d,.]+) flushed/', $r['action'], $m)) {
            $flushedEvents++;
            $flushedValue += (float)str_replace(',', '', $m[1]) * $rate;
        }
    }

    // Commission cost per package bought (attributed to the member who triggered it)
    $perPackage = [];
    foreach ($pkgRows as $pk) {
        $perPackage[(int)$pk['id']] = ['name' => $pk['name'], 'price' => (float)$pk['price'], 'members' => (int)$pk['n'], 'revenue' => (float)$pk['revenue'], 'commissions' => 0.0];
    }
    foreach (q_all("SELECT s.package_id, SUM(e.amount) amt FROM earnings e JOIN users s ON s.id = e.source_user_id
                    WHERE e.id > ? AND e.type <> 'discount' AND s.username LIKE ? GROUP BY s.package_id", [$b['earnings'], $like]) as $r) {
        if (isset($perPackage[(int)$r['package_id']])) {
            $perPackage[(int)$r['package_id']]['commissions'] = (float)$r['amt'];
        }
    }

    $top = q_all("SELECT u.username, pk.name package, SUM(e.amount) total,
                         SUM(CASE WHEN e.paid_to = 'ewallet' THEN e.amount ELSE 0 END) ewallet,
                         SUM(CASE WHEN e.paid_to = 'product' THEN e.amount ELSE 0 END) product,
                         SUM(CASE WHEN e.paid_to = 'outright' THEN e.amount ELSE 0 END) outright
                  FROM earnings e JOIN users u ON u.id = e.user_id LEFT JOIN packages pk ON pk.id = u.package_id
                  WHERE e.id > ? AND e.type <> 'discount' GROUP BY e.user_id ORDER BY total DESC LIMIT 10", [$b['earnings']]);

    // Member ROI: how many simulated members earned back their package price
    $roi = q_row("SELECT COUNT(*) members, SUM(earned >= price) recovered, SUM(earned = 0) zero, COALESCE(AVG(earned),0) avg_earned, COALESCE(MAX(earned),0) max_earned FROM (
                    SELECT u.id, pk.price, COALESCE(SUM(CASE WHEN e.type <> 'discount' THEN e.amount END),0) earned
                    FROM users u JOIN packages pk ON pk.id = u.package_id LEFT JOIN earnings e ON e.user_id = u.id AND e.id > ?
                    WHERE u.id > ? AND u.username LIKE ? GROUP BY u.id, pk.price) t", [$b['earnings'], $b['users'], $like]);

    // Daily cash flow
    $daily = [];
    for ($d = 0; $d < $p['days']; $d++) {
        $daily[date('Y-m-d', $startTs + $d * 86400)] = ['revenue' => 0.0, 'commissions' => 0.0, 'joins' => 0];
    }
    foreach (q_all("SELECT DATE(u.created_at) d, COUNT(*) n, SUM(pk.price) amt FROM users u JOIN packages pk ON pk.id = u.package_id
                    WHERE u.id > ? AND u.username LIKE ? GROUP BY DATE(u.created_at)", [$b['users'], $like]) as $r) {
        if (isset($daily[$r['d']])) {
            $daily[$r['d']]['revenue'] += (float)$r['amt'];
            $daily[$r['d']]['joins'] = (int)$r['n'];
        }
    }
    foreach (q_all("SELECT DATE(paid_at) d, SUM(total) amt FROM orders WHERE id > ? AND status = 'paid' GROUP BY DATE(paid_at)", [$b['orders']]) as $r) {
        if (isset($daily[$r['d']])) {
            $daily[$r['d']]['revenue'] += (float)$r['amt'];
        }
    }
    foreach (q_all("SELECT DATE(created_at) d, SUM(amount) amt FROM earnings WHERE id > ? AND type <> 'discount' GROUP BY DATE(created_at)", [$b['earnings']]) as $r) {
        if (isset($daily[$r['d']])) {
            $daily[$r['d']]['commissions'] += (float)$r['amt'];
        }
    }

    // ---- Profit & loss
    $pkgRevenue = array_sum(array_column($pkgRows, 'revenue'));
    $prodRevenue = (float)$orders['total'];
    $revenue = $pkgRevenue + $prodRevenue;
    $pkgCost = $pkgRevenue * $p['package_cost_percent'] / 100;
    $prodCost = $prodRevenue * $p['cogs_percent'] / 100;
    $pwCost = $paidTo['product'] * $p['cogs_percent'] / 100;
    $opex = $revenue * $p['opex_percent'] / 100;
    $commissions = $paidTo['ewallet'] + $paidTo['product'] + $paidTo['outright'];
    $net = $revenue - $pkgCost - $prodCost - $paidTo['outright'] - $paidTo['ewallet'] - $pwCost - $opex;
    $worst = $net - ($paidTo['product'] - $pwCost);
    $pct = fn($x) => $revenue > 0 ? round($x / $revenue * 100, 2) : 0.0;

    return [
        'run_id' => $runId,
        'members_created' => $created,
        'members_planned' => $p['members'],
        'invites_used' => $invites,
        'start_date' => date('Y-m-d', $startTs),
        'end_date' => date('Y-m-d', $startTs + ($p['days'] - 1) * 86400),
        'plan' => [
            'pairing_mode' => setting('pairing_mode', 'unit'), 'pair_rate' => (float)setting('pair_rate', 100),
            'pairing_to_product_wallet' => setting('pairing_to_product_wallet', '0') === '1',
            'direct_outright' => setting('direct_outright', '1') === '1', 'pair_cap' => $p['pair_cap'],
        ],
        'packages' => array_values($perPackage),
        'orders' => ['count' => (int)$orders['n'], 'units' => $units, 'total' => $prodRevenue, 'srp' => (float)$orders['srp'], 'discount' => (float)$orders['discount']],
        'by_type' => $byType,
        'paid_to' => $paidTo,
        'pairs_paid' => $byType['pairing']['pairs'],
        'flushed' => ['events' => $flushedEvents, 'value' => round($flushedValue, 2)],
        'revenue' => ['packages' => $pkgRevenue, 'products' => $prodRevenue, 'total' => $revenue],
        'costs' => ['package_cost' => round($pkgCost, 2), 'product_cogs' => round($prodCost, 2), 'outright' => $paidTo['outright'],
                    'ewallet' => $paidTo['ewallet'], 'product_wallet' => $paidTo['product'], 'product_wallet_cost' => round($pwCost, 2), 'opex' => round($opex, 2)],
        'profit' => ['net' => round($net, 2), 'worst' => round($worst, 2), 'per_member' => $created ? round($net / $created, 2) : 0],
        'ratios' => ['payout' => $pct($commissions), 'cash_payout' => $pct($paidTo['ewallet'] + $paidTo['outright']), 'margin' => $pct($net), 'worst_margin' => $pct($worst)],
        'roi' => ['members' => (int)$roi['members'], 'recovered' => (int)$roi['recovered'], 'zero' => (int)$roi['zero'],
                  'avg' => round((float)$roi['avg_earned'], 2), 'max' => round((float)$roi['max_earned'], 2)],
        'top' => $top,
        'daily' => $daily,
    ];
}

/* ---------- System Reset ---------- */

/**
 * Clear all transactional data and every member except the root account.
 * Keeps admin / content manager accounts, settings, packages, products, CMS content and the chart of accounts.
 */
function reset_system(bool $clearSimulations = false): array
{
    @set_time_limit(0);
    $root = system_root_id();
    $pdo = db();
    $counts = [];
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        tx(function () use ($root, $clearSimulations, &$counts) {
            foreach (['order_items', 'orders', 'earnings', 'wallet_transactions', 'payouts', 'payments', 'chat_messages', 'chat_threads',
                      'crm_activities', 'crm_leads', 'journal_lines', 'journal_entries', 'expenses', 'activity_logs'] as $t) {
                $counts[$t] = q("DELETE FROM {$t}")->rowCount();
            }
            $counts['codes'] = q('DELETE FROM codes WHERE used_by IS NULL OR used_by <> ?', [$root])->rowCount();
            $counts['members'] = q("DELETE FROM users WHERE role = 'member' AND id <> ?", [$root])->rowCount();
            if ($clearSimulations) {
                $counts['simulation_runs'] = q('DELETE FROM simulation_runs')->rowCount();
            }
            if ($root) {
                q('UPDATE users SET sponsor_id = NULL, placement_id = NULL, position = NULL, passup_upline_id = NULL, direct_count = 0,
                   left_count = 0, right_count = 0, left_points = 0, right_points = 0, wallet_balance = 0, product_wallet = 0, total_earnings = 0
                   WHERE id = ?', [$root]);
            }
        });
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
    return $counts;
}
