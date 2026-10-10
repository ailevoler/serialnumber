<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/ledger.php';

/** Customer-to-merchant (P2M) payments, refunds and merchant settlements. */

function p2m_source_label(string $source): string
{
    return $source === 'boracay_cash' ? 'BCash' : 'Credits';
}

function p2m_limits(): array
{
    return [setting_int('p2m.min_centavos', 100), setting_int('p2m.max_centavos', 5000000)];
}

/** Charge breakdown for an amount the merchant asked for. */
function p2m_quote(int $amountCentavos, string $source): array
{
    [$min, $max] = p2m_limits();
    if ($amountCentavos < $min || $amountCentavos > $max) {
        throw new InvalidArgumentException('Enter an amount from ' . peso($min) . ' to ' . peso($max) . '.');
    }
    $rule = p2m_fee_rule($source);
    if (!$rule['enabled']) throw new InvalidArgumentException('Paying merchants with ' . p2m_source_label($source) . ' is turned off right now.');
    $fee = p2m_fee_for($amountCentavos, $rule);
    if ($rule['bearer'] === 'merchant' && $fee >= $amountCentavos) throw new InvalidArgumentException('Amount is too small for the merchant charge.');
    return [
        'source' => $source, 'amount' => $amountCentavos, 'fee' => $fee, 'bearer' => $rule['bearer'],
        'customer_debit' => $amountCentavos + ($rule['bearer'] === 'customer' ? $fee : 0),
        'merchant_net' => $amountCentavos - ($rule['bearer'] === 'merchant' ? $fee : 0),
    ];
}

function p2m_amount(string $input): int
{
    $input = trim($input);
    if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $input)) throw new InvalidArgumentException('Enter a valid amount, with up to two decimal places.');
    [$whole, $fraction] = array_pad(explode('.', $input, 2), 2, '');
    return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
}

function p2m_pay(PDO $pdo, int $userId, int $merchantId, string $source, int $amountCentavos, string $requestKey, ?string $note): string
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $requestKey)) throw new InvalidArgumentException('Invalid payment request.');
    $quote = p2m_quote($amountCentavos, $source);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, full_name, credits, status FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || ($user['status'] ?? 'active') !== 'active') throw new InvalidArgumentException('Your account cannot make payments right now.');

        $stmt = $pdo->prepare('SELECT reference FROM merchant_payments WHERE user_id = ? AND request_key = ?');
        $stmt->execute([$userId, $requestKey]);
        if ($existing = $stmt->fetch()) { $pdo->commit(); return $existing['reference']; }

        $stmt = $pdo->prepare('SELECT id, business_name, status FROM merchants WHERE id = ? FOR UPDATE');
        $stmt->execute([$merchantId]);
        $merchant = $stmt->fetch();
        if (!$merchant || $merchant['status'] !== 'approved') throw new InvalidArgumentException('This merchant cannot accept payments right now.');

        $debit = $quote['customer_debit'];
        if ($source === 'credits') {
            $stmt = $pdo->prepare('UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?');
            $stmt->execute([centavos_to_decimal($debit), $userId, centavos_to_decimal($debit)]);
            if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Insufficient Credits for this payment.');
        } else {
            $stmt = $pdo->prepare('UPDATE boracay_cash_wallets SET balance_centavos = balance_centavos - ? WHERE user_id = ? AND balance_centavos >= ?');
            $stmt->execute([$debit, $userId, $debit]);
            if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Insufficient BCash for this payment.');
        }
        $pdo->prepare('UPDATE merchants SET balance_centavos = balance_centavos + ? WHERE id = ?')->execute([$quote['merchant_net'], $merchantId]);

        $reference = 'MP-' . strtoupper(bin2hex(random_bytes(8)));
        $stmt = $pdo->prepare('INSERT INTO merchant_payments (reference, request_key, user_id, merchant_id, source, amount_centavos, fee_centavos, fee_bearer, customer_debit_centavos, merchant_net_centavos, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$reference, $requestKey, $userId, $merchantId, $source, $quote['amount'], $quote['fee'], $quote['bearer'], $debit, $quote['merchant_net'], $note]);
        if ($source === 'credits') {
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, type, title, amount, status) VALUES (?, 'payment', ?, ?, 'Completed')");
            $stmt->execute([$userId, 'Paid ' . mb_substr($merchant['business_name'], 0, 80) . ' ' . $reference, '-' . centavos_to_decimal($debit)]);
        }
        $userAccount = ($source === 'credits' ? 'user_credits:' : 'user_cash:') . $userId;
        ledger_post($pdo, $reference, 'p2m_payment', [
            [$userAccount, $debit, 0, 'Customer paid merchant #' . $merchantId],
            ['merchant_payable:' . $merchantId, 0, $quote['merchant_net'], 'Net to merchant'],
            ['fee_revenue:p2m_' . ($source === 'credits' ? 'credits' : 'cash'), 0, $quote['fee'], 'P2M charge (' . $quote['bearer'] . ')'],
        ]);
        $pdo->commit();
        return $reference;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** Full refund of an unsettled payment: the customer gets back everything they paid, including the charge. */
