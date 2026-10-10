<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/fx.php';
$admin = admin_require('finance.view');
$view = q('view') === 'settings' ? 'settings' : 'trades';

/** "0.75" (percent) -> 75 basis points; 0 to 5%. */
$percentToBp = static function (string $value, string $label): int {
    $value = trim(str_replace('%', '', $value));
    if (!preg_match('/^\d(?:\.\d{1,2})?$/D', $value) || (float) $value > 5) throw new InvalidArgumentException($label . ' must be from 0 to 5%, e.g. 0.5 or 0.75.');
    [$w, $f] = array_pad(explode('.', $value, 2), 2, '');
    return (int) $w * 100 + (int) str_pad($f, 2, '0');
};
$usdToCents = static function (string $value, string $label): int {
    $value = trim(str_replace(',', '', $value));
    if (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/D', $value) || (float) $value <= 0) throw new InvalidArgumentException($label . ' must be a USD amount, e.g. 1 or 1000.');
    [$w, $f] = array_pad(explode('.', $value, 2), 2, '');
    return (int) $w * 100 + (int) str_pad($f, 2, '0');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_guard();
    $action = p('action');
    try {
        if (!admin_can('settings')) throw new InvalidArgumentException('Only a Super Admin can change the USD exchange.');
        if ($action === 'settings') {
            $min = $usdToCents(p('min_usd'), 'Minimum');
            $max = $usdToCents(p('max_usd'), 'Maximum');
            if ($min > $max) throw new InvalidArgumentException('The minimum cannot be more than the maximum.');
            $provider = p('provider');
            $manual = trim(p('manual_rate'));
            if ($provider === 'manual') {
                if (!preg_match('/^\d{1,3}(?:\.\d{1,6})?$/D', $manual) || (float) $manual < FX_SANE_MIN || (float) $manual > FX_SANE_MAX) {
                    throw new InvalidArgumentException('Enter the manual rate in PHP per USD, between ' . FX_SANE_MIN . ' and ' . FX_SANE_MAX . ' (e.g. 58.25).');
                }
            }
            $pdo->beginTransaction();
            $id = (int) $admin['id'];
            setting_save($pdo, 'fx.enabled', p('enabled') === '1' ? '1' : '0', $id);
            setting_save($pdo, 'fx.provider', $provider, $id);
            if ($manual !== '') setting_save($pdo, 'fx.manual_rate', $manual, $id);
            setting_save($pdo, 'fx.buy_fee_bp', (string) $percentToBp(p('buy_fee'), 'Buy USD fee'), $id);
            setting_save($pdo, 'fx.sell_fee_bp', (string) $percentToBp(p('sell_fee'), 'Sell USD fee'), $id);
            setting_save($pdo, 'fx.min_usd_cents', (string) $min, $id);
            setting_save($pdo, 'fx.max_usd_cents', (string) $max, $id);
            setting_save($pdo, 'fx.refresh_minutes', p('refresh_minutes'), $id);
            setting_save($pdo, 'fx.max_age_hours', p('max_age_hours'), $id);
            $pdo->commit();
            admin_audit('fx_settings', 'settings', 'fx', ['enabled' => p('enabled') === '1', 'provider' => $provider, 'manual_rate' => $manual, 'buy_fee' => p('buy_fee'), 'sell_fee' => p('sell_fee'), 'min_usd' => p('min_usd'), 'max_usd' => p('max_usd')]);
            try { fx_refresh($pdo); } catch (Throwable $e) { flash('error', 'Saved, but the rate could not be updated: ' . $e->getMessage()); redirect('fx.php?view=settings'); }
            flash('success', 'USD exchange settings saved.');
            redirect('fx.php?view=settings');
        } elseif ($action === 'refresh') {
            $row = fx_refresh($pdo);
            admin_audit('fx_refresh', 'fx_rate', (int) $row['id'], ['rate' => $row['rate'], 'source' => $row['source']]);
            flash('success', 'Rate updated: 1 USD = PHP ' . $row['rate'] . ' (' . fx_source_label($row['source']) . ').');
            redirect('fx.php?view=settings');
        }
    } catch (InvalidArgumentException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $ex->getMessage());
        redirect('fx.php?view=settings');
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
        redirect('fx.php?view=settings');
    }
}

