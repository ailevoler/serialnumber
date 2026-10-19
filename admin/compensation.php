<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

const MAX_LEVELS = 30;

/** Clean a submitted level list (array of numbers) into "a,b,c". */
function levels_from_post(string $key): string
{
    $vals = [];
    foreach ((array)($_POST[$key] ?? []) as $v) {
        if (is_string($v) && trim($v) !== '' && is_numeric($v)) {
            $vals[] = (string)round(max(0, (float)$v), 2);
        }
    }
    return implode(',', array_slice($vals, 0, MAX_LEVELS));
}

if (is_post()) {
    csrf_check();
    foreach (['discount', 'direct', 'indirect', 'passup', 'pairing', 'unilevel'] as $t) {
        save_setting($t . '_enabled', isset($_POST[$t . '_enabled']) ? '1' : '0');
    }
    $mode = fn($k) => input($k) === 'percent' ? 'percent' : 'fixed';
    $numv = fn($k) => (string)max(0, round(input_float($k), 2));

    save_setting('direct_mode', $mode('direct_mode'));
    save_setting('direct_percent', $numv('direct_percent'));
    save_setting('direct_outright', isset($_POST['direct_outright']) ? '1' : '0');

    save_setting('indirect_mode', $mode('indirect_mode'));
    save_setting('indirect_levels', levels_from_post('indirect_levels'));

    $positions = array_unique(array_filter(array_map('intval', explode(',', input('passup_positions'))), fn($n) => $n > 0));
    sort($positions);
    save_setting('passup_positions', implode(',', $positions) ?: '1');
    save_setting('passup_mode', in_array(input('passup_mode'), ['fixed', 'percent', 'package'], true) ? input('passup_mode') : 'fixed');
    save_setting('passup_kept_bonus', isset($_POST['passup_kept_bonus']) ? '1' : '0');
    save_setting('passup_top_keeps', isset($_POST['passup_top_keeps']) ? '1' : '0');
    save_setting('passup_bonus', $numv('passup_bonus'));
    save_setting('passup_sponsor_keeps_direct', isset($_POST['passup_sponsor_keeps_direct']) ? '1' : '0');

    save_setting('pair_pv', (string)max(1, round(input_float('pair_pv'), 2)));
    save_setting('pair_bonus', $numv('pair_bonus'));
    save_setting('pair_daily_cap', (string)max(0, input_int('pair_daily_cap')));
    save_setting('pairing_mode', input('pairing_mode') === 'matching' ? 'matching' : 'unit');
    save_setting('pair_rate', $numv('pair_rate'));
    save_setting('pairing_to_product_wallet', isset($_POST['pairing_to_product_wallet']) ? '1' : '0');

    save_setting('unilevel_base', input('unilevel_base') === 'amount' ? 'amount' : 'pv');
    save_setting('unilevel_percent', levels_from_post('unilevel_percent'));
    save_setting('unilevel_mode', input('unilevel_mode') === 'per_unit' ? 'per_unit' : 'percent');
    save_setting('unilevel_per_unit', levels_from_post('unilevel_per_unit'));
    save_setting('discount_mode', input('discount_mode') === 'tier' ? 'tier' : 'fixed');
    $tiers = [];
    foreach ((array)($_POST['tier_name'] ?? []) as $i => $tn) {
        $tn = trim(str_replace([':', ','], ' ', (string)$tn));
        $tp = (float)($_POST['tier_pct'][$i] ?? 0);
        if ($tn !== '' && $tp > 0 && $tp < 100) {
            $tiers[] = $tn . ':' . round($tp, 2);
        }
    }
    save_setting('stockist_tiers', implode(',', $tiers));
    save_setting('unilevel_require_active', isset($_POST['unilevel_require_active']) ? '1' : '0');
    foreach (['upgrade_enabled', 'upgrade_allow_skip', 'upgrade_add_pv', 'upgrade_wallet'] as $k) {
        save_setting($k, isset($_POST[$k]) ? '1' : '0');
    }
    save_setting('exgen_min_directs', (string)max(1, input_int('exgen_min_directs', 8)));
    save_setting('exgen_window_months', (string)max(0, input_int('exgen_window_months', 2)));
    save_setting('exgen_mode', input('exgen_mode') === 'per_direct' ? 'per_direct' : 'complete');
    save_setting('upgrade_bonus', input('upgrade_bonus') === 'none' ? 'none' : 'difference');

    // Per-package direct bonus and binary PV
    foreach ((array)($_POST['pkg'] ?? []) as $pid => $row) {
        if (!is_array($row)) {
            continue;
        }
        q('UPDATE packages SET price = ?, pv = ?, direct_bonus = ?, coded_bonus = ?, discount_percent = ?, exgen_bonus = ? WHERE id = ?', [
            max(0, round((float)($row['price'] ?? 0), 2)),
            max(0, round((float)($row['pv'] ?? 0), 2)),
            max(0, round((float)($row['direct_bonus'] ?? 0), 2)),
            max(0, round((float)($row['coded_bonus'] ?? 0), 2)),
            min(99, max(0, round((float)($row['discount_percent'] ?? 0), 2))),
            max(0, round((float)($row['exgen_bonus'] ?? 0), 2)),
            (int)$pid,
        ]);
    }

    log_activity((int)$me['id'], 'Updated compensation plan');
    flash('success', 'Compensation plan saved. New values apply to new registrations and orders.');
    redirect('admin/compensation.php');
}

