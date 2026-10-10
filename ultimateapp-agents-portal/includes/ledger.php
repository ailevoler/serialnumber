<?php
declare(strict_types=1);

/**
 * ERP general ledger. Balances are integer centavos (1 Credit = PHP 1.00).
 * Liability accounts (user_credits, user_cash, merchant_payable, settlement_payable) increase with a credit.
 * Call ledger_post() inside the caller's database transaction so money and books move together.
 */
function ledger_post(PDO $pdo, string $group, string $event, array $lines): void
{
    $debits = 0; $credits = 0;
    foreach ($lines as [$account, $debit, $credit]) {
        if (!preg_match('/^[a-z_]+(?::[a-z0-9_]+)?$/D', $account) || $debit < 0 || $credit < 0) {
            throw new InvalidArgumentException('Invalid ledger line.');
        }
        $debits += $debit; $credits += $credit;
    }
    if ($debits !== $credits || $debits === 0) throw new LogicException('Unbalanced ledger entry ' . $group);
    $stmt = $pdo->prepare('INSERT INTO ledger_entries (entry_group, event, account, debit_centavos, credit_centavos, memo) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($lines as $line) {
        [$account, $debit, $credit] = $line;
        if ($debit === 0 && $credit === 0) continue;
        $stmt->execute([$group, $event, $account, $debit, $credit, isset($line[3]) ? mb_substr((string) $line[3], 0, 190) : null]);
    }
}

function ledger_account_label(string $account): string
{
    [$type, $id] = array_pad(explode(':', $account, 2), 2, null);
    $labels = [
        'user_credits' => 'Customer Credits', 'user_cash' => 'Customer BCash', 'merchant_payable' => 'Merchant payable',
        'settlement_payable' => 'Settlement in transit', 'fee_revenue' => 'Charge revenue', 'bank_clearing' => 'Bank clearing',
        'adjustments' => 'Manual adjustments', 'brgy_payable' => 'Owed to barangay', 'paymongo_clearing' => 'PayMongo clearing',
        'driver_wallet' => 'URide driver wallet', 'mctc_collections' => 'Cash held by MCTC agent', 'office_cash' => 'Cash received at office', 'mctc_merchant_due' => 'MCTC cash due from merchant', 'uride_escrow' => 'URide fares on hold',
        'agent_payable' => 'Owed to agent', 'suniway_payable' => 'Owed to SUNIWAY (bills, load, cash-in)', 'agent_commission_expense' => 'Agent commissions (expense)',
    ];
    $label = $labels[$type] ?? $type;
    return $id !== null ? $label . ' · ' . $id : $label;
}

function centavos_to_decimal(int $centavos): string
{
    $sign = $centavos < 0 ? '-' : '';
    $centavos = abs($centavos);
    return $sign . intdiv($centavos, 100) . '.' . str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
}

function peso(int $centavos): string
{
    return ($centavos < 0 ? '-' : '') . number_format(abs($centavos) / 100, 2);
}
