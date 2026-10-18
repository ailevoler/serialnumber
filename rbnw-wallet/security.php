<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$user  = get_user(current_user_id());
$offer = isset($_GET['offer']); // shown right after an MPIN login
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<title>Security — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
<?php include __DIR__ . '/includes/pwa-head.php'; ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
.sec-body { padding: 16px 16px calc(110px + env(safe-area-inset-bottom)); }
.bio-hero { text-align: center; background: #fff; border-radius: var(--radius-lg); box-shadow: var(--shadow-card); padding: 24px 18px 18px; }
.bio-hero .ico { width: 76px; height: 76px; border-radius: 24px; margin: 0 auto 14px; background: var(--rbnw-blue-50); color: var(--rbnw-blue); display: flex; align-items: center; justify-content: center; font-size: 34px; }
.bio-hero .ico.on { background: var(--success-50); color: var(--success); }
.bio-hero h3 { margin: 0 0 6px; font-size: 18px; }
.bio-hero p { margin: 0 0 16px; font-size: 13.5px; color: var(--text-muted); line-height: 1.45; }
.sec-title { margin: 22px 2px 10px; font-size: 14px; font-weight: 700; }
.dev-row { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--border); }
.dev-row:last-child { border-bottom: none; }
.dev-row .ico { width: 38px; height: 38px; border-radius: 12px; background: var(--rbnw-blue-50); color: var(--rbnw-blue); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.dev-row .info { flex: 1; min-width: 0; font-size: 14px; font-weight: 600; }
.dev-row .info small { display: block; font-size: 12px; color: var(--text-muted); font-weight: 500; }
.dev-row .here { font-size: 10.5px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: var(--success-50); color: #1b7a3b; margin-left: 6px; }
.dev-row button { background: none; border: none; color: var(--danger); font-weight: 600; font-size: 13px; }
.tip { display: flex; gap: 12px; padding: 10px 0; font-size: 13px; color: var(--text-body); line-height: 1.45; }
.tip i { color: var(--rbnw-blue); margin-top: 3px; width: 16px; text-align: center; }
.sheet .pin-input { max-width: 320px; margin: 6px auto 0; }
</style>
</head>
<body>
<div class="app-shell">
  <div class="flow-header">
    <button class="back-btn" onclick="location.href='<?= $offer ? 'dashboard.php' : 'profile.php' ?>'" style="background:none;border:none;" aria-label="Back"><i class="fa-solid fa-arrow-left"></i></button>
    <h2><?= $offer ? 'Faster login' : 'Security' ?></h2>
  </div>
  <div class="sec-body">
    <div class="bio-hero">
      <div class="ico" id="bioIco"><i class="fa-solid fa-fingerprint"></i></div>
      <h3 id="bioTitle">Biometric login</h3>
      <p id="bioText">Log in with your fingerprint or face instead of typing your MPIN. Your fingerprint and face never leave your phone.</p>
      <button class="btn btn-primary" id="enableBtn"><i class="fa-solid fa-fingerprint"></i> Turn on for this phone</button>
      <?php if ($offer): ?>
        <button class="btn-danger-text" style="color:var(--text-muted);margin-top:8px" onclick="skipOffer()">Not now</button>
      <?php endif; ?>
      <div id="bioMsg" style="margin-top:12px"></div>
    </div>

    <div id="devWrap" class="hidden">
      <div class="sec-title">Phones with biometric login</div>
      <div class="card" id="devList" style="padding:4px 16px"></div>
    </div>

    <?php if (!$offer): ?>
    <div class="sec-title">Keeping your account safe</div>
    <div class="card">
      <div class="tip"><i class="fa-solid fa-lock"></i><span>Your MPIN is encrypted. RBNW will never ask for your MPIN or OTP by call, text, or chat.</span></div>
      <div class="tip"><i class="fa-solid fa-user-lock"></i><span>5 wrong MPIN attempts lock your account for <?= LOCKOUT_MINUTES ?> minutes.</span></div>
      <div class="tip"><i class="fa-solid fa-mobile-screen"></i><span>Remove biometric login from a phone you no longer use or have lost.</span></div>
    </div>
    <?php endif; ?>
  </div>
  <?php $activeNav = 'profile'; include __DIR__ . '/includes/bottom-nav.php'; ?>
</div>

<div class="sheet-backdrop" id="backdrop"></div>
<div class="sheet" id="pinSheet" role="dialog" aria-modal="true" aria-labelledby="pinTitle" style="text-align:center">
  <div class="grab"></div>
  <h3 id="pinTitle">Confirm with MPIN</h3>
  <p class="sub">Enter your 6-digit MPIN to turn on biometric login.</p>
  <div class="pin-input" id="pinBoxes">
    <input type="password" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="off" aria-label="6-digit MPIN">
    <div class="pin-boxes" aria-hidden="true">
      <span class="pin-box"></span><span class="pin-box"></span><span class="pin-box"></span>
      <span class="pin-box"></span><span class="pin-box"></span><span class="pin-box"></span>
    </div>
  </div>
  <div id="pinError" style="margin-top:14px"></div>
</div>

<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script>
const MOBILE = <?= json_encode($user['mobile_number']) ?>;
const OFFER = <?= json_encode($offer) ?>;
const esc = RBNW.escapeHtml, BIO = RBNW.biometrics;
const LS_KEY = 'rbnw_bio_cred_' + MOBILE;   // credential id enrolled on THIS phone
const lsGet = (k) => { try { return localStorage.getItem(k); } catch (_) { return null; } };
const lsSet = (k, v) => { try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (_) {} };

function deviceLabel() {
  const ua = navigator.userAgent;
  if (/iPhone/.test(ua)) return 'iPhone';
  if (/iPad/.test(ua)) return 'iPad';
  const m = ua.match(/Android[^;]*;\s*([^;)]+?)(?:\s+Build|\))/);
  if (m) return m[1].trim();
  if (/Android/.test(ua)) return 'Android phone';
  return 'This device';
}

