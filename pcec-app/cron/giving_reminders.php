<?php
// Daily job: remind donors when their recurring gift is due (nothing is charged automatically).
// Example crontab:  15 8 * * *  php /path/to/pcec-app/cron/giving_reminders.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/settings.php';

$intervals = ['monthly' => '1 MONTH', 'quarterly' => '3 MONTH', 'yearly' => '1 YEAR'];
$sent = 0;
foreach ($intervals as $freq => $interval) {
    // Latest paid recurring gift per donor + fund whose next date has arrived.
    $due = q_all("SELECT d.user_id, d.fund, MAX(p.paid_at) AS last_paid, SUBSTRING_INDEX(GROUP_CONCAT(d.id ORDER BY p.paid_at DESC), ',', 1) AS donation_id,
                    SUBSTRING_INDEX(GROUP_CONCAT(p.amount - p.fee_amount ORDER BY p.paid_at DESC), ',', 1) AS amount
                  FROM donations d JOIN payments p ON p.id = d.payment_id
                  WHERE d.gift_type = 'recurring' AND d.frequency = ? AND p.status = 'paid' AND d.user_id IS NOT NULL
                  GROUP BY d.user_id, d.fund
                  HAVING MAX(p.paid_at) <= NOW() - INTERVAL $interval", [$freq]);
    foreach ($due as $r) {
        $link = 'give.php?repeat=' . $r['donation_id'];
        // At most one reminder per schedule every 7 days.
        if (q_val("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'reminder' AND link = ? AND created_at > NOW() - INTERVAL 7 DAY", [$r['user_id'], $link])) continue;
        notify((int) $r['user_id'], null, 'reminder', 'Your ' . $freq . ' gift of ' . money((int) $r['amount']) . ' to ' . $r['fund'] . ' is due. Thank you for your faithful giving!', $link);
        $sent++;
    }
}
echo "Sent $sent reminder(s)\n";
