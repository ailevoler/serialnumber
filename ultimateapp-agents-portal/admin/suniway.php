<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/suniway.php';
$admin = admin_require('payments.view');
$view = q('view') === 'settings' ? 'settings' : 'transactions';
$services = suniway_services();
$test = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_guard();
    $action = p('action');
    try {
        if ($action === 'settings') {
            if (!admin_can('settings')) throw new InvalidArgumentException('Only a Super Admin can change SUNIWAY settings.');
            $pdo->beginTransaction();
            setting_save($pdo, 'suniway.enabled', p('enabled') === '1' ? '1' : '0', (int) $admin['id']);
            setting_save($pdo, 'suniway.base_url', rtrim(trim(p('base_url')), '/'), (int) $admin['id']);
            if (trim(p('api_key')) !== '') setting_save($pdo, 'suniway.api_key', trim(p('api_key')), (int) $admin['id']);
            setting_save($pdo, 'suniway.payment_method', p('payment_method'), (int) $admin['id']);
            foreach (array_keys($services) as $svc) {
                setting_save($pdo, "suniway.fee_{$svc}_centavos", (string) suniway_peso_to_centavos(p("fee_$svc", '0') ?: '0'), (int) $admin['id']);
            }
            if (p('enabled') === '1' && !(string) setting('suniway.api_key', '')) throw new InvalidArgumentException('Enter the Partner API key before turning the services on.');
            $pdo->commit();
            admin_audit('suniway_settings', 'settings', 'suniway', ['enabled' => p('enabled') === '1', 'base_url' => p('base_url'), 'key_changed' => trim(p('api_key')) !== '']);
            flash('success', 'SUNIWAY settings saved.');
            redirect('suniway.php?view=settings');
        } elseif ($action === 'test') {
            if (!admin_can('settings')) throw new InvalidArgumentException('Only a Super Admin can test the connection.');
            $test = [];
            foreach (array_keys($services) as $svc) $test[$svc] = count(suniway_providers($svc, true));
            $view = 'settings';
        } else {
            $id = (int) p('id');
            if ($action === 'refresh') {
                $st = suniway_refresh($pdo, $id);
                flash('success', 'Status refreshed: ' . suniway_status_label($st)[0] . '.');
            } elseif ($action === 'refund') {
                if (!admin_can('payments.refund')) throw new InvalidArgumentException('Your role cannot refund.');
                if (mb_strlen(trim(p('note'))) < 4) throw new InvalidArgumentException('Give the reason (e.g. "Not found in SUNIWAY dashboard").');
                suniway_refund($pdo, $id, 'Refunded by admin: ' . trim(p('note')));
                admin_audit('suniway_refund', 'suniway_transaction', $id, ['note' => p('note')]);
                flash('success', 'Credits returned to the customer.');
            } elseif ($action === 'success') {
                if (!admin_can('payments.refund')) throw new InvalidArgumentException('Your role cannot confirm payments.');
                suniway_mark_success($pdo, $id, p('remote_reference'));
                admin_audit('suniway_mark_success', 'suniway_transaction', $id, ['remote_reference' => p('remote_reference')]);
                flash('success', 'Marked as successful.');
            }
            redirect('suniway.php?' . http_build_query(array_filter(['status' => q('status'), 'service' => q('service'), 'q' => q('q')])));
        }
    } catch (InvalidArgumentException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $ex->getMessage());
        if ($action !== 'test') redirect('suniway.php' . ($action === 'settings' ? '?view=settings' : ''));
        $view = 'settings';
    } catch (SuniwayError $ex) {
        flash('error', 'SUNIWAY: ' . $ex->getMessage());
        $view = 'settings';
    }
}

