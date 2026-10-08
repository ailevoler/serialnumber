<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/agents.php';
$admin = admin_require('agents.view');
$statuses = agent_statuses();

if (q('view') === 'rates') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        admin_post_guard();
        if (!admin_can('settings')) { flash('error', 'Only a Super Admin can change commission rates.'); redirect('agents.php?view=rates'); }
        $peso = static function (string $v, string $label): string {
            $v = trim(str_replace(',', '', $v));
            if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $v)) throw new InvalidArgumentException($label . ' must be a peso amount like 5 or 5.50.');
            [$w, $f] = array_pad(explode('.', $v, 2), 2, '');
            return (string) ((int) $w * 100 + (int) str_pad($f, 2, '0'));
        };
        $pct = static function (string $v, string $label): string {
            $v = trim($v);
            if (!preg_match('/^\d{1,2}(?:\.\d{1,2})?$/D', $v) || (float) $v > 50) throw new InvalidArgumentException($label . ' must be a percentage like 2.5 (max 50).');
            return (string) (int) round((float) $v * 100);
        };
        try {
            $pdo->beginTransaction();
            setting_save($pdo, 'agents.enabled', p('enabled') === '1' ? '1' : '0', (int) $admin['id']);
            foreach (agent_services() as $code => $svc) {
                setting_save($pdo, "agents.{$svc['key']}.percent_bp", $pct(p($svc['key'] . '_percent', '0'), $svc['label'] . ' commission'), (int) $admin['id']);
                setting_save($pdo, "agents.{$svc['key']}.fixed_centavos", $peso(p($svc['key'] . '_fixed', '0'), $svc['label'] . ' fixed commission'), (int) $admin['id']);
            }
            setting_save($pdo, 'agents.earn_days', (string) (int) p('earn_days', '365'), (int) $admin['id']);
            setting_save($pdo, 'agents.cookie_days', (string) (int) p('cookie_days', '30'), (int) $admin['id']);
            setting_save($pdo, 'agents.hold_days', (string) (int) p('hold_days', '3'), (int) $admin['id']);
            setting_save($pdo, 'agents.payout_min_centavos', $peso(p('payout_min', '500'), 'Minimum payout'), (int) $admin['id']);
            $pdo->commit();
            admin_audit('agent_rates_updated', 'settings', 'agents', array_diff_key($_POST, ['csrf' => 1]));
            flash('success', 'Agent commission rates saved. New rates apply to new activity only.');
        } catch (InvalidArgumentException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', $ex->getMessage());
        }
        redirect('agents.php?view=rates');
    }
    $st = agent_settings();
    $ro = admin_can('settings') ? '' : ' disabled';
    admin_header('Agents', 'agents');
    ?>
    <div class="tabs"><a href="agents.php">Agents</a><a class="active" href="agents.php?view=rates">Commission rates</a></div>
    <form class="card" method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <h2>Agent commission rates <small>Paid by Ultimate App; customers, drivers and merchants are not charged</small></h2>
        <label><input type="checkbox" name="enabled" value="1"<?= $st['enabled'] ? ' checked' : '' ?><?= $ro ?>> Agent commissions enabled (when off, links still tag new customers but no commission is recorded)</label>
        <div class="table-wrap mt"><table><thead><tr><th>Service</th><th>Earned when</th><th>Percent</th><th>Fixed per activity (PHP)</th></tr></thead><tbody>
        <?php foreach (agent_services() as $code => $svc): [$bp, $fixed] = agent_rate($code); ?>
            <tr><td><b><?= e($svc['label']) ?></b><small>% of the <?= e($svc['base']) ?></small></td><td><?= e($svc['when']) ?></td>
                <td><input type="text" inputmode="decimal" name="<?= e($svc['key']) ?>_percent" value="<?= e(rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.')) ?>" aria-label="<?= e($svc['label']) ?> percent" size="6"<?= $ro ?>> %</td>
                <td><input type="text" inputmode="decimal" name="<?= e($svc['key']) ?>_fixed" value="<?= e(centavos_to_decimal($fixed)) ?>" aria-label="<?= e($svc['label']) ?> fixed" size="8"<?= $ro ?>></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <div class="form-grid mt">
            <div class="field"><label for="earn_days">Agent earns from a customer for (days after sign-up)</label><input id="earn_days" name="earn_days" type="number" min="0" max="3650" value="<?= (int) $st['earn_days'] ?>"<?= $ro ?>><small>0 = for as long as the customer uses the app.</small></div>
            <div class="field"><label for="cookie_days">Referral link remembered for (days)</label><input id="cookie_days" name="cookie_days" type="number" min="1" max="365" value="<?= (int) $st['cookie_days'] ?>"<?= $ro ?>><small>Time between clicking the link and signing up.</small></div>
            <div class="field"><label for="hold_days">UEat holding period (days)</label><input id="hold_days" name="hold_days" type="number" min="0" max="60" value="<?= (int) $st['hold_days'] ?>"<?= $ro ?>><small>UEat commission becomes available if the order is not cancelled within this time.</small></div>
            <div class="field"><label for="payout_min">Minimum agent payout (PHP)</label><input id="payout_min" name="payout_min" type="text" inputmode="decimal" value="<?= e(centavos_to_decimal($st['payout_min'])) ?>"<?= $ro ?>></div>
        </div>
        <?php if (!$ro): ?><div class="form-actions"><button class="btn primary" type="submit">Save rates</button></div><?php else: ?><p class="table-meta">Only a Super Admin can change these.</p><?php endif; ?>
    </form>
    <?php admin_footer(); exit;
}