settings_all(true);
$packages = q_all('SELECT * FROM packages ORDER BY id');
$on = fn($t) => setting($t . '_enabled', '1') === '1' ? 'checked' : '';
$sel = fn($k, $v) => setting($k) === $v ? 'selected' : '';
$cur = setting('currency', '₱');

/** Render an editable list of levels with add/remove buttons. */
function level_editor(string $name, array $values, string $suffix): void
{
    ?>
    <div class="level-editor" data-levels data-name="<?= e($name) ?>" data-max="<?= MAX_LEVELS ?>" data-suffix="<?= e($suffix) ?>">
      <div class="level-rows">
        <?php foreach ($values as $i => $v): ?>
          <div class="level-row">
            <span class="level-label">Level <?= $i + 1 ?></span>
            <input class="input" type="number" step="0.01" min="0" name="<?= e($name) ?>[]" value="<?= e($v) ?>" required>
            <span class="level-suffix"><?= e($suffix) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="filters mt-1">
        <button type="button" class="btn btn-outline btn-sm" data-level-add><?= icon('plus', 14) ?> Add Level</button>
        <button type="button" class="btn btn-light btn-sm" data-level-remove>Remove Last Level</button>
        <span class="small text-muted" data-level-count><?= count($values) ?> level(s)</span>
      </div>
    </div>
    <?php
}

page_header('Compensation Plan', $me, 'Set every bonus, level and commission of the 6 ways to earn');
?>
<form method="post">
<?= csrf_field() ?>

<div class="alert alert-info">Changes apply to <strong>new</strong> registrations and orders only. Bonuses already credited are not recalculated.</div>