admin_header('Bills & Load (SUNIWAY)', 'suniway');
if (!suniway_table_ready()) {
    echo '<div class="flash error" role="status">Import <b>database/suniway_migration.sql</b> once in phpMyAdmin, then reload this page.</div>';
    admin_footer();
    exit;
}
?>
<div class="tabs"><a class="<?= $view === 'transactions' ? 'active' : '' ?>" href="suniway.php">Transactions</a><a class="<?= $view === 'settings' ? 'active' : '' ?>" href="suniway.php?view=settings">Settings</a></div>
<?php if ($view === 'settings'):
    $c = suniway_config(); $ro = admin_can('settings') ? '' : ' disabled'; ?>
    <?php if ($test !== null): ?><div class="flash success" role="status">Connected to SUNIWAY. Providers found: Bills <?= (int) $test['bills_pay'] ?> · E-Load <?= (int) $test['eload'] ?> · E-Cash <?= (int) $test['ecash'] ?>.</div><?php endif; ?>
    <div class="grid two">
        <form class="card" method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="settings">
            <h2>SUNIWAY Partner API <?= status_badge(suniway_enabled() ? 'active' : 'disabled') ?></h2>
            <label><input type="checkbox" name="enabled" value="1"<?= $c['enabled'] ? ' checked' : '' ?><?= $ro ?>> Accept UBills, ULoad and UCash In payments (tiles are always shown; when off they say “Coming soon”)</label>
            <div class="form-grid mt">
                <div class="field"><label for="base_url">API base URL</label><input id="base_url" name="base_url" type="url" required value="<?= e($c['base_url']) ?>"<?= $ro ?>><small>From your SUNIWAY dashboard. Must be https.</small></div>
                <div class="field"><label for="api_key">Partner API key</label><input id="api_key" name="api_key" type="password" autocomplete="off" placeholder="<?= $c['api_key'] !== '' ? 'Saved ••••' . e(substr($c['api_key'], -4)) . ' (leave blank to keep)' : 'Paste the key' ?>"<?= $ro ?>><small>Stored encrypted. Never shown to customers.</small></div>
                <div class="field"><label for="payment_method">Payment method sent to SUNIWAY</label><select id="payment_method" name="payment_method"<?= $ro ?>><?php foreach (['CASH' => 'Cash', 'GCASH' => 'GCash', 'PAYMAYA' => 'Maya', 'CARD' => 'Card'] as $k => $l): ?><option value="<?= $k ?>"<?= $c['payment_method'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><small>Customers pay Ultimate App with Credits; this is how you settle with SUNIWAY.</small></div>
            </div>
            <h3 class="mt">Ultimate App convenience fee (added on top, kept as revenue)</h3>
            <div class="form-grid">
                <?php foreach ($services as $k => $s): ?><div class="field"><label for="fee_<?= $k ?>"><?= e($s['label']) ?> (PHP per transaction)</label><input id="fee_<?= $k ?>" name="fee_<?= $k ?>" type="text" inputmode="decimal" value="<?= e(centavos_to_decimal(suniway_app_fee($k))) ?>"<?= $ro ?>></div><?php endforeach; ?>
            </div>
            <?php if (!$ro): ?><div class="form-actions"><button class="btn primary" type="submit">Save settings</button></div><?php endif; ?>
        </form>
        <section class="card"><h2>Check connection</h2><p class="muted">Loads the provider list from SUNIWAY with the saved key.</p>
            <?php if (!$ro): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="test"><button class="btn" type="submit">Test connection</button></form><?php endif; ?>
            <h3 class="mt">How payments work</h3>
            <ul class="muted">
                <li>Customer pays with Credits. Credits are deducted first, then the transaction is sent to SUNIWAY.</li>
                <li>Rejected or failed → Credits are refunded automatically.</li>
                <li>No reply from SUNIWAY → status <b>Checking</b>. Look it up in the SUNIWAY dashboard, then <b>Refund</b> or <b>Mark successful</b> here.</li>
                <li>UCash In sends Credits to the customer's GCash / Maya. It is not Buy Credits.</li>
            </ul></section>
    </div>
<?php else:
    $status = in_array(q('status'), ['attention', 'pending', 'success', 'failed'], true) ? q('status') : '';
    $service = isset($services[q('service')]) ? q('service') : '';
    $search = q('q');
    $where = ['1=1']; $params = [];
    if ($status === 'attention') $where[] = "t.status IN ('unknown','submitting')";
    elseif ($status) { $where[] = 't.status = ?'; $params[] = $status; }
    if ($service) { $where[] = 't.service = ?'; $params[] = $service; }
    if ($search !== '') { $where[] = '(t.reference LIKE ? OR t.account_number LIKE ? OR t.remote_reference LIKE ? OR u.full_name LIKE ?)'; array_push($params, ...array_fill(0, 4, '%' . $search . '%')); }
    $sql = ' FROM suniway_transactions t JOIN users u ON u.id = t.user_id WHERE ' . implode(' AND ', $where);
    if (q('export') === 'csv') {
        $s = $pdo->prepare('SELECT t.reference, t.created_at, u.full_name, t.service, t.provider_name, t.account_number, t.amount_centavos, t.total_centavos, t.app_fee_centavos, t.status, t.remote_status, t.remote_reference' . $sql . ' ORDER BY t.id DESC');
        $s->execute($params);
        admin_audit('export_suniway');
        $rows = static function () use ($s): Generator { foreach ($s as $r) yield [$r['reference'], $r['created_at'], $r['full_name'], $r['service'], $r['provider_name'], $r['account_number'], centavos_to_decimal((int) $r['amount_centavos']), centavos_to_decimal((int) $r['total_centavos']), centavos_to_decimal((int) $r['app_fee_centavos']), $r['status'], $r['remote_status'], $r['remote_reference']]; };
        csv_download('suniway-' . date('Ymd') . '.csv', ['Reference', 'Date', 'Customer', 'Service', 'Provider', 'Account', 'Amount', 'Total Credits', 'Our fee', 'Status', 'SUNIWAY status', 'SUNIWAY ref'], $rows());
    }
    $k = $pdo->query("SELECT COUNT(CASE WHEN status = 'success' AND created_at >= CURDATE() THEN 1 END) today_n, COALESCE(SUM(CASE WHEN status = 'success' AND created_at >= CURDATE() THEN amount_centavos END),0) today_v,
        COALESCE(SUM(CASE WHEN status = 'success' THEN app_fee_centavos END),0) fees, COUNT(CASE WHEN status = 'pending' THEN 1 END) pending, COUNT(CASE WHEN status IN ('unknown','submitting') THEN 1 END) attention FROM suniway_transactions")->fetch();
    [$page, $per, $offset] = paging();
    $cnt = $pdo->prepare('SELECT COUNT(*)' . $sql); $cnt->execute($params); $total = (int) $cnt->fetchColumn();
    $s = $pdo->prepare('SELECT t.*, u.full_name, u.mobile' . $sql . " ORDER BY t.id DESC LIMIT $per OFFSET $offset"); $s->execute($params); $rows = $s->fetchAll();
    ?>
    <?php if (!suniway_enabled()): ?><div class="flash error" role="status">UBills, ULoad and UCash In show <b>Coming soon</b> to customers. Add the API key and turn on payments in <a href="suniway.php?view=settings">Settings</a>.</div><?php endif; ?>
    <div class="grid kpis">
        <div class="card kpi hero"><small>Successful today</small><strong>₱<?= peso((int) $k['today_v']) ?></strong><span><?= (int) $k['today_n'] ?> transaction(s)</span></div>
        <div class="card kpi"><small>Needs checking</small><strong><?= (int) $k['attention'] ?></strong><span><a href="?status=attention">No reply from SUNIWAY</a></span></div>
        <div class="card kpi"><small>Processing</small><strong><?= (int) $k['pending'] ?></strong><span>Waiting for provider</span></div>
        <div class="card kpi"><small>Convenience fees earned</small><strong>₱<?= peso((int) $k['fees']) ?></strong><span>Successful only</span></div>
    </div>
    <div class="tabs mt"><?php foreach (['' => 'All', 'attention' => 'Needs checking', 'pending' => 'Processing', 'success' => 'Successful', 'failed' => 'Failed / refunded'] as $key => $l): ?><a class="<?= $status === $key ? 'active' : '' ?>" href="?<?= e(http_build_query(array_filter(['status' => $key, 'service' => $service]))) ?>"><?= $l ?></a><?php endforeach; ?></div>
    <form class="toolbar" method="get"><?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
        <select name="service" data-autosubmit aria-label="Service"><option value="">All services</option><?php foreach ($services as $key => $s0): ?><option value="<?= $key ?>"<?= $service === $key ? ' selected' : '' ?>><?= e($s0['label']) ?></option><?php endforeach; ?></select>
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Reference, account, customer" aria-label="Search"><button class="btn" type="submit">Filter</button><span class="spacer"></span>
        <a class="btn" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>"><?= icon('download') ?> Export CSV</a></form>
    <div class="table-wrap"><table>
        <thead><tr><th>Reference</th><th>Customer</th><th>Service</th><th>Account</th><th class="num">Amount</th><th class="num">Total</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="8" class="muted">No transactions.</td></tr><?php endif; ?>
        <?php foreach ($rows as $t): [$lbl, $tone] = suniway_status_label($t['status']); ?>
            <tr><td><?= e($t['reference']) ?><small><?= e(date('M j, Y g:i A', strtotime($t['created_at']))) ?></small><?php if ($t['remote_reference']): ?><small>SW <?= e($t['remote_reference']) ?></small><?php endif; ?></td>
                <td><a class="row-link" href="customer.php?id=<?= (int) $t['user_id'] ?>"><?= e($t['full_name']) ?></a><small><?= e($t['mobile']) ?></small></td>
                <td><?= e($services[$t['service']]['label']) ?><small><?= e($t['provider_name']) ?></small></td>
                <td><?= e($t['account_number']) ?></td>
                <td class="num">₱<?= peso((int) $t['amount_centavos']) ?></td><td class="num">₱<?= peso((int) $t['total_centavos']) ?></td>
                <td><span class="badge <?= e($tone) ?>"><?= e($lbl) ?></span><?php if ($t['remote_status']): ?><small><?= e($t['remote_status']) ?></small><?php endif; ?><?php if ($t['error_message']): ?><small><?= e($t['error_message']) ?></small><?php endif; ?></td>
                <td><?php if ($t['status'] === 'pending' && $t['remote_id']): ?>
                        <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="refresh"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="btn small" type="submit">Refresh</button></form>
                    <?php endif; ?>
                    <?php if (in_array($t['status'], ['unknown', 'submitting', 'pending'], true) && admin_can('payments.refund')): ?>
                        <details><summary>Resolve</summary>
                            <form method="post" class="inline mt" data-confirm="Mark as successful? Only if SUNIWAY shows it as paid."><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="success"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="text" name="remote_reference" placeholder="SUNIWAY ref (optional)" aria-label="SUNIWAY reference"><button class="btn small primary" type="submit">Mark successful</button></form>
                            <form method="post" class="inline mt" data-confirm="Return the Credits to the customer? Only if SUNIWAY did not process it."><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="refund"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="text" name="note" required placeholder="Reason" aria-label="Reason"><button class="btn small danger" type="submit">Refund</button></form>
                        </details>
                    <?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?= pager($page, $per, $total) ?>
<?php endif; ?>
<?php admin_footer();
