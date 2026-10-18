<?php
require_once __DIR__ . '/includes/functions.php';
require_guest();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Sign Up — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
<?php include __DIR__ . '/includes/pwa-head.php'; ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
<div class="app-shell">
  <div class="auth-screen">

    <div class="auth-top">
      <a href="login.php" class="auth-back" aria-label="Back to log in"><i class="fa-solid fa-arrow-left"></i></a>
    </div>
    <div class="auth-logo small">
      <img src="<?= asset_url('assets/img/rbnw-logo.png') ?>" alt="RBNW Wallet">
    </div>

    <main class="auth-card">
      <div class="auth-heading">
        <h2>Create your account</h2>
        <p class="subtitle">It only takes a minute. Your wallet is ready right after.</p>
      </div>

      <div id="regAlert" aria-live="polite"></div>

      <form id="registerForm" novalidate>
        <div class="auth-section-title"><span class="step-num">1</span> Your details</div>

        <div class="field" id="nameField">
          <label for="full_name">Full name</label>
          <div class="input-wrap">
            <i class="icon fa-solid fa-user"></i>
            <input type="text" id="full_name" name="full_name" placeholder="Juan Dela Cruz"
                   autocomplete="name" autocapitalize="words" maxlength="200" required>
          </div>
          <div class="field-hint" data-default="As it appears on your valid ID.">As it appears on your valid ID.</div>
        </div>

        <div class="field" id="mobileField">
          <label for="mobile_number">Mobile number</label>
          <div class="input-wrap">
            <span class="prefix">+63</span>
            <input type="tel" id="mobile_number" name="mobile_number" placeholder="917 123 4567"
                   inputmode="numeric" autocomplete="tel-national" required>
          </div>
          <div class="field-hint" data-default="You'll use this number to log in.">You'll use this number to log in.</div>
        </div>

        <div class="field" id="emailField">
          <label for="email">Email address <span class="opt">Optional</span></label>
          <div class="input-wrap">
            <i class="icon fa-solid fa-envelope"></i>
            <input type="email" id="email" name="email" placeholder="juan.delacruz@email.com" autocomplete="email">
          </div>
          <div class="field-hint" data-default=""></div>
        </div>

        <div class="auth-section-title" style="margin-top:22px"><span class="step-num">2</span> Secure your wallet</div>

        <div class="field" id="mpinField">
          <div class="label">
            <label for="mpin" style="margin:0">Create 6-digit MPIN</label>
            <button type="button" class="pin-toggle" data-target="mpinBoxes"><i class="fa-solid fa-eye"></i> <span>Show</span></button>
          </div>
          <div class="pin-input" id="mpinBoxes">
            <input type="password" id="mpin" name="mpin" inputmode="numeric" pattern="[0-9]*"
                   maxlength="6" autocomplete="new-password" aria-label="Create 6-digit MPIN" required>
            <div class="pin-boxes" aria-hidden="true">
              <span class="pin-box"></span><span class="pin-box"></span><span class="pin-box"></span>
              <span class="pin-box"></span><span class="pin-box"></span><span class="pin-box"></span>
            </div>
          </div>
          <div class="field-hint" data-default="Avoid easy PINs like 123456 or your birthday.">Avoid easy PINs like 123456 or your birthday.</div>
        </div>

        <div class="field" id="mpinConfirmField">
          <div class="label">
            <label for="mpin_confirm" style="margin:0">Confirm MPIN</label>
            <button type="button" class="pin-toggle" data-target="mpinConfirmBoxes"><i class="fa-solid fa-eye"></i> <span>Show</span></button>
          </div>
          <div class="pin-input" id="mpinConfirmBoxes">
            <input type="password" id="mpin_confirm" name="mpin_confirm" inputmode="numeric" pattern="[0-9]*"
                   maxlength="6" autocomplete="new-password" aria-label="Confirm 6-digit MPIN" required>
            <div class="pin-boxes" aria-hidden="true">
              <span class="pin-box"></span><span class="pin-box"></span><span class="pin-box"></span>
              <span class="pin-box"></span><span class="pin-box"></span><span class="pin-box"></span>
            </div>
          </div>
          <div class="field-hint" data-default=""></div>
        </div>

        <div class="field" id="termsField" style="margin:6px 0 22px">
          <label class="checkbox-line" style="display:flex;font-weight:500">
            <input type="checkbox" id="terms" required>
            <span>I agree to the <a href="#">Terms &amp; Conditions</a> and <a href="#">Privacy Policy</a> of RBNW Wallet.</span>
          </label>
        </div>

        <button type="submit" class="btn btn-primary" id="regBtn">
          <span id="regBtnText">Create Account</span>
        </button>
      </form>

      <p class="footer-link">Already have an account? <a href="login.php">Log in</a></p>
    </main>

    <footer class="auth-footer">
      <i class="fa-solid fa-lock"></i> Your MPIN is encrypted and never stored in plain text
    </footer>
  </div>
</div>

