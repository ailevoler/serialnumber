<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/cancellation.php';
$admin = admin_require('settings');
$tab = in_array(q('tab'), ['charges', 'maps', 'uride'], true) ? q('tab') : 'payments';
require_once __DIR__ . '/../includes/uride.php';
$schema = settings_schema();
$testResult = null;

$pesoToCentavos = static function (string $v, string $label): string {
    $v = trim(str_replace(',', '', $v));
    if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $v)) throw new InvalidArgumentException($label . ' must be a peso amount like 5 or 5.50.');
    [$w, $f] = array_pad(explode('.', $v, 2), 2, '');
    return (string) ((int) $w * 100 + (int) str_pad($f, 2, '0'));
};
$pctToBp = static function (string $v, string $label): string {
    $v = trim($v);
    if (!preg_match('/^\d{1,2}(?:\.\d{1,2})?$/D', $v)) throw new InvalidArgumentException($label . ' must be a percentage like 1.5 (max 20).');
    return (string) (int) round((float) $v * 100);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_guard();
    $changed = [];
    try {
        $pdo->beginTransaction();
        if (p('section') === 'payments') {
            foreach (['paymongo.mode', 'paymongo.qr_flow', 'paymongo.app_url', 'paymongo.test_public_key', 'paymongo.live_public_key', 'paymongo.test_secret_key', 'paymongo.live_secret_key', 'paymongo.test_webhook_secret', 'paymongo.live_webhook_secret'] as $key) {
                $field = str_replace('.', '__', $key);
                if (!empty($_POST['clear'][$field])) { setting_clear($pdo, $key); $changed[] = $key . ' (cleared)'; continue; }
                if (!array_key_exists($field, $_POST)) continue;
                $value = p($field);
                if (empty($schema[$key]['secret']) && $value === '' ) { setting_clear($pdo, $key); continue; }
                if (setting_save($pdo, $key, $value, (int) $admin['id'])) $changed[] = $key;
            }
            if (p('paymongo__mode') === 'live' && !setting('paymongo.live_secret_key') && !str_starts_with((string) (paymongo_settings()['live_secret_key'] ?? ''), 'sk_live_')) {
                throw new InvalidArgumentException('Add the Live secret key before switching to Live mode.');
            }
        } elseif (p('section') === 'maps') {
            foreach (['maps.browser_key' => 'maps_key', 'maps.map_id' => 'maps_map_id'] as $key => $field) {
                if (trim(p($field)) === '') setting_clear($pdo, $key); else setting_save($pdo, $key, p($field), (int) $admin['id']);
            }
            setting_save($pdo, 'maps.default_lat', p('maps_lat', '11.9674'), (int) $admin['id']);
            setting_save($pdo, 'maps.default_lng', p('maps_lng', '121.9248'), (int) $admin['id']);
            setting_save($pdo, 'maps.default_zoom', p('maps_zoom', '14'), (int) $admin['id']);
            $changed[] = 'maps';
        } elseif (p('section') === 'paymethods') {
            setting_save($pdo, 'pay.cash_enabled', p('pay_cash') === '1' ? '1' : '0', (int) $admin['id']);
            setting_save($pdo, 'pay.bcash_enabled', p('pay_bcash') === '1' ? '1' : '0', (int) $admin['id']);
            $changed[] = 'payment methods';
        } elseif (p('section') === 'cancelfees') {
            foreach (['uride' => 'URide', 'booking' => 'UPass / UGo / ULocal', 'ueat' => 'UEat'] as $area => $label) {
                $v = trim(p("{$area}_pct", '0'));
                if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/D', $v) || (float) $v > 100) throw new InvalidArgumentException("$label cancellation fee must be 0 to 100%.");
                setting_save($pdo, "cancel.{$area}_pct", $v, (int) $admin['id']);
            }
            setting_save($pdo, 'cancel.uride_free_min', (string) max(0, min(60, (int) p('uride_free_min', '2'))), (int) $admin['id']);
            $changed[] = 'cancellation fees';
        } elseif (p('section') === 'charges') {
            foreach (['credits' => 'Credits', 'boracay_cash' => 'BCash'] as $src => $label) {
                setting_save($pdo, "p2m.$src.enabled", p("{$src}_enabled") === '1' ? '1' : '0', (int) $admin['id']);
                setting_save($pdo, "p2m.$src.percent_bp", $pctToBp(p("{$src}_percent", '0'), "$label charge"), (int) $admin['id']);
                setting_save($pdo, "p2m.$src.fixed_centavos", $pesoToCentavos(p("{$src}_fixed", '0'), "$label fixed charge"), (int) $admin['id']);
                setting_save($pdo, "p2m.$src.bearer", p("{$src}_bearer"), (int) $admin['id']);
            }
            $min = $pesoToCentavos(p('min', '1'), 'Minimum payment'); $max = $pesoToCentavos(p('max', '50000'), 'Maximum payment');
            if ((int) $min >= (int) $max) throw new InvalidArgumentException('Minimum payment must be lower than the maximum.');
            setting_save($pdo, 'p2m.min_centavos', $min, (int) $admin['id']);
            setting_save($pdo, 'p2m.max_centavos', $max, (int) $admin['id']);
            setting_save($pdo, 'fees.buy_credits_centavos', $pesoToCentavos(p('buy_fee', '0'), 'Buy Credits fee'), (int) $admin['id']);
            setting_save($pdo, 'fees.mctc_conversion_credits', (string) (int) p('mctc_fee', '0'), (int) $admin['id']);
            setting_save($pdo, 'mctc.all_approved', p('mctc_all') === '1' ? '1' : '0', (int) $admin['id']);
            $changed[] = 'charges';
        } elseif (p('section') === 'uride') {
            $aid = (int) $admin['id'];
            foreach (array_keys(uride_vehicles()) as $v) {
                setting_save($pdo, "uride.$v.enabled", p("{$v}_enabled") === '1' ? '1' : '0', $aid);
                setting_save($pdo, "uride.$v.base_centavos", $pesoToCentavos(p("{$v}_base", '0'), 'Base fare'), $aid);
                setting_save($pdo, "uride.$v.included_km", trim(p("{$v}_included", '0')) ?: '0', $aid);
                setting_save($pdo, "uride.$v.per_km_centavos", $pesoToCentavos(p("{$v}_per_km", '0'), 'Per km rate'), $aid);
                setting_save($pdo, "uride.$v.minimum_centavos", $pesoToCentavos(p("{$v}_minimum", '0'), 'Minimum fare'), $aid);
            }
            foreach (['enabled', 'credits_enabled', 'cash_enabled', 'bcash_enabled'] as $k) setting_save($pdo, "uride.$k", p($k) === '1' ? '1' : '0', $aid);
            // Rent a Ride (vehicle with driver by the hour / whole day)
            foreach (array_keys(uride_rentable_vehicles()) as $v) {
                setting_save($pdo, "uride.$v.rental_enabled", p("{$v}_rental_enabled") === '1' ? '1' : '0', $aid);
                setting_save($pdo, "uride.$v.rental_hour_centavos", $pesoToCentavos(p("{$v}_rental_hour", '0'), 'Rental per hour'), $aid);
                setting_save($pdo, "uride.$v.rental_min_hours", (string) max(1, (int) p("{$v}_rental_min", '1')), $aid);
                setting_save($pdo, "uride.$v.rental_day_centavos", $pesoToCentavos(p("{$v}_rental_day", '0'), 'Whole-day rental'), $aid);
            }
            setting_save($pdo, 'uride.rental_enabled', p('rental_enabled') === '1' ? '1' : '0', $aid);
            setting_save($pdo, 'uride.rental_day_hours', (string) (int) p('rental_day_hours', '8'), $aid);
            setting_save($pdo, 'uride.rental_max_hours', (string) (int) p('rental_max_hours', '12'), $aid);
            setting_save($pdo, 'uride.rental_schedule_days', (string) (int) p('rental_schedule_days', '7'), $aid);
            // Self-drive (customer drives; deposit held in Credits)
            foreach (array_keys(uride_rentable_vehicles()) as $v) {
                setting_save($pdo, "uride.$v.self_enabled", p("{$v}_self_enabled") === '1' ? '1' : '0', $aid);
                setting_save($pdo, "uride.$v.self_hour_centavos", $pesoToCentavos(p("{$v}_self_hour", '0'), 'Self-drive per hour'), $aid);
                setting_save($pdo, "uride.$v.self_min_hours", (string) max(1, (int) p("{$v}_self_min", '1')), $aid);
                setting_save($pdo, "uride.$v.self_day_centavos", $pesoToCentavos(p("{$v}_self_day", '0'), 'Self-drive whole day'), $aid);
                setting_save($pdo, "uride.$v.self_deposit_centavos", $pesoToCentavos(p("{$v}_self_deposit", '0'), 'Self-drive deposit'), $aid);
            }
            setting_save($pdo, 'uride.self_drive_enabled', p('self_drive_enabled') === '1' ? '1' : '0', $aid);
            setting_save($pdo, 'uride.self_drive_kyc', p('self_drive_kyc') === '1' ? '1' : '0', $aid);
            setting_save($pdo, 'uride.self_drive_grace_min', (string) (int) p('self_drive_grace_min', '15'), $aid);
            if (p('credits_enabled') !== '1' && p('cash_enabled') !== '1' && p('bcash_enabled') !== '1') throw new InvalidArgumentException('Allow at least one payment method for rides.');
            setting_save($pdo, 'uride.road_factor', trim(p('road_factor', '1.3')), $aid);
            $night = trim(p('night_pct', '0'));
            if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $night) || (float) $night > 100) throw new InvalidArgumentException('Night surcharge must be a percentage from 0 to 100.');
            setting_save($pdo, 'uride.night_surcharge_bp', (string) (int) round((float) $night * 100), $aid);
            setting_save($pdo, 'uride.night_start', (string) (int) p('night_start', '22'), $aid);
            setting_save($pdo, 'uride.night_end', (string) (int) p('night_end', '5'), $aid);
            setting_save($pdo, 'uride.commission_type', p('commission_type'), $aid);
            $pct = trim(p('commission_pct', '0'));
            if (!preg_match('/^\d{1,2}(?:\.\d{1,2})?$/D', $pct) || (float) $pct > 50) throw new InvalidArgumentException('Commission percentage must be from 0 to 50.');
            setting_save($pdo, 'uride.commission_percent_bp', (string) (int) round((float) $pct * 100), $aid);
            setting_save($pdo, 'uride.commission_fixed_centavos', $pesoToCentavos(p('commission_fixed', '0'), 'Fixed commission'), $aid);
            setting_save($pdo, 'uride.dispatch_radius_km', trim(p('radius', '3')), $aid);
            setting_save($pdo, 'uride.request_timeout_min', (string) (int) p('timeout', '5'), $aid);
            setting_save($pdo, 'uride.min_wallet_centavos', $pesoToCentavos(p('min_wallet', '50'), 'Minimum wallet'), $aid);
            $tmin = $pesoToCentavos(p('topup_min', '50'), 'Minimum top-up'); $tmax = $pesoToCentavos(p('topup_max', '10000'), 'Maximum top-up');
            if ((int) $tmin >= (int) $tmax) throw new InvalidArgumentException('Minimum top-up must be lower than the maximum.');
            setting_save($pdo, 'uride.topup_min_centavos', $tmin, $aid);
            setting_save($pdo, 'uride.topup_max_centavos', $tmax, $aid);
            setting_save($pdo, 'uride.topup_fee_centavos', $pesoToCentavos(p('topup_fee', '0'), 'Top-up fee'), $aid);
            foreach (['qrph', 'mctc', 'bcash'] as $m) setting_save($pdo, "uride.topup_{$m}_enabled", p("topup_{$m}") === '1' ? '1' : '0', $aid);
            if (p('topup_qrph') !== '1' && p('topup_mctc') !== '1' && p('topup_bcash') !== '1') throw new InvalidArgumentException('Allow at least one driver top-up method.');
            setting_save($pdo, 'uride.rewards_enabled', p('rewards_enabled') === '1' ? '1' : '0', $aid);
            $ms = uride_parse_milestones(p('reward_milestones', ''));
            if (p('rewards_enabled') === '1' && !$ms) throw new InvalidArgumentException('Add at least one trip reward, e.g. 50:100.');
            setting_save($pdo, 'uride.reward_milestones', implode(', ', array_map(static fn($t, $c) => $t . ':' . rtrim(rtrim(number_format($c / 100, 2, '.', ''), '0'), '.'), array_keys($ms), $ms)), $aid);
            setting_save($pdo, 'uride.payout_min_centavos', $pesoToCentavos(p('payout_min', '500'), 'Minimum payout'), $aid);
            $changed[] = 'uride';
        }
        $pdo->commit();
        admin_audit('settings_updated', 'settings', p('section'), $changed);
        flash('success', 'Settings saved.');
    } catch (InvalidArgumentException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $ex->getMessage());
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $ex->getMessage());
    }
    redirect('settings.php?tab=' . (in_array(p('section'), ['paymethods', 'cancelfees'], true) ? 'charges' : (in_array(p('section'), ['charges', 'maps', 'uride'], true) ? p('section') : 'payments')));
}

