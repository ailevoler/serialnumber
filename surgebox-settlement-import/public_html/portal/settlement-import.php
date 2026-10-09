<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/settlement-import.php';
$user = sb_stl_require_admin(false); // Admin only - never the client portal
$pageTitle = 'Settlement Import - SurgeBox';

// Clients that have a Nationlink gateway (the report is booked on that gateway).
$clients = db()->query("SELECT o.id, o.organization_name, o.client_code FROM sb_organization o JOIN sb_client_gateways g ON g.organization_id = o.id AND g.provider_code = 'nationlink' ORDER BY o.organization_name")->fetchAll();
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Settlement Import</h1>
    <p class="form-hint">Upload Nationlink's daily <b>QR Transactions Report (DTQR)</b> — PDF, Excel (.xlsx) or CSV. Each line is matched to the client's Nationlink QR Ph by MemberID (e.g. A10103) and TRACE NO.; payments the webhook missed are recorded on the client's dashboard, ledger and settlement. Lines already recorded are never added twice.</p>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <form id="importForm" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <div class="form-group" style="margin:0;min-width:260px;flex:1">
      <label class="form-label" for="stlFile">Settlement report file</label>
      <input type="file" class="form-control" id="stlFile" name="file" accept=".pdf,.xlsx,.xls,.csv,.txt,application/pdf,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
    </div>
    <div class="form-group" style="margin:0;min-width:240px">
      <label class="form-label" for="stlOrg">Client</label>
      <select class="form-control" id="stlOrg" name="organizationId">
        <option value="">Auto-detect from MemberID</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?= (int) $c['id'] ?>"><?= h($c['organization_name']) ?><?= $c['client_code'] ? ' (' . h($c['client_code']) . ')' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary" id="previewBtn">Upload &amp; Preview</button>
  </form>
  <p class="form-hint" style="margin:10px 0 0">Nothing is saved until you click <b>Import</b> on the preview.</p>
</div>

<div id="previewBox"></div>

<div class="table-wrap" style="margin-top:18px">
  <div class="card-header" style="padding:16px 18px 0"><h3 class="card-title">Import history</h3></div>
  <div style="overflow-x:auto"><table class="data-table" style="min-width:900px"><thead><tr><th>Imported</th><th>Report date</th><th>File</th><th>Client</th><th>Lines</th><th>New</th><th>Already recorded</th><th>Mismatch</th><th>Unmatched</th><th>Tran Amount</th><th>Net Settlement</th><th>By</th></tr></thead><tbody id="historyRows"></tbody></table></div>
</div>

<script>
const BASE = <?= json_encode(base_path()) ?>;
const CSRF = <?= json_encode(csrf_token()) ?>;
let pendingToken = null;

const STATUS_STYLE = {
  New: 'background:#dbeafe;color:#1d4ed8', Imported: 'background:var(--green-bg);color:var(--green)',
  Matched: 'background:#f1f5f9;color:#334155', Mismatch: 'background:var(--amber-bg);color:var(--amber)',
  Unmatched: 'background:#fee2e2;color:#b91c1c', Duplicate: 'background:#f1f5f9;color:#64748b', Error: 'background:#fee2e2;color:#b91c1c'
};
const STATUS_LABEL = { New: 'New', Imported: 'Imported', Matched: 'Already recorded', Mismatch: 'Amount mismatch', Unmatched: 'Unmatched', Duplicate: 'Duplicate', Error: 'Error' };
const pill = s => `<span class="pill" style="white-space:nowrap;${STATUS_STYLE[s] || ''}">${sbEscape(STATUS_LABEL[s] || s)}</span>`;

