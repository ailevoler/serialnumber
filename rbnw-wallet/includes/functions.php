<?php
/**
 * RBNW WALLET - shared business-logic helpers
 * Included by every page/api after config.php
 */

require_once __DIR__ . '/../config.php';

function get_user(int $userId): ?array
{
    $stmt = db()->prepare("SELECT * FROM rbnw_users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function get_wallet(int $userId): ?array
{
    $stmt = db()->prepare("SELECT * FROM rbnw_wallets WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $wallet = $stmt->fetch();
    return $wallet ?: null;
}

function get_recent_transactions(int $userId, int $limit = 5): array
{
    $stmt = db()->prepare(
        "SELECT t.* FROM rbnw_wallet_transactions t
         INNER JOIN rbnw_wallets w ON w.id = t.wallet_id
         WHERE w.user_id = ?
         ORDER BY t.created_at DESC
         LIMIT ?"
    );
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Atomically debit/credit a wallet and write a matching ledger row.
 * $amount is always positive; $direction is 'debit' or 'credit'.
 * Returns the new balance. Throws on insufficient funds (debit only).
 */
function post_wallet_transaction(
    int $walletId,
    string $direction,
    float $amount,
    string $type,
    array $meta = []
): array {
    $pdo = db();
    // Join a caller's transaction if one is open (e.g. loan disbursement),
    // otherwise run in our own.
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }

    try {
        // Lock the wallet row so concurrent requests can't race the balance
        $stmt = $pdo->prepare("SELECT balance FROM rbnw_wallets WHERE id = ? FOR UPDATE");
        $stmt->execute([$walletId]);
        $wallet = $stmt->fetch();

        if (!$wallet) {
            throw new RuntimeException('Wallet not found.');
        }

        $before = (float)$wallet['balance'];
        $fee    = (float)($meta['fee'] ?? 0);

        if ($direction === 'debit') {
            $totalOut = $amount + $fee;
            if ($totalOut > $before) {
                throw new RuntimeException('Insufficient balance.');
            }
            $after = $before - $totalOut;
        } elseif ($direction === 'credit') {
            $after = $before + $amount;
        } else {
            throw new InvalidArgumentException('Invalid direction.');
        }

        $pdo->prepare("UPDATE rbnw_wallets SET balance = ? WHERE id = ?")
            ->execute([$after, $walletId]);

        $reference = $meta['reference_no'] ?? generate_reference();

        $pdo->prepare(
            "INSERT INTO rbnw_wallet_transactions
                (reference_no, wallet_id, type, amount, fee, balance_before, balance_after,
                 counterparty_user_id, counterparty_name, counterparty_mobile, note, status, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
        )->execute([
            $reference,
            $walletId,
            $type,
            $amount,
            $fee,
            $before,
            $after,
            $meta['counterparty_user_id'] ?? null,
            $meta['counterparty_name'] ?? null,
            $meta['counterparty_mobile'] ?? null,
            $meta['note'] ?? null,
            'success',
        ]);

        if ($ownTx) {
            $pdo->commit();
        }

        return ['reference_no' => $reference, 'balance_before' => $before, 'balance_after' => $after];
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Credit a wallet for a completed Cash In (PayMongo QR Ph) request.
 *
 * Idempotent and safe to call from both the client status-poll endpoint
 * (api/top-up.php) and the PayMongo webhook (topup-webhook.php) — a
 * `FOR UPDATE` lock on the request row means only one caller ever wins
 * the race and credits the wallet. Returns true if it actually credited,
 * false if the request was already paid (nothing to do).
 */
function credit_cash_in(array $req): bool
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("SELECT * FROM rbnw_cash_in_requests WHERE id = ? FOR UPDATE");
        $stmt->execute([$req['id']]);
        $locked = $stmt->fetch();

        if (!$locked || $locked['status'] === 'paid') {
            $pdo->commit();
            return false; // already credited by the other caller
        }

        $stmt = $pdo->prepare("SELECT balance FROM rbnw_wallets WHERE id = ? FOR UPDATE");
        $stmt->execute([$locked['wallet_id']]);
        $wallet = $stmt->fetch();

        if (!$wallet) {
            throw new RuntimeException('Wallet not found for cash in credit.');
        }

        $before = (float)$wallet['balance'];
        $after  = $before + (float)$locked['amount'];

        $pdo->prepare("UPDATE rbnw_wallets SET balance = ? WHERE id = ?")
            ->execute([$after, $locked['wallet_id']]);

        $pdo->prepare(
            "INSERT INTO rbnw_wallet_transactions
                (reference_no, wallet_id, type, amount, fee, balance_before, balance_after, note, status, created_at)
             VALUES (?, ?, 'cash_in', ?, 0, ?, ?, ?, 'success', NOW())"
        )->execute([
            $locked['reference_no'],
            $locked['wallet_id'],
            $locked['amount'],
            $before,
            $after,
            'PayMongo QR Ph — total paid ' . peso((float)$locked['total_charged']),
        ]);

        $pdo->prepare("UPDATE rbnw_cash_in_requests SET status = 'paid', paid_at = NOW() WHERE id = ?")
            ->execute([$locked['id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    push_notification(
        (int)$locked['user_id'],
        'Cash In Successful',
        'Your RBNW Wallet was credited ' . peso((float)$locked['amount']) . ' via QR Ph.',
        'cash_in'
    );

    return true;
}

function push_notification(int $userId, string $title, string $message, string $type = 'general'): void
{
    db()->prepare(
        "INSERT INTO rbnw_notifications (user_id, title, message, type) VALUES (?,?,?,?)"
    )->execute([$userId, $title, $message, $type]);
}

function time_ago_ph(string $datetime): string
{
    $d = new DateTime($datetime);
    $now = new DateTime('now');
    if ($d->format('Y-m-d') === $now->format('Y-m-d')) {
        return 'Today, ' . $d->format('h:i A');
    }
    $yesterday = (clone $now)->modify('-1 day');
    if ($d->format('Y-m-d') === $yesterday->format('Y-m-d')) {
        return 'Yesterday, ' . $d->format('h:i A');
    }
    return $d->format('M d, Y - h:i A');
}

/**
 * Pay a pending/viewed money request. Locks the request row so it can
 * only ever be paid once, even under concurrent taps. Debits the payer,
 * credits the requester, and marks the request 'paid'.
 *
 * Returns ['ok' => bool, 'message' => string, ...payment details]
 */
function pay_money_request(string $referenceNo, int $payerId, string $mpin): array
{
    $payer = get_user($payerId);
    if (!$payer || !password_verify($mpin, $payer['mpin_hash'])) {
        return ['ok' => false, 'message' => 'Incorrect MPIN.'];
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("SELECT * FROM rbnw_money_requests WHERE reference_no = ? FOR UPDATE");
        $stmt->execute([$referenceNo]);
        $req = $stmt->fetch();

        if (!$req) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Request not found.'];
        }
        if (!in_array($req['status'], ['pending', 'viewed'], true)) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This request is no longer payable (' . $req['status'] . ').'];
        }
        if ((int)$req['requester_id'] === $payerId) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'You cannot pay your own request.'];
        }
        if ($req['requestee_id'] && (int)$req['requestee_id'] !== $payerId) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This request was sent to another RBNW Wallet user.'];
        }
        if ($req['due_date'] && strtotime($req['due_date']) < strtotime('today')) {
            $pdo->prepare("UPDATE rbnw_money_requests SET status = 'expired' WHERE id = ?")->execute([$req['id']]);
            $pdo->commit();
            return ['ok' => false, 'message' => 'This request has expired.'];
        }

        $payerWallet     = get_wallet($payerId);
        $requesterWallet = get_wallet((int)$req['requester_id']);
        if (!$payerWallet || !$requesterWallet) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Wallet setup error. Please contact support.'];
        }

        $amount = (float)$req['amount'];
        if ($amount > (float)$payerWallet['balance']) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Insufficient balance.'];
        }

        // Lock + move both wallets within this same transaction
        $stmt = $pdo->prepare("SELECT balance FROM rbnw_wallets WHERE id = ? FOR UPDATE");
        $stmt->execute([$payerWallet['id']]);
        $payerBefore = (float)$stmt->fetchColumn();
        $payerAfter  = $payerBefore - $amount;
        $pdo->prepare("UPDATE rbnw_wallets SET balance = ? WHERE id = ?")->execute([$payerAfter, $payerWallet['id']]);

        $stmt = $pdo->prepare("SELECT balance FROM rbnw_wallets WHERE id = ? FOR UPDATE");
        $stmt->execute([$requesterWallet['id']]);
        $reqBefore = (float)$stmt->fetchColumn();
        $reqAfter  = $reqBefore + $amount;
        $pdo->prepare("UPDATE rbnw_wallets SET balance = ? WHERE id = ?")->execute([$reqAfter, $requesterWallet['id']]);

        $requester = get_user((int)$req['requester_id']);
        $requesterName = trim($requester['first_name'] . ' ' . $requester['last_name']);
        $payerName = trim($payer['first_name'] . ' ' . $payer['last_name']);
        $note = 'Request payment' . ($req['note'] ? ' — ' . $req['note'] : '');

        $pdo->prepare(
            "INSERT INTO rbnw_wallet_transactions
                (reference_no, wallet_id, type, amount, fee, balance_before, balance_after,
                 counterparty_user_id, counterparty_name, counterparty_mobile, note, status, created_at)
             VALUES (?,?,?,?,0,?,?,?,?,?,?,'success',NOW())"
        )->execute([
            $req['reference_no'], $payerWallet['id'], 'send', $amount, $payerBefore, $payerAfter,
            $req['requester_id'], $requesterName, $requester['mobile_number'], $note,
        ]);

        $pdo->prepare(
            "INSERT INTO rbnw_wallet_transactions
                (reference_no, wallet_id, type, amount, fee, balance_before, balance_after,
                 counterparty_user_id, counterparty_name, counterparty_mobile, note, status, created_at)
             VALUES (?,?,?,?,0,?,?,?,?,?,?,'success',NOW())"
        )->execute([
            $req['reference_no'] . '-R', $requesterWallet['id'], 'receive', $amount, $reqBefore, $reqAfter,
            $payerId, $payerName, $payer['mobile_number'], $note,
        ]);

        $pdo->prepare("UPDATE rbnw_money_requests SET status = 'paid', paid_at = NOW() WHERE id = ?")
            ->execute([$req['id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    push_notification(
        (int)$req['requester_id'],
        'Request Paid',
        $payerName . ' paid your request of ' . peso($amount) . '.',
        'request_paid'
    );

    return [
        'ok'              => true,
        'amount'          => $amount,
        'requester_name'  => $requesterName,
        'reference_no'    => $req['reference_no'],
        'new_balance'     => $payerAfter,
    ];
}

function tx_display_meta(string $type): array
{
    // [label, icon-class, is_negative]
    return match ($type) {
        'send', 'cash_out', 'bill_payment', 'loan_repayment', 'savings_deposit', 'donation', 'remittance_out', 'bank_transfer'
            => ['sign' => '-', 'class' => 'tx-out'],
        default => ['sign' => '+', 'class' => 'tx-in'],
    };
}

/**
 * Display label for a ledger type. Wallet funding is always called
 * "Cash In" in the UI, including legacy 'top_up' rows.
 */
function tx_type_label(string $type): string
{
    return match ($type) {
        'cash_in', 'top_up' => 'Cash In',
        'loan_disbursement' => 'Loan Received',
        'loan_repayment'    => 'Loan Payment',
        'bank_transfer'        => 'Bank Transfer',
        'bank_transfer_refund' => 'Bank Transfer Refund',
        'savings_deposit'    => 'Moved to Savings',
        'savings_withdrawal' => 'From Savings',
        default             => ucwords(str_replace('_', ' ', $type)),
    };
}

/**
 * Single source for the service tiles on the dashboard and All Services.
 * key => [href, Font Awesome icon, label]
 */
function services_catalog(): array
{
    return [
        'send'     => ['send-money.php',    'fa-paper-plane',          'Send Money'],
        'cashin'   => ['top-up.php',        'fa-wallet',               'Cash In'],
        'bills'    => ['pay-bills.php',     'fa-file-invoice',         'Pay Bills'],
        'loans'    => ['loans.php',         'fa-hand-holding-dollar',  'Loans'],
        'save'     => ['save-money.php',    'fa-piggy-bank',           'Save Money'],
        'request'  => ['request-money.php', 'fa-money-bill-transfer',  'Request Money'],
        'bank'     => ['bank-wallet.php',   'fa-building-columns',     'Bank Wallet'],
        'donate'   => ['donations.php',     'fa-heart',                'Donations'],
        'cashout'  => ['cash-out.php',      'fa-money-bill-wave',      'Cash Out'],
        'scan'     => ['scan-qr.php',       'fa-qrcode',               'Scan QR'],
        'history'  => ['transactions.php',  'fa-receipt',              'Transactions'],
        'notif'    => ['notifications.php', 'fa-bell',                 'Notifications'],
        'more'     => ['services.php',      'fa-grip',                 'More'],
    ];
}

function render_service_tiles(array $keys): void
{
    $all = services_catalog();
    foreach ($keys as $key) {
        [$href, $icon, $label] = $all[$key];
        echo '<a class="service-item" href="' . htmlspecialchars($href) . '">'
           . '<div class="icon-tile"><i class="fa-solid ' . $icon . '"></i></div>'
           . '<span>' . htmlspecialchars($label) . '</span></a>' . "\n";
    }
}
