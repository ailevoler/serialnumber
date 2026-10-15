<?php
require_once __DIR__ . '/includes/core.php';
loadCenterCheck();
$lc = getLoadCenter();
if (!$lc) loadCenterLogout();

$todayStats = DB::row(
    "SELECT COUNT(*) AS count, COALESCE(SUM(amount),0) AS total
     FROM tl_topups
     WHERE load_center_id=? AND channel='teller' AND status='paid' AND DATE(paid_at)=CURDATE()",
    [$lc['id']]
);

$recent = DB::all(
    "SELECT t.topup_code, t.amount, t.account_type, t.paid_at,
            COALESCE(d.full_name, u.full_name) AS account_name
     FROM tl_topups t
     LEFT JOIN tl_drivers d ON t.account_type='driver'    AND d.id = t.account_id
     LEFT JOIN tl_users   u ON t.account_type='passenger' AND u.id = t.account_id
     WHERE t.load_center_id=? AND t.channel='teller' AND t.status='paid'
     ORDER BY t.paid_at DESC LIMIT 10",
    [$lc['id']]
);

$csrfToken = Session::csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Dashboard – TriLink Load Center</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    :root{--navy:#1A2B5E;--green:#2E7D32;--orange:#E65100;}
    *{box-sizing:border-box;margin:0;padding:0;}
    body{background:#F5F6FA;font-family:'Inter',sans-serif;}
    .app{max-width:380px;margin:0 auto;min-height:100vh;background:#F5F6FA;padding-bottom:24px;}

    .lc-hero{background:linear-gradient(135deg,#0F1A3A,#1A2B5E);padding:16px 14px;color:#fff;}
    .lc-hero-top{display:flex;justify-content:space-between;align-items:center;}
    .lc-name{font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;}
    .lc-code{font-size:10.5px;color:rgba(255,255,255,0.6);}
    .lc-logout{color:rgba(255,255,255,0.7);font-size:16px;}

    .lc-stats{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;padding:12px 14px;}
    .lc-stat{background:#fff;border-radius:12px;padding:12px;text-align:center;border:1.5px solid #EAECF0;}
    .lc-stat-val{font-family:'Plus Jakarta Sans',sans-serif;font-size:20px;font-weight:800;color:#1A2B5E;}
    .lc-stat-lbl{font-size:10px;color:#9AA0A6;margin-top:1px;}

    .scan-section{margin:0 14px;padding:20px;background:#fff;border-radius:16px;border:1.5px solid #EAECF0;text-align:center;}
    .scan-btn{width:100%;padding:15px;border-radius:14px;border:none;background:linear-gradient(135deg,#1A2B5E,#2D4A8A);color:#fff;font-weight:800;font-size:15px;cursor:pointer;font-family:inherit;}
    #qrReader{width:100%;border-radius:12px;overflow:hidden;margin-top:12px;}
    .manual-row{display:flex;gap:8px;margin-top:14px;}
    .manual-inp{flex:1;padding:12px;border-radius:10px;border:1.5px solid #EAECF0;font-size:13px;text-align:center;letter-spacing:1px;font-weight:700;text-transform:uppercase;}
    .manual-btn{padding:12px 18px;border-radius:10px;border:none;background:#2E7D32;color:#fff;font-weight:800;font-size:13px;cursor:pointer;}

    .lookup-result{margin:14px 14px 0;padding:18px;background:#fff;border-radius:16px;border:2px solid #2E7D32;display:none;}
    .lr-amount{font-family:'Plus Jakarta Sans',sans-serif;font-size:30px;font-weight:800;color:#2E7D32;text-align:center;}
    .lr-code{text-align:center;font-size:13px;color:#9AA0A6;margin-top:2px;letter-spacing:1px;}
    .lr-driver{display:flex;align-items:center;gap:10px;margin-top:14px;padding:12px;background:#F5F6FA;border-radius:12px;}
    .lr-avatar{width:38px;height:38px;border-radius:50%;background:#1A2B5E;color:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;}
    .lr-name{font-size:13.5px;font-weight:800;color:#1A2B5E;}
    .lr-sub{font-size:11px;color:#9AA0A6;}
    .confirm-btn{width:100%;margin-top:14px;padding:14px;border-radius:12px;border:none;background:linear-gradient(135deg,#2E7D32,#43A047);color:#fff;font-weight:800;font-size:14px;cursor:pointer;font-family:inherit;}
    .lookup-error{margin:14px 14px 0;padding:12px 14px;background:#FFEBEE;color:#C62828;border-radius:10px;font-size:13px;font-weight:600;display:none;}

    .section-title{font-size:13px;font-weight:800;color:#3C4043;padding:16px 14px 8px;}
    .txn-list{padding:0 14px;display:flex;flex-direction:column;gap:8px;}
    .txn-card{display:flex;align-items:center;gap:10px;background:#fff;border-radius:12px;padding:11px 13px;border:1.5px solid #EAECF0;}
    .txn-icon{width:36px;height:36px;border-radius:10px;background:#E8F5E9;color:#2E7D32;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;}
    .txn-name{font-size:12.5px;font-weight:700;color:#202124;}
    .txn-time{font-size:10.5px;color:#9AA0A6;}
    .txn-amt{font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:800;color:#2E7D32;margin-left:auto;}
  </style>
</head>
<body>
<div class="app">
  <div class="lc-hero">
    <div class="lc-hero-top">
      <div>
        <div class="lc-name"><?= htmlspecialchars($lc['center_name']) ?></div>
        <div class="lc-code"><?= htmlspecialchars($lc['center_code']) ?></div>
      </div>
      <a href="<?= APP_URL ?>/logout.php" class="lc-logout" onclick="return confirm('Logout?')"><i class="fas fa-sign-out-alt"></i></a>
    </div>
  </div>

  <div class="lc-stats">
    <div class="lc-stat"><div class="lc-stat-val"><?= (int)($todayStats['count'] ?? 0) ?></div><div class="lc-stat-lbl">Processed Ngayon</div></div>
    <div class="lc-stat"><div class="lc-stat-val">₱<?= number_format((float)($todayStats['total'] ?? 0),0) ?></div><div class="lc-stat-lbl">Total Ngayon</div></div>
  </div>

  <div class="scan-section">
    <div style="font-size:13px;font-weight:700;color:#3C4043;margin-bottom:12px;">I-scan ang Top Up QR ng Driver o Pasahero</div>
    <button type="button" class="scan-btn" id="scanBtn" onclick="startScan()"><i class="fas fa-camera"></i> Simulan ang Pag-scan</button>
    <div id="qrReader" style="display:none;"></div>
    <div class="manual-row">
      <input type="text" id="manualCode" class="manual-inp" placeholder="Top Up Code (TU...)">
      <button type="button" class="manual-btn" onclick="lookupCode()">Hanapin</button>
    </div>
  </div>

  <div class="lookup-error" id="lookupError"></div>

  <div class="lookup-result" id="lookupResult">
    <div class="lr-amount" id="lrAmount">₱0.00</div>
    <div class="lr-code" id="lrCode">#—</div>
    <div class="lr-driver">
      <div class="lr-avatar" id="lrAvatar"><i class="fas fa-motorcycle"></i></div>
      <div>
        <div class="lr-name" id="lrDriverName">—</div>
        <div class="lr-sub" id="lrDriverSub">—</div>
      </div>
    </div>
    <label style="display:block;font-size:11.5px;font-weight:700;color:#3C4043;margin-top:14px;">Halagang natanggap (cash)</label>
    <input type="number" id="amountReceived" class="manual-inp" style="width:100%;margin-top:6px;letter-spacing:0;" placeholder="0.00" step="0.01" min="0">
    <button type="button" class="confirm-btn" id="confirmBtn" onclick="confirmTopup()"><i class="fas fa-check-circle"></i> Nabayaran na — I-credit</button>
  </div>

  <div class="section-title">Mga Huling Na-process</div>
  <div class="txn-list">
    <?php if (empty($recent)): ?>
    <div style="text-align:center;padding:30px 20px;color:#9AA0A6;font-size:13px;">Wala pang na-process.</div>
    <?php else: foreach ($recent as $r): ?>
    <div class="txn-card">
      <div class="txn-icon"><i class="fas fa-check"></i></div>
      <div>
        <div class="txn-name"><?= htmlspecialchars($r['account_name'] ?? '—') ?> <span style="font-size:10px;color:#9AA0A6;font-weight:600;">· <?= $r['account_type'] === 'driver' ? 'Driver' : 'Pasahero' ?></span></div>
        <div class="txn-time"><?= date('M d, h:i A', strtotime($r['paid_at'])) ?> · #<?= htmlspecialchars($r['topup_code']) ?></div>
      </div>
      <div class="txn-amt">+₱<?= number_format((float)$r['amount'],2) ?></div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"></script>
<script>
const APP_URL   = '<?= APP_URL ?>';
const CSRF_NAME = '<?= CSRF_TOKEN_NAME ?>';
const CSRF_TOK  = '<?= $csrfToken ?>';
let currentRequestId = null;

function startScan() {
  const readerEl = document.getElementById('qrReader');
  const btn = document.getElementById('scanBtn');

  if (typeof Html5Qrcode === 'undefined') {
    showLookupError('Hindi ma-load ang QR scanner library. I-check ang internet connection o gamitin na lang ang manual input.');
    return;
  }
  if (!window.isSecureContext) {
    showLookupError('Kailangan ng HTTPS ang camera scanner. Gamitin na lang ang manual input.');
    return;
  }

  readerEl.style.display = 'block';
  btn.style.display = 'none';

  const html5QrCode = new Html5Qrcode('qrReader');
  html5QrCode.start(
    { facingMode: 'environment' },
    { fps: 10, qrbox: 220 },
    (decodedText) => {
      html5QrCode.stop().then(() => {
        readerEl.style.display = 'none';
        btn.style.display = 'block';
        document.getElementById('manualCode').value = decodedText;
        lookupCode();
      });
    },
    () => {}
  ).catch((err) => {
    console.error('QR camera error:', err);
    showLookupError('Hindi ma-access ang camera. Siguraduhing pinayagan ang camera permission sa browser settings, o gamitin na lang ang manual input.');
    readerEl.style.display = 'none';
    btn.style.display = 'block';
  });
}

function showLookupError(msg) {
  const el = document.getElementById('lookupError');
  el.textContent = msg;
  el.style.display = 'block';
  document.getElementById('lookupResult').style.display = 'none';
}

function lookupCode() {
  const code = document.getElementById('manualCode').value.trim();
  if (!code) return;

  document.getElementById('lookupError').style.display = 'none';

  fetch(APP_URL + '/api/topup.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=lookup_request&code=' + encodeURIComponent(code) + '&' + CSRF_NAME + '=' + CSRF_TOK,
  })
  .then(r => r.json())
  .then(res => {
    if (!res.success) { showLookupError(res.message); return; }
    const d = res.data;
    currentRequestId = d.id;
    document.getElementById('lrAmount').textContent = '₱' + d.amount.toFixed(2);
    document.getElementById('lrCode').textContent = '#' + d.request_code;
    document.getElementById('lrDriverName').textContent = d.account_name;
    document.getElementById('lrDriverSub').textContent = (d.account_type === 'driver' ? 'Driver' : 'Pasahero') + ' · ' + d.account_code + ' · ' + d.account_phone;
    document.getElementById('lrAvatar').innerHTML = d.account_type === 'driver' ? '<i class="fas fa-motorcycle"></i>' : '<i class="fas fa-user"></i>';
    document.getElementById('amountReceived').value = '';
    document.getElementById('lookupResult').style.display = 'block';
  })
  .catch(() => showLookupError('Network error. Subukan ulit.'));
}

function confirmTopup() {
  if (!currentRequestId) return;
  const received = document.getElementById('amountReceived').value.trim();
  if (!received) { alert('Ilagay ang halagang natanggap mo.'); return; }
  if (!confirm('Kumpirmahin na natanggap mo na ang ₱' + Number(received).toFixed(2) + ' na cash?')) return;

  const btn = document.getElementById('confirmBtn');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…';

  fetch(APP_URL + '/api/topup.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=confirm_topup&request_id=' + currentRequestId + '&amount_received=' + encodeURIComponent(received) + '&' + CSRF_NAME + '=' + CSRF_TOK,
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      alert(res.message);
      window.location.reload();
    } else {
      alert(res.message);
      btn.disabled = false; btn.innerHTML = '<i class="fas fa-check-circle"></i> Nabayaran na — I-credit';
    }
  })
  .catch(() => {
    alert('Network error. Subukan ulit.');
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-check-circle"></i> Nabayaran na — I-credit';
  });
}
</script>
</body>
</html>
