<?php
require_once __DIR__ . '/_bootstrap.php';
admin_require('finance.view');
$val = static fn(string $sql, array $p = []) => (function () use ($sql, $p) { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return (int) round((float) $s->fetchColumn()); })();
$liab = [
    'Customer Credits' => $val('SELECT COALESCE(SUM(credits),0)*100 FROM users'),
    'Customer BCash' => $val('SELECT COALESCE(SUM(balance_centavos),0) FROM boracay_cash_wallets'),
    'Merchant balances (unsettled)' => $val('SELECT COALESCE(SUM(balance_centavos),0) FROM merchants'),
    'Settlements awaiting transfer' => $val("SELECT COALESCE(SUM(amount_centavos),0) FROM settlements WHERE status = 'pending'"),
    'URide driver wallets' => $val('SELECT COALESCE(SUM(wallet_centavos),0) FROM uride_drivers'),
    'URide Credits fares on hold' => $val("SELECT COALESCE(SUM(held_centavos),0) FROM uride_requests"),
    'Driver payouts awaiting transfer' => $val("SELECT COALESCE(SUM(amount_centavos),0) FROM uride_payouts WHERE status = 'requested'"),
    'MCTC cash held by merchants (to collect)' => $val('SELECT COALESCE(SUM(mctc_due_centavos),0) FROM merchants'),
    'Barangay fees collected (owed to barangays)' => $val("SELECT COALESCE(SUM(credit_centavos - debit_centavos),0) FROM ledger_entries WHERE account LIKE 'brgy_payable:%'"),
];
$periods = [
    'This month' => [date('Y-m-01 00:00:00'), date('Y-m-d 23:59:59')],
    'Last month' => [date('Y-m-01 00:00:00', strtotime('first day of last month')), date('Y-m-t 23:59:59', strtotime('last day of last month'))],
    'Year to date' => [date('Y-01-01 00:00:00'), date('Y-m-d 23:59:59')],
];
$revenue = [];
foreach ($periods as $label => [$a, $b]) {
    $revenue[$label] = [
        'P2M charges · Credits' => $val("SELECT COALESCE(SUM(fee_centavos),0) FROM merchant_payments WHERE status='completed' AND source='credits' AND created_at BETWEEN ? AND ?", [$a, $b]),
        'P2M charges · BCash' => $val("SELECT COALESCE(SUM(fee_centavos),0) FROM merchant_payments WHERE status='completed' AND source='boracay_cash' AND created_at BETWEEN ? AND ?", [$a, $b]),
        'Top-up service fees' => $val("SELECT COALESCE(SUM(fee_centavos),0) FROM credit_topups WHERE status='paid' AND paid_at BETWEEN ? AND ?", [$a, $b]),
        'URide commission' => $val("SELECT COALESCE(SUM(commission_centavos),0) FROM uride_requests WHERE status='completed' AND completed_at BETWEEN ? AND ?", [$a, $b]),
        'Driver top-up fees' => $val("SELECT COALESCE(SUM(fee_centavos),0) FROM uride_driver_topups WHERE status='paid' AND paid_at BETWEEN ? AND ?", [$a, $b]),
        'MCTC cash-out fees' => $val("SELECT COALESCE(SUM(fee_credits),0)*100 FROM credit_conversions WHERE status='completed' AND completed_at BETWEEN ? AND ?", [$a, $b]),
    ];
}
// Books check: every ledger group must balance, and ledgered merchant balances must match the merchants table.
$unbalanced = $pdo->query('SELECT entry_group, SUM(debit_centavos) d, SUM(credit_centavos) c FROM ledger_entries GROUP BY entry_group HAVING d <> c LIMIT 20')->fetchAll();
$merchantDiff = $pdo->query("SELECT * FROM (SELECT m.id, m.business_name, m.balance_centavos, COALESCE(SUM(l.credit_centavos - l.debit_centavos),0) ledger FROM merchants m LEFT JOIN ledger_entries l ON l.account = CONCAT('merchant_payable:', m.id) GROUP BY m.id, m.business_name, m.balance_centavos) x WHERE ledger <> balance_centavos LIMIT 20")->fetchAll();
$driverDiff = $pdo->query("SELECT * FROM (SELECT d.id, d.full_name, d.wallet_centavos, COALESCE(SUM(l.credit_centavos - l.debit_centavos),0) ledger FROM uride_drivers d LEFT JOIN ledger_entries l ON l.account = CONCAT('driver_wallet:', d.id) GROUP BY d.id, d.full_name, d.wallet_centavos) x WHERE ledger <> wallet_centavos LIMIT 20")->fetchAll();
$mctcDiff = $pdo->query("SELECT * FROM (SELECT m.id, m.business_name, m.mctc_due_centavos, COALESCE(SUM(l.debit_centavos - l.credit_centavos),0) ledger FROM merchants m LEFT JOIN ledger_entries l ON l.account = CONCAT('mctc_merchant_due:', m.id) GROUP BY m.id, m.business_name, m.mctc_due_centavos) x WHERE ledger <> mctc_due_centavos LIMIT 20")->fetchAll();
// Customer Credits vs their transaction history (every Credits movement writes a transaction row).
$creditDiff = $pdo->query('SELECT * FROM (SELECT u.id, u.full_name, ROUND(u.credits*100) bal, ROUND(COALESCE(SUM(t.amount),0)*100) hist FROM users u LEFT JOIN transactions t ON t.user_id = u.id GROUP BY u.id, u.full_name, u.credits) x WHERE bal <> hist ORDER BY ABS(bal - hist) DESC LIMIT 20')->fetchAll();
$trial = $pdo->query("SELECT SUBSTRING_INDEX(account, ':', 1) acct, SUM(debit_centavos) d, SUM(credit_centavos) c FROM ledger_entries GROUP BY acct ORDER BY acct")->fetchAll();
admin_header('Finance', 'finance');
?>
<div class="grid kpis">
    <?php foreach ($liab as $label => $amount): ?><div class="card kpi"><small><?= e($label) ?></small><strong>₱<?= peso($amount) ?></strong><span><?= str_contains($label, "to collect") ? "Receivable" : "Liability" ?></span></div><?php endforeach; ?>