// Optional connection test: lists webhooks with the active secret key.
if ($tab === 'payments' && q('test') === '1') {
    $cfg = paymongo_settings();
    $key = (string) ($cfg[$cfg['mode'] . '_secret_key'] ?? '');
    if (!str_starts_with($key, 'sk_' . $cfg['mode'] . '_')) {
        $testResult = ['error', 'No ' . $cfg['mode'] . ' secret key is configured.'];
    } elseif (!function_exists('curl_init')) {
        $testResult = ['error', 'cURL is not available on this server.'];
    } else {
        $ch = curl_init('https://api.paymongo.com/v1/webhooks');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $key . ':', CURLOPT_TIMEOUT => 12, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $body = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code === 200) {
            $hooks = json_decode((string) $body, true)['data'] ?? [];
            $url = $cfg['app_url'] . '/webhook.php';
            $match = array_values(array_filter($hooks, static fn($h) => ($h['attributes']['url'] ?? '') === $url));
            $testResult = ['success', 'Connected to PayMongo (' . strtoupper($cfg['mode']) . '). ' . count($hooks) . ' webhook(s) on the account; ' . ($match ? 'this app\'s webhook is registered and ' . ($match[0]['attributes']['status'] ?? 'unknown') . '.' : 'this app\'s webhook URL is NOT registered yet.')];
        } else {
            $testResult = ['error', $code === 401 ? 'PayMongo rejected the secret key (401).' : 'Could not reach PayMongo (HTTP ' . $code . ').'];
        }
    }
    admin_audit('paymongo_test', 'settings', 'payments', $testResult[1]);
}

