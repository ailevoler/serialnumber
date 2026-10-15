<?php
// ============================================================
// TRILINK PAY – views/topup_status.php
// Shared "pay this top-up" screen for passenger + driver apps.
// Expects:
//   $t            top-up row (Topup::refresh()'d)
//   $balance      current wallet balance (float)
//   $pageTitle    e.g. 'Top Up – TriLink'
//   $backUrl      wallet page URL
//   $statusUrl    GET  → JSON {success, data:{status, seconds_left, balance}}
//   $cancelUrl    POST code + csrf → JSON {success, message}
//   $csrfName, $csrfToken
// ============================================================
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$status   = $t['status'];
$isQr     = $t['channel'] === 'qrph';
$amount   = number_format((float)$t['amount'], 2);
$secsLeft = max(0, (int)($t['seconds_left'] ?? 0));
$pillText = [
    'pending'   => $isQr ? 'Naghihintay ng bayad (QR Ph)' : 'Naghihintay ng bayad sa Teller',
    'paid'      => 'Bayad na – na-credit na!',
    'expired'   => 'Expired na',
    'cancelled' => 'Kinansela',
    'failed'    => 'Hindi natuloy',
][$status] ?? ucfirst($status);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title><?= $h($pageTitle) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    *{box-sizing:border-box;margin:0;padding:0;}
    body{background:#F5F6FA;font-family:'Inter',sans-serif;color:#202124;}
    .app{max-width:480px;margin:0 auto;min-height:100vh;background:#F5F6FA;padding-bottom:30px;}
    .topbar{display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#0F1A3A,#1A2B5E);padding:14px;}
    .tb-btn{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,0.1);color:#fff;display:flex;align-items:center;justify-content:center;text-decoration:none;}
    .tb-title{color:#fff;font-family:'Plus Jakarta Sans',sans-serif;font-size:15px;font-weight:800;}
    .pill{display:block;width:fit-content;margin:18px auto 0;font-size:12.5px;font-weight:800;padding:6px 16px;border-radius:14px;}
    .pill.pending{background:#FFF3E0;color:#E65100;}
    .pill.paid{background:#E8F5E9;color:#2E7D32;}
    .pill.expired,.pill.cancelled,.pill.failed{background:#FFEBEE;color:#C62828;}
    .card{margin:14px 16px 0;background:#fff;border-radius:16px;border:1.5px solid #EAECF0;padding:22px;text-align:center;}
    .qr-box{width:230px;height:230px;margin:0 auto;display:flex;align-items:center;justify-content:center;}
    .qr-box img,.qr-box canvas{width:230px !important;height:230px !important;image-rendering:pixelated;}
    .qrph-badge{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:800;color:#1A2B5E;background:#EEF2FF;padding:4px 10px;border-radius:10px;margin-bottom:12px;}
    .amount{font-family:'Plus Jakarta Sans',sans-serif;font-size:32px;font-weight:800;color:#1A2B5E;margin-top:12px;}
    .code{font-family:'Plus Jakarta Sans',sans-serif;font-size:15px;font-weight:800;color:#5F6368;margin-top:4px;letter-spacing:1px;}
    .timer{margin-top:10px;font-size:13px;font-weight:700;color:#E65100;}
    .steps{margin:14px 16px 0;background:#fff;border-radius:14px;border:1.5px solid #EAECF0;padding:14px 16px;font-size:12.5px;color:#3C4043;line-height:1.6;}
    .steps ol{padding-left:18px;}
    .steps strong{color:#1A2B5E;}
    .btn{display:flex;align-items:center;justify-content:center;gap:8px;margin:12px 16px 0;padding:14px;border-radius:14px;font-weight:800;font-size:15px;text-decoration:none;border:none;cursor:pointer;font-family:inherit;width:calc(100% - 32px);}
    .btn-green{background:linear-gradient(135deg,#2E7D32,#43A047);color:#fff;}
    .btn-navy{background:linear-gradient(135deg,#1A2B5E,#2D4A8A);color:#fff;}
    .btn-ghost{background:#fff;color:#C62828;border:1.5px solid #F5C6C6;}
    .note{margin:12px 16px 0;font-size:11.5px;color:#9AA0A6;text-align:center;line-height:1.5;}
  </style>
</head>
<body>
<div class="app">
  <div class="topbar">
    <a href="<?= $h($backUrl) ?>" class="tb-btn"><i class="fas fa-arrow-left"></i></a>
    <div class="tb-title"><?= $isQr ? 'Top Up via QR Ph' : 'Top Up sa Teller' ?></div>
    <div class="tb-btn" style="opacity:0;"></div>
  </div>

  <span class="pill <?= $h($status) ?>"><?= $h($pillText) ?></span>

  <?php if ($status === 'pending' && $isQr): ?>
    <div class="card">
      <div class="qrph-badge"><i class="fas fa-qrcode"></i> QR Ph · GCash · Maya · Bank apps</div>
      <div class="qr-box"><img src="<?= $h($t['qr_image']) ?>" alt="QR Ph code"></div>
      <div class="amount">₱<?= $amount ?></div>
      <div class="code">#<?= $h($t['topup_code']) ?></div>
      <div class="timer" id="timer"></div>
    </div>
    <a class="btn btn-navy" href="<?= $h($t['qr_image']) ?>" download="TriLink-<?= $h($t['topup_code']) ?>.png"><i class="fas fa-download"></i> I-save ang QR</a>
    <div class="steps">
      <ol>
        <li>Buksan ang <strong>GCash, Maya,</strong> o kahit anong <strong>bank app</strong> na may QR Ph.</li>
        <li>I-scan ang QR (o i-upload ang na-save na QR mula sa gallery).</li>
        <li>Bayaran ang eksaktong <strong>₱<?= $amount ?></strong>.</li>
        <li>Awtomatikong papasok sa wallet mo pag kumpirmado na ang bayad. Huwag isara ang page na ito.</li>
      </ol>
    </div>
    <button class="btn btn-ghost" id="cancelBtn" onclick="cancelTopup()"><i class="fas fa-times"></i> I-cancel</button>

  <?php elseif ($status === 'pending'): ?>
    <div class="card">
      <div class="qr-box" id="tellerQr"></div>
      <div class="amount">₱<?= $amount ?></div>
      <div class="code">#<?= $h($t['topup_code']) ?></div>
      <div class="timer" id="timer"></div>
    </div>
    <div class="steps">
      <ol>
        <li>Pumunta sa pinakamalapit na <strong>TriLink Load Center / Teller</strong>.</li>
        <li>Ipakita ang QR code o sabihin ang code <strong>#<?= $h($t['topup_code']) ?></strong>.</li>
        <li>Magbayad ng cash na <strong>₱<?= $amount ?></strong>.</li>
        <li>Papasok lang ang pera sa wallet <strong>pag na-confirm na ng teller</strong>.</li>
      </ol>
    </div>
    <button class="btn btn-ghost" id="cancelBtn" onclick="cancelTopup()"><i class="fas fa-times"></i> I-cancel</button>

  <?php elseif ($status === 'paid'): ?>
    <div class="card" style="background:#E8F5E9;border-color:#C8E6C9;">
      <i class="fas fa-check-circle" style="font-size:48px;color:#2E7D32;"></i>
      <div class="amount" style="color:#2E7D32;">+₱<?= $amount ?></div>
      <div style="font-size:13px;color:#5F6368;margin-top:6px;">Bagong balance: <strong>₱<?= number_format((float)$balance, 2) ?></strong></div>
      <div class="code" style="font-size:12px;margin-top:8px;">#<?= $h($t['topup_code']) ?> · <?= $isQr ? 'QR Ph' : 'Teller' ?></div>
    </div>
    <a class="btn btn-green" href="<?= $h($backUrl) ?>"><i class="fas fa-wallet"></i> Balik sa Wallet</a>

  <?php else: ?>
    <div class="card">
      <i class="fas fa-<?= $status === 'failed' ? 'exclamation-triangle' : 'clock' ?>" style="font-size:44px;color:#C62828;opacity:.8;"></i>
      <div style="font-size:15px;font-weight:800;color:#1A2B5E;margin-top:10px;">
        <?= $status === 'expired' ? 'Nag-expire na ang top-up request na ito.' : ($status === 'failed' ? 'Hindi natuloy ang top-up request na ito.' : 'Kinansela ang top-up request na ito.') ?>
      </div>
      <div class="code" style="font-size:12px;margin-top:6px;">#<?= $h($t['topup_code']) ?> · ₱<?= $amount ?></div>
    </div>
    <?php if ($isQr): ?>
    <div class="note">Kung nakabayad ka na pero hindi pumasok, huwag mag-alala — awtomatiko itong ma-credit kapag na-confirm ng QR Ph. Kung lumipas ang 1 oras, i-contact ang support at ibigay ang code sa itaas.</div>
    <?php endif; ?>
    <a class="btn btn-green" href="<?= $h($backUrl) ?>?topup=1"><i class="fas fa-plus"></i> Gumawa ng Bagong Top Up</a>
  <?php endif; ?>
</div>

<?php if ($status === 'pending' && !$isQr): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
  if (window.QRCode) {
    new QRCode(document.getElementById('tellerQr'), { text: <?= json_encode($t['topup_code']) ?>, width: 230, height: 230, correctLevel: QRCode.CorrectLevel.M });
  } else {
    document.getElementById('tellerQr').innerHTML = '<div style="font-size:22px;font-weight:800;color:#1A2B5E;">#<?= $h($t['topup_code']) ?></div>';
  }
</script>
<?php endif; ?>

<?php if ($status === 'pending'): ?>
<script>
(function () {
  var STATUS_URL = <?= json_encode($statusUrl) ?>;
  var CANCEL_URL = <?= json_encode($cancelUrl) ?>;
  var CODE       = <?= json_encode($t['topup_code']) ?>;
  var CSRF_NAME  = <?= json_encode($csrfName) ?>;
  var CSRF_TOK   = <?= json_encode($csrfToken) ?>;
  var hasExpiry  = <?= $t['expires_at'] !== null ? 'true' : 'false' ?>;
  var deadline   = Date.now() + <?= $secsLeft ?> * 1000;
  var timerEl    = document.getElementById('timer');

  function tick() {
    if (!hasExpiry || !timerEl) return;
    var s = Math.max(0, Math.round((deadline - Date.now()) / 1000));
    var hh = Math.floor(s / 3600), mm = Math.floor((s % 3600) / 60), ss = s % 60;
    timerEl.textContent = 'Valid pa ng ' + (hh ? hh + 'h ' : '') + (hh ? mm : String(mm).padStart(2, '0')) + (hh ? 'm' : ':' + String(ss).padStart(2, '0'));
  }
  tick(); setInterval(tick, 1000);

  function poll() {
    fetch(STATUS_URL + (STATUS_URL.indexOf('?') < 0 ? '?' : '&') + 'code=' + encodeURIComponent(CODE) + '&t=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success && res.data && res.data.status !== 'pending') window.location.reload();
      })
      .catch(function () {});
  }
  setInterval(poll, 4000);

  window.cancelTopup = function () {
    if (!confirm('I-cancel ang top-up na ito?')) return;
    var btn = document.getElementById('cancelBtn');
    btn.disabled = true;
    var body = 'code=' + encodeURIComponent(CODE) + '&' + encodeURIComponent(CSRF_NAME) + '=' + encodeURIComponent(CSRF_TOK);
    fetch(CANCEL_URL, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
      .then(function (r) { return r.json(); })
      .then(function (res) { if (!res.success) alert(res.message || 'Hindi ma-cancel.'); window.location.reload(); })
      .catch(function () { btn.disabled = false; alert('Network error. Subukan ulit.'); });
  };
})();
</script>
<?php endif; ?>
</body>
</html>
