/* ============================================================
   TRILINK – assets/js/app.js
   Screen nav, drawer, fare calc, form submit, toast
   ============================================================ */

// ── SCREEN NAV ────────────────────────────────────────────────
function showScreen(id) {
  // Block opening a new booking form while a ride/delivery/order is already active
  if ((id === 'screenRide' || id === 'screenDelivery') && typeof ACTIVE_TRIP !== 'undefined' && ACTIVE_TRIP) {
    const labels = { ride: 'ride', delivery: 'delivery', order: 'order' };
    const label = labels[ACTIVE_TRIP.type] || 'trip';
    if (typeof showToast === 'function') {
      showToast(`May kasalukuyan ka nang aktibong ${label} (#${ACTIVE_TRIP.code}). Kumpletuhin muna ito bago mag-book ulit.`, 'info');
    }
    setTimeout(() => {
      window.location.href = APP_URL + '/pages/tracking.php?code=' + encodeURIComponent(ACTIVE_TRIP.code) + '&type=' + ACTIVE_TRIP.type;
    }, 900);
    return;
  }

  document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
  const el = document.getElementById(id);
  if (el) el.classList.add('active');

  // Scroll screens container back to top on screen change
  const screensEl = document.querySelector('.screens');
  if (screensEl) screensEl.scrollTop = 0;

  // Bottom nav active state
  document.querySelectorAll('.bn').forEach(b => {
    b.classList.toggle('active', b.dataset.screen === id);
  });
  if (id === 'screenHome') {
    document.querySelectorAll('.bn')[0]?.classList.add('active');
  }

  // Init maps lazily — use flags to prevent duplicate init
  if (id === 'screenRide') {
    setTimeout(() => {
      if (typeof initRideMap === 'function') {
        initRideMap();                        // safe to call multiple times (guards inside)
        if (typeof rideMap !== 'undefined' && rideMap) rideMap.invalidateSize();
      }
      if (typeof setupAutocomplete === 'function' && !document.getElementById('pickupAddr')?._acDone) {
        setupAutocomplete('pickupAddr',  'ride', 'pickup');
        setupAutocomplete('dropoffAddr', 'ride', 'dropoff');
        const el = document.getElementById('pickupAddr');
        if (el) el._acDone = true;
      }
    }, 220);
  }
  if (id === 'screenDelivery') {
    setTimeout(() => {
      if (typeof initDelivMap === 'function') {
        initDelivMap();
        if (typeof delivMap !== 'undefined' && delivMap) delivMap.invalidateSize();
      }
      if (typeof setupAutocomplete === 'function' && !document.getElementById('delivPickupAddrInput')?._acDone) {
        setupAutocomplete('delivPickupAddrInput',  'deliv', 'pickup');
        setupAutocomplete('delivDropoffAddrInput', 'deliv', 'dropoff');
        const el = document.getElementById('delivPickupAddrInput');
        if (el) el._acDone = true;
      }
    }, 220);
  }
}

// ── DRAWER ────────────────────────────────────────────────────
function openDrawer() {
  document.getElementById('sideDrawer')?.classList.remove('hidden');
  document.getElementById('drawerOverlay')?.classList.remove('hidden');
}
function closeDrawer() {
  document.getElementById('sideDrawer')?.classList.add('hidden');
  document.getElementById('drawerOverlay')?.classList.add('hidden');
}
document.getElementById('menuBtn')?.addEventListener('click', openDrawer);
document.getElementById('drawerClose')?.addEventListener('click', closeDrawer);
document.getElementById('drawerOverlay')?.addEventListener('click', closeDrawer);

// ── BANNER CAROUSEL ───────────────────────────────────────────
let bannerIdx = 0;
function startCarousel() {
  const track = document.getElementById('bannerTrack');
  const dots  = document.querySelectorAll('.bdot');
  if (!track || !dots.length) return;
  const total = document.querySelectorAll('.banner-slide').length;
  setInterval(() => {
    bannerIdx = (bannerIdx + 1) % total;
    track.style.transform = `translateX(-${bannerIdx * 100}%)`;
    dots.forEach((d, i) => d.classList.toggle('active', i === bannerIdx));
  }, 3800);
}
document.addEventListener('DOMContentLoaded', startCarousel);

// ── PAYMENT SELECTOR (Wallet vs Cash) — scoped per form ────────
document.querySelectorAll('.pay-opt[data-pay]').forEach(opt => {
  opt.addEventListener('click', () => {
    const group = opt.closest('.pay-opts');
    if (group) group.querySelectorAll('.pay-opt').forEach(o => o.classList.remove('active'));
    opt.classList.add('active');

    const form = opt.closest('form');
    const hidden = form?.querySelector('input[name="payment_method"]');
    if (hidden) hidden.value = opt.dataset.pay;

    // Show/hide "who pays cash" sub-selector (delivery only)
    const cashPayerGroup = form?.querySelector('.cash-payer-group');
    if (cashPayerGroup) cashPayerGroup.style.display = (opt.dataset.pay === 'cash') ? 'flex' : 'none';
  });
});

