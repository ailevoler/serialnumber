<?php
declare(strict_types=1);

/*
 * Package Upgrade: a member moves to a higher package by paying only the price difference.
 *  - Bonus difference: the member who earned the original Direct Referral / Coded Bonus earns the
 *    difference between the new and the old package's bonus (Indirect too, in percent mode).
 *  - PV difference goes up the binary tree (left/right points) and runs pairing; head counts do not change.
 *  - The new package's discount applies from the next purchase.
 */

function upgrade_enabled(): bool
{
    return setting('upgrade_enabled', '1') === '1';
}

function code_type_badge(string $type): string
{
    return match ($type) {
        'free' => '<span class="badge badge-muted">Free</span>',
        'upgrade' => '<span class="badge badge-gold">Upgrade</span>',
        default => '<span class="badge badge-success">Paid</span>',
    };
}

function upgrade_methods(): array
{
    return [
        'wallet' => 'E-Wallet', 'paymongo' => 'QR Ph / PayMongo', 'code' => 'Upgrade Code',
        'cash' => 'Cash', 'bank' => 'Bank Deposit', 'gcash' => 'GCash / Maya', 'none' => 'Payment recorded later', 'free' => 'Free (no bonuses / PV)',
    ];
}

/** Packages a member can upgrade to. $admin: admins may always skip levels. */
function upgrade_targets(array $member, bool $admin = false): array
{
    $current = (float)q_val('SELECT price FROM packages WHERE id = ?', [(int)$member['package_id']]);
    $higher = q_all("SELECT * FROM packages WHERE status = 'active' AND price > ? ORDER BY price, id", [$current]);
    if (!$admin && setting('upgrade_allow_skip', '1') !== '1') {
        return array_slice($higher, 0, 1);
    }
    return $higher;
}

/** Price, PV and bonus differences for an upgrade. */
function upgrade_quote(array $member, array $to): array
{
    $from = $member['package_id'] ? q_row('SELECT * FROM packages WHERE id = ?', [(int)$member['package_id']]) : null;
    $origFree = q_val("SELECT code_type FROM codes WHERE used_by = ? AND code_type IN ('paid','free') ORDER BY id LIMIT 1", [(int)$member['id']]) === 'free';
    $base = fn(string $k) => $from && !$origFree ? (float)$from[$k] : 0.0;
    // PV and bonuses already given for a free registration were zero.
    $amount = round((float)$to['price'] - ($from ? (float)$from['price'] : 0.0), 2);
    return [
        'from' => $from, 'to' => $to, 'amount' => max(0.0, $amount), 'orig_free' => $origFree,
        'pv' => max(0.0, (float)$to['pv'] - $base('pv')),
        'direct' => max(0.0, (float)$to['direct_bonus'] - $base('direct_bonus')),
        'coded' => max(0.0, (float)$to['coded_bonus'] - $base('coded_bonus')),
        'discount' => (float)$to['discount_percent'],
    ];
}

/**
 * Upgrade a member. $method: wallet | paymongo | code | cash | bank | gcash | none | free.
 * $o: reference, code_id, admin_id, amount (amount actually paid, for PayMongo), erp_method (for codes).
 * Returns the package_upgrades id.
 */
