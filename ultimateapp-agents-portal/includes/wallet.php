<?php
declare(strict_types=1);

/**
 * In-app wallets customers pay with: Credits and BCash (both PHP, 1 = ₱1.00).
 * Admin › Settings › Charges › Payment methods: "Pay Cash" (cash to the driver, pay on the day) can be switched off
 * app-wide so customers pay in the app with Credits or BCash.
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/ledger.php';

/** Cash payments (URide cash, "pay on the day" bookings). Off = Credits / BCash only. */
function pay_cash_enabled(): bool
{
    return (string) setting('pay.cash_enabled', '1') === '1';
}

/** BCash as a payment method in URide, UPass, UGo, ULocal and UEat. */
function pay_bcash_enabled(): bool
{
    return (string) setting('pay.bcash_enabled', '1') === '1';
}

function wallet_label(string $source): string
{
    return ['credits' => 'Credits', 'bcash' => 'BCash', 'boracay_cash' => 'BCash', 'cash' => 'Cash', 'on_site' => 'Pay on the day'][$source] ?? ucfirst($source);
}

/** Ledger account of a customer's wallet. */
function wallet_account(string $source, int $userId): string
{
    return ($source === 'bcash' || $source === 'boracay_cash' ? 'user_cash:' : 'user_credits:') . $userId;
}

function wallet_balance(PDO $pdo, int $userId, string $source): int
{
    if ($source === 'bcash') {
        $s = $pdo->prepare('SELECT balance_centavos FROM boracay_cash_wallets WHERE user_id = ?');
        $s->execute([$userId]);
        return (int) $s->fetchColumn();
    }
    $s = $pdo->prepare('SELECT credits FROM users WHERE id = ?');
    $s->execute([$userId]);
    return (int) round((float) $s->fetchColumn() * 100);
}

/** Takes money from a wallet inside the caller's transaction (no ledger lines: the caller posts them). */
function wallet_debit_tx(PDO $pdo, int $userId, string $source, int $amount, string $title, bool $evenWhenOff = false): void
{
    if ($amount <= 0) return;
    if ($source === 'bcash') {
        if (!$evenWhenOff && !pay_bcash_enabled()) throw new InvalidArgumentException('Paying with BCash is turned off right now. Please use Credits.');
        $s = $pdo->prepare('UPDATE boracay_cash_wallets SET balance_centavos = balance_centavos - ? WHERE user_id = ? AND balance_centavos >= ?');
        $s->execute([$amount, $userId, $amount]);
        if ($s->rowCount() !== 1) throw new InvalidArgumentException('Not enough BCash (₱' . peso($amount) . ' needed). Convert Credits to BCash or pay with Credits.');
        return;
    }
    $s = $pdo->prepare('UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?');
    $s->execute([centavos_to_decimal($amount), $userId, centavos_to_decimal($amount)]);
    if ($s->rowCount() !== 1) throw new InvalidArgumentException('Not enough Credits (₱' . peso($amount) . ' needed). Buy Credits' . (pay_bcash_enabled() ? ' or pay with BCash.' : '.'));
    $pdo->prepare("INSERT INTO transactions (user_id, type, title, amount, status) VALUES (?, 'payment', ?, ?, 'Completed')")->execute([$userId, mb_substr($title, 0, 120), '-' . centavos_to_decimal($amount)]);
}

/** Gives money back to a wallet inside the caller's transaction (no ledger lines). */
function wallet_credit_tx(PDO $pdo, int $userId, string $source, int $amount, string $title): void
{
    if ($amount <= 0) return;
    if ($source === 'bcash') {
        $pdo->prepare('INSERT IGNORE INTO boracay_cash_wallets (user_id) VALUES (?)')->execute([$userId]);
        $pdo->prepare('UPDATE boracay_cash_wallets SET balance_centavos = balance_centavos + ? WHERE user_id = ?')->execute([$amount, $userId]);
        return;
    }
    $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')->execute([centavos_to_decimal($amount), $userId]);
    $pdo->prepare("INSERT INTO transactions (user_id, type, title, amount, status) VALUES (?, 'payment', ?, ?, 'Completed')")->execute([$userId, mb_substr($title, 0, 120), centavos_to_decimal($amount)]);
}