</div>
<div class="grid half mt">
    <section class="card"><h2>Revenue <small>PHP</small></h2>
        <div class="table-wrap"><table><thead><tr><th>Source</th><?php foreach (array_keys($periods) as $p): ?><th class="num"><?= e($p) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach (array_keys($revenue['This month']) as $line): ?><tr><td><?= e($line) ?></td><?php foreach (array_keys($periods) as $p): ?><td class="num">₱<?= peso($revenue[$p][$line]) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
        <tr><td><b>Total</b></td><?php foreach (array_keys($periods) as $p): ?><td class="num"><b>₱<?= peso(array_sum($revenue[$p])) ?></b></td><?php endforeach; ?></tr>
        </tbody></table></div>
        <p class="table-meta">Refunded P2M payments are excluded. PayMongo's own processing charges are billed separately by PayMongo.</p>
    </section>
    <section class="card"><h2>Trial balance <a href="ledger.php">Open ledger</a></h2>
        <div class="table-wrap"><table><thead><tr><th>Account</th><th class="num">Debits</th><th class="num">Credits</th><th class="num">Net</th></tr></thead><tbody>
        <?php $td = 0; $tc = 0; foreach ($trial as $r): $td += (int) $r['d']; $tc += (int) $r['c']; ?><tr><td><?= e(ledger_account_label($r['acct'])) ?></td><td class="num"><?= peso((int) $r['d']) ?></td><td class="num"><?= peso((int) $r['c']) ?></td><td class="num"><?= peso((int) $r['c'] - (int) $r['d']) ?></td></tr><?php endforeach; ?>
        <?php if (!$trial): ?><tr><td colspan="4" class="muted">No ledger entries yet.</td></tr><?php endif; ?>
        <tr><td><b>Total</b></td><td class="num"><b><?= peso($td) ?></b></td><td class="num"><b><?= peso($tc) ?></b></td><td class="num"><?= $td === $tc ? '<span class="badge good">Balanced</span>' : '<span class="badge bad">Unbalanced</span>' ?></td></tr>
        </tbody></table></div>
        <p class="table-meta">The ledger covers P2P, P2M, refunds, settlements and manual adjustments made from this release onward.</p>
    </section>
