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

    save_setting('indirect_mode', $mode('indirect_mode'));
    save_setting('indirect_levels', levels_from_post('indirect_levels'));

    $positions = array_unique(array_filter(array_map('intval', explode(',', input('passup_positions'))), fn($n) => $n > 0));
    sort($positions);
    save_setting('passup_positions', implode(',', $positions) ?: '1');
    save_setting('passup_mode', $mode('passup_mode'));
    save_setting('passup_bonus', $numv('passup_bonus'));
    save_setting('passup_sponsor_keeps_direct', isset($_POST['passup_sponsor_keeps_direct']) ? '1' : '0');

    save_setting('pair_pv', (string)max(1, round(input_float('pair_pv'), 2)));
    save_setting('pair_bonus', $numv('pair_bonus'));
    save_setting('pair_daily_cap', (string)max(0, input_int('pair_daily_cap')));

    save_setting('unilevel_base', input('unilevel_base') === 'amount' ? 'amount' : 'pv');
    save_setting('unilevel_percent', levels_from_post('unilevel_percent'));
    save_setting('unilevel_require_active', isset($_POST['unilevel_require_active']) ? '1' : '0');

    // Per-package direct bonus and binary PV
    foreach ((array)($_POST['pkg'] ?? []) as $pid => $row) {
        if (!is_array($row)) {
            continue;
        }
        q('UPDATE packages SET price = ?, pv = ?, direct_bonus = ? WHERE id = ?', [
            max(0, round((float)($row['price'] ?? 0), 2)),
            max(0, round((float)($row['pv'] ?? 0), 2)),
            max(0, round((float)($row['direct_bonus'] ?? 0), 2)),
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
      <p class="help mb-0">Paid to the sponsor once a new member activates with a paid code.</p>
    </div>
  </div>

  <!-- Pass Up -->
  <div class="card">
    <div class="card-header"><h3><?= icon('arrow-up') ?> Pass Up Bonus (Australian Pass Up)</h3></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group"><label>Recruit number(s) passed up</label><input class="input" name="passup_positions" value="<?= e(setting('passup_positions', '1')) ?>" placeholder="1 or 1,2">
          <div class="help">1 = every member's 1st recruit goes up · 1,2 = 2-Up</div></div>
        <div class="form-group"><label>Computation</label>
          <select class="input" name="passup_mode">
            <option value="fixed" <?= $sel('passup_mode', 'fixed') ?>>Fixed amount</option>
            <option value="percent" <?= $sel('passup_mode', 'percent') ?>>Percent of package price</option>
          </select></div>
        <div class="form-group"><label>Pass-up bonus (<?= e($cur) ?> or %)</label><input class="input" type="number" step="0.01" min="0" name="passup_bonus" value="<?= e(setting('passup_bonus', 0)) ?>"></div>
        <div class="form-group"><label>&nbsp;</label>
          <label style="display:flex;gap:8px;align-items:center;font-weight:400"><input type="checkbox" name="passup_sponsor_keeps_direct" value="1" <?= setting('passup_sponsor_keeps_direct', '1') === '1' ? 'checked' : '' ?>> Sponsor still earns Direct Referral on a passed-up recruit</label></div>
      </div>
      <p class="help mb-0">The passed-up recruit (and their own pass-ups) goes to the sponsor's pass-up upline, who earns the bonus.</p>
    </div>
  </div>
</div>

<!-- Packages -->
<div class="card">
  <div class="card-header"><h3><?= icon('package') ?> Packages — Direct Referral Bonus &amp; Binary PV</h3><div class="actions"><a class="btn btn-light btn-sm" href="<?= e(url('admin/packages.php')) ?>"><?= icon('plus', 14) ?> Add / manage packages</a></div></div>
  <div class="card-body p-0 table-wrap">
    <table class="table stack">
      <thead><tr><th>Package</th><th>Price (<?= e($cur) ?>)</th><th>Binary PV</th><th>Direct Referral Bonus (<?= e($cur) ?>)</th></tr></thead>
      <tbody>
      <?php foreach ($packages as $pk): ?>
        <tr>
          <td><strong><?= e($pk['name']) ?></strong> <?= $pk['status'] !== 'active' ? badge($pk['status']) : '' ?></td>
          <td><input class="input" type="number" step="0.01" min="0" name="pkg[<?= (int)$pk['id'] ?>][price]" value="<?= e($pk['price']) ?>"></td>
          <td><input class="input" type="number" step="0.01" min="0" name="pkg[<?= (int)$pk['id'] ?>][pv]" value="<?= e($pk['pv']) ?>"></td>
          <td><input class="input" type="number" step="0.01" min="0" name="pkg[<?= (int)$pk['id'] ?>][direct_bonus]" value="<?= e($pk['direct_bonus']) ?>"></td>
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
      <p class="help">Level 1 = the buyer's sponsor. Percent of the base per level.</p>
      <?php level_editor('unilevel_percent', setting_list('unilevel_percent'), '%'); ?>
    </div>
  </div>
</div>

<div class="grid grid-2">
  <!-- Pairing -->
  <div class="card">
    <div class="card-header"><h3><?= icon('git-merge') ?> Pairing / Matching Bonus (Binary)</h3></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group"><label>PV per side for 1 pair</label><input class="input" type="number" step="0.01" min="1" name="pair_pv" value="<?= e(setting('pair_pv', 100)) ?>"></div>
        <div class="form-group"><label>Bonus per pair (<?= e($cur) ?>)</label><input class="input" type="number" step="0.01" min="0" name="pair_bonus" value="<?= e(setting('pair_bonus', 0)) ?>"></div>
        <div class="form-group"><label>Max pairs per day</label><input class="input" type="number" min="0" name="pair_daily_cap" value="<?= e(setting('pair_daily_cap', 0)) ?>"><div class="help">0 = unlimited. Excess pairs are flushed.</div></div>
      </div>
      <p class="help mb-0">Each package's PV goes to the left or right leg of every upline in the binary tree. Unpaired PV is carried forward.</p>
    </div>
  </div>

  <!-- Discount -->
  <div class="card">
    <div class="card-header"><h3><?= icon('tag') ?> Product Discount</h3></div>
    <div class="card-body">
      <p class="mb-1">Members buy at <strong>Member Price</strong>. The difference from SRP is recorded as their discount / retail profit (not credited to the wallet).</p>
      <a class="btn btn-light btn-sm" href="<?= e(url('admin/products.php')) ?>"><?= icon('box', 14) ?> Set SRP &amp; Member Price per product</a>
    </div>
  </div>
</div>

<div class="sticky-save"><button class="btn btn-primary" type="submit"><?= icon('settings', 16) ?> Save Compensation Plan</button></div>
</form>
<?php page_footer();
