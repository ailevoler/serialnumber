<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
$user = require_role(['Admin']);
$pageTitle = 'Client Fees - SurgeBox';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Client Fees (MDR)</h1>
    <p class="page-sub">SurgeBox fees per client from gateway collections. <b>Deducted</b> fees are already netted from the client's settlement; <b>Unbilled</b> fees belong to clients who keep the full amount and are billed separately.</p>
  </div>
</div>
<div class="table-wrap" style="margin-bottom:18px">
  <div class="card-header" style="padding:16px 18px 0;display:block">
    <h3 class="card-title">Fee Setup per Client</h3>
    <p class="card-sub">Every client has its own setup, and <b>Nationlink and PayMongo are set separately</b> per client: Fixed, MDR % (e.g. 1.5%), Fixed + MDR, or Bracket by amount. <b>Edit</b> changes only that client and that provider. Example fees are for ₱100 / ₱1,000 / ₱10,000.</p>
  </div>
  <div style="display:flex;gap:10px;padding:12px 18px 0;flex-wrap:wrap"><input id="fsQ" class="form-control" placeholder="Search client..." style="max-width:300px"></div>
  <div style="overflow-x:auto"><table class="data-table" style="min-width:1000px">
    <thead id="fsHead"><tr><th style="text-align:left">Client</th></tr></thead>
    <tbody id="fsRows"><tr><td class="table-loading">Loading...</td></tr></tbody></table></div>
</div>

<div class="filters-bar sp-filters">
  <label class="form-hint">From <input type="date" id="cfFrom" class="form-control" value="<?= h(date('Y-m-01')) ?>"></label>
  <label class="form-hint">To <input type="date" id="cfTo" class="form-control" value="<?= h(date('Y-m-d')) ?>"></label>
  <button class="btn btn-primary" id="cfLoad">Apply</button>
</div>
<div id="cfMsg"></div>
<div class="table-wrap">
  <div style="overflow-x:auto"><table class="data-table" style="min-width:1100px">
    <thead><tr><th style="text-align:left">Client</th><th>Tx</th><th>Gross</th><th>Deducted</th><th>Unbilled</th><th>Billed</th><th>Paid</th><th>Waived</th><th>Actions</th></tr></thead>
    <tbody id="cfRows"><tr><td colspan="9" class="table-loading">Loading...</td></tr></tbody></table></div>
</div>
<script>
const BASE = <?= json_encode(base_path()) ?>;
async function cfLoad() {
  const from = document.getElementById('cfFrom').value, to = document.getElementById('cfTo').value;
  try {
    const r = await sbFetch(`${BASE}/api/client_fees.php?from=${from}&to=${to}`);
    const rows = r.data.clients;
    document.getElementById('cfRows').innerHTML = rows.length ? rows.map(c => `<tr>
      <td style="text-align:left"><a href="org-view.php?id=${c.organization_id}" style="color:var(--brand-blue);font-weight:600;text-decoration:none">${sbEscape(c.organization_name)}</a> ${c.client_code ? '<span class="form-hint">' + sbEscape(c.client_code) + '</span>' : ''}</td>
      <td>${c.tx_count}</td><td>${sbMoney(c.gross)}</td><td>${sbMoney(c.fee_deducted)}</td>
      <td><b>${sbMoney(c.fee_unbilled)}</b></td><td>${sbMoney(c.fee_billed)}</td><td>${sbMoney(c.fee_paid)}</td><td>${sbMoney(c.fee_waived)}</td>
      <td style="white-space:nowrap">
        ${+c.fee_unbilled > 0 ? `<button class="btn btn-sm btn-primary" onclick="cfMark(${c.organization_id}, 'Billed')">Mark Billed</button> <button class="btn btn-sm btn-outline" onclick="cfMark(${c.organization_id}, 'Waived')">Waive</button>` : ''}
        ${(+c.fee_billed > 0 || +c.fee_unbilled > 0) ? `<button class="btn btn-sm btn-gray" onclick="cfMark(${c.organization_id}, 'Paid')">Mark Paid</button>` : ''}
        <a class="btn btn-sm btn-gray" href="client-settlement.php?id=${c.organization_id}">Settlement</a>
      </td></tr>`).join('') : '<tr><td colspan="9" class="table-empty">No gateway collections in this period.</td></tr>';
  } catch (e) { document.getElementById('cfRows').innerHTML = `<tr><td colspan="9"><div class="alert alert-error">${sbEscape(e.message)}</div></td></tr>`; }
}
async function cfMark(orgId, status) {
  const from = document.getElementById('cfFrom').value, to = document.getElementById('cfTo').value;
  if (!confirm(`Mark this client's fees from ${from} to ${to} as ${status}?`)) return;
  try {
    const r = await sbFetch(`${BASE}/api/client_fees.php`, { method: 'POST', body: JSON.stringify({ organizationId: orgId, from, to, status }) });
    document.getElementById('cfMsg').innerHTML = `<div class="alert alert-success">${sbEscape(r.message)}</div>`;
    cfLoad();
  } catch (e) { document.getElementById('cfMsg').innerHTML = `<div class="alert alert-error">${sbEscape(e.message)}</div>`; }
}
document.getElementById('cfLoad').addEventListener('click', cfLoad);
cfLoad();

