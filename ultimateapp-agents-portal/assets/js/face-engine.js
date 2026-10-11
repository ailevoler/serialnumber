/* Ultimate App face engine: guided liveness check + face recognition, all on the phone.
 * - Liveness: MediaPipe Face Landmarker (self-hosted). Each step lights up its side of the oval: the side to turn to
 *   flashes red; it turns green when the head is turned the right way and the photo is taken automatically.
 *   Turning the wrong way shows an error and nothing is captured.
 * - Recognition: face-api (self-hosted, MIT) makes a 128-number face descriptor. Same person: distance usually < 0.5.
 * Used by KYC (selfie vs ID photo) and Face Login (live face vs the KYC face). Load with
 *   <script src="assets/js/face-engine.js" data-vendor="assets/vendor/"></script>
 */
window.UAFace = (() => {
  const script = document.currentScript;
  const VENDOR = new URL(script ? script.dataset.vendor || 'assets/vendor/' : 'assets/vendor/', location.href).href;
  const MP = VENDOR + 'mediapipe/';
  const FA = VENDOR + 'face-api/';

  const SAY = {
    center: 'Look straight at the camera',
    left: 'Turn your head to your LEFT',
    right: 'Turn your head to your RIGHT',
    up: 'Look UP',
    down: 'Look DOWN',
    blink: 'BLINK your eyes',
  };
  const WRONG = {
    left: 'That is your RIGHT. Turn to your LEFT (the red side).',
    right: 'That is your LEFT. Turn to your RIGHT (the red side).',
    up: 'That is down. Look UP (the red side).',
    down: 'That is up. Look DOWN (the red side).',
  };
  const RED = '#e5372b', GREEN = '#12b76a', GREY = 'rgba(255,255,255,.55)';

  /* ---------------------------------------------------------------- models (loaded once) */
  let landmarkerP = null, faceapiP = null;
  const loadLandmarker = () => landmarkerP ??= (async () => {
    const vision = await import(MP + 'vision_bundle.mjs');
    const files = await vision.FilesetResolver.forVisionTasks(MP + 'wasm');
    const opts = delegate => ({baseOptions: {modelAssetPath: MP + 'face_landmarker.task', delegate}, runningMode: 'VIDEO', numFaces: 2, outputFaceBlendshapes: true});
    try { return await vision.FaceLandmarker.createFromOptions(files, opts('GPU')); }
    catch (e) { return await vision.FaceLandmarker.createFromOptions(files, opts('CPU')); }
  })();
  const loadFaceApi = () => faceapiP ??= (async () => {
    const faceapi = await import(FA + 'face-api.esm.js');
    try { faceapi.tf.removeBackend('wasm'); } catch (e) { /* not bundled */ } // WebGL or CPU only: no extra .wasm download
    try { await faceapi.tf.setBackend('webgl'); } catch (e) { await faceapi.tf.setBackend('cpu'); }
    await faceapi.tf.ready();
    const M = FA + 'model';
    await Promise.all([faceapi.nets.tinyFaceDetector.loadFromUri(M), faceapi.nets.ssdMobilenetv1.loadFromUri(M), faceapi.nets.faceLandmark68Net.loadFromUri(M), faceapi.nets.faceRecognitionNet.loadFromUri(M)]);
    return faceapi;
  })();

  /** Face descriptor of the largest face in an image/canvas/video. kind 'id' uses the stronger detector (small faces on IDs). */
  async function descriptor(source, kind = 'selfie') {
    const faceapi = await loadFaceApi();
    const opts = kind === 'id' ? new faceapi.SsdMobilenetv1Options({minConfidence: 0.35}) : new faceapi.TinyFaceDetectorOptions({inputSize: 416, scoreThreshold: 0.4});
    let all = await faceapi.detectAllFaces(source, opts).withFaceLandmarks().withFaceDescriptors();
    if (!all.length && kind !== 'id') all = await faceapi.detectAllFaces(source, new faceapi.SsdMobilenetv1Options({minConfidence: 0.4})).withFaceLandmarks().withFaceDescriptors();
    if (!all.length) return null;
    all.sort((a, b) => b.detection.box.area - a.detection.box.area);
    return {descriptor: Array.from(all[0].descriptor, v => Math.round(v * 1e6) / 1e6), faces: all.length, score: all[0].detection.score};
  }
  const distance = (a, b) => Math.sqrt(a.reduce((s, v, i) => s + (v - b[i]) ** 2, 0));
  /** 0-100 "match score" for people: 100 at distance <= 0.3, 50 at 0.6, 0 at >= 0.9. */
  const score = d => Math.round(100 * Math.max(0, Math.min(1, (0.9 - d) / 0.6)));
  const imageFromDataUrl = url => new Promise((ok, no) => { const i = new Image(); i.onload = () => ok(i); i.onerror = no; i.src = url; });

  /* ---------------------------------------------------------------- liveness */
  /**
   * opts: {video, overlay (canvas over the video), steps: ['center', ...], onSay(text, tone), onCapture(step, dataUrl, metrics),
   *        onDone(log), onError(text)}
   * Returns {start(), stop(), takeManual()}.
   */
  function liveness(opts) {
    const {video, overlay} = opts;
    const steps = opts.steps;
    const ctx = overlay.getContext('2d');
    let stream = null, lm = null, running = false, i = 0, base = null, hold = 0, wrong = 0, lastCapture = 0, t0 = 0, stepStart = 0;
    let blinkPhase = 0, flash = null, centerSamples = [], raf = 0;
    const log = {engine: 'ua-face-1', model: 'mediapipe-face-landmarker-0.10.14', steps: {}, wrong: 0};
    const say = (t, tone = '') => opts.onSay && opts.onSay(t, tone);

    function measure(pts, shapes) {
      const nose = pts[1], cheekR = pts[234], cheekL = pts[454], top = pts[10], chin = pts[152];
      const yaw = (nose.x - cheekR.x) / ((cheekL.x - cheekR.x) || 1e-6);      // ~0.5 straight; up when the person turns to THEIR left
      const pitch = (nose.y - top.y) / ((chin.y - top.y) || 1e-6);            // down when looking up, up when looking down
      let bl = 0, br = 0;
      (shapes || []).forEach(c => { if (c.categoryName === 'eyeBlinkLeft') bl = c.score; if (c.categoryName === 'eyeBlinkRight') br = c.score; });
      const xs = pts.map(p => p.x), ys = pts.map(p => p.y);
      const box = {x0: Math.min(...xs), x1: Math.max(...xs), y0: Math.min(...ys), y1: Math.max(...ys)};
      return {yaw: +yaw.toFixed(3), pitch: +pitch.toFixed(3), blinkMin: +Math.min(bl, br).toFixed(3), blinkMax: +Math.max(bl, br).toFixed(3),
        width: box.x1 - box.x0, cx: (box.x0 + box.x1) / 2, cy: (box.y0 + box.y1) / 2};
    }

    /** 'ok' | 'wrong' | 'wait' for the current step. */
    function judge(s, m) {
      if (s === 'center') return Math.abs(m.yaw - 0.5) < 0.09 && m.pitch > 0.35 && m.pitch < 0.75 ? 'ok' : 'wait';
      const dy = m.yaw - base.yaw, dp = m.pitch - base.pitch;
      if (s === 'left') return dy > 0.13 && Math.abs(dp) < 0.12 ? 'ok' : (dy < -0.1 ? 'wrong' : 'wait');
      if (s === 'right') return dy < -0.13 && Math.abs(dp) < 0.12 ? 'ok' : (dy > 0.1 ? 'wrong' : 'wait');
      if (s === 'up') return dp < -0.055 && Math.abs(dy) < 0.12 ? 'ok' : (dp > 0.05 ? 'wrong' : 'wait');
      if (s === 'down') return dp > 0.055 && Math.abs(dy) < 0.12 ? 'ok' : (dp < -0.05 ? 'wrong' : 'wait');
      if (s === 'blink') {
        if (Math.abs(dy) > 0.12) return 'wait';                       // blink while looking at the camera
        if (blinkPhase === 0 && m.blinkMax < 0.3) blinkPhase = 1;      // eyes seen open
        if (blinkPhase === 1 && m.blinkMin > 0.55) return 'ok';         // then both eyes closed
        return 'wait';
      }
      return 'wait';
    }
    const NEED = {center: 12, left: 5, right: 5, up: 5, down: 5, blink: 1};

    /* ---- drawing: oval with four sides; the side to move to flashes red, turns green when right */
    function draw(now, state) {
      const w = overlay.clientWidth, h = overlay.clientHeight, dpr = window.devicePixelRatio || 1;
      if (overlay.width !== Math.round(w * dpr)) { overlay.width = Math.round(w * dpr); overlay.height = Math.round(h * dpr); }
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, w, h);
      const cx = w / 2, cy = h * 0.46, rx = Math.min(w * 0.34, h * 0.3), ry = Math.min(h * 0.38, rx * 1.32);
      // dim outside the oval
      ctx.save(); ctx.fillStyle = 'rgba(0,0,0,.38)'; ctx.beginPath(); ctx.rect(0, 0, w, h); ctx.ellipse(cx, cy, rx, ry, 0, 0, Math.PI * 2); ctx.fill('evenodd'); ctx.restore();
      const s = steps[i];
      const sides = {right: [-40, 40], down: [50, 130], left: [140, 220], up: [230, 310]};
      const blinkOn = Math.floor(now / 380) % 2 === 0;
      const arc = (a0, a1, color, width) => { ctx.beginPath(); ctx.strokeStyle = color; ctx.lineWidth = width; ctx.lineCap = 'round'; ctx.ellipse(cx, cy, rx, ry, 0, a0 * Math.PI / 180, a1 * Math.PI / 180); ctx.stroke(); };
      if (flash && now < flash.until) { arc(0, 360, flash.color, 8); return; }
      arc(0, 360, GREY, 3);
      if (!s) return;
      const progress = Math.min(1, hold / NEED[s]);
      const target = s === 'center' || s === 'blink' ? [0, 360] : sides[s];
      if (state === 'ok' || progress > 0) {
        arc(target[0], target[1], GREEN, 9);
      } else {
        arc(target[0], target[1], state === 'wrong' ? RED : (blinkOn ? RED : 'rgba(229,55,43,.25)'), 9);
      }
      // arrow outside the oval pointing where to move
      if (sides[s]) {
        const mid = (sides[s][0] + sides[s][1]) / 2 * Math.PI / 180;
        const ax = cx + Math.cos(mid) * (rx + 22), ay = cy + Math.sin(mid) * (ry + 22);
        ctx.save(); ctx.translate(ax, ay); ctx.rotate(mid); ctx.fillStyle = state === 'ok' ? GREEN : (blinkOn ? RED : 'rgba(229,55,43,.35)');
        ctx.beginPath(); ctx.moveTo(10, 0); ctx.lineTo(-6, -10); ctx.lineTo(-6, 10); ctx.closePath(); ctx.fill(); ctx.restore();
      }
      if (s === 'blink') {
        ctx.save(); ctx.fillStyle = state === 'ok' ? GREEN : (blinkOn ? RED : 'rgba(229,55,43,.35)'); ctx.font = '600 15px system-ui, sans-serif'; ctx.textAlign = 'center';
        ctx.fillText('◉  ◉', cx, cy - ry - 14); ctx.restore();
      }
    }

    function snapshot(max = 640) {
      const c = document.createElement('canvas');
      const vw = video.videoWidth, vh = video.videoHeight, k = Math.min(1, max / Math.max(vw, vh));
      c.width = Math.round(vw * k); c.height = Math.round(vh * k);
      c.getContext('2d').drawImage(video, 0, 0, c.width, c.height);
      return c.toDataURL('image/jpeg', 0.88);
    }

    function capture(m, manual) {
      const s = steps[i];
      const url = snapshot();
      log.steps[s] = {t: Math.round(performance.now() - t0), yaw: m ? m.yaw : null, pitch: m ? m.pitch : null, blink: m ? m.blinkMin : null, manual: !!manual};
      flash = {color: GREEN, until: performance.now() + 450};
      if (navigator.vibrate) navigator.vibrate(35);
      opts.onCapture && opts.onCapture(s, url, m);
      i++; hold = 0; wrong = 0; blinkPhase = 0; lastCapture = performance.now(); stepStart = lastCapture;
      if (i >= steps.length) { running = false; stopCamera(); draw(performance.now(), 'ok'); say('Done! Checking your photos…', 'ok'); opts.onDone && opts.onDone(log); return; }
      say(SAY[steps[i]], 'go');
    }

    function loop() {
      if (!running) return;
      const now = performance.now();
      let state = 'wait';
      if (video.readyState >= 2 && lm) {
        const res = lm.detectForVideo(video, now);
        const faces = res.faceLandmarks || [];
        const s = steps[i];
        if (!faces.length) { hold = 0; say('Put your face inside the oval', 'warn'); }
        else if (faces.length > 1) { hold = 0; say('Only one face, please', 'warn'); }
        else {
          const m = measure(faces[0], res.faceBlendshapes && res.faceBlendshapes[0] ? res.faceBlendshapes[0].categories : []);
          const inside = Math.abs(m.cx - 0.5) < 0.18 && Math.abs(m.cy - 0.48) < 0.2;
          if (m.width < 0.22) { hold = 0; say('Move a little closer', 'warn'); }
          else if (m.width > 0.8) { hold = 0; say('Move a little back', 'warn'); }
          else if (!inside && s === 'center') { hold = 0; say('Center your face in the oval', 'warn'); }
          else {
            if (s !== 'center' && !base) base = {yaw: 0.5, pitch: 0.55};
            state = judge(s, m);
            if (state === 'wrong') {
              wrong++; hold = 0;
              if (wrong === 3) { log.wrong++; flash = {color: RED, until: now + 700}; if (navigator.vibrate) navigator.vibrate([60, 40, 60]); }
              if (wrong >= 3) say(WRONG[s] || SAY[s], 'error');
            } else if (state === 'ok' && now - lastCapture > 350) {
              wrong = 0; hold++;
              if (s === 'center') centerSamples.push(m);
              say(s === 'center' ? 'Hold still…' : 'Good, hold it…', 'ok');
              if (hold >= NEED[s]) {
                if (s === 'center') {
                  const last = centerSamples.slice(-NEED.center);
                  base = {yaw: last.reduce((a, b) => a + b.yaw, 0) / last.length, pitch: last.reduce((a, b) => a + b.pitch, 0) / last.length};
                }
                capture(m, false);
              }
            } else {
              wrong = 0; if (s !== 'blink') hold = 0;
              say(SAY[s], 'go');
            }
          }
        }
        if (running && now - stepStart > 20000 && opts.onSlow) opts.onSlow();
      }
      draw(now, state);
      if (running) raf = requestAnimationFrame(loop);
    }

    function stopCamera() {
      running = false; cancelAnimationFrame(raf);
      if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
    }

    return {
      log,
      async start() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) throw new Error('This browser cannot open the camera. Please use Chrome or Safari on your phone.');
        say('Opening the camera…');
        try {
          stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'user', width: {ideal: 640}, height: {ideal: 480}}, audio: false});
        } catch (e) { throw new Error('Camera access was blocked. Allow the camera for this site in your browser settings, then try again.'); }
        video.srcObject = stream; await video.play();
        say('Loading the face check (first time takes a few seconds)…');
        try { lm = await loadLandmarker(); } catch (e) { lm = null; }
        loadFaceApi().catch(() => {}); // warm up recognition in the background
        i = 0; base = null; hold = 0; wrong = 0; blinkPhase = 0; centerSamples = []; log.steps = {}; log.wrong = 0;
        t0 = performance.now(); stepStart = t0; lastCapture = 0; running = true;
        say(lm ? SAY[steps[0]] : 'Automatic face check is not available on this phone.', lm ? 'go' : 'warn');
        raf = requestAnimationFrame(loop);
        return !!lm;
      },
      stop: stopCamera,
      /** Manual fallback (no face check on this phone): takes the current step's photo. */
      takeManual() { if (running && i < steps.length) { if (steps[i] === 'center') base = {yaw: 0.5, pitch: 0.55}; capture(null, true); } },
      get step() { return steps[i]; },
      _test: {judge: (s, m) => judge(s, m), setBase: b => { base = b; }, resetBlink: () => { blinkPhase = 0; }},
    };
  }

  return {liveness, descriptor, distance, score, imageFromDataUrl, loadFaceApi, loadLandmarker, SAY};
})();
