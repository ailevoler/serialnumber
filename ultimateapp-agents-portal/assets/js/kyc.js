/* KYC: ID photos + selfie liveness (look straight, left, right, up, down, blink).
 * Face detection runs on the phone with the self-hosted MediaPipe face landmarker; nothing is sent until "Send".
 * Head turns are measured from the nose tip between the cheeks (yaw) and between forehead and chin (pitch),
 * relative to the person's own "look straight" pose. Blink uses the eyeBlink blendshapes. */
(() => {
  const form = document.querySelector('[data-kyc]');
  if (!form) return;
  const $ = (s, el = form) => el.querySelector(s);
  const $$ = (s, el = form) => Array.from(el.querySelectorAll(s));
  const STEPS = ['center', 'left', 'right', 'up', 'down', 'blink'];
  const SAY = {
    center: 'Look straight at the camera', left: 'Slowly turn your head to your LEFT', right: 'Now turn your head to your RIGHT',
    up: 'Look UP', down: 'Look DOWN', blink: 'Now BLINK your eyes',
  };

  /* ---------- steps */
  const show = n => {
    $$('[data-pane]').forEach(p => { p.hidden = p.dataset.pane !== String(n); });
    $$('[data-step-dot]').forEach(d => d.classList.toggle('on', Number(d.dataset.stepDot) <= n));
    if (n === 3) buildReview();
    if (n !== 2) stopCamera();
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
        const url = toDataUrl(img, 1600, 0.85);
        $('[data-value]', box).value = url;
        const pv = $('[data-preview]', box); pv.src = url; pv.hidden = false; $('[data-empty]', box).hidden = true;
        URL.revokeObjectURL(img.src);
      };
      img.onerror = () => alert('This file is not a photo. Please choose a JPG or PNG image.');
      img.src = URL.createObjectURL(file);
      e.target.value = '';
    });
  });

  /* ---------- liveness */
  const video = $('[data-video]');
  const say = t => { $('[data-say]').textContent = t; };
  const btnStart = $('[data-start]'), btnManual = $('[data-manual]'), btnRetry = $('[data-retry]'), btnToSend = $('[data-to-send]');
  let stream = null, landmarker = null, running = false, step = 0, base = null, hold = 0, stepStart = 0, t0 = 0, closed = false, samples = [];
  let log = {model: 'mediapipe-face-landmarker-0.10.14', steps: {}};
  const vendor = new URL(form.dataset.vendor, location.href).href;

  const stopCamera = () => {
    running = false;
    if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
  };
  const reset = () => {
    step = 0; base = null; hold = 0; closed = false; samples = []; log = {model: log.model, steps: {}};
    STEPS.forEach(s => { $('[data-frame="' + s + '"]').value = ''; const li = $('[data-check="' + s + '"]'); li.classList.remove('done', 'now'); $('[data-thumb]', li).hidden = true; });
    $('[data-mode]').value = landmarker ? 'auto' : 'manual';
    btnToSend.disabled = true; btnRetry.hidden = true;
  };
  const markNow = () => STEPS.forEach((s, i) => $('[data-check="' + s + '"]').classList.toggle('now', i === step));

  const capture = (m, manual) => {
    const s = STEPS[step];
    const url = toDataUrl(video, 640, 0.85);
    $('[data-frame="' + s + '"]').value = url;
    log.steps[s] = {t: Math.round(performance.now() - t0), yaw: m ? m.yaw : null, pitch: m ? m.pitch : null, blink: m ? m.blink : null};
    if (manual) $('[data-mode]').value = 'manual';
    const li = $('[data-check="' + s + '"]'); li.classList.add('done'); const th = $('[data-thumb]', li); th.src = url; th.hidden = false;
    if (navigator.vibrate) navigator.vibrate(40);
    step++; hold = 0; closed = false; stepStart = performance.now(); btnManual.hidden = !!landmarker;
    if (step >= STEPS.length) {
      $('[data-log]').value = JSON.stringify(log);
      say('Done! All photos taken.'); stopCamera(); btnToSend.disabled = false; btnRetry.hidden = false; markNow();
      return;
    }
    say(SAY[STEPS[step]]); markNow();
  };

  /* Measurements from the 478 face landmarks (image coordinates, not mirrored). */
  const measure = (lm, shapes) => {
    const nose = lm[1], cheekR = lm[234], cheekL = lm[454], top = lm[10], chin = lm[152];
    const yaw = (nose.x - cheekR.x) / ((cheekL.x - cheekR.x) || 1e-6);   // ~0.5 straight; grows when turning to the person's left
    const pitch = (nose.y - top.y) / ((chin.y - top.y) || 1e-6);         // smaller when looking up, larger when looking down
    let bl = 0, br = 0;
    (shapes || []).forEach(c => { if (c.categoryName === 'eyeBlinkLeft') bl = c.score; if (c.categoryName === 'eyeBlinkRight') br = c.score; });
    const width = Math.abs(cheekL.x - cheekR.x);
    return {yaw: +yaw.toFixed(3), pitch: +pitch.toFixed(3), blink: +Math.min(bl, br).toFixed(3), width};
  };

  const passes = m => {
    const s = STEPS[step];
    if (s === 'center') return m.yaw > 0.4 && m.yaw < 0.6 && m.width > 0.22;
    if (s === 'left') return m.yaw - base.yaw > 0.14;
    if (s === 'right') return base.yaw - m.yaw > 0.14;
    if (s === 'up') return base.pitch - m.pitch > 0.06;
    if (s === 'down') return m.pitch - base.pitch > 0.06;
    if (s === 'blink') { if (m.blink > 0.55) closed = true; return closed && m.blink > 0.55; }
    return false;
  };

  const loop = () => {
    if (!running) return;
    if (video.readyState >= 2) {
      const now = performance.now();
      const res = landmarker.detectForVideo(video, now);
      const faces = res.faceLandmarks || [];
      if (faces.length === 0) { say('Put your face inside the oval'); hold = 0; }
      else if (faces.length > 1) { say('Only one face, please'); hold = 0; }
      else {
        const m = measure(faces[0], res.faceBlendshapes && res.faceBlendshapes[0] ? res.faceBlendshapes[0].categories : []);
        if (STEPS[step] === 'center' && m.width <= 0.22) { say('Move a little closer'); hold = 0; }
        else {
          say(SAY[STEPS[step]]);
          if (STEPS[step] === 'center' && passes(m)) samples.push(m);
          if (passes(m)) hold++; else if (STEPS[step] !== 'blink') hold = 0;
          const need = STEPS[step] === 'center' ? 12 : (STEPS[step] === 'blink' ? 1 : 4);
          if (hold >= need) {
            if (STEPS[step] === 'center') {
              const last = samples.slice(-need);
              base = {yaw: last.reduce((a, b) => a + b.yaw, 0) / last.length, pitch: last.reduce((a, b) => a + b.pitch, 0) / last.length};
            }
            capture(m, false);
          }
        }
      }
      if (step < STEPS.length && now - stepStart > 20000) btnManual.hidden = false;
    }
    if (running) requestAnimationFrame(loop);
  };

  const loadModel = async () => {
    const vision = await import(vendor + 'vision_bundle.mjs');
    const files = await vision.FilesetResolver.forVisionTasks(vendor + 'wasm');
    const opts = delegate => ({baseOptions: {modelAssetPath: vendor + 'face_landmarker.task', delegate}, runningMode: 'VIDEO', numFaces: 2, outputFaceBlendshapes: true});
    try { return await vision.FaceLandmarker.createFromOptions(files, opts('GPU')); }
    catch (e) { return await vision.FaceLandmarker.createFromOptions(files, opts('CPU')); }
  };

  btnStart.addEventListener('click', async () => {
    btnStart.disabled = true;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { say('This browser cannot open the camera. Please use Chrome or Safari on your phone.'); btnStart.disabled = false; return; }
    try {
      say('Opening the camera…');
      stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'user', width: {ideal: 640}, height: {ideal: 480}}, audio: false});
      video.srcObject = stream; await video.play();
    } catch (e) {
      say('Camera access was blocked. Allow the camera for this site in your browser settings, then tap Start.'); btnStart.disabled = false; return;
    }
    if (!landmarker) {
      say('Loading the face check (first time takes a few seconds)…');
      try { landmarker = await loadModel(); }
      catch (e) { landmarker = null; console.warn('Face check unavailable', e); }
    }
    reset(); t0 = performance.now(); stepStart = t0; running = true; markNow();
    btnStart.hidden = true;
    if (landmarker) { say(SAY.center); requestAnimationFrame(loop); }
    else { $('[data-mode]').value = 'manual'; btnManual.hidden = false; say('Automatic face check is not available on this phone. ' + SAY.center + ', then tap "Take this photo".'); }
  });
  btnManual.addEventListener('click', () => {
    if (!stream || step >= STEPS.length) return;
    if (STEPS[step] === 'center' && !base) base = {yaw: 0.5, pitch: 0.5};
    capture(null, true);
    if (step < STEPS.length && !landmarker) say(SAY[STEPS[step]] + ', then tap "Take this photo".');
  });
  btnRetry.addEventListener('click', () => { reset(); btnStart.hidden = false; btnStart.disabled = false; say('Tap Start to try again.'); });

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
  window.addEventListener('pagehide', stopCamera);
})();
