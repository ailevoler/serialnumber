<?php
require_once __DIR__ . '/../includes/loans.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$userId = current_user_id();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$user   = get_user($userId);

function loan_payload(array $loan): array
{
    $schedule = get_loan_schedule((int)$loan['id']);
    $next = null;
    foreach ($schedule as $row) {
        if ($row['status'] !== 'paid') { $next = $row; break; }
    }
    $paidCount = count(array_filter($schedule, fn($r) => $r['status'] === 'paid'));
    return [
        'reference_no'         => $loan['reference_no'],
        'status'               => $loan['status'],
        'amount'               => (float)$loan['amount'],
        'term_months'          => (int)$loan['term_months'],
        'purpose'              => $loan['purpose'],
        'monthly_amortization' => (float)$loan['monthly_amortization'],
        'total_payable'        => (float)$loan['total_payable'],
        'amount_paid'          => (float)$loan['amount_paid'],
        'outstanding'          => loan_outstanding($loan),
        'paid_installments'    => $paidCount,
        'next_due'             => $next ? [
            'installment_no' => (int)$next['installment_no'],
            'due_date'       => $next['due_date'],
            'amount'         => round((float)$next['amount_due'] - (float)$next['amount_paid'], 2),
            'overdue'        => $next['due_date'] < date('Y-m-d'),
        ] : null,
        'schedule' => array_map(fn($r) => [
            'installment_no' => (int)$r['installment_no'],
            'due_date'       => $r['due_date'],
            'amount_due'     => (float)$r['amount_due'],
            'status'         => $r['status'],
        ], $schedule),
        'disbursed_at' => $loan['disbursed_at'],
    ];
}

function require_valid_mpin(array $user, string $mpin): void
{
    if ($mpin === '' || empty($user['mpin_hash']) || !password_verify($mpin, $user['mpin_hash'])) {
        json_out(['success' => false, 'message' => 'Incorrect MPIN.']);
    }
}

