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
    <p class="card-sub">Each client has its own fee per payment provider: Fixed, MDR % (e.g. 1.5%), Fixed + MDR, or Bracket by amount. Click <b>Edit Fee</b> to change one client only.</p>
  </div>
  <div style="display:flex;gap:10px;padding:12px 18px 0;flex-wrap:wrap"><input id="fsQ" class="form-control" placeholder="Search client..." style="max-width:300px"></div>
  <div style="overflow-x:auto"><table class="data-table" style="min-width:1100px">
    <thead><tr><th style="text-align:left">Client</th><th>Provider</th><th>Status</th><th style="text-align:left">Fee Setup</th><th>₱100</th><th>₱1,000</th><th>₱10,000</th><th>Collected</th><th></th></tr></thead>
    <tbody id="fsRows"><tr><td colspan="9" class="table-loading">Loading...</td></tr></tbody></table></div>
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
let fsData = [];
function fsRender() {
  const q = document.getElementById('fsQ').value.trim().toLowerCase();
  const rows = fsData.filter(g => !q || [g.organization_name, g.client_code, g.provider_name].some(v => String(v || '').toLowerCase().includes(q)));
  const st = s => s === 'Active' ? '<span class="pill pill-completed">Active</span>' : s === 'Disabled' ? '<span class="pill pill-disabled">Disabled</span>' : '<span class="pill pill-pending">Draft</span>';
  document.getElementById('fsRows').innerHTML = rows.length ? rows.map(g => `<tr>
    <td style="text-align:left"><a href="org-view.php?id=${g.organization_id}" style="color:var(--brand-blue);font-weight:600;text-decoration:none">${sbEscape(g.organization_name)}</a> ${g.client_code ? '<span class="form-hint">' + sbEscape(g.client_code) + '</span>' : ''}</td>
    <td>${sbEscape(g.provider_name)}</td><td>${st(g.status)}</td>
    <td style="text-align:left;font-size:12.5px;max-width:420px"><b>${sbEscape(g.fee_type === 'Fixed + Percentage' ? 'Fixed + MDR' : g.fee_type === 'Percentage' ? 'MDR %' : g.fee_type)}</b><br>${sbEscape(g.fee_label)}</td>
    ${g.examples.map(e => `<td>${sbMoney(e.fee)}</td>`).join('')}
    <td>${g.fee_mode === 'separate' ? 'Billed separately' : 'Deducted'}</td>
    <td style="white-space:nowrap"><a class="btn btn-sm btn-primary" href="org-view.php?id=${g.organization_id}&editFee=${encodeURIComponent(g.provider_code)}">Edit Fee</a></td>
  </tr>`).join('') : '<tr><td colspan="9" class="table-empty">No client payment providers yet. Add one from the client page (Payment Credentials).</td></tr>';
}
async function fsLoad() {
  try { fsData = (await sbFetch(`${BASE}/api/client_fees.php?setup=1`)).data || []; fsRender(); }
  catch (e) { document.getElementById('fsRows').innerHTML = `<tr><td colspan="9"><div class="alert alert-error">${sbEscape(e.message)}</div></td></tr>`; }
}
document.getElementById('fsQ').addEventListener('input', sbDebounce(fsRender, 200));
fsLoad();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