function p2m_refund(PDO $pdo, int $paymentId, int $adminId): string
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM merchant_payments WHERE id = ? FOR UPDATE');
        $stmt->execute([$paymentId]);
        $p = $stmt->fetch();
        if (!$p) throw new InvalidArgumentException('Payment not found.');
        if ($p['status'] !== 'completed') throw new InvalidArgumentException('This payment was already refunded.');
        $stmt = $pdo->prepare('UPDATE merchants SET balance_centavos = balance_centavos - ? WHERE id = ? AND balance_centavos >= ?');
        $stmt->execute([$p['merchant_net_centavos'], $p['merchant_id'], $p['merchant_net_centavos']]);
        if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Merchant balance is lower than this payment (it may already be settled). Recover funds from the merchant first.');
        $debit = (int) $p['customer_debit_centavos'];
        if ($p['source'] === 'credits') {
            $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')->execute([centavos_to_decimal($debit), $p['user_id']]);
            $pdo->prepare("INSERT INTO transactions (user_id, type, title, amount, status) VALUES (?, 'payment', ?, ?, 'Completed')")
                ->execute([$p['user_id'], 'Refund ' . $p['reference'], centavos_to_decimal($debit)]);
        } else {
            $pdo->prepare('INSERT IGNORE INTO boracay_cash_wallets (user_id) VALUES (?)')->execute([$p['user_id']]);
            $pdo->prepare('UPDATE boracay_cash_wallets SET balance_centavos = balance_centavos + ? WHERE user_id = ?')->execute([$debit, $p['user_id']]);
        }
        $pdo->prepare("UPDATE merchant_payments SET status = 'refunded', refunded_at = NOW(), refunded_by = ? WHERE id = ?")->execute([$adminId, $paymentId]);
        $userAccount = ($p['source'] === 'credits' ? 'user_credits:' : 'user_cash:') . $p['user_id'];
        ledger_post($pdo, 'RF-' . substr($p['reference'], 3), 'p2m_refund', [
            ['merchant_payable:' . $p['merchant_id'], (int) $p['merchant_net_centavos'], 0, 'Refund ' . $p['reference']],
            ['fee_revenue:p2m_' . ($p['source'] === 'credits' ? 'credits' : 'cash'), (int) $p['fee_centavos'], 0, 'Charge reversed'],
            [$userAccount, 0, $debit, 'Refund to customer'],
        ]);
        $pdo->commit();
        return $p['reference'];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** Moves a merchant's whole available balance into a pending payout. */
