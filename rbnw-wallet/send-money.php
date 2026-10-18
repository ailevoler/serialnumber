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
<title>Send Money — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
.st-tabs { display: flex; gap: 8px; margin-bottom: 18px; }
.st-tab {
  flex: 1; padding: 11px 8px; border-radius: 12px; border: 1.5px solid var(--border);
  background: #fff; color: var(--text-muted); font-family: inherit; font-size: 12.5px; font-weight: 700;
  cursor: pointer; transition: var(--transition); text-align: center; -webkit-tap-highlight-color: transparent;
}
.st-tab.active { border-color: var(--rbnw-blue); background: var(--rbnw-blue); color: #fff; }
.mobile-row { display: flex; gap: 8px; }
.mobile-prefix {
  display: flex; align-items: center; padding: 0 14px; background: var(--bg);
  border: 1.5px solid var(--border); border-radius: var(--radius-md);
  font-weight: 700; color: var(--text-muted); font-size: 14px; flex-shrink: 0;
}
.mobile-input {
  flex: 1; min-width: 0; padding: 14px; background: var(--bg);
  border: 1.5px solid var(--border); border-radius: var(--radius-md);
  font-size: 16px; font-weight: 700; letter-spacing: 0.5px; outline: none; transition: var(--transition);
}
.mobile-input:focus { border-color: var(--rbnw-blue-light); }
</style>
</head>
<body>
<div class="app-shell">

  <!-- STEP 1: Choose Recipient -->
  <div id="step1">
    <div class="flow-header">
      <button class="back-btn" onclick="RBNW.goBack('dashboard.php')" style="background:none;border:none;"><i class="fa-solid fa-arrow-left"></i></button>
      <h2>Send Money</h2>
    </div>
    <div class="flow-body">

      <div class="st-tabs" role="tablist">
        <button class="st-tab active" id="tab-contacts" type="button" onclick="switchRecipientTab('contacts')" role="tab" aria-selected="true">To Contacts</button>
        <button class="st-tab" id="tab-mobile" type="button" onclick="switchRecipientTab('mobile')" role="tab" aria-selected="false">To Mobile Number</button>
      </div>

      <!-- Tab: Contacts -->
      <div id="tabPanel-contacts">
        <div class="field">
          <div class="input-wrap">
            <i class="icon fa-solid fa-magnifying-glass"></i>
            <input type="text" id="searchInput" placeholder="Search by name or mobile number">
          </div>
        </div>
        <div id="recipientListLabel" class="section-head" style="margin-top:16px;">
          <h3>Frequently Sent</h3>
        </div>
        <div id="recipientList"></div>
      </div>

      <!-- Tab: Mobile Number -->
      <div id="tabPanel-mobile" class="hidden">
        <div class="field">
          <label>Recipient's Mobile Number</label>
          <div class="mobile-row">
            <div class="mobile-prefix">+63</div>
            <input type="tel" id="mobileNumInput" class="mobile-input" placeholder="9XXXXXXXXX" maxlength="10" inputmode="numeric" autocomplete="off">
          </div>
        </div>
        <div id="mobileLookupResult"></div>
      </div>

    </div>
  </div>

  <!-- STEP 2: Enter Amount -->
  <div id="step2" class="hidden">
    <div class="flow-header">
      <button class="back-btn" onclick="goToStep(1)" style="background:none;border:none;"><i class="fa-solid fa-arrow-left"></i></button>
      <h2>Send Money</h2>
    </div>
    <div class="flow-body">
      <div class="contact-item" style="border:none;">
        <div class="avatar" id="recipientAvatar"></div>
        <div class="info">
          <div class="cname" id="recipientName"></div>
          <div class="cmobile" id="recipientMobile"></div>
        </div>
      </div>

      <div class="amount-display">
        <input type="text" id="amountInput" inputmode="numeric" placeholder="₱0.00" autocomplete="off">
        <div class="avail">Available Balance: <strong><?= peso((float)$wallet['balance']) ?></strong></div>
      </div>

      <div class="quick-amounts">
        <button type="button" data-amt="100">₱100</button>
        <button type="button" data-amt="500">₱500</button>
        <button type="button" data-amt="1000">₱1,000</button>
        <button type="button" data-amt="2000">₱2,000</button>
      </div>

      <div class="field">
        <label>Add a note (optional)</label>
        <div class="input-wrap">
          <input type="text" id="noteInput" placeholder="What's this for?" maxlength="50">
        </div>
      </div>

      <div id="amountError"></div>
      <button class="btn btn-primary" id="toReviewBtn">Next</button>
    </div>
  </div>

  <!-- STEP 3: Review Details -->
  <div id="step3" class="hidden">
    <div class="flow-header">
      <button class="back-btn" onclick="goToStep(2)" style="background:none;border:none;"><i class="fa-solid fa-arrow-left"></i></button>
      <h2>Send Money</h2>
    </div>
    <div class="flow-body">
      <div class="card">
        <p style="color:var(--text-muted);font-size:12.5px;margin:0 0 4px;">You are sending</p>
        <p style="font-size:26px;font-weight:800;color:var(--rbnw-blue);margin:0 0 14px;" id="reviewAmount">₱0.00</p>
        <div class="review-row"><span class="label">To</span><span id="reviewName"></span></div>
        <div class="review-row"><span class="label">Note</span><span id="reviewNote">—</span></div>
        <div class="review-row"><span class="label">Transaction Fee</span><span>₱0.00</span></div>
        <div class="review-row"><span class="label">Total Amount</span><span id="reviewTotal">₱0.00</span></div>
      </div>
      <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i> Please review the details before confirming your transaction.</div>
      <button class="btn btn-primary" id="toPinBtn">Confirm</button>
    </div>
  </div>

  <!-- STEP 4: Enter MPIN -->
  <div id="step4" class="hidden">
    <div class="flow-header">
      <button class="back-btn" onclick="goToStep(3)" style="background:none;border:none;"><i class="fa-solid fa-arrow-left"></i></button>
      <h2>Enter MPIN</h2>
    </div>
    <div class="flow-body">
      <p style="text-align:center;color:var(--text-muted);font-size:13.5px;">Confirm the transaction with your MPIN</p>
      <div class="pin-dots" id="pinDots">
        <div class="dot"></div><div class="dot"></div><div class="dot"></div>
        <div class="dot"></div><div class="dot"></div><div class="dot"></div>
      </div>
      <div id="pinError" style="text-align:center;"></div>
      <p style="text-align:center;"><a href="forgot-mpin.php" style="color:var(--rbnw-blue);font-size:12.5px;font-weight:700;">Forgot MPIN?</a></p>
      <div class="pin-keypad" id="pinKeypad">
        <button data-k="1">1</button><button data-k="2">2</button><button data-k="3">3</button>
        <button data-k="4">4</button><button data-k="5">5</button><button data-k="6">6</button>
        <button data-k="7">7</button><button data-k="8">8</button><button data-k="9">9</button>
        <button class="empty"></button><button data-k="0">0</button><button data-k="del"><i class="fa-solid fa-delete-left"></i></button>
      </div>
    </div>
  </div>

  <!-- STEP 5: Sending -->
  <div id="step5" class="hidden">
    <div class="flow-body" style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:70vh;">
      <div class="spinner" style="width:44px;height:44px;border-width:4px;border-color:rgba(15,76,146,0.2);border-top-color:var(--rbnw-blue);"></div>
      <p style="margin-top:18px;color:var(--text-muted);">Please wait while we process your payment...</p>
    </div>
  </div>

  <!-- STEP 6: Success -->
  <div id="step6" class="hidden">
    <div class="success-screen">
      <div class="success-check"><i class="fa-solid fa-check"></i></div>
      <h2>Money Sent Successfully!</h2>
      <p style="color:var(--text-muted);font-size:13.5px;">You have sent</p>
      <div class="amt" id="successAmount">₱0.00</div>
      <div class="avatar" id="successAvatar" style="margin:0 auto 8px;"></div>
      <p style="font-weight:700;margin:0;" id="successName"></p>
      <p style="color:var(--text-muted);font-size:12.5px;" id="successMobile"></p>
      <p style="color:var(--text-muted);font-size:11.5px;margin-top:10px;">Transaction ID: <span id="successRef"></span></p>

      <div style="margin-top:24px;display:flex;flex-direction:column;gap:10px;">
        <button class="btn btn-outline" onclick="window.print()">View Receipt</button>
        <button class="btn btn-primary" onclick="location.href='dashboard.php'">Done</button>
      </div>
    </div>
  </div>

</div>

<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script>
const state = { recipient: null, amount: 0, note: '', pin: '' };

function goToStep(n) {
  for (let i = 1; i <= 6; i++) document.getElementById('step' + i).classList.add('hidden');
  document.getElementById('step' + n).classList.remove('hidden');
}

function initials(name) {
  return name.split(' ').filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase();
}

/* ── Step 1 tabs: To Contacts / To Mobile Number ── */
function switchRecipientTab(tab) {
  document.getElementById('tab-contacts').classList.toggle('active', tab === 'contacts');
  document.getElementById('tab-contacts').setAttribute('aria-selected', tab === 'contacts');
  document.getElementById('tab-mobile').classList.toggle('active', tab === 'mobile');
  document.getElementById('tab-mobile').setAttribute('aria-selected', tab === 'mobile');
  document.getElementById('tabPanel-contacts').classList.toggle('hidden', tab !== 'contacts');
  document.getElementById('tabPanel-mobile').classList.toggle('hidden', tab !== 'mobile');
}

let mobileLookupTimer;
document.getElementById('mobileNumInput').addEventListener('input', (e) => {
  e.target.value = e.target.value.replace(/[^0-9]/g, '').slice(0, 10);
  clearTimeout(mobileLookupTimer);
  const val = e.target.value;
  const resultBox = document.getElementById('mobileLookupResult');
  if (val.length < 10) { resultBox.innerHTML = ''; return; }
  resultBox.innerHTML = `<div class="empty-state" style="padding:16px 0;"><div class="spinner" style="margin:0 auto 8px;"></div>Looking up number...</div>`;
  mobileLookupTimer = setTimeout(() => lookupMobile('0' + val), 400);
});

async function lookupMobile(mobile) {
  const res = await RBNW.api('send-money.php?action=lookup&mobile=' + encodeURIComponent(mobile));
  renderMobileLookupResult(res);
  return res;
}

function renderMobileLookupResult(res) {
  const resultBox = document.getElementById('mobileLookupResult');
  if (res.found) {
    const recipient = { user_id: res.user_id, name: res.name, mobile_number: res.mobile_number };
    resultBox.innerHTML = `
      <div class="contact-item" style="cursor:pointer;margin-top:12px;" onclick='selectRecipient(${JSON.stringify(recipient)})'>
        <div class="avatar">${initials(res.name)}</div>
        <div class="info">
          <div class="cname">${res.name}${res.verified ? ' <i class="fa-solid fa-circle-check" style="color:var(--success);font-size:11px;"></i>' : ''}</div>
          <div class="cmobile">${res.mobile_number}</div>
        </div>
      </div>`;
  } else {
    resultBox.innerHTML = `<div class="alert alert-error" style="margin-top:12px;">${res.message || 'Recipient not found.'}</div>`;
  }
}

/* ── Prefill from Scan QR (send-money.php?mobile=...&name=...) ── */
(async function tryPrefillFromScan() {
  const params = new URLSearchParams(window.location.search);
  const mobileParam = params.get('mobile');
  if (!mobileParam) return;

  switchRecipientTab('mobile');
  const digits = mobileParam.replace(/^\+?63/, '0').replace(/^0/, '');
  document.getElementById('mobileNumInput').value = digits.slice(0, 10);

  const res = await lookupMobile(mobileParam);
  if (res.found) {
    selectRecipient({ user_id: res.user_id, name: res.name, mobile_number: res.mobile_number });
  }
})();

function renderRecipients(list) {
  const box = document.getElementById('recipientList');
  if (!list.length) {
    box.innerHTML = `<div class="empty-state"><div class="icon"><i class="fa-regular fa-address-book"></i></div>No results. Try a different name or mobile number.</div>`;
    return;
  }
  box.innerHTML = list.map(r => `
    <div class="contact-item" style="cursor:pointer;" onclick='selectRecipient(${JSON.stringify(r)})'>
      <div class="avatar">${initials(r.name)}</div>
      <div class="info">
        <div class="cname">${r.name}</div>
        <div class="cmobile">${r.mobile_number}</div>
      </div>
    </div>
  `).join('');
}

async function loadRecipients(q = '') {
  const res = await RBNW.api('send-money.php?action=search&q=' + encodeURIComponent(q));
  document.getElementById('recipientListLabel').querySelector('h3').textContent = q ? 'Search Results' : 'Frequently Sent';
  if (res.success) renderRecipients(res.results);
}
loadRecipients();

let searchTimer;
document.getElementById('searchInput').addEventListener('input', (e) => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => loadRecipients(e.target.value.trim()), 300);
});