<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script>
(() => {
  const form     = document.getElementById('registerForm');
  const alertBox = document.getElementById('regAlert');
  const btn      = document.getElementById('regBtn');
  const btnText  = document.getElementById('regBtnText');
  const nameEl   = document.getElementById('full_name');
  const mobileEl = document.getElementById('mobile_number');
  const emailEl  = document.getElementById('email');

  const WEAK_PINS = ['000000', '111111', '123456', '654321', '121212', '112233', '123123'];

  function setField(id, state, msg) {
    const f = document.getElementById(id);
    const hint = f.querySelector('.field-hint');
    f.classList.toggle('has-error', state === 'error');
    f.classList.toggle('is-valid', state === 'valid');
    if (hint) {
      const icon = state === 'error' ? '<i class="fa-solid fa-circle-exclamation"></i> '
                 : state === 'valid' ? '<i class="fa-solid fa-circle-check"></i> ' : '';
      hint.innerHTML = msg ? icon + RBNW.escapeHtml(msg) : RBNW.escapeHtml(hint.dataset.default || '');
    }
  }

  RBNW.bindMobileInput(mobileEl);
  nameEl.addEventListener('input', () => setField('nameField', null));
  mobileEl.addEventListener('input', () => {
    const ok = RBNW.localMobile(mobileEl.value).length === 10 && RBNW.localMobile(mobileEl.value).startsWith('9');
    setField('mobileField', ok ? 'valid' : null, ok ? 'Looks good.' : '');
  });
  emailEl.addEventListener('input', () => setField('emailField', null));

  const pin = RBNW.mountPinInput(document.getElementById('mpinBoxes'), (v) => {
    setField('mpinField', null);
    if (v.length === 6) {
      if (WEAK_PINS.includes(v)) setField('mpinField', 'error', 'That MPIN is too easy to guess. Try another.');
      else { setField('mpinField', 'valid', 'MPIN set.'); pinConfirm.input.focus(); }
    }
    checkMatch();
  });
  const pinConfirm = RBNW.mountPinInput(document.getElementById('mpinConfirmBoxes'), () => checkMatch());

  function checkMatch() {
    const a = pin.input.value, b = pinConfirm.input.value;
    if (b.length < 6) { setField('mpinConfirmField', null); return; }
    if (a === b) setField('mpinConfirmField', 'valid', 'MPINs match.');
    else setField('mpinConfirmField', 'error', 'MPINs do not match.');
  }

  document.querySelectorAll('.pin-toggle[data-target]').forEach(t => {
    t.addEventListener('click', () => {
      const ctl = t.dataset.target === 'mpinBoxes' ? pin : pinConfirm;
      const reveal = ctl.toggleReveal();
      t.querySelector('i').className = reveal ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
      t.querySelector('span').textContent = reveal ? 'Hide' : 'Show';
    });
  });

  function fail(msg, fieldId, focusEl) {
    alertBox.innerHTML = RBNW.alertHtml(msg, 'error');
    if (fieldId) {
      setField(fieldId, 'error', msg);
      const f = document.getElementById(fieldId);
      f.classList.remove('shake'); void f.offsetWidth; f.classList.add('shake');
    }
    if (focusEl) focusEl.focus();
    alertBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  let busy = false;
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (busy) return;
    alertBox.innerHTML = '';

    const payload = {
      full_name: nameEl.value.trim().replace(/\s+/g, ' '),
      mobile_number: RBNW.mobileFromField(mobileEl),
      email: emailEl.value.trim(),
      mpin: pin.input.value,
      mpin_confirm: pinConfirm.input.value,
    };

    if (payload.full_name.length < 2) return fail('Please enter your full name.', 'nameField', nameEl);
    if (!/^09\d{9}$/.test(payload.mobile_number)) return fail('Please enter a valid mobile number (e.g. 917 123 4567).', 'mobileField', mobileEl);
    if (payload.email && !emailEl.checkValidity()) return fail('Please enter a valid email address, or leave it blank.', 'emailField', emailEl);
    if (!/^\d{6}$/.test(payload.mpin)) return fail('MPIN must be exactly 6 digits.', 'mpinField', pin.input);
    if (WEAK_PINS.includes(payload.mpin)) return fail('That MPIN is too easy to guess. Try another.', 'mpinField', pin.input);
    if (payload.mpin !== payload.mpin_confirm) return fail('MPIN confirmation does not match.', 'mpinConfirmField', pinConfirm.input);
    if (!document.getElementById('terms').checked) return fail('Please agree to the Terms & Conditions to continue.', 'termsField');

    busy = true;
    btn.disabled = true;
    btnText.innerHTML = '<span class="spinner" style="display:inline-block;vertical-align:middle;"></span>';

    const res = await RBNW.api('auth.php?action=register', 'POST', payload);

    if (res.success) {
      btnText.innerHTML = '<i class="fa-solid fa-check"></i> Account created';
      alertBox.innerHTML = RBNW.alertHtml(`Welcome! Your wallet ${res.wallet_code || ''} is ready. Redirecting…`, 'success');
      try { localStorage.setItem('rbnw_last_mobile', payload.mobile_number); } catch (_) { /* storage unavailable */ }
      setTimeout(() => window.location.href = 'dashboard.php', 900);
      return;
    }

    busy = false;
    btn.disabled = false;
    btnText.textContent = 'Create Account';
    const msg = res.message || 'Registration failed. Please try again.';
    const field = /mobile/i.test(msg) ? 'mobileField' : /email/i.test(msg) ? 'emailField'
                : /confirm/i.test(msg) ? 'mpinConfirmField' : /mpin/i.test(msg) ? 'mpinField'
                : /name/i.test(msg) ? 'nameField' : null;
    fail(msg, field);
  });
})();
</script>
</body>
</html>
