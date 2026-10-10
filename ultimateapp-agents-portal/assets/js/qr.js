(() => {
  const CODE_RE = /^UA-[A-F0-9]{10}$/;

  /* ---------- Pay form: block double submits ---------- */
  const payForm = document.querySelector('[data-pay-form]');
  if (payForm) {
    payForm.addEventListener('submit', event => {
      const button = payForm.querySelector('[data-pay-submit]');
      if (button.disabled) { event.preventDefault(); return; }
      button.disabled = true;
      button.textContent = 'Processing...';
    });
  }

  /* ---------- P2M: live charge breakdown ---------- */
  const p2m = document.querySelector('[data-p2m]');
  if (p2m) {
    const amountInput = p2m.querySelector('#pay-amount');
    const f = c => (c / 100).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const out = k => p2m.querySelector('[data-sum-' + k + ']');
    const calc = () => {
      const opt = p2m.querySelector('.p2m-source input:checked');
      const src = opt ? opt.closest('.p2m-source') : null;
      p2m.querySelectorAll('.p2m-source').forEach(el => el.classList.toggle('selected', el === src));
      const raw = amountInput.value.trim().replace(/,/g, '');
      const amount = /^\d{1,7}(\.\d{1,2})?$/.test(raw) ? Math.round(Number(raw) * 100) : 0;
      if (!src) return;
      const fee = Math.floor((amount * Number(src.dataset.bp) + 5000) / 10000) + (amount ? Number(src.dataset.fixed) : 0);
      const customerPays = src.dataset.bearer === 'customer';
      const total = amount + (customerPays ? fee : 0);
      const unit = src.dataset.source === 'credits' ? ' Credits' : ' PHP';
      out('amount').textContent = f(amount);
      out('fee').textContent = customerPays ? f(fee) : '0.00';
      out('total').textContent = f(total) + unit;
      const note = out('note');
      const min = Number(p2m.dataset.min), max = Number(p2m.dataset.max), bal = Number(src.dataset.balance);
      note.className = '';
      if (amount && (amount < min || amount > max)) { note.textContent = 'Amount must be between ' + f(min) + ' and ' + f(max) + '.'; note.className = 'warn'; }
      else if (amount && total > bal) { note.textContent = 'Not enough balance. You have ' + f(bal) + unit + '.'; note.className = 'warn'; }
      else if (!customerPays && fee) { note.textContent = 'The merchant pays the ' + f(fee) + ' charge.'; }
      else note.textContent = '';
    };
    p2m.addEventListener('input', calc);
    p2m.addEventListener('change', calc);
    calc();
  }

  /* ---------- Turn any scanned text into an in-app destination ---------- */
  const destinationFor = (raw) => {
    const text = String(raw || '').trim();
    if (/^UM-[A-F0-9]{10}$/.test(text.toUpperCase())) {
      return new URL('pay-merchant.php?m=' + encodeURIComponent(text.toUpperCase()), location.href);
    }
    if (CODE_RE.test(text.toUpperCase())) {
      return new URL('pay.php?to=' + encodeURIComponent(text.toUpperCase()), location.href);
    }
    let url;
    try { url = new URL(text); } catch (error) { url = null; }
    if (url) {
      const to = (url.searchParams.get('to') || '').toUpperCase();
      if (/\/pay\.php$/.test(url.pathname) && CODE_RE.test(to)) {
        // Only the QR ID and requested amount are taken; the payment always happens on this app.
        const target = new URL('pay.php', location.href);
        target.searchParams.set('to', to);
        const amount = url.searchParams.get('amount');
        if (amount && /^\d{1,5}(\.\d{1,2})?$/.test(amount)) target.searchParams.set('amount', amount);
        if (url.searchParams.get('fixed') === '1') target.searchParams.set('fixed', '1');
        return target;
      }
      const m = (url.searchParams.get('m') || '').toUpperCase();
      if (/\/pay-merchant\.php$/.test(url.pathname) && /^UM-[A-F0-9]{10}$/.test(m)) {
        const target = new URL('pay-merchant.php', location.href);
        target.searchParams.set('m', m);
        const amount = url.searchParams.get('amount');
        if (amount && /^\d{1,7}(\.\d{1,2})?$/.test(amount)) target.searchParams.set('amount', amount);
        return target;
      }
      if (/\/driver-topup-pay\.php$/.test(url.pathname) && /^[a-f0-9]{64}$/.test(url.searchParams.get('token') || '')) {
        // URide driver wallet top-up paid from BCash: only the token is taken; payment happens on this app.
        const target = new URL('driver-topup-pay.php', location.href);
        target.searchParams.set('token', url.searchParams.get('token'));
        return target;
      }
      if (url.origin === location.origin && /\/(?:mctc|convert)-approve\.php$/.test(url.pathname)
        && /^[a-f0-9]{64}$/.test(url.searchParams.get('token') || '')) {
        return url; // MCTC request QR: the server checks merchant access.
      }
    }
    if (/^[a-f0-9]{64}$/.test(text)) {
      throw new Error('This is an MCTC top-up or cash-out QR. Show it to an MCTC merchant to scan.');
    }
    throw new Error('This is not an Ultimate App payment QR.');
  };

  /* ---------- Scanner ---------- */
  const reader = document.getElementById('qr-reader');
  if (reader) {
    const idle = document.querySelector('[data-qr-idle]');
    const startBtn = document.querySelector('[data-qr-start]');
    const stopBtn = document.querySelector('[data-qr-stop]');
    const torchBtn = document.querySelector('[data-qr-torch]');
    const fileInput = document.querySelector('[data-qr-file]');
    const status = document.querySelector('[data-qr-status]');
    const title = document.querySelector('.qr-screen .page-head h1');
    let scanner = null;
    let running = false;
    let starting = false;
    let torchOn = false;
    let opening = false;

    const say = (message) => { status.textContent = message || ''; };

    const open = async (value) => {
      if (opening) return;
      try {
        const url = destinationFor(value);
        opening = true;
        say('QR found. Opening...');
        if (navigator.vibrate) navigator.vibrate(60);
        await stop();
        location.href = url.href;
      } catch (error) {
        say(error.message);
      }
    };

    const ensureScanner = () => {
      if (!window.Html5Qrcode) throw new Error('Camera scanning is unavailable on this browser. Use Gallery or enter the QR ID.');
      if (!scanner) scanner = new Html5Qrcode(reader.id, {verbose: false});
      return scanner;
    };

    const setupTorch = () => {
      torchBtn.disabled = true;
      torchOn = false;
      torchBtn.classList.remove('on');
      try {
        const torch = scanner.getRunningTrackCameraCapabilities().torchFeature();
        if (torch.isSupported()) torchBtn.disabled = false;
      } catch (error) { /* torch not available */ }
    };

    const start = async () => {
      if (running || starting) return;
      starting = true;
      say('Starting camera...');
      try {
        ensureScanner();
        await scanner.start(
          {facingMode: 'environment'},
          {fps: 12, aspectRatio: 1, qrbox: (w, h) => { const s = Math.floor(Math.min(w, h) * 0.72); return {width: s, height: s}; }},
          decoded => { void open(decoded); },
          () => {}
        );
        running = true;
        idle.hidden = true;
        stopBtn.hidden = false;
        reader.classList.add('live');
        setupTorch();
        say('Point the camera at an Ultimate App QR.');
      } catch (error) {
        say(error && error.message && !/NotAllowed|Permission/i.test(String(error))
          ? 'Camera unavailable. Use Gallery or enter the QR ID below.'
          : 'Camera access was blocked. Allow camera access in your browser settings, or use Gallery.');
      } finally {
        starting = false;
      }
    };

    async function stop() {
      if (scanner && running) {
        try { await scanner.stop(); } catch (error) { /* already stopped */ }
        try { scanner.clear(); } catch (error) { /* nothing to clear */ }
      }
      running = false;
      idle.hidden = false;
      stopBtn.hidden = true;
      torchBtn.disabled = true;
      torchBtn.classList.remove('on');
      reader.classList.remove('live');
    }

    startBtn.addEventListener('click', () => { void start(); });
    stopBtn.addEventListener('click', () => { void stop().then(() => say('')); });

    torchBtn.addEventListener('click', async () => {
      if (!running) return;
      try {
        torchOn = !torchOn;
        await scanner.getRunningTrackCameraCapabilities().torchFeature().apply(torchOn);
        torchBtn.classList.toggle('on', torchOn);
        torchBtn.setAttribute('aria-pressed', String(torchOn));
      } catch (error) {
        torchOn = false;
        say('Flash is not available on this camera.');
      }
    });

    fileInput.addEventListener('change', async () => {
      const file = fileInput.files && fileInput.files[0];
      fileInput.value = '';
      if (!file) return;
      try {
        ensureScanner();
        await stop();
        say('Reading QR from image...');
        const decoded = await scanner.scanFile(file, false);
        await open(decoded);
      } catch (error) {
        say(error && error.message && /Ultimate App|MCTC|unavailable/.test(error.message)
          ? error.message : 'No QR code found in that image. Try a clearer photo.');
      }
    });

    // Tabs: stop the camera on My QR, resume on Scan when permission was already given.
    const autoStart = async () => {
      try {
        if (!navigator.permissions) return;
        const perm = await navigator.permissions.query({name: 'camera'});
        if (perm.state === 'granted') void start();
      } catch (error) { /* permissions API not supported for camera */ }
    };
    document.querySelectorAll('.qr-screen [data-tab]').forEach(button => button.addEventListener('click', () => {
      const scan = button.dataset.tab === 'scan';
      if (title) title.textContent = scan ? 'Scan QR' : 'My QR';
      document.querySelectorAll('.qr-screen [data-tab]').forEach(b => b.setAttribute('aria-selected', String(b === button)));
      const url = new URL(location.href);
      if (scan) url.searchParams.delete('tab'); else url.searchParams.set('tab', 'myqr');
      history.replaceState(null, '', url);
      if (scan) void autoStart(); else void stop();
    }));
    document.addEventListener('visibilitychange', () => { if (document.hidden) void stop(); });
    window.addEventListener('pagehide', () => { void stop(); });

    if (document.getElementById('scan').classList.contains('active')) void autoStart();
  }

  /* ---------- My QR ---------- */
  const card = document.querySelector('[data-my-qr]');
  if (card) {
    const target = card.querySelector('[data-my-qr-target]');
    const amountNote = card.querySelector('[data-my-qr-amount]');
    const requestForm = document.querySelector('[data-my-qr-request]');
    const amountInput = document.getElementById('my-qr-amount');
    const copyBtn = document.querySelector('[data-my-qr-copy]');
    const saveBtn = document.querySelector('[data-my-qr-download]');
    const status = document.createElement('p');
    status.className = 'qr-status';
    status.setAttribute('role', 'status');
    document.querySelector('.my-qr-actions').after(status);
    const code = card.dataset.code;
    const name = card.querySelector('strong').textContent;
    let amount = '';
    let qr = null;

    const payUrl = () => {
      const url = new URL('pay.php', location.href);
      url.search = '';
      url.hash = '';
      url.searchParams.set('to', code);
      if (amount) { url.searchParams.set('amount', amount); url.searchParams.set('fixed', '1'); }
      return url.href;
    };

    const render = () => {
      try {
        qr = qrcode(0, 'M');
        qr.addData(payUrl());
        qr.make();
        target.innerHTML = qr.createSvgTag({cellSize: 6, margin: 0, scalable: true});
      } catch (error) {
        target.textContent = 'QR unavailable. Share your QR ID instead: ' + code;
      }
      amountNote.hidden = !amount;
      amountNote.textContent = amount ? 'Requesting ' + Number(amount).toLocaleString('en-PH', {minimumFractionDigits: 2}) + ' Credits' : '';
    };

    requestForm.addEventListener('submit', event => {
      event.preventDefault();
      const value = amountInput.value.trim();
      if (value === '') { amount = ''; render(); status.textContent = 'Amount cleared.'; return; }
      const n = Number(value);
      if (!/^\d{1,5}(\.\d{1,2})?$/.test(value) || n < 1 || n > 10000) {
        status.textContent = 'Enter an amount from 1 to 10,000 Credits.';
        return;
      }
      amount = n.toFixed(2);
      render();
      status.textContent = 'QR updated with the requested amount.';
    });

    copyBtn.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(payUrl());
        status.textContent = 'Payment link copied.';
      } catch (error) {
        status.textContent = 'Copy not available. Your QR ID is ' + code + '.';
      }
    });

    saveBtn.addEventListener('click', () => {
      if (!qr) return;
      const count = qr.getModuleCount();
      const cell = 12;
      const pad = 48;
      const size = count * cell;
      const canvas = document.createElement('canvas');
      canvas.width = size + pad * 2;
      canvas.height = size + pad * 2 + 120;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.fillStyle = '#000';
      for (let r = 0; r < count; r++) for (let c = 0; c < count; c++) {
        if (qr.isDark(r, c)) ctx.fillRect(pad + c * cell, pad + r * cell, cell, cell);
      }
      ctx.textAlign = 'center';
      ctx.font = '700 34px system-ui, sans-serif';
      ctx.fillText(name, canvas.width / 2, size + pad + 58);
      ctx.font = '500 24px system-ui, sans-serif';
      ctx.fillStyle = '#555';
      ctx.fillText(code + (amount ? '  ·  ' + amount + ' Credits' : '') + '  ·  Ultimate App Boracay', canvas.width / 2, size + pad + 98);
      canvas.toBlob(blob => {
        if (!blob) return;
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'ultimate-app-qr-' + code + '.png';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 2000);
        status.textContent = 'QR image saved.';
      }, 'image/png');
    });

    render();
  }
})();