// V5.26: fee setup per client
let fsData = { providers: [], clients: [] };
function fsCell(c, p) {
  const g = c.gateways[p.code];
  const link = `org-view.php?id=${c.organization_id}&editFee=${encodeURIComponent(p.code)}`;
  if (!g) {
    return `<td style="text-align:left;vertical-align:top"><span class="form-hint">Not set up</span><br>
      ${c.is_approved ? `<a class="btn btn-sm btn-outline" style="margin-top:6px" href="${link}">+ Set up ${sbEscape(p.name)}</a>` : '<small class="form-hint">Approve the client first</small>'}</td>`;
  }
  const st = g.status === 'Active' ? '<span class="pill pill-completed">Active</span>' : g.status === 'Disabled' ? '<span class="pill pill-disabled">Disabled</span>' : '<span class="pill pill-pending">Draft</span>';
  const type = g.fee_type === 'Fixed + Percentage' ? 'Fixed + MDR' : g.fee_type === 'Percentage' ? 'MDR %' : g.fee_type;
  return `<td style="text-align:left;vertical-align:top;font-size:12.5px;min-width:280px">
    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">${st} <b>${sbEscape(type)}</b> · ${g.fee_mode === 'separate' ? 'billed separately' : 'deducted'}</div>
    <div style="margin:4px 0">${sbEscape(g.fee_label.split(' · ')[0])}</div>
    <div class="form-hint">${g.examples.map(e => sbMoney(e.fee)).join(' / ')}</div>
    <a class="btn btn-sm btn-primary" style="margin-top:6px" href="${link}">Edit ${sbEscape(p.name)} fee</a></td>`;
}
function fsRender() {
  const q = document.getElementById('fsQ').value.trim().toLowerCase();
  const P = fsData.providers;
  document.getElementById('fsHead').innerHTML = `<tr><th style="text-align:left">Client</th>${P.map(p => `<th style="text-align:left">${sbEscape(p.name)}</th>`).join('')}</tr>`;
  const rows = fsData.clients.filter(c => !q || [c.organization_name, c.client_code].some(v => String(v || '').toLowerCase().includes(q)));
  document.getElementById('fsRows').innerHTML = rows.length ? rows.map(c => `<tr>
    <td style="text-align:left;vertical-align:top"><a href="org-view.php?id=${c.organization_id}" style="color:var(--brand-blue);font-weight:600;text-decoration:none">${sbEscape(c.organization_name)}</a> ${c.client_code ? '<span class="form-hint">' + sbEscape(c.client_code) + '</span>' : ''}</td>
    ${P.map(p => fsCell(c, p)).join('')}</tr>`).join('') : `<tr><td colspan="${P.length + 1}" class="table-empty">No clients found.</td></tr>`;
}
async function fsLoad() {
  try { fsData = (await sbFetch(`${BASE}/api/client_fees.php?setup=1`)).data; fsRender(); }
  catch (e) { document.getElementById('fsRows').innerHTML = `<tr><td><div class="alert alert-error">${sbEscape(e.message)}</div></td></tr>`; }
}
document.getElementById('fsQ').addEventListener('input', sbDebounce(fsRender, 200));
fsLoad();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
