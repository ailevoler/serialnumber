<?php
declare(strict_types=1);
/**
 * V5 - Admin: Client Approval + Payment Credentials (API / Webhook / Fees) card.
 * Included by org-view.php; expects $org and $orgId.
 */
?>
<div class="dash-card v5-card" id="credentials" style="margin-bottom:18px">
  <div class="dash-card-title">
    <span>Client Approval &amp; Payment Credentials</span>
    <span style="display:flex;gap:6px;flex-wrap:wrap">
      <button class="btn btn-sm btn-primary" id="cgAddBtn" onclick="cgOpenEditor()">+ Add Payment Provider</button>
    </span>
  </div>

  <div id="cgApproval" class="v5-approval"><div class="table-loading">Loading...</div></div>

  <div style="overflow-x:auto;margin-top:12px">
    <table class="data-table" style="min-width:980px">
      <thead><tr>
        <th style="text-align:left">Provider</th><th>Environment</th><th>Status</th><th>Fees / MDR</th>
        <th style="text-align:left">API Keys</th><th style="text-align:left">Webhook</th><th>Actions</th>
      </tr></thead>
      <tbody id="cgTbody"><tr><td colspan="7" class="table-loading">Loading...</td></tr></tbody>
    </table>
  </div>
  <p class="form-hint" style="margin-top:8px">Credentials are stored encrypted (AES-256-GCM, <code>APP_KEY</code>). Secrets are never shown again after saving — leave a secret field blank to keep the current value. Priority provider: <strong>PayMongo</strong> (Static Store Front QR Ph + Dynamic QR Ph).</p>
</div>

<!-- V5.21 Cash Out settings -->
<div class="modal-backdrop" id="cgCoModal">
  <div class="modal">
    <button class="modal-close" onclick="sbCloseModal('cgCoModal')">&times;</button>
    <h2 class="modal-title">PayMongo Cash Out</h2>
    <p class="form-hint" style="margin-top:-6px">Lets the client's <b>Owner / Finance</b> send money from their own PayMongo Wallet to any bank or e-wallet (InstaPay up to ₱50,000, PESONet up to ₱10,000,000) from the Client Portal — no PayMongo login needed. The client's PayMongo Wallet must be activated (Statement of Acceptance signed in PayMongo).</p>
    <div id="cgCoErr"></div>
    <input type="hidden" id="cgCoId">
    <label class="v5-check" style="margin-bottom:12px"><input type="checkbox" id="cgCoEnabled"> <b>Enable Cash Out for this client</b></label>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Max per transfer (₱, optional)</label><input type="number" step="0.01" min="0" class="form-control" id="cgCoMax" placeholder="no extra limit"></div>
      <div class="form-group"><label class="form-label">Daily limit (₱, optional)</label><input type="number" step="0.01" min="0" class="form-control" id="cgCoDaily" placeholder="no extra limit"></div>
    </div>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="sbCloseModal('cgCoModal')">Cancel</button>
      <button class="btn btn-primary" id="cgCoSave">Save</button>
    </div>
  </div>
</div>