agent_mature_pending($pdo);
$search = q('q'); $status = q('status');
$counts = array_fill_keys(array_keys($statuses), 0);
foreach ($pdo->query('SELECT status, COUNT(*) n FROM agents GROUP BY status') as $r) $counts[$r['status']] = (int) $r['n'];
$where = ['1=1']; $params = [];
if ($search !== '') { $where[] = '(a.full_name LIKE ? OR a.email LIKE ? OR a.code LIKE ? OR a.mobile LIKE ?)'; array_push($params, ...array_fill(0, 4, '%' . $search . '%')); }
if (isset($statuses[$status])) { $where[] = 'a.status = ?'; $params[] = $status; }
$sql = ' FROM agents a WHERE ' . implode(' AND ', $where);
$cols = "a.*, (SELECT COUNT(*) FROM users u WHERE u.referred_by_agent_id = a.id) referrals,
    (SELECT COALESCE(SUM(amount_centavos),0) FROM agent_commissions c WHERE c.agent_id = a.id AND c.status = 'approved') earned,
    (SELECT COALESCE(SUM(amount_centavos),0) FROM agent_commissions c WHERE c.agent_id = a.id AND c.status = 'pending') pending";
if (q('export') === 'csv') {
    $stmt = $pdo->prepare("SELECT $cols" . $sql . ' ORDER BY a.id DESC');
    $stmt->execute($params);
    admin_audit('export_agents');
    $rows = static function () use ($stmt): Generator { foreach ($stmt as $r) yield [$r['id'], $r['code'], $r['full_name'], $r['email'], $r['mobile'], $r['barangay'], $r['status'], $r['referrals'], $r['link_clicks'], centavos_to_decimal((int) $r['pending']), centavos_to_decimal((int) $r['earned']), centavos_to_decimal((int) $r['balance_centavos']), $r['created_at']]; };
    csv_download('agents-' . date('Ymd') . '.csv', ['ID', 'Code', 'Name', 'Email', 'Mobile', 'Barangay', 'Status', 'Referrals', 'Link clicks', 'Pending', 'Earned', 'Balance', 'Registered'], $rows());
}
$k = $pdo->query("SELECT (SELECT COUNT(*) FROM users WHERE referred_by_agent_id IS NOT NULL) referred,
    (SELECT COALESCE(SUM(amount_centavos),0) FROM agent_commissions WHERE status = 'pending') pending,
    (SELECT COALESCE(SUM(amount_centavos),0) FROM agent_commissions WHERE status = 'approved' AND approved_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) month,
    (SELECT COALESCE(SUM(balance_centavos),0) FROM agents) owed")->fetch();
[$page, $per, $offset] = paging();
$c = $pdo->prepare('SELECT COUNT(*)' . $sql); $c->execute($params); $total = (int) $c->fetchColumn();
$stmt = $pdo->prepare("SELECT $cols" . $sql . " ORDER BY a.id DESC LIMIT $per OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();
admin_header('Agents', 'agents');
?>
<div class="tabs"><a class="active" href="agents.php">Agents</a><a href="agents.php?view=rates">Commission rates</a></div>
<div class="grid kpis">
    <div class="card kpi hero"><small>Owed to agents</small><strong>₱<?= peso((int) $k['owed']) ?></strong><span>Available balances</span></div>
    <div class="card kpi"><small>Earned this month</small><strong>₱<?= peso((int) $k['month']) ?></strong><span><?= e(date('F Y')) ?></span></div>
    <div class="card kpi"><small>Pending commissions</small><strong>₱<?= peso((int) $k['pending']) ?></strong><span>Awaiting completion</span></div>
    <div class="card kpi"><small>Referred customers</small><strong><?= number_format((int) $k['referred']) ?></strong><span>All agents</span></div>
</div>
<div class="tabs mt"><a class="<?= $status === '' ? 'active' : '' ?>" href="agents.php">All<span><?= array_sum($counts) ?></span></a><?php foreach ($statuses as $key => $label): ?><a class="<?= $status === $key ? 'active' : '' ?>" href="?status=<?= e($key) ?>"><?= e($label) ?><span><?= $counts[$key] ?></span></a><?php endforeach; ?></div>
<form class="toolbar" method="get">
    <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, email, mobile, code" aria-label="Search">
    <button class="btn" type="submit">Filter</button><span class="spacer"></span>
    <a class="btn" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>"><?= icon('download') ?> Export CSV</a>
</form>
<div class="table-wrap"><table>
    <thead><tr><th>Agent</th><th>Contact</th><th class="num">Referrals</th><th class="num">Clicks</th><th class="num">Pending</th><th class="num">Earned</th><th class="num">Balance</th><th>Status</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="8" class="muted">No agents yet. Share the Agents Portal sign-up link: /agent-portal/register.php</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
        <tr><td><a class="row-link" href="agent.php?id=<?= (int) $r['id'] ?>"><?= e($r['full_name']) ?></a><small><?= e($r['code']) ?> · since <?= e(date('M j, Y', strtotime($r['created_at']))) ?></small></td>
            <td><?= e($r['email']) ?><small><?= e($r['mobile']) ?><?= $r['barangay'] ? ' · ' . e($r['barangay']) : '' ?></small></td>
            <td class="num"><?= number_format((int) $r['referrals']) ?></td><td class="num"><?= number_format((int) $r['link_clicks']) ?></td>
            <td class="num">₱<?= peso((int) $r['pending']) ?></td><td class="num">₱<?= peso((int) $r['earned']) ?></td><td class="num">₱<?= peso((int) $r['balance_centavos']) ?></td>
            <td><?= status_badge($r['status']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?= pager($page, $per, $total) ?>
<?php admin_footer();
