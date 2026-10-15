<?php
declare(strict_types=1);

/**
 * ERP Finance — double-entry general ledger with SAP-style document numbers and types.
 *
 * Every money movement in the MLM system posts a balanced journal entry automatically:
 *   DR  sales (package/product)      Dr cash/bank/PayMongo/wallet payable   Cr sales
 *   CM  commission credited          Dr commission expense                  Cr members' wallet payable
 *   ZP  payout requested             Dr members' wallet payable             Cr payouts payable
 *   ZP  payout released              Dr payouts payable                     Cr bank, Cr payout fee income
 *   ZP  payout rejected              Dr payouts payable                     Cr members' wallet payable
 *   WA  admin wallet adjustment      Dr wallet adjustments / wallet payable Cr wallet payable / other income
 *   KR  expense                      Dr expense account                     Cr cash/bank
 *   SA  manual journal entry,  AB  reversal
 */

function erp_doc_types(): array
{
    return [
        'SA' => 'G/L Journal',
        'DR' => 'Sales',
        'CM' => 'Commission',
        'ZP' => 'Payout',
        'WA' => 'Wallet Adjustment',
        'KR' => 'Expense',
        'AB' => 'Reversal',
    ];
}

function erp_on(): bool
{
    return setting('erp_enabled', '1') === '1';
}

/** Resolve an account by setting key (acct_*) or by account code. */
function gl_account_id(string $keyOrCode): int
{
    static $cache = [];
    $code = str_starts_with($keyOrCode, 'acct_') ? (string)setting($keyOrCode, '') : $keyOrCode;
    if (!isset($cache[$code])) {
        $id = q_val('SELECT id FROM gl_accounts WHERE code = ?', [$code]);
        if (!$id) {
            throw new RuntimeException("G/L account '{$keyOrCode}' is not set up. Check ERP → Chart of Accounts → Account Mapping.");
        }
        $cache[$code] = (int)$id;
    }
    return $cache[$code];
}

/** G/L account (setting key) that receives money for a payment method. */
function erp_payment_account(string $method): string
{
    return [
        'wallet'   => 'acct_wallet_payable',
        'paymongo' => 'acct_paymongo',
        'cash'     => 'acct_cash',
        'bank'     => 'acct_bank',
        'gcash'    => 'acct_ewallet',
        'maya'     => 'acct_ewallet',
    ][$method] ?? 'acct_cash';
}

/**
 * Post a balanced journal entry. $lines: list of [account key|code, debit, credit, memo?, user_id?].
 * Automatic postings are skipped when the ERP is switched off; $manual entries are always posted.
 * Returns the entry id (or null when skipped).
 */
function erp_post(string $docType, string $memo, array $lines, ?string $refType = null, ?int $refId = null, ?string $date = null, bool $manual = false): ?int
{
    if (!$manual && !erp_on()) {
        return null;
    }
    $date = $date ?: date('Y-m-d');
    $lock = (string)setting('erp_lock_date', '');
    if ($lock !== '' && $date <= $lock) {
        if ($manual) {
            throw new RuntimeException("The period up to {$lock} is closed. Use a later posting date.");
        }
        $date = date('Y-m-d', strtotime($lock . ' +1 day'));
    }
    $rows = [];
    $dr = $cr = 0.0;
    foreach ($lines as $l) {
        $d = round((float)($l[1] ?? 0), 2);
        $c = round((float)($l[2] ?? 0), 2);
        if ($d < 0 || $c < 0) {
            throw new RuntimeException('Debit and credit amounts cannot be negative.');
        }
        if ($d == 0.0 && $c == 0.0) {
            continue;
        }
        $rows[] = [gl_account_id((string)$l[0]), $d, $c, $l[3] ?? null, $l[4] ?? null];
        $dr += $d;
        $cr += $c;
    }
    if (count($rows) < 2 || $dr <= 0) {
        if ($manual) {
            throw new RuntimeException('A journal entry needs at least one debit and one credit line.');
        }
        return null;
    }
    if (abs($dr - $cr) > 0.004) {
        throw new RuntimeException(sprintf('Entry is not balanced (debit %.2f ≠ credit %.2f).', $dr, $cr));
    }
    return tx(function () use ($docType, $memo, $rows, $refType, $refId, $date, $dr) {
        $uid = $_SESSION['uid'] ?? null;
        q('INSERT INTO journal_entries (doc_no, doc_type, posting_date, memo, ref_type, ref_id, total, created_by) VALUES (?,?,?,?,?,?,?,?)',
            ['T' . random_string(10), $docType, $date, mb_substr($memo, 0, 255), $refType, $refId, round($dr, 2), $uid]);
        $id = (int)db()->lastInsertId();
        q('UPDATE journal_entries SET doc_no = ? WHERE id = ?', [(string)(1000000000 + $id), $id]);
        foreach ($rows as [$acc, $d, $c, $m, $u]) {
            q('INSERT INTO journal_lines (entry_id, account_id, debit, credit, memo, user_id) VALUES (?,?,?,?,?,?)',
                [$id, $acc, $d, $c, $m !== null ? mb_substr((string)$m, 0, 255) : null, $u]);
        }
        return $id;
    });
}

