<?php
declare(strict_types=1);

function boracay_cash_amount(string $input): int
{
    $input = trim($input);
    if (!preg_match('/^[1-9][0-9]{0,4}(?:\.[0-9]{1,2})?$/D', $input)) {
        throw new InvalidArgumentException('Enter an amount from 1 to 10,000, with up to two decimal places.');
    }
    [$whole, $fraction] = array_pad(explode('.', $input, 2), 2, '');
    $centavos = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    if ($centavos < 100 || $centavos > 1000000) {
        throw new InvalidArgumentException('Enter an amount from 1 to 10,000.');
    }
    return $centavos;
}

function boracay_cash_convert(PDO $pdo, int $userId, int $centavos, string $requestKey, string $direction = 'credits_to_cash'): string
{
    if ($centavos < 100 || $centavos > 1000000 || !preg_match('/^[a-f0-9]{64}$/D', $requestKey)
        || !in_array($direction, ['credits_to_cash', 'cash_to_credits'], true)) {
        throw new InvalidArgumentException('Invalid conversion request.');
    }
    $credits = sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) throw new UnexpectedValueException('Account not found.');

        $stmt = $pdo->prepare('SELECT reference FROM boracay_cash_conversions WHERE request_key = ? AND user_id = ?');
        $stmt->execute([$requestKey, $userId]);
        $existing = $stmt->fetch();
        if ($existing) {
            $pdo->commit();
            return $existing['reference'];
        }

        $stmt = $pdo->prepare('INSERT IGNORE INTO boracay_cash_wallets (user_id) VALUES (?)');
        $stmt->execute([$userId]);
        if ($direction === 'credits_to_cash') {
            $stmt = $pdo->prepare('UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?');
            $stmt->execute([$credits, $userId, $credits]);
            if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Insufficient Credits for this conversion.');
            $stmt = $pdo->prepare('UPDATE boracay_cash_wallets SET balance_centavos = balance_centavos + ? WHERE user_id = ?');
            $stmt->execute([$centavos, $userId]);
        } else {
            $stmt = $pdo->prepare('UPDATE boracay_cash_wallets SET balance_centavos = balance_centavos - ? WHERE user_id = ? AND balance_centavos >= ?');
            $stmt->execute([$centavos, $userId, $centavos]);
            if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Insufficient BCash for this conversion.');
            $stmt = $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?');
            $stmt->execute([$credits, $userId]);
        }
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Wallet update failed.');

        $reference = 'BC-' . strtoupper(bin2hex(random_bytes(8)));
        $stmt = $pdo->prepare('INSERT INTO boracay_cash_conversions (reference, request_key, user_id, direction, credits, cash_centavos) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$reference, $requestKey, $userId, $direction, $credits, $centavos]);
        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, type, title, amount, status) VALUES (?, 'conversion', ?, ?, 'Completed')");
        $stmt->execute([$userId, ($direction === 'credits_to_cash' ? 'Transfer to BCash ' : 'Convert BCash to Credits ') . $reference, ($direction === 'credits_to_cash' ? '-' : '') . $credits]);
        $pdo->commit();
        return $reference;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
