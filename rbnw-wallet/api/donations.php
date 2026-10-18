<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$userId = current_user_id();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

/** Supporter display name: "Juan D." or "Anonymous". */
function donor_label(array $r): string
{
    if ($r['is_anonymous']) {
        return 'Anonymous';
    }
    $last = trim((string)$r['last_name']);
    return trim($r['first_name'] . ' ' . ($last !== '' ? mb_substr($last, 0, 1) . '.' : ''));
}

switch ($action) {

    case 'summary':
        $causes = db()->query(
            "SELECT c.*, (SELECT COUNT(DISTINCT d.user_id) FROM rbnw_donations d WHERE d.cause_id = c.id) AS supporters
             FROM rbnw_donation_causes c WHERE c.is_active = 1 ORDER BY c.id"
        )->fetchAll();

        $stmt = db()->prepare(
            "SELECT d.reference_no, d.amount, d.is_anonymous, d.created_at, c.name
             FROM rbnw_donations d JOIN rbnw_donation_causes c ON c.id = d.cause_id
             WHERE d.user_id = ? ORDER BY d.id DESC LIMIT 20"
        );
        $stmt->execute([$userId]);
        $mine = $stmt->fetchAll();

        $stmt = db()->prepare("SELECT COALESCE(SUM(amount),0) total, COUNT(DISTINCT cause_id) causes FROM rbnw_donations WHERE user_id = ?");
        $stmt->execute([$userId]);
        $tot = $stmt->fetch();

        json_out([
            'success'        => true,
            'wallet_balance' => (float)get_wallet($userId)['balance'],
            'min'            => DONATION_MIN,
            'max'            => DONATION_MAX,
            'my_total'       => (float)$tot['total'],
            'my_causes'      => (int)$tot['causes'],
            'causes'         => array_map(fn($c) => [
                'id' => (int)$c['id'], 'name' => $c['name'], 'description' => $c['description'],
                'category' => $c['category'], 'goal' => (float)$c['goal_amount'], 'raised' => (float)$c['raised_amount'],
                'supporters' => (int)$c['supporters'],
                'image' => $c['image'] ? asset_url($c['image']) : null,
            ], $causes),
            'mine' => array_map(fn($d) => [
                'reference_no' => $d['reference_no'], 'amount' => (float)$d['amount'], 'cause' => $d['name'],
                'anonymous' => (bool)$d['is_anonymous'], 'when' => time_ago_ph($d['created_at']),
            ], $mine),
        ]);
        break;

    case 'supporters':
        $stmt = db()->prepare(
            "SELECT d.amount, d.is_anonymous, d.message, d.created_at, u.first_name, u.last_name
             FROM rbnw_donations d JOIN rbnw_users u ON u.id = d.user_id
             WHERE d.cause_id = ? ORDER BY d.id DESC LIMIT 10"
        );
        $stmt->execute([(int)($_GET['cause_id'] ?? 0)]);
        json_out(['success' => true, 'supporters' => array_map(fn($r) => [
            'name'    => donor_label($r),
            'amount'  => (float)$r['amount'],
            'message' => $r['message'],
            'when'    => time_ago_ph($r['created_at']),
        ], $stmt->fetchAll())]);
        break;

    case 'donate':
        $causeId   = (int)($input['cause_id'] ?? 0);
        $amount    = round((float)($input['amount'] ?? 0), 2);
        $message   = trim(preg_replace('/\s+/', ' ', (string)($input['message'] ?? '')));
        $anonymous = !empty($input['anonymous']);
        $mpin      = trim((string)($input['mpin'] ?? ''));

        $user = get_user($userId);
        if ($mpin === '' || empty($user['mpin_hash']) || !password_verify($mpin, $user['mpin_hash'])) {
            json_out(['success' => false, 'message' => 'Incorrect MPIN.']);
        }
        if ($amount < DONATION_MIN || $amount > DONATION_MAX) {
            json_out(['success' => false, 'message' => 'Donate from ' . peso(DONATION_MIN) . ' to ' . peso(DONATION_MAX) . '.']);
        }
        if (mb_strlen($message) > 150) {
            json_out(['success' => false, 'message' => 'Message is too long (max 150 characters).']);
        }

        $pdo = db();
        $ref = generate_reference('DN');
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM rbnw_donation_causes WHERE id = ? AND is_active = 1 FOR UPDATE");
            $stmt->execute([$causeId]);
            $cause = $stmt->fetch();
            if (!$cause) {
                throw new RuntimeException('This cause is no longer accepting donations.');
            }

            post_wallet_transaction((int)get_wallet($userId)['id'], 'debit', $amount, 'donation', [
                'reference_no'      => $ref,
                'counterparty_name' => $cause['name'],
                'note'              => $message ?: 'Donation',
            ]);
            $pdo->prepare(
                "INSERT INTO rbnw_donations (reference_no, user_id, cause_id, amount, is_anonymous, message)
                 VALUES (?,?,?,?,?,?)"
            )->execute([$ref, $userId, $causeId, $amount, $anonymous ? 1 : 0, $message ?: null]);
            $pdo->prepare("UPDATE rbnw_donation_causes SET raised_amount = raised_amount + ? WHERE id = ?")
                ->execute([$amount, $causeId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = $e instanceof RuntimeException ? $e->getMessage() : 'Donation failed. Please try again.';
            json_out(['success' => false, 'message' => $msg]);
        }

        try {
            push_notification($userId, 'Thank you for donating!', peso($amount) . " to {$cause['name']}. Ref {$ref}.", 'donation');
        } catch (Throwable $e) {
            error_log('[RBNW] donation notification failed: ' . $e->getMessage());
        }
        json_out(['success' => true, 'reference_no' => $ref, 'amount' => $amount, 'cause' => $cause['name']]);
        break;

    default:
        json_out(['success' => false, 'message' => 'Unknown action.'], 404);
}
