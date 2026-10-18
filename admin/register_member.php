<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

$packages = q_all("SELECT * FROM packages WHERE status = 'active' ORDER BY price");
$placementQ = strtolower(input('placement'));
$form = [
    'sponsor'   => strtolower(input('sponsor')) ?: $placementQ,
    'placement' => $placementQ,
    'position'  => in_array(strtoupper(input('position')), ['L', 'R'], true) ? strtoupper(input('position')) : '',
    'code_mode' => 'new',
    'package_id' => (string)($packages[0]['id'] ?? ''),
    'code_type' => 'paid',
    'sale_method' => '',
];
$error = null;

if (is_post()) {
    csrf_check();
    $form = form_values(['code_mode', 'package_id', 'code_type', 'sale_method', 'code', 'pin', 'sponsor', 'placement', 'position',
        'first_name', 'last_name', 'email', 'mobile', 'address', 'username']);
    $form['password'] = (string)($_POST['password'] ?? '');
    try {
        $newId = tx(function () use (&$form, $me) {
            if ($form['code_mode'] !== 'existing') {
                // Generate a fresh activation code for this registration (rolled back if registration fails).
                $pkg = q_row("SELECT * FROM packages WHERE id = ? AND status = 'active'", [(int)$form['package_id']]);
                if (!$pkg) {
                    throw new RuntimeException('Please choose a package.');
                }
                $type = $form['code_type'] === 'free' ? 'free' : 'paid';
                $code = generate_codes((int)$pkg['id'], 1, $type, null)[0];
                $form['code'] = $code;
                $form['pin'] = (string)q_val('SELECT pin FROM codes WHERE code = ?', [$code]);
                if ($type === 'paid' && in_array($form['sale_method'], ['cash', 'bank', 'gcash'], true)) {
                    erp_post_code_sale($form['sale_method'], (float)$pkg['price'], "Code sale {$code}: {$pkg['name']} (" . strtoupper($form['sale_method']) . ") for @{$form['username']}", null, 'codes', null);
                }
            }
            $id = register_member($form);
            log_activity((int)$me['id'], "Admin registered @{$form['username']} (code {$form['code']})");
            return $id;
        });
        $new = q_row('SELECT u.username, u.placement_id, u.position, c.code, c.pin FROM users u JOIN codes c ON c.used_by = u.id WHERE u.id = ?', [$newId]);
        flash('success', "Member @{$new['username']} registered and activated (code {$new['code']}). Placed on the " . ($new['position'] === 'L' ? 'LEFT' : 'RIGHT') . ' side.');
        redirect('admin/genealogy.php?root=' . (int)$new['placement_id']);
    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    }
    unset($form['password']);
}

$placementUser = $form['placement'] !== '' ? find_member_by_username($form['placement']) : null;
$hideCodeSection = true;
$lockSponsor = false;
$codeOptions = null;

page_header('Register Member', $me, 'Add a new member directly from the Backoffice');
?>
<div class="grid grid-main">
  <div class="card">
    <div class="card-header"><h3><?= icon('user-plus') ?> New Member</h3>
      <?php if ($placementUser && $form['position']): ?>
        <div class="actions"><span class="badge badge-gold">Placement: @<?= e($placementUser['username']) ?> · <?= $form['position'] === 'L' ? 'LEFT' : 'RIGHT' ?></span></div>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="section-title">Activation Code</div>
        <div class="radio-row mb-2">
          <label class="radio-pill"><input type="radio" name="code_mode" value="new" <?= $form['code_mode'] !== 'existing' ? 'checked' : '' ?> data-code-mode><span>Generate a new code</span></label>
          <label class="radio-pill"><input type="radio" name="code_mode" value="existing" <?= $form['code_mode'] === 'existing' ? 'checked' : '' ?> data-code-mode><span>Use an existing code</span></label>
        </div>
        <div class="form-grid" data-code-new>
          <div class="form-group"><label>Package</label>
            <select class="input" name="package_id"><?php foreach ($packages as $pk): ?><option value="<?= (int)$pk['id'] ?>" <?= (string)$pk['id'] === (string)$form['package_id'] ? 'selected' : '' ?>><?= e($pk['name']) ?> – <?= money($pk['price']) ?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label>Code type</label>
            <select class="input" name="code_type"><option value="paid">Paid (earns bonuses)</option><option value="free" <?= $form['code_type'] === 'free' ? 'selected' : '' ?>>Free (no bonuses / PV)</option></select></div>
          <div class="form-group full"><label>Payment received (ERP)</label>
            <select class="input" name="sale_method">
              <option value="">Not yet / record later</option>
              <?php foreach (['cash' => 'Cash on Hand', 'bank' => 'Bank Deposit', 'gcash' => 'GCash / Maya'] as $k => $l): ?><option value="<?= $k ?>" <?= $form['sale_method'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="form-grid" data-code-existing>
          <div class="form-group"><label for="code">Activation Code</label><input class="input mono" id="code" name="code" value="<?= e($form['code'] ?? '') ?>" placeholder="XGXXXXXXXX"></div>
          <div class="form-group"><label for="pin">PIN</label><input class="input mono" id="pin" name="pin" value="<?= e($form['pin'] ?? '') ?>" inputmode="numeric"></div>
        </div>
        <?php require APP_ROOT . '/includes/register_form.php'; ?>
        <button class="btn btn-primary mt-3" type="submit"><?= icon('user-plus', 16) ?> Register &amp; Activate</button>
        <a class="btn btn-light mt-3" href="<?= e(url('admin/genealogy.php' . ($placementUser ? '?root=' . $placementUser['id'] : ''))) ?>">Cancel</a>
      </form>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h3><?= icon('tree') ?> How it works</h3></div>
    <div class="card-body small">
      <ol style="padding-left:18px;margin:0">
        <li>Click <strong>+ Add</strong> on an empty Left or Right slot in the Genealogy — placement and side are filled in for you.</li>
        <li>Choose a package to generate a new activation code, or enter an existing code and PIN.</li>
        <li>The sponsor defaults to the placement member; change it to the actual sponsor if different (the placement must be in the sponsor's genealogy).</li>
        <li>All bonuses (Direct Referral, Coded Bonus, Pairing, Indirect) are computed exactly as in a normal registration.</li>
      </ol>
      <div class="divider"></div>
      <p class="mb-0 text-muted">A generated code is only created if the registration succeeds.</p>
    </div>
  </div>
</div>
<script>
(function () {
  var radios = document.querySelectorAll('[data-code-mode]');
  var newBox = document.querySelector('[data-code-new]'), oldBox = document.querySelector('[data-code-existing]');
  var sync = function () {
    var existing = document.querySelector('[data-code-mode]:checked').value === 'existing';
    newBox.style.display = existing ? 'none' : '';
    oldBox.style.display = existing ? '' : 'none';
    oldBox.querySelectorAll('input').forEach(function (i) { i.required = existing; });
  };
  radios.forEach(function (r) { r.addEventListener('change', sync); });
  sync();
})();
</script>
<?php page_footer();
