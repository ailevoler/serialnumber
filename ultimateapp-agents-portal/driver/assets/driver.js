/* URide Driver App: selfie capture, GPS heartbeat, nearby request feed, trip page updates. */
(() => {
  /* ---------- Confirm dialogs on sensitive forms ---------- */
  document.querySelectorAll('form[data-confirm]').forEach(f => f.addEventListener('submit', e => {
    if (!window.confirm(f.dataset.confirm)) e.preventDefault();
  }));
  /* ---------- Disable submit buttons after one tap ---------- */
  document.addEventListener('submit', e => {
    if (e.defaultPrevented) return;
    const b = e.target.querySelector('button[type="submit"]');
    if (b) setTimeout(() => { b.disabled = true; }, 0);
  });

  /* ---------- Top-up amount chips ---------- */
  document.querySelectorAll('[data-topup-form] [data-amount]').forEach(chip => chip.addEventListener('click', () => {
    const input = chip.closest('form').querySelector('input[name="amount"]');
    input.value = chip.dataset.amount;
    chip.parentElement.querySelectorAll('.chip').forEach(c => c.classList.toggle('active', c === chip));
  }));

  /* ---------- Top-up method picker ---------- */
  const methodForm = document.querySelector('[data-topup-form]');
  if (methodForm && methodForm.querySelector('input[name="method"]')) {
    const help = methodForm.querySelector('[data-method-help]');
    const btn = methodForm.querySelector('[data-method-submit]');
    const texts = {
      qrph: ['Generate QR Ph', 'Pay the QR with GCash, Maya or any bank app. Your wallet is credited automatically once paid.'],
      mctc: ['Create MCTC request', 'You get a QR to show at any MCTC top-up center. Pay cash there; the agent scans it and your wallet is credited right away. Valid 24 hours.'],
      boracay_cash: ['Create BCash request', 'You get a QR and link. Pay it from an Ultimate App account with BCash (yours or someone else\'s). Valid 30 minutes. No fee.'],
    };
    const sync = () => {
      const m = (methodForm.querySelector('input[name="method"]:checked') || {}).value;
      if (texts[m]) { btn.textContent = texts[m][0]; help.textContent = texts[m][1]; }
    };
    methodForm.querySelectorAll('input[name="method"]').forEach(r => r.addEventListener('change', sync));
    sync();
  }

  /* ---------- QR codes (MCTC / BCash requests) ---------- */
  document.querySelectorAll('[data-qr-text]').forEach(el => {
    try {
      const q = qrcode(0, 'M'); q.addData(el.dataset.qrText); q.make();
      el.innerHTML = q.createSvgTag(6, 2);
    } catch (e) { el.textContent = 'QR unavailable — use the request link below.'; }
  });
  const req = document.querySelector('[data-topup-request]');
  if (req && req.dataset.status === 'pending') {
    const st = req.querySelector('[data-tq-status]');
    const poll = async () => {
      if (document.hidden) return;
      try {
        const r = await fetch('topup-status.php?reference=' + encodeURIComponent(req.dataset.reference), {credentials: 'same-origin', cache: 'no-store'});
        const j = await r.json();
        if (j.status === 'paid') {
          req.querySelector('.tq-card').classList.add('paid');
          st.innerHTML = '<span class="tq-dot ok"></span> Payment received! Adding it to your wallet…';
          if (navigator.vibrate) navigator.vibrate([60, 40, 60]);
          setTimeout(() => { location.href = 'wallet.php'; }, 1200);
        } else if (j.status !== 'pending') { location.reload(); }
      } catch (e) { /* try again */ }
    };
    // Check for ~15 minutes, then stop so an idle page does not keep using server connections.
    let n = 0;
    const iv = setInterval(() => { if (++n > 110) { clearInterval(iv); st.textContent = 'Stopped checking automatically. Refresh this page to check again.'; return; } poll(); }, 8000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden && n <= 110) poll(); });
  }

  /* ---------- Selfie capture (registration / account) ---------- */
  const box = document.querySelector('[data-selfie]');
  if (box) {
    const video = box.querySelector('[data-selfie-video]');
    const preview = box.querySelector('[data-selfie-preview]');
    const empty = box.querySelector('[data-selfie-empty]');
    const data = document.querySelector('[data-selfie-data]');
    const start = document.querySelector('[data-selfie-start]');
    const snap = document.querySelector('[data-selfie-snap]');
    const retake = document.querySelector('[data-selfie-retake]');
    const file = document.querySelector('[data-selfie-file]');
    const status = document.querySelector('[data-selfie-status]');
    let stream = null;
    const stop = () => { if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; } video.hidden = true; };
    const show = src => { preview.src = src; preview.hidden = false; empty.hidden = true; video.hidden = true; snap.hidden = true; retake.hidden = false; start.hidden = true; };
    start.addEventListener('click', async () => {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { status.textContent = 'Camera not available here. Use "Upload photo" instead.'; file.click(); return; }
      try {
        stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'user', width: {ideal: 960}, height: {ideal: 960}}, audio: false});
        video.srcObject = stream; await video.play();
        video.hidden = false; empty.hidden = true; preview.hidden = true;
        start.hidden = true; snap.hidden = false; retake.hidden = true;
        status.textContent = 'Center your face in the frame, then tap Take photo.';
      } catch (e) { status.textContent = 'Camera access was blocked. Allow the camera or use "Upload photo".'; }
    });
    snap.addEventListener('click', () => {
      const size = Math.min(video.videoWidth, video.videoHeight);
      if (!size) return;
      const c = document.createElement('canvas');
      const out = Math.min(720, size);
      c.width = out; c.height = out;
      const x = c.getContext('2d');
      x.translate(out, 0); x.scale(-1, 1);
      x.drawImage(video, (video.videoWidth - size) / 2, (video.videoHeight - size) / 2, size, size, 0, 0, out, out);
      const url = c.toDataURL('image/jpeg', 0.88);
      data.value = url; file.value = '';
      stop(); show(url);
      status.textContent = 'Selfie ready.';
    });
    retake.addEventListener('click', () => { data.value = ''; preview.hidden = true; empty.hidden = false; retake.hidden = true; start.hidden = false; start.click(); });
    file.addEventListener('change', () => {
      const f = file.files && file.files[0];
      if (!f) return;
      data.value = ''; stop();
      const r = new FileReader(); r.onload = () => show(r.result); r.readAsDataURL(f);
      status.textContent = 'Photo selected.';
    });
    window.addEventListener('pagehide', stop);
    const form = document.querySelector('[data-driver-form]');
    if (form) form.addEventListener('submit', e => {
      if (data.value === '' && !(file.files && file.files.length)) {
        e.preventDefault();
        status.textContent = 'Please take a selfie before submitting.';
        box.scrollIntoView({behavior: 'smooth', block: 'center'});
      }
    }, true);
  }

  /* ---------- GPS + heartbeat (Drive screen and trip page) ---------- */
  const drive = document.querySelector('[data-drive]');
  const ridePage = document.querySelector('[data-ride-page]');
  const root = drive || ridePage;
  if (!root) return;
  const online = drive ? drive.dataset.online === '1' : ridePage.dataset.live === '1';
  const gpsLine = document.querySelector('[data-gps]');
  const csrf = root.dataset.csrf;
  let pos = null;
  let seen = new Set();
  let timer = null;

  const beep = () => {
    try {
      const a = new (window.AudioContext || window.webkitAudioContext)();
      [0, 0.22].forEach(t => { const o = a.createOscillator(); const g = a.createGain(); o.frequency.value = 880; o.connect(g); g.connect(a.destination); g.gain.setValueAtTime(0.2, a.currentTime + t); g.gain.exponentialRampToValueAtTime(0.001, a.currentTime + t + 0.18); o.start(a.currentTime + t); o.stop(a.currentTime + t + 0.2); });
    } catch (e) { /* sound is optional */ }
    if (navigator.vibrate) navigator.vibrate([120, 60, 120]);
  };

  const render = list => {
    const wrap = document.querySelector('[data-feed]');
    const tpl = document.querySelector('[data-feed-tpl]');
    const count = document.querySelector('[data-feed-count]');
    if (!wrap || !tpl) return;
    count.textContent = list.length ? list.length + ' nearby' : '';
    if (!list.length) { wrap.innerHTML = '<p class="d-empty">No requests nearby right now. Stay online — new ones appear automatically.</p>'; return; }
    let fresh = false;
    wrap.innerHTML = '';
    list.forEach(r => {
      if (!seen.has(r.id)) { fresh = true; seen.add(r.id); }
      const n = tpl.content.cloneNode(true);
      const f = k => n.querySelector('[data-f="' + k + '"]');
      f('fare').textContent = '₱' + r.fare;
      f('pay').textContent = r.payment === 'cash' ? 'Cash' : 'Credits';
      f('pay').className = 'd-pay ' + r.payment;
      f('away').textContent = r.pickup_km.toFixed(1) + ' km away';
      f('pickup').textContent = r.pickup;
      f('dropoff').textContent = r.dropoff;
      f('passenger').textContent = r.passenger;
      f('trip').textContent = r.trip_km.toFixed(1) + ' km trip';
      f('net').textContent = 'You earn ₱' + r.net;
      if (r.note) { f('note').hidden = false; f('note').textContent = 'Note: ' + r.note; }
      n.querySelectorAll('input[name="csrf"]').forEach(i => { i.value = csrf; });
      n.querySelectorAll('input[name="ride_id"]').forEach(i => { i.value = r.id; });
      wrap.append(n);
    });
    if (fresh) beep();
  };

  const pulse = async () => {
    if (document.hidden) return;
    const body = new URLSearchParams({csrf});
    if (pos) { body.set('lat', pos.lat); body.set('lng', pos.lng); }
    if (ridePage) body.set('ride_id', ridePage.dataset.ride);
    try {
      const r = await fetch('api.php', {method: 'POST', body, credentials: 'same-origin', cache: 'no-store'});
      if (r.status === 401) { location.href = 'login.php'; return; }
      if (!r.ok) return;
      const j = await r.json();
      const chip = document.querySelector('.d-wallet-chip');
      if (chip && j.wallet) chip.lastChild.textContent = '₱' + j.wallet;
      if (drive) {
        if (j.active && Number(drive.dataset.active) !== j.active.id) { location.href = 'ride.php?id=' + j.active.id; return; }
        if (online && !j.online) { location.reload(); return; }
        if (online) render(j.requests || []);
        if (gpsLine && online) gpsLine.textContent = j.gps === 'outside' ? 'Your location is outside the service area.' : (j.live ? 'Location on · visible to nearby passengers' : 'Waiting for your GPS location…');
      }
      if (ridePage && j.ride && j.ride.status !== ridePage.dataset.status) {
        if (['cancelled', 'expired'].includes(j.ride.status)) { alert('The passenger cancelled this ride.'); }
        location.reload();
      }
    } catch (e) { /* offline for a moment: try again */ }
  };

  if (online) {
    if (navigator.geolocation) {
      navigator.geolocation.watchPosition(p => {
        const first = !pos;
        pos = {lat: p.coords.latitude.toFixed(6), lng: p.coords.longitude.toFixed(6)};
        if (first) pulse();
        document.dispatchEvent(new CustomEvent('uride:driver-pos', {detail: {lat: Number(pos.lat), lng: Number(pos.lng)}}));
      }, err => {
        if (gpsLine) gpsLine.textContent = err.code === 1 ? 'Location permission is off. Allow location for this site to receive requests.' : 'Cannot read your GPS location. Move to an open area.';
      }, {enableHighAccuracy: true, maximumAge: 5000, timeout: 20000});
    } else if (gpsLine) {
      gpsLine.textContent = 'This device cannot share its location.';
    }
    pulse();
    timer = setInterval(pulse, 8000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) pulse(); });
  }
})();
