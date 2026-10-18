<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$userId = current_user_id();
$user   = get_user($userId);
$myName = trim($user['first_name'] . ' ' . $user['last_name']);

// Decide mode: if ?ref= belongs to someone else, this is a "pay this
// request" visit (from Scan QR or a shared link). If it belongs to the
// current user, jump straight to that request's Activity view. Otherwise
// it's a normal "create a new request" visit.
$refParam = trim($_GET['ref'] ?? '');
$payMode  = false;
$ownerRef = null;

if ($refParam) {
    $stmt = db()->prepare("SELECT requester_id FROM rbnw_money_requests WHERE reference_no = ? LIMIT 1");
    $stmt->execute([$refParam]);
    $row = $stmt->fetch();
    if ($row) {
        if ((int)$row['requester_id'] === $userId) {
            $ownerRef = $refParam;
        } else {
            $payMode = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<title>Request Money — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
.rm-method-list { display: flex; flex-direction: column; gap: 10px; }
.rm-method-item {
  display: flex; align-items: center; gap: 14px; width: 100%;
  background: #fff; border: 1.5px solid var(--border); border-radius: 14px;
  padding: 14px; cursor: pointer; text-align: left; font: inherit; color: inherit;
  -webkit-tap-highlight-color: transparent; transition: var(--transition);
}
.rm-method-item.active { border-color: var(--rbnw-blue); box-shadow: 0 0 0 3px rgba(15,76,146,0.08); }
.rm-method-icon {
  width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0;
  background: rgba(15,76,146,0.08); color: var(--rbnw-blue);
  display: flex; align-items: center; justify-content: center; font-size: 17px;
}
.rm-method-text { flex: 1; min-width: 0; }
.rm-method-text .t { font-size: 13.5px; font-weight: 700; }
.rm-method-text .s { font-size: 11.5px; color: var(--text-muted); margin-top: 1px; }
.rm-method-item > .fa-chevron-right { color: var(--text-muted); font-size: 12px; }

.qr-wrap-x { background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 14px; display: inline-flex; box-shadow: var(--shadow-soft); }

.status-pill-x { padding: 5px 12px; border-radius: 20px; font-size: 11.5px; font-weight: 700; }
.status-pill-x.pending, .status-pill-x.viewed { background: rgba(245,166,35,0.12); color: #c17d00; }
.status-pill-x.paid { background: rgba(47,168,79,0.12); color: var(--success); }
.status-pill-x.cancelled, .status-pill-x.expired { background: rgba(224,69,63,0.12); color: var(--danger); }

.rm-share-row { display: flex; gap: 8px; width: 100%; }
.rm-share-btn {
  flex: 1; display: flex; flex-direction: column; align-items: center; gap: 5px;
  padding: 12px 6px; border-radius: 12px; border: 1.5px solid var(--border); background: #fff;
  font-size: 11px; font-weight: 700; color: var(--text-dark); cursor: pointer;
  -webkit-tap-highlight-color: transparent;
}
.rm-share-btn i { font-size: 16px; color: var(--rbnw-blue); }

.rm-to { display: flex; align-items: center; gap: 12px; background: #fff; border-radius: 14px; padding: 12px 14px; box-shadow: var(--shadow-soft); margin-bottom: 18px; }
.rm-to .avatar { width: 40px; height: 40px; font-size: 14px; }
.rm-to .who { flex: 1; min-width: 0; }
.rm-to .who small { display: block; font-size: 11.5px; color: var(--text-muted); }
.rm-to .who strong { font-size: 14px; }
.rm-to button { background: none; border: none; color: var(--rbnw-blue); font-weight: 700; font-size: 12.5px; }
.rm-person { display: flex; align-items: center; gap: 12px; width: 100%; padding: 11px 0; background: none; border: none; border-bottom: 1px solid var(--border); text-align: left; color: inherit; }
.rm-person:last-child { border-bottom: none; }
.rm-person .avatar { width: 40px; height: 40px; font-size: 13px; }
.rm-person .who { flex: 1; font-size: 14px; font-weight: 700; }
.rm-person .who small { display: block; font-size: 12px; color: var(--text-muted); font-weight: 500; }
.rm-in-card { background: #fff; border-radius: 14px; box-shadow: var(--shadow-soft); padding: 14px; margin-bottom: 10px; }
.rm-in-card .top { display: flex; align-items: center; gap: 12px; }
.rm-in-card .avatar { width: 40px; height: 40px; font-size: 13px; }
.rm-in-card .who { flex: 1; min-width: 0; font-size: 13.5px; }
.rm-in-card .who small { display: block; font-size: 11.5px; color: var(--text-muted); }
.rm-in-card .amt { font-weight: 800; font-size: 16px; color: var(--rbnw-blue); }
.rm-in-card .acts { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 12px; }
.rm-in-card .acts .btn { min-height: 42px; padding: 8px; font-size: 14px; }
.rm-out-row { display: flex; align-items: center; gap: 10px; width: 100%; padding: 11px 0; background: none; border: none; border-bottom: 1px solid var(--border); text-align: left; color: inherit; }
.rm-out-row:last-child { border-bottom: none; }
.rm-out-row .who { flex: 1; min-width: 0; font-size: 13.5px; }
.rm-out-row .who small { display: block; font-size: 11.5px; color: var(--text-muted); }
.rm-out-row .amt { font-weight: 700; font-size: 13.5px; }
.rm-link-box { display: flex; align-items: center; gap: 10px; width: 100%; background: #fff; border: 1.5px dashed var(--border-strong); border-radius: 12px; padding: 10px 12px; }
.rm-link-box span { flex: 1; min-width: 0; font-size: 12.5px; color: var(--text-body); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rm-link-box button { background: var(--rbnw-blue-50); border: none; color: var(--rbnw-blue); width: 34px; height: 34px; border-radius: 10px; }
.act-timeline-row { display: flex; gap: 12px; padding: 8px 0; }
.act-dot { width: 10px; height: 10px; border-radius: 50%; margin-top: 4px; flex-shrink: 0; background: var(--border); }
.act-dot.done { background: var(--rbnw-blue); }
.act-title { font-size: 13px; font-weight: 700; }
.act-time { font-size: 11.5px; color: var(--text-muted); margin-top: 1px; }
</style>
</head>
<body>
<div class="app-shell">

<?php if ($payMode): ?>

  <!-- ══════════ PAY MODE ══════════ -->
  <div id="payStep1">
    <div class="flow-header">
      <button class="back-btn" onclick="RBNW.goBack('dashboard.php')" style="background:none;border:none;"><i class="fa-solid fa-arrow-left"></i></button>
      <h2>Pay Request</h2>
    </div>
    <div class="flow-body" id="payPreviewBody">
      <div class="spinner" style="margin:60px auto;display:block;"></div>
    </div>
  </div>

  <div id="payStep2" class="hidden">
    <div class="flow-header">
      <button class="back-btn" onclick="goToPayStep(1)" style="background:none;border:none;"><i class="fa-solid fa-arrow-left"></i></button>
      <h2>Enter MPIN</h2>
    </div>
    <div class="flow-body">
      <p style="text-align:center;color:var(--text-muted);font-size:13.5px;">Confirm the payment with your MPIN</p>
      <div class="pin-dots" id="payPinDots">
        <div class="dot"></div><div class="dot"></div><div class="dot"></div>
        <div class="dot"></div><div class="dot"></div><div class="dot"></div>
      </div>
      <div id="payPinError" style="text-align:center;"></div>
      <div class="pin-keypad" id="payPinKeypad">
        <button data-k="1">1</button><button data-k="2">2</button><button data-k="3">3</button>
        <button data-k="4">4</button><button data-k="5">5</button><button data-k="6">6</button>
        <button data-k="7">7</button><button data-k="8">8</button><button data-k="9">9</button>
        <button class="empty"></button><button data-k="0">0</button><button data-k="del"><i class="fa-solid fa-delete-left"></i></button>
      </div>
    </div>
  </div>

  <div id="payStep3" class="hidden">
    <div class="success-screen">
      <div class="success-check"><i class="fa-solid fa-check"></i></div>
      <h2>Payment Sent!</h2>
      <p style="color:var(--text-muted);font-size:13.5px;">You paid</p>
      <div class="amt" id="paySuccessAmount">₱0.00</div>
      <p style="font-weight:700;margin:0;" id="paySuccessName"></p>
      <p style="color:var(--text-muted);font-size:11.5px;margin-top:10px;">Reference: <span id="paySuccessRef"></span></p>
      <div style="margin-top:24px;display:flex;flex-direction:column;gap:10px;">
        <button class="btn btn-primary" onclick="location.href='dashboard.php'">Done</button>
      </div>
    </div>
  </div>

<?php else: ?>

  <!-- ══════════ CREATE MODE ══════════ -->
  <div id="step1">
    <div class="flow-header">
      <button class="back-btn" onclick="RBNW.goBack('dashboard.php')" style="background:none;border:none;"><i class="fa-solid fa-arrow-left"></i></button>
      <h2>Request Money</h2>
    </div>
    <div class="flow-body">
      <p style="color:var(--text-muted);font-size:13px;margin-bottom:14px;">How would you like to request?</p>
      <div class="rm-method-list">
        <button class="rm-method-item" type="button" onclick="startMethod('contacts')">
          <div class="rm-method-icon"><i class="fa-solid fa-users"></i></div>
          <div class="rm-method-text"><div class="t">From Contacts</div><div class="s">Request from people you've sent to</div></div>
          <i class="fa-solid fa-chevron-right"></i>
        </button>
        <button class="rm-method-item" type="button" onclick="startMethod('mobile')">
          <div class="rm-method-icon"><i class="fa-solid fa-mobile-screen"></i></div>
          <div class="rm-method-text"><div class="t">Mobile Number</div><div class="s">Request from any RBNW Wallet user</div></div>
          <i class="fa-solid fa-chevron-right"></i>
        </button>
        <button class="rm-method-item" type="button" onclick="startMethod('email')">
          <div class="rm-method-icon"><i class="fa-solid fa-envelope"></i></div>
          <div class="rm-method-text"><div class="t">Email Address</div><div class="s">Find a user by their registered email</div></div>
          <i class="fa-solid fa-chevron-right"></i>
        </button>
        <button class="rm-method-item" type="button" onclick="startMethod('link')">
          <div class="rm-method-icon"><i class="fa-solid fa-link"></i></div>
          <div class="rm-method-text"><div class="t">Share Link</div><div class="s">Send a payment link via chat or SMS</div></div>
          <i class="fa-solid fa-chevron-right"></i>
        </button>
        <button class="rm-method-item" type="button" onclick="startMethod('qr')">
          <div class="rm-method-icon"><i class="fa-solid fa-qrcode"></i></div>
          <div class="rm-method-text"><div class="t">QR Code with Amount</div><div class="s">Generate QR to receive money</div></div>
          <i class="fa-solid fa-chevron-right"></i>
        </button>
      </div>

      <div id="incomingWrap" class="hidden">
        <div class="section-head"><h3>Requests for you</h3></div>
        <div id="incomingList"></div>
      </div>

      <div id="outgoingWrap" class="hidden">
        <div class="section-head"><h3>Your requests</h3></div>
        <div class="card" id="outgoingList" style="padding:4px 16px"></div>
      </div>
    </div>
  </div>

  <!-- STEP 1b: Choose who to request from -->
  <div id="stepPick" class="hidden">
    <div class="flow-header">
      <button class="back-btn" onclick="goToStep(1)" style="background:none;border:none;" aria-label="Back"><i class="fa-solid fa-arrow-left"></i></button>
      <h2 id="pickTitle">Request from</h2>
    </div>
    <div class="flow-body">
      <div class="field" id="pickField">
        <label for="pickInput" id="pickLabel">Search</label>
        <div class="input-wrap" id="pickWrap">
          <span class="prefix hidden" id="pickPrefix">+63</span>
          <i class="icon fa-solid fa-magnifying-glass" id="pickIcon"></i>
          <input type="text" id="pickInput" autocomplete="off">
        </div>
      </div>
      <div id="pickError"></div>
      <div class="card hidden" id="pickResults" style="padding:4px 16px"></div>
      <button class="btn btn-primary hidden" id="pickBtn">Continue</button>
    </div>
  </div>

  <!-- STEP 2: Create QR Request -->
  <div id="step2" class="hidden">
    <div class="flow-header">
      <button class="back-btn" onclick="R.to ? goToStep('stepPick') : goToStep(1)" style="background:none;border:none;"><i class="fa-solid fa-arrow-left"></i></button>
      <h2 id="step2Title">Create QR Request</h2>
    </div>
    <div class="flow-body">
      <div class="rm-to hidden" id="toChip"></div>
      <div class="field">
        <label>Enter Amount</label>
        <div class="amount-display" style="margin:0 0 4px;">
          <input type="text" id="reqAmountInput" inputmode="numeric" placeholder="₱0.00" autocomplete="off">
        </div>
      </div>

      <div class="field">
        <label>Add a note (optional)</label>
        <div class="input-wrap" style="align-items:flex-start;padding:10px 14px;">
          <textarea id="reqNoteInput" maxlength="100" placeholder="What's this for?" rows="2"
            style="border:none;outline:none;background:transparent;width:100%;resize:none;font-family:inherit;font-size:14px;color:var(--text-dark);"></textarea>
        </div>
        <div style="text-align:right;font-size:11px;color:var(--text-muted);margin-top:4px;" id="noteCounter">0/100</div>
      </div>

      <div class="field">
        <label>Due Date (optional)</label>
        <div class="input-wrap">
          <i class="icon fa-regular fa-calendar"></i>
          <input type="date" id="reqDueDateInput" style="border:none;outline:none;background:transparent;width:100%;font-family:inherit;font-size:14px;color:var(--text-dark);">
        </div>
      </div>

      <div id="reqError"></div>
      <button class="btn btn-primary" id="generateReqBtn">Generate QR Code</button>
    </div>
  </div>

  <!-- STEP 3: Request QR Code -->
  <div id="step3" class="hidden">
    <div class="flow-header">
      <button class="back-btn" onclick="goToStep(2)" style="background:none;border:none;"><i class="fa-solid fa-arrow-left"></i></button>
      <h2 id="step3Title">Request QR Code</h2>
    </div>
    <div class="flow-body" style="display:flex;flex-direction:column;align-items:center;gap:16px;">
      <div class="card" style="width:100%;text-align:center;">
        <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;">Scan to Pay</div>
        <div style="font-weight:800;font-size:15px;margin-top:2px;color:var(--rbnw-blue);"><?= APP_NAME ?></div>
        <div style="font-weight:700;font-size:16px;margin-top:12px;"><?= htmlspecialchars($myName) ?></div>
        <div style="font-size:12.5px;color:var(--text-muted);"><?= htmlspecialchars($user['mobile_number']) ?></div>
        <div style="font-size:28px;font-weight:800;color:var(--rbnw-blue);margin-top:12px;" id="reqDisplayAmount">₱0.00</div>
        <div style="font-size:12.5px;color:var(--text-muted);margin-top:2px;" id="reqDisplayNote"></div>
        <div class="qr-wrap-x" style="margin:18px auto 10px;"><div id="reqQrImage"></div></div>
        <div style="font-size:11.5px;color:var(--text-muted);" id="reqExpiryNote"></div>
      </div>

      <div class="rm-link-box" id="linkBox">
        <span id="linkText"></span>
        <button type="button" onclick="copyRequestLink()" aria-label="Copy link"><i class="fa-regular fa-copy"></i></button>
      </div>

      <div class="rm-share-row">
        <button class="rm-share-btn" type="button" onclick="downloadQr()"><i class="fa-solid fa-download"></i> Download</button>
        <button class="rm-share-btn" type="button" onclick="shareRequest()"><i class="fa-solid fa-share-nodes"></i> Share</button>
        <button class="rm-share-btn" type="button" onclick="copyRequestLink()"><i class="fa-regular fa-copy"></i> Copy Link</button>
      </div>

      <button class="btn btn-primary" style="width:100%;" onclick="viewActivity(currentRef)">Done</button>
    </div>
  </div>

  <!-- STEP 4: Request Activity -->
  <div id="step4" class="hidden">
    <div class="flow-header">
      <button class="back-btn" onclick="goToStep(1)" style="background:none;border:none;"><i class="fa-solid fa-arrow-left"></i></button>
      <h2>Request Activity</h2>
    </div>
    <div class="flow-body">
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
          <div>
            <div style="font-size:12px;color:var(--text-muted);">Amount</div>
            <div style="font-size:24px;font-weight:800;color:var(--rbnw-blue);" id="actAmount">₱0.00</div>
          </div>
          <div class="status-pill-x pending" id="actStatus">Pending</div>
        </div>
        <div class="review-row"><span class="label">Requested from</span><span id="actTo">—</span></div>
        <div class="review-row"><span class="label">Note</span><span id="actNote">—</span></div>
        <div class="review-row"><span class="label">Due Date</span><span id="actDueDate">—</span></div>
      </div>

      <div class="section-head"><h3>Activity</h3></div>
      <div class="card" id="actTimeline"></div>

      <div id="actCancelWrap">
        <button class="btn btn-outline" id="cancelReqBtn" style="width:100%;color:var(--danger);border-color:var(--danger);">Cancel Request</button>
      </div>
    </div>
  </div>

  <!-- STEP 5: Request sent to a person -->
  <div id="step5" class="hidden">
    <div class="success-screen">
      <div class="success-check"><i class="fa-solid fa-paper-plane"></i></div>
      <h2>Request sent!</h2>
      <p style="color:var(--text-muted);font-size:13.5px;" id="sentTo"></p>
      <div class="amt" id="sentAmount"></div>
      <p style="color:var(--text-muted);font-size:12px;">They'll get a notification and can pay from their RBNW Wallet.</p>
      <div style="margin-top:24px;display:flex;flex-direction:column;gap:10px;">
        <button class="btn btn-primary" onclick="viewActivity(currentRef)">View request</button>
        <button class="btn btn-outline" onclick="location.href='request-money.php'">Done</button>
      </div>
    </div>
  </div>

<?php endif; ?>

</div>

<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<script>
const PAY_MODE  = <?= json_encode($payMode) ?>;
const PAY_REF   = <?= json_encode($refParam) ?>;
const OWNER_REF = <?= json_encode($ownerRef) ?>;
const APP_URL_JS = <?= json_encode(rtrim(APP_URL, '/')) ?>;

function initials(name) {
  return (name || '').split(' ').filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase();
}

<?php if ($payMode): ?>
/* ══════════ PAY MODE JS ══════════ */
let payState = { ref: PAY_REF, amount: 0, requesterName: '', payable: false, pin: '' };

function goToPayStep(n) {
  for (let i = 1; i <= 3; i++) document.getElementById('payStep' + i).classList.add('hidden');
  document.getElementById('payStep' + n).classList.remove('hidden');
}

async function loadPayPreview() {
  const body = document.getElementById('payPreviewBody');
  const res = await RBNW.api('request-money.php?action=get&ref=' + encodeURIComponent(PAY_REF));

  if (!res.success) {
    body.innerHTML = RBNW.alertHtml(res.message || 'Request not found.', 'error');
    return;
  }

  payState.amount = res.amount;
  payState.requesterName = res.requester_name;
  payState.payable = res.payable;

  const statusLabel = { pending: 'Pending', viewed: 'Pending', paid: 'Already Paid', cancelled: 'Cancelled', expired: 'Expired' }[res.status] || res.status;

  body.innerHTML = `
    <div class="card" style="text-align:center;margin-top:8px;">
      <div class="avatar" style="width:60px;height:60px;font-size:22px;margin:0 auto 10px;">${initials(res.requester_name)}</div>
      <div style="font-weight:800;font-size:16px;">${RBNW.escapeHtml(res.requester_name)}</div>
      <div style="font-size:12.5px;color:var(--text-muted);">${res.requester_mobile}</div>
      <div style="font-size:13px;color:var(--text-muted);margin-top:14px;">is requesting</div>
      <div style="font-size:30px;font-weight:800;color:var(--rbnw-blue);margin-top:2px;">${RBNW.peso(res.amount)}</div>
      ${res.note ? `<div style="font-size:13px;color:var(--text-muted);margin-top:6px;">${RBNW.escapeHtml(res.note)}</div>` : ''}
      ${res.due_date ? `<div style="font-size:11.5px;color:var(--text-muted);margin-top:8px;">Due ${res.due_date}</div>` : ''}
    </div>
    ${res.payable
      ? `<button class="btn btn-primary" style="width:100%;margin-top:6px;" onclick="startPay()">Pay ${RBNW.peso(res.amount)}</button>`
      : `<div class="alert alert-info" style="margin-top:6px;"><i class="fa-solid fa-circle-info"></i> This request is ${statusLabel.toLowerCase()} and can no longer be paid.</div>`}
  `;
}
loadPayPreview();

function startPay() {
  payState.pin = '';
  updatePayPinDots();
  document.getElementById('payPinError').innerHTML = '';
  goToPayStep(2);
}

function updatePayPinDots() {
  document.querySelectorAll('#payPinDots .dot').forEach((d, i) => d.classList.toggle('filled', i < payState.pin.length));
}

document.getElementById('payPinKeypad').addEventListener('click', async (e) => {
  const btn = e.target.closest('button');
  if (!btn || btn.classList.contains('empty')) return;
  const k = btn.dataset.k;

  if (k === 'del') { payState.pin = payState.pin.slice(0, -1); updatePayPinDots(); return; }
  if (payState.pin.length >= 6) return;
  payState.pin += k;
  updatePayPinDots();

  if (payState.pin.length === 6) {
    const res = await RBNW.api('request-money.php?action=pay', 'POST', { ref: payState.ref, mpin: payState.pin });
    if (res.success) {
      document.getElementById('paySuccessAmount').textContent = res.amount_formatted;
      document.getElementById('paySuccessName').textContent = res.requester_name;
      document.getElementById('paySuccessRef').textContent = res.reference_no;
      goToPayStep(3);
    } else {
      payState.pin = '';
      updatePayPinDots();
      document.getElementById('payPinError').innerHTML = `<div class="alert alert-error">${res.message || 'Payment failed.'}</div>`;
    }
  }
});

<?php else: ?>
/* ══════════ CREATE MODE JS ══════════ */
let currentRef = OWNER_REF || null;
const esc = RBNW.escapeHtml;
// method: contacts | mobile | email | link | qr; to: chosen payer (or null)
let R = { method: 'qr', to: null };

function goToStep(n) {
  ['step1', 'stepPick', 'step2', 'step3', 'step4', 'step5'].forEach(id => document.getElementById(id).classList.add('hidden'));
  document.getElementById(typeof n === 'number' ? 'step' + n : n).classList.remove('hidden');
  window.scrollTo(0, 0);
  if (n === 1) loadLists();
}

// ---------- Requests for you / Your requests ----------
const STATUS = { pending: 'Pending', viewed: 'Viewed', paid: 'Paid', cancelled: 'Cancelled', expired: 'Expired' };
async function loadLists() {
  const res = await RBNW.api('request-money.php?action=lists');
  if (!res.success) return;
  const inW = document.getElementById('incomingWrap'), outW = document.getElementById('outgoingWrap');
  inW.classList.toggle('hidden', !res.incoming.length);
  outW.classList.toggle('hidden', !res.outgoing.length);
  document.getElementById('incomingList').innerHTML = res.incoming.map(r => `
    <div class="rm-in-card">
      <div class="top">
        <div class="avatar">${esc(initials(r.from_name))}</div>
        <div class="who"><strong>${esc(r.from_name)}</strong><small>${esc(r.note || 'Money request')} · ${esc(r.when)}${r.due_date ? ' · due ' + esc(r.due_date) : ''}</small></div>
        <div class="amt">${RBNW.peso(r.amount)}</div>
      </div>
      <div class="acts">
        <button class="btn btn-outline" onclick="declineRequest('${esc(r.reference_no)}')">Decline</button>
        <button class="btn btn-primary" onclick="location.href='request-money.php?ref=${encodeURIComponent(r.reference_no)}'">Pay</button>
      </div>
    </div>`).join('');
  document.getElementById('outgoingList').innerHTML = res.outgoing.map(r => `
    <button class="rm-out-row" onclick="viewActivity('${esc(r.reference_no)}')">
      <span class="who"><strong>${r.to_name ? esc(r.to_name) : 'QR / link request'}</strong><small>${r.note ? esc(r.note) + ' · ' : ''}${esc(r.when)}</small></span>
      <span class="amt">${RBNW.peso(r.amount)}</span>
      <span class="status-pill-x ${esc(r.status)}">${STATUS[r.status] || esc(r.status)}</span>
    </button>`).join('');
}

async function declineRequest(ref) {
  if (!confirm('Decline this request?')) return;
  const res = await RBNW.api('request-money.php?action=decline', 'POST', { ref });
  RBNW.toast(res.success ? 'Request declined.' : (res.message || 'Could not decline.'), res.success ? 'info' : 'error');
  loadLists();
}

// ---------- Choose a method ----------
function startMethod(method) {
  R = { method, to: null };
  if (method === 'link' || method === 'qr') { openAmountStep(); return; }

  const input = document.getElementById('pickInput');
  const cfg = {
    contacts: ['From Contacts', 'Search name or mobile number', 'text', 'Juan or 0917…'],
    mobile:   ['Mobile Number', 'Mobile number', 'tel', '917 123 4567'],
    email:    ['Email Address', 'Email address', 'email', 'name@email.com'],
  }[method];
  document.getElementById('pickTitle').textContent = cfg[0];
  document.getElementById('pickLabel').textContent = cfg[1];
  input.type = cfg[2];
  input.placeholder = cfg[3];
  input.inputMode = method === 'mobile' ? 'numeric' : (method === 'email' ? 'email' : 'text');
  input.value = '';
  document.getElementById('pickPrefix').classList.toggle('hidden', method !== 'mobile');
  document.getElementById('pickIcon').className = 'icon fa-solid ' + (method === 'email' ? 'fa-envelope' : method === 'mobile' ? 'fa-mobile-screen hidden' : 'fa-magnifying-glass');
  document.getElementById('pickBtn').classList.toggle('hidden', method === 'contacts');
  document.getElementById('pickResults').classList.add('hidden');
  document.getElementById('pickError').innerHTML = '';
  goToStep('stepPick');
  setTimeout(() => input.focus(), 50);
  if (method === 'contacts') searchContacts('');
}

const pickInput = document.getElementById('pickInput');
let searchTimer = null;
pickInput.addEventListener('input', () => {
  document.getElementById('pickError').innerHTML = '';
  if (R.method === 'mobile') pickInput.value = RBNW.prettyMobile(pickInput.value);
  if (R.method === 'contacts') {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => searchContacts(pickInput.value.trim()), 250);
  }
});
pickInput.addEventListener('keydown', (e) => { if (e.key === 'Enter' && R.method !== 'contacts') document.getElementById('pickBtn').click(); });

async function searchContacts(q) {
  const box = document.getElementById('pickResults');
  const res = await RBNW.api('send-money.php?action=search&q=' + encodeURIComponent(q));
  const people = (res.results || []).filter(p => p.user_id);
  box.classList.remove('hidden');
  if (!people.length) {
    box.innerHTML = `<div class="empty-state" style="padding:22px 8px">${q ? 'No RBNW Wallet users found.' : 'No contacts yet. Search by name or mobile number.'}</div>`;
    return;
  }
  window._people = people;
  box.innerHTML = (q ? '' : '<div style="font-size:12px;font-weight:700;color:var(--text-muted);padding:10px 0 2px">FREQUENT CONTACTS</div>') +
    people.map((p, i) => `<button class="rm-person" onclick="choosePerson(window._people[${i}])">
      <span class="avatar">${esc(p.initials)}</span>
      <span class="who">${esc(p.name)}<small>${esc(p.mobile_number)}</small></span>
      <i class="fa-solid fa-chevron-right" style="color:var(--text-faint)"></i></button>`).join('');
}

document.getElementById('pickBtn').addEventListener('click', async (e) => {
  const btn = e.currentTarget;
  const err = document.getElementById('pickError');
  btn.disabled = true;
  const res = R.method === 'mobile'
    ? await RBNW.api('send-money.php?action=lookup&mobile=' + encodeURIComponent(RBNW.mobileFromField(pickInput)))
    : await RBNW.api('request-money.php?action=lookup_email&email=' + encodeURIComponent(pickInput.value.trim()));
  btn.disabled = false;
  if (!res.found) {
    err.innerHTML = RBNW.alertHtml((res.message || 'User not found.').replace('send money to', 'request money from'), 'error');
    return;
  }
  choosePerson(res);
});

function choosePerson(p) {
  R.to = { user_id: p.user_id, name: p.name, mobile: p.mobile_number, initials: p.initials };
  openAmountStep();
}

function openAmountStep() {
  const chip = document.getElementById('toChip');
  if (R.to) {
    chip.innerHTML = `<span class="avatar">${esc(R.to.initials)}</span>
      <span class="who"><small>Request from</small><strong>${esc(R.to.name)}</strong> <small>${esc(R.to.mobile)}</small></span>
      <button type="button" onclick="startMethod(R.method)">Change</button>`;
  }
  chip.classList.toggle('hidden', !R.to);
  document.getElementById('step2Title').textContent = R.to ? 'Request Money' : (R.method === 'link' ? 'Create Payment Link' : 'Create QR Request');
  document.getElementById('generateReqBtn').textContent = R.to ? 'Send Request' : (R.method === 'link' ? 'Create Link' : 'Generate QR Code');
  document.getElementById('reqError').innerHTML = '';
  goToStep(2);
}

document.getElementById('reqAmountInput').addEventListener('input', (e) => {
  e.target.value = e.target.value.replace(/[^0-9.]/g, '');
});

document.getElementById('reqNoteInput').addEventListener('input', (e) => {
  document.getElementById('noteCounter').textContent = e.target.value.length + '/100';
});

document.getElementById('generateReqBtn').addEventListener('click', async () => {
  const amount = parseFloat(document.getElementById('reqAmountInput').value || '0');
  const note = document.getElementById('reqNoteInput').value.trim();
  const dueDate = document.getElementById('reqDueDateInput').value;
  const errBox = document.getElementById('reqError');

  if (!amount || amount <= 0) {
    errBox.innerHTML = RBNW.alertHtml('Please enter a valid amount.', 'error');
    return;
  }
  errBox.innerHTML = '';

  const btn = document.getElementById('generateReqBtn');
  btn.disabled = true;

  const label = btn.textContent;
  const res = await RBNW.api('request-money.php?action=generate', 'POST', {
    amount, note, due_date: dueDate, to_user_id: R.to ? R.to.user_id : null,
  });

  btn.disabled = false;
  btn.textContent = label;

  if (!res.success) {
    errBox.innerHTML = RBNW.alertHtml(res.message || 'Failed to create request.', 'error');
    return;
  }

  currentRef = res.reference_no;
  if (R.to) {
    document.getElementById('sentTo').textContent = 'Sent to ' + res.to_name;
    document.getElementById('sentAmount').textContent = RBNW.peso(res.amount);
    goToStep(5);
    return;
  }
  renderQrStep(res);
});

function renderQrStep(res) {
  document.getElementById('reqDisplayAmount').textContent = RBNW.peso(res.amount);
  document.getElementById('reqDisplayNote').textContent = res.note || '';
  document.getElementById('reqExpiryNote').textContent = res.due_date ? ('This request is payable until ' + res.due_date) : 'This request has no expiry date';

  const box = document.getElementById('reqQrImage');
  box.innerHTML = '';
  new QRCode(box, {
    text: res.qr_payload,
    width: 200, height: 200,
    colorDark: '#0f4c92', colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.M
  });

  document.getElementById('linkText').textContent = requestLink();
  document.getElementById('step3Title').textContent = R.method === 'link' ? 'Payment Link' : 'Request QR Code';
  goToStep(3);
}

function downloadQr() {
  const canvas = document.querySelector('#reqQrImage canvas');
  if (!canvas) { RBNW.toast('QR not ready yet.', 'error'); return; }
  const a = document.createElement('a');
  a.href = canvas.toDataURL('image/png');
  a.download = 'rbnw-request-' + currentRef + '.png';
  a.click();
}

function requestLink() {
  return APP_URL_JS + '/request-money.php?ref=' + encodeURIComponent(currentRef);
}

function shareRequest() {
  const amt = document.getElementById('reqDisplayAmount').textContent;
  const msg = `<?= addslashes($myName) ?> is requesting ${amt} via RBNW Wallet.\n${requestLink()}`;
  if (navigator.share) {
    navigator.share({ title: 'RBNW Wallet Request', text: msg }).catch(() => {});
  } else {
    navigator.clipboard.writeText(msg).then(() => RBNW.toast('Request details copied!', 'success'));
  }
}

function copyRequestLink() {
  navigator.clipboard.writeText(requestLink()).then(() => {
    RBNW.toast('Link copied!', 'success');
  }).catch(() => RBNW.toast(requestLink(), 'info'));
}

async function viewActivity(ref) {
  if (!ref) { goToStep(1); return; }
  currentRef = ref;
  goToStep(4);
  await loadActivity(ref);
}

async function loadActivity(ref) {
  const res = await RBNW.api('request-money.php?action=get&ref=' + encodeURIComponent(ref));
  if (!res.success) {
    document.getElementById('actTimeline').innerHTML = `<div class="alert alert-error">${res.message || 'Could not load this request.'}</div>`;
    return;
  }

  document.getElementById('actAmount').textContent = RBNW.peso(res.amount);
  document.getElementById('actNote').textContent = res.note || '—';
  document.getElementById('actTo').textContent = res.to_name || 'Anyone with the QR / link';
  document.getElementById('actDueDate').textContent = res.due_date || '—';

  const statusLabel = { pending: 'Pending', viewed: 'Viewed', paid: 'Paid', cancelled: 'Cancelled', expired: 'Expired' }[res.status] || res.status;
  const statusEl = document.getElementById('actStatus');
  statusEl.textContent = statusLabel;
  statusEl.className = 'status-pill-x ' + res.status;

  const rows = [
    { label: 'Request Created', time: res.created_at, done: true },
    { label: 'QR Code Generated', time: res.created_at, done: true },
    { label: 'Request Viewed', time: res.viewed_at, done: !!res.viewed_at },
    { label: 'Payment Received', time: res.paid_at, done: !!res.paid_at },
  ];
  document.getElementById('actTimeline').innerHTML = rows.map(r => `
    <div class="act-timeline-row">
      <div class="act-dot ${r.done ? 'done' : ''}"></div>
      <div>
        <div class="act-title">${r.label}</div>
        <div class="act-time">${r.time || '—'}</div>
      </div>
    </div>
  `).join('');

  const cancelWrap = document.getElementById('actCancelWrap');
  if (res.status === 'pending' || res.status === 'viewed') {
    cancelWrap.style.display = 'block';
  } else {
    cancelWrap.style.display = 'none';
  }
}

document.getElementById('cancelReqBtn').addEventListener('click', async () => {
  if (!currentRef) return;
  const btn = document.getElementById('cancelReqBtn');
  btn.disabled = true;
  const res = await RBNW.api('request-money.php?action=cancel', 'POST', { ref: currentRef });
  btn.disabled = false;
  if (res.success) {
    RBNW.toast('Request cancelled.', 'success');
    loadActivity(currentRef);
  } else {
    RBNW.toast(res.message || 'Could not cancel this request.', 'error');
  }
});

if (OWNER_REF) {
  viewActivity(OWNER_REF);
} else {
  loadLists();
}
<?php endif; ?>
</script>
</body>
</html>
