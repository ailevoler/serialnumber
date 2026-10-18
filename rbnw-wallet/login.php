<?php
require_once __DIR__ . '/includes/functions.php';
require_guest();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#ffffff">
<title>Log In — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
<div class="app-shell">
  <div class="auth-screen">

    <div class="auth-logo">
      <img src="<?= asset_url('assets/img/rbnw-logo.png') ?>" alt="RBNW Wallet">
    </div>

    <main class="auth-card">
      <div class="auth-heading">
        <h2>Welcome back!</h2>
        <p class="subtitle">Log in with your mobile number and 6-digit MPIN.</p>
      </div>

      <div id="loginAlert" aria-live="polite"></div>

      <form id="loginForm" novalidate>
        <div class="field" id="mobileField">
          <label for="mobile_number">Mobile number</label>
          <div class="input-wrap">
            <span class="prefix">+63</span>
            <input type="tel" id="mobile_number" name="mobile_number" placeholder="917 123 4567"
                   inputmode="numeric" autocomplete="tel-national" required>
          </div>
        </div>

        <div class="field" id="mpinField">
          <div class="label">
            <label for="mpin" style="margin:0">MPIN</label>
            <button type="button" class="pin-toggle" id="togglePin"><i class="fa-solid fa-eye"></i> <span>Show</span></button>
          </div>
          <div class="pin-input" id="mpinBoxes">
            <input type="password" id="mpin" name="mpin" inputmode="numeric" pattern="[0-9]*"
                   maxlength="6" autocomplete="current-password" aria-label="6-digit MPIN" required>
            <div class="pin-boxes" aria-hidden="true">
              <span class="pin-box"></span><span class="pin-box"></span><span class="pin-box"></span>
              <span class="pin-box"></span><span class="pin-box"></span><span class="pin-box"></span>
            </div>
          </div>
        </div>

        <div class="row-between">
          <label class="checkbox-line" style="align-items:center">
            <input type="checkbox" id="remember" checked> Remember me
          </label>
          <a href="forgot-mpin.php">Forgot MPIN?</a>
        </div>

        <button type="submit" class="btn btn-primary" id="loginBtn">
          <span id="loginBtnText">Log In</span>
        </button>
      </form>

      <div class="divider-or">or</div>

      <button type="button" class="btn-biometric" id="biometricBtn">
        <span class="fp-chip"><i class="fa-solid fa-fingerprint"></i></span> Log in with Biometrics
      </button>

      <p class="footer-link">New to RBNW Wallet? <a href="register.php">Create an account</a></p>
    </main>

    <footer class="auth-footer">
      <i class="fa-solid fa-shield-halved"></i> Protected by Rural Bank of New Washington
    </footer>
  </div>
</div>

<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script>
(() => {
  const form     = document.getElementById('loginForm');
  const mobileEl = document.getElementById('mobile_number');
  const alertBox = document.getElementById('loginAlert');
  const btn      = document.getElementById('loginBtn');
  const btnText  = document.getElementById('loginBtnText');
  const REMEMBER_KEY = 'rbnw_last_mobile';

  function showError(msg, fieldId) {
    alertBox.innerHTML = RBNW.alertHtml(msg, 'error');
    document.querySelectorAll('.field').forEach(f => f.classList.remove('has-error'));
    if (fieldId) {
      const f = document.getElementById(fieldId);
      f.classList.add('has-error');
      f.classList.remove('shake'); void f.offsetWidth; f.classList.add('shake');
    }
  }

  try {
    const saved = localStorage.getItem(REMEMBER_KEY);
    if (saved) mobileEl.value = saved;
  } catch (_) { /* storage unavailable */ }
  RBNW.bindMobileInput(mobileEl);

  const pin = RBNW.mountPinInput(document.getElementById('mpinBoxes'), (v) => {
    document.getElementById('mpinField').classList.remove('has-error');
    if (v.length === 6 && RBNW.localMobile(mobileEl.value).length === 10) form.requestSubmit();
  });

  mobileEl.addEventListener('input', () => {
    document.getElementById('mobileField').classList.remove('has-error');
    if (RBNW.localMobile(mobileEl.value).length === 10 && !pin.input.value) pin.input.focus();
  });

  document.getElementById('togglePin').addEventListener('click', (e) => {
    const reveal = pin.toggleReveal();
    e.currentTarget.querySelector('i').className = reveal ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
    e.currentTarget.querySelector('span').textContent = reveal ? 'Hide' : 'Show';
  });

  document.getElementById('biometricBtn').addEventListener('click', () => {
    RBNW.toast('Biometric login requires the RBNW mobile app.', 'info');
  });

  let busy = false;
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (busy) return;
    alertBox.innerHTML = '';

    const mobile = RBNW.mobileFromField(mobileEl);
    const mpin = pin.input.value;

    if (!/^09\d{9}$/.test(mobile)) {
      showError('Please enter a valid mobile number (e.g. 917 123 4567).', 'mobileField');
      mobileEl.focus();
      return;
    }
    if (!/^\d{6}$/.test(mpin)) {
      showError('Please enter your 6-digit MPIN.', 'mpinField');
      pin.input.focus();
      return;
    }

    busy = true;
    btn.disabled = true;
    btnText.innerHTML = '<span class="spinner" style="display:inline-block;vertical-align:middle;"></span>';

    const res = await RBNW.api('auth.php?action=login', 'POST', { mobile_number: mobile, mpin });

    if (res.success) {
      try {
        if (document.getElementById('remember').checked) localStorage.setItem(REMEMBER_KEY, mobile);
        else localStorage.removeItem(REMEMBER_KEY);
      } catch (_) { /* storage unavailable */ }
      btnText.innerHTML = '<i class="fa-solid fa-check"></i> Success';
      window.location.href = 'dashboard.php';
      return;
    }

    busy = false;
    btn.disabled = false;
    btnText.textContent = 'Log In';
    pin.clear();
    const msg = res.message || 'Login failed. Please try again.';
    showError(msg, /mobile|account found/i.test(msg) ? 'mobileField' : 'mpinField');
  });
})();
</script>
</body>
</html>
