<?php
declare(strict_types=1);

/**
 * Cancellation fees and reasons (Admin › Settings › Charges & limits › Cancellation fees).
 * - URide: free before a driver accepts and for a few minutes after; after that (or once the driver has arrived) the
 *   fee is a percentage of the fare and goes to the driver.
 * - UPass / UGo / ULocal: free while waiting for confirmation and until the listing's free-cancellation cut-off;
 *   a late cancellation (after the cut-off, before the start) costs a percentage and goes to the provider.
 * - UEat: free before the restaurant accepts; after it accepts (until the food leaves the kitchen) a percentage goes
 *   to the restaurant.
 * Fees are only taken from payments made in the app (Credits or BCash); the rest is refunded to the same wallet.
 * Every customer cancellation needs a reason.
 */

require_once __DIR__ . '/settings.php';

const CANCEL_AREAS = ['uride', 'booking', 'ueat'];

function cancel_reasons(string $area): array
{
    return [
        'uride' => ['Driver is taking too long', 'Driver asked me to cancel', 'Changed my plans', 'Wrong pick-up or drop-off', 'Booked by mistake', 'Found another ride', 'Other'],
        'booking' => ['Change of plans', 'Weather', 'Health or emergency', 'Booked the wrong date or time', 'Found another option', 'Provider asked me to cancel', 'Other'],
        'ueat' => ['Waiting too long', 'Ordered by mistake', 'Wrong address', 'Changed my mind', 'Restaurant asked me to cancel', 'Other'],
    ][$area] ?? ['Other'];
}

/** Fee percentage (0-100) for an area. */
function cancel_fee_pct(string $area): float
{
    $default = ['uride' => '20', 'booking' => '25', 'ueat' => '30'][$area] ?? '0';
    $v = setting("cancel.{$area}_pct", $default);
    return is_numeric($v) ? max(0.0, min(100.0, (float) $v)) : (float) $default;
}

/** Minutes after a driver accepts during which the passenger can still cancel for free. */
function cancel_uride_free_minutes(): int
{
    return max(0, setting_int('cancel.uride_free_min', 2));
}

/** Fee in centavos for a paid amount (rounded to the centavo). */
function cancel_fee_amount(int $paid, string $area): int
{
    return max(0, min($paid, (int) round($paid * cancel_fee_pct($area) / 100)));
}

/** Validated reason text: one of the presets, or "Other: …" with the customer's words. */
function cancel_reason(string $area, string $reason, string $other = ''): string
{
    $reason = trim($reason); $other = trim(preg_replace('/\s+/u', ' ', $other) ?? '');
    if ($reason === '' && $other !== '') $reason = 'Other';
    if (!in_array($reason, cancel_reasons($area), true)) {
        if (mb_strlen($reason) >= 3) return mb_substr($reason, 0, 200); // API / older apps sending free text
        throw new InvalidArgumentException('Please choose a reason for cancelling.');
    }
    if ($reason === 'Other') {
        if (mb_strlen($other) < 3) throw new InvalidArgumentException('Please tell us why you are cancelling.');
        return mb_substr('Other: ' . $other, 0, 200);
    }
    return $reason;
}

function cancel_pct_label(string $area): string
{
    $p = cancel_fee_pct($area);
    return rtrim(rtrim(number_format($p, 2), '0'), '.') . '%';
}