$cfg = paymongo_settings();
$db = settings_all();
$src = static fn(string $key): string => isset($db[$key]) && $db[$key] !== '' ? 'Admin settings' : 'config file / environment';
admin_header('Settings', 'settings');
?>
<div class="tabs"><a class="<?= $tab === 'payments' ? 'active' : '' ?>" href="?tab=payments">Payments · PayMongo</a><a class="<?= $tab === 'charges' ? 'active' : '' ?>" href="?tab=charges">Charges &amp; limits</a><a class="<?= $tab === 'maps' ? 'active' : '' ?>" href="?tab=maps">Google Maps</a><a class="<?= $tab === 'uride' ? 'active' : '' ?>" href="?tab=uride">URide fares &amp; commission</a></div>
<?php if ($testResult): ?><div class="flash <?= e($testResult[0]) ?>" role="status"><?= e($testResult[1]) ?></div><?php endif; ?>
<?php if ($tab === 'payments'): ?>
<div class="grid two">
<form class="card" method="post" autocomplete="off">
    <h2>PayMongo <small>Third-party payment API</small></h2>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="section" value="payments">
    <div class="form-grid">
        <div class="field"><label for="mode">Mode</label><select id="mode" name="paymongo__mode"><option value="test"<?= $cfg['mode'] === 'test' ? ' selected' : '' ?>>Test (sandbox)</option><option value="live"<?= $cfg['mode'] === 'live' ? ' selected' : '' ?>>Live (real money)</option></select><small>Currently from <?= e($src('paymongo.mode')) ?>.</small></div>
        <div class="field"><label for="qr_flow">Buy Credits QR Ph</label><select id="qr_flow" name="paymongo__qr_flow"><option value="inapp"<?= setting('paymongo.qr_flow', 'inapp') === 'inapp' ? ' selected' : '' ?>>Show QR inside the app (recommended)</option><option value="checkout"<?= setting('paymongo.qr_flow', 'inapp') === 'checkout' ? ' selected' : '' ?>>Open PayMongo checkout page</option></select><small>In-app QR falls back to the checkout page automatically if PayMongo cannot issue one. Subscribe the webhook to <b>payment.paid</b> too.</small></div>
        <div class="field"><label for="app_url">App URL</label><input id="app_url" type="url" name="paymongo__app_url" value="<?= e($db['paymongo.app_url'] ?? '') ?>" placeholder="<?= e($cfg['app_url']) ?>"><small>Used for return links and the webhook URL.</small></div>
    </div>
    <?php foreach (['test' => 'Test keys', 'live' => 'Live keys'] as $m => $title): ?>
        <p class="section-title"><?= e($title) ?></p>
        <div class="form-grid">
            <div class="field"><label for="<?= $m ?>_pk">Public key</label><input id="<?= $m ?>_pk" type="text" name="paymongo__<?= $m ?>_public_key" value="<?= e($db["paymongo.{$m}_public_key"] ?? '') ?>" placeholder="pk_<?= $m ?>_…"></div>
            <?php foreach (['secret_key' => ['Secret key', 'sk_'], 'webhook_secret' => ['Webhook signing secret', 'whsk_']] as $f => [$label, $prefix]): $key = "paymongo.{$m}_{$f}"; $field = str_replace('.', '__', $key); $current = (string) ($cfg["{$m}_{$f}"] ?? ''); $isSet = str_starts_with($current, $prefix); ?>
            <div class="field"><label for="<?= e($field) ?>"><?= e($label) ?></label><input id="<?= e($field) ?>" type="password" name="<?= e($field) ?>" placeholder="<?= $isSet ? 'Saved — type to replace' : $prefix . ($f === 'secret_key' ? $m . '_' : '') . '…' ?>" autocomplete="new-password">
                <small><span class="secret-state"><?= $isSet ? e(settings_mask($current)) : 'Not set' ?></span> · <?= e($src($key)) ?><?php if (isset($db[$key])): ?> · <label><input type="checkbox" name="clear[<?= e($field) ?>]" value="1"> remove</label><?php endif; ?></small></div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    <div class="form-actions"><button class="btn primary" type="submit">Save payment settings</button><a class="btn" href="?tab=payments&amp;test=1">Test connection</a></div>
