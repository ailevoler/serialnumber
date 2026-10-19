<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

$defaults = sim_defaults();
$form = $defaults;
$packages = q_all("SELECT id, name, price FROM packages WHERE status = 'active' ORDER BY price");
$errors = [];

if (is_post()) {
    csrf_check();
    $action = input('action');
    try {
        if ($action === 'set_pin') {
            $hash = (string)q_val('SELECT password FROM users WHERE id = ?', [$me['id']]);
            if (!password_verify((string)($_POST['admin_password'] ?? ''), $hash)) {
                throw new RuntimeException('Your admin password is incorrect.');
            }
            if (($_POST['new_pin'] ?? '') !== ($_POST['confirm_pin'] ?? '')) {
                throw new RuntimeException('The PINs do not match.');
            }
            $wasSet = admin_pin_is_set();
            admin_pin_set((string)$_POST['new_pin']);
            log_activity((int)$me['id'], 'Admin Action PIN ' . ($wasSet ? 'changed' : 'set'));
            flash('success', 'Admin Action PIN saved.');
            redirect('admin/simulation.php');
        }

        if ($action === 'backup') {
            $file = db_backup('manual');
            log_activity((int)$me['id'], "Database backup {$file}");
            flash('success', 'Backup created and downloading: ' . $file);
            redirect('admin/simulation.php?dl=' . urlencode($file));
        }

        if ($action === 'delete_backup') {
            admin_pin_check((string)($_POST['pin'] ?? ''));
            $path = backup_path(input('file'));
            if (!$path) {
                throw new RuntimeException('Backup file not found.');
            }
            unlink($path);
            log_activity((int)$me['id'], 'Deleted backup ' . input('file'));
            flash('success', 'Backup deleted.');
            redirect('admin/simulation.php#backups');
        }

        if ($action === 'simulate') {
            $form = sim_params($_POST);
            admin_pin_check((string)($_POST['pin'] ?? ''));
            $file = db_backup('pre-simulation');
            $runId = run_simulation($form, (int)$me['id'], $file);
            $run = q_row('SELECT status, error FROM simulation_runs WHERE id = ?', [$runId]);
            if ($run['status'] === 'failed') {
                flash('error', 'The simulation stopped early: ' . $run['error'] . ' The report shows the members created up to that point.');
            } else {
                flash('success', "Simulation #{$runId} finished. A backup taken before the run is downloading.");
            }
            redirect('admin/simulation_report.php?id=' . $runId . '&dl=' . urlencode($file));
        }

        if ($action === 'reset') {
            if (trim((string)($_POST['confirm'] ?? '')) !== 'RESET') {
                throw new RuntimeException('Type RESET (capital letters) to confirm.');
            }
            admin_pin_check((string)($_POST['pin'] ?? ''));
            $file = db_backup('pre-reset');
            $counts = reset_system(!empty($_POST['clear_simulations']));
            log_activity((int)$me['id'], "System reset (backup {$file}): " . (int)$counts['members'] . ' member(s) removed');
            flash('success', 'System reset done: ' . num($counts['members']) . ' member(s), ' . num($counts['earnings']) . ' earning(s), '
                . num($counts['orders']) . ' order(s) and ' . num($counts['journal_entries']) . ' journal entr(ies) cleared. Admin and root accounts kept. The backup is downloading.');
            redirect('admin/simulation.php?dl=' . urlencode($file));
        }
    } catch (RuntimeException $ex) {
        $errors[$action] = $ex->getMessage();
    }
}

$pinSet = admin_pin_is_set();
$rootUser = q_row('SELECT username, first_name, last_name FROM users WHERE id = ?', [system_root_id()]);
$backups = backup_list();
$runs = q_all('SELECT r.*, u.username admin FROM simulation_runs r LEFT JOIN users u ON u.id = r.created_by ORDER BY r.id DESC LIMIT 20');
$memberCount = (int)q_val("SELECT COUNT(*) FROM users WHERE role = 'member'");
$dl = backup_path(input('dl')) ? input('dl') : '';
$err = fn($k) => !empty($errors[$k]) ? '<div class="alert alert-error">' . e($errors[$k]) . '</div>' : '';
$pinField = fn() => '<div class="form-group"><label>Admin Action PIN</label><input class="input mono" type="password" name="pin" inputmode="numeric" pattern="\d{4,8}" autocomplete="off" required placeholder="••••"></div>';

