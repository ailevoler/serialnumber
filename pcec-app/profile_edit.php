<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/partials.php';
$me = require_login();
$errors = [];

if (is_post()) {
    csrf_check();
    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    [$phoneOk, $phone, $phoneErr] = normalize_phone((string) ($_POST['phone_country'] ?? 'PH'), (string) ($_POST['phone'] ?? ''));
    if ($first === '' || $last === '') $errors['name'] = 'Please enter your first and last name.';
    if (!$phoneOk) $errors['phone'] = $phoneErr;
    if (!$errors) {
        q('UPDATE users SET first_name = ?, last_name = ?, title = ?, phone = ?, phone_country = ?, church_name = ?, address = ?, bio = ? WHERE id = ?',
            [mb_substr($first, 0, 80), mb_substr($last, 0, 80), mb_substr(trim($_POST['title'] ?? ''), 0, 60) ?: null, $phone,
             (string) $_POST['phone_country'], mb_substr(trim($_POST['church_name'] ?? ''), 0, 160) ?: null,
             mb_substr(trim($_POST['address'] ?? ''), 0, 400) ?: null, mb_substr(trim($_POST['bio'] ?? ''), 0, 500) ?: null, $me['id']]);
        flash('success', 'Your profile has been saved.');
        redirect('profile.php');
    }
}
$v = fn(string $k) => e((string) (is_post() ? ($_POST[$k] ?? '') : ($me[$k] ?? '')));
$phoneLocal = is_post() ? (string) ($_POST['phone'] ?? '') : format_phone($me['phone'], $me['phone_country'] ?: 'PH');

$pageTitle = 'Edit Profile';
$activeNav = 'profile';
require __DIR__ . '/includes/header.php';
?>
<form method="post" class="card form auth-like">
  <?= csrf_field() ?>
  <div class="grid-3">
    <label>Title<input name="title" value="<?= $v('title') ?>" placeholder="Rev., Ptr., Bro., Sis." maxlength="60"></label>
    <label>First name<input name="first_name" value="<?= $v('first_name') ?>" required maxlength="80" autocomplete="given-name"></label>
    <label>Last name<input name="last_name" value="<?= $v('last_name') ?>" required maxlength="80" autocomplete="family-name"></label>
  </div>
  <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>
  <label>Email Address<input value="<?= e($me['email']) ?>" readonly></label>
  <p class="hint">To change your email, contact the PCEC office.</p>
  <span class="form-label">Mobile number</span>
  <?= phone_field(is_post() ? (string) ($_POST['phone_country'] ?? 'PH') : ($me['phone_country'] ?: 'PH'), $phoneLocal, $errors['phone'] ?? '') ?>
  <label>Church Name<input name="church_name" value="<?= $v('church_name') ?>" placeholder="e.g. Grace Bible Church" maxlength="160"></label>
  <label>Address<textarea name="address" rows="3" maxlength="400" autocomplete="street-address"><?= $v('address') ?></textarea></label>
  <label>About me (optional)<textarea name="bio" rows="2" maxlength="500"><?= $v('bio') ?></textarea></label>
  <button class="btn btn-gradient btn-lg btn-block"><?= icon('check') ?> Save Changes</button>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