</form>
<div class="stack">
    <section class="card"><h2>Status</h2>
        <div class="kv"><span>Active mode</span><b><?= $cfg['mode'] === 'live' ? '<span class="badge good">LIVE</span>' : '<span class="badge warn">TEST · sandbox</span>' ?></b></div>
        <div class="kv"><span>Secret key</span><b><?= str_starts_with((string) ($cfg[$cfg['mode'] . '_secret_key'] ?? ''), 'sk_' . $cfg['mode'] . '_') ? 'Configured' : 'Missing' ?></b></div>
        <div class="kv"><span>Webhook secret</span><b><?= str_starts_with((string) ($cfg[$cfg['mode'] . '_webhook_secret'] ?? ''), 'whsk_') ? 'Configured' : 'Missing' ?></b></div>
        <div class="kv"><span>Webhook URL</span><b class="url"><?= e($cfg['app_url'] . '/webhook.php') ?></b></div>
    </section>
    <section class="card"><h2>How it works</h2>
        <p class="muted">Keys saved here override config/paymongo.php. Secret keys are encrypted in the database with the key in config/app_secret.php — back that file up with your site.</p>
        <p class="muted">In the PayMongo dashboard, register the webhook URL above for <b>checkout_session.payment.paid</b> and <b>payment.paid</b> and paste its signing secret here.</p>
        <p class="muted">Test and Live keep separate keys. Use a sandbox database for Test mode.</p>
    </section>
</div>
</div>
<?php elseif ($tab === 'maps'): $gm = google_maps_settings(); ?>
<div class="grid two" <?= google_maps_attrs() ?>>
<form class="card" method="post" autocomplete="off"><h2>Google Maps <small>Maps JavaScript API</small></h2>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="section" value="maps">
    <div class="form-grid">
        <div class="field"><label for="maps_key">Browser API key</label><input id="maps_key" name="maps_key" type="text" value="<?= e($db['maps.browser_key'] ?? '') ?>" placeholder="<?= $gm['enabled'] ? 'Using key from config file' : 'AIza…' ?>"><small>Public key used in browsers. Restrict it by HTTP referrer to your domain in Google Cloud.</small></div>
        <div class="field"><label for="maps_map_id">Map ID (optional)</label><input id="maps_map_id" name="maps_map_id" type="text" value="<?= e($db['maps.map_id'] ?? '') ?>" placeholder="<?= e($gm['map_id']) ?>"><small>Used by URide's styled map.</small></div>
    </div>
    <p class="section-title">Default map view</p>
    <div class="form-grid">
        <div class="field"><label for="maps_lat">Latitude</label><input id="maps_lat" name="maps_lat" type="text" value="<?= e((string) $gm['lat']) ?>"></div>
        <div class="field"><label for="maps_lng">Longitude</label><input id="maps_lng" name="maps_lng" type="text" value="<?= e((string) $gm['lng']) ?>"></div>
        <div class="field"><label for="maps_zoom">Zoom (3–20)</label><input id="maps_zoom" name="maps_zoom" type="number" min="3" max="20" value="<?= (int) $gm['zoom'] ?>"></div>
    </div>
    <div class="form-actions"><button class="btn primary" type="submit">Save map settings</button></div>
</form>
<div class="stack">
    <section class="card"><h2>Preview <?= $gm['enabled'] ? status_badge('active') : status_badge('pending') ?></h2>
        <div class="map-box" data-map-view data-markers="[]"><?= $gm['enabled'] ? 'Loading map…' : 'Add a browser key to turn on maps.' ?></div>
        <p class="table-meta" data-map-status></p></section>
    <section class="card" data-maps-check><h2>Check place search</h2>
        <p class="muted">Tests this key the same way URide does: map, Google place suggestions and street addresses.</p>
        <button class="btn" type="button">Run check</button>
        <ul class="maps-check" data-maps-check-out></ul></section>
    <section class="card"><h2>Where maps are used</h2>
        <div class="kv"><span>URide pick-up &amp; drop-off pins</span></div>
        <div class="kv"><span>Merchant Portal — business location pin during onboarding</span></div>
        <div class="kv"><span>Admin — merchant location and Merchants map</span></div>
        <div class="kv"><span>App — "Pay with Credits" merchants map and directions</span></div>
        <p class="table-meta">Enable Maps JavaScript API for the key. Without a key, pages fall back to lists and Google Maps links.</p>
    </section>
