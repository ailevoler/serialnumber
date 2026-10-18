<?php
/**
 * RBNW Loans — pricing, eligibility and schedule helpers.
 * Product rules live in config.php (LOAN_* constants).
 */

require_once __DIR__ . '/functions.php';

const LOAN_PURPOSES = ['Business', 'Education', 'Medical', 'Home improvement', 'Personal', 'Emergency'];

function loan_credit_limit(array $user): float
{
    $limits = LOAN_LIMIT_BY_LEVEL;
    $level  = (int)$user['wallet_level'];
    return (float)($limits[$level] ?? reset($limits));
}

/** ['eligible' => bool, 'reason' => string|null, 'limit' => float] */
function loan_eligibility(array $user): array
{
    $limit = loan_credit_limit($user);
    if (LOAN_REQUIRE_KYC && $user['kyc_status'] !== 'verified') {
        return ['eligible' => false, 'reason' => 'kyc', 'limit' => $limit];
    }
    if (get_active_loan((int)$user['id'])) {
        return ['eligible' => false, 'reason' => 'active_loan', 'limit' => $limit];
    }
    return ['eligible' => true, 'reason' => null, 'limit' => $limit];
}

function get_active_loan(int $userId): ?array
{
    $stmt = db()->prepare("SELECT * FROM rbnw_loans WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function get_loan_schedule(int $loanId): array
{
    $stmt = db()->prepare("SELECT * FROM rbnw_loan_schedule WHERE loan_id = ? ORDER BY installment_no");
    $stmt->execute([$loanId]);
    return $stmt->fetchAll();
}

/**
 * Add-on interest quote. Interest = principal x monthly rate x term, paid in
 * equal monthly installments; the processing fee is deducted up front, so
 * the borrower receives net_proceeds. The EIR is the IRR of what is actually
 * received vs. what is repaid (what the Truth in Lending disclosure needs).
 */
function loan_quote(float $amount, int $term, ?DateTimeImmutable $start = null): array
{
    $rate          = LOAN_MONTHLY_RATE_PCT / 100;
    $totalInterest = round($amount * $rate * $term, 2);
    $totalPayable  = round($amount + $totalInterest, 2);
    $monthly       = round($totalPayable / $term, 2);
    $fee           = round($amount * LOAN_PROCESSING_FEE_PCT / 100, 2);
    $net           = round($amount - $fee, 2);

    $start = $start ?? new DateTimeImmutable('today');
    $schedule = [];
    $principalLeft = $amount;
    $interestLeft  = $totalInterest;
    for ($i = 1; $i <= $term; $i++) {
        $last      = $i === $term;
        $principal = $last ? round($principalLeft, 2) : round($amount / $term, 2);
        $interest  = $last ? round($interestLeft, 2) : round($totalInterest / $term, 2);
        $principalLeft -= $principal;
        $interestLeft  -= $interest;
        $schedule[] = [
            'installment_no' => $i,
            'due_date'       => loan_add_months($start, $i)->format('Y-m-d'),
            'principal'      => $principal,
            'interest'       => $interest,
            'amount_due'     => round($principal + $interest, 2),
        ];
    }

    $eirMonthly = loan_irr_monthly($net, array_column($schedule, 'amount_due'));

    return [
        'amount'                => round($amount, 2),
        'term_months'           => $term,
        'monthly_rate_pct'      => LOAN_MONTHLY_RATE_PCT,
        'processing_fee_pct'    => LOAN_PROCESSING_FEE_PCT,
        'processing_fee'        => $fee,
        'net_proceeds'          => $net,
        'monthly_amortization'  => $monthly,
        'total_interest'        => $totalInterest,
        'total_payable'         => $totalPayable,
        'finance_charge'        => round($totalInterest + $fee, 2),
        'eir_monthly_pct'       => round($eirMonthly * 100, 2),
        'eir_annual_pct'        => round($eirMonthly * 12 * 100, 2),
        'first_due_date'        => $schedule[0]['due_date'],
        'schedule'              => $schedule,
    ];
}

/** Same day next month(s), clamped to month end (Jan 31 + 1 -> Feb 28/29). */
function loan_add_months(DateTimeImmutable $d, int $n): DateTimeImmutable
{
    $first = $d->modify('first day of this month')->modify("+{$n} months");
    $day   = min((int)$d->format('j'), (int)$first->format('t'));
    return $first->setDate((int)$first->format('Y'), (int)$first->format('n'), $day);
}

/** Monthly IRR r such that sum(payment_i / (1+r)^i) = received. */
function loan_irr_monthly(float $received, array $payments): float
{
    $lo = 0.0;
    $hi = 1.0;
    for ($k = 0; $k < 100; $k++) {
        $mid = ($lo + $hi) / 2;
        $pv = 0.0;
        foreach ($payments as $i => $p) {
            $pv += $p / pow(1 + $mid, $i + 1);
        }
        if ($pv > $received) { $lo = $mid; } else { $hi = $mid; }
    }
    return ($lo + $hi) / 2;
}

function loan_outstanding(array $loan): float
{
    return round((float)$loan['total_payable'] - (float)$loan['amount_paid'], 2);
}
