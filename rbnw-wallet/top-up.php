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
<?php include __DIR__ . '/includes/pwa-head.php'; ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
.cashin-body { padding-bottom: 120px; }
.cashin-amount-card {
  background: #fff; border-radius: var(--radius-lg); padding: 20px 18px 16px;
  box-shadow: var(--shadow-card); text-align: center;
}
.cashin-label { display: block; font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 8px; }
.cashin-input {
  display: flex; align-items: center; justify-content: center; gap: 4px;
  border-bottom: 2px solid var(--border); padding-bottom: 8px; margin: 0 8px;
  transition: border-color .15s;
}
.cashin-input:focus-within { border-color: var(--rbnw-blue-light); }
.cashin-input .currency { font-size: 32px; font-weight: 800; color: var(--rbnw-blue); }
.cashin-input input {
  border: none; outline: none; background: transparent; min-width: 0;
  width: 4.2ch; max-width: 240px; font-size: 40px; font-weight: 800;
  color: var(--text-dark); letter-spacing: -0.5px; text-align: left;
}
.cashin-input input::placeholder { color: #c3ccd9; }
.cashin-balance {
  display: inline-flex; align-items: center; gap: 6px; margin-top: 14px;
  padding: 6px 12px; border-radius: 999px; background: var(--rbnw-blue-50);
  font-size: 12.5px; color: var(--text-muted);
}
.cashin-balance i { color: var(--rbnw-blue); }
.cashin-balance strong { color: var(--text-dark); }
.cashin-section-title { margin: 22px 2px 10px; font-size: 13px; font-weight: 700; color: var(--text-body); }
.cashin-quick { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.cashin-quick button {
  height: 46px; border-radius: var(--radius-sm); border: 1.5px solid var(--border);
  background: #fff; font-size: 15px; font-weight: 700; color: var(--rbnw-blue);
  transition: border-color .15s, background .15s, color .15s;
}
.cashin-quick button:hover { border-color: var(--border-strong); }
.cashin-quick button.active { background: var(--rbnw-blue); border-color: var(--rbnw-blue); color: #fff; }
.cashin-summary { margin: 18px 0 0; }
.cashin-summary .review-row.total span { color: var(--rbnw-blue); font-size: 15px; }
.cashin-note {
  display: flex; gap: 10px; align-items: flex-start; margin-top: 18px;
  padding: 12px 14px; border-radius: var(--radius-sm);
  background: var(--rbnw-blue-50); border: 1px solid #cfe0f5; color: var(--rbnw-blue);
}
.cashin-note i { margin-top: 2px; }
.cashin-note strong { display: block; font-size: 13.5px; }
.cashin-note span { display: block; font-size: 12.5px; color: var(--text-body); margin-top: 2px; line-height: 1.4; }
#amountError .alert { margin: 14px 0 0; }
.cashin-footer {
  position: fixed; bottom: 0; left: 50%; transform: translateX(-50%);
  width: 100%; max-width: var(--max-w);
  padding: 12px 16px calc(12px + env(safe-area-inset-bottom));
  background: linear-gradient(180deg, rgba(243,246,250,0) 0%, var(--bg) 35%);
}
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
    <div class="flow-body cashin-body">

      <div class="cashin-amount-card">
        <label for="amountInput" class="cashin-label">Enter amount</label>
        <div class="cashin-input">
          <span class="currency">₱</span>
          <input type="text" id="amountInput" inputmode="decimal" placeholder="0.00" autocomplete="off" aria-describedby="cashinLimits">
        </div>
        <div class="cashin-balance">
          <i class="fa-solid fa-wallet"></i> Current balance <strong><?= peso((float)$wallet['balance']) ?></strong>
        </div>
      </div>

      <div class="cashin-section-title">Quick amounts</div>
      <div class="cashin-quick">
        <?php foreach ([100, 200, 500, 1000, 2000, 5000] as $amt): ?>
          <button type="button" data-amt="<?= $amt ?>">₱<?= number_format($amt) ?></button>
        <?php endforeach; ?>
      </div>

      <div class="card cashin-summary" id="feeCard" style="display:none;">
        <div class="review-row"><span class="label">Amount to receive</span><span id="feeAmount">₱0.00</span></div>
        <div class="review-row"><span class="label">PayMongo fee (<?= number_format(CASH_IN_FEE_PAYMONGO_PCT, 2) ?>%)</span><span id="feePaymongo">₱0.00</span></div>
        <?php if (CASH_IN_FEE_RBNW_PCT > 0): ?>
        <div class="review-row"><span class="label">RBNW fee (<?= number_format(CASH_IN_FEE_RBNW_PCT, 2) ?>%)</span><span id="feeRbnw">₱0.00</span></div>
        <?php endif; ?>
        <div class="review-row total"><span class="label">Total to pay</span><span id="feeTotal">₱0.00</span></div>
      </div>

      <div class="cashin-note" id="cashinLimits">
        <i class="fa-solid fa-circle-info"></i>
        <div>
          <strong>Min <?= peso(CASH_IN_MIN_AMOUNT) ?> · Max <?= peso(CASH_IN_MAX_AMOUNT) ?></strong>
          <span>Pay using GCash, Maya, or any QR Ph-supported bank app.</span>
        </div>
      </div>

      <div id="amountError"></div>
    </div>

    <div class="cashin-footer">
      <button class="btn btn-primary" id="generateBtn"><i class="fa-solid fa-qrcode"></i> <span>Generate QR Ph Code</span></button>
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

document.querySelectorAll('.cashin-quick button').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.cashin-quick button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('amountInput').value = btn.dataset.amt;
    document.getElementById('amountError').innerHTML = '';
    fitAmountInput();
    updateFeePreview();
  });
});

document.getElementById('amountInput').addEventListener('input', (e) => {
  // digits and one decimal point, max 2 decimals
  let v = e.target.value.replace(/[^0-9.]/g, '');
  const [whole, ...rest] = v.split('.');
  v = rest.length ? whole + '.' + rest.join('').slice(0, 2) : whole;
  e.target.value = v;
  document.querySelectorAll('.cashin-quick button').forEach(b => b.classList.toggle('active', b.dataset.amt === v));
  document.getElementById('amountError').innerHTML = '';
  fitAmountInput();
  updateFeePreview();
});

// Grow the amount field with its text so "₱ 1,000" stays centred
function fitAmountInput() {
  const el = document.getElementById('amountInput');
  el.style.width = Math.max(el.value.length || 4, 1) + 0.6 + 'ch';
}

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
    errBox.innerHTML = RBNW.alertHtml(`Minimum cash in is ${RBNW.peso(CASH_IN_MIN)}.`, 'error');
    return;
  }
  if (amt > CASH_IN_MAX) {
    errBox.innerHTML = RBNW.alertHtml(`Maximum cash in is ${RBNW.peso(CASH_IN_MAX)}.`, 'error');
    return;
  }
  errBox.innerHTML = '';

  const btn = document.getElementById('generateBtn');
  const btnLabel = btn.querySelector('span');
  btn.disabled = true;
  btnLabel.textContent = 'Generating QR Ph code…';

  const res = await RBNW.api('top-up.php?action=generate', 'POST', { amount: amt });

  btn.disabled = false;
  btnLabel.textContent = 'Generate QR Ph Code';

  if (!res.success) {
    errBox.innerHTML = RBNW.alertHtml(res.message || 'Failed to generate payment.', 'error');
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
