<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout_auth.php';

if (current_user()) redirect('index.php');

$errors = [];
$churches = q_all('SELECT id, name, city, region, denomination FROM churches ORDER BY name');

if (is_post()) {
    csrf_check();
    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $pass = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');
    $church = (int) ($_POST['church_id'] ?? 0) ?: null;

    if ($first === '' || $last === '') $errors[] = 'Please enter your first and last name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if (!preg_match('/^[A-Za-z0-9_.]{3,30}$/', $username)) $errors[] = 'Username must be 3–30 letters, numbers, dots or underscores.';
    if (strlen($pass) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($pass !== $confirm) $errors[] = 'Passwords do not match.';
    if (empty($_POST['agree'])) $errors[] = 'Please agree to the Terms of Service and Privacy Policy.';
    if ($church && !q_val('SELECT 1 FROM churches WHERE id = ?', [$church])) $church = null;

    if (!$errors) {
        if (q_val('SELECT 1 FROM users WHERE email = ?', [$email])) $errors[] = 'That email is already registered.';
        if (q_val('SELECT 1 FROM users WHERE username = ?', [$username])) $errors[] = 'That username is taken.';
    }
    if (!$errors) {
        q('INSERT INTO users (first_name, last_name, email, username, password_hash, church_id) VALUES (?,?,?,?,?,?)',
            [mb_substr($first, 0, 80), mb_substr($last, 0, 80), $email, $username, password_hash($pass, PASSWORD_DEFAULT), $church]);
        $id = (int) db()->lastInsertId();
        login_user($id, false);
        flash('success', 'Welcome to the PCEC Community, ' . $first . '!');
        redirect('index.php');
    }
}

auth_head(t('Create Account'), 'register-body');
?>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-top auth-top-sm">
      <div class="auth-top-bar">
        <a href="<?= e(url('login.php')) ?>" class="icon-btn light" aria-label="Back"><?= icon('arrow-left') ?></a>
        <?= lang_switch() ?>
      </div>
      <?php auth_brand(true); ?>
    </div>
    <div class="auth-panel">
      <h1><?= e(t('Create Your Account')) ?></h1>
      <p class="auth-sub"><?= e(t('Join the PCEC Community Platform and be part of our growing network of churches and leaders.')) ?></p>
      <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
      <form method="post" class="auth-form" novalidate>
        <?= csrf_field() ?>
        <div class="grid-2">
          <label class="field"><?= icon('user') ?><input name="first_name" placeholder="<?= e(t('First Name')) ?>" value="<?= old('first_name') ?>" autocomplete="given-name" required></label>
          <label class="field"><?= icon('user') ?><input name="last_name" placeholder="<?= e(t('Last Name')) ?>" value="<?= old('last_name') ?>" autocomplete="family-name" required></label>
        </div>
        <label class="field"><?= icon('mail') ?><input type="email" name="email" placeholder="<?= e(t('Email Address')) ?>" value="<?= old('email') ?>" autocomplete="email" required></label>
        <label class="field"><?= icon('user') ?><input name="username" placeholder="<?= e(t('Username')) ?>" value="<?= old('username') ?>" autocomplete="username" required></label>
        <label class="field"><?= icon('lock') ?><input type="password" name="password" placeholder="<?= e(t('Password')) ?>" autocomplete="new-password" minlength="8" required><button type="button" class="pw-toggle" aria-label="Show password"><?= icon('eye-off') ?></button></label>
        <label class="field"><?= icon('lock') ?><input type="password" name="confirm" placeholder="<?= e(t('Confirm Password')) ?>" autocomplete="new-password" required><button type="button" class="pw-toggle" aria-label="Show password"><?= icon('eye-off') ?></button></label>
        <div class="picker" data-picker>
          <label class="field field-select"><?= icon('church') ?>
            <select name="church_id" data-picker-select aria-label="<?= e(t('Church / Organization (Optional)')) ?>"
                    data-search-placeholder="Search church, city or denomination…" data-sheet-title="Select your church">
              <option value=""><?= e(t('Church / Organization (Optional)')) ?></option>
              <?php foreach ($churches as $c): ?>
                <option value="<?= (int) $c['id'] ?>"
                        data-city="<?= e($c['city']) ?>" data-region="<?= e($c['region']) ?>" data-denomination="<?= e($c['denomination']) ?>"
                        <?= (int) ($_POST['church_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?= icon('chevron-down', 'select-caret') ?>
          </label>
        </div>
        <label class="check"><input type="checkbox" name="agree" value="1" <?= is_post() && empty($_POST['agree']) ? '' : 'checked' ?>><span></span><em>I agree to the <a href="<?= e(url('terms.php')) ?>" class="link">Terms of Service</a> and <a href="<?= e(url('terms.php#privacy')) ?>" class="link u">Privacy Policy</a></em></label>
        <button class="btn btn-gradient btn-lg btn-block"><?= icon('user-plus') ?> <?= e(t('Create Account')) ?></button>
      </form>
      <div class="divider"><span><?= e(t('OR SIGN UP WITH')) ?></span></div>
      <div class="social-row social-row-2">
        <a href="<?= e(url('social.php?p=google')) ?>" class="btn btn-social btn-google"><?= brand_icon('google') ?> Sign up with Google</a>
        <a href="<?= e(url('social.php?p=facebook')) ?>" class="btn btn-social btn-facebook"><?= brand_icon('facebook') ?> Sign up with Facebook</a>
      </div>
      <p class="auth-switch"><?= e(t('Already have an account?')) ?> <a href="<?= e(url('login.php')) ?>"><?= e(t('Log In')) ?> <?= icon('chevron-right') ?></a></p>
    </div>
  </div>
</div>
<?php auth_foot(); ?>
