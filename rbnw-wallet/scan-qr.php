<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$user = get_user(current_user_id());

$myName    = trim($user['first_name'] . ' ' . $user['last_name']);
$qrPayload = json_encode([
    'type'        => 'rbnw_receive',
    'mobile'      => $user['mobile_number'],
    'name'        => $myName,
    'wallet_code' => $user['wallet_code'],
]);
$qrData = base64_encode($qrPayload);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, maximum-scale=1.0">
<?php $pwaThemeColor = '#000000'; include __DIR__ . '/includes/pwa-head.php'; ?>
<title>Scan QR — <?= APP_NAME ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; background: #000; overflow: hidden; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #fff; }

:root {
  --rbnw-blue: #0f4c92;
  --rbnw-blue-light: #1a63b8;
  --rbnw-green: #4caf50;
  --text-muted-d: rgba(255,255,255,.6);
}

.scan-page { position: fixed; inset: 0; display: flex; flex-direction: column; }

#qr-reader { flex: 1; width: 100%; position: relative; background: #000; overflow: hidden; }
#qr-reader video { width: 100% !important; height: 100% !important; object-fit: cover !important; position: absolute !important; top: 0 !important; left: 0 !important; }
#qr-reader img, #qr-reader canvas { display: none !important; }
#qr-reader__scan_region { display: none !important; }
#qr-reader__dashboard { display: none !important; }

.scan-topbar {
  position: absolute; top: 0; left: 0; right: 0; z-index: 20;
  display: flex; align-items: center; gap: 12px;
  padding: max(env(safe-area-inset-top,16px),16px) 20px 16px;
  background: linear-gradient(to bottom, rgba(0,0,0,.75) 0%, transparent 100%);
  pointer-events: none;
}
.topbar-btn {
  width: 42px; height: 42px; border-radius: 13px;
  background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.25);
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; color: #fff; text-decoration: none;
  pointer-events: all; flex-shrink: 0;
  backdrop-filter: blur(8px); -webkit-tap-highlight-color: transparent;
}
.scan-title { font-size: 18px; font-weight: 700; text-shadow: 0 1px 4px rgba(0,0,0,.6); }

.vf-overlay { position: absolute; inset: 0; z-index: 10; display: flex; flex-direction: column; align-items: center; justify-content: center; pointer-events: none; }
.vf-shadow-top, .vf-shadow-bottom { width: 100%; background: rgba(0,0,0,.5); }
.vf-shadow-top { flex: 1.2; } .vf-shadow-bottom { flex: 1.8; }
.vf-middle-row { display: flex; width: 100%; align-items: stretch; }
.vf-shadow-side { flex: 1; background: rgba(0,0,0,.5); }
.vf-frame { width: min(260px, 70vw); height: min(260px, 70vw); flex-shrink: 0; position: relative; }
.vf-frame::before, .vf-frame::after, .vf-bl, .vf-br { content: ''; position: absolute; width: 36px; height: 36px; border-color: var(--rbnw-green); border-style: solid; border-radius: 2px; }
.vf-frame::before { top: 0; left: 0; border-width: 3px 0 0 3px; }
.vf-frame::after  { top: 0; right: 0; border-width: 3px 3px 0 0; }
.vf-bl { bottom: 0; left: 0; border-width: 0 0 3px 3px; }
.vf-br { bottom: 0; right: 0; border-width: 0 3px 3px 0; }
.vf-line {
  position: absolute; left: 4px; right: 4px; height: 2px;
  background: linear-gradient(90deg, transparent, var(--rbnw-green), transparent);
  box-shadow: 0 0 10px var(--rbnw-green), 0 0 20px rgba(76,175,80,.4);
  animation: scanLine 2s ease-in-out infinite;
}
@keyframes scanLine { 0%{top:6px;opacity:1} 45%{top:calc(100% - 6px);opacity:1} 50%{opacity:0} 55%{top:6px;opacity:0} 60%{opacity:1} 100%{top:calc(100% - 6px);opacity:1} }

.scan-bottom {
  position: absolute; bottom: 0; left: 0; right: 0; z-index: 20;
  background: linear-gradient(to top, rgba(0,0,0,.9) 70%, transparent 100%);
  padding: 20px 24px max(env(safe-area-inset-bottom,20px),20px);
  display: flex; flex-direction: column; align-items: center; gap: 14px;
}
.scan-hint { font-size: 13px; color: rgba(255,255,255,.7); text-align: center; line-height: 1.5; }
.scan-action-row { display: flex; gap: 10px; width: 100%; max-width: 360px; }
.scan-action-btn {
  flex: 1; display: flex; flex-direction: column; align-items: center; gap: 6px;
  background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.2);
  border-radius: 14px; padding: 13px 8px; cursor: pointer;
  -webkit-tap-highlight-color: transparent; backdrop-filter: blur(8px);
  text-decoration: none; color: #fff; font: inherit;
}
.scan-action-btn:active { background: rgba(255,255,255,.22); }
.sab-icon { font-size: 20px; }
.sab-label { font-size: 11px; font-weight: 600; color: rgba(255,255,255,.8); }

