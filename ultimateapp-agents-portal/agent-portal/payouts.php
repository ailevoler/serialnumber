<?php
require_once __DIR__ . '/_bootstrap.php';
$a = agent_require();
$id = (int) $a['id'];
$st = agent_settings();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    rate_limit_enforce('agent_payout', 'agent:' . $id, 10, 3600);
    try {
        $raw = trim(str_replace(',', '', ap('amount')));
        if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $raw)) throw new InvalidArgumentException('Enter an amount like 500 or 500.50.');
        [$w, $f] = array_pad(explode('.', $raw, 2), 2, '');
        $ref = agent_payout_request($pdo, $id, (int) $w * 100 + (int) str_pad($f, 2, '0'));
        aflash('success', 'Payout ' . $ref . ' requested. Ultimate App will send it to your ' . $a['payout_method'] . ' account.');
    } catch (InvalidArgumentException $ex) {
        aflash('error', $ex->getMessage());
    }
    redirect('payouts.php');
}
$s = $pdo->prepare('SELECT * FROM agent_payouts WHERE agent_id = ? ORDER BY id DESC LIMIT 50');
$s->execute([$id]); $rows = $s->fetchAll();
$open = array_filter($rows, static fn($r) => $r['status'] === 'requested');
$paid = array_sum(array_map(static fn($r) => $r['status'] === 'paid' ? (int) $r['amount_centavos'] : 0, $rows));
agent_header('Payouts', 'payouts');
if (!in_array($a['status'], ['approved', 'suspended'], true)) { agent_locked_panel($a); agent_footer(); exit; }
?>
<div class="grid kpis">
    <div class="card kpi hero"><small>Available balance</small><strong>₱<?= peso((int) $a['balance_centavos']) ?></strong><span>Minimum payout ₱<?= peso($st['payout_min']) ?></span></div>
    <div class="card kpi"><small>Being processed</small><strong>₱<?= peso(array_sum(array_map(static fn($r) => (int) $r['amount_centavos'], $open))) ?></strong><span><?= count($open) ?> request(s)</span></div>
    <div class="card kpi"><small>Paid to you</small><strong>₱<?= peso($paid) ?></strong><span>Last 50 payouts</span></div>
</div>
<div class="grid two mt">
    <section class="card"><h2>Payout history</h2>
        <div class="table-wrap"><table><thead><tr><th>Reference</th><th>Sent to</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="4" class="muted">No payouts yet.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?><tr><td><b><?= e($r['reference']) ?></b><small><?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></small></td><td><?= e($r['payout_method']) ?><small><?= e(agent_mask_account($r['payout_account_no'])) ?></small></td><td class="num">₱<?= peso((int) $r['amount_centavos']) ?></td><td><?= agent_badge($r['status'], 'payout') ?><?php if ($r['status'] !== 'requested' && ($r['bank_reference'] || $r['note'])): ?><small><?= e($r['bank_reference'] ?: $r['note']) ?></small><?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
    <section class="card"><h2>Cash out</h2>
        <?php if (!$a['payout_account_no']): ?>
            <p class="muted">Add the bank or e-wallet where we send your earnings.</p><a class="btn primary" href="profile.php">Add payout account</a>
        <?php elseif ($open): ?>
            <p class="muted">You have a payout being processed. You can request another once it is sent.</p>
        <?php elseif ((int) $a['balance_centavos'] < $st['payout_min']): ?>
            <p class="muted">You can cash out once your available balance reaches ₱<?= peso($st['payout_min']) ?>.</p>
        <?php else: ?>
            <form method="post" class="stack" data-confirm="Request this payout?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <div class="field"><label for="amount">Amount (PHP)</label><input id="amount" name="amount" type="text" inputmode="decimal" required value="<?= e(centavos_to_decimal((int) $a['balance_centavos'])) ?>"></div>
                <p class="table-meta">Send to <?= e($a['payout_method']) ?> · <?= e($a['payout_account_name']) ?> · <?= e(agent_mask_account($a['payout_account_no'])) ?></p>
                <button class="btn grad" type="submit">Request payout</button></form>
        <?php endif; ?>
    </section>
</div>
<?php agent_footer();