<div class="card">
  <div class="card-header"><h3><?= icon('settings') ?> Ways to Earn — On / Off</h3></div>
  <div class="card-body">
    <div class="toggle-grid">
      <?php foreach (earning_types() as $k => $t): ?>
        <label class="toggle-card">
          <input type="checkbox" name="<?= $k ?>_enabled" value="1" <?= $on($k) ?>>
          <span class="toggle-ui"></span>
          <span><?= icon($t['icon'], 16) ?> <strong><?= e($t['label']) ?></strong><small><?= e($t['hint']) ?></small></span>
        </label>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="grid grid-2">
  <!-- Direct Referral -->
  <div class="card">
    <div class="card-header"><h3><?= icon('user-plus') ?> Direct Referral Bonus</h3></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group"><label>Computation</label>
          <select class="input" name="direct_mode">
            <option value="fixed" <?= $sel('direct_mode', 'fixed') ?>>Fixed amount per package (table below)</option>
            <option value="percent" <?= $sel('direct_mode', 'percent') ?>>Percent of package price</option>
          </select></div>
        <div class="form-group"><label>Percent (if percent mode)</label><input class="input" type="number" step="0.01" min="0" name="direct_percent" value="<?= e(setting('direct_percent', 10)) ?>"></div>
      </div>
      <label class="toggle-card mt-1">
        <input type="checkbox" name="direct_outright" value="1" <?= setting('direct_outright', '1') === '1' ? 'checked' : '' ?>>
        <span class="toggle-ui"></span>
        <span><strong>Outright Direct Referral Bonus</strong><small>Paid directly to the sponsor at the time of sale. Shown only on the Referral Bonus card — not credited to the wallet, not withdrawable and not included in Total Earnings.</small></span>
      </label>
      <p class="help mb-0 mt-1">Paid to the sponsor once a new member activates with a paid code.</p>
    </div>
  </div>

  <!-- Pass Up -->
  <div class="card">
    <div class="card-header"><h3><?= icon('arrow-up') ?> Coded Bonus (Pass-Up · Australian)</h3></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group"><label>Recruit number(s) passed up</label><input class="input" name="passup_positions" value="<?= e(setting('passup_positions', '1')) ?>" placeholder="1 or 1,2">
          <div class="help">Which invites go up, separated by commas. <strong>1,2</strong> = each member's 1st and 2nd invite pass up to the upline; from the 3rd invite the sponsor earns the Coded Bonus (2-Up). <strong>1</strong> = only the 1st invite passes up (1-Up).</div></div>
        <div class="form-group"><label>Computation</label>
          <select class="input" name="passup_mode">
            <option value="fixed" <?= $sel('passup_mode', 'fixed') ?>>Fixed amount</option>
            <option value="percent" <?= $sel('passup_mode', 'percent') ?>>Percent of package price</option>
            <option value="package" <?= $sel('passup_mode', 'package') ?>>Per package (Coded Bonus column below)</option>
          </select></div>
        <div class="form-group"><label>Coded bonus (<?= e($cur) ?> or %)</label><input class="input" type="number" step="0.01" min="0" name="passup_bonus" value="<?= e(setting('passup_bonus', 0)) ?>"></div>
        <div class="form-group"><label>&nbsp;</label>
          <label style="display:flex;gap:8px;align-items:center;font-weight:400"><input type="checkbox" name="passup_sponsor_keeps_direct" value="1" <?= setting('passup_sponsor_keeps_direct', '1') === '1' ? 'checked' : '' ?>> Sponsor still earns Direct Referral on a passed-up recruit</label></div>
      </div>
      <label class="toggle-card mt-1">
        <input type="checkbox" name="passup_kept_bonus" value="1" <?= setting('passup_kept_bonus', '0') === '1' ? 'checked' : '' ?>>
        <span class="toggle-ui"></span>
        <span><strong>Sponsor earns the coded bonus on recruits that are not passed up</strong><small>e.g. 2-Up: invites #1 and #2 pass up to your upline; from invite #3 onward the coded bonus is yours.</small></span>
      </label>
      <label class="toggle-card mt-1">
        <input type="checkbox" name="passup_top_keeps" value="1" <?= setting('passup_top_keeps', '0') === '1' ? 'checked' : '' ?>>
        <span class="toggle-ui"></span>
        <span><strong>Top of line earns its own pass-ups</strong><small>For a sponsor with no upline (e.g. the root account): on = it earns the Coded Bonus of its own invites #1 and #2; off = nobody earns it (the company keeps it).</small></span>
      </label>
      <p class="help mb-0 mt-1">A passed-up recruit is coded to the upline who received it, so that upline also receives the recruit's own pass-ups.</p>
    </div>
  </div>
</div>

