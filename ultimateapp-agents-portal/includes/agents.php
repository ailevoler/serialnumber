<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/ledger.php';

/**
 * Agents Portal: an approved agent shares a referral link (register.php?ref=CODE) or the code itself. Customers who
 * sign up with it are tagged to the agent (users.referred_by_agent_id), and the agent earns a commission on that
 * customer's URide, UPass, UGo, ULocal and UEat activity for agents.earn_days days.
 *
 * When a commission is earned:
 *   URide  - approved as soon as the trip is completed (% of the fare).
 *   UEat   - pending when a Credits order is paid; approved after agents.hold_days if the order was not cancelled,
 *            reversed if it is cancelled / refunded.
 *   UPass, UGo, ULocal - pending when the request is sent (value = listed price x quantity); approved when Admin
 *            marks the request completed, reversed when it is cancelled.
 * Commissions are paid by Ultimate App (expense), never deducted from the customer, driver or merchant.
 * Approved commissions add to agents.balance_centavos; the agent requests a payout from the portal.
 */

const AGENT_SOURCE_TYPES = ['uride', 'service_request', 'ueat'];

function agent_services(): array
{
    return [
        'URide' => ['key' => 'uride', 'label' => 'URide', 'when' => 'Completed trip', 'base' => 'fare'],
        'UPass' => ['key' => 'upass', 'label' => 'UPass', 'when' => 'Completed pass booking', 'base' => 'listed price'],
        'UGo' => ['key' => 'ugo', 'label' => 'UGo', 'when' => 'Completed activity booking', 'base' => 'listed price'],
        'ULocal' => ['key' => 'ulocal', 'label' => 'ULocal', 'when' => 'Completed local service', 'base' => 'listed price'],
        'UEat' => ['key' => 'ueat', 'label' => 'UEat', 'when' => 'Paid food order (not cancelled)', 'base' => 'order total'],
    ];
}

function agent_statuses(): array
{
    return ['pending' => 'Pending review', 'approved' => 'Active', 'suspended' => 'Suspended', 'rejected' => 'Not approved'];
}

function agent_commission_statuses(): array
{
    return ['pending' => 'Pending', 'approved' => 'Earned', 'reversed' => 'Reversed'];
}

function agent_settings(): array
{
    return [
        'enabled' => setting('agents.enabled', '1') === '1',
        'earn_days' => max(0, setting_int('agents.earn_days', 365)),
        'cookie_days' => max(1, setting_int('agents.cookie_days', 30)),
        'hold_days' => max(0, setting_int('agents.hold_days', 3)),
        'payout_min' => max(100, setting_int('agents.payout_min_centavos', 50000)),
    ];
}

/**
 * Rate tiers (Admin > Agents > Commission rates):
 *   direct   - a Master Agent (or independent agent) on their own customers
 *   sub      - a Sub-Agent on their own customers
 *   override - what the Master Agent earns on top, on the customers of their Sub-Agents (% of the activity amount)
 */
const AGENT_RATE_TIERS = ['direct', 'sub', 'override'];

/** [percent in basis points, fixed centavos] for one service and tier. */
function agent_rate(string $service, string $tier = 'direct'): array
{
    $defaults = ['URide' => 200, 'UPass' => 500, 'UGo' => 500, 'ULocal' => 500, 'UEat' => 300];
    $overrideDefaults = ['URide' => 50, 'UPass' => 100, 'UGo' => 100, 'ULocal' => 100, 'UEat' => 50];
    $key = agent_services()[$service]['key'] ?? null;
    if ($key === null || !in_array($tier, AGENT_RATE_TIERS, true)) throw new InvalidArgumentException('Unknown service.');
    $direct = [max(0, setting_int("agents.$key.percent_bp", $defaults[$service])), max(0, setting_int("agents.$key.fixed_centavos", 0))];
    if ($tier === 'direct') return $direct;
    if ($tier === 'sub') return [max(0, setting_int("agents.sub.$key.percent_bp", $direct[0])), max(0, setting_int("agents.sub.$key.fixed_centavos", $direct[1]))];
    return [max(0, setting_int("agents.master.$key.percent_bp", $overrideDefaults[$service])), max(0, setting_int("agents.master.$key.fixed_centavos", 0))];
}

