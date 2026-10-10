<?php
declare(strict_types=1);

require_once __DIR__ . '/ledger.php';
require_once __DIR__ . '/qr_pay.php';
require_once __DIR__ . '/boracay_cash.php';

/** BCash Send / Receive: move BCash (PHP) from one Ultimate App account to another. */
const BCASH_SEND_MIN_CENTAVOS = 100;      // PHP 1.00
const BCASH_SEND_MAX_CENTAVOS = 1000000;  // PHP 10,000.00 per transfer

function bcash_transfers_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $pdo->query('SELECT 1 FROM bcash_transfers LIMIT 0'); $ready = true; } catch (Throwable) { $ready = false; }
    }
    return $ready;
}

function bcash_balance(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT balance_centavos FROM boracay_cash_wallets WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * Sends BCash atomically. Idempotent per (payer, request key): a repeated submit
 * returns the original reference and never charges twice.
 */
function bcash_transfer(PDO $pdo, int $payerId, int $payeeId, int $centavos, string $requestKey, ?string $note): string
{
    if ($payerId === $payeeId) throw new InvalidArgumentException('You cannot send BCash to your own QR code.');
    require_once __DIR__ . '/kyc.php';
    kyc_require_verified($pdo, 'user', $payerId, 'sending BCash');
    if ($centavos < BCASH_SEND_MIN_CENTAVOS || $centavos > BCASH_SEND_MAX_CENTAVOS || !preg_match('/^[a-f0-9]{64}$/D', $requestKey)) {
        throw new InvalidArgumentException('Invalid BCash transfer.');
    }
    $pdo->beginTransaction();
    try {
        // Lock both accounts in id order so two opposite transfers cannot deadlock.
        $stmt = $pdo->prepare('SELECT id, full_name, status FROM users WHERE id IN (?, ?) ORDER BY id FOR UPDATE');
        $stmt->execute([$payerId, $payeeId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) $rows[(int) $row['id']] = $row;
        if (!isset($rows[$payerId], $rows[$payeeId]) || ($rows[$payeeId]['status'] ?? 'active') !== 'active') throw new InvalidArgumentException('This BCash QR code is no longer valid.');
        if (($rows[$payerId]['status'] ?? 'active') !== 'active') throw new InvalidArgumentException('Your account cannot send BCash right now.');

        $stmt = $pdo->prepare('SELECT reference FROM bcash_transfers WHERE payer_id = ? AND request_key = ?');
        $stmt->execute([$payerId, $requestKey]);
        if ($existing = $stmt->fetch()) {
            $pdo->commit();
            return $existing['reference'];
        }

        $pdo->prepare('INSERT IGNORE INTO boracay_cash_wallets (user_id) VALUES (?), (?)')->execute([$payerId, $payeeId]);
        $stmt = $pdo->prepare('UPDATE boracay_cash_wallets SET balance_centavos = balance_centavos - ? WHERE user_id = ? AND balance_centavos >= ?');
        $stmt->execute([$centavos, $payerId, $centavos]);
        if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Not enough BCash for this transfer.');
        $stmt = $pdo->prepare('UPDATE boracay_cash_wallets SET balance_centavos = balance_centavos + ? WHERE user_id = ?');
        $stmt->execute([$centavos, $payeeId]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Recipient BCash wallet update failed.');

        $reference = 'BS-' . strtoupper(bin2hex(random_bytes(8)));
        $stmt = $pdo->prepare('INSERT INTO bcash_transfers (reference, request_key, payer_id, payee_id, amount_centavos, note) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$reference, $requestKey, $payerId, $payeeId, $centavos, $note]);
        ledger_post($pdo, $reference, 'bcash_transfer', [
            ['user_cash:' . $payerId, $centavos, 0, 'BCash sent'],
            ['user_cash:' . $payeeId, 0, $centavos, 'BCash received'],
        ]);
        $pdo->commit();
        return $reference;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** Sent and received transfers for one account, newest first, for the transactions list. */
function bcash_transfer_history(PDO $pdo, int $userId, int $limit = 100): array
{
    if (!bcash_transfers_ready($pdo)) return [];
    $stmt = $pdo->prepare('SELECT t.reference, t.payer_id, t.amount_centavos, t.created_at, payer.full_name AS payer_name, payee.full_name AS payee_name
        FROM bcash_transfers t JOIN users payer ON payer.id = t.payer_id JOIN users payee ON payee.id = t.payee_id
        WHERE t.payer_id = ? OR t.payee_id = ? ORDER BY t.created_at DESC, t.id DESC LIMIT ' . max(1, min(500, $limit)));
    $stmt->execute([$userId, $userId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $sent = (int) $r['payer_id'] === $userId;
        $out[] = [
            'reference' => $r['reference'], 'sent' => $sent, 'created_at' => $r['created_at'],
            'amount_centavos' => $sent ? -(int) $r['amount_centavos'] : (int) $r['amount_centavos'],
            'title' => ($sent ? 'Sent BCash to ' . qr_display_name($r['payee_name']) : 'Received BCash from ' . qr_display_name($r['payer_name'])) . ' ' . $r['reference'],
        ];
    }
    return $out;
}