</div>
<section class="card mt"><h2>Reconciliation checks</h2>
    <div class="kv"><span>Every ledger entry balances (debits = credits)</span><?= $unbalanced ? '<span class="badge bad">Mismatch</span> ' . count($unbalanced) . ' issue(s)' : '<span class="badge good">Passed</span>' ?></div>
    <div class="kv"><span>Merchant balances match the ledger</span><?= $merchantDiff ? '<span class="badge bad">Mismatch</span> ' . count($merchantDiff) . ' merchant(s)' : '<span class="badge good">Passed</span>' ?></div>
    <div class="kv"><span>Customer Credits match their transaction history</span><?= $creditDiff ? '<span class="badge warn">Review</span> ' . count($creditDiff) . ' customer(s)' : '<span class="badge good">Passed</span>' ?></div>
    <div class="kv"><span>URide driver wallets match the ledger</span><?= $driverDiff ? '<span class="badge bad">Mismatch</span> ' . count($driverDiff) . ' driver(s)' : '<span class="badge good">Passed</span>' ?></div>
    <div class="kv"><span>MCTC cash due matches the ledger</span><?= $mctcDiff ? '<span class="badge bad">Mismatch</span> ' . count($mctcDiff) . ' merchant(s)' : '<span class="badge good">Passed</span>' ?></div>
    <?php if ($creditDiff || $merchantDiff || $unbalanced || $driverDiff || $mctcDiff): ?>
    <div class="table-wrap mt"><table><thead><tr><th>Item</th><th class="num">Balance</th><th class="num">Expected</th><th class="num">Difference</th></tr></thead><tbody>
        <?php foreach ($creditDiff as $r): ?><tr><td><a href="customer.php?id=<?= (int) $r['id'] ?>"><?= e($r['full_name']) ?></a><small>Credits vs transaction history</small></td><td class="num"><?= peso((int) $r['bal']) ?></td><td class="num"><?= peso((int) $r['hist']) ?></td><td class="num neg"><?= peso((int) $r['bal'] - (int) $r['hist']) ?></td></tr><?php endforeach; ?>
        <?php foreach ($mctcDiff as $r): ?><tr><td><a href="merchant.php?id=<?= (int) $r['id'] ?>"><?= e($r['business_name']) ?></a><small>MCTC cash due vs ledger</small></td><td class="num"><?= peso((int) $r['mctc_due_centavos']) ?></td><td class="num"><?= peso((int) $r['ledger']) ?></td><td class="num neg"><?= peso((int) $r['mctc_due_centavos'] - (int) $r['ledger']) ?></td></tr><?php endforeach; ?>
        <?php foreach ($driverDiff as $r): ?><tr><td><a href="driver.php?id=<?= (int) $r['id'] ?>"><?= e($r['full_name']) ?></a><small>Driver wallet vs ledger</small></td><td class="num"><?= peso((int) $r['wallet_centavos']) ?></td><td class="num"><?= peso((int) $r['ledger']) ?></td><td class="num neg"><?= peso((int) $r['wallet_centavos'] - (int) $r['ledger']) ?></td></tr><?php endforeach; ?>
        <?php foreach ($merchantDiff as $r): ?><tr><td><a href="merchant.php?id=<?= (int) $r['id'] ?>"><?= e($r['business_name']) ?></a><small>Merchant balance vs ledger</small></td><td class="num"><?= peso((int) $r['balance_centavos']) ?></td><td class="num"><?= peso((int) $r['ledger']) ?></td><td class="num neg"><?= peso((int) $r['balance_centavos'] - (int) $r['ledger']) ?></td></tr><?php endforeach; ?>
        <?php foreach ($unbalanced as $r): ?><tr><td><?= e($r['entry_group']) ?><small>Unbalanced ledger group</small></td><td class="num"><?= peso((int) $r['d']) ?></td><td class="num"><?= peso((int) $r['c']) ?></td><td class="num neg"><?= peso((int) $r['d'] - (int) $r['c']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <p class="table-meta">A difference usually means a balance was edited directly in the database. Correct it with Customer → Adjust Credits so it is recorded.</p>
    <?php endif; ?>
</section>
<?php admin_footer();
