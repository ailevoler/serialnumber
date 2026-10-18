<?php
require_once __DIR__ . '/includes/kyc.php';
require_login();
$user   = get_user(current_user_id());
$latest = kyc_latest(current_user_id());
$state  = $user['kyc_status'] === 'verified' ? 'verified'
        : (($latest && $latest['status'] === 'pending') ? 'pending'
        : (($latest && $latest['status'] === 'rejected') ? 'rejected' : 'new'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<title>Verify Your ID — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
.kyc-body { padding: 16px 16px calc(120px + env(safe-area-inset-bottom)); }
.stepper { display: flex; gap: 6px; margin: 2px 0 18px; }
.stepper span { flex: 1; height: 5px; border-radius: 999px; background: var(--border); transition: background .2s; }
.stepper span.done { background: var(--rbnw-green); }
.stepper span.cur { background: var(--rbnw-blue); }
.kyc-h { margin: 0 0 4px; font-size: 19px; font-weight: 800; }
.kyc-p { margin: 0 0 18px; font-size: 13.5px; color: var(--text-muted); line-height: 1.45; }
.select-wrap select { border: none; outline: none; background: transparent; flex: 1; height: 48px; font: inherit; font-size: 16px; color: var(--text-dark); appearance: none; }
.need { display: flex; flex-direction: column; gap: 10px; margin-bottom: 18px; }
.need div { display: flex; gap: 12px; align-items: center; background: #fff; border-radius: var(--radius-md); padding: 12px 14px; box-shadow: var(--shadow-soft); font-size: 13.5px; }
.need i { width: 36px; height: 36px; border-radius: 11px; background: var(--rbnw-blue-50); color: var(--rbnw-blue); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.need small { display: block; color: var(--text-muted); font-size: 12px; }

/* ID capture */
.id-frame {
  position: relative; aspect-ratio: 1.586; border-radius: 16px; overflow: hidden;
  background: repeating-linear-gradient(135deg, #eef3fa 0 12px, #f6f9fd 12px 24px);
  border: 2px dashed var(--border-strong); display: flex; align-items: center; justify-content: center;
  margin-bottom: 14px;
}
.id-frame.has { border-style: solid; border-color: var(--rbnw-green); background: #000; }
.id-frame img { width: 100%; height: 100%; object-fit: contain; }
.id-frame .ph { text-align: center; color: var(--text-muted); font-size: 13px; }
.id-frame .ph i { display: block; font-size: 34px; color: var(--rbnw-blue); margin-bottom: 8px; }
.id-frame .corner { position: absolute; width: 26px; height: 26px; border: 3px solid var(--rbnw-blue); }
.id-frame .c1 { top: 10px; left: 10px; border-right: 0; border-bottom: 0; border-radius: 8px 0 0 0; }
.id-frame .c2 { top: 10px; right: 10px; border-left: 0; border-bottom: 0; border-radius: 0 8px 0 0; }
.id-frame .c3 { bottom: 10px; left: 10px; border-right: 0; border-top: 0; border-radius: 0 0 0 8px; }
.id-frame .c4 { bottom: 10px; right: 10px; border-left: 0; border-top: 0; border-radius: 0 0 8px 0; }
.id-frame.has .corner { display: none; }
.tips { font-size: 12.5px; color: var(--text-muted); margin: 0 0 16px; padding-left: 18px; line-height: 1.6; }
.cap-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

/* Liveness */
.live-stage { position: relative; width: 100%; max-width: 330px; aspect-ratio: 3 / 4; margin: 0 auto 14px; border-radius: 24px; overflow: hidden; background: #0b1424; }
.live-stage video { width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1); }
.live-stage .shade { position: absolute; inset: 0; pointer-events: none; }
.live-stage svg.ring { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; }
.ring .arc { fill: none; stroke-width: 7; stroke-linecap: round; stroke: #d93a34; transition: stroke .25s; }
.ring .arc.ok { stroke: #2fbf5b; }
.ring .arc.cur { animation: arcPulse 1s ease-in-out infinite; }
@keyframes arcPulse { 50% { stroke-width: 11; opacity: .75; } }
.live-msg { position: absolute; left: 12px; right: 12px; bottom: 12px; text-align: center; color: #fff; font-weight: 700; font-size: 15px; text-shadow: 0 1px 4px rgba(0,0,0,.6); }
.live-msg small { display: block; font-weight: 500; font-size: 12px; opacity: .9; }
.live-arrow { position: absolute; top: 50%; transform: translateY(-50%); font-size: 30px; color: #fff; text-shadow: 0 2px 6px rgba(0,0,0,.5); animation: nudge 1s ease-in-out infinite; display: none; }
.live-arrow.l { left: 10px; } .live-arrow.r { right: 10px; }
.live-arrow.u { left: 50%; top: 14px; transform: translateX(-50%); }
.live-arrow.d { left: 50%; top: auto; bottom: 54px; transform: translateX(-50%); }
@keyframes nudge { 50% { opacity: .35; } }
.checks { display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px; margin-bottom: 14px; }
.check { text-align: center; padding: 8px 2px; border-radius: 12px; background: #fff; box-shadow: var(--shadow-soft); font-size: 11px; font-weight: 700; color: var(--text-body); border: 2px solid #f3c4c2; transition: border-color .2s, background .2s; }
.check i { display: block; font-size: 16px; margin-bottom: 4px; color: #d93a34; transition: color .2s; }
.check.ok { border-color: #2fbf5b; background: #effaf2; }
.check.ok i { color: #2fbf5b; }
.check.cur { box-shadow: 0 0 0 3px rgba(26, 99, 184, .18); }
.selfie-preview { width: 160px; height: 200px; border-radius: 20px; object-fit: cover; display: block; margin: 0 auto 14px; border: 3px solid var(--rbnw-green); }

.review-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
.review-grid figure { margin: 0; background: #fff; border-radius: 12px; padding: 6px; box-shadow: var(--shadow-soft); }
.review-grid img { width: 100%; aspect-ratio: 1.586; object-fit: cover; border-radius: 8px; display: block; }
.review-grid figure.selfie img { aspect-ratio: 3/4; }
.review-grid figcaption { font-size: 11.5px; color: var(--text-muted); text-align: center; padding-top: 4px; }
.status-card { text-align: center; padding: 40px 16px 10px; }
.status-card .ico { width: 84px; height: 84px; border-radius: 50%; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center; font-size: 36px; }
.status-card h2 { margin: 0 0 6px; font-size: 20px; }
.status-card p { margin: 0 0 20px; color: var(--text-muted); font-size: 13.5px; line-height: 1.5; }
.sticky-footer { position: fixed; bottom: 0; left: 50%; transform: translateX(-50%); width: 100%; max-width: var(--max-w); padding: 12px 16px calc(12px + env(safe-area-inset-bottom)); background: linear-gradient(180deg, rgba(243,246,250,0) 0%, var(--bg) 35%); z-index: 20; }
</style>
</head>
<body>
<div class="app-shell">
  <div class="flow-header">
    <button class="back-btn" id="backBtn" style="background:none;border:none;" aria-label="Back"><i class="fa-solid fa-arrow-left"></i></button>
    <h2>Verify Your ID</h2>
  </div>

<?php if ($state === 'verified' || $state === 'pending'): ?>
  <div class="kyc-body">
    <div class="status-card">
      <?php if ($state === 'verified'): ?>
        <div class="ico" style="background:var(--success-50);color:var(--success)"><i class="fa-solid fa-circle-check"></i></div>
        <h2>You're verified</h2>
        <p>Your identity has been confirmed. Wallet Level <?= (int)$user['wallet_level'] ?> limits, Loans and all services are unlocked.</p>
      <?php else: ?>
        <div class="ico" style="background:#fdf3d6;color:#b7791f"><i class="fa-solid fa-hourglass-half"></i></div>
        <h2>Under review</h2>
        <p>We received your <?= htmlspecialchars($latest['id_type']) ?> and selfie on <?= date('M j, Y g:i A', strtotime($latest['submitted_at'])) ?>.<br>Review usually takes 1 banking day. We'll notify you.</p>
      <?php endif; ?>
      <button class="btn btn-primary" onclick="location.href='dashboard.php'">Back to Home</button>
    </div>
  </div>
  <script>document.getElementById('backBtn').onclick = () => location.href = 'dashboard.php';</script>
<?php else: ?>

  <div class="kyc-body">
    <div class="stepper" id="stepper"><span></span><span></span><span></span><span></span><span></span></div>

    <!-- 1. Details -->
    <section class="kstep" id="s1">
      <?php if ($state === 'rejected'): ?>
        <?= '<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> Your last submission was not approved' . ($latest['review_note'] ? ': ' . htmlspecialchars($latest['review_note']) : '') . '. Please try again.</div>' ?>
      <?php endif; ?>
      <h3 class="kyc-h">Let's verify your identity</h3>
      <p class="kyc-p">It takes about 3 minutes. Have one valid government ID ready and find a well-lit spot.</p>
      <div class="need">
        <div><i class="fa-solid fa-id-card"></i><span>Valid ID — front and back<small>Clear photo, all corners visible, no glare</small></span></div>
        <div><i class="fa-solid fa-face-smile"></i><span>Selfie liveness check<small>Turn left, right, look up, down, and blink</small></span></div>
      </div>
      <div class="field">
        <label for="idType">ID type</label>
        <div class="input-wrap select-wrap"><i class="icon fa-solid fa-id-card"></i>
          <select id="idType">
            <option value="">Choose your ID</option>
            <?php foreach (KYC_ID_TYPES as $name => $hasBack): ?>
              <option value="<?= htmlspecialchars($name) ?>" data-back="<?= $hasBack ? 1 : 0 ?>"><?= htmlspecialchars($name) ?></option>
            <?php endforeach; ?>
          </select>
          <i class="fa-solid fa-chevron-down" style="color:var(--text-faint);font-size:12px"></i>
        </div>
      </div>
      <div class="field">
        <label for="idNumber">ID number</label>
        <div class="input-wrap"><i class="icon fa-solid fa-hashtag"></i><input type="text" id="idNumber" autocapitalize="characters" maxlength="40" placeholder="As shown on your ID"></div>
      </div>
      <div class="field">
        <label for="birthdate">Birthdate</label>
        <div class="input-wrap"><i class="icon fa-regular fa-calendar"></i><input type="date" id="birthdate" value="<?= htmlspecialchars((string)$user['birthdate']) ?>"></div>
      </div>
      <div class="field">
        <label for="address">Home address</label>
        <div class="input-wrap"><i class="icon fa-solid fa-location-dot"></i><input type="text" id="address" maxlength="255" placeholder="House no., street, barangay, town, province" value="<?= htmlspecialchars((string)$user['home_address']) ?>"></div>
      </div>
      <div id="s1Error"></div>
    </section>

    <!-- 2/3. ID front / back -->
    <?php foreach (['front' => 'Front of your ID', 'back' => 'Back of your ID'] as $side => $title): ?>
    <section class="kstep hidden" id="s-<?= $side ?>">
      <h3 class="kyc-h"><?= $title ?></h3>
      <p class="kyc-p">Place your <span class="idName">ID</span> on a flat, dark surface and fit it inside the frame.</p>
      <div class="id-frame" id="frame-<?= $side ?>">
        <span class="corner c1"></span><span class="corner c2"></span><span class="corner c3"></span><span class="corner c4"></span>
        <div class="ph"><i class="fa-solid fa-camera"></i>No photo yet</div>
      </div>
      <ul class="tips">
        <li>All 4 corners visible, text readable</li>
        <li>No glare, shadows or fingers covering details</li>
      </ul>
      <div class="cap-actions">
        <label class="btn btn-outline"><i class="fa-solid fa-image"></i> Upload
          <input type="file" accept="image/*" class="hidden" data-side="<?= $side ?>"></label>
        <label class="btn btn-primary"><i class="fa-solid fa-camera"></i> <span class="take">Take photo</span>
          <input type="file" accept="image/*" capture="environment" class="hidden" data-side="<?= $side ?>"></label>
      </div>
    </section>
    <?php endforeach; ?>

    <!-- 4. Selfie liveness -->
    <section class="kstep hidden" id="s-selfie">
      <h3 class="kyc-h">Selfie liveness check</h3>
      <p class="kyc-p">Keep your face inside the oval and follow the prompts. Each side turns <b style="color:#2fbf5b">green</b> once done.</p>
      <div class="live-stage" id="stage">
        <video id="cam" playsinline muted></video>
        <svg class="shade" viewBox="0 0 300 400" preserveAspectRatio="none" aria-hidden="true">
          <defs><mask id="ovalMask"><rect width="300" height="400" fill="#fff"/><ellipse cx="150" cy="185" rx="104" ry="138" fill="#000"/></mask></defs>
          <rect width="300" height="400" fill="rgba(8,20,40,.55)" mask="url(#ovalMask)"/>
        </svg>
        <!-- four arcs around the oval: left / right (screen = your left/right), top, bottom -->
        <svg class="ring" viewBox="0 0 300 400" preserveAspectRatio="none" aria-hidden="true">
          <path class="arc" data-c="left"  d="M 58.3 101.3 A 112 146 0 0 0 58.3 268.7"/>
          <path class="arc" data-c="right" d="M 241.7 101.3 A 112 146 0 0 1 241.7 268.7"/>
          <path class="arc" data-c="up"    d="M 85.8 65.4 A 112 146 0 0 1 214.2 65.4"/>
          <path class="arc" data-c="down"  d="M 85.8 304.6 A 112 146 0 0 0 214.2 304.6"/>
        </svg>
        <i class="fa-solid fa-chevron-left live-arrow l" data-a="left"></i>
        <i class="fa-solid fa-chevron-right live-arrow r" data-a="right"></i>
        <i class="fa-solid fa-chevron-up live-arrow u" data-a="up"></i>
        <i class="fa-solid fa-chevron-down live-arrow d" data-a="down"></i>
        <div class="live-msg" id="liveMsg">Starting camera…</div>
      </div>
      <div class="checks" id="checks">
        <div class="check" data-c="left"><i class="fa-solid fa-arrow-left"></i>Left</div>
        <div class="check" data-c="right"><i class="fa-solid fa-arrow-right"></i>Right</div>
        <div class="check" data-c="up"><i class="fa-solid fa-arrow-up"></i>Look up</div>
        <div class="check" data-c="down"><i class="fa-solid fa-arrow-down"></i>Look down</div>
        <div class="check" data-c="blink"><i class="fa-solid fa-eye"></i>Blink</div>
      </div>
      <img class="selfie-preview hidden" id="selfiePreview" alt="Your selfie">
      <div id="liveError"></div>
    </section>

    <!-- 5. Review -->
    <section class="kstep hidden" id="s-review">
      <h3 class="kyc-h">Review and submit</h3>
      <p class="kyc-p">Make sure the photos are clear and the details match your ID.</p>
      <div class="review-grid" id="reviewGrid"></div>
      <div class="card" id="reviewInfo"></div>
      <label class="checkbox-line" style="margin:14px 2px 0">
        <input type="checkbox" id="consent">
        <span>I confirm this is my own ID and I consent to RBNW processing my personal data for identity verification under the Data Privacy Act of 2012.</span>
      </label>
      <div id="submitError"></div>
    </section>

    <!-- Done -->
    <section class="kstep hidden" id="s-done">
      <div class="status-card">
        <div class="ico" style="background:#fdf3d6;color:#b7791f"><i class="fa-solid fa-hourglass-half"></i></div>
        <h2>Submitted for review</h2>
        <p>Thanks! We're checking your ID and selfie. This usually takes 1 banking day — we'll notify you once you're verified.</p>
        <button class="btn btn-primary" onclick="location.href='dashboard.php'">Back to Home</button>
      </div>
    </section>
  </div>

  <div class="sticky-footer" id="footer"><button class="btn btn-primary" id="nextBtn">Continue</button></div>

<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script src="<?= asset_url('assets/vendor/face-api/face-api.js') ?>" defer></script>
<script>
const MODEL_URL = <?= json_encode(app_url('assets/vendor/face-api/model')) ?>;
const K = { step: 's1', hasBack: true, files: {}, liveness: null };
const esc = RBNW.escapeHtml;

function steps() { return ['s1', 's-front', ...(K.hasBack ? ['s-back'] : []), 's-selfie', 's-review']; }
function go(id) {
  if (K.step === 's-selfie' && id !== 's-selfie') Live.stop();
  K.step = id;
  document.querySelectorAll('.kstep').forEach(s => s.classList.toggle('hidden', s.id !== id));
  const list = steps(), idx = list.indexOf(id);
  const bars = document.querySelectorAll('#stepper span');
  bars.forEach((b, i) => { b.style.display = i < list.length ? '' : 'none'; b.className = i < idx ? 'done' : i === idx ? 'cur' : ''; });
  document.getElementById('stepper').classList.toggle('hidden', id === 's-done');
  document.getElementById('footer').classList.toggle('hidden', id === 's-done');
  window.scrollTo(0, 0);
  updateNext();
  if (id === 's-selfie') Live.start();
  if (id === 's-review') renderReview();
}
document.getElementById('backBtn').onclick = () => {
  const list = steps(), idx = list.indexOf(K.step);
  if (idx <= 0 || K.step === 's-done') location.href = 'dashboard.php'; else go(list[idx - 1]);
};

function updateNext() {
  const btn = document.getElementById('nextBtn');
  const map = {
    's1': [true, 'Continue'],
    's-front': [!!K.files.front, 'Continue'],
    's-back': [!!K.files.back, 'Continue'],
    's-selfie': [!!K.files.selfie, K.files.selfie ? 'Continue' : 'Complete the checks'],
    's-review': [document.getElementById('consent').checked, 'Submit for verification'],
  }[K.step] || [false, 'Continue'];
  btn.disabled = !map[0];
  btn.textContent = map[1];
}
document.getElementById('consent').onchange = updateNext;

// ---------- Step 1 ----------
document.getElementById('idType').onchange = (e) => {
  const opt = e.target.selectedOptions[0];
  K.hasBack = !opt || opt.dataset.back !== '0';
  document.querySelectorAll('.idName').forEach(n => n.textContent = e.target.value || 'ID');
};
function validateDetails() {
  const err = document.getElementById('s1Error');
  const type = document.getElementById('idType').value, num = document.getElementById('idNumber').value.trim();
  const bd = document.getElementById('birthdate').value, addr = document.getElementById('address').value.trim();
  let msg = '';
  if (!type) msg = 'Choose your ID type.';
  else if (!/^[A-Za-z0-9\- ]{4,40}$/.test(num)) msg = 'Enter the ID number exactly as shown on your ID.';
  else if (!bd) msg = 'Enter your birthdate.';
  else if (addr.length < 10) msg = 'Enter your complete home address.';
  err.innerHTML = msg ? RBNW.alertHtml(msg, 'error') : '';
  return !msg;
}

// ---------- ID photos (downscaled to <=1600px JPEG before upload) ----------
function toJpeg(source, maxSide = 1600, mirror = false) {
  const w = source.videoWidth || source.naturalWidth || source.width, h = source.videoHeight || source.naturalHeight || source.height;
  const s = Math.min(1, maxSide / Math.max(w, h));
  const c = document.createElement('canvas');
  c.width = Math.round(w * s); c.height = Math.round(h * s);
  const ctx = c.getContext('2d');
  if (mirror) { ctx.translate(c.width, 0); ctx.scale(-1, 1); }
  ctx.drawImage(source, 0, 0, c.width, c.height);
  return new Promise(res => c.toBlob(b => res(b), 'image/jpeg', 0.88));
}
document.querySelectorAll('input[type=file][data-side]').forEach(inp => inp.addEventListener('change', async () => {
  const f = inp.files[0]; inp.value = '';
  if (!f) return;
  if (!f.type.startsWith('image/')) { RBNW.toast('Please choose a photo.', 'error'); return; }
  const img = new Image();
  img.src = URL.createObjectURL(f);
  try { await img.decode(); } catch (_) { RBNW.toast("We couldn't read that photo.", 'error'); return; }
  if (Math.min(img.naturalWidth, img.naturalHeight) < 320) { RBNW.toast('Photo is too small. Move closer and retake.', 'error'); return; }
  const side = inp.dataset.side;
  K.files[side] = await toJpeg(img);
  const frame = document.getElementById('frame-' + side);
  frame.classList.add('has');
  frame.querySelector('.ph')?.remove();
  frame.querySelector('img')?.remove();
  const pv = document.createElement('img'); pv.src = URL.createObjectURL(K.files[side]); pv.alt = 'ID ' + side;
  frame.appendChild(pv);
  frame.closest('.kstep').querySelector('.take').textContent = 'Retake';
  updateNext();
}));

// ---------- Selfie liveness ----------
const Live = (() => {
  const video = document.getElementById('cam');
  const msg = document.getElementById('liveMsg');
  const LABEL = {
    left: ['Turn your head LEFT', 'Slowly, until the left line turns green'],
    right: ['Turn your head RIGHT', 'Slowly, until the right line turns green'],
    up: ['Look UP', 'Tilt your chin up a little'],
    down: ['Look DOWN', 'Tilt your chin down a little'],
    blink: ['BLINK your eyes', 'Close and open your eyes'],
  };
  let stream = null, running = false, timer = null, modelsReady = null;
  let order = [], passed = new Set(), phase = 'idle', base = null, calib = [], hold = 0, blinkClosed = false, startedAt = 0;

  const say = (t, s = '') => { msg.innerHTML = esc(t) + (s ? `<small>${esc(s)}</small>` : ''); };
  function paint() {
    const cur = phase === 'challenge' ? order[0] : null;
    document.querySelectorAll('.ring .arc').forEach(a => { a.classList.toggle('ok', passed.has(a.dataset.c)); a.classList.toggle('cur', a.dataset.c === cur); });
    document.querySelectorAll('#checks .check').forEach(c => { c.classList.toggle('ok', passed.has(c.dataset.c)); c.classList.toggle('cur', c.dataset.c === cur); });
    document.querySelectorAll('.live-arrow').forEach(a => a.style.display = a.dataset.a === cur ? 'block' : 'none');
  }

  function loadModels() {
    if (!modelsReady) {
      modelsReady = (async () => {
        for (let i = 0; i < 50 && !window.faceapi; i++) await new Promise(r => setTimeout(r, 100));
        if (!window.faceapi) throw new Error('Face detection could not load.');
        // GPU (WebGL) on most phones; plain CPU otherwise. Never the WASM
        // backend, which needs extra .wasm files we don't ship.
        let ok = false;
        try { ok = await faceapi.tf.setBackend('webgl'); } catch (_) { ok = false; }
        if (!ok) await faceapi.tf.setBackend('cpu');
        await faceapi.tf.ready();
        await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
        await faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL);
      })();
    }
    return modelsReady;
  }

  function reset() {
    order = ['left', 'right', 'up', 'down', 'blink'].sort(() => Math.random() - 0.5); // random order defeats replayed videos
    passed = new Set(); phase = 'calibrate'; base = null; calib = []; hold = 0; blinkClosed = false; startedAt = Date.now();
    delete K.files.selfie; K.liveness = null;
    document.getElementById('selfiePreview').classList.add('hidden');
    document.getElementById('stage').classList.remove('hidden');
    document.getElementById('liveError').innerHTML = '';
    paint(); updateNext();
  }

  async function start() {
    if (K.files.selfie) return; // already done; keep the result
    reset();
    say('Starting camera…');
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } }, audio: false });
      video.srcObject = stream;
      await video.play();
      say('Loading face check…');
      await loadModels();
    } catch (e) {
      stop();
      const denied = e && (e.name === 'NotAllowedError' || e.name === 'SecurityError');
      document.getElementById('liveError').innerHTML = RBNW.alertHtml(denied
        ? 'Camera access is blocked. Allow camera for this site in your browser settings, then tap Try again.'
        : (e.message || 'Could not start the camera.'), 'error')
        + '<button class="btn btn-outline" style="margin-top:8px" onclick="Live.start()">Try again</button>';
      say('Camera unavailable');
      return;
    }
    running = true;
    say('Look straight at the camera', 'Hold still for a moment');
    loop();
  }

  function stop() {
    running = false; clearTimeout(timer);
    if (stream) stream.getTracks().forEach(t => t.stop());
    stream = null;
  }

  const d = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
  function metrics(det) {
    const p = det.landmarks.positions;
    const ear = (i) => (d(p[i + 1], p[i + 5]) + d(p[i + 2], p[i + 4])) / (2 * d(p[i], p[i + 3]));
    const eyeMidY = (p[36].y + p[45].y) / 2;
    return {
      // nose position between the jaw edges (raw camera frame): >0.5 = turned to the user's LEFT
      yaw: (p[30].x - p[0].x) / (p[16].x - p[0].x),
      // nose height between eyes and chin: smaller = looking up, larger = looking down
      pitch: (p[30].y - eyeMidY) / (p[8].y - eyeMidY),
      ear: (ear(36) + ear(42)) / 2,
      size: det.detection.box.width / video.videoWidth,
      cx: (det.detection.box.x + det.detection.box.width / 2) / video.videoWidth,
    };
  }

  async function loop() {
    if (!running) return;
    let det = null;
    try {
      det = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.5 })).withFaceLandmarks();
    } catch (_) { /* transient; try next frame */ }
    if (running) { step(det); timer = setTimeout(loop, 90); }
  }

  function step(det) {
    if (!det) { hold = 0; say('No face detected', 'Center your face in the oval'); return; }
    const m = metrics(det);
    if (m.size < 0.22) { hold = 0; say('Move closer', 'Fill the oval with your face'); return; }
    if (m.cx < 0.25 || m.cx > 0.75) { hold = 0; say('Center your face', 'Keep it inside the oval'); return; }

    if (phase === 'calibrate') {
      if (Math.abs(m.yaw - 0.5) > 0.12) { calib = []; say('Look straight at the camera'); return; }
      calib.push(m);
      if (calib.length >= 8) {
        const med = (k) => calib.map(c => c[k]).sort((a, b) => a - b)[Math.floor(calib.length / 2)];
        base = { yaw: med('yaw'), pitch: med('pitch'), ear: med('ear') };
        phase = 'challenge'; paint(); prompt();
      }
      return;
    }

    if (phase === 'challenge') {
      const c = order[0];
      let ok = false;
      if (c === 'left') ok = m.yaw - base.yaw > 0.12;
      else if (c === 'right') ok = base.yaw - m.yaw > 0.12;
      else if (c === 'up') ok = base.pitch - m.pitch > 0.06;
      else if (c === 'down') ok = m.pitch - base.pitch > 0.06;
      else if (c === 'blink') {
        if (m.ear < base.ear * 0.72) blinkClosed = true;
        else if (blinkClosed && m.ear > base.ear * 0.88) ok = true;
      }
      hold = ok ? hold + 1 : 0;
      if (hold >= (c === 'blink' ? 1 : 2)) {
        passed.add(c); order.shift(); hold = 0; blinkClosed = false;
        if (navigator.vibrate) navigator.vibrate(40);
        paint();
        if (order.length) prompt();
        else { phase = 'capture'; say('Great! Now look straight', 'Taking your selfie…'); }
      }
      return;
    }

    if (phase === 'capture' && Math.abs(m.yaw - base.yaw) < 0.05 && Math.abs(m.pitch - base.pitch) < 0.04 && m.ear > base.ear * 0.8) {
      hold++;
      if (hold >= 3) capture();
    }
  }

  function prompt() { const [t, s] = LABEL[order[0]]; say(t, s); }

  async function capture() {
    phase = 'done';
    K.files.selfie = await toJpeg(video, 1280, true);
    K.liveness = { passed: [...passed], order: [...passed], duration_ms: Date.now() - startedAt };
    stop();
    const pv = document.getElementById('selfiePreview');
    pv.src = URL.createObjectURL(K.files.selfie);
    pv.classList.remove('hidden');
    document.getElementById('stage').classList.add('hidden');
    document.getElementById('liveError').innerHTML = RBNW.alertHtml('Liveness check passed.', 'success')
      + '<button class="btn btn-outline" style="margin-top:8px" onclick="Live.redo()">Retake selfie</button>';
    updateNext();
  }

  function redo() { delete K.files.selfie; start(); }
  return { start, stop, redo };
})();

// ---------- Review & submit ----------
function renderReview() {
  const u = (b) => URL.createObjectURL(b);
  document.getElementById('reviewGrid').innerHTML =
    `<figure><img src="${u(K.files.front)}" alt=""><figcaption>ID front</figcaption></figure>` +
    (K.hasBack ? `<figure><img src="${u(K.files.back)}" alt=""><figcaption>ID back</figcaption></figure>` : '') +
    `<figure class="selfie"><img src="${u(K.files.selfie)}" alt=""><figcaption>Selfie · liveness passed</figcaption></figure>`;
  const rows = [['ID type', document.getElementById('idType').value], ['ID number', document.getElementById('idNumber').value.trim().toUpperCase()],
                ['Birthdate', document.getElementById('birthdate').value], ['Address', document.getElementById('address').value.trim()]];
  document.getElementById('reviewInfo').innerHTML = rows.map(([l, v]) => `<div class="review-row"><span class="label">${l}</span><span style="text-align:right;max-width:62%">${esc(v)}</span></div>`).join('');
}

async function submit() {
  const btn = document.getElementById('nextBtn');
  const err = document.getElementById('submitError');
  btn.disabled = true; btn.innerHTML = '<span class="spinner" style="display:inline-block"></span>';
  const fd = new FormData();
  fd.append('id_type', document.getElementById('idType').value);
  fd.append('id_number', document.getElementById('idNumber').value.trim());
  fd.append('birthdate', document.getElementById('birthdate').value);
  fd.append('home_address', document.getElementById('address').value.trim());
  fd.append('liveness', JSON.stringify(K.liveness));
  fd.append('id_front', K.files.front, 'id-front.jpg');
  if (K.hasBack) fd.append('id_back', K.files.back, 'id-back.jpg');
  fd.append('selfie', K.files.selfie, 'selfie.jpg');
  let res;
  try {
    const r = await fetch('api/kyc.php?action=submit', { method: 'POST', body: fd, credentials: 'same-origin' });
    res = await r.json();
  } catch (_) { res = { success: false, message: 'Upload failed. Check your connection and try again.' }; }
  if (res.success) { go('s-done'); return; }
  err.innerHTML = RBNW.alertHtml(res.message || 'Submission failed.', 'error');
  updateNext();
}

document.getElementById('nextBtn').onclick = () => {
  if (K.step === 's1' && !validateDetails()) return;
  if (K.step === 's-review') { submit(); return; }
  const list = steps();
  go(list[list.indexOf(K.step) + 1]);
};

go('s1');
</script>
<?php endif; ?>
</div>
</body>
</html>