/** Reverse a posted entry with an AB document (swapped debits/credits). */
function erp_reverse(int $entryId, ?string $date = null): int
{
    return tx(function () use ($entryId, $date) {
        $je = q_row('SELECT * FROM journal_entries WHERE id = ? FOR UPDATE', [$entryId]);
        if (!$je || $je['status'] !== 'posted' || $je['doc_type'] === 'AB') {
            throw new RuntimeException('Only posted entries can be reversed.');
        }
        $lines = [];
        foreach (q_all('SELECT l.*, a.code FROM journal_lines l JOIN gl_accounts a ON a.id = l.account_id WHERE l.entry_id = ?', [$entryId]) as $l) {
            $lines[] = [$l['code'], $l['credit'], $l['debit'], $l['memo'], $l['user_id']];
        }
        $id = erp_post('AB', 'Reversal of ' . $je['doc_no'] . ': ' . $je['memo'], $lines, 'reversal', $entryId, $date, true);
        q("UPDATE journal_entries SET status = 'reversed' WHERE id = ?", [$entryId]);
        q('UPDATE journal_entries SET reversal_of = ? WHERE id = ?', [$entryId, $id]);
        return $id;
    });
}

/** Balances per account: [account_id => ['debit' => x, 'credit' => y]] for an optional date range. */
function gl_totals(?string $from = null, ?string $to = null): array
{
    $where = ['1=1'];
    $p = [];
    if ($from) { $where[] = 'e.posting_date >= ?'; $p[] = $from; }
    if ($to) { $where[] = 'e.posting_date <= ?'; $p[] = $to; }
    $out = [];
    foreach (q_all('SELECT l.account_id, SUM(l.debit) d, SUM(l.credit) c FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
                    WHERE ' . implode(' AND ', $where) . ' GROUP BY l.account_id', $p) as $r) {
        $out[(int)$r['account_id']] = ['debit' => (float)$r['d'], 'credit' => (float)$r['c']];
    }
    return $out;
}

/** Signed balance in the account's normal direction (assets/expenses debit, others credit). */
function gl_balance(string $type, float $debit, float $credit): float
{
    return in_array($type, ['asset', 'expense'], true) ? $debit - $credit : $credit - $debit;
}

function gl_accounts_with_balances(?string $from = null, ?string $to = null): array
{
    $tot = gl_totals($from, $to);
    $rows = q_all('SELECT * FROM gl_accounts ORDER BY code');
    foreach ($rows as &$a) {
        $t = $tot[(int)$a['id']] ?? ['debit' => 0.0, 'credit' => 0.0];
        $a['debit'] = $t['debit'];
        $a['credit'] = $t['credit'];
        $a['balance'] = gl_balance($a['type'], $t['debit'], $t['credit']);
    }
    return $rows;
}

/* ---------- Automatic postings used by the MLM engine ---------- */

function erp_post_commission(int $earningId, int $userId, string $type, float $amount, string $desc): void
{
    erp_post('CM', $desc, [
        ['acct_comm_' . $type, $amount, 0, null, $userId],
        ['acct_wallet_payable', 0, $amount, null, $userId],
    ], 'earning', $earningId);
}

function erp_post_order_sale(array $order): void
{
    erp_post('DR', "Product sale {$order['order_no']} (" . strtoupper($order['payment_method']) . ')', [
        [erp_payment_account($order['payment_method']), $order['total'], 0, null, $order['user_id']],
        ['acct_product_sales', 0, $order['total'], null, $order['user_id']],
    ], 'order', (int)$order['id']);
}

function erp_post_code_sale(string $method, float $amount, string $memo, ?int $userId, ?string $refType = null, ?int $refId = null): void
{
    erp_post('DR', $memo, [
        [erp_payment_account($method), $amount, 0, null, $userId],
        ['acct_package_sales', 0, $amount, null, $userId],
    ], $refType, $refId);
}