</div>
</div>
<?php elseif ($tab === 'uride'): $us = uride_settings(); $pesoV = static fn(int $c): string => centavos_to_decimal($c); $num = static fn(float $f): string => rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.'); ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="section" value="uride">
<section class="card"><h2>Fare matrix <small>per vehicle type · PHP</small></h2>
    <p class="muted">Fare = base fare (covers the first km shown) + per-km rate for the rest of the estimated road distance, never below the minimum, rounded up to the peso. Road distance = straight-line distance × road factor below. Passengers see this fare before booking and it does not change during the trip. <b>Boat</b> is for point-to-point rides only (no hourly rental or self-drive); set a higher per-km rate for sea travel.</p>
    <div class="table-wrap"><table class="fare-matrix"><thead><tr><th>Vehicle</th><th>Available</th><th>Base fare</th><th>Km in base</th><th>Per km after</th><th>Minimum fare</th></tr></thead><tbody>
    <?php foreach (uride_vehicles() as $v => $veh): $r = uride_fare_rule($v); ?>
        <tr><td><b><?= e($veh['label']) ?></b><small><?= e($veh['seats']) ?></small></td>
            <td><select name="<?= $v ?>_enabled" aria-label="<?= e($veh['label']) ?> available"><option value="1"<?= $r['enabled'] ? ' selected' : '' ?>>Yes</option><option value="0"<?= !$r['enabled'] ? ' selected' : '' ?>>No</option></select></td>
            <td><input type="text" name="<?= $v ?>_base" inputmode="decimal" value="<?= e($pesoV($r['base'])) ?>" aria-label="<?= e($veh['label']) ?> base fare"></td>
            <td><input type="text" name="<?= $v ?>_included" inputmode="decimal" value="<?= e($num($r['included_km'])) ?>" aria-label="<?= e($veh['label']) ?> km included"></td>
            <td><input type="text" name="<?= $v ?>_per_km" inputmode="decimal" value="<?= e($pesoV($r['per_km'])) ?>" aria-label="<?= e($veh['label']) ?> per km"></td>
            <td><input type="text" name="<?= $v ?>_minimum" inputmode="decimal" value="<?= e($pesoV($r['minimum'])) ?>" aria-label="<?= e($veh['label']) ?> minimum fare"></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <p class="section-title">Example fares with the saved settings (daytime)</p>
    <div class="table-wrap"><table><thead><tr><th>Straight-line distance</th><?php foreach (uride_vehicles() as $veh): ?><th class="num"><?= e($veh['label']) ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ([0.5, 1, 2, 3, 5] as $km): ?><tr><td><?= $km ?> km <small>≈ <?= $num($km * $us['road_factor']) ?> km road</small></td><?php foreach (array_keys(uride_vehicles()) as $v): $qq = uride_quote($v, $km, 12); ?><td class="num">₱<?= peso($qq['fare']) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table></div>