<!-- Packages -->
<div class="card">
  <div class="card-header"><h3><?= icon('package') ?> Packages — Bonuses per Package</h3><div class="actions"><a class="btn btn-light btn-sm" href="<?= e(url('admin/packages.php')) ?>"><?= icon('plus', 14) ?> Add / manage packages</a></div></div>
  <?php
  // Catch packages that would silently pay nothing (e.g. values shifted when a package was added).
  $zeroWarn = [];
  foreach ($packages as $pk) {
      if ($pk['status'] !== 'active') {
          continue;
      }
      $miss = [];
      if (plan_on('direct') && setting('direct_mode', 'fixed') === 'fixed' && (float)$pk['direct_bonus'] <= 0) $miss[] = 'Direct Referral';
      if (plan_on('passup') && setting('passup_mode', 'fixed') === 'package' && (float)$pk['coded_bonus'] <= 0) $miss[] = 'Coded Bonus';
      if ((float)$pk['pv'] <= 0) $miss[] = 'PV (no pairing)';
      if (plan_on('discount') && setting('discount_mode', 'fixed') === 'tier' && (float)$pk['discount_percent'] <= 0) $miss[] = 'Product Discount';
      if ($miss) $zeroWarn[] = '<strong>' . e($pk['name']) . '</strong>: ' . e(implode(', ', $miss));
  }
  ?>
  <?php if ($zeroWarn): ?><div class="card-body" style="padding-bottom:0"><div class="alert alert-warning mb-0">These are set to 0, so nothing will be paid for them: <?= implode(' · ', $zeroWarn) ?>. Check the values below.</div></div><?php endif; ?>
  <div class="card-body p-0 table-wrap">
    <table class="table stack">
      <thead><tr><th>Package</th><th>Price (<?= e($cur) ?>)</th><th>Binary PV / Pair value</th><th>Direct Referral (<?= e($cur) ?>)</th><th>Coded Bonus (<?= e($cur) ?>)</th><th>Product Discount (%)</th><th>EXGEN per Direct (<?= e($cur) ?>)</th></tr></thead>
      <tbody>
      <?php foreach ($packages as $pk): ?>
        <tr>
          <td><strong><?= e($pk['name']) ?></strong> <?= $pk['status'] !== 'active' ? badge($pk['status']) : '' ?></td>
          <td><input class="input" type="number" step="0.01" min="0" name="pkg[<?= (int)$pk['id'] ?>][price]" value="<?= e($pk['price']) ?>"></td>
          <td><input class="input" type="number" step="0.01" min="0" name="pkg[<?= (int)$pk['id'] ?>][pv]" value="<?= e($pk['pv']) ?>"></td>
          <td><input class="input" type="number" step="0.01" min="0" name="pkg[<?= (int)$pk['id'] ?>][direct_bonus]" value="<?= e($pk['direct_bonus']) ?>"></td>
          <td><input class="input" type="number" step="0.01" min="0" name="pkg[<?= (int)$pk['id'] ?>][coded_bonus]" value="<?= e($pk['coded_bonus']) ?>"></td>
          <td><input class="input" type="number" step="0.01" min="0" max="99" name="pkg[<?= (int)$pk['id'] ?>][discount_percent]" value="<?= e($pk['discount_percent']) ?>"></td>
          <td><input class="input" type="number" step="0.01" min="0" name="pkg[<?= (int)$pk['id'] ?>][exgen_bonus]" value="<?= e($pk['exgen_bonus'] ?? 0) ?>"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="grid grid-2">
  <!-- Indirect -->
  <div class="card">
    <div class="card-header"><h3><?= icon('users') ?> Indirect Bonus — Levels</h3></div>
    <div class="card-body">
      <div class="form-group"><label>Computation</label>
        <select class="input" name="indirect_mode" data-suffix-target="indirect_levels" data-currency="<?= e($cur) ?>">
          <option value="fixed" <?= $sel('indirect_mode', 'fixed') ?>>Fixed amount per level (<?= e($cur) ?>)</option>
          <option value="percent" <?= $sel('indirect_mode', 'percent') ?>>Percent of package price per level (%)</option>
        </select></div>
      <p class="help">Level 1 = the sponsor's sponsor, Level 2 = the next upline, and so on.</p>
      <?php level_editor('indirect_levels', setting_list('indirect_levels'), setting('indirect_mode') === 'percent' ? '%' : $cur); ?>
    </div>
  </div>

  <!-- Unilevel -->
  <div class="card">
    <div class="card-header"><h3><?= icon('repeat') ?> Unilevel Bonus (Repeat Purchase) — Levels</h3></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group"><label>Commission base</label>
          <select class="input" name="unilevel_base">
            <option value="pv" <?= $sel('unilevel_base', 'pv') ?>>Product PV of the order</option>
            <option value="amount" <?= $sel('unilevel_base', 'amount') ?>>Order amount (member price)</option>
          </select></div>
        <div class="form-group"><label>&nbsp;</label>
          <label style="display:flex;gap:8px;align-items:center;font-weight:400"><input type="checkbox" name="unilevel_require_active" value="1" <?= setting('unilevel_require_active', '1') === '1' ? 'checked' : '' ?>> Only active members earn</label></div>
      </div>
      <div class="form-group"><label>Computation</label>
        <select class="input" name="unilevel_mode">
          <option value="per_unit" <?= $sel('unilevel_mode', 'per_unit') ?>>Fixed amount per bottle / pouch bought, per level</option>
          <option value="percent" <?= $sel('unilevel_mode', 'percent') ?>>Percent of the commission base, per level</option>
        </select></div>
      <p class="help">Level 1 = the buyer's sponsor.</p>
      <div class="section-title">Per bottle / pouch (<?= e($cur) ?>)</div>
      <?php level_editor('unilevel_per_unit', setting_list('unilevel_per_unit') ?: ['5'], $cur); ?>
      <div class="section-title">Percent mode (%)</div>
      <?php level_editor('unilevel_percent', setting_list('unilevel_percent'), '%'); ?>
    </div>
  </div>