/** Commission on an amount: percent (rounded half up) plus fixed, never more than the amount itself. */
function agent_commission_for(string $service, int $baseCentavos, string $tier = 'direct'): int
{
    if ($baseCentavos <= 0) return 0;
    [$bp, $fixed] = agent_rate($service, $tier);
    return min($baseCentavos, intdiv($baseCentavos * $bp + 5000, 10000) + $fixed);
}

function agent_rate_label(string $service, string $tier = 'direct'): string
{
    [$bp, $fixed] = agent_rate($service, $tier);
    $parts = [];
    if ($bp > 0) $parts[] = rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.') . '%';
    if ($fixed > 0) $parts[] = 'PHP ' . peso($fixed);
    return $parts ? implode(' + ', $parts) : 'None';
}

/* ---------------------------------------------------------------- codes & accounts */

function agent_new_code(PDO $pdo): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($i = 0; $i < 10; $i++) {
        $code = 'AG';
        for ($j = 0; $j < 6; $j++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $stmt = $pdo->prepare('SELECT 1 FROM agents WHERE code = ?');
        $stmt->execute([$code]);
        if (!$stmt->fetch()) return $code;
    }
    throw new RuntimeException('Could not allocate an agent code.');
}

function agent_normalize_code(string $value): ?string
{
    $value = strtoupper(preg_replace('/[\s-]+/', '', $value) ?? '');
    return preg_match('/^AG[A-Z2-9]{6}$/D', $value) ? $value : null;
}

/** An agent whose code can bring in new customers (approved only). */
function agent_find_active_by_code(PDO $pdo, string $code): ?array
{
    $code = agent_normalize_code($code);
    if ($code === null) return null;
    $stmt = $pdo->prepare("SELECT * FROM agents WHERE code = ? AND status = 'approved'");
    $stmt->execute([$code]);
    return $stmt->fetch() ?: null;
}

/** "Master Agent" (top level, can have Sub-Agents) or "Sub-Agent" (belongs to a Master Agent). */
function agent_role(array $agent): string
{
    return !empty($agent['parent_agent_id']) ? 'Sub-Agent' : 'Master Agent';
}

/** An approved top-level agent whose team a new Sub-Agent can join, by code. */
function agent_find_master_by_code(PDO $pdo, string $code): ?array
{
    $code = agent_normalize_code($code);
    if ($code === null) return null;
    $stmt = $pdo->prepare("SELECT * FROM agents WHERE code = ? AND status = 'approved' AND parent_agent_id IS NULL");
    $stmt->execute([$code]);
    return $stmt->fetch() ?: null;
}

/**
 * Admin: put an agent under a Master Agent, or make them a Master Agent again ($masterId null).
 * One level only: a Master cannot be a Sub-Agent while they have their own Sub-Agents.
 */
function agent_set_master(PDO $pdo, int $agentId, ?int $masterId): void
{
    if ($masterId !== null) {
        if ($masterId === $agentId) throw new InvalidArgumentException('An agent cannot be their own Master Agent.');
        $s = $pdo->prepare('SELECT id, parent_agent_id FROM agents WHERE id = ?');
        $s->execute([$masterId]);
        $m = $s->fetch();
        if (!$m) throw new InvalidArgumentException('Master Agent not found.');
        if ($m['parent_agent_id']) throw new InvalidArgumentException('That agent is a Sub-Agent and cannot have a team.');
        $s = $pdo->prepare('SELECT COUNT(*) FROM agents WHERE parent_agent_id = ?');
        $s->execute([$agentId]);
        if ((int) $s->fetchColumn() > 0) throw new InvalidArgumentException('This agent has their own Sub-Agents. Move them first.');
    }
    $pdo->prepare('UPDATE agents SET parent_agent_id = ? WHERE id = ?')->execute([$masterId, $agentId]);
}

