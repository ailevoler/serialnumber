<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/fx.php';
$admin = admin_require('finance.view');
$view = in_array(q('view'), ['settings', 'currencies'], true) ? q('view') : 'trades';

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
$refreshFlash = static function (PDO $pdo, string $prefix): void {
    try {
        $res = fx_refresh($pdo);
        $usd = $res['rates']['USD'] ?? fx_latest_row($pdo, 'USD');
        $msg = $prefix . count($res['rates']) . ' rate(s) updated' . ($usd ? '; 1 USD = PHP ' . fx_format_rate(fx_rate_scaled((string) $usd['rate'])) . ' (' . fx_source_label((string) $usd['source']) . ')' : '') . '.';
        flash($res['errors'] ? 'error' : 'success', $msg . ($res['errors'] ? ' Skipped: ' . implode(' · ', array_slice($res['errors'], 0, 6)) . (count($res['errors']) > 6 ? ' …' : '') : ''));
    } catch (Throwable $e) {
        flash('error', $prefix . 'rates could not be updated: ' . $e->getMessage());
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_guard();
    $action = p('action');
    $back = 'fx.php?view=' . ($action === 'currencies' ? 'currencies' : 'settings');
    try {
        if (!admin_can('settings')) throw new InvalidArgumentException('Only a Super Admin can change currency exchange.');
        $id = (int) $admin['id'];
        if ($action === 'settings') {
            $min = $usdToCents(p('min_usd'), 'Minimum');
            $max = $usdToCents(p('max_usd'), 'Maximum');
            if ($min > $max) throw new InvalidArgumentException('The minimum cannot be more than the maximum.');
            $provider = p('provider');
            $manual = trim(p('manual_rate'));
            if ($provider === 'manual' && (!preg_match('/^\d{1,3}(?:\.\d{1,6})?$/D', $manual) || (float) $manual < FX_USD_SANE_MIN || (float) $manual > FX_USD_SANE_MAX)) {
                throw new InvalidArgumentException('Enter the manual rate in PHP per USD, between ' . FX_USD_SANE_MIN . ' and ' . FX_USD_SANE_MAX . ' (e.g. 58.25).');
            }
            $pdo->beginTransaction();
            setting_save($pdo, 'fx.enabled', p('enabled') === '1' ? '1' : '0', $id);
            setting_save($pdo, 'fx.provider', $provider, $id);
            if ($manual !== '') setting_save($pdo, 'fx.manual_rate', $manual, $id);
            setting_save($pdo, 'fx.buy_fee_bp', (string) $percentToBp(p('buy_fee'), 'Buy USD fee'), $id);
            setting_save($pdo, 'fx.sell_fee_bp', (string) $percentToBp(p('sell_fee'), 'Sell USD fee'), $id);
            setting_save($pdo, 'fx.other_buy_fee_bp', (string) $percentToBp(p('other_buy_fee'), 'Buy fee for other currencies'), $id);
            setting_save($pdo, 'fx.other_sell_fee_bp', (string) $percentToBp(p('other_sell_fee'), 'Sell fee for other currencies'), $id);
            setting_save($pdo, 'fx.min_usd_cents', (string) $min, $id);
            setting_save($pdo, 'fx.max_usd_cents', (string) $max, $id);
            setting_save($pdo, 'fx.refresh_minutes', p('refresh_minutes'), $id);
            setting_save($pdo, 'fx.max_age_hours', p('max_age_hours'), $id);
            $pdo->commit();
            admin_audit('fx_settings', 'settings', 'fx', ['enabled' => p('enabled') === '1', 'provider' => $provider, 'manual_rate' => $manual, 'buy_fee' => p('buy_fee'), 'sell_fee' => p('sell_fee'), 'other_buy_fee' => p('other_buy_fee'), 'other_sell_fee' => p('other_sell_fee'), 'min_usd' => p('min_usd'), 'max_usd' => p('max_usd')]);
            $refreshFlash($pdo, 'Settings saved. ');
        } elseif ($action === 'currencies') {
            $picked = array_values(array_filter((array) ($_POST['ccy'] ?? []), 'is_string'));
            $list = fx_parse_currency_list(implode(',', $picked));
            setting_save($pdo, 'fx.currencies', implode(',', $list), $id);
            admin_audit('fx_currencies', 'settings', 'fx', ['currencies' => $list]);
            $refreshFlash($pdo, count($list) . ' currencies offered. ');
        } elseif ($action === 'refresh') {
            $refreshFlash($pdo, '');
            admin_audit('fx_refresh', 'settings', 'fx');
        }
        redirect($back);
    } catch (InvalidArgumentException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $ex->getMessage());
        redirect($back);
    }
}