function renderResult(res, committed) {
  const d = res.data, m = d.meta, s = d.summary, by = s.by_status || {};
  const box = document.getElementById('previewBox');
  const newCount = by.New || 0;
  let html = `<div class="stat-cards-grid">
    <div class="stat-card-v2"><p class="lbl">Report</p><p class="val" style="font-size:18px">${sbEscape(m.report_type || 'Nationlink')} · ${sbEscape(m.report_date || '-')}</p><p class="form-hint" style="margin:4px 0 0">${sbEscape(res.file.name)} (${sbEscape(res.file.format.toUpperCase())})</p></div>
    <div class="stat-card-v2"><p class="lbl">Tran Amount (${s.rows} lines)</p><p class="val">${sbMoney(s.amount)}</p></div>
    <div class="stat-card-v2"><p class="lbl">Discount</p><p class="val">${sbMoney(s.discount)}</p></div>
    <div class="stat-card-v2"><p class="lbl">Net Settlement</p><p class="val">${sbMoney(s.net)}</p></div>
  </div>`;
  if (m.main_org || Object.keys(m.branches || {}).length) {
    html += `<p class="form-hint">Main Org ${sbEscape(m.main_org || '-')} ${sbEscape(m.main_org_name || '')} · Branch ${Object.entries(m.branches || {}).map(([k, v]) => sbEscape(k + ' ' + v)).join(', ') || '-'}${Object.keys(s.organizations || {}).length ? ' · Client: <b>' + Object.values(s.organizations).map(sbEscape).join(', ') + '</b>' : ''}</p>`;
  }
  (d.checks || []).forEach(c => {
    html += `<div class="alert ${c.ok ? 'alert-success' : 'alert-error'}" style="margin:6px 0;padding:8px 12px">${c.ok ? '✓' : '✗'} ${sbEscape(c.kind)} ${sbEscape(c.code || '')}: report ${sbMoney(c.amount)} / net ${sbMoney(c.net)} — read ${sbMoney(c.parsed_amount)} / net ${sbMoney(c.parsed_net)}</div>`;
  });
  (d.warnings || []).forEach(w => { html += `<div class="alert" style="background:#fff7ed;color:#9a3412;border:1px solid #fdba74;margin:6px 0">${sbEscape(w)}</div>`; });

  html += `<p style="margin:12px 0">${Object.entries(by).map(([k, v]) => pill(k) + ' ' + v).join(' &nbsp; ')}</p>`;
  html += '<div class="table-wrap"><div style="overflow-x:auto"><table class="data-table" style="min-width:1200px"><thead><tr><th>Status</th><th>MemberID</th><th>Time Stamp</th><th>Trace No.</th><th>Seq</th><th>Source Account</th><th>Tran Amount</th><th>Discount</th><th>Net Settlement</th><th>Client / QR</th><th>Note</th></tr></thead><tbody>';
  d.lines.forEach(l => {
    html += `<tr>
      <td>${pill(l.status)}</td>
      <td>${sbEscape(l.member || '-')}<br><small class="form-hint">${sbEscape(l.member_name || '')}</small></td>
      <td>${sbEscape(l.paid_at || l.time || '')}</td>
      <td style="font-family:monospace;font-size:12px">${sbEscape(l.trace)}</td>
      <td>${sbEscape(l.seq || '')}</td>
      <td>${sbEscape(l.payer || '')}</td>
      <td>${sbMoney(l.amount)}</td>
      <td>${l.discount == null ? '-' : sbMoney(l.discount)}</td>
      <td>${l.net == null ? '-' : sbMoney(l.net)}</td>
      <td>${sbEscape(l.organization_name || '-')}${l.qr_label ? '<br><small class="form-hint">' + sbEscape(l.qr_label) + '</small>' : ''}</td>
      <td><small>${sbEscape(l.message || '')}</small></td>
    </tr>`;
  });
  html += '</tbody></table></div></div>';

  if (!committed) {
    html += `<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:14px">
      <button class="btn btn-outline" id="cancelBtn" type="button">Cancel</button>
      <button class="btn btn-primary" id="commitBtn" type="button" ${newCount ? '' : 'disabled'}>${newCount ? `Import ${newCount} new transaction${newCount > 1 ? 's' : ''}` : 'Nothing new to import'}</button>
    </div>`;
  } else {
    html = `<div class="alert alert-success">Import finished: ${by.Imported || 0} recorded, ${by.Matched || 0} already recorded${by.Mismatch ? ', ' + by.Mismatch + ' amount mismatch' : ''}${by.Unmatched ? ', ' + by.Unmatched + ' unmatched' : ''}${by.Error ? ', ' + by.Error + ' error' : ''}.</div>` + html;
  }
  box.innerHTML = html;

  if (!committed) {
    document.getElementById('cancelBtn').onclick = () => { pendingToken = null; box.innerHTML = ''; };
    const btn = document.getElementById('commitBtn');
    btn.onclick = async () => {
      if (!confirm(`Record ${newCount} Nationlink payment(s) on the client ledger?`)) return;
      sbSetLoading(btn, 'Importing...');
      try {
        const r = await sbFetch(`${BASE}/api/settlement_import.php`, { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: JSON.stringify({ action: 'commit', token: pendingToken }) });
        pendingToken = null;
        renderResult(r, true);
        loadHistory();
      } catch (e) {
        sbClearLoading(btn);
        alert(e.message);
      }
    };
  }
}

document.getElementById('importForm').addEventListener('submit', async ev => {
  ev.preventDefault();
  const btn = document.getElementById('previewBtn');
  const box = document.getElementById('previewBox');
  const fd = new FormData(ev.target);
  fd.append('action', 'preview');
  sbSetLoading(btn, 'Reading...');
  box.innerHTML = '<div class="card"><p class="table-loading">Reading the report...</p></div>';
  try {
    const r = await sbFetch(`${BASE}/api/settlement_import.php`, { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: fd });
    pendingToken = r.token;
    renderResult(r, false);
  } catch (e) {
    box.innerHTML = `<div class="alert alert-error">${sbEscape(e.message)}</div>`;
  } finally {
    sbClearLoading(btn);
  }
});

async function loadHistory() {
  const tb = document.getElementById('historyRows');
  try {
    const r = await sbFetch(`${BASE}/api/settlement_import.php`);
    tb.innerHTML = (r.data || []).length ? r.data.map(i => `<tr>
      <td>${sbEscape(i.created_at)}</td><td>${sbEscape(i.report_date || '-')}</td>
      <td>${sbEscape(i.file_name)}<br><small class="form-hint">${sbEscape(String(i.file_format).toUpperCase())}</small></td>
      <td>${sbEscape(i.organization_name || 'Auto-detect')}</td><td>${i.rows_total}</td><td>${i.rows_imported}</td><td>${i.rows_matched}</td>
      <td>${i.rows_mismatch}</td><td>${i.rows_unmatched}</td><td>${sbMoney(i.total_amount)}</td><td>${sbMoney(i.total_net)}</td>
      <td>${sbEscape(i.imported_by_name || '')}</td></tr>`).join('')
      : `<tr><td colspan="12" class="table-empty">${sbEscape(r.notice || 'No imports yet.')}</td></tr>`;
  } catch (e) {
    tb.innerHTML = `<tr><td colspan="12"><div class="alert alert-error">${sbEscape(e.message)}</div></td></tr>`;
  }
}
loadHistory();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
