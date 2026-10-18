<?php
require_once __DIR__ . '/../includes/savings.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$userId = current_user_id();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

function pocket_payload(array $p): array
{
    return [
        'id'              => (int)$p['id'],
        'is_main'         => (bool)$p['is_main'],
        'name'            => $p['goal_name'],
        'plan'            => $p['plan_name'],
        'rate'            => (float)$p['interest_rate'],
        'min_deposit'     => (float)$p['min_deposit'],
        'balance'         => (float)$p['current_balance'],
        'interest_earned' => (float)$p['interest_earned'],
        'target'          => (float)$p['target_amount'],
        'target_date'     => $p['target_date'],
    ];
}

switch ($action) {

    case 'summary':
        savings_accrue_all($userId);
        $pockets = array_map('pocket_payload', savings_pockets($userId));

        $stmt = db()->prepare(
            "SELECT t.type, t.amount, t.tax_withheld, t.created_at, g.goal_name, g.is_main
             FROM rbnw_savings_transactions t JOIN rbnw_savings_goals g ON g.id = t.goal_id
             WHERE g.user_id = ? ORDER BY t.id DESC LIMIT 15"
        );
        $stmt->execute([$userId]);

        json_out([
            'success'        => true,
            'wallet_balance' => (float)get_wallet($userId)['balance'],
            'total'          => round(array_sum(array_column($pockets, 'balance')), 2),
            'interest_total' => round(array_sum(array_column($pockets, 'interest_earned')), 2),
            'wtax_pct'       => SAVINGS_WTAX_PCT,
            'max_goals'      => SAVINGS_MAX_GOALS,
            'pockets'        => $pockets,
            'activity'       => array_map(fn($r) => [
                'type'   => $r['type'],
                'amount' => (float)$r['amount'],
                'tax'    => (float)$r['tax_withheld'],
                'pocket' => $r['is_main'] ? 'My Savings' : $r['goal_name'],
                'when'   => time_ago_ph($r['created_at']),
            ], $stmt->fetchAll()),
        ]);
        break;

    case 'deposit':
    case 'withdraw':
        try {
            $res = savings_move($userId, (int)($input['pocket_id'] ?? 0), $action, (float)($input['amount'] ?? 0));
        } catch (RuntimeException $e) {
            json_out(['success' => false, 'message' => $e->getMessage()]);
        }
        json_out(['success' => true] + $res);
        break;

    case 'create_goal':
        $name   = trim(preg_replace('/\s+/', ' ', (string)($input['name'] ?? '')));
        $target = round((float)($input['target'] ?? 0), 2);
        $date   = trim((string)($input['target_date'] ?? ''));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            json_out(['success' => false, 'message' => 'Give your goal a name (2–60 characters).']);
        }
        if ($target < 100) {
            json_out(['success' => false, 'message' => 'Set a target of at least ₱100.']);
        }
        if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date <= date('Y-m-d'))) {
            json_out(['success' => false, 'message' => 'Pick a target date in the future, or leave it blank.']);
        }
        $stmt = db()->prepare("SELECT COUNT(*) c FROM rbnw_savings_goals WHERE user_id = ? AND is_main = 0 AND status = 'active'");
        $stmt->execute([$userId]);
        if ((int)$stmt->fetch()['c'] >= SAVINGS_MAX_GOALS) {
            json_out(['success' => false, 'message' => 'You can have up to ' . SAVINGS_MAX_GOALS . ' goals at a time.']);
        }

        db()->prepare(
            "INSERT INTO rbnw_savings_goals (user_id, plan_id, is_main, goal_name, target_amount, target_date, last_accrual_date)
             VALUES (?, ?, 0, ?, ?, ?, CURDATE())"
        )->execute([$userId, SAVINGS_GOAL_PLAN_ID, $name, $target, $date ?: null]);
        json_out(['success' => true, 'id' => (int)db()->lastInsertId()]);
        break;

    case 'close_goal':
        $id = (int)($input['pocket_id'] ?? 0);
        $stmt = db()->prepare("SELECT * FROM rbnw_savings_goals WHERE id = ? AND user_id = ? AND is_main = 0 AND status = 'active'");
        $stmt->execute([$id, $userId]);
        $goal = $stmt->fetch();
        if (!$goal) {
            json_out(['success' => false, 'message' => 'Goal not found.']);
        }
        try {
            savings_accrue_all($userId);
            $stmt->execute([$id, $userId]);
            $bal = (float)$stmt->fetch()['current_balance'];
            if ($bal > 0) {
                savings_move($userId, $id, 'withdraw', $bal);
            }
            db()->prepare("UPDATE rbnw_savings_goals SET status = 'closed' WHERE id = ?")->execute([$id]);
        } catch (RuntimeException $e) {
            json_out(['success' => false, 'message' => $e->getMessage()]);
        }
        json_out(['success' => true, 'returned' => $bal]);
        break;

    default:
        json_out(['success' => false, 'message' => 'Unknown action.'], 404);
}
