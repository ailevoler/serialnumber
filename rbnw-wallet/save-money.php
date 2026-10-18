<?php
require_once __DIR__ . '/includes/savings.php';
require_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<title>Save Money — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
.sv-body { padding: 16px 16px calc(110px + env(safe-area-inset-bottom)); }
.sv-hero {
  position: relative; overflow: hidden; isolation: isolate;
  background: var(--rbnw-gradient); color: #fff; border-radius: var(--radius-lg);
  padding: 20px; box-shadow: var(--shadow-card);
}
.sv-hero::before {
  content: ''; position: absolute; right: -14px; top: 50%; width: 170px; height: 150px;
  transform: translateY(-50%) rotate(-10deg);
  background: url('<?= asset_url('assets/img/rbnw-r-white.png') ?>') center / contain no-repeat;
  opacity: .09; z-index: -1;
}
.sv-hero .top { display: flex; justify-content: space-between; align-items: center; font-size: 12.5px; opacity: .9; }
.sv-hero .rate { background: rgba(255,255,255,.2); padding: 3px 10px; border-radius: 999px; font-weight: 700; font-size: 11px; }
.sv-hero .big { font-size: 32px; font-weight: 800; letter-spacing: -0.3px; margin: 6px 0 2px; }
.sv-hero .earned { font-size: 12.5px; opacity: .9; }
.sv-hero .earned strong { color: #b8f08a; }
.sv-hero .acts { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 16px; }
.sv-hero .acts .btn { min-height: 46px; }
.sv-hero .btn-in { background: #fff; color: var(--rbnw-blue); }
.sv-hero .btn-out { background: rgba(255,255,255,.14); color: #fff; border: 1.5px solid rgba(255,255,255,.4); }

.sv-wallet { display: flex; align-items: center; gap: 10px; margin-top: 12px; padding: 12px 14px; background: #fff; border-radius: var(--radius-md); box-shadow: var(--shadow-soft); font-size: 13px; color: var(--text-muted); }
.sv-wallet i { color: var(--rbnw-blue); }
.sv-wallet strong { color: var(--text-dark); margin-left: auto; }

.sv-title { margin: 22px 2px 10px; font-size: 14px; font-weight: 700; display: flex; justify-content: space-between; align-items: center; }
.sv-title button { background: none; border: none; color: var(--rbnw-blue); font-weight: 700; font-size: 13px; padding: 4px 0; }

.goal-card { display: block; width: 100%; text-align: left; background: #fff; border: none; border-radius: var(--radius-md); box-shadow: var(--shadow-soft); padding: 14px; margin-bottom: 10px; color: inherit; }
.goal-card .row { display: flex; align-items: center; gap: 12px; }
.goal-card .ico { width: 42px; height: 42px; border-radius: 13px; background: var(--rbnw-blue-50); color: var(--rbnw-blue); display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; }
.goal-card .info { flex: 1; min-width: 0; }
.goal-card .info strong { display: block; font-size: 14px; }
.goal-card .info small { font-size: 12px; color: var(--text-muted); }
.goal-card .pct { font-size: 13px; font-weight: 800; color: var(--rbnw-blue); }
.goal-card .bar { height: 7px; border-radius: 999px; background: var(--bg); margin-top: 12px; overflow: hidden; }
.goal-card .bar span { display: block; height: 100%; background: linear-gradient(90deg, var(--rbnw-blue), var(--rbnw-green)); border-radius: 999px; }
.goal-empty { text-align: center; padding: 18px 14px; background: #fff; border-radius: var(--radius-md); border: 1.5px dashed var(--border-strong); color: var(--text-muted); font-size: 13px; }
.goal-empty button { margin-top: 10px; }

.act-row { display: flex; align-items: center; gap: 12px; padding: 11px 0; border-bottom: 1px solid var(--border); }
.act-row:last-child { border-bottom: none; }
.act-row .ico { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; flex-shrink: 0; }
.act-row .ico.in { background: var(--success-50); color: var(--success); }
.act-row .ico.out { background: var(--rbnw-blue-50); color: var(--rbnw-blue); }
.act-row .ico.int { background: #fdf3d6; color: #b7791f; }
.act-row .info { flex: 1; min-width: 0; font-size: 13.5px; }
.act-row .info small { display: block; font-size: 11.5px; color: var(--text-muted); }
.act-row .amt { font-weight: 700; font-size: 13.5px; }
.fine { font-size: 11.5px; color: var(--text-muted); line-height: 1.45; margin: 12px 2px 0; }

/* Bottom sheet */
.sheet-backdrop { position: fixed; inset: 0; background: rgba(8, 20, 40, .45); z-index: 80; opacity: 0; pointer-events: none; transition: opacity .2s; }
.sheet-backdrop.open { opacity: 1; pointer-events: auto; }
.sheet {
  position: fixed; left: 50%; bottom: 0; width: 100%; max-width: var(--max-w); z-index: 81;
  transform: translate(-50%, 105%); visibility: hidden;
  transition: transform .25s ease, visibility 0s linear .25s;
  background: #fff; border-radius: 24px 24px 0 0; padding: 10px 18px calc(18px + env(safe-area-inset-bottom));
  max-height: 90vh; overflow-y: auto;
}
.sheet.open { transform: translate(-50%, 0); visibility: visible; transition: transform .25s ease; }
.sheet .grab { width: 40px; height: 4px; border-radius: 4px; background: var(--border-strong); margin: 0 auto 14px; }
.sheet h3 { margin: 0 0 4px; font-size: 17px; }
.sheet .sub { margin: 0 0 16px; font-size: 13px; color: var(--text-muted); }
.sheet .amt-input { display: flex; align-items: center; justify-content: center; gap: 4px; border-bottom: 2px solid var(--border); padding-bottom: 6px; margin: 4px 20px 6px; }
.sheet .amt-input:focus-within { border-color: var(--rbnw-blue-light); }
.sheet .amt-input span { font-size: 28px; font-weight: 800; color: var(--rbnw-blue); }
.sheet .amt-input input { border: none; outline: none; width: 5ch; font-size: 34px; font-weight: 800; color: var(--text-dark); }
.sheet .avail { text-align: center; font-size: 12.5px; color: var(--text-muted); margin-bottom: 14px; }
.sheet .avail button { background: none; border: none; color: var(--rbnw-blue); font-weight: 700; font-size: 12.5px; padding: 0 0 0 6px; }
.sheet .quick { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 16px; }
.sheet .quick button { height: 40px; border-radius: var(--radius-sm); border: 1.5px solid var(--border); background: #fff; font-weight: 700; font-size: 13px; color: var(--rbnw-blue); }
.sheet .flow { display: flex; align-items: center; justify-content: center; gap: 10px; font-size: 12.5px; color: var(--text-body); margin-bottom: 16px; }
.sheet .flow span { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; background: var(--bg); border-radius: 999px; font-weight: 600; }
.sheet .flow i.fa-arrow-right { color: var(--text-faint); }
.sheet .stack { display: flex; flex-direction: column; gap: 10px; }
.sheet .goal-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px; }
.sheet .goal-stats div { background: var(--bg); border-radius: var(--radius-sm); padding: 10px 12px; font-size: 12px; color: var(--text-muted); }
.sheet .goal-stats strong { display: block; font-size: 15px; color: var(--text-dark); }
.btn-danger-text { background: none; border: none; color: var(--danger); font-weight: 600; font-size: 13.5px; padding: 8px; }
</style>
</head>
<body>
<div class="app-shell">
  <div class="flow-header">
    <button class="back-btn" onclick="RBNW.goBack('dashboard.php')" style="background:none;border:none;" aria-label="Back"><i class="fa-solid fa-arrow-left"></i></button>
    <h2>Save Money</h2>
  </div>

  <div class="sv-body" id="body">
    <div class="empty-state"><span class="spinner" style="border-color:rgba(15,76,146,.2);border-top-color:var(--rbnw-blue);display:inline-block"></span></div>
  </div>

  <?php $activeNav = 'home'; include __DIR__ . '/includes/bottom-nav.php'; ?>
</div>

<!-- Move money sheet -->
<div class="sheet-backdrop" id="backdrop"></div>
<div class="sheet" id="moveSheet" role="dialog" aria-modal="true" aria-labelledby="moveTitle">
  <div class="grab"></div>
  <h3 id="moveTitle">Deposit</h3>
  <p class="sub" id="moveSub"></p>
  <div class="flow" id="moveFlow"></div>
  <div class="amt-input"><span>₱</span><input type="text" id="moveAmount" inputmode="decimal" placeholder="0" autocomplete="off" aria-label="Amount"></div>
  <div class="avail" id="moveAvail"></div>
  <div class="quick" id="moveQuick"></div>
  <div id="moveError"></div>
  <button class="btn btn-primary" id="moveBtn">Confirm</button>
</div>

<!-- New goal sheet -->
<div class="sheet" id="goalSheet" role="dialog" aria-modal="true" aria-labelledby="goalTitle">
  <div class="grab"></div>
  <h3 id="goalTitle">New savings goal</h3>
  <p class="sub">Save for something specific and watch your progress.</p>
  <div class="field">
    <label for="goalName">Goal name</label>
    <div class="input-wrap"><i class="icon fa-solid fa-bullseye"></i><input type="text" id="goalName" placeholder="e.g. Emergency fund, New phone" maxlength="60"></div>
  </div>
  <div class="field">
    <label for="goalTarget">Target amount</label>
    <div class="input-wrap"><span class="prefix">₱</span><input type="text" id="goalTarget" inputmode="decimal" placeholder="10,000"></div>
  </div>
  <div class="field">
    <label for="goalDate">Target date <span class="opt">Optional</span></label>
    <div class="input-wrap"><i class="icon fa-regular fa-calendar"></i><input type="date" id="goalDate"></div>
  </div>
  <div id="goalError"></div>
  <button class="btn btn-primary" id="goalCreateBtn">Create goal</button>
</div>

<!-- Goal detail sheet -->
<div class="sheet" id="detailSheet" role="dialog" aria-modal="true" aria-labelledby="detailTitle">
  <div class="grab"></div>
  <h3 id="detailTitle"></h3>
  <p class="sub" id="detailSub"></p>
  <div class="goal-stats" id="detailStats"></div>
  <div class="stack">
    <button class="btn btn-primary" id="detailAdd">Add money</button>
    <button class="btn btn-outline" id="detailWithdraw">Withdraw</button>
    <button class="btn-danger-text" id="detailClose">Close goal</button>
  </div>
</div>

<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script>
const esc = RBNW.escapeHtml, peso = RBNW.peso;
let D = null;            // summary from the API
let move = null;         // { pocket, dir }

async function load() {
  const res = await RBNW.api('savings.php?action=summary');
  const body = document.getElementById('body');
  if (!res.success) { body.innerHTML = RBNW.alertHtml(res.message || 'Could not load savings.', 'error'); return; }
  D = res;
  const main = res.pockets.find(p => p.is_main);
  const goals = res.pockets.filter(p => !p.is_main);

  let html = `
    <div class="sv-hero">
      <div class="top"><span>Total savings</span><span class="rate">Up to ${Math.max(...res.pockets.map(p => p.rate))}% p.a.</span></div>
      <div class="big">${peso(res.total)}</div>
      <div class="earned">Interest earned: <strong>+${peso(res.interest_total)}</strong></div>
      <div class="acts">
        <button class="btn btn-in" onclick="openMove(${main.id}, 'deposit')"><i class="fa-solid fa-arrow-down"></i> Deposit</button>
        <button class="btn btn-out" onclick="openMove(${main.id}, 'withdraw')"><i class="fa-solid fa-arrow-up"></i> Withdraw</button>
      </div>
    </div>
    <div class="sv-wallet"><i class="fa-solid fa-wallet"></i> Wallet balance <strong>${peso(res.wallet_balance)}</strong></div>

    <div class="sv-title"><span>My Savings <small style="font-weight:500;color:var(--text-muted)">· ${main.rate}% p.a.</small></span></div>
    <button class="goal-card" onclick="openMove(${main.id}, 'deposit')">
      <div class="row">
        <div class="ico"><i class="fa-solid fa-piggy-bank"></i></div>
        <div class="info"><strong>${peso(main.balance)}</strong><small>Withdraw anytime · earned ${peso(main.interest_earned)}</small></div>
        <i class="fa-solid fa-chevron-right" style="color:var(--text-faint)"></i>
      </div>
    </button>

    <div class="sv-title"><span>Goals${goals[0] ? ` <small style="font-weight:500;color:var(--text-muted)">· ${goals[0].rate}% p.a.</small>` : ''}</span>
      ${goals.length < res.max_goals ? '<button onclick="openGoal()"><i class="fa-solid fa-plus"></i> New goal</button>' : ''}</div>`;

  if (goals.length) {
    html += goals.map(g => {
      const pct = g.target ? Math.min(100, Math.round(g.balance / g.target * 100)) : 0;
      const by = g.target_date ? ' · by ' + new Date(g.target_date + 'T00:00:00').toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }) : '';
      return `<button class="goal-card" onclick="openDetail(${g.id})">
        <div class="row">
          <div class="ico"><i class="fa-solid fa-bullseye"></i></div>
          <div class="info"><strong>${esc(g.name)}</strong><small>${peso(g.balance)} of ${peso(g.target)}${by}</small></div>
          <span class="pct">${pct}%</span>
        </div>
        <div class="bar"><span style="width:${pct}%"></span></div>
      </button>`;
    }).join('');
  } else {
    html += `<div class="goal-empty">Saving for something? Set a goal and track your progress.<br>
      <button class="btn btn-outline btn-sm" style="width:auto;display:inline-flex" onclick="openGoal()"><i class="fa-solid fa-plus"></i> Create a goal</button></div>`;
  }

  html += `<div class="sv-title"><span>Recent activity</span></div><div class="card">`;
  html += res.activity.length ? res.activity.map(a => {
    const m = { deposit: ['in', 'fa-arrow-down', 'Deposit', '+'], withdraw: ['out', 'fa-arrow-up', 'Withdrawal', '−'], interest: ['int', 'fa-percent', 'Interest', '+'] }[a.type];
    return `<div class="act-row"><span class="ico ${m[0]}"><i class="fa-solid ${m[1]}"></i></span>
      <span class="info">${m[2]} · ${esc(a.pocket)}<small>${esc(a.when)}${a.tax ? ` · ${peso(a.tax)} tax withheld` : ''}</small></span>
      <span class="amt">${m[3]}${peso(a.amount)}</span></div>`;
  }).join('') : '<div class="empty-state" style="padding:24px 10px">No savings activity yet.</div>';
  html += `</div>
    <p class="fine">Interest is computed daily and credited to your savings, net of ${res.wtax_pct}% final withholding tax. Deposits and withdrawals move instantly between your RBNW Wallet and Save Money.</p>`;

  document.getElementById('body').innerHTML = html;
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

function openMove(pocketId, dir) {
  const p = D.pockets.find(x => x.id === pocketId);
  move = { pocket: p, dir };
  const isDep = dir === 'deposit';
  const max = isDep ? D.wallet_balance : p.balance;
  const pName = p.is_main ? 'My Savings' : p.name;
  document.getElementById('moveTitle').textContent = isDep ? 'Deposit to savings' : 'Withdraw to wallet';
  document.getElementById('moveSub').textContent = isDep ? `Earn ${p.rate}% p.a. on your savings.` : 'Arrives in your wallet instantly.';
  document.getElementById('moveFlow').innerHTML = isDep
    ? `<span><i class="fa-solid fa-wallet"></i> Wallet</span><i class="fa-solid fa-arrow-right"></i><span><i class="fa-solid fa-piggy-bank"></i> ${esc(pName)}</span>`
    : `<span><i class="fa-solid fa-piggy-bank"></i> ${esc(pName)}</span><i class="fa-solid fa-arrow-right"></i><span><i class="fa-solid fa-wallet"></i> Wallet</span>`;
  document.getElementById('moveAvail').innerHTML = `${isDep ? 'Wallet balance' : 'Available'}: ${peso(max)} <button type="button" id="maxBtn">Max</button>`;
  document.getElementById('maxBtn').onclick = () => setAmount(Math.floor(max * 100) / 100);
  document.getElementById('moveQuick').innerHTML = [100, 500, 1000, 5000].map(a => `<button type="button" data-a="${a}">₱${a.toLocaleString()}</button>`).join('');
  document.querySelectorAll('#moveQuick button').forEach(b => b.onclick = () => setAmount(+b.dataset.a));
  document.getElementById('moveError').innerHTML = '';
  document.getElementById('moveBtn').textContent = isDep ? 'Deposit' : 'Withdraw';
  setAmount('');
  openSheet('moveSheet');
}

const amtEl = document.getElementById('moveAmount');
function fit() { amtEl.style.width = Math.max(amtEl.value.length || 1, 1) + 0.6 + 'ch'; }
function setAmount(v) { amtEl.value = v === '' ? '' : String(v); fit(); document.getElementById('moveError').innerHTML = ''; }
amtEl.addEventListener('input', () => {
  let v = amtEl.value.replace(/[^0-9.]/g, '');
  const [w, ...r] = v.split('.');
  amtEl.value = r.length ? w + '.' + r.join('').slice(0, 2) : w;
  fit();
  document.getElementById('moveError').innerHTML = '';
});

document.getElementById('moveBtn').onclick = async (e) => {
  const amount = parseFloat(amtEl.value || '0');
  if (!amount) { document.getElementById('moveError').innerHTML = RBNW.alertHtml('Enter an amount.', 'error'); return; }
  const btn = e.currentTarget;
  btn.disabled = true;
  const res = await RBNW.api('savings.php?action=' + move.dir, 'POST', { pocket_id: move.pocket.id, amount });
  btn.disabled = false;
  if (!res.success) { document.getElementById('moveError').innerHTML = RBNW.alertHtml(res.message, 'error'); return; }
  closeSheets();
  RBNW.toast(move.dir === 'deposit' ? `${peso(res.amount)} moved to savings` : `${peso(res.amount)} moved to your wallet`, 'success');
  load();
};

function openGoal() {
  ['goalName', 'goalTarget', 'goalDate'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('goalError').innerHTML = '';
  openSheet('goalSheet');
}
document.getElementById('goalTarget').addEventListener('input', (e) => { e.target.value = e.target.value.replace(/[^0-9.]/g, ''); });
document.getElementById('goalCreateBtn').onclick = async (e) => {
  const btn = e.currentTarget;
  btn.disabled = true;
  const res = await RBNW.api('savings.php?action=create_goal', 'POST', {
    name: document.getElementById('goalName').value,
    target: parseFloat(document.getElementById('goalTarget').value || '0'),
    target_date: document.getElementById('goalDate').value,
  });
  btn.disabled = false;
  if (!res.success) { document.getElementById('goalError').innerHTML = RBNW.alertHtml(res.message, 'error'); return; }
  closeSheets();
  RBNW.toast('Goal created', 'success');
  load();
};

function openDetail(id) {
  const g = D.pockets.find(p => p.id === id);
  const pct = g.target ? Math.min(100, Math.round(g.balance / g.target * 100)) : 0;
  document.getElementById('detailTitle').textContent = g.name;
  document.getElementById('detailSub').textContent = `${pct}% of your ${peso(g.target)} goal · ${g.rate}% p.a.`;
  document.getElementById('detailStats').innerHTML =
    `<div>Saved<strong>${peso(g.balance)}</strong></div><div>Interest earned<strong>${peso(g.interest_earned)}</strong></div>`;
  document.getElementById('detailAdd').onclick = () => openMove(id, 'deposit');
  document.getElementById('detailWithdraw').onclick = () => openMove(id, 'withdraw');
  document.getElementById('detailClose').onclick = async () => {
    if (!confirm(`Close "${g.name}"? ${g.balance > 0 ? peso(g.balance) + ' will be moved to your wallet.' : ''}`)) return;
    const res = await RBNW.api('savings.php?action=close_goal', 'POST', { pocket_id: id });
    if (!res.success) { RBNW.toast(res.message, 'error'); return; }
    closeSheets();
    RBNW.toast('Goal closed', 'success');
    load();
  };
  openSheet('detailSheet');
}

load();
</script>
</body>
</html>
