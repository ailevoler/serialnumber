<?php
require_once __DIR__ . '/../../includes/core.php';
Permissions::requireAccess('terminals');
$PAGE_TITLE = 'Terminals & Dispatch'; $ACTIVE_KEY = 'terminals';

$ready = DB::tableExists('tl_terminals');
$terminals = $dispatchers = [];
if ($ready) {
    $terminals = DB::all(
        "SELECT t.*,
                (SELECT COUNT(*) FROM tl_terminal_queue q WHERE q.terminal_id=t.id AND q.status='waiting') AS queue_count,
                (SELECT COUNT(*) FROM tl_bookings b WHERE b.terminal_id=t.id AND b.booking_mode='terminal' AND b.status='searching') AS waiting_passengers,
                (SELECT COUNT(*) FROM tl_bookings b WHERE b.terminal_id=t.id AND DATE(b.dispatched_at)=CURDATE()) AS dispatched_today,
                (SELECT COUNT(*) FROM tl_dispatchers d WHERE d.terminal_id=t.id AND d.status='active') AS dispatcher_count
         FROM tl_terminals t ORDER BY t.is_active DESC, t.name"
    );
    $dispatchers = DB::all(
        "SELECT d.id, d.dispatcher_code, d.full_name, d.phone, d.terminal_id, d.status, d.last_login, t.name AS terminal_name
         FROM tl_dispatchers d JOIN tl_terminals t ON t.id=d.terminal_id ORDER BY d.full_name"
    );
}
$dispatchUrl = 'https://app.trilink.site/trilink-dispatch/';

include __DIR__ . '/../../includes/layout_head.php';
?>
<?= csrfField() ?>
<div class="page-head">
  <div><h1>Terminals &amp; Dispatch</h1><p>Tricycle terminals, their pila, and the dispatchers who assign terminal rides · Portal: <a href="<?= $dispatchUrl ?>" target="_blank" rel="noopener"><?= $dispatchUrl ?></a></p></div>
  <?php if ($ready): ?>
  <div class="page-actions">
    <button class="btn" data-modal-open="dispatcherModal" onclick="newDispatcher()" <?= $terminals ? '' : 'disabled title="Create a terminal first"' ?>><i class="fa-solid fa-user-plus"></i> New Dispatcher</button>
    <button class="btn btn-primary" data-modal-open="terminalModal" onclick="newTerminal()"><i class="fa-solid fa-plus"></i> New Terminal</button>
  </div>
  <?php endif; ?>
</div>

<?php if (!$ready): ?>
<div class="card card-pad"><div class="empty-state"><i class="fa-solid fa-database"></i><p>Run <code>app/trilink-dispatch/migration_dispatch.sql</code> first.</p></div></div>
<?php else: ?>
<div class="card" style="margin-bottom:18px">
  <div class="card-pad" style="padding-bottom:0"><h3 style="margin:0">Terminals</h3></div>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Terminal</th><th>Vehicle</th><th>Pila</th><th>Waiting Passengers</th><th>Dispatched Today</th><th>Dispatchers</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$terminals): ?><tr><td colspan="8"><div class="empty-state"><i class="fa-solid fa-warehouse"></i><p>No terminals yet. Create one to enable "Terminal" rides.</p></div></td></tr><?php endif; ?>
    <?php foreach ($terminals as $t): ?>
      <tr>
        <td><div class="cell-strong"><?= clean($t['name']) ?></div><div class="cell-muted"><?= clean($t['terminal_code']) ?> · <?= clean($t['address']) ?></div></td>
        <td><?= ucwords(str_replace('_', '-', $t['vehicle_type'])) ?></td>
        <td class="cell-strong"><?= (int)$t['queue_count'] ?></td>
        <td><?= (int)$t['waiting_passengers'] ?></td>
        <td><?= (int)$t['dispatched_today'] ?></td>
        <td><?= (int)$t['dispatcher_count'] ?></td>
        <td><?= badge($t['is_active'] ? 'active' : 'inactive') ?></td>
        <td><div class="row-actions">
          <button class="btn btn-icon btn-sm" title="Edit" data-modal-open="terminalModal" onclick='editTerminal(<?= json_encode($t, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fa-solid fa-pen"></i></button>
          <button class="btn btn-icon btn-sm" title="<?= $t['is_active'] ? 'Deactivate' : 'Activate' ?>" onclick="toggleTerminal(<?= (int)$t['id'] ?>,<?= $t['is_active'] ? 0 : 1 ?>)"><i class="fa-solid fa-power-off"></i></button>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="card">
  <div class="card-pad" style="padding-bottom:0"><h3 style="margin:0">Dispatchers</h3></div>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Dispatcher</th><th>Mobile (login)</th><th>Terminal</th><th>Last Login</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$dispatchers): ?><tr><td colspan="6"><div class="empty-state"><i class="fa-solid fa-user-tie"></i><p>No dispatchers yet.</p></div></td></tr><?php endif; ?>
    <?php foreach ($dispatchers as $d): ?>
      <tr>
        <td><div class="cell-strong"><?= clean($d['full_name']) ?></div><div class="cell-muted"><?= clean($d['dispatcher_code']) ?></div></td>
        <td><?= clean($d['phone']) ?></td>
        <td><?= clean($d['terminal_name']) ?></td>
        <td class="cell-muted"><?= $d['last_login'] ? date('M j, g:i A', strtotime($d['last_login'])) : '—' ?></td>
        <td><?= badge($d['status']) ?></td>
        <td><div class="row-actions">
          <button class="btn btn-icon btn-sm" title="Edit / reset PIN" data-modal-open="dispatcherModal" onclick='editDispatcher(<?= json_encode($d, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fa-solid fa-pen"></i></button>
          <button class="btn btn-icon btn-sm" title="<?= $d['status'] === 'active' ? 'Suspend' : 'Activate' ?>" onclick="toggleDispatcher(<?= (int)$d['id'] ?>,'<?= $d['status'] === 'active' ? 'suspended' : 'active' ?>')"><i class="fa-solid fa-power-off"></i></button>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="modal-backdrop" id="terminalModal"><div class="modal modal-lg">
  <form id="terminalForm" onsubmit="saveForm(event)">
    <div class="modal-head"><h3 id="terminalTitle">New Terminal</h3><button type="button" class="modal-close" data-modal-close><i class="fa-solid fa-xmark"></i></button></div>
    <div class="modal-body"><input type="hidden" name="action" value="save_terminal"><input type="hidden" name="id" id="tId">
      <div class="form-grid">
        <div class="form-group"><label>Terminal Name *</label><input name="name" id="tName" required maxlength="120" placeholder="e.g. Katipunan TODA Terminal"></div>
        <div class="form-group"><label>Vehicle Type *</label><select name="vehicle_type" id="tVehicle"><option value="tricycle">Tricycle</option><option value="e_trike">E-Trike</option><option value="motorcycle">Motorcycle</option></select></div>
        <div class="form-group full"><label>Address *</label><input name="address" id="tAddress" required maxlength="255"></div>
        <div class="form-group"><label>Latitude *</label><input name="lat" id="tLat" required inputmode="decimal" placeholder="14.6507"></div>
        <div class="form-group"><label>Longitude *</label><input name="lng" id="tLng" required inputmode="decimal" placeholder="121.0735"></div>
      </div>
      <span class="hint">Drivers can only join the pila from their app when they are near these coordinates (setting <code>terminal_join_radius_m</code>, default 500 m). Dispatchers can add drivers from anywhere.</span>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>Cancel</button><button class="btn btn-primary">Save Terminal</button></div>
  </form>