admin_header('Currency exchange', 'fx');
if (!fx_ready($pdo)) {
    echo '<div class="flash error" role="status">Import <b>database/fx_migration.sql</b>, then <b>database/fx_currencies_migration.sql</b> once in phpMyAdmin, then reload this page.</div>';
    admin_footer();
    exit;
}
$cfg = fx_config();
$rate = fx_current($pdo, FX_MAIN, false);
$board = fx_board($pdo);
$ro = admin_can('settings') ? '' : ' disabled';
?>
<div class="tabs"><a class="<?= $view === 'trades' ? 'active' : '' ?>" href="fx.php">Exchanges</a><a class="<?= $view === 'settings' ? 'active' : '' ?>" href="fx.php?view=settings">Rate &amp; fees</a><a class="<?= $view === 'currencies' ? 'active' : '' ?>" href="fx.php?view=currencies">Currencies (<?= count($cfg['currencies']) ?>)</a></div>
<?php if (!$cfg['enabled']): ?><div class="flash error" role="status">Currency exchange is <b>closed</b> to customers. Open it in <a href="fx.php?view=settings">Rate &amp; fees</a>.</div>
<?php elseif ($rate && !$rate['tradable']): ?><div class="flash error" role="status">Customers cannot exchange right now: the USD rate is <?= (int) $rate['age_minutes'] ?> minutes old. <a href="fx.php?view=settings">Refresh the rates</a> or set a manual USD rate.</div><?php endif; ?>