function selectRecipient(r) {
  state.recipient = r;
  document.getElementById('recipientAvatar').textContent = initials(r.name);
  document.getElementById('recipientName').textContent = r.name;
  document.getElementById('recipientMobile').textContent = r.mobile_number;
  document.getElementById('amountInput').value = '';
  document.getElementById('amountError').innerHTML = '';
  goToStep(2);
}

document.querySelectorAll('.quick-amounts button').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.quick-amounts button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('amountInput').value = btn.dataset.amt;
  });
});

document.getElementById('amountInput').addEventListener('input', (e) => {
  e.target.value = e.target.value.replace(/[^0-9.]/g, '');
});

document.getElementById('toReviewBtn').addEventListener('click', () => {
  const amt = parseFloat(document.getElementById('amountInput').value || '0');
  const errBox = document.getElementById('amountError');
  const available = <?= (float)$wallet['balance'] ?>;

  if (!amt || amt <= 0) {
    errBox.innerHTML = `<div class="alert alert-error">Please enter a valid amount.</div>`;
    return;
  }
  if (amt > available) {
    errBox.innerHTML = `<div class="alert alert-error">Amount exceeds your available balance.</div>`;
    return;
  }
  errBox.innerHTML = '';
  state.amount = amt;
  state.note = document.getElementById('noteInput').value.trim();

  document.getElementById('reviewAmount').textContent = RBNW.peso(amt);
  document.getElementById('reviewName').textContent = state.recipient.name;
  document.getElementById('reviewNote').textContent = state.note || '—';
  document.getElementById('reviewTotal').textContent = RBNW.peso(amt);
  goToStep(3);
});