</div>

<div class="grid grid-2">
  <!-- Pairing -->
  <div class="card">
    <div class="card-header"><h3><?= icon('git-merge') ?> Pairing / Matching Bonus (Binary)</h3></div>
    <div class="card-body">
      <div class="form-group"><label>Pairing computation</label>
        <select class="input" name="pairing_mode">
          <option value="matching" <?= $sel('pairing_mode', 'matching') ?>>Package matching — Silver↔Silver, Gold↔Gold… (pays the matched pair value)</option>
          <option value="unit" <?= $sel('pairing_mode', 'unit') ?>>Fixed PV per pair × fixed bonus per pair</option>
        </select>
        <div class="help">Package matching uses each package's Pair value (table above). The smaller side is matched; the rest carries forward. Each match counts as 1 pair.</div></div>
      <div class="form-grid">
        <div class="form-group"><label>Matching payout (% of pair value)</label><input class="input" type="number" step="0.01" min="0" name="pair_rate" value="<?= e(setting('pair_rate', 100)) ?>"></div>
        <div class="form-group"><label>PV per side for 1 pair (fixed mode)</label><input class="input" type="number" step="0.01" min="1" name="pair_pv" value="<?= e(setting('pair_pv', 100)) ?>"></div>
        <div class="form-group"><label>Bonus per pair (<?= e($cur) ?>, fixed mode)</label><input class="input" type="number" step="0.01" min="0" name="pair_bonus" value="<?= e(setting('pair_bonus', 0)) ?>"></div>
        <div class="form-group"><label>Max pairs per day</label><input class="input" type="number" min="0" name="pair_daily_cap" value="<?= e(setting('pair_daily_cap', 0)) ?>"><div class="help">0 = unlimited. Excess pairs are flushed.</div></div>
      </div>
      <label class="toggle-card mt-1">
        <input type="checkbox" name="pairing_to_product_wallet" value="1" <?= setting('pairing_to_product_wallet', '0') === '1' ? 'checked' : '' ?>>
        <span class="toggle-ui"></span>
        <span><strong>Credit Pairing / Matching Bonus to Product Wallet</strong><small>All binary pairing bonuses go to the member's Product Wallet (for buying products only, not withdrawable). Still shown as Pairing / Matching Bonus.</small></span>
      </label>
      <p class="help mb-0 mt-1">Each package's PV goes to the left or right leg of every upline in the binary tree. Unpaired PV is carried forward.</p>
    </div>
  </div>

  <!-- Discount -->
  <div class="card">
    <div class="card-header"><h3><?= icon('tag') ?> Product Discount</h3></div>
    <div class="card-body">
      <div class="form-group"><label>Member price</label>
        <select class="input" name="discount_mode">
          <option value="tier" <?= $sel('discount_mode', 'tier') ?>>Discount tier — SRP less the member's package / stockist discount %</option>
          <option value="fixed" <?= $sel('discount_mode', 'fixed') ?>>Fixed Member Price set per product</option>
        </select></div>
      <div class="section-title">Stockist tiers (assigned per member, override the package discount)</div>
      <div class="tier-rows">
        <?php $tiers = stockist_tiers(); $tiers[''] = 0; foreach ($tiers as $tn => $tp): ?>
          <div class="filters mb-1"><input class="input" name="tier_name[]" value="<?= e($tn) ?>" placeholder="Tier name, e.g. City Hub" style="flex:1"><input class="input" type="number" step="0.01" min="0" max="99" name="tier_pct[]" value="<?= $tp ?: '' ?>" placeholder="%" style="width:90px"></div>
        <?php endforeach; ?>
      </div>
      <p class="help">Leave a row empty to remove it. Assign a member's tier in Members → Manage.</p>
      <p class="mb-1 small">The difference from SRP is recorded as the member's discount / retail profit (not credited to the wallet).</p>
      <a class="btn btn-light btn-sm" href="<?= e(url('admin/products.php')) ?>"><?= icon('box', 14) ?> Set SRP per product</a>
    </div>
  </div>
