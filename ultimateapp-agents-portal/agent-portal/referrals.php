<?php
require_once __DIR__ . '/_bootstrap.php';
$a = agent_require();
$id = (int) $a['id'];
$st = agent_settings();
$page = max(1, (int) aq('page') ?: 1); $per = 30; $off = ($page - 1) * $per;
$c = $pdo->prepare('SELECT COUNT(*) FROM users WHERE referred_by_agent_id = ?'); $c->execute([$id]); $total = (int) $c->fetchColumn();
$s = $pdo->prepare("SELECT u.id, u.full_name, u.referred_at, u.created_at,
        COUNT(c.id) activities, COALESCE(SUM(CASE WHEN c.status = 'approved' THEN c.amount_centavos END),0) earned,
        COALESCE(SUM(CASE WHEN c.status = 'pending' THEN c.amount_centavos END),0) pending, MAX(c.created_at) last_activity
    FROM users u LEFT JOIN agent_commissions c ON c.user_id = u.id AND c.agent_id = u.referred_by_agent_id AND c.status <> 'reversed'
    WHERE u.referred_by_agent_id = ? GROUP BY u.id ORDER BY u.referred_at DESC, u.id DESC LIMIT $per OFFSET $off");
$s->execute([$id]); $rows = $s->fetchAll();
agent_header('My referrals', 'referrals');
?>
<p class="muted">Customers who signed up with your link or code. Names are shortened for their privacy.<?= $st['earn_days'] ? ' You earn on each customer\'s activity for ' . number_format($st['earn_days']) . ' days after they sign up.' : '' ?></p>
<div class="table-wrap mt"><table><thead><tr><th>Customer</th><th>Signed up</th><th>Earning until</th><th class="num">Activities</th><th class="num">Pending</th><th class="num">Earned</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="6" class="muted">No referrals yet. Share your link from the dashboard.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): $since = strtotime((string) ($r['referred_at'] ?: $r['created_at'])); $until = $st['earn_days'] ? $since + 86400 * $st['earn_days'] : null; ?>
    <tr><td><b><?= e(agent_mask_name($r['full_name'])) ?></b><?php if ($r['last_activity']): ?><small>Last activity <?= e(date('M j, Y', strtotime($r['last_activity']))) ?></small><?php endif; ?></td>
        <td><?= e(date('M j, Y', $since)) ?></td>
        <td><?= $until === null ? 'Always' : ($until < time() ? '<span class="badge muted">Ended ' . e(date('M j, Y', $until)) . '</span>' : e(date('M j, Y', $until))) ?></td>
        <td class="num"><?= number_format((int) $r['activities']) ?></td><td class="num">₱<?= peso((int) $r['pending']) ?></td><td class="num">₱<?= peso((int) $r['earned']) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<nav class="pager"><span><?= number_format($total) ?> referral<?= $total === 1 ? '' : 's' ?></span><?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>">&larr; Prev</a><?php endif; ?><?php if ($off + $per < $total): ?><a href="?page=<?= $page + 1 ?>">Next &rarr;</a><?php endif; ?></nav>
<?php agent_footer();