</section>
<?php $rs = uride_rental_settings(); ?>
<section class="card mt"><h2>Rent a Ride <small>vehicle with driver · PHP</small> <?= status_badge($rs['enabled'] ? 'active' : 'disabled') ?></h2>
    <p class="muted">Customers choose <b>Rent a Ride</b> in URide, pick a vehicle, how long (hours or a whole day) and when to start. The price is upfront: hourly rate × hours (at least the minimum hours), never more than the whole-day price. The same driver commission applies. Drivers see scheduled rentals <?= URIDE_RENTAL_LEAD_MIN ?> minutes before the start.</p>
    <div class="form-grid">
        <div class="field"><label for="rental_enabled">Rent a Ride</label><select id="rental_enabled" name="rental_enabled"><option value="1"<?= $rs['enabled'] ? ' selected' : '' ?>>On</option><option value="0"<?= !$rs['enabled'] ? ' selected' : '' ?>>Off</option></select></div>
        <div class="field"><label for="rental_day_hours">Hours in a whole day</label><input id="rental_day_hours" name="rental_day_hours" type="number" min="4" max="24" value="<?= (int) $rs['day_hours'] ?>"></div>
        <div class="field"><label for="rental_max_hours">Longest hourly package</label><input id="rental_max_hours" name="rental_max_hours" type="number" min="1" max="24" value="<?= (int) $rs['max_hours'] ?>"></div>
        <div class="field"><label for="rental_schedule_days">Book up to (days ahead)</label><input id="rental_schedule_days" name="rental_schedule_days" type="number" min="0" max="30" value="<?= (int) $rs['schedule_days'] ?>"><small>0 = only "Now".</small></div>
    </div>
    <div class="table-wrap"><table class="fare-matrix"><thead><tr><th>Vehicle</th><th>Rentable</th><th>Per hour</th><th>Minimum hours</th><th>Whole day</th><th>Example: 3 hours</th></tr></thead><tbody>
    <?php foreach (uride_rentable_vehicles() as $v => $veh): $rr = uride_rental_rule($v); $ex = uride_rental_quote($v, '3'); ?>
        <tr><td><b><?= e($veh['label']) ?></b></td>
            <td><select name="<?= $v ?>_rental_enabled" aria-label="<?= e($veh['label']) ?> rentable"><option value="1"<?= $rr['enabled'] ? ' selected' : '' ?>>Yes</option><option value="0"<?= !$rr['enabled'] ? ' selected' : '' ?>>No</option></select></td>
            <td><input type="text" name="<?= $v ?>_rental_hour" inputmode="decimal" value="<?= e($pesoV($rr['hour'])) ?>" aria-label="<?= e($veh['label']) ?> per hour"></td>
            <td><input type="number" name="<?= $v ?>_rental_min" min="1" max="24" value="<?= (int) $rr['min_hours'] ?>" aria-label="<?= e($veh['label']) ?> minimum hours"></td>
            <td><input type="text" name="<?= $v ?>_rental_day" inputmode="decimal" value="<?= e($pesoV($rr['day'])) ?>" aria-label="<?= e($veh['label']) ?> whole day"></td>
            <td class="num">₱<?= peso($ex['fare']) ?><?= $ex['capped'] ? ' <small>day cap</small>' : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<section class="card mt"><h2>Self-drive <small>customer drives · PHP</small> <?= status_badge(setting('uride.self_drive_enabled', '1') === '1' ? 'active' : 'disabled') ?></h2>
    <p class="muted">Rent a Ride › <b>Self-drive</b>: drivers who turn on "Offer my vehicle for self-drive" bring the vehicle, check the customer's license and ID, and hand it over. Paid with Credits only; the deposit is held in the app. Late returns cost the hourly rate per started hour after the grace minutes, taken from the deposit. Damage claims are decided in <a href="uride-licenses.php?view=claims">Self-drive licenses &amp; claims</a>. E-Trike and Motorcycle need license code A/A1; Car needs code B.</p>
    <div class="form-grid">
        <div class="field"><label for="self_drive_enabled">Self-drive</label><select id="self_drive_enabled" name="self_drive_enabled"><option value="1"<?= setting('uride.self_drive_enabled', '1') === '1' ? ' selected' : '' ?>>On</option><option value="0"<?= setting('uride.self_drive_enabled', '1') !== '1' ? ' selected' : '' ?>>Off</option></select></div>
        <div class="field"><label for="self_drive_kyc">Needs a verified ID (KYC)</label><select id="self_drive_kyc" name="self_drive_kyc"><option value="1"<?= setting('uride.self_drive_kyc', '1') === '1' ? ' selected' : '' ?>>Yes (recommended)</option><option value="0"<?= setting('uride.self_drive_kyc', '1') !== '1' ? ' selected' : '' ?>>No</option></select></div>
        <div class="field"><label for="self_drive_grace_min">Late return grace (minutes)</label><input id="self_drive_grace_min" name="self_drive_grace_min" type="number" min="0" max="120" value="<?= setting_int('uride.self_drive_grace_min', 15) ?>"></div>
    </div>
    <div class="table-wrap"><table class="fare-matrix"><thead><tr><th>Vehicle</th><th>Self-drive</th><th>Per hour</th><th>Minimum hours</th><th>Whole day</th><th>Deposit</th><th>Example: 4 hours</th></tr></thead><tbody>
    <?php foreach (uride_rentable_vehicles() as $v => $veh): $sr = uride_self_rule($v); $ex = uride_rental_quote($v, '4', 'self'); ?>
        <tr><td><b><?= e($veh['label']) ?></b><small>License <?= uride_license_class($v) === 'car' ? 'B' : 'A / A1' ?></small></td>
            <td><select name="<?= $v ?>_self_enabled" aria-label="<?= e($veh['label']) ?> self-drive"><option value="1"<?= setting("uride.$v.self_enabled", '1') === '1' ? ' selected' : '' ?>>Yes</option><option value="0"<?= setting("uride.$v.self_enabled", '1') !== '1' ? ' selected' : '' ?>>No</option></select></td>
            <td><input type="text" name="<?= $v ?>_self_hour" inputmode="decimal" value="<?= e($pesoV($sr['hour'])) ?>" aria-label="<?= e($veh['label']) ?> self-drive per hour"></td>
            <td><input type="number" name="<?= $v ?>_self_min" min="1" max="24" value="<?= (int) $sr['min_hours'] ?>" aria-label="<?= e($veh['label']) ?> self-drive minimum hours"></td>
            <td><input type="text" name="<?= $v ?>_self_day" inputmode="decimal" value="<?= e($pesoV($sr['day'])) ?>" aria-label="<?= e($veh['label']) ?> self-drive whole day"></td>
            <td><input type="text" name="<?= $v ?>_self_deposit" inputmode="decimal" value="<?= e($pesoV($sr['deposit'])) ?>" aria-label="<?= e($veh['label']) ?> deposit"></td>
            <td class="num">₱<?= peso($ex['fare']) ?> <small>+ ₱<?= peso($ex['deposit']) ?> deposit</small></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<div class="grid half mt">
    <section class="card"><h2>Pricing rules</h2>
        <div class="form-grid">
            <div class="field"><label for="road_factor">Road distance factor</label><input type="text" id="road_factor" name="road_factor" inputmode="decimal" value="<?= e($num($us['road_factor'])) ?>"><small>1.3 means roads are ~30% longer than a straight line.</small></div>
            <div class="field"><label for="night_pct">Night surcharge (%)</label><input type="text" id="night_pct" name="night_pct" inputmode="decimal" value="<?= e($num($us['night_bp'] / 100)) ?>"><small>0 to turn off.</small></div>
            <div class="field"><label for="night_start">Night starts (hour, 0–23)</label><input id="night_start" name="night_start" type="number" min="0" max="23" value="<?= (int) $us['night_start'] ?>"></div>
            <div class="field"><label for="night_end">Night ends (hour, 0–23)</label><input id="night_end" name="night_end" type="number" min="0" max="23" value="<?= (int) $us['night_end'] ?>"></div>
        </div>
    </section>
    <section class="card"><h2>Driver commission <small>deducted from the driver wallet per completed trip</small></h2>
        <div class="form-grid">
            <div class="field"><label for="commission_type">Commission type</label><select id="commission_type" name="commission_type"><option value="percent"<?= $us['commission_type'] === 'percent' ? ' selected' : '' ?>>Percentage of fare</option><option value="fixed"<?= $us['commission_type'] === 'fixed' ? ' selected' : '' ?>>Fixed amount per trip</option></select></div>
            <div class="field"><label for="commission_pct">Percentage (%)</label><input type="text" id="commission_pct" name="commission_pct" inputmode="decimal" value="<?= e($num($us['commission_bp'] / 100)) ?>"></div>
            <div class="field"><label for="commission_fixed">Fixed amount (PHP)</label><input type="text" id="commission_fixed" name="commission_fixed" inputmode="decimal" value="<?= e($pesoV($us['commission_fixed'])) ?>"><small>Never more than the fare.</small></div>
            <div class="field"><label for="min_wallet">Minimum wallet to go online (PHP)</label><input type="text" id="min_wallet" name="min_wallet" inputmode="decimal" value="<?= e($pesoV($us['min_wallet'])) ?>"></div>
        </div>
        <h3 class="mt">Trip rewards</h3>
        <div class="form-grid">
            <div class="field"><label for="rewards_enabled">Driver trip rewards</label><select id="rewards_enabled" name="rewards_enabled"><option value="1"<?= setting('uride.rewards_enabled', '1') === '1' ? ' selected' : '' ?>>On</option><option value="0"<?= setting('uride.rewards_enabled', '1') !== '1' ? ' selected' : '' ?>>Off</option></select></div>
            <div class="field"><label for="reward_milestones">Milestones (trips:PHP)</label><input id="reward_milestones" name="reward_milestones" maxlength="200" value="<?= e((string) setting('uride.reward_milestones', URIDE_REWARD_DEFAULT)) ?>"><small>e.g. 50:100 = PHP 100 bonus at the 50th completed trip. Added to the driver wallet, paid by Ultimate App.</small></div>
        </div>
        <p class="alert info mt">Current: <?= e(uride_commission_label()) ?>. Credits rides: the fare goes to the driver wallet less commission. Cash rides: the driver keeps the cash and the commission is deducted from the wallet.</p>
    </section>
