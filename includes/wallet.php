<?php
declare(strict_types=1);

/**
 * Credit or debit a member's e-wallet atomically and record the ledger entry.
 * Returns the new balance. Throws when a debit would make the balance negative.
 */
function wallet_post(int $userId, string $direction, string $category, float $amount, string $description, ?string $reference = null): float
{
    $amount = round($amount, 2);
    if ($amount <= 0) {
        throw new InvalidArgumentException('Amount must be greater than zero.');
    }
    return tx(function () use ($userId, $direction, $category, $amount, $description, $reference) {
        $bal = q_val('SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE', [$userId]);
        if ($bal === null) {
            throw new RuntimeException('Member not found.');
        }
        $new = $direction === 'credit' ? (float)$bal + $amount : (float)$bal - $amount;
        if ($new < -0.004) {
            throw new RuntimeException('Insufficient wallet balance.');
        }
        q('UPDATE users SET wallet_balance = ? WHERE id = ?', [$new, $userId]);
        q('INSERT INTO wallet_transactions (user_id, direction, category, amount, balance_after, reference, description) VALUES (?,?,?,?,?,?,?)',
            [$userId, $direction, $category, $amount, $new, $reference, mb_substr($description, 0, 255)]);
        return $new;
    });
}

function wallet_credit(int $userId, string $category, float $amount, string $description, ?string $reference = null): float
{
    return wallet_post($userId, 'credit', $category, $amount, $description, $reference);
}

function wallet_debit(int $userId, string $category, float $amount, string $description, ?string $reference = null): float
{
    return wallet_post($userId, 'debit', $category, $amount, $description, $reference);
}

function payout_fee(float $amount): float
{
    $pct = (float)setting('payout_fee_percent', 0);
    $fixed = (float)setting('payout_fee_fixed', 0);
    return round($amount * $pct / 100 + $fixed, 2);
}