admin_header('USD ⇄ PHP exchange', 'fx');
if (!fx_ready($pdo)) {
    echo '<div class="flash error" role="status">Import <b>database/fx_migration.sql</b> once in phpMyAdmin, then reload this page.</div>';
    admin_footer();
    exit;
}
$cfg = fx_config();
$rate = fx_current($pdo, false);
?>
<div class="tabs"><a class="<?= $view === 'trades' ? 'active' : '' ?>" href="fx.php">Exchanges</a><a class="<?= $view === 'settings' ? 'active' : '' ?>" href="fx.php?view=settings">Rate &amp; fees</a></div>
<?php if (!$cfg['enabled']): ?><div class="flash error" role="status">The USD exchange is <b>closed</b> to customers. Open it in <a href="fx.php?view=settings">Rate &amp; fees</a>.</div>
<?php elseif ($rate && !$rate['tradable']): ?><div class="flash error" role="status">Customers cannot exchange right now: the rate is <?= (int) $rate['age_minutes'] ?> minutes old. <a href="fx.php?view=settings">Refresh the rate</a> or set a manual rate.</div><?php endif; ?>

<?php if ($view === 'settings'): $ro = admin_can('settings') ? '' : ' disabled'; ?>
    <div class="grid two">
        <form class="card" method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="settings">
            <h2>Rate &amp; fees <?= status_badge($cfg['enabled'] ? 'active' : 'disabled') ?></h2>
            <label><input type="checkbox" name="enabled" value="1"<?= $cfg['enabled'] ? ' checked' : '' ?><?= $ro ?>> Customers can buy and sell USD</label>
            <div class="form-grid mt">
                <div class="field"><label for="buy_fee">Buy USD fee (%)</label><input id="buy_fee" name="buy_fee" type="text" inputmode="decimal" required value="<?= e(number_format($cfg['buy_fee_bp'] / 100, 2)) ?>"<?= $ro ?>><small>Added on top of the mid rate when a customer buys USD. Example: 0.50 to 1.00.</small></div>
                <div class="field"><label for="sell_fee">Sell USD fee (%)</label><input id="sell_fee" name="sell_fee" type="text" inputmode="decimal" required value="<?= e(number_format($cfg['sell_fee_bp'] / 100, 2)) ?>"<?= $ro ?>><small>Taken from the PHP a customer gets when selling USD.</small></div>
                <div class="field"><label for="provider">Rate source</label><select id="provider" name="provider"<?= $ro ?>>
                    <?php foreach (['auto' => 'Automatic: Frankfurter, then currency-api', 'frankfurter' => 'Frankfurter only (ECB reference rate)', 'currency_api' => 'currency-api only (fawazahmed0)', 'manual' => 'Manual rate (set below)'] as $k => $l): ?><option value="<?= $k ?>"<?= $cfg['provider'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select><small>Both are free, open-source APIs with no key.</small></div>
                <div class="field"><label for="manual_rate">Manual rate (PHP per 1 USD)</label><input id="manual_rate" name="manual_rate" type="text" inputmode="decimal" value="<?= e($cfg['manual_rate']) ?>" placeholder="e.g. 58.25"<?= $ro ?>><small>Used only when the source is Manual. Update it yourself.</small></div>
                <div class="field"><label for="min_usd">Minimum per exchange (USD)</label><input id="min_usd" name="min_usd" type="text" inputmode="decimal" required value="<?= e(fx_usd($cfg['min_usd_cents'])) ?>"<?= $ro ?>></div>
                <div class="field"><label for="max_usd">Maximum per exchange (USD)</label><input id="max_usd" name="max_usd" type="text" inputmode="decimal" required value="<?= e(str_replace(',', '', fx_usd($cfg['max_usd_cents']))) ?>"<?= $ro ?>></div>
                <div class="field"><label for="refresh_minutes">Refresh the rate every (minutes)</label><input id="refresh_minutes" name="refresh_minutes" type="number" min="5" max="1440" required value="<?= (int) $cfg['refresh_minutes'] ?>"<?= $ro ?>></div>
                <div class="field"><label for="max_age_hours">Pause exchanges when the rate is older than (hours)</label><input id="max_age_hours" name="max_age_hours" type="number" min="1" max="168" required value="<?= (int) $cfg['max_age_hours'] ?>"<?= $ro ?>></div>
            </div>
            <?php if (!$ro): ?><div class="form-actions"><button class="btn primary" type="submit">Save</button></div><?php endif; ?>
        </form>
        <section class="card"><h2>Current rate</h2>
            <?php if ($rate): ?>
                <p class="fx-admin-rate"><b>1 USD = PHP <?= fx_format_rate($rate['micro']) ?></b></p>
                <dl class="facts">
                    <div><dt>Customers buy USD at</dt><dd>PHP <?= fx_format_rate($rate['buy_micro']) ?> (<?= fx_percent($cfg['buy_fee_bp']) ?> fee)</dd></div>
                    <div><dt>Customers sell USD at</dt><dd>PHP <?= fx_format_rate($rate['sell_micro']) ?> (<?= fx_percent($cfg['sell_fee_bp']) ?> fee)</dd></div>
                    <div><dt>Source</dt><dd><?= e(fx_source_label($rate['source'])) ?></dd></div>
                    <div><dt>Published</dt><dd><?= e($rate['rate_date'] ?? '—') ?></dd></div>
                    <div><dt>Last checked</dt><dd><?= e($rate['fetched_at']) ?> (<?= (int) $rate['age_minutes'] ?> min ago)</dd></div>
                </dl>
            <?php else: ?><p class="muted">No rate yet. Press <b>Refresh now</b>.</p><?php endif; ?>
            <?php if (!$ro): ?><form method="post" class="mt"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="refresh"><button class="btn" type="submit">Refresh now</button></form><?php endif; ?>
            <h3 class="mt">How it works</h3>
            <ul class="muted">
                <li>Customers buy USD with BCash and sell USD back to BCash. USD stays in their Ultimate App USD wallet.</li>
                <li>The fee is shown in PHP before they confirm, and booked as <b>USD exchange fees</b> in the ledger.</li>
                <li>A new automatic rate outside PHP <?= FX_SANE_MIN ?>–<?= FX_SANE_MAX ?>, or more than <?= (int) (FX_MAX_JUMP * 100) ?>% away from the last one, is refused. Use a manual rate if the market really moved that much.</li>
                <li>Hourly cron (recommended): <code>php …/cron/fx-refresh.php</code></li>
            </ul>
            <h3 class="mt">Recent rates</h3>
            <div class="table-wrap"><table><thead><tr><th>Checked</th><th class="num">PHP per USD</th></tr></thead><tbody>
            <?php foreach ($pdo->query("SELECT * FROM fx_rates ORDER BY id DESC LIMIT 10") as $r): ?><tr><td><?= e(date('M j, g:i A', strtotime((string) $r['fetched_at']))) ?><small><?= e($r['source']) ?></small></td><td class="num"><?= e(number_format((float) $r['rate'], 4)) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>
    </div>
<?php else:
    $side = isset(FX_SIDES[q('side')]) ? q('side') : '';
    $search = q('q');
    $where = ['1=1']; $params = [];
    if ($side) { $where[] = 't.side = ?'; $params[] = $side; }
    if ($search !== '') { $where[] = '(t.reference LIKE ? OR u.full_name LIKE ? OR u.mobile LIKE ?)'; array_push($params, ...array_fill(0, 3, '%' . $search . '%')); }
    $sql = ' FROM fx_trades t JOIN users u ON u.id = t.user_id WHERE ' . implode(' AND ', $where);
    if (q('export') === 'csv') {
        $s = $pdo->prepare('SELECT t.*, u.full_name' . $sql . ' ORDER BY t.id DESC');
        $s->execute($params);
        admin_audit('export_fx');
        $rows = static function () use ($s): Generator { foreach ($s as $r) yield [$r['reference'], $r['created_at'], $r['full_name'], FX_SIDES[$r['side']], fx_usd((int) $r['usd_cents']), $r['rate'], centavos_to_decimal((int) $r['gross_php_centavos']), $r['fee_bp'] / 100 . '%', centavos_to_decimal((int) $r['fee_php_centavos']), centavos_to_decimal((int) $r['total_php_centavos'])]; };
        csv_download('usd-exchange-' . date('Ymd') . '.csv', ['Reference', 'Date', 'Customer', 'Side', 'USD', 'Rate', 'PHP at mid', 'Fee %', 'Fee PHP', 'Customer paid / got PHP'], $rows());
    }
    $k = $pdo->query("SELECT COUNT(CASE WHEN created_at >= CURDATE() THEN 1 END) today_n,
        COALESCE(SUM(CASE WHEN created_at >= CURDATE() THEN fee_php_centavos END),0) today_fee, COALESCE(SUM(fee_php_centavos),0) fees,
        COALESCE(SUM(CASE WHEN side='buy_usd' AND created_at >= CURDATE() THEN usd_cents END),0) bought, COALESCE(SUM(CASE WHEN side='sell_usd' AND created_at >= CURDATE() THEN usd_cents END),0) sold FROM fx_trades")->fetch();
    $held = (int) $pdo->query('SELECT COALESCE(SUM(balance_cents),0) FROM usd_wallets')->fetchColumn();
    [$page, $per, $offset] = paging();
    $cnt = $pdo->prepare('SELECT COUNT(*)' . $sql); $cnt->execute($params); $total = (int) $cnt->fetchColumn();
    $s = $pdo->prepare('SELECT t.*, u.full_name, u.mobile' . $sql . " ORDER BY t.id DESC LIMIT $per OFFSET $offset"); $s->execute($params); $rows = $s->fetchAll();
    ?>
    <div class="grid kpis">
        <div class="card kpi hero"><small>USD held by customers</small><strong>$<?= fx_usd($held) ?></strong><span><?= $rate ? '≈ ₱' . peso(intdiv($held * $rate['micro'], 1000000)) . ' at today\'s rate' : 'No rate yet' ?></span></div>
        <div class="card kpi"><small>Fees today</small><strong>₱<?= peso((int) $k['today_fee']) ?></strong><span><?= (int) $k['today_n'] ?> exchange(s)</span></div>
        <div class="card kpi"><small>Today bought / sold</small><strong>$<?= fx_usd((int) $k['bought']) ?></strong><span>sold $<?= fx_usd((int) $k['sold']) ?></span></div>
        <div class="card kpi"><small>All-time fees</small><strong>₱<?= peso((int) $k['fees']) ?></strong><span><?= $rate ? 'Rate ₱' . fx_format_rate($rate['micro']) . ' · ' . e($rate['source']) : '' ?></span></div>
    </div>
    <div class="tabs mt"><?php foreach (['' => 'All', 'buy_usd' => 'Buy USD', 'sell_usd' => 'Sell USD'] as $key => $l): ?><a class="<?= $side === $key ? 'active' : '' ?>" href="?<?= e(http_build_query(array_filter(['side' => $key]))) ?>"><?= $l ?></a><?php endforeach; ?></div>
    <form class="toolbar" method="get"><?php if ($side): ?><input type="hidden" name="side" value="<?= e($side) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Reference, customer, mobile" aria-label="Search"><button class="btn" type="submit">Filter</button><span class="spacer"></span>
        <a class="btn" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>"><?= icon('download') ?> Export CSV</a></form>
    <div class="table-wrap"><table>
        <thead><tr><th>Reference</th><th>Customer</th><th>Side</th><th class="num">USD</th><th class="num">Rate</th><th class="num">Fee</th><th class="num">Customer paid / got</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="muted">No exchanges yet.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?><tr>
            <td><b><?= e($r['reference']) ?></b><small><?= e($r['created_at']) ?></small></td>
            <td><a href="customer.php?id=<?= (int) $r['user_id'] ?>"><?= e($r['full_name']) ?></a><small><?= e($r['mobile']) ?></small></td>
            <td><?= e(FX_SIDES[$r['side']]) ?></td>
            <td class="num">$<?= fx_usd((int) $r['usd_cents']) ?></td>
            <td class="num"><?= e(number_format((float) $r['rate'], 4)) ?></td>
            <td class="num">₱<?= peso((int) $r['fee_php_centavos']) ?><small><?= fx_percent((int) $r['fee_bp']) ?></small></td>
            <td class="num"><?= $r['side'] === 'buy_usd' ? '-' : '+' ?>₱<?= peso((int) $r['total_php_centavos']) ?></td>
        </tr><?php endforeach; ?>
        </tbody></table></div>
    <?= pager($page, $per, $total) ?>
<?php endif; ?>
<?php admin_footer(); ?>