page_header('Simulation & Reset', $me, 'Stress-test the compensation plan, reset data and back up the database');
?>
<?php if ($dl): ?>
<div class="alert alert-info">Your backup <strong class="mono"><?= e($dl) ?></strong> is downloading. If it does not start, <a href="<?= e(url('admin/backup_download.php?f=' . urlencode($dl))) ?>">click here to download it</a>.</div>
<iframe src="<?= e(url('admin/backup_download.php?f=' . urlencode($dl))) ?>" style="display:none" title="backup download"></iframe>
<?php endif; ?>

<div class="alert alert-warning">
  <strong>Para sa testing lamang.</strong> Ang Simulation ay gumagawa ng totoong test members (<span class="mono">sim#_n</span>), codes, orders, kita at journal entries para makita kung kikita o malulugi ang plan.
  Bago tumakbo ang Simulation o Reset, awtomatikong gagawa at magda-download ng <strong>database backup</strong>. Kailangan ang <strong>Admin Action PIN</strong> sa bawat proseso.
</div>

<div class="grid grid-main">
  <div>
    <div class="card" id="simulate">
      <div class="card-header"><h3><?= icon('chart') ?> Plan Simulation (Actuarial Test)</h3></div>
      <div class="card-body">
        <?= $err('simulate') ?>
        <?php if (!$pinSet): ?><div class="alert alert-info">Set the Admin Action PIN first (right panel).</div><?php endif; ?>
        <form method="post" data-busy="Running the simulation… this can take a few minutes. Huwag isara ang page.">
          <?= csrf_field() ?><input type="hidden" name="action" value="simulate">
          <div class="section-title">Network</div>
          <div class="form-grid">
            <div class="form-group"><label>Members to simulate</label><input class="input" type="number" name="members" min="1" max="2000" value="<?= (int)$form['members'] ?>" required><div class="help">Up to 2,000 per run.</div></div>
            <div class="form-group"><label>Levels (sponsor depth)</label><input class="input" type="number" name="levels" min="1" max="30" value="<?= (int)$form['levels'] ?>" required><div class="help">How many generations below the top sponsor.</div></div>
            <div class="form-group"><label>Invites per member</label><input class="input" type="number" name="invites" min="0" max="100" value="<?= (int)$form['invites'] ?>"><div class="help">0 = auto (just enough to fill the levels).</div></div>
            <div class="form-group"><label>Top sponsor</label><input class="input" name="top" value="<?= e($form['top']) ?>" required><div class="help">Default: root account.</div></div>
          </div>
          <div class="section-title">Binary Pairing</div>
          <div class="form-grid">
            <div class="form-group"><label>Max pairs per day (cap)</label><input class="input" type="number" name="pair_cap" min="0" max="1000" value="<?= (int)$form['pair_cap'] ?>"><div class="help">Current plan: <?= (int)setting('pair_daily_cap', 0) ?>. 0 = no cap. Used for this run only.</div></div>
            <div class="form-group"><label>Simulated days</label><input class="input" type="number" name="days" min="1" max="365" value="<?= (int)$form['days'] ?>" required><div class="help">Joins are spread over these days, so the daily cap resets each day.</div></div>
            <div class="form-group"><label>Placement</label>
              <select class="input" name="placement" data-placement>
                <option value="auto" <?= $form['placement'] === 'auto' ? 'selected' : '' ?>>Balanced (auto-fill, most pairs)</option>
                <option value="ratio" <?= $form['placement'] === 'ratio' ? 'selected' : '' ?>>Left / Right ratio</option>
              </select></div>
            <div class="form-group" data-ratio><label>% placed on the LEFT leg</label><input class="input" type="number" name="left_percent" min="0" max="100" value="<?= (int)$form['left_percent'] ?>"><div class="help">50 = even legs; 80 = strong left, weak right (fewer pairs).</div></div>
          </div>
          <div class="section-title">Pass-Up (Coded Bonus)</div>
          <div class="form-grid">
            <div class="form-group"><label>Invites passed up</label><input class="input" name="passup_positions" value="<?= e($form['passup_positions']) ?>" placeholder="1,2"><div class="help"><strong>1</strong> = invite #1 passes up to the upline; from #2 the sponsor earns (1-Up). <strong>1,2</strong> = #1 and #2 pass up (2-Up). Current plan: <?= e(implode(',', setting_list('passup_positions')) ?: 'none') ?>. This run only.</div></div>
            <div class="form-group"><label>&nbsp;</label><label style="display:flex;gap:8px;align-items:center;font-weight:400"><input type="checkbox" name="passup_kept" value="1" <?= !empty($form['passup_kept']) ? 'checked' : '' ?>> Invites that are not passed up also pay a Coded Bonus to the sponsor</label>
              <div class="help">Current plan: <?= setting('passup_kept_bonus', '0') === '1' ? 'on' : 'off' ?>. The top of the line always earns its own pass-ups.</div></div>
          </div>
          <div class="section-title">Package Mix (% of new members)</div>
          <div class="form-grid">
            <?php foreach ($packages as $pk): ?>
              <div class="form-group"><label><?= e($pk['name']) ?> <span class="text-muted">(<?= money($pk['price']) ?>)</span></label><input class="input" type="number" step="0.01" min="0" max="100" name="mix[<?= (int)$pk['id'] ?>]" value="<?= e((string)($form['mix'][$pk['id']] ?? 0)) ?>"></div>
            <?php endforeach; ?>
          </div>
          <div class="section-title">Repeat Purchase &amp; Costs</div>
          <div class="form-grid">
            <div class="form-group"><label>Repeat units per member</label><input class="input" type="number" name="repeat_units" min="0" max="100" value="<?= (int)$form['repeat_units'] ?>"><div class="help">Bottles / pouches each member buys during the period (Unilevel).</div></div>
            <div class="form-group"><label>% of members who upgrade</label><input class="input" type="number" step="0.01" min="0" max="100" name="upgrade_percent" value="<?= e((string)($form['upgrade_percent'] ?? 0)) ?>"><div class="help">They move up one package (paying the difference) on a later day.</div></div>
            <div class="form-group"><label>Product cost (COGS) %</label><input class="input" type="number" step="0.01" min="0" max="100" name="cogs_percent" value="<?= e((string)$form['cogs_percent']) ?>"><div class="help">Cost of goods as % of selling price. Also the real cost of Product Wallet credits.</div></div>
            <div class="form-group"><label>Package product cost %</label><input class="input" type="number" step="0.01" min="0" max="100" name="package_cost_percent" value="<?= e((string)$form['package_cost_percent']) ?>"><div class="help">Cost of the products inside a package, as % of its price.</div></div>
            <div class="form-group"><label>Operating expenses %</label><input class="input" type="number" step="0.01" min="0" max="100" name="opex_percent" value="<?= e((string)$form['opex_percent']) ?>"><div class="help">Admin, shipping, salaries, etc. as % of revenue.</div></div>
            <div class="form-group"><label>Random seed <span class="text-muted">(optional)</span></label><input class="input" name="seed" value="<?= e($form['seed']) ?>" inputmode="numeric"><div class="help">Same seed = same package picks.</div></div>
            <?= $pinField() ?>
          </div>
          <button class="btn btn-primary mt-2" type="submit" <?= $pinSet ? '' : 'disabled' ?>><?= icon('chart', 16) ?> Run Simulation &amp; Generate Report</button>
        </form>
      </div>
    </div>

    <div class="card" id="runs">
      <div class="card-header"><h3><?= icon('list') ?> Simulation Reports</h3></div>
      <div class="card-body p-0 table-wrap">
        <table class="table">
          <thead><tr><th>#</th><th>Date</th><th>Members</th><th class="text-right">Net Profit</th><th class="text-right">Payout Ratio</th><th>Result</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($runs as $r): $net = (float)$r['net_profit']; ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= e(fdatetime($r['created_at'])) ?><br><small class="text-muted">by @<?= e($r['admin'] ?? '—') ?></small></td>
              <td><?= num($r['members_created']) ?></td>
              <td class="text-right <?= $net < 0 ? 'text-danger' : 'text-green' ?>"><?= $r['net_profit'] !== null ? money($net) : '—' ?></td>
              <td class="text-right"><?= $r['payout_ratio'] !== null ? num($r['payout_ratio'], 1) . '%' : '—' ?></td>
              <td><?php if ($r['status'] === 'running'): ?><span class="badge badge-muted">Running</span><?php else: ?><span class="badge <?= $net < 0 ? 'badge-danger' : 'badge-success' ?>"><?= $net < 0 ? 'Nalulugi' : 'Kumikita' ?></span><?php if ($r['status'] === 'failed'): ?> <span class="badge badge-warning">Stopped</span><?php endif; endif; ?></td>
              <td><a class="btn btn-light btn-xs" href="<?= e(url('admin/simulation_report.php?id=' . $r['id'])) ?>">View report</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$runs) echo empty_row(7, 'No simulations yet.'); ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div>
    <div class="card" id="pin">
      <div class="card-header"><h3><?= icon('shield') ?> Admin Action PIN</h3><div class="actions"><span class="badge <?= $pinSet ? 'badge-success' : 'badge-warning' ?>"><?= $pinSet ? 'Set' : 'Not set' ?></span></div></div>
      <div class="card-body">
        <?= $err('set_pin') ?>
        <p class="small text-muted">Required before running a Simulation, Reset or deleting a backup. 5 wrong tries lock it for 15 minutes.</p>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="set_pin">
          <div class="form-group"><label>Your admin password</label><input class="input" type="password" name="admin_password" autocomplete="current-password" required></div>
          <div class="form-group"><label><?= $pinSet ? 'New PIN' : 'PIN' ?> (4–8 digits)</label><input class="input mono" type="password" name="new_pin" inputmode="numeric" pattern="\d{4,8}" autocomplete="new-password" required></div>
          <div class="form-group"><label>Confirm PIN</label><input class="input mono" type="password" name="confirm_pin" inputmode="numeric" pattern="\d{4,8}" autocomplete="new-password" required></div>
          <button class="btn btn-gold btn-block" type="submit"><?= icon('key', 16) ?> <?= $pinSet ? 'Change PIN' : 'Set PIN' ?></button>
        </form>
      </div>
    </div>

    <div class="card" id="reset" style="border-color:#f3c2c2">
      <div class="card-header"><h3 class="text-danger"><?= icon('alert') ?> Reset System</h3></div>
      <div class="card-body">
        <?= $err('reset') ?>
        <p class="small">Deletes <strong>all members</strong> (<?= num(max(0, $memberCount - 1)) ?> now) except the root account<?= $rootUser ? ' <strong>@' . e($rootUser['username']) . '</strong>' : '' ?>, plus all codes, orders, package upgrades, earnings, wallet history, payouts, payments, chat, CRM leads, journal entries, expenses and logs. The root account's wallets and counters go back to zero.</p>
        <p class="small text-muted">Kept: admin &amp; content manager accounts, settings, compensation plan, packages, products, website CMS and chart of accounts.</p>
        <form method="post" data-busy="Backing up and resetting the system…" data-confirm="Sigurado ka bang buburahin ang lahat ng data maliban sa admin at root account? May backup na awtomatikong ida-download.">
          <?= csrf_field() ?><input type="hidden" name="action" value="reset">
          <div class="form-group"><label>Type <strong>RESET</strong> to confirm</label><input class="input mono" name="confirm" autocomplete="off" required placeholder="RESET"></div>
          <label class="small" style="display:flex;gap:8px;align-items:center;margin-bottom:12px"><input type="checkbox" name="clear_simulations" value="1"> Also delete the simulation reports</label>
          <?= $pinField() ?>
          <button class="btn btn-danger btn-block" type="submit" <?= $pinSet ? '' : 'disabled' ?>><?= icon('alert', 16) ?> Back Up &amp; Reset System</button>
        </form>
      </div>
    </div>

    <div class="card" id="backups">
      <div class="card-header"><h3><?= icon('box') ?> Database Backups</h3>
        <div class="actions"><form method="post" data-busy="Creating the backup…"><?= csrf_field() ?><input type="hidden" name="action" value="backup"><button class="btn btn-primary btn-sm" type="submit"><?= icon('box', 14) ?> Backup Now</button></form></div>
      </div>
      <div class="card-body p-0 table-wrap">
        <?= $err('delete_backup') ?>
        <table class="table">
          <thead><tr><th>File</th><th class="text-right">Size</th><th></th></tr></thead>
          <tbody>
          <?php foreach (array_slice($backups, 0, 15) as $b): ?>
            <tr>
              <td><span class="mono small"><?= e($b['name']) ?></span><br><small class="text-muted"><?= e(date('M d, Y h:i A', $b['time'])) ?></small></td>
              <td class="text-right small"><?= e(human_size($b['size'])) ?></td>
              <td class="text-right" style="white-space:nowrap">
                <a class="btn btn-light btn-xs" href="<?= e(url('admin/backup_download.php?f=' . urlencode($b['name']))) ?>">Download</a>
                <button class="btn btn-outline btn-xs" type="button" data-del-backup="<?= e($b['name']) ?>">Delete</button>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$backups) echo empty_row(3, 'No backups yet.'); ?>
          </tbody>
        </table>
        <form method="post" id="delBackupForm" style="display:none;padding:14px" class="card-body">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete_backup"><input type="hidden" name="file">
          <p class="small mb-1">Delete <strong class="mono" data-del-name></strong>?</p>
          <?= $pinField() ?>
          <button class="btn btn-danger btn-sm" type="submit">Delete backup</button>
          <button class="btn btn-light btn-sm" type="button" data-del-cancel>Cancel</button>
        </form>
      </div>
      <div class="card-body small text-muted">Backups are stored in the <span class="mono">backups/</span> folder (not reachable from the web). Restore with phpMyAdmin → Import, or <span class="mono">gunzip &lt; file.sql.gz | mysql dbname</span>.</div>
    </div>
  </div>
