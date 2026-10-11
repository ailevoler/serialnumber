/* KYC: ID photos + guided selfie liveness (look straight, left, right, up, down, blink) + face match with the ID.
 * Everything runs on the phone (assets/js/face-engine.js): the side of the oval to turn to flashes red, turns green
 * when done right and the photo is taken automatically; a wrong turn shows an error and is not captured.
 * After the six photos, the "look straight" face is compared with the face on the ID photo. Nothing is sent until "Send". */
(() => {
  const form = document.querySelector('[data-kyc]');
  if (!form || !window.UAFace) return;
  const F = window.UAFace;
  const $ = (s, el = form) => el.querySelector(s);
  const $$ = (s, el = form) => Array.from(el.querySelectorAll(s));
  const STEPS = ['center', 'left', 'right', 'up', 'down', 'blink'];
  const MAX = Number(form.dataset.maxDistance) || 0.6;

  /* ---------- steps */
  let engine = null;
  const show = n => {
    $$('[data-pane]').forEach(p => { p.hidden = p.dataset.pane !== String(n); });
    $$('[data-step-dot]').forEach(d => d.classList.toggle('on', Number(d.dataset.stepDot) <= n));
    if (n === 3) buildReview();
    if (n !== 2 && engine) engine.stop();
    form.scrollIntoView({behavior: 'smooth', block: 'start'});
  };
  $$('[data-next]').forEach(b => b.addEventListener('click', () => {
    const n = Number(b.dataset.next);
    if (n === 2) {
      const missing = !form.id_type.value ? 'Choose your ID type.' : (form.full_name.value.trim().length < 2 ? 'Enter your full name as printed on the ID.'
        : (!form.id_front.value ? 'Add a photo of the front of your ID.' : (!form.id_back.value ? 'Add a photo of the back of your ID.' : '')));
      if (missing) { alert(missing); return; }
    }
    show(n);
  }));

  /* ---------- ID photos: resized on the phone to max 1600 px before upload */
  const toDataUrl = (src, max, quality) => {
    const c = document.createElement('canvas');
    const w = src.videoWidth || src.naturalWidth || src.width, h = src.videoHeight || src.naturalHeight || src.height;
    const s = Math.min(1, max / Math.max(w, h));
    c.width = Math.round(w * s); c.height = Math.round(h * s);
    c.getContext('2d').drawImage(src, 0, 0, c.width, c.height);
    return c.toDataURL('image/jpeg', quality);
  };
  $$('[data-id-side]').forEach(box => {
    $('[data-file]', box).addEventListener('change', e => {
      const file = e.target.files && e.target.files[0];
      if (!file) return;
      const img = new Image();
      img.onload = () => {
        if (Math.min(img.naturalWidth, img.naturalHeight) < 400) { alert('This photo is too small. Please take a clearer photo.'); return; }
        const url = toDataUrl(img, 1600, 0.9);
        $('[data-value]', box).value = url;
        const pv = $('[data-preview]', box); pv.src = url; pv.hidden = false; $('[data-empty]', box).hidden = true;
        URL.revokeObjectURL(img.src);
        if (box.dataset.idSide === 'id_front') { idDesc = null; resetMatch(); F.loadFaceApi().catch(() => {}); }
      };
      img.onerror = () => alert('This file is not a photo. Please choose a JPG or PNG image.');
      img.src = URL.createObjectURL(file);
      e.target.value = '';
    });
  });

  /* ---------- liveness */
  const video = $('[data-video]'), overlay = $('[data-overlay]'), sayBox = $('[data-say]'), matchBox = $('[data-match]');
  const btnStart = $('[data-start]'), btnManual = $('[data-manual]'), btnRetry = $('[data-retry]'), btnToSend = $('[data-to-send]');
  const say = (t, tone = '') => { sayBox.textContent = t; sayBox.className = 'kyc-say' + (tone ? ' tone-' + tone : ''); };
  let idDesc = null;

  const resetMatch = () => { matchBox.hidden = true; matchBox.className = 'kyc-match'; $('[data-face-desc]').value = ''; $('[data-id-face-desc]').value = ''; btnToSend.disabled = true; };
  const showMatch = (cls, html) => { matchBox.hidden = false; matchBox.className = 'kyc-match ' + cls; matchBox.innerHTML = html; };
  const resetFrames = () => {
    STEPS.forEach(s => { $('[data-frame="' + s + '"]').value = ''; const li = $('[data-check="' + s + '"]'); li.classList.remove('done', 'now'); $('[data-thumb]', li).hidden = true; });
    resetMatch();
  };
  const markNow = s => STEPS.forEach(x => $('[data-check="' + x + '"]').classList.toggle('now', x === s));

  /** After the six photos: same face in every frontal photo, and the same face as on the ID. */
  async function checkFaces(log) {
    showMatch('wait', '<b>Checking your face…</b>Comparing your selfie with the photo on your ID. This takes a few seconds.');
    try {
      const frame = s => F.imageFromDataUrl($('[data-frame="' + s + '"]').value);
      const center = await F.descriptor(await frame('center'), 'selfie');
      if (!center) return fail('We could not see your face clearly in the "Look straight" photo. Try again in better light.');
      for (const s of ['up', 'down', 'blink']) {
        const d = await F.descriptor(await frame(s), 'selfie');
        if (d && F.distance(center.descriptor, d.descriptor) > 0.6) return fail('A different face appeared in the "' + $('[data-check="' + s + '"] span').textContent + '" photo. The same person must do every step.');
      }
      if (!idDesc) {
        const idImg = await F.imageFromDataUrl(form.id_front.value);
        idDesc = await F.descriptor(idImg, 'id');
      }
      if (!idDesc) return fail('We could not find the face on the front of your ID. Retake the ID photo: flat, in good light, no glare on the photo.', true);
      const dist = F.distance(center.descriptor, idDesc.descriptor);
      const pct = F.score(dist);
      $('[data-face-desc]').value = JSON.stringify(center.descriptor);
      $('[data-id-face-desc]').value = JSON.stringify(idDesc.descriptor);
      log.face = {distance: +dist.toFixed(4), score: pct};
      $('[data-log]').value = JSON.stringify(log);
      if (dist > MAX) {
        $('[data-face-desc]').value = ''; $('[data-id-face-desc]').value = '';
        return fail('Your face does not match the photo on your ID (match ' + pct + '%). Make sure it is your own ID, retake the ID photo without glare, or try the selfie again in better light.', true);
      }
      showMatch('ok', '<b>✓ Your face matches your ID (match ' + pct + '%)</b>Tap Next to review and send.');
      btnToSend.disabled = false; btnRetry.hidden = false;
    } catch (e) {
      console.warn('Face match unavailable', e);
      showMatch('wait', '<b>Face match not available on this phone</b>Our team will compare your selfie with your ID by hand. Tap Next.');
      $('[data-log]').value = JSON.stringify(log);
      btnToSend.disabled = false; btnRetry.hidden = false;
    }
  }
  function fail(text, offerId) {
    showMatch('bad', '<b>Not verified</b>' + text.replace(/[<>&]/g, '') + '<br><button type="button" class="kyc-btn" data-again>Try the selfie again</button>' + (offerId ? ' <button type="button" class="kyc-btn" data-fix-id>Change ID photo</button>' : ''));
    $('[data-again]', matchBox).addEventListener('click', () => { resetFrames(); start(); });
    const fix = $('[data-fix-id]', matchBox); if (fix) fix.addEventListener('click', () => { resetFrames(); show(1); });
    btnRetry.hidden = true; btnToSend.disabled = true;
  }

  async function start() {
    btnStart.disabled = true; btnStart.hidden = false; btnRetry.hidden = true; btnManual.hidden = true;
    resetFrames(); $('[data-mode]').value = 'auto';
    engine = F.liveness({
      video, overlay, steps: STEPS,
      onSay: say,
      onSlow: () => { btnManual.hidden = false; },
      onCapture: (s, url) => {
        $('[data-frame="' + s + '"]').value = url;
        const li = $('[data-check="' + s + '"]'); li.classList.add('done'); const th = $('[data-thumb]', li); th.src = url; th.hidden = false;
        markNow(STEPS[STEPS.indexOf(s) + 1]);
      },
      onDone: log => { markNow(null); $('[data-log]').value = JSON.stringify(log); checkFaces(log); },
    });
    try {
      const auto = await engine.start();
      btnStart.hidden = true; markNow('center');
      if (!auto) { $('[data-mode]').value = 'manual'; btnManual.hidden = false; say('Automatic face check is not available on this phone. Look straight at the camera, then tap "Take this photo".', 'warn'); }
    } catch (e) { say(e.message, 'error'); btnStart.disabled = false; }
  }
  btnStart.addEventListener('click', start);
  btnManual.addEventListener('click', () => { if (engine) { $('[data-mode]').value = 'manual'; engine.takeManual(); } });
  btnRetry.addEventListener('click', () => { resetFrames(); start(); });

  /* ---------- review + submit */
  const buildReview = () => {
    const box = $('[data-review]'); box.innerHTML = '';
    const add = (src, label) => { if (!src) return; const f = document.createElement('figure'); const i = document.createElement('img'); i.src = src; i.alt = label; const c = document.createElement('figcaption'); c.textContent = label; f.append(i, c); box.append(f); };
    add(form.id_front.value, 'ID front'); add(form.id_back.value, 'ID back');
    STEPS.forEach(s => add($('[data-frame="' + s + '"]').value, $('[data-check="' + s + '"] span').textContent));
  };
  const agree = $('[data-agree]'), submit = $('[data-submit]');
  agree.addEventListener('change', () => { submit.disabled = !agree.checked; });
  form.addEventListener('submit', e => {
    const ready = form.id_front.value && form.id_back.value && STEPS.every(s => $('[data-frame="' + s + '"]').value) && agree.checked;
    if (!ready) { e.preventDefault(); alert('Please finish all steps first.'); return; }
    submit.disabled = true; submit.textContent = 'Sending…';
  });
  window.addEventListener('pagehide', () => engine && engine.stop());
})();