function skipOffer() { lsSet('rbnw_bio_skip_' + MOBILE, '1'); location.href = 'dashboard.php'; }

async function load() {
  const res = await RBNW.api('webauthn.php?action=list');
  const creds = res.credentials || [];
  const mine = lsGet(LS_KEY);
  const hereOn = creds.some(c => c.credential_id === mine);
  if (mine && !hereOn) lsSet(LS_KEY, null); // removed from another phone

  document.getElementById('devWrap').classList.toggle('hidden', !creds.length);
  document.getElementById('devList').innerHTML = creds.map(c => `
    <div class="dev-row"><span class="ico"><i class="fa-solid fa-mobile-screen"></i></span>
      <span class="info">${esc(c.label)}${c.credential_id === mine ? '<span class="here">This phone</span>' : ''}
        <small>Added ${esc(c.created)}${c.last_used ? ' · last used ' + esc(c.last_used) : ''}</small></span>
      <button onclick="removeCred(${c.id}, '${esc(c.credential_id)}')">Remove</button></div>`).join('');

  const ico = document.getElementById('bioIco'), btn = document.getElementById('enableBtn');
  if (hereOn) {
    ico.classList.add('on');
    document.getElementById('bioTitle').textContent = 'Biometric login is on';
    document.getElementById('bioText').textContent = 'Use your fingerprint or face to log in on this phone. Your MPIN still works anytime.';
    btn.classList.add('hidden');
    if (OFFER) setTimeout(() => location.href = 'dashboard.php', 1200);
  } else if (!(await BIO.available())) {
    btn.disabled = true;
    document.getElementById('bioMsg').innerHTML = RBNW.alertHtml(
      'This phone or browser has no fingerprint / face unlock available. Set up a screen lock with fingerprint or face, or use Chrome / Safari.', 'info');
  }
}

async function removeCred(id, credId) {
  if (!confirm('Remove biometric login from this phone?')) return;
  await RBNW.api('webauthn.php?action=remove', 'POST', { id });
  if (credId === lsGet(LS_KEY)) lsSet(LS_KEY, null);
  RBNW.toast('Biometric login removed', 'info');
  load();
}

// ---------- enable ----------
const backdrop = document.getElementById('backdrop'), sheet = document.getElementById('pinSheet');
function openSheet() { sheet.classList.add('open'); backdrop.classList.add('open'); pin.clear(); document.getElementById('pinError').innerHTML = ''; setTimeout(() => pin.input.focus(), 60); }
function closeSheet() { sheet.classList.remove('open'); backdrop.classList.remove('open'); }
backdrop.onclick = closeSheet;
document.getElementById('enableBtn').onclick = openSheet;

let busy = false;
const pin = RBNW.mountPinInput(document.getElementById('pinBoxes'), async (v) => {
  if (v.length !== 6 || busy) return;
  busy = true;
  const err = document.getElementById('pinError');
  try {
    const opt = await RBNW.api('webauthn.php?action=register_options', 'POST', { mpin: v });
    if (!opt.success) { pin.clear(); err.innerHTML = RBNW.alertHtml(opt.message, 'error'); return; }
    closeSheet();
    const cred = await BIO.create(opt.publicKey);           // phone asks for fingerprint / face
    const res = await RBNW.api('webauthn.php?action=register_verify', 'POST', { ...cred, label: deviceLabel() });
    if (!res.success) { document.getElementById('bioMsg').innerHTML = RBNW.alertHtml(res.message, 'error'); return; }
    lsSet(LS_KEY, res.credential_id);
    RBNW.toast('Biometric login is on', 'success');
    load();
  } catch (e) {
    closeSheet();
    document.getElementById('bioMsg').innerHTML = RBNW.alertHtml(BIO.errorText(e), 'error');
  } finally { busy = false; }
});

load();
</script>
</body>
</html>