<!-- Gateway editor -->
<div class="modal-backdrop" id="cgModal">
  <div class="modal modal-lg">
    <button class="modal-close" onclick="sbCloseModal('cgModal')">&times;</button>
    <h2 class="modal-title" id="cgModalTitle">Payment Provider Credentials</h2>
    <div id="cgError"></div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Payment Third-Party</label><select class="form-control" id="cgProvider"></select></div>
      <div class="form-group"><label class="form-label">Environment</label>
        <select class="form-control" id="cgEnv"><option value="test">Test / Sandbox</option><option value="live">Live / Production</option></select></div>
      <div class="form-group"><label class="form-label">Status</label>
        <select class="form-control" id="cgStatus"><option value="Draft">Draft (not usable yet)</option><option value="Active">Active</option><option value="Disabled">Disabled</option></select></div>
    </div>
    <div id="cgProviderNote"></div>

    <h3 class="v5-subhead">API Credentials</h3>
    <div id="cgFields" class="form-row" style="flex-wrap:wrap"></div>

    <h3 class="v5-subhead">Webhook</h3>
    <div class="form-group">
      <label class="form-label">Webhook URL for this client</label>
      <div class="v5-copy"><input class="form-control" id="cgWebhookUrl" readonly placeholder="Generated after the first save"><button type="button" class="btn btn-sm btn-gray" onclick="cgCopy('cgWebhookUrl')">Copy</button></div>
      <p class="form-hint">For PayMongo, save first then click <b>Register Webhook</b> in the table — SurgeBox creates the webhook on the client's PayMongo account (events: payment.paid, payment.failed, qrph.expired) and stores the signing secret automatically.</p>
    </div>

    <h3 class="v5-subhead">Fees / MDR (charged by SurgeBox)</h3>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Fee Type</label>
        <select class="form-control" id="cgFeeType">
          <option value="Fixed">Fixed (e.g. ₱10.00 per transaction)</option>
          <option value="Percentage">Percentage (MDR %)</option>
          <option value="Fixed + Percentage">Fixed + Percentage (e.g. ₱10.00 + 1.5% MDR)</option>
          <option value="Bracket">Bracket by amount (Fixed + MDR % per amount range)</option>
          <option value="None">No fee</option>
        </select></div>
      <div class="form-group" id="cgFeeFixedWrap"><label class="form-label">Fixed Fee (₱)</label><input type="number" step="0.01" min="0" class="form-control" id="cgFeeFixed" value="10.00"></div>
      <div class="form-group" id="cgFeePctWrap"><label class="form-label">MDR / Percentage (%)</label><input type="number" step="0.001" min="0" max="100" class="form-control" id="cgFeePct" value="1.5"></div>
    </div>
    <div class="form-group" id="cgBracketWrap" style="display:none">
      <label class="form-label">Amount Brackets</label>
      <div style="overflow-x:auto"><table class="data-table" style="min-width:560px">
        <thead><tr><th>From (₱)</th><th>To (₱)</th><th>Fixed Fee (₱)</th><th>MDR (%)</th><th></th></tr></thead>
        <tbody id="cgBracketRows"></tbody>
      </table></div>
      <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
        <button type="button" class="btn btn-sm btn-gray" id="cgBracketAdd">+ Add bracket</button>
        <button type="button" class="btn btn-sm btn-gray" id="cgBracketSample">Use sample brackets</button>
      </div>
      <p class="form-hint">Fee = Fixed + (amount × MDR %) of the bracket the payment amount falls in. Leave <b>To</b> empty on the last bracket for "and above". Amounts outside every bracket have no fee.</p>
    </div>
    <div class="form-row" id="cgFeeCapRow">
      <div class="form-group"><label class="form-label">Minimum Fee (₱, optional)</label><input type="number" step="0.01" min="0" class="form-control" id="cgFeeMin" placeholder="none"></div>
      <div class="form-group"><label class="form-label">Maximum Fee / Cap (₱, optional)</label><input type="number" step="0.01" min="0" class="form-control" id="cgFeeMax" placeholder="none"></div>
    </div>
    <div class="form-group">
      <label class="form-label">How is the fee collected?</label>
      <label class="v5-radio"><input type="radio" name="cgFeeMode" value="deduct" checked> <span><b>Deduct from settlement (recommended)</b> — e.g. payer sends ₱40.00, fee ₱10.00 → client receives/sees <b>₱30.00</b>.</span></label>
      <label class="v5-radio"><input type="radio" name="cgFeeMode" value="separate"> <span><b>Do not deduct — bill separately</b> — payer sends ₱40.00 → client still sees <b>₱40.00</b>; the ₱10.00 is invoiced later. The client sees the full processed amount in PayMongo and in their Settlement Dashboard; the fee goes to <i>Client Fees</i> for separate billing.</span></label>
      <p class="form-hint" id="cgFeePreview"></p>
    </div>

    <div class="form-row">
      <label class="v5-check"><input type="checkbox" id="cgDefault"> Default provider for this client</label>
      <label class="v5-check" style="flex-basis:100%"><input type="checkbox" id="cgApplyExisting" checked> <b>Apply this fee to existing transactions too</b> <small>(recalculates Amount, Fee and Balance of past payments)</small></label>
      <label class="v5-check"><input type="checkbox" id="cgCredit"> Also credit the SurgeBox wallet balance <small>(only if funds settle to a SurgeBox-controlled account)</small></label>
    </div>

    <div class="modal-actions">
      <button class="btn btn-outline" onclick="sbCloseModal('cgModal')">Cancel</button>
      <button class="btn btn-primary" id="cgSaveBtn">Save Credentials</button>
    </div>
  </div>
