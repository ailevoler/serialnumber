<?php
/**
 * RBNW Save Money — a main savings account plus optional goals ("pockets"),
 * all stored in rbnw_savings_goals. Money moves wallet <-> pocket in one DB
 * transaction together with the wallet ledger row.
 */

require_once __DIR__ . '/functions.php';

function savings_main(int $userId): array
{
    $stmt = db()->prepare("SELECT * FROM rbnw_savings_goals WHERE user_id = ? AND is_main = 1 LIMIT 1");
    $stmt->execute([$userId]);
    if ($row = $stmt->fetch()) {
        return $row;
    }
    db()->prepare(
        "INSERT INTO rbnw_savings_goals (user_id, plan_id, is_main, goal_name, last_accrual_date)
         VALUES (?, ?, 1, 'My Savings', CURDATE())"
    )->execute([$userId, SAVINGS_MAIN_PLAN_ID]);
    return savings_main($userId);
}

/** Active pockets (main first) with their plan's rate. */
function savings_pockets(int $userId): array
{
    savings_main($userId);
    $stmt = db()->prepare(
        "SELECT g.*, p.name AS plan_name, p.interest_rate, p.min_deposit
         FROM rbnw_savings_goals g JOIN rbnw_savings_plans p ON p.id = g.plan_id
         WHERE g.user_id = ? AND g.status = 'active'
         ORDER BY g.is_main DESC, g.id"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Credit interest earned since last_accrual_date on a pocket row already
 * locked FOR UPDATE by the caller. Daily compounding at the plan's p.a.
 * rate; 20% final tax withheld. Days are only consumed once at least
 * ₱0.01 net is credited, so small balances still earn over time.
 */
function savings_accrue_locked(PDO $pdo, array $pocket, float $ratePa): array
{
    $today = new DateTimeImmutable('today');
    $last  = $pocket['last_accrual_date'] ? new DateTimeImmutable($pocket['last_accrual_date']) : $today;
    $days  = (int)$last->diff($today)->format('%r%a');
    $bal   = (float)$pocket['current_balance'];

    if ($days <= 0 || $bal <= 0 || $ratePa <= 0) {
        if ($bal <= 0 && $days > 0) {
            // nothing to earn on an empty pocket; just move the marker
            $pdo->prepare("UPDATE rbnw_savings_goals SET last_accrual_date = CURDATE() WHERE id = ?")->execute([$pocket['id']]);
            $pocket['last_accrual_date'] = $today->format('Y-m-d');
        }
        return $pocket;
    }

    $gross = $bal * (pow(1 + $ratePa / 100 / 365, $days) - 1);
    $tax   = round($gross * SAVINGS_WTAX_PCT / 100, 2);
    $net   = round($gross - $tax, 2);
    if ($net < 0.01) {
        return $pocket;
    }

    $newBal = round($bal + $net, 2);
    $pdo->prepare(
        "UPDATE rbnw_savings_goals
         SET current_balance = ?, interest_earned = interest_earned + ?, last_accrual_date = CURDATE() WHERE id = ?"
    )->execute([$newBal, $net, $pocket['id']]);
    $pdo->prepare(
        "INSERT INTO rbnw_savings_transactions (goal_id, reference_no, type, amount, tax_withheld, balance_after)
         VALUES (?, ?, 'interest', ?, ?, ?)"
    )->execute([$pocket['id'], generate_reference('SI'), $net, $tax, $newBal]);

    $pocket['current_balance'] = $newBal;
    $pocket['interest_earned'] = (float)$pocket['interest_earned'] + $net;
    $pocket['last_accrual_date'] = $today->format('Y-m-d');
    return $pocket;
}

/** Bring every active pocket of a user up to date. */
function savings_accrue_all(int $userId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT g.*, p.interest_rate FROM rbnw_savings_goals g JOIN rbnw_savings_plans p ON p.id = g.plan_id
             WHERE g.user_id = ? AND g.status = 'active' FOR UPDATE"
        );
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $p) {
            savings_accrue_locked($pdo, $p, (float)$p['interest_rate']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Move money between the wallet and a pocket.
 * $direction: 'deposit' (wallet -> savings) or 'withdraw' (savings -> wallet).
 */
function savings_move(int $userId, int $pocketId, string $direction, float $amount): array
{
    $amount = round($amount, 2);
    if ($amount <= 0) {
        throw new RuntimeException('Enter a valid amount.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT g.*, p.interest_rate, p.min_deposit FROM rbnw_savings_goals g
             JOIN rbnw_savings_plans p ON p.id = g.plan_id
             WHERE g.id = ? AND g.user_id = ? AND g.status = 'active' FOR UPDATE"
        );
        $stmt->execute([$pocketId, $userId]);
        $pocket = $stmt->fetch();
        if (!$pocket) {
            throw new RuntimeException('Savings account not found.');
        }

        $pocket = savings_accrue_locked($pdo, $pocket, (float)$pocket['interest_rate']);
        $bal    = (float)$pocket['current_balance'];
        $wallet = get_wallet($userId);
        $ref    = generate_reference($direction === 'deposit' ? 'SD' : 'SW');
        $label  = $pocket['is_main'] ? 'Savings' : 'Goal: ' . $pocket['goal_name'];

        if ($direction === 'deposit') {
            if ($amount < (float)$pocket['min_deposit']) {
                throw new RuntimeException('Minimum deposit is ' . peso((float)$pocket['min_deposit']) . '.');
            }
            // throws 'Insufficient balance.' when the wallet can't cover it
            post_wallet_transaction((int)$wallet['id'], 'debit', $amount, 'savings_deposit', [
                'reference_no' => $ref, 'counterparty_name' => 'RBNW Save Money', 'note' => $label,
            ]);
            $newBal = round($bal + $amount, 2);
        } elseif ($direction === 'withdraw') {
            if ($amount > $bal) {
                throw new RuntimeException('You can withdraw up to ' . peso($bal) . '.');
            }
            post_wallet_transaction((int)$wallet['id'], 'credit', $amount, 'savings_withdrawal', [
                'reference_no' => $ref, 'counterparty_name' => 'RBNW Save Money', 'note' => $label,
            ]);
            $newBal = round($bal - $amount, 2);
        } else {
            throw new InvalidArgumentException('Invalid direction.');
        }

        $pdo->prepare("UPDATE rbnw_savings_goals SET current_balance = ? WHERE id = ?")->execute([$newBal, $pocketId]);
        $pdo->prepare(
            "INSERT INTO rbnw_savings_transactions (goal_id, reference_no, type, amount, balance_after)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$pocketId, $ref, $direction === 'deposit' ? 'deposit' : 'withdraw', $amount, $newBal]);

        $pdo->commit();
        return ['reference_no' => $ref, 'balance' => $newBal, 'amount' => $amount];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
