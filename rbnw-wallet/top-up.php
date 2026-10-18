<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$wallet = get_wallet(current_user_id());
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<title>Cash In — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
.status-pill {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 700;
  margin: 0 auto 4px;
}
.status-pill.pending { background: rgba(245,166,35,0.12); color: #c17d00; }
.status-pill.paid    { background: rgba(47,168,79,0.12); color: var(--success); }
.status-pill.expired { background: rgba(224,69,63,0.12); color: var(--danger); }
.status-pill.pending::before {
  content: ''; width: 7px; height: 7px; border-radius: 50%;
  background: #c17d00; animation: pulseDot 1.4s ease-in-out infinite;
}
@keyframes pulseDot { 0%,100%{opacity:1} 50%{opacity:.4} }
.qr-wrap {
  background: #fff; border: 1px solid var(--border); border-radius: var(--radius-md);
  padding: 14px; display: flex; align-items: center; justify-content: center;
  box-shadow: var(--shadow-soft);
}
.qr-wrap img { width: 200px; height: 200px; display: block; border-radius: 4px; }
.qr-meta { text-align: center; margin-top: 4px; }
.qr-meta .ref { font-weight: 800; color: var(--rbnw-blue); letter-spacing: 0.5px; font-size: 14px; display: block; }
.qr-meta .lbl { font-size: 11.5px; color: var(--text-muted); }
.qr-countdown { text-align: center; font-size: 12.5px; color: var(--text-muted); }
.qr-countdown strong { color: #c17d00; }
</style>
</head>
<body>
<div class="app-shell">

  <!-- STEP 1: Enter Amount -->
  <div id="step1">
    <div class="flow-header">
      <button class="back-btn" onclick="RBNW.goBack('dashboard.php')" style="background:none;border:none;"><i class="fa-solid fa-arrow-left"></i></button>
      <h2>Cash In</h2>
    </div>
    <div class="flow-body">

      <div class="amount-display">
        <input type="text" id="amountInput" inputmode="numeric" placeholder="₱0.00" autocomplete="off">
        <div class="avail">Current Balance: <strong><?= peso((float)$wallet['balance']) ?></strong></div>
      </div>

      <div class="quick-amounts">
        <button type="button" data-amt="100">₱100</button>
        <button type="button" data-amt="200">₱200</button>
        <button type="button" data-amt="500">₱500</button>
        <button type="button" data-amt="1000">₱1,000</button>
        <button type="button" data-amt="5000">₱5,000</button>
      </div>

      <div class="card" id="feeCard" style="display:none;">
        <div class="review-row"><span class="label">Amount to Receive</span><span id="feeAmount">₱0.00</span></div>
        <div class="review-row"><span class="label">PayMongo Fee (<?= number_format(CASH_IN_FEE_PAYMONGO_PCT, 2) ?>%)</span><span id="feePaymongo">₱0.00</span></div>
        <?php if (CASH_IN_FEE_RBNW_PCT > 0): ?>
        <div class="review-row"><span class="label">RBNW Fee (<?= number_format(CASH_IN_FEE_RBNW_PCT, 2) ?>%)</span><span id="feeRbnw">₱0.00</span></div>
        <?php endif; ?>
        <div class="review-row"><span class="label">Total to Pay</span><span id="feeTotal">₱0.00</span></div>
      </div>

      <div class="alert alert-info">
        <i class="fa-solid fa-circle-info"></i>
        Minimum cash in is <strong><?= peso(CASH_IN_MIN_AMOUNT) ?></strong>, maximum is <strong><?= peso(CASH_IN_MAX_AMOUNT) ?></strong>. Pay via GCash, Maya, or any QR Ph-supported bank app.
      </div>

      <div id="amountError"></div>
      <button class="btn btn-primary" id="generateBtn">Generate QR Ph Code</button>
    </div>
  </div>

  <!-- STEP 2: QR Ph Payment -->
  <div id="step2" class="hidden">
    <div class="flow-header">
      <button class="back-btn" onclick="cancelCashIn()" style="background:none;border:none;"><i class="fa-solid fa-arrow-left"></i></button>
      <h2>Scan to Pay</h2>
    </div>
    <div class="flow-body" style="display:flex;flex-direction:column;align-items:center;gap:14px;">
      <div class="status-pill pending" id="statusPill">Waiting for Payment</div>
      <p style="text-align:center;color:var(--text-muted);font-size:13px;margin:0;max-width:280px;">Scan with your GCash, Maya, or any QR Ph-supported app</p>

      <div style="text-align:center;">
        <div style="font-size:26px;font-weight:800;color:var(--rbnw-blue);" id="qrTotal">₱0.00</div>
        <div style="font-size:12.5px;color:var(--text-muted);" id="qrNote">You will receive ₱0.00</div>
      </div>

      <div class="qr-wrap"><div id="qrImageBox"></div></div>

      <div class="qr-meta">
        <span class="ref" id="qrRef">CI-XXXXXXXXXX</span>
        <span class="lbl">Reference Number</span>
      </div>

      <div class="qr-countdown">Expires in <strong id="qrCountdown">15:00</strong></div>

      <div class="card" style="width:100%;">
        <div style="display:flex;gap:10px;padding:6px 0;">
          <div style="width:22px;height:22px;border-radius:50%;background:rgba(15,76,146,0.1);color:var(--rbnw-blue);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;flex-shrink:0;">1</div>
          <div style="font-size:12.5px;color:var(--text-muted);">Open GCash, Maya, or any bank app</div>
        </div>
        <div style="display:flex;gap:10px;padding:6px 0;">
          <div style="width:22px;height:22px;border-radius:50%;background:rgba(15,76,146,0.1);color:var(--rbnw-blue);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;flex-shrink:0;">2</div>
          <div style="font-size:12.5px;color:var(--text-muted);">Tap "Pay QR" or "Scan QR"</div>
        </div>
        <div style="display:flex;gap:10px;padding:6px 0;">
          <div style="width:22px;height:22px;border-radius:50%;background:rgba(15,76,146,0.1);color:var(--rbnw-blue);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;flex-shrink:0;">3</div>
          <div style="font-size:12.5px;color:var(--text-muted);">Scan the QR code above and confirm payment</div>
        </div>
        <div style="display:flex;gap:10px;padding:6px 0;">
          <div style="width:22px;height:22px;border-radius:50%;background:rgba(15,76,146,0.1);color:var(--rbnw-blue);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;flex-shrink:0;">4</div>
          <div style="font-size:12.5px;color:var(--text-muted);">Your RBNW Wallet is credited automatically</div>
        </div>
      </div>

      <button class="btn btn-outline" style="width:100%;" onclick="cancelCashIn()">Cancel &amp; Start Over</button>
    </div>
  </div>

  <!-- STEP 3: Success -->
  <div id="step3" class="hidden">
    <div class="success-screen">
      <div class="success-check"><i class="fa-solid fa-check"></i></div>
      <h2>Cash In Successful!</h2>
      <p style="color:var(--text-muted);font-size:13.5px;">Your wallet was credited</p>
      <div class="amt" id="successAmount">₱0.00</div>
      <p style="color:var(--text-muted);font-size:11.5px;margin-top:6px;">Reference: <span id="successRef"></span></p>
      <div style="margin-top:24px;display:flex;flex-direction:column;gap:10px;">
        <button class="btn btn-primary" onclick="location.href='dashboard.php'">Done</button>
      </div>
    </div>
  </div>

</div>

<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script>
const CASH_IN_MIN = <?= (float)CASH_IN_MIN_AMOUNT ?>;
const CASH_IN_MAX = <?= (float)CASH_IN_MAX_AMOUNT ?>;
const FEE_PAYMONGO_PCT = <?= (float)CASH_IN_FEE_PAYMONGO_PCT ?>;
const FEE_RBNW_PCT = <?= (float)CASH_IN_FEE_RBNW_PCT ?>;

let countdownTimer = null;
let pollTimer = null;

function goToStep(n) {
  for (let i = 1; i <= 3; i++) document.getElementById('step' + i).classList.add('hidden');
  document.getElementById('step' + n).classList.remove('hidden');
}

document.querySelectorAll('.quick-amounts button').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.quick-amounts button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('amountInput').value = btn.dataset.amt;
    updateFeePreview();
  });
});