</div>

<div class="card" id="exgen">
  <div class="card-header"><h3><?= icon('award') ?> EXGEN Bonus (Balik Puhunan)</h3></div>
  <div class="card-body">
    <div class="form-grid">
      <div class="form-group"><label>Minimum directs</label><input class="input" type="number" min="1" name="exgen_min_directs" value="<?= e(setting('exgen_min_directs', 8)) ?>">
        <div class="help">Target = EXGEN per direct × this number (Silver 250 × 8 = 2,000).</div></div>
      <div class="form-group"><label>Time limit (months)</label><input class="input" type="number" min="0" name="exgen_window_months" value="<?= e(setting('exgen_window_months', 2)) ?>">
        <div class="help">Not reached in time → progress resets and a new period starts. 0 = no limit.</div></div>
      <div class="form-group full"><label>Payout</label>
        <select class="input" name="exgen_mode">
          <option value="complete" <?= $sel('exgen_mode', 'complete') ?>>When completed — pay the full target once the minimum directs and the target are reached</option>
          <option value="per_direct" <?= $sel('exgen_mode', 'per_direct') ?>>Per direct — credit each direct's value as it comes in, up to the target</option>
        </select></div>
    </div>
    <p class="help mb-0">Each paid direct counts the member's own package value, but never more than the direct's package value (a Diamond with a Silver direct counts 250). Set the value per package in the Packages table above. Switch the bonus on/off under Ways to Earn.</p>
  </div>
</div>

<div class="card" id="upgrade">
  <div class="card-header"><h3><?= icon('arrow-up') ?> Package Upgrade</h3><div class="actions"><a class="btn btn-light btn-sm" href="<?= e(url('admin/upgrades.php')) ?>">Upgrade history</a></div></div>
  <div class="card-body">
    <div class="toggle-grid">
      <?php foreach ([
          'upgrade_enabled' => ['Allow package upgrades', 'Members pay only the price difference (Silver → Gold → Platinum → Diamond).'],
          'upgrade_allow_skip' => ['Allow skipping levels', 'e.g. Silver straight to Diamond. Off = one level at a time.'],
          'upgrade_add_pv' => ['Add the PV difference to the binary', 'e.g. Silver 100 → Gold 400 adds 300 PV to the upline legs (pairing).'],
          'upgrade_wallet' => ['Members can pay with E-Wallet', 'QR Ph follows the PayMongo settings; upgrade codes always work.'],
      ] as $k => [$title, $hint]): ?>
        <label class="toggle-card">
          <input type="checkbox" name="<?= $k ?>" value="1" <?= setting($k, '1') === '1' ? 'checked' : '' ?>>
          <span class="toggle-ui"></span>
          <span><strong><?= e($title) ?></strong><small><?= e($hint) ?></small></span>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="form-group mt-2"><label>Bonuses on upgrade</label>
      <select class="input" name="upgrade_bonus">
        <option value="difference" <?= $sel('upgrade_bonus', 'difference') ?>>Pay the difference — Direct Referral and Coded Bonus of the new package less the old one</option>
        <option value="none" <?= $sel('upgrade_bonus', 'none') ?>>No bonuses on upgrades</option>
      </select>
      <div class="help">The bonus goes to whoever earned the original bonus. Indirect in percent mode pays the % of the difference.</div></div>
  </div>
</div>

<div class="sticky-save"><button class="btn btn-primary" type="submit"><?= icon('settings', 16) ?> Save Compensation Plan</button></div>
</form>
<?php page_footer();