<?php if ($view === 'settings'): ?>
    <div class="grid two">
        <form class="card" method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="settings">
            <h2>Rate &amp; fees <?= status_badge($cfg['enabled'] ? 'active' : 'disabled') ?></h2>
            <label><input type="checkbox" name="enabled" value="1"<?= $cfg['enabled'] ? ' checked' : '' ?><?= $ro ?>> Customers can buy and sell currencies</label>
            <h3 class="mt">Main pair: USD/PHP</h3>
            <div class="form-grid">
                <div class="field"><label for="buy_fee">Buy USD fee (%)</label><input id="buy_fee" name="buy_fee" type="text" inputmode="decimal" required value="<?= e(number_format($cfg['buy_fee_bp'] / 100, 2)) ?>"<?= $ro ?>><small>Added on top of the mid rate when a customer buys USD. Example: 0.50 to 1.00.</small></div>
                <div class="field"><label for="sell_fee">Sell USD fee (%)</label><input id="sell_fee" name="sell_fee" type="text" inputmode="decimal" required value="<?= e(number_format($cfg['sell_fee_bp'] / 100, 2)) ?>"<?= $ro ?>><small>Taken from the PHP a customer gets when selling USD.</small></div>
                <div class="field"><label for="provider">Rate source</label><select id="provider" name="provider"<?= $ro ?>>
                    <?php foreach (['auto' => 'Automatic: Frankfurter, then currency-api', 'frankfurter' => 'Frankfurter only (ECB, ~30 currencies)', 'currency_api' => 'currency-api only (150+ currencies)', 'manual' => 'Manual USD rate (others automatic)'] as $k => $l): ?><option value="<?= $k ?>"<?= $cfg['provider'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select><small>Both are free, open-source APIs with no key.</small></div>
                <div class="field"><label for="manual_rate">Manual USD rate (PHP per 1 USD)</label><input id="manual_rate" name="manual_rate" type="text" inputmode="decimal" value="<?= e($cfg['manual_rate']) ?>" placeholder="e.g. 58.25"<?= $ro ?>><small>Used only when the source is Manual.</small></div>
            </div>
            <h3 class="mt">Other currencies</h3>
            <div class="form-grid">
                <div class="field"><label for="other_buy_fee">Buy fee (%)</label><input id="other_buy_fee" name="other_buy_fee" type="text" inputmode="decimal" required value="<?= e(number_format($cfg['other_buy_fee_bp'] / 100, 2)) ?>"<?= $ro ?>><small>EUR, JPY, KRW and every other currency.</small></div>
                <div class="field"><label for="other_sell_fee">Sell fee (%)</label><input id="other_sell_fee" name="other_sell_fee" type="text" inputmode="decimal" required value="<?= e(number_format($cfg['other_sell_fee_bp'] / 100, 2)) ?>"<?= $ro ?>></div>
            </div>
            <h3 class="mt">Limits and freshness</h3>
            <div class="form-grid">
                <div class="field"><label for="min_usd">Minimum per exchange (USD value)</label><input id="min_usd" name="min_usd" type="text" inputmode="decimal" required value="<?= e(fx_usd($cfg['min_usd_cents'])) ?>"<?= $ro ?>><small>Other currencies use the same limit, converted at today's rate.</small></div>
                <div class="field"><label for="max_usd">Maximum per exchange (USD value)</label><input id="max_usd" name="max_usd" type="text" inputmode="decimal" required value="<?= e(str_replace(',', '', fx_usd($cfg['max_usd_cents']))) ?>"<?= $ro ?>></div>
                <div class="field"><label for="refresh_minutes">Refresh rates every (minutes)</label><input id="refresh_minutes" name="refresh_minutes" type="number" min="5" max="1440" required value="<?= (int) $cfg['refresh_minutes'] ?>"<?= $ro ?>></div>
                <div class="field"><label for="max_age_hours">Pause exchanges when a rate is older than (hours)</label><input id="max_age_hours" name="max_age_hours" type="number" min="1" max="168" required value="<?= (int) $cfg['max_age_hours'] ?>"<?= $ro ?>></div>
            </div>
            <?php if (!$ro): ?><div class="form-actions"><button class="btn primary" type="submit">Save</button></div><?php endif; ?>
        </form>
        <section class="card"><h2>Main pair: USD/PHP</h2>
            <?php if ($rate): ?>
                <p class="fx-admin-rate"><b>1 USD = PHP <?= fx_format_rate($rate['scaled']) ?></b></p>
                <dl class="facts">
                    <div><dt>Customers buy USD at</dt><dd>PHP <?= fx_format_rate($rate['buy_scaled']) ?> (<?= fx_percent($rate['buy_fee_bp']) ?> fee)</dd></div>
                    <div><dt>Customers sell USD at</dt><dd>PHP <?= fx_format_rate($rate['sell_scaled']) ?> (<?= fx_percent($rate['sell_fee_bp']) ?> fee)</dd></div>
                    <div><dt>Source</dt><dd><?= e(fx_source_label($rate['source'])) ?></dd></div>
                    <div><dt>Last checked</dt><dd><?= e($rate['fetched_at']) ?> (<?= (int) $rate['age_minutes'] ?> min ago)</dd></div>
                </dl>
            <?php else: ?><p class="muted">No rate yet. Press <b>Refresh now</b>.</p><?php endif; ?>
            <?php if (!$ro): ?><form method="post" class="mt"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="refresh"><button class="btn" type="submit">Refresh now</button></form><?php endif; ?>
            <h3 class="mt">How it works</h3>
            <ul class="muted">
                <li>Customers buy a currency with BCash and sell it back to BCash. It stays in their Ultimate App wallet for that currency.</li>
                <li>The fee is shown in PHP before they confirm and booked as <b>USD exchange fees</b> in the ledger.</li>
                <li>Every rate is PHP per 1 unit, from one source (cross rate through USD). A USD rate outside PHP <?= FX_USD_SANE_MIN ?>–<?= FX_USD_SANE_MAX ?>, or any rate more than <?= (int) (FX_MAX_JUMP * 100) ?>% away from its last value, is refused and the last good rate is kept.</li>
                <li>Hourly cron (recommended): <code>php …/cron/fx-refresh.php</code></li>
            </ul>
        </section>
    </div>
<?php elseif ($view === 'currencies'):
    $catalog = fx_currency_catalog();
    $on = array_flip($cfg['currencies']);
    $groups = ['Popular' => array_values(array_intersect(FX_POPULAR, array_keys($catalog))), 'All other currencies' => array_values(array_diff(array_keys($catalog), FX_POPULAR))];
    sort($groups['All other currencies']);
    ?>
    <form class="card" method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="currencies">
        <h2>Currencies offered to customers</h2>
        <p class="muted">USD/PHP is the main pair and is always on. Tick the other currencies customers can buy and sell. Frankfurter covers about 30 major currencies; the rest come from currency-api. A currency with no rate yet stays hidden from customers.</p>
        <input type="search" class="fx-ccy-filter" placeholder="Filter: code or name" aria-label="Filter currencies" data-fx-filter>
        <?php foreach ($groups as $label => $codes): ?>
            <h3 class="mt"><?= e($label) ?></h3>
            <div class="fx-ccy-grid">
                <?php foreach ($codes as $c): $r = $board[$c] ?? null; ?>
                    <label class="fx-ccy" data-fx-item="<?= e(strtolower($c . ' ' . fx_currency_name($c))) ?>"><input type="checkbox" name="ccy[]" value="<?= e($c) ?>"<?= isset($on[$c]) ? ' checked' : '' ?><?= $c === FX_MAIN ? ' checked disabled' : $ro ?>>
                        <span><b><?= fx_currency_flag($c) ?> <?= e($c) ?></b><?= $c === FX_MAIN ? ' <em>Main</em>' : '' ?><small><?= e(fx_currency_name($c)) ?><?= $r ? ' · PHP ' . fx_format_rate($r['scaled']) : '' ?></small></span></label>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <input type="hidden" name="ccy[]" value="USD">
        <?php if (!$ro): ?><div class="form-actions"><button class="btn primary" type="submit">Save currencies</button></div><?php endif; ?>
    </form>
    <section class="card mt"><h2>Current rates (PHP per 1 unit)</h2>
        <div class="table-wrap"><table><thead><tr><th>Currency</th><th class="num">Mid</th><th class="num">Customers buy</th><th class="num">Customers sell</th><th>Source</th><th>Checked</th></tr></thead><tbody>
        <?php foreach ($cfg['currencies'] as $c): $r = $board[$c] ?? null; ?>
            <tr><td><b><?= fx_currency_flag($c) ?> <?= e($c) ?></b><small><?= e(fx_currency_name($c)) ?></small></td>
            <?php if ($r): ?><td class="num"><?= fx_format_rate($r['scaled']) ?></td><td class="num"><?= fx_format_rate($r['buy_scaled']) ?></td><td class="num"><?= fx_format_rate($r['sell_scaled']) ?></td><td><?= e($r['source']) ?></td><td><?= e(date('M j, g:i A', strtotime($r['fetched_at']))) ?><?= $r['tradable'] ? '' : ' <small>' . e($r['reason']) . '</small>' ?></td>
            <?php else: ?><td colspan="5" class="muted">No rate yet. Press Refresh now in Rate &amp; fees.</td><?php endif; ?></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>
<?php else:
    $side = isset(FX_SIDES[q('side')]) ? q('side') : '';
    $ccyF = isset(fx_currency_catalog()[q('ccy')]) ? q('ccy') : '';
    $search = q('q');
    $where = ['1=1']; $params = [];
    if ($side) { $where[] = 't.side = ?'; $params[] = $side; }
    if ($ccyF) { $where[] = 't.currency = ?'; $params[] = $ccyF; }
    if ($search !== '') { $where[] = '(t.reference LIKE ? OR u.full_name LIKE ? OR u.mobile LIKE ?)'; array_push($params, ...array_fill(0, 3, '%' . $search . '%')); }
    $sql = ' FROM fx_trades t JOIN users u ON u.id = t.user_id WHERE ' . implode(' AND ', $where);
    if (q('export') === 'csv') {
        $s = $pdo->prepare('SELECT t.*, u.full_name' . $sql . ' ORDER BY t.id DESC');
        $s->execute($params);
        admin_audit('export_fx');
        $rows = static function () use ($s): Generator { foreach ($s as $r) yield [$r['reference'], $r['created_at'], $r['full_name'], FX_SIDES[$r['side']], $r['currency'], str_replace(',', '', fx_amount((int) $r['amount_minor'], $r['currency'])), $r['rate'], centavos_to_decimal((int) $r['gross_php_centavos']), $r['fee_bp'] / 100 . '%', centavos_to_decimal((int) $r['fee_php_centavos']), centavos_to_decimal((int) $r['total_php_centavos'])]; };
        csv_download('currency-exchange-' . date('Ymd') . '.csv', ['Reference', 'Date', 'Customer', 'Side', 'Currency', 'Amount', 'Rate (PHP per unit)', 'PHP at mid', 'Fee %', 'Fee PHP', 'Customer paid / got PHP'], $rows());
    }
    $k = $pdo->query("SELECT COUNT(CASE WHEN created_at >= CURDATE() THEN 1 END) today_n,
        COALESCE(SUM(CASE WHEN created_at >= CURDATE() THEN fee_php_centavos END),0) today_fee, COALESCE(SUM(fee_php_centavos),0) fees FROM fx_trades")->fetch();
    $holdings = $pdo->query('SELECT currency, SUM(balance_minor) total FROM fx_wallets WHERE balance_minor > 0 GROUP BY currency')->fetchAll();
    $heldPhp = 0; $heldUsd = 0;
    foreach ($holdings as $h) {
        if ($h['currency'] === 'USD') $heldUsd = (int) $h['total'];
        $row = fx_latest_row($pdo, $h['currency']);
        if ($row) $heldPhp += fx_php_value((int) $h['total'], fx_rate_scaled((string) $row['rate']), $h['currency'], false);
    }
    [$page, $per, $offset] = paging();
    $cnt = $pdo->prepare('SELECT COUNT(*)' . $sql); $cnt->execute($params); $total = (int) $cnt->fetchColumn();
    $s = $pdo->prepare('SELECT t.*, u.full_name, u.mobile' . $sql . " ORDER BY t.id DESC LIMIT $per OFFSET $offset"); $s->execute($params); $rows = $s->fetchAll();
    ?>
    <div class="grid kpis">
        <div class="card kpi hero"><small>Currency held by customers</small><strong>₱<?= peso($heldPhp) ?></strong><span>at today's rates · <?= count($holdings) ?> currenc<?= count($holdings) === 1 ? 'y' : 'ies' ?></span></div>
        <div class="card kpi"><small>USD held (main pair)</small><strong>$<?= fx_usd($heldUsd) ?></strong><span><?= $rate ? 'Rate ₱' . fx_format_rate($rate['scaled']) . ' · ' . e($rate['source']) : 'No rate yet' ?></span></div>
        <div class="card kpi"><small>Fees today</small><strong>₱<?= peso((int) $k['today_fee']) ?></strong><span><?= (int) $k['today_n'] ?> exchange(s)</span></div>
        <div class="card kpi"><small>All-time fees</small><strong>₱<?= peso((int) $k['fees']) ?></strong><span>All currencies</span></div>
    </div>
    <?php if ($holdings): ?><p class="muted mt">Held: <?php foreach ($holdings as $i => $h): ?><?= $i ? ' · ' : '' ?><a href="?ccy=<?= e($h['currency']) ?>"><?= e($h['currency']) ?> <?= fx_amount((int) $h['total'], $h['currency']) ?></a><?php endforeach; ?></p><?php endif; ?>
    <div class="tabs mt"><?php foreach (['' => 'All', 'buy' => 'Customers bought', 'sell' => 'Customers sold'] as $key => $l): ?><a class="<?= $side === $key ? 'active' : '' ?>" href="?<?= e(http_build_query(array_filter(['side' => $key, 'ccy' => $ccyF]))) ?>"><?= $l ?></a><?php endforeach; ?></div>
    <form class="toolbar" method="get"><?php if ($side): ?><input type="hidden" name="side" value="<?= e($side) ?>"><?php endif; ?>
        <select name="ccy" data-autosubmit aria-label="Currency"><option value="">All currencies</option><?php foreach ($cfg['currencies'] as $c): ?><option value="<?= e($c) ?>"<?= $ccyF === $c ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select>
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Reference, customer, mobile" aria-label="Search"><button class="btn" type="submit">Filter</button><span class="spacer"></span>
        <a class="btn" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>"><?= icon('download') ?> Export CSV</a></form>
    <div class="table-wrap"><table>
        <thead><tr><th>Reference</th><th>Customer</th><th>Side</th><th class="num">Amount</th><th class="num">Rate</th><th class="num">Fee</th><th class="num">Customer paid / got</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="muted">No exchanges yet.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?><tr>
            <td><b><?= e($r['reference']) ?></b><small><?= e($r['created_at']) ?></small></td>
            <td><a href="customer.php?id=<?= (int) $r['user_id'] ?>"><?= e($r['full_name']) ?></a><small><?= e($r['mobile']) ?></small></td>
            <td><?= e(FX_SIDES[$r['side']] . ' ' . $r['currency']) ?></td>
            <td class="num"><?= e($r['currency']) ?> <?= fx_amount((int) $r['amount_minor'], $r['currency']) ?></td>
            <td class="num"><?= e(fx_format_rate(fx_rate_scaled((string) $r['rate']))) ?></td>
            <td class="num">₱<?= peso((int) $r['fee_php_centavos']) ?><small><?= fx_percent((int) $r['fee_bp']) ?></small></td>
            <td class="num"><?= $r['side'] === 'buy' ? '-' : '+' ?>₱<?= peso((int) $r['total_php_centavos']) ?></td>
        </tr><?php endforeach; ?>
        </tbody></table></div>
    <?= pager($page, $per, $total) ?>
<?php endif; ?>
<?php admin_footer(['assets/fx-admin.js?v=1']); ?>