function upgrade_member(int $userId, int $toPackageId, string $method, array $o = []): int
{
    if (!array_key_exists($method, upgrade_methods())) {
        throw new RuntimeException('Invalid payment method.');
    }
    return tx(function () use ($userId, $toPackageId, $method, $o) {
        $m = q_row("SELECT * FROM users WHERE id = ? AND role = 'member' FOR UPDATE", [$userId]);
        if (!$m) {
            throw new RuntimeException('Member not found.');
        }
        $isAdmin = !empty($o['admin_id']);
        $to = null;
        foreach (upgrade_targets($m, $isAdmin) as $t) {
            if ((int)$t['id'] === $toPackageId) {
                $to = $t;
            }
        }
        if (!$to) {
            throw new RuntimeException('That package is not an available upgrade for this account.');
        }
        $qt = upgrade_quote($m, $to);
        $free = $method === 'free';
        $name = $m['username'];
        $label = ($qt['from']['name'] ?? 'None') . ' → ' . $to['name'];
        $ref = $o['reference'] ?? ('UP' . date('ymdHis') . random_string(3, '0123456789'));
        $paid = (float)($o['amount'] ?? $qt['amount']);

        // ---- Payment
        if ($method === 'wallet') {
            if (setting('upgrade_wallet', '1') !== '1' && !$isAdmin) {
                throw new RuntimeException('E-Wallet payment for upgrades is turned off.');
            }
            wallet_post($userId, 'debit', 'upgrade', $qt['amount'], "Package upgrade {$label}", $ref);
        }
        $erpMethod = match ($method) {
            'wallet', 'paymongo', 'cash', 'bank', 'gcash' => $method,
            'code' => $o['erp_method'] ?? null,
            default => null,
        };
        if ($erpMethod && $paid > 0) {
            erp_post_code_sale($erpMethod, $paid, "Package upgrade {$ref}: @{$name} {$label} (" . strtoupper($erpMethod) . ')', $userId, 'upgrade', null);
        }

        q('UPDATE users SET package_id = ? WHERE id = ?', [$to['id'], $userId]);

        // ---- Bonus difference
        $bonusTotal = 0.0;
        $pay = function (int $receiverId, string $type, float $amt, string $desc, ?int $level = null) use ($userId, &$bonusTotal) {
            $r = q_row('SELECT status FROM users WHERE id = ?', [$receiverId]);
            if ($amt > 0 && $r && $r['status'] === 'active') {
                credit_earning($receiverId, $type, $amt, $userId, $desc, null, $level);
                $bonusTotal += round($amt, 2);
            }
        };
        if (!$free && setting('upgrade_bonus', 'difference') === 'difference') {
            // Whoever earned the original bonus earns the difference (a free registration earned none,
            // so the sponsor / coded upline is used then).
            $first = fn(string $type) => (int)q_val('SELECT user_id FROM earnings WHERE source_user_id = ? AND type = ? AND order_id IS NULL ORDER BY id LIMIT 1', [$userId, $type]);
            if (plan_on('direct')) {
                $directTo = $first('direct') ?: ($qt['orig_free'] ? (int)$m['sponsor_id'] : 0);
                $amt = setting('direct_mode', 'fixed') === 'percent' ? $qt['amount'] * (float)setting('direct_percent', 0) / 100 : $qt['direct'];
                if ($directTo) {
                    $pay($directTo, 'direct', $amt, "Direct referral upgrade bonus: {$name} ({$label})");
                }
            }
            if (plan_on('passup')) {
                $codedTo = $first('passup');
                if (!$codedTo && $qt['orig_free'] && $m['passup_upline_id']
                    && ((int)$m['passup_upline_id'] !== (int)$m['sponsor_id'] || setting('passup_kept_bonus', '0') === '1')) {
                    $codedTo = (int)$m['passup_upline_id'];
                }
                $val = (float)setting('passup_bonus', 0);
                $amt = match (setting('passup_mode', 'fixed')) {
                    'percent' => $qt['amount'] * $val / 100,
                    'package' => $qt['coded'],
                    default   => 0.0, // a fixed coded bonus was already paid in full
                };
                if ($codedTo) {
                    $pay($codedTo, 'passup', $amt, "Coded bonus upgrade: {$name} ({$label})");
                }
            }
            $indMode = setting('indirect_mode', 'fixed');
            if (plan_on('indirect') && in_array($indMode, ['percent', 'package'], true)) {
                // percent: % of the price difference; per package: new package's amount less the old one's
                $fromPkg = $qt['orig_free'] ? null : ($qt['from']['id'] ?? null);
                $up = (int)q_val('SELECT sponsor_id FROM users WHERE id = ?', [(int)$m['sponsor_id']]);
                foreach (indirect_plan() as $i => $pct) {
                    if (!$up) {
                        break;
                    }
                    $amt = $indMode === 'percent'
                        ? $qt['amount'] * $pct / 100
                        : indirect_package_amount((int)$to['id'], $up) - indirect_package_amount($fromPkg ? (int)$fromPkg : null, $up);
                    $pay($up, 'indirect', $amt, 'Indirect upgrade bonus L' . ($i + 1) . " from {$name} ({$label})", $i + 1);
                    $up = (int)q_val('SELECT sponsor_id FROM users WHERE id = ?', [$up]);
                }
            }
        }

        // ---- XGEN Bonus: the member's own target rises; the sponsor's direct may now count more
        if (!$free) {
            exgen_check($userId, $userId);
            if ($m['sponsor_id']) {
                exgen_check((int)$m['sponsor_id'], $userId);
            }
        }

        // ---- PV difference to the binary
        $pv = !$free && setting('upgrade_add_pv', '1') === '1' ? $qt['pv'] : 0.0;
        if ($pv > 0) {
            add_upgrade_points($userId, $pv);
        }

        q('INSERT INTO package_upgrades (user_id, from_package_id, to_package_id, amount, pv_added, bonuses, method, reference, code_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)', [
            $userId, $m['package_id'], $to['id'], $free ? 0 : $paid, $pv, $bonusTotal, $method, $ref, $o['code_id'] ?? null, $o['admin_id'] ?? null,
        ]);
        $id = (int)db()->lastInsertId();
        log_activity($o['admin_id'] ?? $userId, "Package upgrade @{$name}: {$label} via " . upgrade_methods()[$method] . " ({$ref})");
        return $id;
    });
}

/** Add upgrade PV up the placement tree (no head count change) and run pairing. */
function add_upgrade_points(int $userId, float $pv): void
{
    $node = q_row('SELECT id, placement_id, position FROM users WHERE id = ?', [$userId]);
    $guard = 0;
    while ($node && $node['placement_id'] && $guard++ < 100000) {
        $parentId = (int)$node['placement_id'];
        $side = $node['position'] === 'L' ? 'left' : 'right';
        q("UPDATE users SET {$side}_points = {$side}_points + ? WHERE id = ?", [$pv, $parentId]);
        if (plan_on('pairing')) {
            process_pairing($parentId, $userId);
        }
        $node = q_row('SELECT id, placement_id, position FROM users WHERE id = ?', [$parentId]);
    }
}

/** Redeem an Upgrade Code (code + PIN) for a member. */
function upgrade_with_code(int $userId, string $code, string $pin): int
{
    return tx(function () use ($userId, $code, $pin) {
        $c = q_row('SELECT * FROM codes WHERE code = ? FOR UPDATE', [strtoupper(trim($code))]);
        if (!$c || !hash_equals((string)$c['pin'], trim($pin))) {
            throw new RuntimeException('Invalid upgrade code or PIN.');
        }
        if ($c['code_type'] !== 'upgrade') {
            throw new RuntimeException('This is an activation code, not an upgrade code. Use it to register a new member.');
        }
        if ($c['status'] !== 'unused') {
            throw new RuntimeException('This upgrade code has already been used or is blocked.');
        }
        $id = upgrade_member($userId, (int)$c['package_id'], 'code', ['code_id' => (int)$c['id'], 'reference' => $c['code'], 'erp_method' => $c['sale_method'] ?: null]);
        q("UPDATE codes SET status = 'used', used_by = ?, used_at = NOW() WHERE id = ?", [$userId, $c['id']]);
        return $id;
    });
}
