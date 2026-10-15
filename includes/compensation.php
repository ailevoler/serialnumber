<?php
declare(strict_types=1);

/**
 * XGEN LIFE Intl - Compensation Engine (6 Ways to Earn + Wallet)
 *
 *  1. Discount          - members buy at member price; SRP minus member price is their retail profit.
 *  2. Direct Referral   - fixed bonus (per package) to the sponsor when a new member activates.
 *  3. Indirect Referral - fixed amounts per sponsor level above the direct sponsor.
 *  4. Pass Up Bonus     - Australian pass-up: a sponsor's Nth recruit(s) are passed up; the
 *                         member they are passed up to earns the pass-up bonus.
 *  5. Pairing Bonus     - binary: every pair of PV (left + right) pays a bonus, with a daily cap.
 *  6. Unilevel          - % of repeat purchase PV paid up the sponsor line.
 *  + Wallet             - all credited income goes to the e-wallet (payouts, purchases, transfers).
 */

function credit_earning(int $userId, string $type, float $amount, ?int $sourceId, string $desc, ?int $orderId = null, ?int $level = null, int $pairs = 0, bool $toWallet = true): void
{
    $amount = round($amount, 2);
    if ($amount <= 0) {
        return;
    }
    q('INSERT INTO earnings (user_id, type, amount, source_user_id, order_id, level, pairs, credited, description) VALUES (?,?,?,?,?,?,?,?,?)',
        [$userId, $type, $amount, $sourceId, $orderId, $level, $pairs, $toWallet ? 1 : 0, mb_substr($desc, 0, 255)]);
    if ($toWallet) {
        q('UPDATE users SET total_earnings = total_earnings + ? WHERE id = ?', [$amount, $userId]);
        wallet_credit($userId, 'earning', $amount, $desc, strtoupper($type));
    }
}

function find_member_by_username(string $username): ?array
{
    return q_row("SELECT * FROM users WHERE username = ? AND role = 'member'", [$username]);
}

/** True when $nodeId is $ancestorId or sits anywhere below it in the binary tree. */
function in_binary_downline(int $ancestorId, int $nodeId): bool
{
    $guard = 0;
    while ($nodeId && $guard++ < 100000) {
        if ($nodeId === $ancestorId) {
            return true;
        }
        $nodeId = (int)q_val('SELECT placement_id FROM users WHERE id = ?', [$nodeId]);
    }
    return false;
}

/** True when $nodeId is anywhere below $ancestorId in the sponsor (unilevel) tree. */
function in_sponsor_downline(int $ancestorId, int $nodeId): bool
{
    $guard = 0;
    $nodeId = (int)q_val('SELECT sponsor_id FROM users WHERE id = ?', [$nodeId]);
    while ($nodeId && $guard++ < 100000) {
        if ($nodeId === $ancestorId) {
            return true;
        }
        $nodeId = (int)q_val('SELECT sponsor_id FROM users WHERE id = ?', [$nodeId]);
    }
    return false;
}

function slot_taken(int $placementId, string $pos): bool
{
    return (bool)q_val('SELECT id FROM users WHERE placement_id = ? AND position = ?', [$placementId, $pos]);
}

/** Outer-most free slot on one side of $rootId (extreme left / extreme right). */
function find_extreme_slot(int $rootId, string $side): array
{
    $cur = $rootId;
    while (true) {
        $child = q_val('SELECT id FROM users WHERE placement_id = ? AND position = ?', [$cur, $side]);
        if (!$child) {
            return [$cur, $side];
        }
        $cur = (int)$child;
    }
}

/** First free slot under $rootId, level by level, left before right. */
function find_first_free_slot(int $rootId): array
{
    $queue = [$rootId];
    while ($queue) {
        $id = array_shift($queue);
        $kids = q_all('SELECT id, position FROM users WHERE placement_id = ?', [$id]);
        $have = array_column($kids, 'id', 'position');
        foreach (['L', 'R'] as $s) {
            if (!isset($have[$s])) {
                return [$id, $s];
            }
            $queue[] = (int)$have[$s];
        }
    }
    return [$rootId, 'L'];
}