</div></div>

<div class="modal-backdrop" id="dispatcherModal"><div class="modal">
  <form id="dispatcherForm" onsubmit="saveForm(event)">
    <div class="modal-head"><h3 id="dispatcherTitle">New Dispatcher</h3><button type="button" class="modal-close" data-modal-close><i class="fa-solid fa-xmark"></i></button></div>
    <div class="modal-body"><input type="hidden" name="action" value="save_dispatcher"><input type="hidden" name="id" id="dId">
      <div class="form-group"><label>Full Name *</label><input name="full_name" id="dName" required maxlength="120"></div>
      <div class="form-group"><label>Mobile Number * <span class="hint">(used to log in)</span></label><input type="tel" name="phone" id="dPhone" required maxlength="13" placeholder="09XXXXXXXXX"></div>
      <div class="form-group"><label>Terminal *</label><select name="terminal_id" id="dTerminal" required><?php foreach ($terminals as $t): ?><option value="<?= (int)$t['id'] ?>"><?= clean($t['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>6-digit PIN <span class="hint" id="dPinHint">*</span></label><input type="password" name="pin" id="dPin" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="new-password"><span class="hint">Leave blank when editing to keep the current PIN.</span></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>Cancel</button><button class="btn btn-primary">Save Dispatcher</button></div>
  </form>
</div></div>

<script>
function el(id){return document.getElementById(id);}
function newTerminal(){el('terminalForm').reset();el('tId').value='';el('terminalTitle').textContent='New Terminal';}
function editTerminal(t){el('terminalForm').reset();el('tId').value=t.id;el('tName').value=t.name;el('tAddress').value=t.address;el('tLat').value=t.lat||'';el('tLng').value=t.lng||'';el('tVehicle').value=t.vehicle_type;el('terminalTitle').textContent='Edit Terminal';}
function newDispatcher(){el('dispatcherForm').reset();el('dId').value='';el('dPin').required=true;el('dPinHint').textContent='*';el('dispatcherTitle').textContent='New Dispatcher';}
function editDispatcher(d){el('dispatcherForm').reset();el('dId').value=d.id;el('dName').value=d.full_name;el('dPhone').value=d.phone;el('dTerminal').value=d.terminal_id;el('dPin').required=false;el('dPinHint').textContent='(optional)';el('dispatcherTitle').textContent='Edit Dispatcher';}
async function saveForm(e){e.preventDefault();const r=await ajaxPost('api.php',Object.fromEntries(new FormData(e.target)));showToast(r.message,r.success?'success':'error');if(r.success)setTimeout(()=>location.reload(),500);}
async function toggleTerminal(id,is_active){if(!confirm(is_active?'Activate this terminal?':'Deactivate this terminal? Its pila will be cleared and waiting passengers will be offered to nearby drivers.'))return;const r=await ajaxPost('api.php',{action:'toggle_terminal',id,is_active});showToast(r.message,r.success?'success':'error');if(r.success)setTimeout(()=>location.reload(),500);}
async function toggleDispatcher(id,status){if(!confirm('Change this dispatcher\'s status?'))return;const r=await ajaxPost('api.php',{action:'toggle_dispatcher',id,status});showToast(r.message,r.success?'success':'error');if(r.success)setTimeout(()=>location.reload(),500);}
</script>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/layout_foot.php'; ?>
