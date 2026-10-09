<?php
declare(strict_types=1);

require_once __DIR__ . '/ledger.php';

/** Pay QR: move Credits from one Ultimate App account to another. */
const QR_PAY_MIN_CENTAVOS = 100;        // 1.00 Credit
const QR_PAY_MAX_CENTAVOS = 1000000;    // 10,000.00 Credits per payment

function qr_pay_normalize_code(string $value): ?string
{
    $value = strtoupper(trim($value));
    return preg_match('/^UA-[A-F0-9]{10}$/D', $value) ? $value : null;
}

/** Accepts a scanned payload: a Pay QR link, or a bare UA- code. Returns the code or null. */
function qr_pay_code_from_payload(string $payload): ?string
{
    $payload = trim($payload);
    if ($code = qr_pay_normalize_code($payload)) return $code;
    $query = parse_url($payload, PHP_URL_QUERY);
    if (!is_string($query)) return null;
    parse_str($query, $params);
    return is_string($params['to'] ?? null) ? qr_pay_normalize_code($params['to']) : null;
}

/** Privacy-friendly name: first name plus last-name initial, e.g. "Angelo T.". */
function qr_display_name(string $fullName): string
{
    $parts = preg_split('/\s+/u', trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$parts) return 'Ultimate App user';
    $first = $parts[0];
    if (count($parts) === 1) return $first;
    $last = $parts[count($parts) - 1];
    return $first . ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.';
}

function qr_pay_find_user(PDO $pdo, string $code): ?array
{
    $stmt = $pdo->prepare("SELECT id, full_name, qr_code, role FROM users WHERE qr_code = ? AND status = 'active'");
    $stmt->execute([$code]);
    return $stmt->fetch() ?: null;
}

function qr_pay_amount(string $input): int
{
    $input = trim($input);
    if (!preg_match('/^[1-9][0-9]{0,4}(?:\.[0-9]{1,2})?$/D', $input)) {
        throw new InvalidArgumentException('Enter an amount from 1 to 10,000 Credits, with up to two decimal places.');
    }
    [$whole, $fraction] = array_pad(explode('.', $input, 2), 2, '');
    $centavos = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    if ($centavos < QR_PAY_MIN_CENTAVOS || $centavos > QR_PAY_MAX_CENTAVOS) {
        throw new InvalidArgumentException('Enter an amount from 1 to 10,000 Credits.');
    }
    return $centavos;
}

function qr_pay_note(string $note): ?string
{
    $note = trim(preg_replace('/\s+/u', ' ', $note) ?? '');
    if ($note === '') return null;
    if (mb_strlen($note) > 140) throw new InvalidArgumentException('Keep the note under 140 characters.');
    return $note;
}

/**
 * Transfers Credits atomically. Idempotent per (payer, request key): a repeated submit
 * returns the original reference and never charges twice.
 */
function qr_pay_transfer(PDO $pdo, int $payerId, int $payeeId, int $centavos, string $requestKey, ?string $note): string
{
    if ($payerId === $payeeId) throw new InvalidArgumentException('You cannot pay your own QR code.');
    require_once __DIR__ . '/kyc.php';
    kyc_require_verified($pdo, 'user', $payerId, 'sending Credits');
    if ($centavos < QR_PAY_MIN_CENTAVOS || $centavos > QR_PAY_MAX_CENTAVOS || !preg_match('/^[a-f0-9]{64}$/D', $requestKey)) {
        throw new InvalidArgumentException('Invalid payment request.');
    }
    $credits = sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100);
    $pdo->beginTransaction();
    try {
        // Lock both accounts in id order so two opposite payments cannot deadlock.
        $stmt = $pdo->prepare('SELECT id, full_name, credits, status FROM users WHERE id IN (?, ?) ORDER BY id FOR UPDATE');
        $stmt->execute([$payerId, $payeeId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) $rows[(int) $row['id']] = $row;
        if (!isset($rows[$payerId], $rows[$payeeId]) || ($rows[$payeeId]['status'] ?? 'active') !== 'active') throw new InvalidArgumentException('This QR code is no longer valid.');
        if (($rows[$payerId]['status'] ?? 'active') !== 'active') throw new InvalidArgumentException('Your account cannot make payments right now.');

        $stmt = $pdo->prepare('SELECT reference FROM qr_payments WHERE payer_id = ? AND request_key = ?');
        $stmt->execute([$payerId, $requestKey]);
        if ($existing = $stmt->fetch()) {
            $pdo->commit();
            return $existing['reference'];
        }

        $stmt = $pdo->prepare('UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?');
        $stmt->execute([$credits, $payerId, $credits]);
        if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Insufficient Credits for this payment.');
        $stmt = $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?');
        $stmt->execute([$credits, $payeeId]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Recipient wallet update failed.');

        $reference = 'QP-' . strtoupper(bin2hex(random_bytes(8)));
        $stmt = $pdo->prepare('INSERT INTO qr_payments (reference, request_key, payer_id, payee_id, credits, note) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$reference, $requestKey, $payerId, $payeeId, $credits, $note]);
        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, type, title, amount, status) VALUES (?, 'payment', ?, ?, 'Completed')");
        $stmt->execute([$payerId, 'Paid ' . qr_display_name($rows[$payeeId]['full_name']) . ' via QR ' . $reference, '-' . $credits]);
        $stmt->execute([$payeeId, 'Received from ' . qr_display_name($rows[$payerId]['full_name']) . ' via QR ' . $reference, $credits]);
        ledger_post($pdo, $reference, 'p2p_payment', [
            ['user_credits:' . $payerId, $centavos, 0, 'P2P sent'],
            ['user_credits:' . $payeeId, 0, $centavos, 'P2P received'],
        ]);
        $pdo->commit();
        return $reference;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
