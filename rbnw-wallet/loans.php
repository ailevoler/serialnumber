<?php
require_once __DIR__ . '/includes/loans.php';
require_login();
$wallet = get_wallet(current_user_id());
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<title>Loans — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
<?php include __DIR__ . '/includes/pwa-head.php'; ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
.loan-body { padding: 16px 16px calc(120px + env(safe-area-inset-bottom)); }
.loan-hero {
  position: relative; overflow: hidden; isolation: isolate;
  background: var(--rbnw-gradient); color: #fff; border-radius: var(--radius-lg);
  padding: 20px; box-shadow: var(--shadow-card);
}
.loan-hero::before {
  content: ''; position: absolute; right: -14px; top: 50%; width: 170px; height: 150px;
  transform: translateY(-50%) rotate(-10deg);
  background: url('<?= asset_url('assets/img/rbnw-r-white.png') ?>') center / contain no-repeat;
  opacity: .09; z-index: -1;
}
.loan-hero small { font-size: 12.5px; opacity: .85; }
.loan-hero .big { font-size: 32px; font-weight: 800; letter-spacing: -0.3px; margin: 2px 0 4px; }
.loan-hero .sub { font-size: 12.5px; opacity: .85; }
.loan-hero .btn { margin-top: 16px; background: #fff; color: var(--rbnw-blue); box-shadow: none; }
.loan-hero .progress { height: 8px; border-radius: 999px; background: rgba(255,255,255,.22); margin: 14px 0 6px; overflow: hidden; }
.loan-hero .progress span { display: block; height: 100%; border-radius: 999px; background: #7fc242; }
.loan-hero .hero-row { display: flex; justify-content: space-between; font-size: 12px; opacity: .9; }

.loan-features { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 16px 0 4px; }
.loan-features div {
  background: #fff; border-radius: var(--radius-md); padding: 12px 8px; text-align: center;
  box-shadow: var(--shadow-soft); font-size: 11.5px; font-weight: 600; color: var(--text-body); line-height: 1.3;
}
.loan-features i { display: block; font-size: 18px; color: var(--rbnw-blue); margin-bottom: 6px; }

.loan-section-title { margin: 22px 2px 10px; font-size: 14px; font-weight: 700; color: var(--text-dark); display: flex; justify-content: space-between; align-items: baseline; }
.loan-section-title small { font-size: 12px; font-weight: 500; color: var(--text-muted); }

.due-card { display: flex; align-items: center; gap: 12px; }
.due-card .ico { width: 44px; height: 44px; border-radius: 14px; background: var(--rbnw-blue-50); color: var(--rbnw-blue); display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.due-card .ico.overdue { background: var(--danger-50); color: var(--danger); }
.due-card .info { flex: 1; }
.due-card .info strong { display: block; font-size: 17px; }
.due-card .info small { font-size: 12.5px; color: var(--text-muted); }
.pay-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 14px; }

.sched-row { display: flex; align-items: center; gap: 12px; padding: 11px 0; border-bottom: 1px solid var(--border); font-size: 13.5px; }
.sched-row:last-child { border-bottom: none; }
.sched-row .no { width: 26px; height: 26px; border-radius: 50%; background: var(--bg); font-size: 11.5px; font-weight: 700; display: flex; align-items: center; justify-content: center; color: var(--text-muted); flex-shrink: 0; }
.sched-row .date { flex: 1; }
.sched-row .amt { font-weight: 700; }
.sched-row .tag { font-size: 10.5px; font-weight: 700; padding: 3px 8px; border-radius: 999px; margin-left: 8px; }
.tag.paid { background: var(--success-50); color: #1b7a3b; }
.tag.due { background: var(--rbnw-blue-50); color: var(--rbnw-blue); }
.tag.overdue { background: var(--danger-50); color: #b42d28; }
.sched-row.is-paid .no { background: var(--success-50); color: var(--success); }

.notice-card { text-align: center; padding: 24px 18px; }
.notice-card .ico { width: 60px; height: 60px; border-radius: 20px; margin: 0 auto 12px; background: var(--rbnw-blue-50); color: var(--rbnw-blue); display: flex; align-items: center; justify-content: center; font-size: 24px; }
.notice-card h3 { margin: 0 0 6px; font-size: 16px; }
.notice-card p { margin: 0 0 16px; font-size: 13px; color: var(--text-muted); line-height: 1.45; }

/* Apply */
.amount-card { background: #fff; border-radius: var(--radius-lg); padding: 18px; box-shadow: var(--shadow-card); text-align: center; }
.amount-card label { font-size: 13px; font-weight: 600; color: var(--text-muted); }
.amount-card .val { font-size: 36px; font-weight: 800; color: var(--text-dark); margin: 4px 0 12px; letter-spacing: -0.4px; }
.amount-card input[type=range] { width: 100%; accent-color: var(--rbnw-blue); height: 28px; }
.amount-card .range-ends { display: flex; justify-content: space-between; font-size: 11.5px; color: var(--text-muted); }
.chips { display: flex; flex-wrap: wrap; gap: 8px; }
.chips button {
  height: 40px; padding: 0 16px; border-radius: 999px; border: 1.5px solid var(--border);
  background: #fff; font-size: 13.5px; font-weight: 600; color: var(--text-body);
}
.chips button.active { background: var(--rbnw-blue); border-color: var(--rbnw-blue); color: #fff; }
.chips.terms { display: grid; grid-template-columns: repeat(4, 1fr); }
.chips.terms button { padding: 0; border-radius: var(--radius-sm); height: 52px; display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1.1; }
.chips.terms button small { font-size: 10.5px; font-weight: 500; opacity: .8; }

.quote-card .monthly { text-align: center; padding: 4px 0 12px; border-bottom: 1px dashed var(--border); margin-bottom: 4px; }
.quote-card .monthly small { font-size: 12px; color: var(--text-muted); }
.quote-card .monthly strong { display: block; font-size: 26px; color: var(--rbnw-blue); }
.review-row .val-strong { font-weight: 800; }
.fine { font-size: 11.5px; color: var(--text-muted); line-height: 1.45; margin: 10px 2px 0; }

.sticky-footer {
  position: fixed; bottom: 0; left: 50%; transform: translateX(-50%);
  width: 100%; max-width: var(--max-w);
  padding: 12px 16px calc(12px + env(safe-area-inset-bottom));
  background: linear-gradient(180deg, rgba(243,246,250,0) 0%, var(--bg) 35%); z-index: 20;
}
.pin-step { text-align: center; padding-top: 24px; }
.pin-step h3 { margin: 0 0 6px; font-size: 18px; }
.pin-step p { margin: 0 0 22px; color: var(--text-muted); font-size: 13.5px; }
.pin-step .pin-input { max-width: 320px; margin: 0 auto; }
</style>
</head>
<body>
<div class="app-shell">

  <!-- HOME -->
  <section id="vHome" class="view">
    <div class="flow-header">
      <button class="back-btn" onclick="RBNW.goBack('dashboard.php')" style="background:none;border:none;" aria-label="Back"><i class="fa-solid fa-arrow-left"></i></button>
      <h2>Loans</h2>
    </div>
    <div class="loan-body" id="homeBody">
      <div class="empty-state"><span class="spinner" style="border-color:rgba(15,76,146,.2);border-top-color:var(--rbnw-blue);display:inline-block"></span></div>
    </div>
    <?php $activeNav = 'home'; include __DIR__ . '/includes/bottom-nav.php'; ?>
  </section>

  <!-- APPLY -->
  <section id="vApply" class="view hidden">
    <div class="flow-header">
      <button class="back-btn" onclick="show('vHome')" style="background:none;border:none;" aria-label="Back"><i class="fa-solid fa-arrow-left"></i></button>
      <h2>Apply for a Loan</h2>
    </div>
    <div class="loan-body">
      <div class="amount-card">
        <label for="amountRange">How much do you need?</label>
        <div class="val" id="amountVal">₱0</div>
        <input type="range" id="amountRange" step="500">
        <div class="range-ends"><span id="minLbl"></span><span id="maxLbl"></span></div>
      </div>

      <div class="loan-section-title">Pay in</div>
      <div class="chips terms" id="termChips"></div>

      <div class="loan-section-title">Purpose</div>
      <div class="chips" id="purposeChips"></div>

      <div class="loan-section-title">Your loan</div>
      <div class="card quote-card" id="quoteCard">
        <div class="monthly"><small>Monthly payment</small><strong id="qMonthly">—</strong></div>
        <div class="review-row"><span class="label">You'll receive</span><span id="qNet" class="val-strong">—</span></div>
        <div class="review-row"><span class="label">Processing fee (<?= rtrim(rtrim(number_format(LOAN_PROCESSING_FEE_PCT, 2), '0'), '.') ?>%)</span><span id="qFee">—</span></div>
        <div class="review-row"><span class="label">Interest (<?= LOAN_MONTHLY_RATE_PCT ?>% / month)</span><span id="qInterest">—</span></div>
        <div class="review-row"><span class="label">Total amount to pay</span><span id="qTotal">—</span></div>
      </div>
      <div id="applyError"></div>
    </div>
    <div class="sticky-footer"><button class="btn btn-primary" id="toReviewBtn" disabled>Continue</button></div>
  </section>

  <!-- REVIEW / DISCLOSURE -->
  <section id="vReview" class="view hidden">
    <div class="flow-header">
      <button class="back-btn" onclick="show('vApply')" style="background:none;border:none;" aria-label="Back"><i class="fa-solid fa-arrow-left"></i></button>
      <h2>Review Loan</h2>
    </div>
    <div class="loan-body">
      <div class="loan-section-title" style="margin-top:4px">Disclosure statement</div>
      <div class="card" id="disclosure"></div>
      <div class="loan-section-title">Payment schedule</div>
      <div class="card" id="reviewSchedule"></div>
      <label class="checkbox-line" style="margin:16px 2px 0">
        <input type="checkbox" id="agree">
        <span>I have read and agree to the <a href="#">Disclosure Statement</a> and <a href="#">Loan Agreement</a>, and authorize RBNW to debit my wallet for payments.</span>
      </label>
      <div id="reviewError"></div>
    </div>
    <div class="sticky-footer"><button class="btn btn-primary" id="toPinBtn" disabled>Confirm with MPIN</button></div>
  </section>

  <!-- MPIN -->
  <section id="vPin" class="view hidden">
    <div class="flow-header">
      <button class="back-btn" id="pinBack" style="background:none;border:none;" aria-label="Back"><i class="fa-solid fa-arrow-left"></i></button>
      <h2>Enter MPIN</h2>
    </div>
    <div class="loan-body pin-step">
      <h3 id="pinTitle">Confirm your loan</h3>
      <p id="pinSub">Enter your 6-digit MPIN</p>
      <div class="pin-input" id="pinBoxes">
        <input type="password" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="off" aria-label="6-digit MPIN">
        <div class="pin-boxes" aria-hidden="true">
          <span class="pin-box"></span><span class="pin-box"></span><span class="pin-box"></span>
          <span class="pin-box"></span><span class="pin-box"></span><span class="pin-box"></span>
        </div>
      </div>
      <div id="pinError" style="margin-top:16px"></div>
    </div>
  </section>

  <!-- RESULT -->
  <section id="vDone" class="view hidden">
    <div class="success-screen">
      <div class="success-check"><i class="fa-solid fa-check"></i></div>
      <h2 id="doneTitle">Loan approved!</h2>
      <p style="color:var(--text-muted);font-size:13.5px;" id="doneSub"></p>
      <div class="amt" id="doneAmt"></div>
      <p style="color:var(--text-muted);font-size:12px;" id="doneRef"></p>
      <div style="margin-top:24px;display:flex;flex-direction:column;gap:10px;">
        <button class="btn btn-primary" onclick="location.href='dashboard.php'">Back to Home</button>
        <button class="btn btn-outline" onclick="location.reload()">View my loan</button>
      </div>
    </div>
  </section>
</div>

<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script>
const WALLET_BALANCE = <?= json_encode((float)$wallet['balance']) ?>;
const esc = RBNW.escapeHtml, peso = RBNW.peso;
const fmtDate = (d) => new Date(d + 'T00:00:00').toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });

let S = { summary: null, amount: 0, term: 0, purpose: '', quote: null, pinMode: null, payMode: null };

function show(id) {
  document.querySelectorAll('.view').forEach(v => v.classList.toggle('hidden', v.id !== id));
  window.scrollTo(0, 0);
}

// ---------- HOME ----------
async function loadHome() {
  const res = await RBNW.api('loans.php?action=summary');
  const body = document.getElementById('homeBody');
  if (!res.success) { body.innerHTML = RBNW.alertHtml(res.message || 'Could not load loans.', 'error'); return; }
  S.summary = res;

  let html = '';
  const L = res.active_loan;
  if (L) {
    const pct = Math.min(100, Math.round(L.amount_paid / L.total_payable * 100));
    html += `
      <div class="loan-hero">
        <small>Outstanding balance</small>
        <div class="big">${peso(L.outstanding)}</div>
        <div class="sub">Loan ${esc(L.reference_no)} · ${L.term_months} months · ${esc(L.purpose || '')}</div>
        <div class="progress"><span style="width:${pct}%"></span></div>
        <div class="hero-row"><span>Paid ${peso(L.amount_paid)}</span><span>${L.paid_installments} of ${L.term_months} payments</span></div>
      </div>`;
    if (L.next_due) {
      const od = L.next_due.overdue;
      html += `
      <div class="loan-section-title">Next payment ${od ? '<small style="color:var(--danger);font-weight:700">Overdue</small>' : ''}</div>
      <div class="card">
        <div class="due-card">
          <div class="ico ${od ? 'overdue' : ''}"><i class="fa-regular fa-calendar"></i></div>
          <div class="info"><strong>${peso(L.next_due.amount)}</strong><small>Due ${fmtDate(L.next_due.due_date)} · Payment ${L.next_due.installment_no} of ${L.term_months}</small></div>
        </div>
        <div class="pay-actions">
          <button class="btn btn-primary" onclick="startPay('next')">Pay now</button>
          <button class="btn btn-outline" onclick="startPay('full')">Pay in full</button>
        </div>
        <p class="fine">Wallet balance: ${peso(WALLET_BALANCE)}. Payments are deducted from your RBNW Wallet.</p>
      </div>`;
    }
    html += `<div class="loan-section-title">Payment schedule</div><div class="card">${scheduleHtml(L.schedule)}</div>`;
  } else if (!res.eligible && res.reason === 'kyc') {
    html += heroOffer(res, false) + `
      <div class="card notice-card" style="margin-top:16px">
        <div class="ico"><i class="fa-solid fa-id-card"></i></div>
        <h3>Verify your account to apply</h3>
        <p>Loans are available to verified RBNW Wallet users. Submit a valid ID to unlock your credit limit.</p>
        <button class="btn btn-primary" onclick="location.href='kyc.php'">Verify now</button>
      </div>`;
  } else {
    html += heroOffer(res, true);
  }

  if (!L) html += `
    <div class="loan-features">
      <div><i class="fa-solid fa-bolt"></i>Approved in minutes</div>
      <div><i class="fa-solid fa-shield-halved"></i>No collateral</div>
      <div><i class="fa-solid fa-calendar-check"></i>Pay monthly via wallet</div>
    </div>`;

  if (res.history.length) {
    html += `<div class="loan-section-title">Past loans</div><div class="card">` + res.history.map(h => `
      <div class="sched-row"><div class="date"><strong>${peso(+h.amount)}</strong> · ${h.term_months} months<br><small style="color:var(--text-muted)">${esc(h.reference_no)}</small></div>
      <span class="tag ${h.status === 'completed' ? 'paid' : 'due'}">${esc(h.status)}</span></div>`).join('') + `</div>`;
  }
  body.innerHTML = html;
}

function heroOffer(res, canApply) {
  return `
    <div class="loan-hero">
      <small>RBNW Loan · You can borrow up to</small>
      <div class="big">${peso(res.limit)}</div>
      <div class="sub">Get cash sent straight to your wallet. Pay in ${res.terms.join(', ')} months.</div>
      ${canApply ? '<button class="btn" onclick="startApply()">Apply now</button>' : ''}
    </div>`;
}

function scheduleHtml(rows) {
  const today = new Date().toISOString().slice(0, 10);
  return rows.map(r => {
    const st = r.status === 'paid' ? 'paid' : (r.due_date < today ? 'overdue' : 'due');
    return `<div class="sched-row ${st === 'paid' ? 'is-paid' : ''}">
      <span class="no">${st === 'paid' ? '<i class="fa-solid fa-check"></i>' : r.installment_no}</span>
      <span class="date">${fmtDate(r.due_date)}</span>
      <span class="amt">${peso(r.amount_due)}</span>
      ${r.status !== undefined ? `<span class="tag ${st}">${st === 'paid' ? 'Paid' : st === 'overdue' ? 'Overdue' : 'Due'}</span>` : ''}
    </div>`;
  }).join('');
}

// ---------- APPLY ----------
function startApply() {
  const s = S.summary;
  const range = document.getElementById('amountRange');
  range.min = s.min_amount; range.max = s.limit;
  range.value = S.amount || Math.min(s.limit, Math.max(s.min_amount, Math.round(s.limit / 2 / 500) * 500));
  document.getElementById('minLbl').textContent = peso(s.min_amount).replace('.00', '');
  document.getElementById('maxLbl').textContent = peso(s.limit).replace('.00', '');

  document.getElementById('termChips').innerHTML = s.terms.map(t =>
    `<button type="button" data-term="${t}">${t}<small>months</small></button>`).join('');
  document.getElementById('purposeChips').innerHTML = s.purposes.map(p =>
    `<button type="button" data-purpose="${esc(p)}">${esc(p)}</button>`).join('');

  S.term = S.term || s.terms[Math.min(1, s.terms.length - 1)];
  document.querySelectorAll('#termChips button').forEach(b => {
    b.classList.toggle('active', +b.dataset.term === S.term);
    b.onclick = () => { S.term = +b.dataset.term; document.querySelectorAll('#termChips button').forEach(x => x.classList.toggle('active', x === b)); requestQuote(); };
  });
  document.querySelectorAll('#purposeChips button').forEach(b => {
    b.classList.toggle('active', b.dataset.purpose === S.purpose);
    b.onclick = () => { S.purpose = b.dataset.purpose; document.querySelectorAll('#purposeChips button').forEach(x => x.classList.toggle('active', x === b)); updateContinue(); };
  });

  range.oninput = () => { onAmount(); requestQuote(); };
  onAmount();
  requestQuote();
  show('vApply');
}

function onAmount() {
  S.amount = +document.getElementById('amountRange').value;
  document.getElementById('amountVal').textContent = peso(S.amount).replace('.00', '');
}

let quoteTimer = null, quoteSeq = 0;
function requestQuote() {
  clearTimeout(quoteTimer);
  S.quote = null; updateContinue();
  quoteTimer = setTimeout(async () => {
    const seq = ++quoteSeq;
    const res = await RBNW.api('loans.php?action=quote', 'POST', { amount: S.amount, term: S.term });
    if (seq !== quoteSeq) return; // a newer request is in flight
    const err = document.getElementById('applyError');
    if (!res.success) { err.innerHTML = RBNW.alertHtml(res.message, 'error'); return; }
    err.innerHTML = '';
    S.quote = res.quote;
    const q = res.quote;
    document.getElementById('qMonthly').textContent = peso(q.monthly_amortization) + ' × ' + q.term_months;
    document.getElementById('qNet').textContent = peso(q.net_proceeds);
    document.getElementById('qFee').textContent = '− ' + peso(q.processing_fee);
    document.getElementById('qInterest').textContent = peso(q.total_interest);
    document.getElementById('qTotal').textContent = peso(q.total_payable);
    updateContinue();
  }, 200);
}

function updateContinue() {
  document.getElementById('toReviewBtn').disabled = !(S.quote && S.purpose);
}

document.getElementById('toReviewBtn').onclick = () => {
  const q = S.quote;
  const rows = [
    ['Loan amount (principal)', peso(q.amount)],
    ['Processing fee', '− ' + peso(q.processing_fee)],
    ['Net proceeds to your wallet', peso(q.net_proceeds)],
    ['Interest rate', q.monthly_rate_pct + '% per month (add-on)'],
    ['Total interest', peso(q.total_interest)],
    ['Finance charge (interest + fee)', peso(q.finance_charge)],
    ['Effective interest rate (EIR)', q.eir_monthly_pct + '% per month · ' + q.eir_annual_pct + '% per year'],
    ['Term', q.term_months + ' months · ' + esc(S.purpose)],
    ['Monthly payment', peso(q.monthly_amortization)],
    ['First payment due', fmtDate(q.first_due_date)],
    ['Total amount to pay', peso(q.total_payable)],
  ];
  document.getElementById('disclosure').innerHTML = rows.map(([l, v]) =>
    `<div class="review-row"><span class="label">${l}</span><span style="text-align:right">${v}</span></div>`).join('');
  document.getElementById('reviewSchedule').innerHTML = scheduleHtml(q.schedule.map(r => ({ ...r, status: undefined })));
  document.getElementById('agree').checked = false;
  document.getElementById('toPinBtn').disabled = true;
  show('vReview');
};
document.getElementById('agree').onchange = (e) => { document.getElementById('toPinBtn').disabled = !e.target.checked; };

// ---------- MPIN (apply + pay) ----------
const pin = RBNW.mountPinInput(document.getElementById('pinBoxes'), (v) => { if (v.length === 6) submitPin(v); });

function openPin(mode, title, sub, back) {
  S.pinMode = mode;
  document.getElementById('pinTitle').textContent = title;
  document.getElementById('pinSub').textContent = sub;
  document.getElementById('pinError').innerHTML = '';
  document.getElementById('pinBack').onclick = () => show(back);
  pin.clear();
  show('vPin');
  setTimeout(() => pin.input.focus(), 50);
}

document.getElementById('toPinBtn').onclick = () =>
  openPin('apply', 'Confirm your loan', `Enter your MPIN to receive ${peso(S.quote.net_proceeds)}`, 'vReview');

function startPay(mode) {
  const L = S.summary.active_loan;
  const amt = mode === 'full' ? L.outstanding : L.next_due.amount;
  if (amt > WALLET_BALANCE) {
    RBNW.toast(`Not enough balance. Cash In at least ${peso(amt - WALLET_BALANCE)} first.`, 'error');
    return;
  }
  S.payMode = mode;
  openPin('pay', mode === 'full' ? 'Pay loan in full' : 'Pay installment', `${peso(amt)} will be deducted from your wallet`, 'vHome');
}

let busy = false;
async function submitPin(mpin) {
  if (busy) return;
  busy = true;
  document.getElementById('pinError').innerHTML = '<span class="spinner" style="border-color:rgba(15,76,146,.2);border-top-color:var(--rbnw-blue);display:inline-block"></span>';
  const res = S.pinMode === 'apply'
    ? await RBNW.api('loans.php?action=apply', 'POST', { amount: S.amount, term: S.term, purpose: S.purpose, agreed: true, mpin })
    : await RBNW.api('loans.php?action=pay', 'POST', { mode: S.payMode, mpin });
  busy = false;

  if (!res.success) {
    pin.clear();
    document.getElementById('pinError').innerHTML = RBNW.alertHtml(res.message || 'Something went wrong.', 'error');
    return;
  }
  if (S.pinMode === 'apply') {
    document.getElementById('doneTitle').textContent = 'Loan approved!';
    document.getElementById('doneSub').textContent = 'Sent to your RBNW Wallet';
    document.getElementById('doneAmt').textContent = peso(res.net_proceeds);
    document.getElementById('doneRef').textContent = `Ref ${res.reference_no} · First payment of ${peso(res.monthly)} due ${fmtDate(res.first_due_date)}`;
  } else {
    document.getElementById('doneTitle').textContent = res.completed ? 'Loan fully paid!' : 'Payment successful';
    document.getElementById('doneSub').textContent = res.completed ? 'Thank you for paying on time.' : 'Your loan payment was received';
    document.getElementById('doneAmt').textContent = peso(res.amount);
    document.getElementById('doneRef').textContent = '';
  }
  show('vDone');
}

loadHome();
</script>
</body>
</html>
