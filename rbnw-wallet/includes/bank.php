<?php
/**
 * RBNW Bank Wallet — linked bank/e-wallet accounts and wallet -> bank
 * transfers. Money leaves the wallet when the transfer is submitted; RBNW
 * operations then send it out and settle it (completed, or failed with an
 * automatic refund) via bank_transfer_settle().
 */

require_once __DIR__ . '/functions.php';

/** code => [name, short label, type]. 'RBNW' transfers are internal (free). */
function bank_directory(): array
{
    return [
        'RBNW'   => ['Rural Bank of New Washington', 'RBNW', 'bank'],
        'BDO'    => ['BDO Unibank', 'BDO', 'bank'],
        'BPI'    => ['Bank of the Philippine Islands', 'BPI', 'bank'],
        'MBTC'   => ['Metrobank', 'Metrobank', 'bank'],
        'LBP'    => ['Landbank of the Philippines', 'Landbank', 'bank'],
        'PNB'    => ['Philippine National Bank', 'PNB', 'bank'],
        'SECB'   => ['Security Bank', 'Security Bank', 'bank'],
        'UBP'    => ['UnionBank of the Philippines', 'UnionBank', 'bank'],
        'RCBC'   => ['RCBC', 'RCBC', 'bank'],
        'CBC'    => ['China Banking Corporation', 'China Bank', 'bank'],
        'EWB'    => ['EastWest Bank', 'EastWest', 'bank'],
        'PSB'    => ['PSBank', 'PSBank', 'bank'],
        'AUB'    => ['Asia United Bank', 'AUB', 'bank'],
        'DBP'    => ['Development Bank of the Philippines', 'DBP', 'bank'],
        'MAYB'   => ['Maybank Philippines', 'Maybank', 'bank'],
        'CIMB'   => ['CIMB Bank Philippines', 'CIMB', 'bank'],
        'TONIK'  => ['Tonik Digital Bank', 'Tonik', 'bank'],
        'GOTYME' => ['GoTyme Bank', 'GoTyme', 'bank'],
        'MARI'   => ['MariBank', 'MariBank', 'bank'],
        'GCASH'  => ['GCash', 'GCash', 'ewallet'],
        'MAYA'   => ['Maya', 'Maya', 'ewallet'],
        'SHOPEE' => ['ShopeePay', 'ShopeePay', 'ewallet'],
    ];
}

function bank_mask(string $number): string
{
    return '•••• ' . substr($number, -4);
}

function bank_accounts(int $userId): array
{
    $stmt = db()->prepare(
        "SELECT id, bank_name, bank_code, account_number_masked, account_name, is_primary, linked_at
         FROM rbnw_bank_accounts WHERE user_id = ? AND is_linked = 1 ORDER BY is_primary DESC, id"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/** Fee and limits for a channel; null when not allowed for that account. */
function bank_channel_quote(string $bankCode, string $channel, float $amount): array
{
    if ($bankCode === 'RBNW') {
        return ['channel' => 'internal', 'fee' => 0.0, 'max' => BANK_PESONET_MAX, 'eta' => 'Within the day'];
    }
    return $channel === 'pesonet'
        ? ['channel' => 'pesonet', 'fee' => (float)BANK_PESONET_FEE, 'max' => (float)BANK_PESONET_MAX, 'eta' => 'Next banking day']
        : ['channel' => 'instapay', 'fee' => (float)BANK_INSTAPAY_FEE, 'max' => (float)BANK_INSTAPAY_MAX, 'eta' => 'Within minutes'];
}

/**
 * Settle a processing transfer. On 'failed' the amount + fee go back to
 * the wallet. Safe to call twice: only a 'processing' row is changed.
 */
function bank_transfer_settle(string $reference, string $outcome, ?string $reason = null): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM rbnw_bank_transfers WHERE reference_no = ? FOR UPDATE");
        $stmt->execute([$reference]);
        $t = $stmt->fetch();
        if (!$t || $t['status'] !== 'processing') {
            $pdo->rollBack();
            return false;
        }

        if ($outcome === 'failed') {
            $wallet = get_wallet((int)$t['user_id']);
            post_wallet_transaction((int)$wallet['id'], 'credit', round((float)$t['amount'] + (float)$t['fee'], 2), 'bank_transfer_refund', [
                'reference_no'      => $t['reference_no'] . '-RF',
                'counterparty_name' => $t['bank_name'],
                'note'              => 'Refund: ' . ($reason ?: 'transfer could not be completed'),
            ]);
        }
        $pdo->prepare(
            "UPDATE rbnw_bank_transfers SET status = ?, failure_reason = ?, completed_at = NOW() WHERE id = ?"
        )->execute([$outcome === 'failed' ? 'failed' : 'completed', $outcome === 'failed' ? $reason : null, $t['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    try {
        push_notification((int)$t['user_id'],
            $outcome === 'failed' ? 'Bank transfer failed' : 'Bank transfer completed',
            $outcome === 'failed'
                ? peso((float)$t['amount'] + (float)$t['fee']) . " was returned to your wallet. {$t['bank_name']} {$t['account_number_masked']}."
                : peso((float)$t['amount']) . " was sent to {$t['bank_name']} {$t['account_number_masked']}.",
            'bank_transfer');
    } catch (Throwable $e) {
        error_log('[RBNW] bank notification failed: ' . $e->getMessage());
    }
    return true;
}
