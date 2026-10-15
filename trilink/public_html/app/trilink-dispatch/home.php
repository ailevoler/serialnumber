<?php
require_once __DIR__ . '/includes/core.php';
$me = dispatcherCheck();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dispatch – <?= e($me['terminal_name']) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    :root{--navy:#1A2B5E;--green:#2E7D32;--orange:#E65100;--red:#C62828;--muted:#9AA0A6;--line:#EAECF0;}
    *{box-sizing:border-box;margin:0;padding:0;}
    body{background:#F5F6FA;font-family:'Inter',sans-serif;color:#202124;}
    .top{position:sticky;top:0;z-index:10;display:flex;align-items:center;gap:12px;padding:12px 16px;background:linear-gradient(135deg,#0F1A3A,#1A2B5E);color:#fff;}
    .top .ic{width:38px;height:38px;border-radius:11px;background:rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;}
    .top .t{flex:1;min-width:0;}
    .top .t b{display:block;font-family:'Plus Jakarta Sans',sans-serif;font-size:16px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .top .t small{font-size:11.5px;color:rgba(255,255,255,.65);}
    .top .stat{text-align:right;font-size:11px;color:rgba(255,255,255,.65);}
    .top .stat strong{display:block;font-size:18px;color:#fff;font-family:'Plus Jakarta Sans',sans-serif;}
    .top a{color:rgba(255,255,255,.75);font-size:18px;padding:6px;}
    .wrap{display:grid;grid-template-columns:1.25fr 1fr;gap:16px;padding:16px;max-width:1200px;margin:0 auto;}
    @media (max-width:820px){.wrap{grid-template-columns:1fr;}}
    .panel{background:#fff;border:1.5px solid var(--line);border-radius:16px;overflow:hidden;}
    .ph{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 16px;border-bottom:1.5px solid var(--line);}
    .ph h2{font-size:14px;font-weight:800;color:var(--navy);display:flex;align-items:center;gap:8px;}
    .count{font-size:12px;font-weight:800;padding:3px 10px;border-radius:12px;background:#EEF2FF;color:var(--navy);}
    .count.hot{background:#FFF3E0;color:var(--orange);}
    .empty{padding:34px 16px;text-align:center;color:var(--muted);font-size:13px;}
    .empty i{display:block;font-size:30px;opacity:.35;margin-bottom:8px;}
    .bk{padding:14px 16px;border-bottom:1.5px solid var(--line);}
    .bk:last-child{border-bottom:none;}
    .bk-top{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;}
    .bk-name{font-weight:800;font-size:15px;}
    .bk-name a{color:var(--green);font-size:12.5px;font-weight:700;text-decoration:none;margin-left:6px;}
    .bk-fare{font-family:'Plus Jakarta Sans',sans-serif;font-weight:800;font-size:18px;color:var(--navy);text-align:right;}
    .bk-fare small{display:block;font-family:'Inter',sans-serif;font-size:11px;font-weight:700;color:var(--muted);}
    .bk-route{margin-top:6px;font-size:13px;color:#3C4043;}
    .bk-meta{margin-top:4px;font-size:11.5px;color:var(--muted);}
    .wait{color:var(--orange);font-weight:700;}
    .bk-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;}
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:10px 14px;border-radius:11px;border:1.5px solid var(--line);background:#fff;font-weight:700;font-size:13px;cursor:pointer;font-family:inherit;color:#3C4043;}
    .btn:disabled{opacity:.55;cursor:not-allowed;}
    .btn-go{flex:1 1 100%;border:none;background:linear-gradient(135deg,#2E7D32,#43A047);color:#fff;font-size:14.5px;padding:13px;}
    .btn-danger{color:var(--red);border-color:#F5C6C6;}
    select.pick{flex:1;min-width:150px;padding:10px;border-radius:11px;border:1.5px solid var(--line);font-family:inherit;font-size:13px;background:#fff;}
    .q{display:flex;align-items:center;gap:12px;padding:11px 16px;border-bottom:1.5px solid var(--line);}
    .q:last-child{border-bottom:none;}
    .pos{width:34px;height:34px;border-radius:50%;background:#EEF2FF;color:var(--navy);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex-shrink:0;}
    .q.first .pos{background:var(--green);color:#fff;}
    .q-info{flex:1;min-width:0;}
    .q-name{font-weight:700;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .q-sub{font-size:11.5px;color:var(--muted);}
    .flag{display:inline-block;font-size:10px;font-weight:800;padding:2px 7px;border-radius:8px;margin-left:4px;}
    .flag.low{background:#FFEBEE;color:var(--red);}
    .flag.off{background:#F1F3F4;color:#5F6368;}
    .icon-btn{width:34px;height:34px;border-radius:10px;border:1.5px solid var(--line);background:#fff;color:#5F6368;cursor:pointer;}
    .icon-btn.red{color:var(--red);}
    .add{display:flex;gap:8px;padding:12px 16px;background:#FAFBFC;border-bottom:1.5px solid var(--line);}
    .add input{flex:1;min-width:0;padding:11px 12px;border-radius:11px;border:1.5px solid var(--line);font-size:14px;font-family:inherit;}
    .add button{border:none;background:var(--navy);color:#fff;}
    .recent{font-size:12.5px;}
    .recent .r{display:flex;justify-content:space-between;gap:10px;padding:9px 16px;border-bottom:1px solid #F1F3F4;}
    .recent .r span{color:var(--muted);}
    .side{display:flex;flex-direction:column;gap:16px;}
    .toast{position:fixed;left:50%;bottom:20px;transform:translateX(-50%);max-width:92vw;padding:12px 16px;border-radius:12px;background:#202124;color:#fff;font-size:13.5px;font-weight:600;z-index:50;box-shadow:0 8px 24px rgba(0,0,0,.25);display:none;}
    .toast.ok{background:var(--green);} .toast.err{background:var(--red);}
    .conn{width:8px;height:8px;border-radius:50%;background:#00C853;display:inline-block;margin-right:5px;}
  </style>
</head>
<body>
<header class="top">
  <div class="ic"><i class="fas fa-traffic-light"></i></div>
  <div class="t">
    <b><?= e($me['terminal_name']) ?></b>
    <small><span class="conn" id="conn"></span><?= e($me['full_name']) ?> · <?= e($me['dispatcher_code']) ?> · <span id="clock">—</span></small>
  </div>
  <div class="stat"><strong id="todayCount">0</strong>dispatched today</div>
  <a href="<?= APP_URL ?>/logout.php" onclick="return confirm('Logout?')" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
</header>

<main class="wrap">
  <section class="panel">
    <div class="ph"><h2><i class="fas fa-user-clock"></i> Mga Pasaherong Naghihintay</h2><span class="count" id="bkCount">0</span></div>
    <div id="bookings"><div class="empty"><i class="fas fa-spinner fa-spin"></i>Loading…</div></div>
  </section>

  <div class="side">
    <section class="panel">
      <div class="ph"><h2><i class="fas fa-list-ol"></i> Pila ng Tricycle</h2><span class="count" id="qCount">0</span></div>
      <form class="add" onsubmit="addDriver(event)">
        <input id="addQ" placeholder="Driver code, phone o plate #" autocomplete="off">
        <button class="btn" type="submit"><i class="fas fa-plus"></i> Ipila</button>
      </form>
      <div id="queue"></div>
    </section>

    <section class="panel">
      <div class="ph"><h2><i class="fas fa-history"></i> Na-dispatch Ngayon</h2></div>
      <div class="recent" id="recent"></div>
    </section>
  </div>
</main>

<div class="toast" id="toast"></div>

<script>
const API  = <?= json_encode(APP_URL . '/api/dispatch.php') ?>;
const CSRF = { name: <?= json_encode(CSRF_TOKEN_NAME) ?>, value: <?= json_encode(csrfToken()) ?> };
let state = { queue: [], bookings: [] };
let knownBookings = null;
let busy = false;

function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function peso(n) { return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function ago(s) { s = Number(s) || 0; return s < 60 ? s + 's' : Math.floor(s / 60) + 'm ' + (s % 60) + 's'; }
function toast(msg, ok) {
  const t = document.getElementById('toast');
  t.textContent = msg; t.className = 'toast ' + (ok ? 'ok' : 'err'); t.style.display = 'block';
  clearTimeout(toast._t); toast._t = setTimeout(() => t.style.display = 'none', 3800);
}
function beep() {
  try {
    const a = new (window.AudioContext || window.webkitAudioContext)(), o = a.createOscillator(), g = a.createGain();
    o.connect(g); g.connect(a.destination); o.frequency.value = 880; g.gain.value = 0.15;
    o.start(); setTimeout(() => { o.frequency.value = 1175; }, 180); setTimeout(() => { o.stop(); a.close(); }, 420);
  } catch (e) {}
  if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
}

function post(data) {
  const body = new URLSearchParams(Object.assign({ [CSRF.name]: CSRF.value }, data));
  return fetch(API, { method: 'POST', credentials: 'same-origin', body })
    .then(r => r.json())
    .then(res => { if (res.message === 'session_expired') location.reload(); return res; });
}
function act(data, confirmMsg) {
  if (confirmMsg && !confirm(confirmMsg)) return;
  busy = true;
  post(data).then(res => { toast(res.message, res.success); })
    .catch(() => toast('Network error. Subukan ulit.', false))
    .finally(() => { busy = false; load(); });
}

function renderBookings() {
  const el = document.getElementById('bookings');
  const q = state.queue;
  document.getElementById('bkCount').textContent = state.bookings.length;
  document.getElementById('bkCount').className = 'count' + (state.bookings.length ? ' hot' : '');
  if (!state.bookings.length) { el.innerHTML = '<div class="empty"><i class="fas fa-mug-hot"></i>Walang naghihintay na pasahero.</div>'; return; }
  const first = q[0];
  el.innerHTML = state.bookings.map(b => {
    const opts = q.map(d => `<option value="${d.queue_id}">#${d.position} ${esc(d.full_name)}${d.plate_number ? ' · ' + esc(d.plate_number) : ''}</option>`).join('');
    const mainBtn = first
      ? `<button class="btn btn-go" onclick="dispatchTo(${b.id}, 0)"><i class="fas fa-paper-plane"></i> I-dispatch kay #1 ${esc(first.full_name)}${first.plate_number ? ' (' + esc(first.plate_number) + ')' : ''}</button>`
      : `<button class="btn btn-go" disabled><i class="fas fa-ban"></i> Walang driver sa pila</button>`;
    return `<div class="bk">
      <div class="bk-top">
        <div><div class="bk-name">${esc(b.passenger_name)}<a href="tel:${esc(b.passenger_phone)}"><i class="fas fa-phone"></i> ${esc(b.passenger_phone)}</a></div>
          <div class="bk-meta">#${esc(b.booking_code)} · ${b.payment_method === 'wallet' ? 'Bayad na (Wallet)' : 'Cash sa driver'} · <span class="wait"><i class="fas fa-clock"></i> ${ago(b.waiting_secs)}</span></div></div>
        <div class="bk-fare">${peso(b.fare_total)}<small>${Number(b.distance_km || 0).toFixed(1)} km</small></div>
      </div>
      <div class="bk-route"><i class="fas fa-flag-checkered" style="color:#1A2B5E"></i> ${esc(b.dropoff_address)}</div>
      <div class="bk-actions">
        ${mainBtn}
        ${q.length > 1 ? `<select class="pick" id="pick${b.id}"><option value="">Ibang driver sa pila…</option>${opts}</select><button class="btn" onclick="dispatchPicked(${b.id})"><i class="fas fa-user-check"></i> I-assign</button>` : ''}
        <button class="btn" onclick="act({action:'release', booking_id:${b.id}}, 'I-release sa labas? Makikita ito ng mga driver na wala sa terminal.')"><i class="fas fa-share-square"></i> Release sa labas</button>
        <button class="btn btn-danger" onclick="cancelBooking(${b.id})"><i class="fas fa-times"></i> Cancel</button>
      </div>
    </div>`;
  }).join('');
}

function renderQueue() {
  const el = document.getElementById('queue');
  document.getElementById('qCount').textContent = state.queue.length;
  if (!state.queue.length) { el.innerHTML = '<div class="empty"><i class="fas fa-motorcycle"></i>Wala pang nakapila.</div>'; return; }
  el.innerHTML = state.queue.map((d, i) => `<div class="q ${i === 0 ? 'first' : ''}">
    <div class="pos">${d.position}</div>
    <div class="q-info">
      <div class="q-name">${esc(d.full_name)}${d.low_balance ? '<span class="flag low">LOW WALLET</span>' : ''}${Number(d.is_online) ? '' : '<span class="flag off">OFFLINE</span>'}</div>
      <div class="q-sub">${esc(d.plate_number || '—')} · ${esc(d.driver_code)} · since ${new Date(d.joined_at.replace(' ', 'T')).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}</div>
    </div>
    <button class="icon-btn" title="Ilipat sa hulihan" onclick="act({action:'move_end', queue_id:${d.queue_id}})"><i class="fas fa-level-down-alt"></i></button>
    <button class="icon-btn red" title="Alisin sa pila" onclick="act({action:'remove', queue_id:${d.queue_id}}, 'Alisin ang driver na ito sa pila?')"><i class="fas fa-user-minus"></i></button>
  </div>`).join('');
}

function renderRecent(rows) {
  const el = document.getElementById('recent');
  el.innerHTML = rows.length ? rows.map(r => `<div class="r"><div><b>${esc(r.driver_name)}</b> → ${esc(r.passenger_name)}<br><span>#${esc(r.booking_code)} · ${esc(r.status)}</span></div><span>${new Date(r.dispatched_at.replace(' ', 'T')).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}</span></div>`).join('')
    : '<div class="empty" style="padding:20px">Wala pa.</div>';
}

function dispatchTo(bookingId, queueId) { act({ action: 'dispatch', booking_id: bookingId, queue_id: queueId }); }
function dispatchPicked(bookingId) {
  const v = document.getElementById('pick' + bookingId)?.value;
  if (!v) { toast('Pumili ng driver sa listahan.', false); return; }
  dispatchTo(bookingId, v);
}
function cancelBooking(id) {
  const reason = prompt('Dahilan ng pag-cancel (makikita ng pasahero):', 'Walang available na tricycle');
  if (reason === null) return;
  act({ action: 'cancel', booking_id: id, reason });
}
function addDriver(e) {
  e.preventDefault();
  const q = document.getElementById('addQ').value.trim();
  if (!q) return;
  busy = true;
  post({ action: 'add_driver', q }).then(res => { toast(res.message, res.success); if (res.success) document.getElementById('addQ').value = ''; })
    .catch(() => toast('Network error.', false)).finally(() => { busy = false; load(); });
}

function load() {
  if (busy) return;
  // Don't redraw while the dispatcher is picking from a dropdown or typing
  const a = document.activeElement;
  const interacting = a && (a.tagName === 'SELECT');
  fetch(API + '?action=state&t=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' })
    .then(r => r.json())
    .then(res => {
      if (!res.success) { if (res.message === 'session_expired') location.href = <?= json_encode(APP_URL . '/login.php') ?>; return; }
      document.getElementById('conn').style.background = '#00C853';
      const d = res.data;
      const ids = d.bookings.map(b => b.id);
      if (knownBookings && ids.some(id => !knownBookings.includes(id))) { beep(); toast('May bagong pasahero!', true); }
      knownBookings = ids;
      state = d;
      document.getElementById('todayCount').textContent = d.today;
      document.getElementById('clock').textContent = d.time;
      if (!interacting) renderBookings();
      renderQueue();
      renderRecent(d.recent);
    })
    .catch(() => { document.getElementById('conn').style.background = '#FF5252'; });
}
load();
setInterval(load, 4000);
</script>
</body>
</html>
