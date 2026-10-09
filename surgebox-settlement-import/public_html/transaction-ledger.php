<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
$user = require_role(['Admin','Manager']);
$pageTitle = 'Transaction Ledger - SurgeBox';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header"><div><h1 class="page-title">Transaction Ledger</h1><p class="form-hint">QR Ph collections, cash-out and settlement-related wallet movements with balance before/after.</p></div></div>
<div class="filters-bar" style="margin-bottom:14px;background:#fff;border:1px solid var(--gray-200);border-radius:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
 <input id="q" class="form-control" placeholder="Search reference, sender, branch..." style="max-width:340px"><button class="btn btn-primary" id="refreshBtn">Refresh</button>
</div>
<div class="table-wrap"><div style="overflow-x:auto"><table class="data-table" style="min-width:1250px"><thead><tr><th>Date</th><th>Organization / Branch</th><th>Type</th><th>Provider</th><th>Reference</th><th>Trace</th><th>Sender</th><?php if (sb_fees_visible($user)): ?><th>Amount</th><th>Fee</th><?php else: ?><th>Amount</th><?php endif; ?><th>Balance Before</th><th>Balance After</th><th>Status</th></tr></thead><tbody id="txRows"></tbody></table></div><div id="txPager"></div></div>
<script>
const BASE=<?= json_encode(base_path()) ?>; const SHOW_FEES=<?= sb_fees_visible($user) ? 'true' : 'false' ?>; const NC=SHOW_FEES?12:11; let page=1, cache=[];
function render(){const q=document.getElementById('q').value.trim().toLowerCase(); const d=cache.filter(t=>!q||[t.reference_no,t.sender_name,t.branch_name,t.organization_name,t.qr_ph_trace_no,t.type,t.provider_name].some(v=>String(v||'').toLowerCase().includes(q))); document.getElementById('txRows').innerHTML=d.length?d.map(t=>`<tr><td>${sbEscape(t.transaction_date||t.created_at||'')}</td><td>${sbEscape(t.organization_name||'-')}${t.branch_name?'<br><small>'+sbEscape(t.branch_name)+'</small>':''}</td><td>${sbEscape(t.type||'')}</td><td>${sbProviderPill(t.provider_code,t.provider_name)}</td><td>${sbEscape(t.reference_no||'')}</td><td>${sbEscape(t.qr_ph_trace_no||'-')}</td><td>${sbEscape(t.sender_name||'-')}${t.sender_account?'<br><small class="form-hint">'+sbEscape(t.sender_account)+'</small>':''}</td>${SHOW_FEES?sbAdminAmountCell(t)+`<td>${sbMoney(t.display_fee??t.fee??0)}</td>`:`<td>${sbMoney(t.net_amount||0)}</td>`}<td>${sbMoney(t.balance_before||0)}</td><td>${sbMoney(t.balance_after||0)}${t.balance_kind==='collections'?'<br><small class="form-hint" title="Settled by the provider directly to the client; not part of the SurgeBox wallet balance">'+sbEscape(t.provider_name||'')+' running total</small>':''}</td><td><span class="status-badge status-${String(t.status||'').toLowerCase()}">${sbEscape(t.status||'')}</span></td></tr>`).join(''):`<tr><td colspan="${NC}" class="table-empty">No transactions found.</td></tr>`}
async function load(){document.getElementById('txRows').innerHTML=`<tr><td colspan="${NC}" class="table-loading">Loading...</td></tr>`; try{const r=await sbFetch(`${BASE}/api/transactions.php?pageNumber=${page}&pageSize=25`);cache=r.data||[];render();sbRenderPagination(document.getElementById('txPager'),page,r.totalPages||1,p=>{page=p;load()})}catch(e){document.getElementById('txRows').innerHTML=`<tr><td colspan="${NC}"><div class="alert alert-error">${sbEscape(e.message)}</div></td></tr>`}}
document.getElementById('q').addEventListener('input',sbDebounce(render,250)); document.getElementById('refreshBtn').onclick=load; load();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