</div>

<script>
(function () {
  const CG_BASE = <?= json_encode(base_path()) ?>;
  const CG_ORG = <?= (int) $orgId ?>;
  let CG = null;
  let cgEditingCode = null;

  function envPill(env) { return env === 'live' ? '<span class="pill pill-completed">LIVE</span>' : '<span class="pill pill-sandbox">TEST</span>'; }
  function stPill(s) { return s === 'Active' ? '<span class="pill pill-completed">Active</span>' : s === 'Disabled' ? '<span class="pill pill-disabled">Disabled</span>' : '<span class="pill pill-pending">Draft</span>'; }

  function renderApproval() {
    const o = CG.organization, d = CG.documents;
    const approved = o.is_approved;
    const badge = o.onboarding_status === 'Approved' ? '<span class="pill pill-completed">Approved</span>'
      : o.onboarding_status === 'Approved - Documents to Follow' ? '<span class="pill" style="background:#fef3c7;color:#92400e">Approved – Documents to Follow</span>'
      : '<span class="pill pill-pending">Pending Approval</span>';
    let html = `<div class="v5-approval-row"><div><div class="form-hint">Client approval</div><div style="font-size:15px;font-weight:700">${badge}</div>
      ${o.approved_at ? `<div class="form-hint">since ${sbEscape(o.approved_at)}${o.approval_notes ? ' · ' + sbEscape(o.approval_notes) : ''}</div>` : ''}</div>
      <div><div class="form-hint">Merchant documents</div><div style="font-size:15px;font-weight:700">${d.submitted} / ${d.total} submitted <span class="form-hint">(${d.verified} verified)</span></div></div>
      <div class="v5-approval-actions">`;
    if (!approved) {
      html += `<input class="form-control" id="cgApproveNotes" placeholder="Approval notes (optional)" style="max-width:220px">
        <button class="btn btn-sm btn-primary" ${d.complete ? '' : 'disabled title="All merchant documents must be submitted first"'} onclick="cgApprove(false)">Approve</button>
        <button class="btn btn-sm btn-gray" onclick="cgApprove(true)">Approve – Documents to Follow</button>`;
    } else {
      html += `<button class="btn btn-sm btn-outline" onclick="cgRevoke()">Revoke Approval</button>`;
    }
    html += `</div></div>`;
    if (!approved) html += `<div class="alert" style="background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;margin:10px 0 0">Payment credentials (API, Webhook, Fees) can be set up once the client is approved — either after the pre-registration documents are approved, or right away with <b>Approve – Documents to Follow</b>.</div>`;
    if (!CG.app_key_set) html += `<div class="alert alert-error" style="margin:10px 0 0">APP_KEY is not set in .env — credentials cannot be saved until it is added.</div>`;
    document.getElementById('cgApproval').innerHTML = html;
    document.getElementById('cgAddBtn').disabled = !approved;
  }

  function renderGateways() {
    const tb = document.getElementById('cgTbody');
    // Big, obvious warning + one-click fix when a gateway is NOT deducting its fee.
    let warn = document.getElementById('cgSepWarn');
    if (!warn) { warn = document.createElement('div'); warn.id = 'cgSepWarn'; tb.closest('div').parentNode.insertBefore(warn, tb.closest('div')); }
    const sep = CG.gateways.filter(g => g.fee_mode === 'separate' && g.fee_type !== 'None');
    warn.innerHTML = sep.map(g => `<div class="alert" style="background:#fffbeb;color:#78350f;border:1px solid #fcd34d;margin:12px 0 0;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
      <span style="flex:1;min-width:240px"><b>${sbEscape(g.provider_name)}: the ${sbEscape(g.fee_label.split(' · ')[0])} fee is NOT being deducted.</b> The client currently sees the full amount (e.g. ₱40.00 instead of ₱30.00).</span>
      <button class="btn btn-sm btn-primary" onclick="cgSetDeduct(${g.id}, this)">Deduct Fee from Client &amp; Fix Past Transactions</button></div>`).join('');
    if (!CG.gateways.length) {
      tb.innerHTML = `<tr><td colspan="7" class="table-empty">No payment provider set up for this client yet.${CG.organization.is_approved ? ' Click “+ Add Payment Provider” (PayMongo recommended).' : ''}</td></tr>`;
      return;
    }
    tb.innerHTML = CG.gateways.map(g => {
      const keys = Object.entries(g.secrets_masked || {}).map(([k, v]) => `<div><span class="form-hint">${sbEscape(k)}:</span> <code>${sbEscape(v || '—')}</code></div>`).join('')
        + Object.entries(g.public_config || {}).filter(([k, v]) => v).map(([k, v]) => `<div><span class="form-hint">${sbEscape(k)}:</span> <code>${sbEscape(String(v).length > 28 ? String(v).slice(0, 14) + '…' + String(v).slice(-6) : v)}</code></div>`).join('');
      const isPm = g.provider_code === 'paymongo';
      return `<tr>
        <td style="text-align:left"><b>${sbEscape(g.provider_name)}</b>${+g.is_default ? ' <span class="pill" style="background:#eef2ff;color:#3730a3">Default</span>' : ''}
          ${g.provider_code === 'nationlink' ? '<div class="form-hint">Static QR Ph: see <a href="#nlqr">Nationlink QR Ph</a> card</div>' : (g.integration_status !== 'Live' ? '<div class="form-hint">Credentials only (integration pending)</div>' : '')}
          ${g.secrets_error ? `<div class="form-hint" style="color:#b91c1c">${sbEscape(g.secrets_error)}</div>` : ''}</td>
        <td>${envPill(g.environment)}</td>
        <td>${stPill(g.status)}</td>
        <td style="font-size:12px">${sbEscape(g.fee_label)}${g.fee_type !== 'None' ? (g.fee_mode === 'separate'
          ? '<div><span class="pill" style="background:#fef3c7;color:#92400e;margin-top:4px">NOT deducted — client gets full amount</span></div>'
          : '<div><span class="pill pill-completed" style="margin-top:4px">Deducted from client</span></div>') : ''}</td>
        <td style="text-align:left;font-size:12px">${keys || '—'}</td>
        <td style="text-align:left;font-size:12px;max-width:260px">
          <div class="v5-copy"><input class="form-control" style="font-size:11px;padding:4px 6px" readonly value="${sbEscape(g.webhook_url)}" id="cgWh${g.id}"><button class="btn btn-sm btn-gray" onclick="cgCopy('cgWh${g.id}')">Copy</button></div>
          <div class="form-hint">${g.webhook_remote_id ? 'Registered: ' + sbEscape(g.webhook_remote_id) : (isPm ? 'Not registered yet' : (g.provider_code === 'nationlink' ? 'Give this URL to Nationlink' : 'Paste into provider dashboard'))}</div>
          ${g.last_test_result ? `<div class="form-hint" title="${sbEscape(g.last_tested_at || '')}">${sbEscape(g.last_test_result)}</div>` : ''}</td>
        <td style="white-space:nowrap">
          <button class="btn btn-sm btn-gray" onclick="cgOpenEditor('${g.provider_code}')">Edit</button>
          ${isPm ? `<button class="btn btn-sm btn-gray" onclick="cgAction('test', ${g.id}, this)">Test</button>
          <button class="btn btn-sm btn-primary" onclick="cgAction('register_webhook', ${g.id}, this)">Register Webhook</button>
          <button class="btn btn-sm ${+g.cashout_enabled ? 'btn-primary' : 'btn-gray'}" title="PayMongo Send Money from the client portal" onclick="cgCashout(${g.id})">Cash Out: ${+g.cashout_enabled ? 'ON' : 'OFF'}</button>` : ''}
          <button class="btn btn-sm btn-gray" title="Apply the current fee setting to this client's existing transactions" onclick="cgRecalc(${g.id}, this)">Recalculate Fees</button>
          <button class="icon-btn danger" title="Remove" onclick="cgAction('delete', ${g.id}, this, true)">🗑</button>
        </td></tr>`;
    }).join('');
  }

  async function cgLoad() {
    try {
      const r = await sbFetch(`${CG_BASE}/api/client_gateways.php?organizationId=${CG_ORG}`);
      CG = r.data; renderApproval(); renderGateways();
      // V5.26: Admin > Client Fees > "Edit Fee" opens this client's fee editor directly.
      const editFee = new URLSearchParams(location.search).get('editFee');
      if (editFee && !cgLoad.opened && CG.gateways.some(g => g.provider_code === editFee)) {
        cgLoad.opened = true;
        document.getElementById('cgTbody').scrollIntoView({ behavior: 'smooth', block: 'center' });
        cgOpenEditor(editFee);
      }
    } catch (e) {
      document.getElementById('cgApproval').innerHTML = `<div class="alert alert-error">${sbEscape(e.message)}</div>`;
    }
  }
  function flash(msg, ok) {
    const el = document.getElementById('cgApproval');
    const div = document.createElement('div');
    div.className = 'alert ' + (ok ? 'alert-success' : 'alert-error');
    div.style.marginTop = '10px';
    div.textContent = msg;
    el.appendChild(div);
    setTimeout(() => div.remove(), 9000);
  }

  window.cgApprove = async function (follow) {
    if (!confirm(follow ? 'Approve this client now with documents TO FOLLOW?' : 'Approve this client (documents reviewed)?')) return;
    try {
      const notes = (document.getElementById('cgApproveNotes') || {}).value || '';
      const r = await sbFetch(`${CG_BASE}/api/client_gateways.php`, { method: 'POST', body: JSON.stringify({ action: 'approve', organizationId: CG_ORG, docs_to_follow: follow, notes }) });
      CG = r.data; renderApproval(); renderGateways(); flash(r.message, true);
    } catch (e) { flash(e.message, false); }
  };
  window.cgRevoke = async function () {
    if (!confirm('Revoke approval? Active payment gateways for this client will be disabled.')) return;
    try {
      const r = await sbFetch(`${CG_BASE}/api/client_gateways.php`, { method: 'POST', body: JSON.stringify({ action: 'revoke_approval', organizationId: CG_ORG }) });
      CG = r.data; renderApproval(); renderGateways(); flash(r.message, true);
    } catch (e) { flash(e.message, false); }
  };
  window.cgAction = async function (action, id, btn, confirmFirst) {
    if (confirmFirst && !confirm('Remove this provider from the client? (If it already has transactions it will be disabled instead.)')) return;
    sbSetLoading(btn, '...');
    try {
      const r = await sbFetch(`${CG_BASE}/api/client_gateways.php`, { method: 'POST', body: JSON.stringify({ action, id }) });
      if (r.data) { CG = r.data; renderApproval(); renderGateways(); }
      flash(r.message, true);
    } catch (e) {
      if (e.data && e.data.data) { CG = e.data.data; renderGateways(); }
      flash(e.message, false);
    } finally { sbClearLoading(btn); }
  };
  // V5.21: PayMongo Cash Out switch + limits
  window.cgCashout = function (id) {
    const g = CG.gateways.find(x => +x.id === +id); if (!g) return;
    document.getElementById('cgCoId').value = id;
    document.getElementById('cgCoEnabled').checked = !!+g.cashout_enabled;
    document.getElementById('cgCoMax').value = g.cashout_max_per_txn ?? '';
    document.getElementById('cgCoDaily').value = g.cashout_daily_limit ?? '';
    document.getElementById('cgCoErr').innerHTML = '';
    sbOpenModal('cgCoModal');
  };
  document.getElementById('cgCoSave').addEventListener('click', async (ev) => {
    const btn = ev.currentTarget; sbSetLoading(btn, 'Saving...');
    try {
      const r = await sbFetch(`${CG_BASE}/api/client_gateways.php`, { method: 'POST', body: JSON.stringify({ action: 'cashout_settings', id: +document.getElementById('cgCoId').value,
        enabled: document.getElementById('cgCoEnabled').checked, max_per_txn: document.getElementById('cgCoMax').value, daily_limit: document.getElementById('cgCoDaily').value }) });
      CG = r.data; renderGateways(); sbCloseModal('cgCoModal'); flash(r.message, true);
      if (window.coAdminReload) window.coAdminReload();
    } catch (e) { document.getElementById('cgCoErr').innerHTML = `<div class="alert alert-error">${sbEscape(e.message)}</div>`; }
    finally { sbClearLoading(btn); }
  });

  window.cgSetDeduct = function (id, btn) {
    if (!confirm('Deduct the fee from the client for ALL payments (past and future)? Amounts and balances of past payments will be corrected.')) return;
    cgAction('set_deduct', id, btn);
  };
  window.cgRecalc = function (id, btn) {
    const g = CG.gateways.find(x => x.id === id);
    if (!confirm(`Re-apply the current fee setting (${g ? g.fee_label : ''}) to ALL existing transactions of this provider? Amounts, fees and the client's balance will be updated.`)) return;
    cgAction('recalc_fees', id, btn);
  };
  window.cgCopy = function (id) {
    const el = document.getElementById(id);
    el.select();
    (navigator.clipboard ? navigator.clipboard.writeText(el.value) : Promise.resolve(document.execCommand('copy'))).catch(() => {});
  };

  function renderFields(code, g) {
    const p = CG.providers.find(x => x.code === code);
    const wrap = document.getElementById('cgFields');
    wrap.innerHTML = (p ? p.fields : []).map(f => {
      const cur = g ? (f.secret ? (g.secrets_masked || {})[f.key] : (g.public_config || {})[f.key]) : '';
      return `<div class="form-group" style="flex:1 1 45%;min-width:240px"><label class="form-label">${sbEscape(f.label)}${f.required ? ' *' : ''}${f.secret ? ' <span class="form-hint">(secret)</span>' : ''}</label>
        <input class="form-control" data-key="${f.key}" data-secret="${f.secret ? 1 : 0}" type="${f.secret ? 'password' : 'text'}" autocomplete="off"
          value="${f.secret ? '' : sbEscape(cur || '')}" placeholder="${f.secret && cur ? 'Saved: ' + sbEscape(cur) + ' — leave blank to keep' : sbEscape(f.placeholder || '')}"></div>`;
    }).join('');
    const note = document.getElementById('cgProviderNote');
    if (code === 'nationlink') {
      note.innerHTML = `<div class="alert" style="background:#f5f3ff;color:#4c1d95;border:1px solid #c4b5fd"><b>Nationlink — all fields are optional.</b> Save it (Status: Active) so you can attach the Static QR Ph issued by Nationlink in the <b>Nationlink QR Ph</b> card below. Payments to those QRs are recorded on this client for settlement (fee below applies).</div>`;
      if (!g) { document.getElementById('cgStatus').value = 'Active'; document.getElementById('cgEnv').value = 'live'; document.getElementById('cgDefault').checked = false; }
      return;
    }
    note.innerHTML = p && p.integration_status !== 'Live'
      ? `<div class="alert" style="background:#fff7ed;color:#9a3412;border:1px solid #fdba74">${sbEscape(p.display_name)}: credentials can be stored now; per-client QR generation for this provider is not live yet (PayMongo is the priority integration).</div>`
      : (p && !+p.is_enabled ? `<div class="alert alert-error">${sbEscape(p.display_name)} is disabled globally in Payment Providers.</div>` : '');
  }

  // V5.25: amount brackets editor (Fixed + MDR % per amount range)
  const bracketBody = document.getElementById('cgBracketRows');
  function bracketRow(b) {
    const v = x => x === null || x === undefined ? '' : x;
    const tr = document.createElement('tr');
    tr.innerHTML = `<td><input type="number" step="0.01" min="0" class="form-control" data-b="from" value="${v(b.from)}"></td>
      <td><input type="number" step="0.01" min="0" class="form-control" data-b="to" value="${v(b.to)}" placeholder="and above"></td>
      <td><input type="number" step="0.01" min="0" class="form-control" data-b="fixed" value="${v(b.fixed)}"></td>
      <td><input type="number" step="0.001" min="0" max="100" class="form-control" data-b="percent" value="${v(b.percent)}"></td>
      <td><button type="button" class="btn btn-sm btn-gray" title="Remove">✕</button></td>`;
    tr.querySelector('button').onclick = () => { tr.remove(); feeUi(); };
    tr.querySelectorAll('input').forEach(i => i.addEventListener('input', feeUi));
    bracketBody.appendChild(tr);
  }
  function setBrackets(list) {
    bracketBody.innerHTML = '';
    (list && list.length ? list : [{ from: '0.01', to: '', fixed: '10.00', percent: '1.5' }]).forEach(bracketRow);
  }
  function getBrackets() {
    return [...bracketBody.querySelectorAll('tr')].map(tr => {
      const o = {}; tr.querySelectorAll('input[data-b]').forEach(i => o[i.dataset.b] = i.value.trim()); return o;
    }).filter(o => o.from !== '' || o.to !== '' || o.fixed !== '' || o.percent !== '');
  }
  function bracketFee(amount) {
    const b = getBrackets().find(x => amount >= parseFloat(x.from) && (x.to === '' || amount <= parseFloat(x.to)));
    return b ? (parseFloat(b.fixed) || 0) + amount * (parseFloat(b.percent) || 0) / 100 : 0;
  }
  document.getElementById('cgBracketAdd').onclick = () => {
    const last = getBrackets().slice(-1)[0];
    bracketRow({ from: last && last.to !== '' ? (parseFloat(last.to) + 0.01).toFixed(2) : '', to: '', fixed: '0.00', percent: '1.5' });
    feeUi();
  };
  document.getElementById('cgBracketSample').onclick = () => {
    setBrackets([
      { from: '0.01', to: '500.00', fixed: '10.00', percent: '0' },
      { from: '500.01', to: '5000.00', fixed: '10.00', percent: '1.5' },
      { from: '5000.01', to: '', fixed: '0.00', percent: '1.5' },
    ]);
    feeUi();
  };

  function feeUi() {
    const t = document.getElementById('cgFeeType').value;
    document.getElementById('cgFeeFixedWrap').style.display = (t === 'Fixed' || t === 'Fixed + Percentage') ? '' : 'none';
    document.getElementById('cgFeePctWrap').style.display = (t === 'Percentage' || t === 'Fixed + Percentage') ? '' : 'none';
    document.getElementById('cgBracketWrap').style.display = t === 'Bracket' ? '' : 'none';
    document.getElementById('cgFeeCapRow').style.display = t === 'None' ? 'none' : '';
    const fixed = parseFloat(document.getElementById('cgFeeFixed').value) || 0;
    const pct = parseFloat(document.getElementById('cgFeePct').value) || 0;
    const min = parseFloat(document.getElementById('cgFeeMin').value);
    const max = parseFloat(document.getElementById('cgFeeMax').value);
    const mode = (document.querySelector('input[name=cgFeeMode]:checked') || {}).value;
    const calc = ex => {
      let fee = t === 'Fixed' ? fixed : t === 'Percentage' ? ex * pct / 100 : t === 'Fixed + Percentage' ? fixed + ex * pct / 100 : t === 'Bracket' ? bracketFee(ex) : 0;
      if (t !== 'None' && !isNaN(min) && fee < min) fee = min;
      if (!isNaN(max) && max > 0 && fee > max) fee = max;
      fee = Math.round(Math.max(0, fee) * 100) / 100;
      if (mode !== 'separate' && fee > ex) fee = ex;
      return `a ${sbMoney(ex)} payment → fee ${sbMoney(fee)} · client Net Settlement <b>${sbMoney(mode === 'separate' ? ex : ex - fee)}</b>`;
    };
    const samples = t === 'Bracket' ? [100, 1000, 10000] : [1000];
    document.getElementById('cgFeePreview').innerHTML = t === 'None' ? 'No SurgeBox fee.'
      : 'Example: ' + samples.map(calc).join('<br>Example: ') + (mode === 'separate' ? ' (fee billed separately)' : '') + '.';
  }
  ['cgFeeType', 'cgFeeFixed', 'cgFeePct', 'cgFeeMin', 'cgFeeMax'].forEach(id => document.getElementById(id).addEventListener('input', feeUi));
  document.querySelectorAll('input[name=cgFeeMode]').forEach(r => r.addEventListener('change', feeUi));

  window.cgOpenEditor = function (code) {
    if (!CG || !CG.organization.is_approved) { flash('Approve this client first.', false); return; }
    const g = code ? CG.gateways.find(x => x.provider_code === code) : null;
    cgEditingCode = code || null;
    const sel = document.getElementById('cgProvider');
    const taken = CG.gateways.map(x => x.provider_code);
    sel.innerHTML = CG.providers.map(p => `<option value="${p.code}" ${(!code && taken.includes(p.code)) ? 'disabled' : ''}>${sbEscape(p.display_name)}${+p.is_priority ? ' (Priority)' : ''}${p.integration_status !== 'Live' ? ' — credentials only' : ''}${taken.includes(p.code) && !code ? ' — already added' : ''}</option>`).join('');
    if (code) { sel.value = code; sel.disabled = true; } else {
      sel.disabled = false;
      const first = CG.providers.find(p => !taken.includes(p.code));
      if (first) sel.value = first.code;
    }
    document.getElementById('cgModalTitle').textContent = g ? `Edit ${g.provider_name} Credentials` : 'Add Payment Provider';
    document.getElementById('cgEnv').value = g ? g.environment : 'test';
    document.getElementById('cgStatus').value = g ? g.status : 'Draft';
    document.getElementById('cgFeeType').value = g ? g.fee_type : 'Fixed';
    document.getElementById('cgFeeFixed').value = g ? g.fee_fixed : '10.00';
    document.getElementById('cgFeePct').value = g ? g.fee_percent : '1.5';
    let gb = []; try { gb = g && g.fee_brackets ? JSON.parse(g.fee_brackets) : []; } catch (e) { gb = []; }
    setBrackets(gb);
    document.getElementById('cgFeeMin').value = g && g.fee_min !== null ? g.fee_min : '';
    document.getElementById('cgFeeMax').value = g && g.fee_max !== null ? g.fee_max : '';
    document.querySelectorAll('input[name=cgFeeMode]').forEach(r => r.checked = r.value === (g ? g.fee_mode : 'deduct'));
    document.getElementById('cgDefault').checked = g ? !!+g.is_default : !CG.gateways.length;
    document.getElementById('cgCredit').checked = g ? !!+g.credit_wallet : false;
    document.getElementById('cgWebhookUrl').value = g ? g.webhook_url : '';
    document.getElementById('cgError').innerHTML = '';
    renderFields(sel.value, g);
    feeUi();
    sbOpenModal('cgModal');
  };
  document.getElementById('cgProvider').addEventListener('change', (e) => renderFields(e.target.value, null));
  // V5.15: open the editor for a new provider, preselected (used by the Nationlink QR Ph card).
  window.cgOpenEditorFor = function (code) {
    if (CG && CG.gateways.some(x => x.provider_code === code)) { cgOpenEditor(code); return; }
    cgOpenEditor();
    const sel = document.getElementById('cgProvider');
    if (!sel || !document.getElementById('cgModal').classList.contains('open')) return;
    sel.value = code; renderFields(code, null);
  };

  document.getElementById('cgSaveBtn').addEventListener('click', async () => {
    const btn = document.getElementById('cgSaveBtn');
    const fields = {};
    document.querySelectorAll('#cgFields input[data-key]').forEach(i => fields[i.dataset.key] = i.value.trim());
    const body = {
      action: 'save', organizationId: CG_ORG,
      provider_code: document.getElementById('cgProvider').value,
      environment: document.getElementById('cgEnv').value,
      status: document.getElementById('cgStatus').value,
      fee_type: document.getElementById('cgFeeType').value,
      fee_fixed: document.getElementById('cgFeeFixed').value,
      fee_percent: document.getElementById('cgFeePct').value,
      fee_brackets: getBrackets(),
      fee_min: document.getElementById('cgFeeMin').value,
      fee_max: document.getElementById('cgFeeMax').value,
      fee_mode: (document.querySelector('input[name=cgFeeMode]:checked') || {}).value || 'deduct',
      is_default: document.getElementById('cgDefault').checked,
      credit_wallet: document.getElementById('cgCredit').checked,
      apply_existing: document.getElementById('cgApplyExisting').checked,
      fields,
    };
    sbSetLoading(btn, 'Saving...');
    try {
      const r = await sbFetch(`${CG_BASE}/api/client_gateways.php`, { method: 'POST', body: JSON.stringify(body) });
      CG = r.data; renderApproval(); renderGateways();
      sbCloseModal('cgModal');
      if (window.nlqReload) window.nlqReload();
      flash(r.message, true);
    } catch (e) {
      document.getElementById('cgError').innerHTML = `<div class="alert alert-error">${sbEscape(e.message)}</div>`;
    } finally { sbClearLoading(btn); }
  });

  cgLoad();
})();
</script>