function settlement_create(PDO $pdo, int $merchantId, int $adminId): string
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM merchants WHERE id = ? FOR UPDATE');
        $stmt->execute([$merchantId]);
        $m = $stmt->fetch();
        if (!$m) throw new InvalidArgumentException('Merchant not found.');
        $amount = (int) $m['balance_centavos'];
        if ($amount <= 0) throw new InvalidArgumentException('This merchant has no balance to settle.');
        if (!$m['bank_account_no']) throw new InvalidArgumentException('Add the merchant bank details before settling.');
        $pdo->prepare('UPDATE merchants SET balance_centavos = balance_centavos - ? WHERE id = ?')->execute([$amount, $merchantId]);
        // MCTC cash the merchant collected for Ultimate App is deducted from this payout first.
        if ((int) ($m['mctc_due_centavos'] ?? 0) > 0) {
            require_once __DIR__ . '/mctc_merchant.php';
            $offset = mctc_offset_from_payout($pdo, $m, $amount, $adminId ?: null);
            $amount -= $offset;
            if ($amount <= 0) { $pdo->commit(); return 'fully offset by MCTC cash due (₱' . peso($offset) . ')'; }
        }
        $reference = 'ST-' . strtoupper(bin2hex(random_bytes(6)));
        $bank = trim(($m['bank_name'] ?? '') . ' · ' . ($m['bank_account_name'] ?? '') . ' · ' . $m['bank_account_no']);
        $pdo->prepare('INSERT INTO settlements (reference, merchant_id, amount_centavos, bank_snapshot, created_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$reference, $merchantId, $amount, mb_substr($bank, 0, 255), $adminId]);
        ledger_post($pdo, $reference, 'settlement_created', [
            ['merchant_payable:' . $merchantId, $amount, 0, 'Balance moved to payout'],
            ['settlement_payable:' . $merchantId, 0, $amount, 'Awaiting bank transfer'],
        ]);
        $pdo->commit();
        return $reference;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** Marks a pending payout as paid (with bank reference) or cancels it (money returns to merchant balance). */
function settlement_close(PDO $pdo, int $settlementId, string $action, string $payoutReference, int $adminId): void
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM settlements WHERE id = ? FOR UPDATE');
        $stmt->execute([$settlementId]);
        $s = $stmt->fetch();
        if (!$s || $s['status'] !== 'pending') throw new InvalidArgumentException('Only pending settlements can be updated.');
        $amount = (int) $s['amount_centavos'];
        if ($action === 'paid') {
            $payoutReference = trim($payoutReference);
            if (!preg_match('/^[A-Za-z0-9._\/ -]{4,80}$/D', $payoutReference)) throw new InvalidArgumentException('Enter the bank transfer reference.');
            $pdo->prepare("UPDATE settlements SET status = 'paid', payout_reference = ?, closed_by = ?, closed_at = NOW() WHERE id = ?")->execute([$payoutReference, $adminId, $settlementId]);
            ledger_post($pdo, $s['reference'], 'settlement_paid', [
                ['settlement_payable:' . $s['merchant_id'], $amount, 0, 'Paid out'],
                ['bank_clearing', 0, $amount, 'Bank ref ' . $payoutReference],
            ]);
        } elseif ($action === 'cancel') {
            $pdo->prepare("UPDATE settlements SET status = 'cancelled', closed_by = ?, closed_at = NOW() WHERE id = ?")->execute([$adminId, $settlementId]);
            $pdo->prepare('UPDATE merchants SET balance_centavos = balance_centavos + ? WHERE id = ?')->execute([$amount, $s['merchant_id']]);
            ledger_post($pdo, $s['reference'], 'settlement_cancelled', [
                ['settlement_payable:' . $s['merchant_id'], $amount, 0, 'Payout cancelled'],
                ['merchant_payable:' . $s['merchant_id'], 0, $amount, 'Returned to balance'],
            ]);
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** Admin credit/debit adjustment on a customer's Credits (with reason, fully ledgered). */
function admin_adjust_credits(PDO $pdo, int $userId, int $centavos, string $reason, int $adminId): string
{
    $reason = trim($reason);
    if ($centavos === 0 || abs($centavos) > 100000000) throw new InvalidArgumentException('Enter a non-zero amount.');
    if (mb_strlen($reason) < 5) throw new InvalidArgumentException('Enter a reason (at least 5 characters).');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) throw new InvalidArgumentException('Customer not found.');
        $value = centavos_to_decimal(abs($centavos));
        if ($centavos > 0) {
            $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')->execute([$value, $userId]);
        } else {
            $stmt = $pdo->prepare('UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?');
            $stmt->execute([$value, $userId, $value]);
            if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Customer does not have enough Credits for this deduction.');
        }
        $reference = 'AJ-' . strtoupper(bin2hex(random_bytes(6)));
        $pdo->prepare("INSERT INTO transactions (user_id, type, title, amount, status) VALUES (?, 'reward', ?, ?, 'Completed')")
            ->execute([$userId, 'Account adjustment ' . $reference, ($centavos < 0 ? '-' : '') . $value]);
        ledger_post($pdo, $reference, 'admin_adjustment', $centavos > 0
            ? [['adjustments', $centavos, 0, mb_substr($reason, 0, 150)], ['user_credits:' . $userId, 0, $centavos, 'Credit adjustment']]
            : [['user_credits:' . $userId, -$centavos, 0, 'Debit adjustment'], ['adjustments', 0, -$centavos, mb_substr($reason, 0, 150)]]);
        $pdo->commit();
        return $reference;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