</div>
<div class="grid half mt">
    <section class="card"><h2>Dispatch &amp; bookings</h2>
        <div class="form-grid">
            <div class="field"><label for="enabled">URide bookings</label><select id="enabled" name="enabled"><option value="1"<?= $us['enabled'] ? ' selected' : '' ?>>Open</option><option value="0"<?= !$us['enabled'] ? ' selected' : '' ?>>Paused</option></select></div>
            <div class="field"><label for="radius">Broadcast radius (km)</label><input type="text" id="radius" name="radius" inputmode="decimal" value="<?= e($num($us['radius_km'])) ?>"><small>Online drivers within this distance of the pick-up see the request.</small></div>
            <div class="field"><label for="timeout">Request expires after (minutes)</label><input id="timeout" name="timeout" type="number" min="1" max="60" value="<?= (int) $us['timeout_min'] ?>"><small>Unaccepted requests close and held Credits are returned.</small></div>
            <div class="field"><label for="credits_enabled">Pay with Credits</label><select id="credits_enabled" name="credits_enabled"><option value="1"<?= $us['credits_enabled'] ? ' selected' : '' ?>>Allowed</option><option value="0"<?= !$us['credits_enabled'] ? ' selected' : '' ?>>Off</option></select></div>
            <div class="field"><label for="cash_enabled">Pay with cash</label><select id="cash_enabled" name="cash_enabled"><option value="1"<?= setting('uride.cash_enabled', '1') === '1' ? ' selected' : '' ?>>Allowed</option><option value="0"<?= setting('uride.cash_enabled', '1') !== '1' ? ' selected' : '' ?>>Off</option></select><?php if (!pay_cash_enabled()): ?><small>Pay Cash is off app-wide (Charges › Payment methods), so cash is hidden.</small><?php endif; ?></div>
            <div class="field"><label for="bcash_enabled">Pay with BCash</label><select id="bcash_enabled" name="bcash_enabled"><option value="1"<?= setting('uride.bcash_enabled', '1') === '1' ? ' selected' : '' ?>>Allowed</option><option value="0"<?= setting('uride.bcash_enabled', '1') !== '1' ? ' selected' : '' ?>>Off</option></select><?php if (!pay_bcash_enabled()): ?><small>BCash payments are off app-wide (Charges › Payment methods).</small><?php endif; ?></div>
        </div>
    </section>
    <section class="card"><h2>Driver wallet</h2>
        <div class="form-grid">
            <div class="field"><label for="topup_min">Minimum top-up (PHP)</label><input type="text" id="topup_min" name="topup_min" inputmode="decimal" value="<?= e($pesoV($us['topup_min'])) ?>"></div>
            <div class="field"><label for="topup_max">Maximum top-up (PHP)</label><input type="text" id="topup_max" name="topup_max" inputmode="decimal" value="<?= e($pesoV($us['topup_max'])) ?>"></div>
            <?php foreach (['qrph' => ['qrph', 'QR Ph (PayMongo)'], 'mctc' => ['mctc', 'MCTC top-up center (cash)'], 'bcash' => ['boracay_cash', 'BCash (from an app account)']] as $k => [$mk, $ml]): ?>
            <div class="field"><label for="topup_<?= $k ?>"><?= e($ml) ?></label><select id="topup_<?= $k ?>" name="topup_<?= $k ?>"><option value="1"<?= uride_topup_method_enabled($mk) ? ' selected' : '' ?>>Allowed</option><option value="0"<?= !uride_topup_method_enabled($mk) ? ' selected' : '' ?>>Off</option></select></div>
            <?php endforeach; ?>
            <div class="field"><label for="topup_fee">Top-up service fee (PHP)</label><input type="text" id="topup_fee" name="topup_fee" inputmode="decimal" value="<?= e($pesoV($us['topup_fee'])) ?>"><small>Added to QR Ph and MCTC top-ups. BCash top-ups have no fee.</small></div>
            <div class="field"><label for="payout_min">Minimum payout (PHP)</label><input type="text" id="payout_min" name="payout_min" inputmode="decimal" value="<?= e($pesoV($us['payout_min'])) ?>"></div>
        </div>
    </section>
</div>
<div class="form-actions"><button class="btn primary" type="submit">Save URide settings</button><span class="muted">New fares apply to bookings made after saving. Booked rides keep their upfront fare.</span></div>
</form>
<?php else: $rules = ['credits' => p2m_fee_rule('credits'), 'boracay_cash' => p2m_fee_rule('boracay_cash')]; ?>
<form method="post" class="card mb pay-methods-card">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="section" value="cancelfees">
    <h2>Cancellation fees <small>% of what the customer paid in the app (Credits or BCash)</small></h2>
    <div class="form-grid">
        <div class="field"><label for="uride_pct">URide (% of fare)</label><input id="uride_pct" name="uride_pct" inputmode="decimal" value="<?= e((string) cancel_fee_pct('uride')) ?>"><small>Charged when the passenger cancels after the free minutes, or after the driver arrived. Goes to the driver.</small></div>
        <div class="field"><label for="uride_free_min">URide free minutes after the driver accepts</label><input id="uride_free_min" name="uride_free_min" type="number" min="0" max="60" value="<?= cancel_uride_free_minutes() ?>"><small>Always free before a driver accepts.</small></div>
        <div class="field"><label for="booking_pct">UPass / UGo / ULocal late cancellation (%)</label><input id="booking_pct" name="booking_pct" inputmode="decimal" value="<?= e((string) cancel_fee_pct('booking')) ?>"><small>After the listing's free-cancellation cut-off, before the start. Goes to the provider (or Ultimate App for its own listings).</small></div>
        <div class="field"><label for="ueat_pct">UEat after the restaurant accepts (%)</label><input id="ueat_pct" name="ueat_pct" inputmode="decimal" value="<?= e((string) cancel_fee_pct('ueat')) ?>"><small>Free before the restaurant accepts. Cannot cancel once the food is ready or on the way. Goes to the restaurant.</small></div>
    </div>
    <p class="table-meta">0% = free. Customers must give a reason every time they cancel. Declines by the driver, merchant or restaurant, and cancellations by Ultimate App, are always refunded in full. Cash and pay-on-the-day bookings have no fee.</p>
    <div class="form-actions"><button class="btn primary" type="submit">Save cancellation fees</button></div>