function agent_validate_profile(array $in, bool $withPassword): array
{
    $s = static fn(string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
    $name = preg_replace('/\s+/u', ' ', $s('full_name')) ?? '';
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || !preg_match('/^[\p{L} .\'-]+$/uD', $name)) throw new InvalidArgumentException('Enter your full name (letters only).');
    $mobile = preg_replace('/[\s-]/', '', $s('mobile')) ?? '';
    if (!preg_match('/^(?:09\d{9}|\+639\d{9})$/D', $mobile)) throw new InvalidArgumentException('Enter a valid PH mobile number like 09171234567.');
    $email = strtolower($s('email'));
    if (strlen($email) > 160 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
    $barangay = $s('barangay');
    if ($barangay !== '' && !in_array($barangay, agent_barangays(), true)) throw new InvalidArgumentException('Choose a barangay from the list.');
    $data = ['full_name' => $name, 'mobile' => $mobile, 'email' => $email, 'barangay' => $barangay ?: null];
    if ($withPassword) {
        $pw = is_string($in['password'] ?? null) ? $in['password'] : '';
        if (strlen($pw) < 8 || strlen($pw) > 72 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) throw new InvalidArgumentException('Password needs 8+ characters with letters and numbers.');
        if (!hash_equals($pw, is_string($in['password_confirm'] ?? null) ? $in['password_confirm'] : '')) throw new InvalidArgumentException('Passwords do not match.');
        $data['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
    }
    return $data;
}

function agent_barangays(): array
{
    return ['Balabag', 'Manoc-Manoc', 'Yapak', 'Outside Boracay (Malay)', 'Outside Malay'];
}

function agent_payout_methods(): array
{
    return ['GCash', 'Maya', 'BDO', 'BPI', 'Landbank', 'Metrobank', 'PNB', 'Security Bank', 'UnionBank', 'Other bank'];
}

function agent_validate_payout_account(string $method, string $name, string $number): array
{
    $method = trim($method); $name = preg_replace('/\s+/u', ' ', trim($name)) ?? ''; $number = preg_replace('/[^0-9A-Za-z-]/', '', $number) ?? '';
    if (!in_array($method, agent_payout_methods(), true)) throw new InvalidArgumentException('Choose a bank or e-wallet.');
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) throw new InvalidArgumentException('Enter the account name.');
    if (strlen($number) < 6 || strlen($number) > 40) throw new InvalidArgumentException('Enter a valid account / mobile number.');
    return [$method, $name, $number];
}

function agent_mask_name(?string $name): string
{
    $parts = preg_split('/\s+/u', trim((string) $name)) ?: [];
    if (!$parts || $parts[0] === '') return 'Customer';
    $first = $parts[0];
    $last = count($parts) > 1 ? mb_strtoupper(mb_substr((string) end($parts), 0, 1)) . '.' : '';
    return trim($first . ' ' . $last);
}

function agent_mask_account(?string $number): string
{
    if (!$number) return 'Not set';
    return str_repeat('•', max(0, strlen($number) - 4)) . substr($number, -4);
}

/* ---------------------------------------------------------------- referral capture (app side) */

function agent_cookie_path(): string
{
    return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/x')), '/') . '/';
}

/**
 * Remembers ?ref=CODE from a shared link (session + cookie for agents.cookie_days) and counts the click once per
 * browser session. Call on public app pages (index.php, register.php, login.php) before output.
 */
function agent_capture_referral(PDO $pdo): void
{
    $raw = $_GET['ref'] ?? null;
    if (!is_string($raw) || $raw === '') return;
    try {
        $agent = agent_find_active_by_code($pdo, $raw);
        if (!$agent || !agent_settings()['enabled']) return;
        $code = $agent['code'];
        $_SESSION['agent_ref'] = $code;
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        if (!headers_sent()) {
            setcookie('ua_ref', $code, ['expires' => time() + 86400 * agent_settings()['cookie_days'], 'path' => agent_cookie_path(), 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        }
        $_COOKIE['ua_ref'] = $code;
        if (empty($_SESSION['agent_ref_clicked'][$code])) {
            $_SESSION['agent_ref_clicked'][$code] = 1;
            $pdo->prepare('UPDATE agents SET link_clicks = link_clicks + 1 WHERE id = ?')->execute([$agent['id']]);
        }
    } catch (Throwable $error) {
        error_log('Referral capture skipped: ' . get_class($error));
    }
}

/** Referral code waiting to be applied at sign-up (from the link), or ''. */
function agent_pending_referral_code(): string
{
    foreach ([$_SESSION['agent_ref'] ?? null, $_COOKIE['ua_ref'] ?? null] as $value) {
        if (is_string($value) && ($code = agent_normalize_code($value)) !== null) return $code;
    }
    return '';
}

function agent_forget_referral(): void
{
    unset($_SESSION['agent_ref']);
    if (isset($_COOKIE['ua_ref']) && !headers_sent()) {
        setcookie('ua_ref', '', ['expires' => time() - 3600, 'path' => agent_cookie_path(), 'httponly' => true, 'samesite' => 'Lax']);
    }
    unset($_COOKIE['ua_ref']);
}

/**
 * Tags a brand-new customer to the agent. Only once per customer, only approved agents, never the agent's own
 * email or mobile. Returns the agent row when tagged. Never throws: a bad code must not block sign-up.
 */
function agent_attach_referral(PDO $pdo, int $userId, string $code): ?array
{
    try {
        if ($code === '' || !agent_settings()['enabled']) return null;
        $agent = agent_find_active_by_code($pdo, $code);
        if (!$agent) return null;
        $stmt = $pdo->prepare('SELECT email, mobile FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) return null;
        $agentMobile = preg_replace('/^\+63/', '0', (string) $agent['mobile']);
        if (strcasecmp((string) $user['email'], (string) $agent['email']) === 0 || ($user['mobile'] !== '' && $user['mobile'] === $agentMobile)) return null;
        $stmt = $pdo->prepare('UPDATE users SET referred_by_agent_id = ?, referred_at = NOW() WHERE id = ? AND referred_by_agent_id IS NULL');
        $stmt->execute([$agent['id'], $userId]);
        agent_forget_referral();
        return $stmt->rowCount() === 1 ? $agent : null;
    } catch (Throwable $error) {
        error_log('Referral attach skipped: ' . get_class($error));
        return null;
    }
}

/** The agent who earns from this customer's activity right now, or null. */
function agent_for_user(PDO $pdo, int $userId): ?array
{
    $s = agent_settings();
    if (!$s['enabled']) return null;
    $stmt = $pdo->prepare("SELECT a.*, u.referred_at FROM users u JOIN agents a ON a.id = u.referred_by_agent_id WHERE u.id = ? AND a.status = 'approved'");
    $stmt->execute([$userId]);
    $agent = $stmt->fetch();
    if (!$agent) return null;
    if ($s['earn_days'] > 0 && $agent['referred_at'] && strtotime((string) $agent['referred_at']) < time() - 86400 * $s['earn_days']) return null;
    return $agent;
}

/* ---------------------------------------------------------------- commissions */

/**
 * Runs $fn so that a failure can never break the customer's payment / ride / order: inside the caller's transaction
 * it uses a savepoint (rolled back on error), otherwise its own transaction. Errors are logged, not thrown.
 */
function agent_guarded(PDO $pdo, callable $fn): mixed
{
    $outer = $pdo->inTransaction();
    try {
        if ($outer) $pdo->exec('SAVEPOINT agent_commission'); else $pdo->beginTransaction();
        $result = $fn();
        if ($outer) $pdo->exec('RELEASE SAVEPOINT agent_commission'); else $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        try {
            if ($outer) $pdo->exec('ROLLBACK TO SAVEPOINT agent_commission'); elseif ($pdo->inTransaction()) $pdo->rollBack();
        } catch (Throwable $ignored) {}
        error_log('Agent commission skipped: ' . get_class($error) . ' ' . $error->getMessage());
        return null;
    }
}

/**
 * Records the commission for one activity of a referred customer. Idempotent per (source_type, source_id).
 * $approveNow = true for activities that are already final (completed URide trip).
 */
function agent_record_commission(PDO $pdo, int $userId, string $service, string $sourceType, int $sourceId, string $reference, int $baseCentavos, bool $approveNow): void
{
    agent_guarded($pdo, function () use ($pdo, $userId, $service, $sourceType, $sourceId, $reference, $baseCentavos, $approveNow) {
        if (!isset(agent_services()[$service]) || !in_array($sourceType, AGENT_SOURCE_TYPES, true)) throw new InvalidArgumentException('Unknown commission source.');
        $agent = agent_for_user($pdo, $userId);
        if (!$agent) return;
        // A Sub-Agent earns the Sub-Agent rate; their approved Master Agent earns the override on top.
        $master = null;
        if (!empty($agent['parent_agent_id'])) {
            $s = $pdo->prepare("SELECT id FROM agents WHERE id = ? AND status = 'approved' AND parent_agent_id IS NULL");
            $s->execute([$agent['parent_agent_id']]);
            $master = $s->fetch() ?: null;
        }
        $rows = [['direct', (int) $agent['id'], null, empty($agent['parent_agent_id']) ? 'direct' : 'sub']];
        if ($master) $rows[] = ['override', (int) $master['id'], (int) $agent['id'], 'override'];
        $stmt = $pdo->prepare('INSERT IGNORE INTO agent_commissions (agent_id, kind, from_agent_id, user_id, service_code, source_type, source_id, source_reference, base_centavos, rate_bp, fixed_centavos, amount_centavos) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $added = 0;
        foreach ($rows as [$kind, $agentId, $fromId, $tier]) {
            $amount = agent_commission_for($service, $baseCentavos, $tier);
            if ($amount <= 0) continue;
            [$bp, $fixed] = agent_rate($service, $tier);
            $stmt->execute([$agentId, $kind, $fromId, $userId, $service, $sourceType, $sourceId, mb_substr($reference, 0, 40), $baseCentavos, $bp, $fixed, $amount]);
            $added += $stmt->rowCount();
        }
        if ($approveNow && $added > 0) agent_set_commission_status($pdo, $sourceType, $sourceId, 'approved', 'Earned on ' . $reference);
    });
}

/** All commission rows of one activity (the agent's, plus the Master Agent's override if any), locked. */
function agent_lock_commissions(PDO $pdo, string $sourceType, int $sourceId): array
{
    $stmt = $pdo->prepare('SELECT * FROM agent_commissions WHERE source_type = ? AND source_id = ? ORDER BY id FOR UPDATE');
    $stmt->execute([$sourceType, $sourceId]);
    return $stmt->fetchAll();
}

/**
 * Moves an activity's commissions to approved (adds to each agent's balance) or reversed (takes them back if
 * approved). The Sub-Agent's commission and the Master Agent's override always move together.
 * Caller provides the transaction. Returns false when there is nothing to change.
 */
function agent_set_commission_status(PDO $pdo, string $sourceType, int $sourceId, string $to, string $note = ''): bool
{
    $changed = false;
    foreach (agent_lock_commissions($pdo, $sourceType, $sourceId) as $c) {
        if (agent_set_commission_row_status($pdo, $c, $to, $note)) $changed = true;
    }
    return $changed;
}

function agent_set_commission_row_status(PDO $pdo, array $c, string $to, string $note): bool
{
    if ($c['status'] === $to || $c['status'] === 'reversed') return false;
    $amount = (int) $c['amount_centavos'];
    $agentId = (int) $c['agent_id'];
    $pdo->prepare('SELECT id FROM agents WHERE id = ? FOR UPDATE')->execute([$agentId]);
    if ($to === 'approved') {
        if ($c['status'] !== 'pending') return false;
        $pdo->prepare("UPDATE agent_commissions SET status = 'approved', approved_at = NOW(), note = ? WHERE id = ?")->execute([mb_substr($note, 0, 190) ?: null, $c['id']]);
        $pdo->prepare('UPDATE agents SET balance_centavos = balance_centavos + ? WHERE id = ?')->execute([$amount, $agentId]);
        ledger_post($pdo, 'AC-' . $c['id'], 'agent_commission', [
            ['agent_commission_expense', $amount, 0, $c['service_code'] . ' ' . $c['source_reference']],
            ['agent_payable:' . $agentId, 0, $amount, ($c['kind'] ?? 'direct') === 'override' ? 'Team override earned' : 'Commission earned'],
        ]);
        return true;
    }
    if ($to === 'reversed') {
        $pdo->prepare("UPDATE agent_commissions SET status = 'reversed', reversed_at = NOW(), note = ? WHERE id = ?")->execute([mb_substr($note, 0, 190) ?: null, $c['id']]);
        if ($c['status'] === 'approved') {
            // Taken back from the balance; it may go below zero and is then netted against future commissions.
            $pdo->prepare('UPDATE agents SET balance_centavos = balance_centavos - ? WHERE id = ?')->execute([$amount, $agentId]);
            ledger_post($pdo, 'ACR-' . $c['id'], 'agent_commission_reversed', [
                ['agent_payable:' . $agentId, $amount, 0, 'Commission reversed'],
                ['agent_commission_expense', 0, $amount, $c['service_code'] . ' ' . $c['source_reference']],
            ]);
        }
        return true;
    }
    throw new InvalidArgumentException('Unknown commission status.');
}

/** Hook-safe wrappers used by URide / UEat / service requests (never throw). */
function agent_approve_source(PDO $pdo, string $sourceType, int $sourceId, string $note = ''): void
{
    agent_guarded($pdo, fn() => agent_set_commission_status($pdo, $sourceType, $sourceId, 'approved', $note));
}

function agent_reverse_source(PDO $pdo, string $sourceType, int $sourceId, string $note = ''): void
{
    agent_guarded($pdo, fn() => agent_set_commission_status($pdo, $sourceType, $sourceId, 'reversed', $note));
}

/** Approves UEat commissions whose order is still paid and open after the holding period. Returns how many. */
function agent_mature_pending(PDO $pdo, ?int $agentId = null): int
{
    $n = 0;
    try {
        $hold = agent_settings()['hold_days'];
        $sql = "SELECT DISTINCT c.source_id FROM agent_commissions c JOIN ueat_orders o ON o.id = c.source_id
            WHERE c.source_type = 'ueat' AND c.status = 'pending' AND o.payment_status = 'paid' AND o.status <> 'cancelled' AND c.created_at <= NOW() - INTERVAL $hold DAY"
            . ($agentId ? ' AND c.agent_id = ' . (int) $agentId : '') . ' ORDER BY c.source_id LIMIT 200';
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
            if (agent_guarded($pdo, fn() => agent_set_commission_status($pdo, 'ueat', (int) $orderId, 'approved', 'Order not cancelled after ' . $hold . ' day(s)'))) $n++;
        }
    } catch (Throwable $error) {
        error_log('Agent maturing skipped: ' . get_class($error));
    }
    return $n;
}

/* ---------------------------------------------------------------- payouts */

function agent_tx(PDO $pdo, callable $fn): mixed
{
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function agent_payout_request(PDO $pdo, int $agentId, int $amount): string
{
    return agent_tx($pdo, function () use ($pdo, $agentId, $amount) {
        $stmt = $pdo->prepare('SELECT * FROM agents WHERE id = ? FOR UPDATE');
        $stmt->execute([$agentId]);
        $agent = $stmt->fetch();
        if (!$agent || !in_array($agent['status'], ['approved', 'suspended'], true)) throw new InvalidArgumentException('Payouts are available to approved agents.');
        if (!$agent['payout_method'] || !$agent['payout_account_no']) throw new InvalidArgumentException('Add your payout account in Profile first.');
        $min = agent_settings()['payout_min'];
        if ($amount < $min) throw new InvalidArgumentException('Minimum payout is PHP ' . peso($min) . '.');
        if ($amount > (int) $agent['balance_centavos']) throw new InvalidArgumentException('Amount is more than your available balance.');
        $stmt = $pdo->prepare("SELECT 1 FROM agent_payouts WHERE agent_id = ? AND status = 'requested'");
        $stmt->execute([$agentId]);
        if ($stmt->fetch()) throw new InvalidArgumentException('You already have a payout being processed.');
        $reference = 'AP-' . strtoupper(bin2hex(random_bytes(6)));
        $pdo->prepare('UPDATE agents SET balance_centavos = balance_centavos - ? WHERE id = ?')->execute([$amount, $agentId]);
        $pdo->prepare('INSERT INTO agent_payouts (reference, agent_id, amount_centavos, payout_method, payout_account_name, payout_account_no) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$reference, $agentId, $amount, $agent['payout_method'], $agent['payout_account_name'], $agent['payout_account_no']]);
        ledger_post($pdo, $reference, 'agent_payout_request', [
            ['agent_payable:' . $agentId, $amount, 0, 'Payout requested'],
            ['settlement_payable:agent_' . $agentId, 0, $amount, 'Owed to agent'],
        ]);
        return $reference;
    });
}

function agent_payout_decide(PDO $pdo, int $payoutId, string $action, string $bankRef, string $note, int $adminId): array
{
    return agent_tx($pdo, function () use ($pdo, $payoutId, $action, $bankRef, $note, $adminId) {
        $stmt = $pdo->prepare('SELECT * FROM agent_payouts WHERE id = ? FOR UPDATE');
        $stmt->execute([$payoutId]);
        $p = $stmt->fetch();
        if (!$p) throw new InvalidArgumentException('Payout not found.');
        if ($p['status'] !== 'requested') throw new InvalidArgumentException('This payout was already processed.');
        $amount = (int) $p['amount_centavos'];
        $agentId = (int) $p['agent_id'];
        if ($action === 'paid') {
            $bankRef = trim($bankRef);
            if (!preg_match('/^[A-Za-z0-9 ._\/-]{4,80}$/D', $bankRef)) throw new InvalidArgumentException('Enter the bank / e-wallet transfer reference.');
            $pdo->prepare("UPDATE agent_payouts SET status = 'paid', bank_reference = ?, note = ?, processed_by = ?, processed_at = NOW() WHERE id = ?")->execute([$bankRef, mb_substr(trim($note), 0, 300) ?: null, $adminId, $payoutId]);
            ledger_post($pdo, $p['reference'], 'agent_payout_paid', [
                ['settlement_payable:agent_' . $agentId, $amount, 0, 'Paid ' . $bankRef],
                ['bank_clearing', 0, $amount, 'Agent payout'],
            ]);
        } elseif ($action === 'rejected') {
            if (mb_strlen(trim($note)) < 4) throw new InvalidArgumentException('Give the reason for returning this payout.');
            $pdo->prepare('SELECT id FROM agents WHERE id = ? FOR UPDATE')->execute([$agentId]);
            $pdo->prepare("UPDATE agent_payouts SET status = 'rejected', note = ?, processed_by = ?, processed_at = NOW() WHERE id = ?")->execute([mb_substr(trim($note), 0, 300), $adminId, $payoutId]);
            $pdo->prepare('UPDATE agents SET balance_centavos = balance_centavos + ? WHERE id = ?')->execute([$amount, $agentId]);
            ledger_post($pdo, 'RV-' . substr($p['reference'], 3), 'agent_payout_returned', [
                ['settlement_payable:agent_' . $agentId, $amount, 0, 'Payout returned'],
                ['agent_payable:' . $agentId, 0, $amount, 'Back to agent balance'],
            ]);
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
        return $p;
    });
}

/* ---------------------------------------------------------------- reporting */

/** Per-service totals for one agent: [service => [n, earned, pending]]. */
function agent_service_totals(PDO $pdo, int $agentId): array
{
    $out = [];
    foreach (agent_services() as $code => $_) $out[$code] = ['n' => 0, 'earned' => 0, 'pending' => 0];
    $stmt = $pdo->prepare("SELECT service_code, COUNT(*) n, COALESCE(SUM(CASE WHEN status = 'approved' THEN amount_centavos END),0) earned,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN amount_centavos END),0) pending FROM agent_commissions WHERE agent_id = ? AND status <> 'reversed' GROUP BY service_code");
    $stmt->execute([$agentId]);
    foreach ($stmt as $r) $out[$r['service_code']] = ['n' => (int) $r['n'], 'earned' => (int) $r['earned'], 'pending' => (int) $r['pending']];
    return $out;
}