// ── CASH PAYER SELECTOR (Sender vs Recipient) — delivery only ──
document.querySelectorAll('.pay-opt[data-payer]').forEach(opt => {
  opt.addEventListener('click', () => {
    const group = opt.closest('.pay-opts');
    if (group) group.querySelectorAll('.pay-opt').forEach(o => o.classList.remove('active'));
    opt.classList.add('active');

    const form = opt.closest('form');
    const hidden = form?.querySelector('input[name="cash_payer"]');
    if (hidden) hidden.value = opt.dataset.payer;
  });
});

// ── VEHICLE SELECTION → fare recalc ───────────────────────────
document.querySelectorAll('.veh-card').forEach(card => {
  card.addEventListener('click', () => {
    document.querySelectorAll('.veh-card').forEach(c => c.classList.remove('sel'));
    card.classList.add('sel');
    recalcFare();
  });
});

function recalcFare() {
  if (typeof FARE_MATRIX === 'undefined') return;
  const vehicle = document.querySelector('.veh-card.sel input')?.value
               || document.querySelector('.veh-card:has(input:checked) input')?.value
               || 'tricycle';
  const distKm  = parseFloat(document.getElementById('hidDist')?.value || '1');
  const m       = FARE_MATRIX[vehicle];
  if (!m) return;
  const surge = parseFloat(m.surge_multiplier || 1);
  const raw   = (parseFloat(m.base_fare) + parseFloat(m.per_km_rate) * distKm) * surge;
  const fare  = Math.max(raw, parseFloat(m.min_fare || m.base_fare));

  const fd = document.getElementById('fareDisplay');
  const hf = document.getElementById('hidFare');
  const dd = document.getElementById('distDisplay');
  const ed = document.getElementById('etaDisplay');

  if (fd) fd.textContent = '₱ ' + fare.toFixed(2);
  if (hf) hf.value = fare.toFixed(2);
  if (dd && distKm > 0) dd.textContent = distKm.toFixed(1) + ' km';
  if (ed && distKm > 0) ed.textContent = '~' + Math.ceil(distKm * 3) + ' mins';
}

// ── PACKAGE TYPE → delivery fee recalc ───────────────────────
document.querySelectorAll('.pkg-card').forEach(card => {
  card.addEventListener('click', () => {
    document.querySelectorAll('.pkg-card').forEach(c => c.classList.remove('sel'));
    card.classList.add('sel');
    recalcDelivFee();
  });
});

function recalcDelivFee() {
  const type    = document.querySelector('.pkg-card.sel input')?.value || 'small';
  const dist    = parseFloat(document.getElementById('hidDelDist')?.value || '1');
  const base    = { small:60, medium:90, large:130 };
  const perKm   = { small:5,  medium:7,  large:10  };
  const fee     = Math.max((base[type]||60) + ((perKm[type]||5) * dist), base[type]||60);
  const el      = document.getElementById('delFeeDisplay');
  const hf      = document.getElementById('hidDelFee');
  if (el) el.textContent = '₱ ' + fee.toFixed(2);
  if (hf) hf.value = fee.toFixed(2);
}

// ── TERMINAL vs NOT IN TERMINAL (ride) ────────────────────────
(function () {
  const modes = document.querySelectorAll('input[name="booking_mode"]');
  if (!modes.length) return;
  const pick    = document.getElementById('terminalPick');
  const sel     = document.getElementById('terminalSelect');
  const info    = document.getElementById('terminalInfo');
  const pickup  = document.getElementById('pickupAddr');
  const label   = document.getElementById('pickupLabel');
  const gpsBtn  = document.getElementById('pickupGpsBtn');
  const latEl   = document.getElementById('ridePickupLat');
  const lngEl   = document.getElementById('ridePickupLng');

  function lockVehicle(type) {
    document.querySelectorAll('.veh-card').forEach(card => {
      const input = card.querySelector('input');
      const match = !type || input.value === type;
      card.classList.toggle('locked', !match);
      if (type && match) {
        input.checked = true;
        document.querySelectorAll('.veh-card').forEach(c => c.classList.remove('sel'));
        card.classList.add('sel');
      }
    });
    recalcFare();
  }

  function applyTerminal() {
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) {
      pickup.value = ''; latEl.value = ''; lngEl.value = '';
      lockVehicle(null);
      return;
    }
    pickup.value = opt.dataset.label;
    const lat = parseFloat(opt.dataset.lat), lng = parseFloat(opt.dataset.lng);
    if (!isNaN(lat) && !isNaN(lng)) {
      latEl.value = lat; lngEl.value = lng;
      if (typeof setPickupFromLatLngNoGeocode === 'function' && typeof rideMap !== 'undefined' && rideMap) {
        setPickupFromLatLngNoGeocode(lat, lng, 'ride', opt.dataset.label);
      }
    }
    const q = parseInt(opt.dataset.queue || '0', 10);
    info.innerHTML = (q > 0 ? '<b>' + q + ' tricycle</b> ang nakapila ngayon. ' : '<b>Wala pang nakapila</b> ngayon — maaaring matagalan. ')
      + 'Ang dispatcher ang mag-a-assign sa\'yo ng nasa unahan ng pila.';
    lockVehicle(opt.dataset.vehicle);
  }

  function applyMode() {
    const terminal = document.querySelector('input[name="booking_mode"]:checked')?.value === 'terminal';
    pick.style.display = terminal ? 'block' : 'none';
    pickup.readOnly = terminal;
    if (gpsBtn) gpsBtn.style.display = terminal ? 'none' : '';
    if (label) label.textContent = terminal ? 'Terminal (Pick-up)' : 'Pick-up Location';
    if (terminal) {
      applyTerminal();
    } else {
      sel.value = '';
      pickup.value = ''; latEl.value = ''; lngEl.value = '';
      lockVehicle(null);
    }
  }

  modes.forEach(m => m.addEventListener('change', applyMode));
  sel.addEventListener('change', applyTerminal);
})();