.scan-msg {
  position: absolute; bottom: 180px; left: 50%; transform: translateX(-50%);
  z-index: 25; background: rgba(10,20,40,.92); border: 1px solid rgba(255,255,255,.15);
  border-radius: 12px; padding: 12px 18px; font-size: 13px; font-weight: 600; text-align: center;
  backdrop-filter: blur(12px); display: none; max-width: calc(100% - 40px);
}
.scan-msg.show { display: block; }
.scan-msg.error { border-color: rgba(224,69,63,.4); color: #ff7d76; }
.scan-msg.info  { border-color: rgba(26,99,184,.4); color: #6fb3ff; }

/* ── Result bottom sheet ── */
.result-sheet {
  position: absolute; bottom: 0; left: 0; right: 0; z-index: 35;
  background: #16233d; border-radius: 24px 24px 0 0; border-top: 1px solid rgba(255,255,255,.1);
  padding: 20px 24px max(env(safe-area-inset-bottom,24px),24px);
  transform: translateY(100%); transition: transform .4s cubic-bezier(.4,0,.2,1);
}
.result-sheet.show { transform: translateY(0); }
.sheet-handle { width: 40px; height: 4px; border-radius: 2px; background: rgba(255,255,255,.2); margin: 0 auto 20px; }
.result-user { display: flex; align-items: center; gap: 14px; background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.1); border-radius: 14px; padding: 16px; margin-bottom: 16px; }
.result-avatar { width: 54px; height: 54px; border-radius: 50%; background: linear-gradient(135deg, var(--rbnw-blue-light), var(--rbnw-blue)); display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 800; color: #fff; flex-shrink: 0; }
.result-name { font-size: 17px; font-weight: 800; }
.result-mobile { font-size: 13px; color: var(--text-muted-d); margin-top: 2px; }
.result-verified { display: inline-flex; align-items: center; gap: 4px; margin-top: 4px; padding: 2px 8px; border-radius: 10px; background: rgba(76,175,80,.15); color: #7fc242; font-size: 10px; font-weight: 700; }
.result-btns { display: flex; gap: 10px; }
.btn-send { flex: 1; padding: 15px; border-radius: 14px; background: linear-gradient(135deg, var(--rbnw-blue-light), var(--rbnw-blue)); color: #fff; font-size: 15px; font-weight: 800; border: none; cursor: pointer; -webkit-tap-highlight-color: transparent; }
.btn-send:active { transform: scale(.97); }
.btn-cancel { padding: 15px 18px; border-radius: 14px; background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.15); color: #fff; font-size: 14px; font-weight: 700; cursor: pointer; -webkit-tap-highlight-color: transparent; }

.loading-ov { position: absolute; inset: 0; z-index: 30; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,.5); backdrop-filter: blur(4px); }
.loading-ov.show { display: flex; }
.loading-spin { width: 46px; height: 46px; border: 3px solid rgba(76,175,80,.2); border-top-color: var(--rbnw-green); border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

#uploadInput { display: none; }

.toast {
  position: fixed; top: 16px; left: 50%; transform: translateX(-50%) translateY(-120px);
  width: calc(100% - 32px); max-width: 400px;
  background: #16233d; border: 1px solid rgba(255,255,255,.12); border-radius: 14px; padding: 14px 18px;
  display: flex; align-items: center; gap: 12px; box-shadow: 0 8px 32px rgba(0,0,0,.5);
  transition: transform .4s cubic-bezier(.175,.885,.32,1.275); z-index: 9999; pointer-events: none;
}
.toast.show { transform: translateX(-50%) translateY(0); }
.toast.success { border-color: rgba(76,175,80,.35); }
.toast.error   { border-color: rgba(224,69,63,.35); }
.toast-icon { font-size: 18px; flex-shrink: 0; }
.toast-text { font-size: 13px; font-weight: 500; line-height: 1.4; }

/* ── My QR panel ── */
.myqr-panel {
  position: absolute; inset: 0; z-index: 40;
  background: linear-gradient(160deg, #0b3d7a 0%, #0f4c92 55%, #14396b 100%);
  display: none; flex-direction: column; align-items: center;
  padding: max(env(safe-area-inset-top,20px),20px) 24px max(env(safe-area-inset-bottom,24px),24px);
  overflow-y: auto;
}
.myqr-panel.show { display: flex; }
.myqr-topbar { display: flex; align-items: center; gap: 12px; width: 100%; margin-bottom: 22px; }
.myqr-title { font-size: 18px; font-weight: 700; }
.myqr-card {
  background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.12);
  border-radius: 22px; padding: 26px 20px; width: 100%; max-width: 340px;
  display: flex; flex-direction: column; align-items: center; gap: 14px; margin-top: 12px;
}
.myqr-name { font-size: 19px; font-weight: 800; text-align: center; }
.myqr-mobile { font-size: 13.5px; color: var(--text-muted-d); }
.myqr-wrap { background: #fff; border-radius: 16px; padding: 16px; box-shadow: 0 8px 32px rgba(0,0,0,.4); }
.myqr-note { font-size: 12px; color: var(--text-muted-d); text-align: center; line-height: 1.5; }
.myqr-share-row { display: flex; gap: 10px; width: 100%; max-width: 340px; margin-top: 18px; }
.myqr-share-btn {
  flex: 1; display: flex; align-items: center; justify-content: center; gap: 8px;
  padding: 13px; border-radius: 12px; background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.18);
  font-size: 13px; font-weight: 700; color: #fff; cursor: pointer; -webkit-tap-highlight-color: transparent;
}
</style>
</head>
<body>

<div class="toast" id="toast"><span class="toast-icon">ℹ️</span><span class="toast-text"></span></div>

<div class="scan-page">

  <div id="qr-reader"></div>

  <div class="scan-topbar">
    <a href="dashboard.php" class="topbar-btn"><i class="fa-solid fa-arrow-left"></i></a>
    <div class="scan-title">Scan QR Code</div>
  </div>

  <div class="vf-overlay">
    <div class="vf-shadow-top"></div>
    <div class="vf-middle-row">
      <div class="vf-shadow-side"></div>
      <div class="vf-frame"><span class="vf-bl"></span><span class="vf-br"></span><div class="vf-line"></div></div>
      <div class="vf-shadow-side"></div>
    </div>
    <div class="vf-shadow-bottom"></div>
  </div>

  <div class="scan-msg" id="scanMsg"></div>

  <div class="scan-bottom">
    <div class="scan-hint">Point camera at another RBNW Wallet user's QR code</div>
    <div class="scan-action-row">
      <button class="scan-action-btn" type="button" onclick="restartScan()">
        <div class="sab-icon"><i class="fa-solid fa-rotate-right"></i></div>
        <div class="sab-label">Restart</div>
      </button>
      <label class="scan-action-btn" for="uploadInput">
        <div class="sab-icon"><i class="fa-solid fa-image"></i></div>
        <div class="sab-label">Upload QR</div>
      </label>
      <button class="scan-action-btn" type="button" onclick="showMyQr()">
        <div class="sab-icon"><i class="fa-solid fa-qrcode"></i></div>
        <div class="sab-label">My QR</div>
      </button>
    </div>
  </div>

  <div class="loading-ov" id="loadingOv"><div class="loading-spin"></div></div>

  <!-- Result bottom sheet -->
  <div class="result-sheet" id="resultSheet">
    <div class="sheet-handle"></div>
    <div style="font-size:15px;font-weight:700;margin-bottom:14px;">✅ RBNW Wallet User Found!</div>
    <div class="result-user">
      <div class="result-avatar" id="rAvatar">?</div>
      <div>
        <div class="result-name" id="rName">—</div>
        <div class="result-mobile" id="rMobile">—</div>
        <div class="result-verified" id="rVerified" style="display:none;"><i class="fa-solid fa-circle-check"></i> Verified</div>
      </div>
    </div>
    <div class="result-btns">
      <button class="btn-cancel" type="button" onclick="closeResult()">Cancel</button>
      <button class="btn-send" type="button" onclick="goToSend()"><i class="fa-solid fa-paper-plane"></i> Send Money</button>
    </div>
  </div>

  <!-- My QR panel -->
  <div class="myqr-panel" id="myQrPanel">
    <div class="myqr-topbar">
      <button class="topbar-btn" type="button" onclick="hideMyQr()"><i class="fa-solid fa-arrow-left"></i></button>
      <div class="myqr-title">My QR Code</div>
    </div>
    <div class="myqr-card">
      <div class="myqr-name"><?= htmlspecialchars($myName) ?></div>
      <div class="myqr-mobile"><?= htmlspecialchars($user['mobile_number']) ?></div>
      <div class="myqr-wrap"><div id="myQR"></div></div>
      <div class="myqr-note">Let another RBNW Wallet user scan this<br>to send you money instantly.</div>
    </div>
    <div class="myqr-share-row">
      <button class="myqr-share-btn" type="button" onclick="copyMobile()"><i class="fa-regular fa-copy"></i> Copy Number</button>
      <button class="myqr-share-btn" type="button" onclick="shareQr()"><i class="fa-solid fa-share-nodes"></i> Share</button>
    </div>
  </div>

</div>

<input type="file" id="uploadInput" accept="image/*">

<script>
var html5Qr = null;
var scannedMobile = '';
var scannedName = '';

/* ── Parse RBNW QR payload ── */
function parseQR(raw) {
  if (!raw) return null;
  try {
    var d = JSON.parse(atob(raw.trim()));
    if (d && d.type === 'rbnw_receive' && d.mobile) return { kind: 'receive', mobile: d.mobile, name: d.name || '' };
    if (d && d.type === 'rbnw_request' && d.ref) return { kind: 'request', ref: d.ref };
  } catch (e) {}
  try {
    var p = JSON.parse(raw);
    if (p && p.mobile) return { kind: 'receive', mobile: p.mobile, name: p.name || '' };
  } catch (e) {}
  var m = raw.match(/(09|\+639)[0-9]{9}/);
  if (m) return { kind: 'receive', mobile: m[0].replace(/^\+63/, '0'), name: '' };
  return null;
}

/* ── Start camera ── */
function startScan() {
  if (html5Qr) { try { html5Qr.stop().catch(function(){}); } catch (e) {} html5Qr = null; }

  html5Qr = new Html5Qrcode('qr-reader', { verbose: false });

  var config = {
    fps: 15,
    qrbox: function (w, h) { var s = Math.floor(Math.min(w, h) * 0.70); return { width: s, height: s }; },
    aspectRatio: window.innerHeight / window.innerWidth,
    disableFlip: false,
    rememberLastUsedCamera: true,
    experimentalFeatures: { useBarCodeDetectorIfSupported: true },
  };

  html5Qr.start({ facingMode: 'environment' }, config, onQRDetected, function () {})
    .then(function () {
      showMsg('Scanning... point at QR code', 'info');
      setTimeout(hideMsg, 2000);
    })
    .catch(function (err) {
      var msg = String(err);
      if (msg.includes('NotAllowed') || msg.includes('Permission')) {
        msg = 'Camera permission denied. Tap the camera icon in your browser\'s address bar to allow access, then restart.';
      } else if (msg.includes('NotFound') || msg.includes('no devices')) {
        msg = 'No camera found on this device.';
      } else if (msg.includes('NotReadable')) {
        msg = 'Camera is busy. Close other apps using it and try again.';
      }
      showMsg(msg, 'error');
    });
}

function onQRDetected(rawText) {
  if (html5Qr) { try { html5Qr.pause(true); } catch (e) {} }

  var result = parseQR(rawText);
  if (!result) {
    showToast('QR code found but not an RBNW Wallet QR.', 'error');
    setTimeout(function () { if (html5Qr) { try { html5Qr.resume(); } catch (e) {} } }, 2000);
    return;
  }

  if (result.kind === 'request') {
    window.location.href = 'request-money.php?ref=' + encodeURIComponent(result.ref);
    return;
  }

  lookupUser(result.mobile);
}

async function lookupUser(mobile) {
  showLoading(true);
  try {
    var res = await fetch('api/send-money.php?action=lookup&mobile=' + encodeURIComponent(mobile), { credentials: 'same-origin' });
    var data = await res.json();
    showLoading(false);

    if (data.found) {
      scannedMobile = data.mobile_number;
      scannedName = data.name;
      document.getElementById('rAvatar').textContent = data.initials || data.name.charAt(0).toUpperCase();
      document.getElementById('rName').textContent = data.name;
      document.getElementById('rMobile').textContent = data.mobile_number;
      document.getElementById('rVerified').style.display = data.verified ? 'inline-flex' : 'none';
      document.getElementById('resultSheet').classList.add('show');
    } else {
      showToast(data.message || 'Mobile number not registered.', 'error');
      setTimeout(function () { if (html5Qr) { try { html5Qr.resume(); } catch (e) {} } }, 2500);
    }
  } catch (e) {
    showLoading(false);
    showToast('Network error. Could not reach server.', 'error');
    setTimeout(function () { if (html5Qr) { try { html5Qr.resume(); } catch (e) {} } }, 2500);
  }
}

function goToSend() {
  window.location.href = 'send-money.php?mobile=' + encodeURIComponent(scannedMobile) + '&name=' + encodeURIComponent(scannedName);
}

function closeResult() {
  document.getElementById('resultSheet').classList.remove('show');
  scannedMobile = ''; scannedName = '';
  if (html5Qr) { try { html5Qr.resume(); } catch (e) {} }
}

function restartScan() {
  document.getElementById('resultSheet').classList.remove('show');
  hideMsg();
  if (html5Qr) {
    html5Qr.stop().then(function () { html5Qr.clear(); html5Qr = null; startScan(); }).catch(function () { startScan(); });
  } else { startScan(); }
}

/* ── Upload QR from file ── */
document.getElementById('uploadInput').addEventListener('change', function (e) {
  var file = e.target.files[0];
  if (!file) return;
  e.target.value = '';

  if (html5Qr) { try { html5Qr.pause(true); } catch (e) {} }
  showLoading(true);

  var tempId = 'temp-scan';
  var tempDiv = document.getElementById(tempId);
  if (!tempDiv) {
    tempDiv = document.createElement('div');
    tempDiv.id = tempId;
    tempDiv.style.display = 'none';
    document.body.appendChild(tempDiv);
  }

  var sc = new Html5Qrcode(tempId, { verbose: false });
  sc.scanFile(file, true)
    .then(function (text) {
      showLoading(false);
      sc.clear();
      var result = parseQR(text);
      if (result && result.kind === 'request') {
        window.location.href = 'request-money.php?ref=' + encodeURIComponent(result.ref);
      } else if (result && result.kind === 'receive') {
        lookupUser(result.mobile);
      } else {
        showToast('QR found but not an RBNW Wallet QR.', 'error', 5000);
        if (html5Qr) { try { html5Qr.resume(); } catch (e) {} }
      }
    })
    .catch(function () {
      showLoading(false);
      sc.clear();
      showToast('No QR code detected in image. Try a clearer photo.', 'error');
      if (html5Qr) { try { html5Qr.resume(); } catch (e) {} }
    });
});

/* ── My QR panel ── */
var myQrRendered = false;
function showMyQr() {
  if (html5Qr) { try { html5Qr.pause(true); } catch (e) {} }
  if (!myQrRendered) {
    new QRCode(document.getElementById('myQR'), {
      text: '<?= addslashes($qrData) ?>',
      width: 210, height: 210,
      colorDark: '#0f4c92', colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.M
    });
    myQrRendered = true;
  }
  document.getElementById('myQrPanel').classList.add('show');
}
function hideMyQr() {
  document.getElementById('myQrPanel').classList.remove('show');
  if (html5Qr) { try { html5Qr.resume(); } catch (e) {} }
}
function copyMobile() {
  navigator.clipboard.writeText('<?= addslashes($user['mobile_number']) ?>').then(function () {
    showToast('Mobile number copied!', 'success', 2000);
  }).catch(function () { showToast('<?= addslashes($user['mobile_number']) ?>', 'info', 3000); });
}
function shareQr() {
  var msg = 'Send me money via RBNW Wallet\nMobile: <?= addslashes($user['mobile_number']) ?>\nName: <?= addslashes($myName) ?>';
  if (navigator.share) {
    navigator.share({ title: 'RBNW Wallet', text: msg }).catch(function () {});
  } else {
    navigator.clipboard.writeText(msg).then(function () { showToast('Details copied to clipboard!', 'success', 2500); });
  }
}

/* ── Helpers ── */
function showMsg(msg, type) {
  var el = document.getElementById('scanMsg');
  el.textContent = msg; el.className = 'scan-msg show ' + (type || 'info');
}
function hideMsg() { document.getElementById('scanMsg').classList.remove('show'); }
function showLoading(v) { document.getElementById('loadingOv').classList.toggle('show', v); }

var toastTimer;
function showToast(msg, type, dur) {
  clearTimeout(toastTimer);
  type = type || 'info'; dur = dur || 3000;
  var t = document.getElementById('toast');
  t.className = 'toast ' + type;
  t.querySelector('.toast-icon').textContent = type === 'success' ? '✅' : type === 'error' ? '❌' : 'ℹ️';
  t.querySelector('.toast-text').textContent = msg;
  t.classList.add('show');
  toastTimer = setTimeout(function () { t.classList.remove('show'); }, dur);
}

window.addEventListener('load', startScan);
</script>
</body>
</html>
