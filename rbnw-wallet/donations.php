<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<title>Donations — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
.dn-body { padding: 16px 16px calc(110px + env(safe-area-inset-bottom)); }
.dn-hero {
  position: relative; overflow: hidden; isolation: isolate;
  background: var(--rbnw-gradient); color: #fff; border-radius: var(--radius-lg);
  padding: 20px; box-shadow: var(--shadow-card);
}
.dn-hero::before {
  content: ''; position: absolute; right: -14px; top: 50%; width: 170px; height: 150px;
  transform: translateY(-50%) rotate(-10deg);
  background: url('<?= asset_url('assets/img/rbnw-r-white.png') ?>') center / contain no-repeat;
  opacity: .09; z-index: -1;
}
.dn-hero small { font-size: 12.5px; opacity: .85; }
.dn-hero .big { font-size: 30px; font-weight: 800; margin: 4px 0 2px; }
.dn-hero .sub { font-size: 12.5px; opacity: .9; }
.dn-chips { display: flex; gap: 8px; margin: 18px 0 4px; overflow-x: auto; scrollbar-width: none; }
.dn-chips::-webkit-scrollbar { display: none; }
.dn-chips button { flex-shrink: 0; height: 36px; padding: 0 16px; border-radius: 999px; border: 1.5px solid var(--border); background: #fff; font-size: 13px; font-weight: 600; color: var(--text-body); }
.dn-chips button.active { background: var(--rbnw-blue); border-color: var(--rbnw-blue); color: #fff; }
.dn-title { margin: 18px 2px 10px; font-size: 14px; font-weight: 700; }

.cause { background: #fff; border-radius: var(--radius-md); box-shadow: var(--shadow-soft); padding: 14px; margin-bottom: 12px; }
.cause .top { display: flex; gap: 12px; align-items: flex-start; }
.cause .ico { width: 48px; height: 48px; border-radius: 14px; flex-shrink: 0; background: var(--rbnw-blue-50); color: var(--rbnw-blue); display: flex; align-items: center; justify-content: center; font-size: 20px; overflow: hidden; }
.cause .ico img { width: 100%; height: 100%; object-fit: cover; }
.cause .info { flex: 1; min-width: 0; }
.cause .info strong { display: block; font-size: 14.5px; }
.cause .info p { margin: 3px 0 0; font-size: 12.5px; color: var(--text-muted); line-height: 1.4; }
.cause .tag { display: inline-block; font-size: 10.5px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: var(--success-50); color: #1b7a3b; margin-top: 6px; }
.bar { height: 8px; border-radius: 999px; background: var(--bg); margin: 14px 0 6px; overflow: hidden; }
.bar span { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, var(--rbnw-blue), var(--rbnw-green)); }
.cause .stats { display: flex; justify-content: space-between; font-size: 12px; color: var(--text-muted); }
.cause .stats strong { color: var(--text-dark); }
.cause .acts { display: grid; grid-template-columns: 1fr 1.4fr; gap: 8px; margin-top: 12px; }
.cause .acts .btn { min-height: 42px; padding: 8px; font-size: 14px; }

.mine-row { display: flex; align-items: center; gap: 12px; padding: 11px 0; border-bottom: 1px solid var(--border); font-size: 13.5px; }
.mine-row:last-child { border-bottom: none; }
.mine-row .ico { width: 34px; height: 34px; border-radius: 50%; background: #fde6e5; color: var(--danger); display: flex; align-items: center; justify-content: center; font-size: 13px; flex-shrink: 0; }
.mine-row .info { flex: 1; min-width: 0; }
.mine-row small { display: block; font-size: 11.5px; color: var(--text-muted); }
.mine-row .amt { font-weight: 700; }

.sup-row { display: flex; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
.sup-row:last-child { border-bottom: none; }
.sup-row .av { width: 32px; height: 32px; border-radius: 50%; background: var(--rbnw-blue-50); color: var(--rbnw-blue); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; flex-shrink: 0; }
.sup-row .info { flex: 1; min-width: 0; }
.sup-row .info small { display: block; font-size: 11.5px; color: var(--text-muted); }
.sup-row .info em { display: block; font-style: normal; color: var(--text-body); margin-top: 2px; }
.sup-row .amt { font-weight: 700; color: var(--rbnw-blue); }
.sheet .msg { width: 100%; border: 1.5px solid var(--border); border-radius: var(--radius-sm); padding: 10px 12px; font: inherit; font-size: 14px; resize: none; outline: none; margin-bottom: 12px; }
.sheet .msg:focus { border-color: var(--rbnw-blue-light); box-shadow: var(--focus-ring); }
.sheet .pin-input { max-width: 320px; margin: 6px auto 0; }
.sheet .cause-head { display: flex; gap: 12px; align-items: center; margin-bottom: 14px; }
.sheet .cause-head .ico { width: 44px; height: 44px; border-radius: 13px; background: var(--rbnw-blue-50); color: var(--rbnw-blue); display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.sheet .cause-head strong { font-size: 16px; }
.sheet .cause-head small { display: block; font-size: 12px; color: var(--text-muted); }
</style>
</head>
<body>
<div class="app-shell">
  <div class="flow-header">
    <button class="back-btn" onclick="RBNW.goBack('dashboard.php')" style="background:none;border:none;" aria-label="Back"><i class="fa-solid fa-arrow-left"></i></button>
    <h2>Donations</h2>
  </div>
  <div class="dn-body" id="body">
    <div class="empty-state"><span class="spinner" style="border-color:rgba(15,76,146,.2);border-top-color:var(--rbnw-blue);display:inline-block"></span></div>
  </div>
  <?php $activeNav = 'home'; include __DIR__ . '/includes/bottom-nav.php'; ?>
</div>

<div class="sheet-backdrop" id="backdrop"></div>

<!-- Cause detail -->
<div class="sheet" id="detailSheet" role="dialog" aria-modal="true" aria-labelledby="detailName">
  <div class="grab"></div>
  <div class="cause-head"><span class="ico" id="detailIco"></span><span><strong id="detailName"></strong><small id="detailCat"></small></span></div>
  <p class="sub" id="detailDesc"></p>
  <div class="bar"><span id="detailBar"></span></div>
  <div class="sum-row" style="display:flex;justify-content:space-between;font-size:12.5px;color:var(--text-muted);margin-bottom:14px"><span id="detailRaised"></span><span id="detailPct"></span></div>
  <div style="font-size:13.5px;font-weight:700;margin-bottom:4px">Recent supporters</div>
  <div id="detailSupporters" style="margin-bottom:14px"></div>
  <button class="btn btn-primary" id="detailDonate"><i class="fa-solid fa-heart"></i> Donate</button>
</div>

<!-- Donate -->
<div class="sheet" id="donateSheet" role="dialog" aria-modal="true" aria-labelledby="donName">
  <div class="grab"></div>
  <div id="donForm">
    <div class="cause-head"><span class="ico" id="donIco"></span><span><small>Donate to</small><strong id="donName"></strong></span></div>
    <div class="amt-input"><span>₱</span><input type="text" id="donAmount" inputmode="decimal" placeholder="0" autocomplete="off" aria-label="Amount"></div>
    <div class="avail" id="donAvail"></div>
    <div class="quick" id="donQuick"></div>
    <textarea class="msg" id="donMsg" rows="2" maxlength="150" placeholder="Add a message of support (optional)"></textarea>
    <label class="checkbox-line" style="margin-bottom:14px"><input type="checkbox" id="donAnon"> <span>Donate anonymously — hide my name from supporters</span></label>
    <div id="donError"></div>
    <button class="btn btn-primary" id="donNext">Continue</button>
  </div>
  <div id="donPin" class="hidden" style="text-align:center">
    <h3>Enter MPIN</h3>
    <p class="sub" id="donPinSub"></p>
    <div class="pin-input" id="pinBoxes">
      <input type="password" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="off" aria-label="6-digit MPIN">
      <div class="pin-boxes" aria-hidden="true">
        <span class="pin-box"></span><span class="pin-box"></span><span class="pin-box"></span>
        <span class="pin-box"></span><span class="pin-box"></span><span class="pin-box"></span>
      </div>
    </div>
    <div id="donPinError" style="margin-top:14px"></div>
    <button class="btn-danger-text" style="color:var(--rbnw-blue)" onclick="showDonForm()">Back</button>
  </div>
  <div id="donDone" class="hidden" style="text-align:center;padding:10px 0 4px">
    <div class="success-check" style="width:70px;height:70px;font-size:30px;background:#fde6e5;color:var(--danger)"><i class="fa-solid fa-heart"></i></div>
    <h3>Thank you!</h3>
    <p class="sub" id="donDoneSub"></p>
    <button class="btn btn-primary" onclick="closeSheets(); load();">Done</button>
  </div>
</div>

<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script>
const esc = RBNW.escapeHtml, peso = RBNW.peso;
let D = null, filter = 'All', cur = null;

function causeIcon(c) {
  if (c.image) return `<img src="${esc(c.image)}" alt="">`;
  const n = c.name.toLowerCase();
  const i = n.includes('relief') ? 'fa-house-flood-water' : n.includes('education') || n.includes('scholar') ? 'fa-graduation-cap'
    : n.includes('environment') || n.includes('beach') ? 'fa-leaf' : n.includes('animal') ? 'fa-paw'
    : n.includes('youth') ? 'fa-people-group' : 'fa-hand-holding-heart';
  return `<i class="fa-solid ${i}"></i>`;
}
const pct = (c) => c.goal ? Math.min(100, Math.round(c.raised / c.goal * 100)) : 0;

async function load() {
  const res = await RBNW.api('donations.php?action=summary');
  const body = document.getElementById('body');
  if (!res.success) { body.innerHTML = RBNW.alertHtml(res.message || 'Could not load donations.', 'error'); return; }
  D = res;
  const cats = ['All', ...new Set(res.causes.map(c => c.category).filter(Boolean))];
  let html = `
    <div class="dn-hero">
      <small>Your total donations</small>
      <div class="big">${peso(res.my_total)}</div>
      <div class="sub">${res.my_causes ? `You've supported ${res.my_causes} cause${res.my_causes > 1 ? 's' : ''}. Salamat!` : 'Every peso goes to verified local causes.'}</div>
    </div>
    <div class="dn-chips">${cats.map(c => `<button class="${c === filter ? 'active' : ''}" onclick="setFilter('${esc(c)}')">${esc(c)}</button>`).join('')}</div>
    <div class="dn-title">Causes you can support</div>`;
  html += res.causes.filter(c => filter === 'All' || c.category === filter).map(c => `
    <div class="cause">
      <div class="top">
        <span class="ico">${causeIcon(c)}</span>
        <span class="info"><strong>${esc(c.name)}</strong><p>${esc(c.description || '')}</p>${c.category ? `<span class="tag">${esc(c.category)}</span>` : ''}</span>
      </div>
      <div class="bar"><span style="width:${pct(c)}%"></span></div>
      <div class="stats"><span><strong>${peso(c.raised)}</strong> raised of ${peso(c.goal)}</span><span>${pct(c)}%</span></div>
      <div class="acts">
        <button class="btn btn-outline" onclick="openDetail(${c.id})">Details</button>
        <button class="btn btn-primary" onclick="openDonate(${c.id})"><i class="fa-solid fa-heart"></i> Donate</button>
      </div>
    </div>`).join('');

  html += `<div class="dn-title">My donations</div><div class="card">`;
  html += res.mine.length ? res.mine.map(d => `
    <div class="mine-row"><span class="ico"><i class="fa-solid fa-heart"></i></span>
      <span class="info">${esc(d.cause)}<small>${esc(d.when)}${d.anonymous ? ' · anonymous' : ''}</small></span>
      <span class="amt">${peso(d.amount)}</span></div>`).join('')
    : '<div class="empty-state" style="padding:22px 10px">You haven\'t donated yet.</div>';
  html += '</div>';
  body.innerHTML = html;
}
function setFilter(c) { filter = c; load(); }

// ---------- sheets ----------
const backdrop = document.getElementById('backdrop');
function openSheet(id) { document.querySelectorAll('.sheet').forEach(s => s.classList.toggle('open', s.id === id)); backdrop.classList.add('open'); }
function closeSheets() { document.querySelectorAll('.sheet').forEach(s => s.classList.remove('open')); backdrop.classList.remove('open'); }
backdrop.onclick = closeSheets;

async function openDetail(id) {
  const c = D.causes.find(x => x.id === id);
  document.getElementById('detailIco').innerHTML = causeIcon(c);
  document.getElementById('detailName').textContent = c.name;
  document.getElementById('detailCat').textContent = (c.category ? c.category + ' · ' : '') + `${c.supporters} supporter${c.supporters === 1 ? '' : 's'} on RBNW Wallet`;
  document.getElementById('detailDesc').textContent = c.description || '';
  document.getElementById('detailBar').style.width = pct(c) + '%';
  document.getElementById('detailRaised').textContent = `${peso(c.raised)} raised of ${peso(c.goal)}`;
  document.getElementById('detailPct').textContent = pct(c) + '%';
  document.getElementById('detailDonate').onclick = () => openDonate(id);
  const box = document.getElementById('detailSupporters');
  box.innerHTML = '<div class="empty-state" style="padding:12px">Loading…</div>';
  openSheet('detailSheet');
  const res = await RBNW.api('donations.php?action=supporters&cause_id=' + id);
  box.innerHTML = (res.supporters || []).length ? res.supporters.map(s => `
    <div class="sup-row"><span class="av">${s.name === 'Anonymous' ? '<i class="fa-solid fa-user-secret"></i>' : esc(s.name.slice(0, 1))}</span>
      <span class="info">${esc(s.name)}<small>${esc(s.when)}</small>${s.message ? `<em>“${esc(s.message)}”</em>` : ''}</span>
      <span class="amt">${peso(s.amount)}</span></div>`).join('')
    : '<div style="font-size:12.5px;color:var(--text-muted);padding:6px 0 4px">Be the first RBNW Wallet user to support this cause.</div>';
}

const amtEl = document.getElementById('donAmount');
function fit() { amtEl.style.width = Math.max(amtEl.value.length || 1, 1) + 0.6 + 'ch'; }
amtEl.addEventListener('input', () => {
  let v = amtEl.value.replace(/[^0-9.]/g, '');
  const [w, ...r] = v.split('.');
  amtEl.value = r.length ? w + '.' + r.join('').slice(0, 2) : w;
  fit(); document.getElementById('donError').innerHTML = '';
});

function openDonate(id) {
  cur = D.causes.find(x => x.id === id);
  document.getElementById('donIco').innerHTML = causeIcon(cur);
  document.getElementById('donName').textContent = cur.name;
  document.getElementById('donAvail').textContent = `Wallet balance: ${peso(D.wallet_balance)}`;
  document.getElementById('donQuick').innerHTML = [50, 100, 500, 1000].map(a => `<button type="button" data-a="${a}">₱${a.toLocaleString()}</button>`).join('');
  document.querySelectorAll('#donQuick button').forEach(b => b.onclick = () => { amtEl.value = b.dataset.a; fit(); document.getElementById('donError').innerHTML = ''; });
  amtEl.value = ''; fit();
  document.getElementById('donMsg').value = '';
  document.getElementById('donAnon').checked = false;
  showDonForm();
  openSheet('donateSheet');
}
function showDonForm() {
  document.getElementById('donForm').classList.remove('hidden');
  document.getElementById('donPin').classList.add('hidden');
  document.getElementById('donDone').classList.add('hidden');
  document.getElementById('donError').innerHTML = '';
}

document.getElementById('donNext').onclick = () => {
  const amt = parseFloat(amtEl.value || '0');
  const err = document.getElementById('donError');
  if (amt < D.min || amt > D.max) { err.innerHTML = RBNW.alertHtml(`Donate from ${peso(D.min)} to ${peso(D.max)}.`, 'error'); return; }
  if (amt > D.wallet_balance) { err.innerHTML = RBNW.alertHtml('Not enough wallet balance. Cash In first.', 'error'); return; }
  document.getElementById('donPinSub').textContent = `Donate ${peso(amt)} to ${cur.name}`;
  document.getElementById('donForm').classList.add('hidden');
  document.getElementById('donPin').classList.remove('hidden');
  document.getElementById('donPinError').innerHTML = '';
  pin.clear();
  setTimeout(() => pin.input.focus(), 50);
};

let busy = false;
const pin = RBNW.mountPinInput(document.getElementById('pinBoxes'), async (v) => {
  if (v.length !== 6 || busy) return;
  busy = true;
  const res = await RBNW.api('donations.php?action=donate', 'POST', {
    cause_id: cur.id, amount: parseFloat(amtEl.value), message: document.getElementById('donMsg').value,
    anonymous: document.getElementById('donAnon').checked, mpin: v,
  });
  busy = false;
  if (!res.success) { pin.clear(); document.getElementById('donPinError').innerHTML = RBNW.alertHtml(res.message, 'error'); return; }
  document.getElementById('donDoneSub').textContent = `Your ${peso(res.amount)} donation to ${res.cause} was received. Ref ${res.reference_no}`;
  document.getElementById('donPin').classList.add('hidden');
  document.getElementById('donDone').classList.remove('hidden');
});

load();
</script>
</body>
</html>