// ── RIDE FORM SUBMIT ──────────────────────────────────────────
document.getElementById('rideForm')?.addEventListener('submit', function(e) {
  e.preventDefault();
  const pickup  = document.getElementById('pickupAddr')?.value.trim();
  const dropoff = document.getElementById('dropoffAddr')?.value.trim();
  const isTerminal = document.querySelector('input[name="booking_mode"]:checked')?.value === 'terminal';
  if (isTerminal && !document.getElementById('terminalSelect')?.value) { showToast('Piliin ang terminal kung saan ka sasakay.'); return; }
  if (!pickup)  { showToast('Please enter pick-up location.'); return; }
  if (!dropoff) { showToast('Please enter drop-off location.'); return; }

  // Ensure distance set
  const hd = document.getElementById('hidDist');
  if (!hd?.value || parseFloat(hd.value) <= 0) {
    hd.value = (Math.random() * 3 + 1).toFixed(1);
  }
  recalcFare();

  const btn = document.getElementById('rideSubmitBtn');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Finding ride…'; }

  fetch(APP_URL + '/api/booking.php', { method:'POST', body: new FormData(this) })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        showToast('Booking created!', 'success');
        setTimeout(() => { window.location.href = res.data.redirect; }, 1000);
      } else {
        showToast(res.message || 'Booking failed.');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-search"></i> Find Ride'; }
      }
    })
    .catch(() => {
      showToast('Network error. Please try again.');
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-search"></i> Find Ride'; }
    });
});

// ── DELIVERY FORM SUBMIT ──────────────────────────────────────
document.getElementById('delivForm')?.addEventListener('submit', function(e) {
  e.preventDefault();
  const hd = document.getElementById('hidDelDist');
  if (!hd?.value || parseFloat(hd.value) <= 0) hd.value = (Math.random()*3+1).toFixed(1);
  recalcDelivFee();

  fetch(APP_URL + '/api/delivery.php', { method:'POST', body: new FormData(this) })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        showToast('Delivery booked!', 'success');
        setTimeout(() => { window.location.href = res.data.redirect; }, 1000);
      } else {
        showToast(res.message || 'Failed.');
      }
    });
});

// ── TOAST ─────────────────────────────────────────────────────
function showToast(msg, type = 'info') {
  document.querySelectorAll('.tl-toast').forEach(t => t.remove());
  const bg   = { info:'#1A2B5E', success:'#2E7D32', error:'#C62828' }[type] || '#1A2B5E';
  const icon = { info:'fa-info-circle', success:'fa-check-circle', error:'fa-exclamation-circle' }[type];
  const el   = document.createElement('div');
  el.className = 'tl-toast';
  el.innerHTML = `<i class="fas ${icon}"></i> ${msg}`;
  Object.assign(el.style, {
    position:'fixed', bottom:'76px', left:'50%', transform:'translateX(-50%)',
    background:bg, color:'#fff', padding:'12px 20px', borderRadius:'30px',
    fontSize:'13px', fontWeight:'700', display:'flex', alignItems:'center', gap:'8px',
    boxShadow:'0 4px 20px rgba(0,0,0,0.22)', zIndex:'9999', whiteSpace:'nowrap',
    fontFamily:"'Inter',sans-serif", maxWidth:'90vw', overflow:'hidden',
    textOverflow:'ellipsis',
  });
  document.body.appendChild(el);
  setTimeout(() => { el.style.opacity='0'; el.style.transition='opacity 0.3s'; setTimeout(()=>el.remove(),300); }, 3000);
}

// ── NOTIF BTN ─────────────────────────────────────────────────
document.getElementById('notifBtn')?.addEventListener('click', () => {
  showToast('No new notifications.');
});

// ── INIT ──────────────────────────────────────────────────────
recalcFare();