</div>

<div class="sim-busy" data-busy-overlay hidden><div><span class="spinner"></span><p data-busy-text></p></div></div>
<style>
.sim-busy { position: fixed; inset: 0; background: rgba(255,255,255,.88); z-index: 999; display: flex; align-items: center; justify-content: center; text-align: center; padding: 16px; }
.sim-busy[hidden] { display: none; }
.sim-busy p { font-weight: 600; color: var(--green-900); max-width: 360px; }
.sim-busy .spinner { display: inline-block; width: 44px; height: 44px; border: 4px solid var(--green-50); border-top-color: var(--green); border-radius: 50%; animation: simspin 0.9s linear infinite; }
@keyframes simspin { to { transform: rotate(360deg); } }
</style>
<script>
(function () {
  var sel = document.querySelector('[data-placement]'), ratio = document.querySelector('[data-ratio]');
  var sync = function () { ratio.style.display = sel.value === 'ratio' ? '' : 'none'; };
  sel.addEventListener('change', sync); sync();

  var overlay = document.querySelector('[data-busy-overlay]');
  document.querySelectorAll('form[data-busy]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (f.dataset.confirm && !window.confirm(f.dataset.confirm)) { ev.preventDefault(); return; }
      overlay.querySelector('[data-busy-text]').textContent = f.dataset.busy;
      overlay.hidden = false;
    });
  });

  var del = document.getElementById('delBackupForm');
  document.querySelectorAll('[data-del-backup]').forEach(function (b) {
    b.addEventListener('click', function () {
      del.file.value = b.dataset.delBackup;
      del.querySelector('[data-del-name]').textContent = b.dataset.delBackup;
      del.style.display = '';
      del.querySelector('[name=pin]').focus();
    });
  });
  del.querySelector('[data-del-cancel]').addEventListener('click', function () { del.style.display = 'none'; });
})();
</script>
<?php page_footer();