</form>
<form method="post" class="card mb pay-methods-card">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="section" value="paymethods">
    <h2>Payment methods <small>for URide, UPass, UGo, ULocal and UEat</small></h2>
    <div class="pm-rows">
        <label class="pm-row"><span><b>Credits</b><small>Always on. Customers top up with QR Ph, MCTC or BCash.</small></span><span class="badge good">On</span></label>
        <label class="pm-row"><span><b>BCash</b><small>Pay rides, rentals, passes, bookings and food with BCash. Merchant charges follow "Paying merchants with BCash" below.</small></span><span class="pm-switch"><input type="checkbox" name="pay_bcash" value="1"<?= pay_bcash_enabled() ? ' checked' : '' ?>> Enabled</span></label>
        <label class="pm-row"><span><b>Pay Cash</b><small>Cash to the URide driver and "pay cash on the day" for UPass, UGo and ULocal. Turn off to make every payment go through Credits or BCash (held in the app and refunded automatically).</small></span><span class="pm-switch"><input type="checkbox" name="pay_cash" value="1"<?= pay_cash_enabled() ? ' checked' : '' ?>> Enabled</span></label>
    </div>
    <p class="table-meta">Bookings already made keep the payment method they were made with.</p>
    <div class="form-actions"><button class="btn primary" type="submit">Save payment methods</button></div>
</form>
<form method="post">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="section" value="charges">
<div class="grid half">
<?php foreach (['credits' => 'Paying merchants with Credits', 'boracay_cash' => 'Paying merchants with BCash'] as $s => $title): $r = $rules[$s]; ?>
    <fieldset class="card"><h2><?= e($title) ?> <small>P2M</small></h2>
        <div class="form-grid">
            <div class="field"><label for="<?= $s ?>_enabled">Accept this source</label><select id="<?= $s ?>_enabled" name="<?= $s ?>_enabled"><option value="1"<?= $r['enabled'] ? ' selected' : '' ?>>Enabled</option><option value="0"<?= !$r['enabled'] ? ' selected' : '' ?>>Disabled</option></select></div>
            <div class="field"><label for="<?= $s ?>_bearer">Charge paid by</label><select id="<?= $s ?>_bearer" name="<?= $s ?>_bearer" data-fee-bearer><option value="customer"<?= $r['bearer'] === 'customer' ? ' selected' : '' ?>>Customer (added on top)</option><option value="merchant"<?= $r['bearer'] === 'merchant' ? ' selected' : '' ?>>Merchant (deducted, like MDR)</option></select></div>
            <div class="field"><label for="<?= $s ?>_percent">Percentage charge (%)</label><input id="<?= $s ?>_percent" name="<?= $s ?>_percent" type="text" inputmode="decimal" value="<?= e(rtrim(rtrim(number_format($r['percent_bp'] / 100, 2, '.', ''), '0'), '.')) ?>" data-fee-pct></div>
            <div class="field"><label for="<?= $s ?>_fixed">Fixed charge (PHP)</label><input id="<?= $s ?>_fixed" name="<?= $s ?>_fixed" type="text" inputmode="decimal" value="<?= e(centavos_to_decimal($r['fixed_centavos'])) ?>" data-fee-fixed></div>
        </div>
        <p class="alert info mt" data-fee-preview></p>
    </fieldset>
<?php endforeach; ?>
</div>
<div class="grid half mt">
    <section class="card"><h2>Merchant payment limits</h2>
        <div class="form-grid">
            <div class="field"><label for="min">Minimum per payment (PHP)</label><input id="min" name="min" type="text" inputmode="decimal" value="<?= e(centavos_to_decimal(setting_int('p2m.min_centavos', 100))) ?>"></div>
            <div class="field"><label for="max">Maximum per payment (PHP)</label><input id="max" name="max" type="text" inputmode="decimal" value="<?= e(centavos_to_decimal(setting_int('p2m.max_centavos', 5000000))) ?>"></div>
        </div>
    </section>
    <section class="card"><h2>Other app fees</h2>
        <?php $fees = app_fees(); ?>
        <div class="form-grid">
            <div class="field"><label for="buy_fee">Buy Credits service fee (PHP)</label><input id="buy_fee" name="buy_fee" type="text" inputmode="decimal" value="<?= e(centavos_to_decimal((int) $fees['buy_credits_centavos'])) ?>"><small>Added to each QR Ph / MCTC top-up.</small></div>
            <div class="field"><label for="mctc_all">MCTC top-up centers</label><select id="mctc_all" name="mctc_all"><option value="1"<?= setting('mctc.all_approved', '1') === '1' ? ' selected' : '' ?>>Every verified (approved) merchant</option><option value="0"<?= setting('mctc.all_approved', '1') !== '1' ? ' selected' : '' ?>>Only merchants turned on one by one</option></select><small>Can still be turned off or on per merchant in CRM › Merchants.</small></div>
            <div class="field"><label for="mctc_fee">MCTC cash-out fee (Credits)</label><input id="mctc_fee" name="mctc_fee" type="number" min="0" max="1000" value="<?= (int) $fees['mctc_conversion_credits'] ?>"></div>
        </div>
    </section>
</div>
<div class="form-actions"><button class="btn primary" type="submit">Save charges</button><span class="muted">New charges apply to payments made after saving. Past payments keep the charge they were made with.</span></div>
</form>
<?php endif; ?>
<?php admin_footer($tab === 'maps' ? ['../assets/js/gmaps.js?v=2', 'assets/maps-check.js?v=4'] : []);
