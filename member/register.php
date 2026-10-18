<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_member();
$uid = (int)$me['id'];

$codeOptions = q_all("SELECT c.code, c.code_type, p.name package_name FROM codes c JOIN packages p ON p.id = c.package_id
                      WHERE c.owner_id = ? AND c.status = 'unused' AND c.code_type <> 'upgrade' ORDER BY c.id", [$uid]);
$form = ['sponsor' => $me['username'], 'placement' => input('placement'), 'position' => strtoupper(input('position'))];
$lockSponsor = false;
$error = null;

if (is_post()) {
    csrf_check();
    $form = form_values(['code', 'sponsor', 'placement', 'position', 'first_name', 'last_name', 'email', 'mobile', 'address', 'username']);
    $form['password'] = (string)($_POST['password'] ?? '');
    // Codes owned by the logged-in member: look up the PIN for them.
    $form['pin'] = (string)q_val("SELECT pin FROM codes WHERE code = ? AND owner_id = ? AND status = 'unused'", [$form['code'], $uid]);
    try {
        $sponsor = find_member_by_username(strtolower($form['sponsor']));
        if (!$sponsor || ((int)$sponsor['id'] !== $uid && !in_sponsor_downline($uid, (int)$sponsor['id']))) {
            throw new RuntimeException('Sponsor must be you or a member of your downline.');
        }
        $newId = register_member($form);
        log_activity($uid, "Registered new member #{$newId} ({$form['username']})");
        flash('success', "Member @{$form['username']} has been registered and activated.");
        redirect('member/genealogy.php');
    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    }
    unset($form['password']);
}

page_header('Register Member', $me, 'Activate a new member using one of your codes');
?>
<div class="grid grid-main">
  <div class="card">
    <div class="card-header"><h3><?= icon('user-plus') ?> New Member Registration</h3></div>
    <div class="card-body">
      <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
      <?php if (!$codeOptions): ?>
        <div class="alert alert-warning">You have no unused activation codes. Please purchase codes from the company/administrator first.</div>
      <?php else: ?>
        <form method="post">
          <?= csrf_field() ?>
          <?php require APP_ROOT . '/includes/register_form.php'; ?>
          <button class="btn btn-primary mt-3" type="submit"><?= icon('user-plus', 16) ?> Register &amp; Activate</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h3><?= icon('key') ?> How it works</h3></div>
    <div class="card-body small">
      <ol style="padding-left:18px;margin:0">
        <li>Select one of your unused activation codes.</li>
        <li>The sponsor is you by default (or someone in your downline).</li>
        <li>Choose a placement from your genealogy and Left/Right — or let the system auto-place.</li>
        <li>Bonuses (Direct, Indirect, Pass Up and Pairing) are credited instantly to the wallets.</li>
      </ol>
      <div class="divider"></div>
      <p class="mb-0">Unused codes: <strong><?= count($codeOptions) ?></strong> · <a href="<?= e(url('member/codes.php')) ?>">Manage codes</a></p>
    </div>
  </div>
</div>
<?php page_footer();