document.getElementById('toPinBtn').addEventListener('click', () => {
  state.pin = '';
  updatePinDots();
  document.getElementById('pinError').innerHTML = '';
  goToStep(4);
});

function updatePinDots() {
  document.querySelectorAll('#pinDots .dot').forEach((d, i) => {
    d.classList.toggle('filled', i < state.pin.length);
  });
}

document.getElementById('pinKeypad').addEventListener('click', async (e) => {
  const btn = e.target.closest('button');
  if (!btn || btn.classList.contains('empty')) return;
  const k = btn.dataset.k;

  if (k === 'del') {
    state.pin = state.pin.slice(0, -1);
    updatePinDots();
    return;
  }
  if (state.pin.length >= 6) return;
  state.pin += k;
  updatePinDots();

  if (state.pin.length === 6) {
    goToStep(5);
    const res = await RBNW.api('send-money.php?action=execute', 'POST', {
      mobile_number: state.recipient.mobile_number,
      amount: state.amount,
      note: state.note,
      mpin: state.pin,
    });

    if (res.success) {
      document.getElementById('successAmount').textContent = res.amount_formatted;
      document.getElementById('successAvatar').textContent = initials(res.recipient_name);
      document.getElementById('successName').textContent = res.recipient_name;
      document.getElementById('successMobile').textContent = res.recipient_mobile;
      document.getElementById('successRef').textContent = res.reference_no;
      goToStep(6);
    } else {
      goToStep(4);
      state.pin = '';
      updatePinDots();
      document.getElementById('pinError').innerHTML = `<div class="alert alert-error">${res.message || 'Transaction failed.'}</div>`;
    }
  }
});
</script>
</body>
</html>
