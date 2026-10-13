<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout_auth.php';

if (current_user()) redirect('index.php');

const MIN_PASSWORD = 8;
$errors = [];
$fieldErr = [];

/** Unique username from the email address (members sign in with their email). */
function make_username(string $email): string
{
    $base = substr(preg_replace('/[^a-z0-9_.]/', '', strtolower(strstr($email, '@', true) ?: 'member')), 0, 24) ?: 'member';
    $name = strlen($base) >= 3 ? $base : $base . 'member';
    for ($i = 0; q_val('SELECT 1 FROM users WHERE username = ?', [$name]); $i++) {
        $name = $base . random_int(10, 9999);
    }
    return $name;
}

if (is_post()) {
    csrf_check();
    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');
    [$phoneOk, $phone, $phoneErr] = normalize_phone((string) ($_POST['phone_country'] ?? 'PH'), (string) ($_POST['phone'] ?? ''));

    if ($first === '') $fieldErr['first_name'] = 'Please enter your first name.';
    if ($last === '') $fieldErr['last_name'] = 'Please enter your last name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $fieldErr['email'] = 'Please enter a valid email address.';
    if (!$phoneOk) $fieldErr['phone'] = $phoneErr;
    if (strlen($pass) < MIN_PASSWORD) $fieldErr['password'] = 'Use at least ' . MIN_PASSWORD . ' characters.';
    elseif ($pass !== $confirm) $fieldErr['confirm'] = "Passwords don't match.";
    if (empty($_POST['agree'])) $errors[] = 'Please agree to the Terms, Community Rules and Privacy Notice to continue.';

    if (!$fieldErr && !$errors && q_val('SELECT 1 FROM users WHERE email = ?', [$email])) {
        $errors[] = 'This email is already registered. Please log in instead.';
    }
    // Light abuse protection: at most 5 sign-ups per session per hour.
    $_SESSION['signups'] = array_filter($_SESSION['signups'] ?? [], fn($t) => $t > time() - 3600);
    if (!$fieldErr && !$errors && count($_SESSION['signups']) >= 5) {
        $errors[] = 'Too many attempts. Please wait a few minutes and try again.';
    }

    if (!$fieldErr && !$errors) {
        q('INSERT INTO users (first_name, last_name, email, username, password_hash, phone, phone_country, status, terms_accepted_at) VALUES (?,?,?,?,?,?,?,?, NOW())',
            [mb_substr($first, 0, 80), mb_substr($last, 0, 80), $email, make_username($email), password_hash($pass, PASSWORD_DEFAULT),
             $phone, (string) $_POST['phone_country'], 'pending']);
        $user = q_one('SELECT * FROM users WHERE id = ?', [(int) db()->lastInsertId()]);
        $_SESSION['signups'][] = time();
        $_SESSION['confirm_email'] = $email;
        $_SESSION['confirm_sent'] = time();
        $_SESSION['confirm_failed'] = !send_confirmation_email($user);
        redirect('check_email.php');
    }
}

$err = fn(string $k) => isset($fieldErr[$k]) ? '<p class="field-error">' . e($fieldErr[$k]) . '</p>' : '';
$cls = fn(string $k) => isset($fieldErr[$k]) ? ' has-error' : '';

auth_head('Sign Up', 'register-body');
?>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-top auth-top-sm">
      <div class="auth-top-bar">
        <a href="<?= e(url('login.php')) ?>" class="icon-btn light" aria-label="Back to log in"><?= icon('arrow-left') ?></a>
        <?= lang_switch() ?>
      </div>
      <?php auth_brand(true); ?>
    </div>
    <div class="auth-panel">
      <h1>Complete Your Profile</h1>
      <p class="auth-sub">Create your PCEC member account and let us work together with God.</p>
      <?php foreach ($errors as $m): ?><div class="alert alert-error" role="alert"><?= e($m) ?></div><?php endforeach; ?>
      <form method="post" class="auth-form" novalidate>
        <?= csrf_field() ?>
        <div class="grid-2">
          <div><label class="field<?= $cls('first_name') ?>"><?= icon('user') ?><input name="first_name" placeholder="First Name" value="<?= old('first_name') ?>" autocomplete="given-name" maxlength="80" required></label><?= $err('first_name') ?></div>
          <div><label class="field<?= $cls('last_name') ?>"><?= icon('user') ?><input name="last_name" placeholder="Last Name" value="<?= old('last_name') ?>" autocomplete="family-name" maxlength="80" required></label><?= $err('last_name') ?></div>
        </div>
        <label class="field<?= $cls('email') ?>"><?= icon('mail') ?><input type="email" name="email" placeholder="Email Address" value="<?= old('email') ?>" autocomplete="email" inputmode="email" maxlength="160" required></label><?= $err('email') ?>
        <?= phone_field((string) ($_POST['phone_country'] ?? 'PH'), (string) ($_POST['phone'] ?? ''), $fieldErr['phone'] ?? '') ?>
        <label class="field<?= $cls('password') ?>"><?= icon('lock') ?><input type="password" name="password" placeholder="Password (min. <?= MIN_PASSWORD ?> characters)" autocomplete="new-password" minlength="<?= MIN_PASSWORD ?>" required><button type="button" class="pw-toggle" aria-label="Show password"><?= icon('eye-off') ?></button></label><?= $err('password') ?>
        <label class="field<?= $cls('confirm') ?>"><?= icon('lock') ?><input type="password" name="confirm" placeholder="Confirm Password" autocomplete="new-password" required><button type="button" class="pw-toggle" aria-label="Show password"><?= icon('eye-off') ?></button></label><?= $err('confirm') ?>
        <label class="check consent"><input type="checkbox" name="agree" value="1" <?= !empty($_POST['agree']) ? 'checked' : '' ?>><span></span>
          <em>I agree to the <a href="<?= e(url('terms.php')) ?>" class="link" target="_blank" rel="noopener">Terms of Service</a> and
          <a href="<?= e(url('terms.php#community-rules')) ?>" class="link" target="_blank" rel="noopener">Community Rules</a>, and I consent to PCEC
          processing my personal information, including my church membership (sensitive personal information), as described in the
          <a href="<?= e(url('privacy.php')) ?>" class="link" target="_blank" rel="noopener">Privacy Notice</a>. If I am under 18, my parent or guardian agrees too.</em></label>
        <button class="btn btn-gradient btn-lg btn-block"><?= icon('user-plus') ?> Create My Account</button>
      </form>
      <p class="auth-switch">Already a member? <a href="<?= e(url('login.php')) ?>">Sign In <?= icon('chevron-right') ?></a></p>
    </div>
  </div>
</div>
<?php auth_foot(); ?>