document.getElementById('amountInput').addEventListener('input', (e) => {
  e.target.value = e.target.value.replace(/[^0-9.]/g, '');
  updateFeePreview();
});

function updateFeePreview() {
  const amt = parseFloat(document.getElementById('amountInput').value || '0');
  const feeCard = document.getElementById('feeCard');
  if (!amt || amt < CASH_IN_MIN) { feeCard.style.display = 'none'; return; }

  const feePaymongo = Math.round(amt * FEE_PAYMONGO_PCT) / 100;
  const feeRbnw = Math.round(amt * FEE_RBNW_PCT) / 100;
  const total = Math.round((amt + feePaymongo + feeRbnw) * 100) / 100;

  document.getElementById('feeAmount').textContent = RBNW.peso(amt);
  document.getElementById('feePaymongo').textContent = RBNW.peso(feePaymongo);
  const feeRbnwEl = document.getElementById('feeRbnw');
  if (feeRbnwEl) feeRbnwEl.textContent = RBNW.peso(feeRbnw);
  document.getElementById('feeTotal').textContent = RBNW.peso(total);
  feeCard.style.display = 'block';
}

document.getElementById('generateBtn').addEventListener('click', async () => {
  const amt = parseFloat(document.getElementById('amountInput').value || '0');
  const errBox = document.getElementById('amountError');

  if (!amt || amt < CASH_IN_MIN) {
    errBox.innerHTML = `<div class="alert alert-error">Minimum cash in is ${RBNW.peso(CASH_IN_MIN)}.</div>`;
    return;
  }
  if (amt > CASH_IN_MAX) {
    errBox.innerHTML = `<div class="alert alert-error">Maximum cash in is ${RBNW.peso(CASH_IN_MAX)}.</div>`;
    return;
  }
  errBox.innerHTML = '';

  const btn = document.getElementById('generateBtn');
  btn.disabled = true;
  btn.textContent = 'Generating QR Ph code...';

  const res = await RBNW.api('top-up.php?action=generate', 'POST', { amount: amt });

  btn.disabled = false;
  btn.textContent = 'Generate QR Ph Code';

  if (!res.success) {
    errBox.innerHTML = `<div class="alert alert-error">${res.message || 'Failed to generate payment.'}</div>`;
    return;
  }

  showQrStep(res);
});

