/* Face Login: liveness check (steps from the server, in random order) + face recognition on the phone.
 * [data-face-login="login"]: asks face-login.php for a one-time code and steps, then sends the face numbers to log in.
 * [data-face-login="enroll"]: sets up Face Login on this phone (face must match the approved ID check). */
(() => {
  const box = document.querySelector('[data-face-login]');
  if (!box || !window.UAFace) return;
  const F = window.UAFace;
  const mode = box.dataset.faceLogin;
  const $ = s => box.querySelector(s);
  const video = $('[data-video]'), overlay = $('[data-overlay]'), sayBox = $('[data-say]'), matchBox = $('[data-match]'), btn = $('[data-start]'), list = $('[data-steps]');
  const LABEL = {center: 'Look straight', left: 'Turn left', right: 'Turn right', up: 'Look up', down: 'Look down', blink: 'Blink'};
  const say = (t, tone = '') => { sayBox.textContent = t; sayBox.className = 'kyc-say' + (tone ? ' tone-' + tone : ''); };
  const show = (cls, title, text) => {
    matchBox.hidden = false; matchBox.className = 'kyc-match ' + cls; matchBox.textContent = '';
    const b = document.createElement('b'); b.textContent = title; matchBox.append(b, document.createTextNode(text || ''));
  };
  let engine = null, busy = false;

  const post = async data => {
    const body = new FormData();
    body.append('csrf', box.dataset.csrf);
    Object.entries(data).forEach(([k, v]) => body.append(k, v));
    const r = await fetch(box.dataset.endpoint, {method: 'POST', body, credentials: 'same-origin', headers: {Accept: 'application/json'}});
    if (r.status === 429) return {ok: false, error: 'Too many tries. Please wait a few minutes or use your password.'};
    try { return await r.json(); } catch (e) { return {ok: false, error: 'Something went wrong. Please reload the page and try again.'}; }
  };

  const drawSteps = steps => {
    list.textContent = '';
    steps.forEach(s => { const li = document.createElement('li'); li.dataset.step = s; li.textContent = LABEL[s] || s; list.append(li); });
  };
  const mark = (s, cls) => { const li = list.querySelector('[data-step="' + s + '"]'); if (li) li.classList.add(cls); };

  function retry(text) {
    show('bad', 'Not verified', ' ' + text);
    btn.hidden = false; btn.disabled = false; btn.textContent = 'Try again'; busy = false;
  }

  async function finish(steps, frames, log, nonce) {
    show('wait', 'Checking your face…', ' This takes a few seconds.');
    let center, other;
    try {
      center = await F.descriptor(await F.imageFromDataUrl(frames.center), 'selfie');
      if (!center) return retry('We could not see your face clearly in the "Look straight" photo. Try again in better light, no cap or sunglasses.');
      const last = steps.filter(s => s !== 'center' && s !== 'blink').pop();
      other = last && frames[last] ? await F.descriptor(await F.imageFromDataUrl(frames[last]), 'selfie') : null;
      if (other && F.distance(center.descriptor, other.descriptor) > 0.6) return retry('A different face appeared during the check. The same person must do every step.');
    } catch (e) {
      console.warn(e);
      return retry('Face recognition is not available on this phone. Please use your password.');
    }
    const data = {action: mode === 'login' ? 'verify' : 'enroll', descriptor: JSON.stringify(center.descriptor), log: JSON.stringify(log)};
    if (nonce) data.nonce = nonce;
    if (mode === 'enroll' && box.dataset.kycSelfie) {
      try {
        const img = await F.imageFromDataUrl(box.dataset.kycSelfie);
        const k = await F.descriptor(img, 'selfie');
        if (!k) return retry('We could not read the face on your approved ID check. Please verify your identity again.');
        data.kyc_descriptor = JSON.stringify(k.descriptor);
      } catch (e) { return retry('We could not load your ID check photo. Please reload the page.'); }
    }
    const res = await post(data);
    if (!res.ok) return retry(res.error || 'Please try again.');
    show('ok', mode === 'login' ? '✓ Welcome back!' : '✓ Face Login is on', mode === 'login' ? ' Logging you in…' : '');
    location.href = res.redirect || 'dashboard.php';
  }

  async function start() {
    if (busy) return; busy = true;
    btn.disabled = true; matchBox.hidden = true;
    let steps = ['center', 'left', 'right', 'blink'], nonce = '';
    if (mode === 'login') {
      say('Getting your steps…');
      const ch = await post({action: 'challenge'});
      if (!ch.ok) { say('Face Login is not available.', 'error'); return retry(ch.error || 'Please use your password.'); }
      steps = ch.steps; nonce = ch.nonce;
    }
    drawSteps(steps);
    const frames = {};
    engine = F.liveness({
      video, overlay, steps,
      onSay: say,
      onCapture: (s, url) => { frames[s] = url; mark(s, 'done'); },
      onDone: log => finish(steps, frames, log, nonce),
    });
    try {
      const auto = await engine.start();
      btn.hidden = true;
      if (!auto) { engine.stop(); say('The automatic face check does not work on this phone.', 'error'); retry('Face Login needs the automatic face check. Please use your password on this phone.'); }
    } catch (e) { say(e.message, 'error'); btn.disabled = false; busy = false; }
  }
  btn.addEventListener('click', start);
  window.addEventListener('pagehide', () => engine && engine.stop());
})();
