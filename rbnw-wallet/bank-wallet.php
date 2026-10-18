<?php
require_once __DIR__ . '/includes/bank.php';
require_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<title>Bank Wallet — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
<?php include __DIR__ . '/includes/pwa-head.php'; ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
.bw-body { padding: 16px 16px calc(110px + env(safe-area-inset-bottom)); }
.bw-hero {
  position: relative; overflow: hidden; isolation: isolate;
  background: var(--rbnw-gradient); color: #fff; border-radius: var(--radius-lg);
  padding: 20px; box-shadow: var(--shadow-card);
}
.bw-hero::before {
  content: ''; position: absolute; right: -14px; top: 50%; width: 170px; height: 150px;
  transform: translateY(-50%) rotate(-10deg);
  background: url('<?= asset_url('assets/img/rbnw-r-white.png') ?>') center / contain no-repeat;
  opacity: .09; z-index: -1;
}
.bw-hero small { font-size: 12.5px; opacity: .85; }
.bw-hero .big { font-size: 30px; font-weight: 800; margin: 4px 0 14px; }
.bw-hero .btn { background: #fff; color: var(--rbnw-blue); box-shadow: none; }

.bw-title { margin: 22px 2px 10px; font-size: 14px; font-weight: 700; display: flex; justify-content: space-between; align-items: center; }
.bw-title button { background: none; border: none; color: var(--rbnw-blue); font-weight: 700; font-size: 13px; padding: 4px 0; }

.bank-logo {
  width: 44px; height: 44px; border-radius: 13px; flex-shrink: 0;
  background: linear-gradient(160deg, #fff 0%, var(--rbnw-blue-50) 100%); border: 1px solid #dbe6f5;
  display: flex; align-items: center; justify-content: center;
  font-size: 11.5px; font-weight: 800; color: var(--rbnw-blue); letter-spacing: .2px; text-align: center; line-height: 1.05;
  overflow: hidden;
}
.bank-logo.rbnw { background: var(--rbnw-blue-50); padding: 7px; }
.bank-logo img { width: 100%; height: 100%; object-fit: contain; }
.acct-card { display: flex; align-items: center; gap: 12px; width: 100%; text-align: left; background: #fff; border: none; border-radius: var(--radius-md); box-shadow: var(--shadow-soft); padding: 14px; margin-bottom: 10px; color: inherit; }
.acct-card .info { flex: 1; min-width: 0; }
.acct-card .info strong { display: block; font-size: 14px; }
.acct-card .info small { font-size: 12px; color: var(--text-muted); }
.primary-tag { font-size: 10.5px; font-weight: 700; padding: 3px 8px; border-radius: 999px; background: var(--success-50); color: #1b7a3b; margin-left: 6px; vertical-align: 1px; }
.empty-box { text-align: center; padding: 22px 14px; background: #fff; border-radius: var(--radius-md); border: 1.5px dashed var(--border-strong); color: var(--text-muted); font-size: 13px; }
.empty-box .btn { width: auto; display: inline-flex; margin-top: 12px; }

.tr-row { display: flex; align-items: center; gap: 12px; padding: 11px 0; border-bottom: 1px solid var(--border); }
.tr-row:last-child { border-bottom: none; }
.tr-row .info { flex: 1; min-width: 0; font-size: 13.5px; }
.tr-row .info small { display: block; font-size: 11.5px; color: var(--text-muted); }
.tr-row .amt { text-align: right; font-weight: 700; font-size: 13.5px; }
.st { display: inline-block; margin-top: 3px; font-size: 10.5px; font-weight: 700; padding: 2px 8px; border-radius: 999px; }
.st.processing { background: #fdf3d6; color: #8a5a00; }
.st.completed { background: var(--success-50); color: #1b7a3b; }
.st.failed { background: var(--danger-50); color: #b42d28; }
.fine { font-size: 11.5px; color: var(--text-muted); line-height: 1.45; margin: 12px 2px 0; }

/* bank picker */
.bank-search { margin-bottom: 10px; }
.bank-list { max-height: 46vh; overflow-y: auto; margin: 0 -4px 6px; }
.bank-opt { display: flex; align-items: center; gap: 12px; width: 100%; padding: 9px 4px; background: none; border: none; text-align: left; color: inherit; border-radius: 10px; }
.bank-opt:active { background: var(--bg); }
.bank-opt .bank-logo { width: 38px; height: 38px; font-size: 10.5px; }
.bank-opt span { font-size: 14px; font-weight: 600; }
.bank-opt small { display: block; font-size: 11.5px; color: var(--text-muted); font-weight: 500; }
.picked-bank { display: flex; align-items: center; gap: 12px; padding: 10px 12px; background: var(--bg); border-radius: var(--radius-sm); margin-bottom: 14px; }
.picked-bank .grow { flex: 1; font-weight: 700; font-size: 14px; }
.picked-bank button { background: none; border: none; color: var(--rbnw-blue); font-weight: 700; font-size: 12.5px; }

/* transfer */
.to-acct { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border: 1.5px solid var(--border); border-radius: var(--radius-sm); margin-bottom: 14px; width: 100%; background: #fff; text-align: left; color: inherit; }
.to-acct .grow { flex: 1; min-width: 0; font-size: 14px; font-weight: 700; }
.to-acct .grow small { display: block; font-size: 12px; color: var(--text-muted); font-weight: 500; }
.channels { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 14px; }
.channels button { text-align: left; padding: 10px 12px; border-radius: var(--radius-sm); border: 1.5px solid var(--border); background: #fff; color: inherit; }
.channels button strong { display: block; font-size: 13.5px; }
.channels button small { font-size: 11.5px; color: var(--text-muted); }
.channels button.active { border-color: var(--rbnw-blue); box-shadow: 0 0 0 3px rgba(15,76,146,.1); }
.sum-row { display: flex; justify-content: space-between; font-size: 13px; padding: 4px 2px; color: var(--text-muted); }
.sum-row strong { color: var(--text-dark); }
.sheet .pin-input { max-width: 320px; margin: 6px auto 0; }
</style>
</head>
<body>
<div class="app-shell">
  <div class="flow-header">
    <button class="back-btn" onclick="RBNW.goBack('dashboard.php')" style="background:none;border:none;" aria-label="Back"><i class="fa-solid fa-arrow-left"></i></button>
    <h2>Bank Wallet</h2>
  </div>
  <div class="bw-body" id="body">
    <div class="empty-state"><span class="spinner" style="border-color:rgba(15,76,146,.2);border-top-color:var(--rbnw-blue);display:inline-block"></span></div>
  </div>
  <?php $activeNav = 'home'; include __DIR__ . '/includes/bottom-nav.php'; ?>
</div>

<div class="sheet-backdrop" id="backdrop"></div>

<!-- Link account -->
<div class="sheet" id="linkSheet" role="dialog" aria-modal="true" aria-labelledby="linkTitle">
  <div class="grab"></div>
  <h3 id="linkTitle">Link an account</h3>
  <p class="sub">Add a bank or e-wallet account you own to send money to it.</p>
  <div id="bankPick">
    <div class="input-wrap bank-search"><i class="icon fa-solid fa-magnifying-glass"></i><input type="search" id="bankSearch" placeholder="Search bank or e-wallet" autocomplete="off"></div>
    <div class="bank-list" id="bankList"></div>
  </div>
  <div id="bankForm" class="hidden">
    <div class="picked-bank" id="pickedBank"></div>
    <div class="field">
      <label for="acctName">Account name</label>
      <div class="input-wrap"><i class="icon fa-solid fa-user"></i><input type="text" id="acctName" autocomplete="name" maxlength="150"></div>
    </div>
    <div class="field">
      <label for="acctNumber" id="acctNumberLabel">Account number</label>
      <div class="input-wrap"><i class="icon fa-solid fa-hashtag"></i><input type="text" id="acctNumber" inputmode="numeric" autocomplete="off" maxlength="20"></div>
    </div>
    <div class="field">
      <label for="acctNumber2" id="acctNumber2Label">Confirm account number</label>
      <div class="input-wrap"><i class="icon fa-solid fa-hashtag"></i><input type="text" id="acctNumber2" inputmode="numeric" autocomplete="off" maxlength="20"></div>
    </div>
    <div id="linkError"></div>
    <button class="btn btn-primary" id="linkBtn">Link account</button>
  </div>
</div>

<!-- Account options -->
<div class="sheet" id="acctSheet" role="dialog" aria-modal="true" aria-labelledby="acctTitle">
  <div class="grab"></div>
  <h3 id="acctTitle"></h3>
  <p class="sub" id="acctSub"></p>
  <div style="display:flex;flex-direction:column;gap:10px">
    <button class="btn btn-primary" id="acctSend">Transfer to this account</button>
    <button class="btn btn-outline" id="acctPrimary">Set as primary</button>
    <button class="btn-danger-text" id="acctUnlink">Unlink account</button>
  </div>
</div>

<!-- Transfer -->
<div class="sheet" id="sendSheet" role="dialog" aria-modal="true" aria-labelledby="sendTitle">
  <div class="grab"></div>
  <div id="sendForm">
    <h3 id="sendTitle">Transfer to bank</h3>
    <p class="sub">From your RBNW Wallet</p>
    <button type="button" class="to-acct" id="toAcct"></button>
    <div class="amt-input"><span>₱</span><input type="text" id="sendAmount" inputmode="decimal" placeholder="0" autocomplete="off" aria-label="Amount"></div>
    <div class="avail" id="sendAvail"></div>
    <div class="channels" id="channels"></div>
    <div id="sendSummary"></div>
    <div id="sendError"></div>
    <button class="btn btn-primary" id="sendNext" style="margin-top:12px">Continue</button>
  </div>
  <div id="sendPin" class="hidden" style="text-align:center">
    <h3>Enter MPIN</h3>
    <p class="sub" id="pinSub"></p>
    <div class="pin-input" id="pinBoxes">
      <input type="password" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="off" aria-label="6-digit MPIN">
      <div class="pin-boxes" aria-hidden="true">
        <span class="pin-box"></span><span class="pin-box"></span><span class="pin-box"></span>
        <span class="pin-box"></span><span class="pin-box"></span><span class="pin-box"></span>
      </div>
    </div>
    <div id="pinError" style="margin-top:14px"></div>
    <button class="btn-danger-text" style="color:var(--rbnw-blue)" onclick="showSendForm()">Back</button>
  </div>
  <div id="sendDone" class="hidden" style="text-align:center;padding:10px 0 4px">
    <div class="success-check" style="width:70px;height:70px;font-size:32px"><i class="fa-solid fa-check"></i></div>
    <h3 id="doneTitle">Transfer submitted</h3>
    <p class="sub" id="doneSub"></p>
    <button class="btn btn-primary" onclick="closeSheets(); load();">Done</button>
  </div>
</div>

<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script>
const esc = RBNW.escapeHtml, peso = RBNW.peso;
const RBNW_MARK = <?= json_encode(asset_url('assets/img/rbnw-r.png')) ?>;
let D = null, picked = null, T = { acct: null, channel: 'instapay' };

function logo(code, short) {
  if (code === 'RBNW') return `<span class="bank-logo rbnw"><img src="${RBNW_MARK}" alt=""></span>`;
  const label = short.length > 6 ? short.slice(0, 2).toUpperCase() : short;
  return `<span class="bank-logo">${esc(label)}</span>`;
}

async function load() {
  const res = await RBNW.api('bank.php?action=summary');
  const body = document.getElementById('body');
  if (!res.success) { body.innerHTML = RBNW.alertHtml(res.message || 'Could not load Bank Wallet.', 'error'); return; }
  D = res;
  let html = `
    <div class="bw-hero">
      <small>Available to transfer</small>
      <div class="big">${peso(res.wallet_balance)}</div>
      <button class="btn" onclick="openSend()" ${res.accounts.length ? '' : 'disabled'}><i class="fa-solid fa-building-columns"></i> Transfer to bank</button>
    </div>
    <div class="bw-title"><span>Linked accounts</span>${res.accounts.length < res.limits.max_linked ? '<button onclick="openLink()"><i class="fa-solid fa-plus"></i> Link account</button>' : ''}</div>`;
  html += res.accounts.length ? res.accounts.map(a => `
    <button class="acct-card" onclick="openAcct(${a.id})">
      ${logo(a.bank_code, a.short)}
      <span class="info"><strong>${esc(a.short)} ${esc(a.masked)}${a.is_primary ? '<span class="primary-tag">Primary</span>' : ''}</strong><small>${esc(a.account_name)}</small></span>
      <i class="fa-solid fa-ellipsis-vertical" style="color:var(--text-faint)"></i>
    </button>`).join('')
    : `<div class="empty-box">Link your BDO, BPI, Landbank, GCash, Maya or other accounts to transfer money from your wallet.<br>
        <button class="btn btn-primary btn-sm" onclick="openLink()"><i class="fa-solid fa-plus"></i> Link an account</button></div>`;

  html += `<div class="bw-title"><span>Recent transfers</span></div><div class="card">`;
  html += res.transfers.length ? res.transfers.map(t => `
    <div class="tr-row">
      <span class="info">${esc(t.bank_name)} ${esc(t.masked)}<small>${esc(t.channel === 'internal' ? 'RBNW transfer' : t.channel === 'pesonet' ? 'PESONet' : 'InstaPay')} · ${esc(t.when)}${t.reason ? ' · ' + esc(t.reason) : ''}</small></span>
      <span class="amt">${peso(t.amount)}<br><span class="st ${esc(t.status)}">${t.status === 'processing' ? 'Processing' : t.status === 'completed' ? 'Completed' : 'Failed · refunded'}</span></span>
    </div>`).join('') : '<div class="empty-state" style="padding:24px 10px">No bank transfers yet.</div>';
  html += `</div>
    <p class="fine">InstaPay: up to ${peso(res.limits.instapay_max)} per transfer, ${peso(res.limits.instapay_fee)} fee, usually within minutes.
    PESONet: up to ${peso(res.limits.pesonet_max)}, ${res.limits.pesonet_fee ? peso(res.limits.pesonet_fee) + ' fee' : 'no fee'}, next banking day.
    Transfers to RBNW accounts are free. Failed transfers are refunded to your wallet.</p>`;
  body.innerHTML = html;
}

// ---------- sheets ----------
const backdrop = document.getElementById('backdrop');
function openSheet(id) {
  document.querySelectorAll('.sheet').forEach(s => s.classList.toggle('open', s.id === id));
  backdrop.classList.add('open');
}
function closeSheets() {
  document.querySelectorAll('.sheet').forEach(s => s.classList.remove('open'));
  backdrop.classList.remove('open');
}
backdrop.onclick = closeSheets;

// ---------- link ----------
function openLink() {
  picked = null;
  document.getElementById('bankPick').classList.remove('hidden');
  document.getElementById('bankForm').classList.add('hidden');
  document.getElementById('bankSearch').value = '';
  renderBanks('');
  openSheet('linkSheet');
}
function renderBanks(q) {
  q = q.toLowerCase();
  const list = D.banks.filter(b => !q || (b.name + ' ' + b.short).toLowerCase().includes(q));
  document.getElementById('bankList').innerHTML = list.map(b => `
    <button class="bank-opt" onclick="pickBank('${b.code}')">${logo(b.code, b.short)}
      <span>${esc(b.short)}<small>${esc(b.type === 'ewallet' ? 'E-wallet' : b.name)}</small></span></button>`).join('')
    || '<div class="empty-state" style="padding:20px 8px">No match.</div>';
}
document.getElementById('bankSearch').addEventListener('input', e => renderBanks(e.target.value));

function pickBank(code) {
  picked = D.banks.find(b => b.code === code);
  const ew = picked.type === 'ewallet';
  document.getElementById('pickedBank').innerHTML = `${logo(picked.code, picked.short)}<span class="grow">${esc(picked.short)}</span><button type="button" onclick="openLink()">Change</button>`;
  document.getElementById('acctNumberLabel').textContent = ew ? `${picked.short} mobile number` : 'Account number';
  document.getElementById('acctNumber2Label').textContent = ew ? 'Confirm mobile number' : 'Confirm account number';
  ['acctNumber', 'acctNumber2'].forEach(id => { const el = document.getElementById(id); el.value = ''; el.placeholder = ew ? '09XX XXX XXXX' : ''; });
  document.getElementById('acctName').value = '';
  document.getElementById('linkError').innerHTML = '';
  document.getElementById('bankPick').classList.add('hidden');
  document.getElementById('bankForm').classList.remove('hidden');
  setTimeout(() => document.getElementById('acctName').focus(), 50);
}
['acctNumber', 'acctNumber2'].forEach(id => document.getElementById(id).addEventListener('input', e => { e.target.value = RBNW.digitsOnly(e.target.value); }));

document.getElementById('linkBtn').onclick = async (e) => {
  const err = document.getElementById('linkError');
  const n1 = document.getElementById('acctNumber').value, n2 = document.getElementById('acctNumber2').value;
  if (n1 !== n2) { err.innerHTML = RBNW.alertHtml('Account numbers do not match.', 'error'); return; }
  const btn = e.currentTarget; btn.disabled = true;
  const res = await RBNW.api('bank.php?action=link', 'POST', {
    bank_code: picked.code, account_name: document.getElementById('acctName').value, account_number: n1,
  });
  btn.disabled = false;
  if (!res.success) { err.innerHTML = RBNW.alertHtml(res.message, 'error'); return; }
  closeSheets();
  RBNW.toast(`${picked.short} account linked`, 'success');
  load();
};

// ---------- account options ----------
function openAcct(id) {
  const a = D.accounts.find(x => x.id === id);
  document.getElementById('acctTitle').textContent = `${a.short} ${a.masked}`;
  document.getElementById('acctSub').textContent = a.account_name + (a.is_primary ? ' · Primary account' : '');
  document.getElementById('acctPrimary').classList.toggle('hidden', a.is_primary);
  document.getElementById('acctSend').onclick = () => openSend(id);
  document.getElementById('acctPrimary').onclick = async () => {
    const res = await RBNW.api('bank.php?action=set_primary', 'POST', { id });
    closeSheets(); RBNW.toast(res.success ? 'Primary account updated' : res.message, res.success ? 'success' : 'error'); load();
  };
  document.getElementById('acctUnlink').onclick = async () => {
    if (!confirm(`Unlink ${a.short} ${a.masked}?`)) return;
    const res = await RBNW.api('bank.php?action=unlink', 'POST', { id });
    closeSheets(); RBNW.toast(res.success ? 'Account unlinked' : res.message, res.success ? 'info' : 'error'); load();
  };
  openSheet('acctSheet');
}

// ---------- transfer ----------
const amtEl = document.getElementById('sendAmount');
function fit() { amtEl.style.width = Math.max(amtEl.value.length || 1, 1) + 0.6 + 'ch'; }
amtEl.addEventListener('input', () => {
  let v = amtEl.value.replace(/[^0-9.]/g, '');
  const [w, ...r] = v.split('.');
  amtEl.value = r.length ? w + '.' + r.join('').slice(0, 2) : w;
  fit(); renderSummary();
});

function openSend(acctId) {
  if (!D.accounts.length) { openLink(); return; }
  T.acct = D.accounts.find(a => a.id === acctId) || D.accounts.find(a => a.is_primary) || D.accounts[0];
  T.channel = 'instapay';
  amtEl.value = ''; fit();
  showSendForm();
  renderTo();
  openSheet('sendSheet');
}
function showSendForm() {
  document.getElementById('sendForm').classList.remove('hidden');
  document.getElementById('sendPin').classList.add('hidden');
  document.getElementById('sendDone').classList.add('hidden');
  document.getElementById('sendError').innerHTML = '';
}
function renderTo() {
  const a = T.acct;
  const to = document.getElementById('toAcct');
  to.innerHTML = `${logo(a.bank_code, a.short)}<span class="grow">${esc(a.short)} ${esc(a.masked)}<small>${esc(a.account_name)}</small></span>
    ${D.accounts.length > 1 ? '<i class="fa-solid fa-chevron-down" style="color:var(--text-faint)"></i>' : ''}`;
  to.onclick = () => {
    if (D.accounts.length < 2) return;
    const i = D.accounts.indexOf(T.acct);
    T.acct = D.accounts[(i + 1) % D.accounts.length];
    renderTo();
  };
  const L = D.limits, internal = a.bank_code === 'RBNW';
  document.getElementById('channels').innerHTML = internal
    ? `<button type="button" class="active" style="grid-column:1/-1"><strong>RBNW transfer</strong><small>Free · within the day</small></button>`
    : `<button type="button" data-ch="instapay"><strong>InstaPay</strong><small>${peso(L.instapay_fee)} · within minutes</small></button>
       <button type="button" data-ch="pesonet"><strong>PESONet</strong><small>${L.pesonet_fee ? peso(L.pesonet_fee) : 'Free'} · next banking day</small></button>`;
  document.querySelectorAll('#channels button[data-ch]').forEach(b => {
    b.classList.toggle('active', b.dataset.ch === T.channel);
    b.onclick = () => { T.channel = b.dataset.ch; renderTo(); };
  });
  document.getElementById('sendAvail').textContent = `Wallet balance: ${peso(D.wallet_balance)}`;
  renderSummary();
}
function feeNow() {
  if (T.acct.bank_code === 'RBNW') return 0;
  return T.channel === 'pesonet' ? D.limits.pesonet_fee : D.limits.instapay_fee;
}
function renderSummary() {
  const amt = parseFloat(amtEl.value || '0'), fee = feeNow();
  document.getElementById('sendSummary').innerHTML = amt ? `
    <div class="sum-row"><span>Transfer fee</span><strong>${fee ? peso(fee) : 'Free'}</strong></div>
    <div class="sum-row"><span>Total deducted from wallet</span><strong>${peso(amt + fee)}</strong></div>` : '';
}

document.getElementById('sendNext').onclick = () => {
  const amt = parseFloat(amtEl.value || '0'), fee = feeNow(), L = D.limits;
  const max = T.acct.bank_code === 'RBNW' || T.channel === 'pesonet' ? L.pesonet_max : L.instapay_max;
  const err = document.getElementById('sendError');
  if (amt < L.min) { err.innerHTML = RBNW.alertHtml(`Minimum transfer is ${peso(L.min)}.`, 'error'); return; }
  if (amt > max) { err.innerHTML = RBNW.alertHtml(`Maximum per transfer is ${peso(max)}.`, 'error'); return; }
  if (amt + fee > D.wallet_balance) { err.innerHTML = RBNW.alertHtml('Not enough wallet balance to cover the amount and fee.', 'error'); return; }
  document.getElementById('pinSub').textContent = `Send ${peso(amt)} to ${T.acct.short} ${T.acct.masked}${fee ? ' (+' + peso(fee) + ' fee)' : ''}`;
  document.getElementById('sendForm').classList.add('hidden');
  document.getElementById('sendPin').classList.remove('hidden');
  document.getElementById('pinError').innerHTML = '';
  pin.clear();
  setTimeout(() => pin.input.focus(), 50);
};

let busy = false;
const pin = RBNW.mountPinInput(document.getElementById('pinBoxes'), async (v) => {
  if (v.length !== 6 || busy) return;
  busy = true;
  const res = await RBNW.api('bank.php?action=transfer', 'POST', {
    account_id: T.acct.id, amount: parseFloat(amtEl.value), channel: T.channel, mpin: v,
  });
  busy = false;
  if (!res.success) { pin.clear(); document.getElementById('pinError').innerHTML = RBNW.alertHtml(res.message, 'error'); return; }
  document.getElementById('doneTitle').textContent = res.status === 'completed' ? 'Transfer sent!' : 'Transfer submitted';
  document.getElementById('doneSub').textContent = `${peso(res.amount)} to ${res.to} · ${res.eta}. Ref ${res.reference_no}`;
  document.getElementById('sendPin').classList.add('hidden');
  document.getElementById('sendDone').classList.remove('hidden');
});

load();
</script>
</body>
</html>