/**
 * Register and activate a new member with an activation code.
 * $d keys: username, email, password, first_name, last_name, mobile, address,
 *          sponsor, placement (optional), position (L|R|''), code, pin
 * Returns the new user id. Throws RuntimeException with a user-friendly message on error.
 */
function register_member(array $d): int
{
    $username = strtolower(trim($d['username'] ?? ''));
    if (!preg_match('/^[a-z0-9_.]{4,30}$/', $username)) {
        throw new RuntimeException('Username must be 4-30 characters (letters, numbers, underscore, dot).');
    }
    if (!filter_var($d['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Please enter a valid email address.');
    }
    if (trim($d['first_name'] ?? '') === '' || trim($d['last_name'] ?? '') === '') {
        throw new RuntimeException('First name and last name are required.');
    }
    if ($err = validate_password($d['password'] ?? '')) {
        throw new RuntimeException($err);
    }
    $position = strtoupper($d['position'] ?? '');
    if ($position !== '' && !in_array($position, ['L', 'R'], true)) {
        throw new RuntimeException('Invalid placement position.');
    }

    return tx(function () use ($d, $username, $position) {
        if (q_val('SELECT id FROM users WHERE username = ?', [$username])) {
            throw new RuntimeException('Username is already taken.');
        }

        $code = q_row("SELECT c.*, p.name AS package_name, p.pv, p.direct_bonus FROM codes c JOIN packages p ON p.id = c.package_id
                       WHERE c.code = ? FOR UPDATE", [strtoupper(trim($d['code'] ?? ''))]);
        if (!$code || !hash_equals((string)$code['pin'], strtoupper(trim($d['pin'] ?? '')))) {
            throw new RuntimeException('Invalid activation code or PIN.');
        }
        if ($code['status'] !== 'unused') {
            throw new RuntimeException('This activation code has already been used or is blocked.');
        }

        $sponsor = find_member_by_username(strtolower(trim($d['sponsor'] ?? '')));
        if (!$sponsor || $sponsor['status'] !== 'active') {
            throw new RuntimeException('Sponsor username not found or not active.');
        }
        $sponsorId = (int)$sponsor['id'];
        q('SELECT id FROM users WHERE id = ? FOR UPDATE', [$sponsorId]);

        // ---- Binary placement
        $placementName = strtolower(trim($d['placement'] ?? ''));
        if ($placementName !== '') {
            $placement = find_member_by_username($placementName);
            if (!$placement) {
                throw new RuntimeException('Placement username not found.');
            }
            if (!in_binary_downline($sponsorId, (int)$placement['id'])) {
                throw new RuntimeException('Placement must be the sponsor or within the sponsor\'s genealogy.');
            }
            if ($position === '') {
                throw new RuntimeException('Please choose Left or Right position.');
            }
            if (slot_taken((int)$placement['id'], $position)) {
                throw new RuntimeException('The selected ' . ($position === 'L' ? 'left' : 'right') . ' position is already occupied.');
            }
            [$placementId, $pos] = [(int)$placement['id'], $position];
        } elseif ($position !== '') {
            [$placementId, $pos] = find_extreme_slot($sponsorId, $position);
        } else {
            [$placementId, $pos] = find_first_free_slot($sponsorId);
        }

        q('INSERT INTO users (username, email, password, first_name, last_name, mobile, address, role, status, package_id, sponsor_id, placement_id, position)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $username, trim($d['email']), password_hash($d['password'], PASSWORD_DEFAULT),
            trim($d['first_name']), trim($d['last_name']), trim($d['mobile'] ?? ''), trim($d['address'] ?? ''),
            'member', 'active', $code['package_id'], $sponsorId, $placementId, $pos,
        ]);
        $newId = (int)db()->lastInsertId();

        q("UPDATE codes SET status = 'used', used_by = ?, used_at = NOW() WHERE id = ?", [$newId, $code['id']]);
        q('UPDATE users SET direct_count = direct_count + 1 WHERE id = ?', [$sponsorId]);
        $directCount = (int)$sponsor['direct_count'] + 1;

        $newName = $username;
        $paid = $code['code_type'] === 'paid';

        // ---- 2. Direct Referral Bonus
        if ($paid) {
            credit_earning($sponsorId, 'direct', (float)$code['direct_bonus'], $newId, "Direct referral bonus from {$newName} ({$code['package_name']})");
        }

        // ---- 3. Indirect Referral (levels above the sponsor)
        if ($paid) {
            $levels = array_map('floatval', setting_list('indirect_levels'));
            $up = (int)$sponsor['sponsor_id'];
            foreach ($levels as $i => $amt) {
                if (!$up) {
                    break;
                }
                $row = q_row('SELECT id, sponsor_id, status FROM users WHERE id = ?', [$up]);
                if (!$row) {
                    break;
                }
                if ($row['status'] === 'active') {
                    credit_earning((int)$row['id'], 'indirect', $amt, $newId, 'Indirect referral L' . ($i + 1) . " from {$newName}", null, $i + 1);
                }
                $up = (int)$row['sponsor_id'];
            }
        }

        // ---- 4. Pass Up Bonus (Australian pass-up)
        $passupTo = $sponsorId;
        $positions = array_map('intval', setting_list('passup_positions'));
        if (setting('passup_enabled', '1') === '1' && in_array($directCount, $positions, true)) {
            $receiverId = (int)($sponsor['passup_upline_id'] ?: $sponsor['sponsor_id']);
            if ($receiverId) {
                $passupTo = $receiverId;
                $receiver = q_row('SELECT status FROM users WHERE id = ?', [$receiverId]);
                if ($paid && $receiver && $receiver['status'] === 'active') {
                    credit_earning($receiverId, 'passup', (float)setting('passup_bonus', 0), $newId,
                        "Pass-up bonus: {$newName} (#{$directCount} recruit of {$sponsor['username']})");
                }
            }
        }
        q('UPDATE users SET passup_upline_id = ? WHERE id = ?', [$passupTo, $newId]);

        // ---- 5. Binary points & pairing
        add_binary_points($newId, $paid ? (float)$code['pv'] : 0.0);

        log_activity($newId, "Registered with code {$code['code']} (sponsor {$sponsor['username']})");
        return $newId;
    });
}

/** Walk up the placement tree adding PV + head-count to each ancestor and run pairing. */
function add_binary_points(int $newId, float $pv): void
{
    $node = q_row('SELECT id, placement_id, position FROM users WHERE id = ?', [$newId]);
    $guard = 0;
    while ($node && $node['placement_id'] && $guard++ < 100000) {
        $parentId = (int)$node['placement_id'];
        $side = $node['position'] === 'L' ? 'left' : 'right';
        q("UPDATE users SET {$side}_count = {$side}_count + 1, {$side}_points = {$side}_points + ? WHERE id = ?", [$pv, $parentId]);
        if ($pv > 0) {
            process_pairing($parentId, $newId);
        }
        $node = q_row('SELECT id, placement_id, position FROM users WHERE id = ?', [$parentId]);
    }
}

function process_pairing(int $userId, int $sourceId): void
{
    $unit = (float)setting('pair_pv', 100);
    $bonus = (float)setting('pair_bonus', 0);
    $cap = (int)setting('pair_daily_cap', 0);
    if ($unit <= 0) {
        return;
    }
    $u = q_row('SELECT id, status, left_points, right_points FROM users WHERE id = ? FOR UPDATE', [$userId]);
    $pairs = (int)floor(min((float)$u['left_points'], (float)$u['right_points']) / $unit);
    if ($pairs < 1) {
        return;
    }
    $used = $pairs * $unit;
    q('UPDATE users SET left_points = left_points - ?, right_points = right_points - ? WHERE id = ?', [$used, $used, $userId]);

    if ($u['status'] !== 'active') {
        return; // pairs flushed for inactive accounts
    }
    $today = (int)q_val("SELECT COALESCE(SUM(pairs),0) FROM earnings WHERE user_id = ? AND type = 'pairing' AND DATE(created_at) = CURDATE()", [$userId]);
    $payable = $cap > 0 ? max(0, min($pairs, $cap - $today)) : $pairs;
    if ($payable > 0) {
        $flushed = $pairs - $payable;
        credit_earning($userId, 'pairing', $payable * $bonus, $sourceId,
            "Pairing bonus: {$payable} pair(s)" . ($flushed ? " ({$flushed} flushed – daily cap)" : ''), null, null, $payable);
    }
}

/** Pay discount record + unilevel when an order is marked paid. Safe to call more than once. */
function process_order_commission(int $orderId): void
{
    tx(function () use ($orderId) {
        $o = q_row('SELECT o.*, u.username, u.sponsor_id FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ? FOR UPDATE', [$orderId]);
        if (!$o || $o['commission_processed'] || !in_array($o['status'], ['paid', 'shipped', 'completed'], true)) {
            return;
        }

        // 1. Discount – retail profit (SRP - member price). Recorded as income, not a wallet credit.
        if ((float)$o['discount'] > 0) {
            credit_earning((int)$o['user_id'], 'discount', (float)$o['discount'], (int)$o['user_id'],
                "Member discount on order {$o['order_no']}", $orderId, null, 0, false);
        }

        // 6. Repeat Purchase Unilevel
        $pcts = array_map('floatval', setting_list('unilevel_percent'));
        $requireActive = setting('unilevel_require_active', '1') === '1';
        $up = (int)$o['sponsor_id'];
        foreach ($pcts as $i => $pct) {
            if (!$up) {
                break;
            }
            $row = q_row('SELECT id, sponsor_id, status FROM users WHERE id = ?', [$up]);
            if (!$row) {
                break;
            }
            if (!$requireActive || $row['status'] === 'active') {
                credit_earning((int)$row['id'], 'unilevel', (float)$o['total_pv'] * $pct / 100, (int)$o['user_id'],
                    'Unilevel L' . ($i + 1) . " ({$pct}%) – {$o['username']} order {$o['order_no']}", $orderId, $i + 1);
            }
            $up = (int)$row['sponsor_id'];
        }

        q('UPDATE orders SET commission_processed = 1 WHERE id = ?', [$orderId]);
    });
}

/** Earnings summary per type for a member (or all members when $userId is null). */
function earnings_summary(?int $userId = null, ?string $from = null, ?string $to = null): array
{
    $where = ['1=1'];
    $p = [];
    if ($userId) {
        $where[] = 'user_id = ?';
        $p[] = $userId;
    }
    if ($from) {
        $where[] = 'created_at >= ?';
        $p[] = $from . ' 00:00:00';
    }
    if ($to) {
        $where[] = 'created_at <= ?';
        $p[] = $to . ' 23:59:59';
    }
    $rows = q_all('SELECT type, SUM(amount) total, COUNT(*) cnt FROM earnings WHERE ' . implode(' AND ', $where) . ' GROUP BY type', $p);
    $out = [];
    foreach (earning_types() as $k => $t) {
        $out[$k] = ['total' => 0.0, 'count' => 0] + $t;
    }
    foreach ($rows as $r) {
        $out[$r['type']]['total'] = (float)$r['total'];
        $out[$r['type']]['count'] = (int)$r['cnt'];
    }
    return $out;
}

/** Load a binary sub-tree to $depth levels for the genealogy view. */
function binary_tree(int $rootId, int $depth = 4): ?array
{
    $root = q_row('SELECT u.id, u.username, u.first_name, u.last_name, u.status, u.left_count, u.right_count, u.left_points, u.right_points,
                          u.created_at, u.placement_id, p.name package_name
                   FROM users u LEFT JOIN packages p ON p.id = u.package_id WHERE u.id = ?', [$rootId]);
    if (!$root) {
        return null;
    }
    $root['children'] = ['L' => null, 'R' => null];
    if ($depth > 1) {
        foreach (q_all('SELECT id, position FROM users WHERE placement_id = ?', [$rootId]) as $c) {
            $root['children'][$c['position']] = binary_tree((int)$c['id'], $depth - 1);
        }
    }
    return $root;
}

function generate_codes(int $packageId, int $qty, string $type, ?int $ownerId): array
{
    $made = [];
    tx(function () use ($packageId, $qty, $type, $ownerId, &$made) {
        for ($i = 0; $i < $qty; $i++) {
            do {
                $code = 'XG' . random_string(8);
            } while (q_val('SELECT id FROM codes WHERE code = ?', [$code]));
            $pin = random_string(6, '0123456789');
            q('INSERT INTO codes (code, pin, package_id, code_type, owner_id) VALUES (?,?,?,?,?)', [$code, $pin, $packageId, $type, $ownerId]);
            $made[] = $code;
        }
    });
    return $made;
}

function create_order(int $userId, array $cart, string $method, string $address, string $paymentRef = ''): int
{
    $allowed = setting_list('order_payment_methods');
    if (!in_array($method, $allowed, true)) {
        throw new RuntimeException('Invalid payment method.');
    }
    if (!$cart) {
        throw new RuntimeException('Your cart is empty.');
    }
    return tx(function () use ($userId, $cart, $method, $address, $paymentRef) {
        $items = [];
        $srp = $total = $pv = 0.0;
        foreach ($cart as $pid => $qty) {
            $qty = (int)$qty;
            $p = q_row("SELECT * FROM products WHERE id = ? AND status = 'active' FOR UPDATE", [(int)$pid]);
            if (!$p || $qty < 1) {
                continue;
            }
            if ($p['stock'] < $qty) {
                throw new RuntimeException("Not enough stock for {$p['name']} (available: {$p['stock']}).");
            }
            $items[] = [$p, $qty];
            $srp += $p['srp'] * $qty;
            $total += $p['member_price'] * $qty;
            $pv += $p['pv'] * $qty;
        }
        if (!$items) {
            throw new RuntimeException('Your cart is empty.');
        }
        $orderNo = 'XO' . date('ymd') . random_string(5, '0123456789');
        $status = $method === 'wallet' ? 'paid' : 'pending';
        q('INSERT INTO orders (order_no, user_id, subtotal_srp, discount, total, total_pv, payment_method, payment_ref, shipping_address, status, paid_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$orderNo, $userId, $srp, $srp - $total, $total, $pv, $method, $paymentRef, $address, $status,
            $status === 'paid' ? date('Y-m-d H:i:s') : null]);
        $orderId = (int)db()->lastInsertId();
        foreach ($items as [$p, $qty]) {
            q('INSERT INTO order_items (order_id, product_id, product_name, qty, srp, price, pv) VALUES (?,?,?,?,?,?,?)',
                [$orderId, $p['id'], $p['name'], $qty, $p['srp'], $p['member_price'], $p['pv']]);
            q('UPDATE products SET stock = stock - ? WHERE id = ?', [$qty, $p['id']]);
        }
        if ($method === 'wallet') {
            wallet_debit($userId, 'purchase', $total, "Product purchase {$orderNo}", $orderNo);
            process_order_commission($orderId);
        }
        return $orderId;
    });
}

function cancel_order(int $orderId): void
{
    tx(function () use ($orderId) {
        $o = q_row('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if (!$o || $o['status'] === 'cancelled') {
            return;
        }
        if ($o['commission_processed']) {
            throw new RuntimeException('Commissions were already paid for this order; it can no longer be cancelled.');
        }
        foreach (q_all('SELECT product_id, qty FROM order_items WHERE order_id = ?', [$orderId]) as $it) {
            q('UPDATE products SET stock = stock + ? WHERE id = ?', [$it['qty'], $it['product_id']]);
        }
        if ($o['payment_method'] === 'wallet' && $o['status'] !== 'pending') {
            wallet_credit((int)$o['user_id'], 'purchase_refund', (float)$o['total'], "Refund for cancelled order {$o['order_no']}", $o['order_no']);
        }
        q("UPDATE orders SET status = 'cancelled' WHERE id = ?", [$orderId]);
    });
}