function showQrStep(data) {
  document.getElementById('statusPill').className = 'status-pill pending';
  document.getElementById('statusPill').textContent = 'Waiting for Payment';
  document.getElementById('qrTotal').textContent = RBNW.peso(data.total);
  document.getElementById('qrNote').textContent = 'You will receive ' + RBNW.peso(data.amount);
  document.getElementById('qrRef').textContent = data.reference;

  const box = document.getElementById('qrImageBox');
  box.innerHTML = '';
  if (data.qr_image) {
    const img = document.createElement('img');
    img.src = data.qr_image; // data:image/png;base64,...
    img.alt = 'QR Ph Code';
    box.appendChild(img);
  } else {
    box.innerHTML = '<div style="padding:30px;color:var(--danger);font-size:12px;text-align:center;">QR not available</div>';
  }

  goToStep(2);
  startCountdown(data.expires_in || 900);
  startPolling(data.reference);
}

function startCountdown(seconds) {
  clearInterval(countdownTimer);
  let remaining = seconds;
  updateCountdownDisplay(remaining);
  countdownTimer = setInterval(() => {
    remaining--;
    updateCountdownDisplay(remaining);
    if (remaining <= 0) {
      clearInterval(countdownTimer);
      clearInterval(pollTimer);
      document.getElementById('statusPill').className = 'status-pill expired';
      document.getElementById('statusPill').textContent = 'Expired';
      RBNW.toast('QR code expired. Please generate a new one.', 'error');
    }
  }, 1000);
}

function updateCountdownDisplay(seconds) {
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  document.getElementById('qrCountdown').textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
}

function startPolling(reference) {
  clearInterval(pollTimer);
  pollTimer = setInterval(async () => {
    try {
      const res = await RBNW.api('top-up.php?action=status&ref=' + encodeURIComponent(reference));
      if (res.status === 'paid') {
        clearInterval(pollTimer);
        clearInterval(countdownTimer);
        document.getElementById('successAmount').textContent = '₱' + res.amount;
        document.getElementById('successRef').textContent = reference;
        goToStep(3);
      } else if (res.status === 'expired') {
        clearInterval(pollTimer);
        clearInterval(countdownTimer);
        document.getElementById('statusPill').className = 'status-pill expired';
        document.getElementById('statusPill').textContent = 'Expired';
      }
    } catch (e) { /* keep polling silently */ }
  }, 4000);
}

function cancelCashIn() {
  clearInterval(countdownTimer);
  clearInterval(pollTimer);
  goToStep(1);
}
</script>
</body>
</html>