switch ($action) {

    case 'summary':
        $elig   = loan_eligibility($user);
        $active = get_active_loan($userId);
        $stmt = db()->prepare(
            "SELECT reference_no, amount, term_months, status, disbursed_at, completed_at
             FROM rbnw_loans WHERE user_id = ? AND status <> 'active' ORDER BY id DESC LIMIT 5"
        );
        $stmt->execute([$userId]);
        json_out([
            'success'     => true,
            'eligible'    => $elig['eligible'],
            'reason'      => $elig['reason'],
            'limit'       => $elig['limit'],
            'min_amount'  => LOAN_MIN_AMOUNT,
            'terms'       => LOAN_TERMS,
            'purposes'    => LOAN_PURPOSES,
            'active_loan' => $active ? loan_payload($active) : null,
            'history'     => $stmt->fetchAll(),
        ]);
        break;

    case 'quote':
        $amount = round((float)($input['amount'] ?? 0), 2);
        $term   = (int)($input['term'] ?? 0);
        $limit  = loan_credit_limit($user);
        if ($amount < LOAN_MIN_AMOUNT || $amount > $limit) {
            json_out(['success' => false, 'message' => 'Choose an amount from ' . peso(LOAN_MIN_AMOUNT) . ' to ' . peso($limit) . '.']);
        }
        if (!in_array($term, LOAN_TERMS, true)) {
            json_out(['success' => false, 'message' => 'Choose a valid term.']);
        }
        json_out(['success' => true, 'quote' => loan_quote($amount, $term)]);
        break;

    case 'apply':
        $amount  = round((float)($input['amount'] ?? 0), 2);
        $term    = (int)($input['term'] ?? 0);
        $purpose = trim((string)($input['purpose'] ?? ''));
        $agreed  = !empty($input['agreed']);
        $mpin    = trim((string)($input['mpin'] ?? ''));

        $elig = loan_eligibility($user);
        if (!$elig['eligible']) {
            $msg = $elig['reason'] === 'kyc'
                ? 'Verify your account first to apply for a loan.'
                : 'You still have an active loan. Pay it off to apply again.';
            json_out(['success' => false, 'message' => $msg]);
        }
        if ($amount < LOAN_MIN_AMOUNT || $amount > $elig['limit']) {
            json_out(['success' => false, 'message' => 'Choose an amount from ' . peso(LOAN_MIN_AMOUNT) . ' to ' . peso($elig['limit']) . '.']);
        }
        if (!in_array($term, LOAN_TERMS, true)) {
            json_out(['success' => false, 'message' => 'Choose a valid term.']);
        }
        if (!in_array($purpose, LOAN_PURPOSES, true)) {
            json_out(['success' => false, 'message' => 'Choose the purpose of your loan.']);
        }
        if (!$agreed) {
            json_out(['success' => false, 'message' => 'Please agree to the Disclosure Statement and Loan Agreement.']);
        }
        require_valid_mpin($user, $mpin);

        $wallet = get_wallet($userId);
        $q      = loan_quote($amount, $term);
        $ref    = generate_reference('LN');
        $pdo    = db();

        try {
            $pdo->beginTransaction();

            // Re-check under lock so a double tap can't open two loans
            $stmt = $pdo->prepare("SELECT id FROM rbnw_loans WHERE user_id = ? AND status = 'active' FOR UPDATE");
            $stmt->execute([$userId]);
            if ($stmt->fetch()) {
                throw new RuntimeException('You still have an active loan.');
            }

            $pdo->prepare(
                "INSERT INTO rbnw_loans
                    (reference_no, user_id, amount, term_months, purpose, monthly_rate, monthly_amortization,
                     total_interest, total_payable, processing_fee, net_proceeds, effective_rate_annual,
                     status, applied_at, approved_at, disbursed_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'active',NOW(),NOW(),NOW())"
            )->execute([
                $ref, $userId, $q['amount'], $term, $purpose, $q['monthly_rate_pct'], $q['monthly_amortization'],
                $q['total_interest'], $q['total_payable'], $q['processing_fee'], $q['net_proceeds'], $q['eir_annual_pct'],
            ]);
            $loanId = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare(
                "INSERT INTO rbnw_loan_schedule (loan_id, installment_no, due_date, principal, interest, amount_due)
                 VALUES (?,?,?,?,?,?)"
            );
            foreach ($q['schedule'] as $row) {
                $ins->execute([$loanId, $row['installment_no'], $row['due_date'], $row['principal'], $row['interest'], $row['amount_due']]);
            }

            $credit = post_wallet_transaction((int)$wallet['id'], 'credit', $q['net_proceeds'], 'loan_disbursement', [
                'reference_no'      => $ref,
                'counterparty_name' => 'RBNW Loans',
                'note'              => "Loan {$ref} ({$term} months), less processing fee " . peso($q['processing_fee']),
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[RBNW] loan apply failed: ' . $e->getMessage());
            $msg = $e instanceof RuntimeException ? $e->getMessage() : 'Loan could not be processed. Please try again.';
            json_out(['success' => false, 'message' => $msg], 500);
        }

        try {
            push_notification($userId, 'Loan approved', peso($q['net_proceeds']) . " was sent to your wallet. First payment of "
                . peso($q['monthly_amortization']) . ' is due ' . date('M j, Y', strtotime($q['first_due_date'])) . '.', 'loan');
        } catch (Throwable $e) {
            error_log('[RBNW] loan notification failed: ' . $e->getMessage());
        }

        json_out([
            'success'        => true,
            'reference_no'   => $ref,
            'net_proceeds'   => $q['net_proceeds'],
            'first_due_date' => $q['first_due_date'],
            'monthly'        => $q['monthly_amortization'],
            'new_balance'    => $credit['balance_after'],
        ]);
        break;

    case 'pay':
        $mode = ($input['mode'] ?? 'next') === 'full' ? 'full' : 'next';
        $mpin = trim((string)($input['mpin'] ?? ''));
        require_valid_mpin($user, $mpin);

        $wallet = get_wallet($userId);
        $pdo    = db();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM rbnw_loans WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $stmt->execute([$userId]);
            $loan = $stmt->fetch();
            if (!$loan) {
                throw new RuntimeException('You have no active loan.');
            }

            $stmt = $pdo->prepare("SELECT * FROM rbnw_loan_schedule WHERE loan_id = ? AND status = 'due' ORDER BY installment_no FOR UPDATE");
            $stmt->execute([$loan['id']]);
            $due = $stmt->fetchAll();
            if (!$due) {
                throw new RuntimeException('Nothing left to pay on this loan.');
            }
            $rows = $mode === 'full' ? $due : [$due[0]];

            $amount = 0.0;
            foreach ($rows as $r) {
                $amount += (float)$r['amount_due'] - (float)$r['amount_paid'];
            }
            $amount = round($amount, 2);

            post_wallet_transaction((int)$wallet['id'], 'debit', $amount, 'loan_repayment', [
                'reference_no'      => generate_reference('LP'),
                'counterparty_name' => 'RBNW Loans',
                'note'              => "Loan {$loan['reference_no']} " . ($mode === 'full' ? 'full payment' : 'installment ' . $rows[0]['installment_no']),
            ]);

            $mark = $pdo->prepare("UPDATE rbnw_loan_schedule SET amount_paid = amount_due, status = 'paid', paid_at = NOW() WHERE id = ?");
            foreach ($rows as $r) {
                $mark->execute([$r['id']]);
            }

            $completed = count($rows) === count($due);
            $pdo->prepare(
                "UPDATE rbnw_loans SET amount_paid = amount_paid + ?, status = ?, completed_at = " . ($completed ? 'NOW()' : 'completed_at') . " WHERE id = ?"
            )->execute([$amount, $completed ? 'completed' : 'active', $loan['id']]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = $e instanceof RuntimeException ? $e->getMessage() : 'Payment failed. Please try again.';
            json_out(['success' => false, 'message' => $msg]);
        }

        try {
            push_notification($userId, $completed ? 'Loan fully paid' : 'Loan payment received',
                $completed ? "Your loan {$loan['reference_no']} is fully paid. Thank you!"
                           : peso($amount) . " received for loan {$loan['reference_no']}.", 'loan');
        } catch (Throwable $e) {
            error_log('[RBNW] loan notification failed: ' . $e->getMessage());
        }

        json_out(['success' => true, 'amount' => $amount, 'completed' => $completed]);
        break;

    default:
        json_out(['success' => false, 'message' => 'Unknown action.'], 404);
}
