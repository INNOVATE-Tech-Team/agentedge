<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/roles.php';
$agent = require_login();
if (!is_leader()) { header('Location: index.php'); exit; }
$superAdmin = is_super_admin();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>State Annual Reports — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .btn-primary{padding:8px 16px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer}
    .btn-primary:hover{background:#5b8e0d;color:#fff}
    .btn-sm{padding:4px 10px;font-size:11px;font-weight:700;border-radius:4px;border:1px solid #ddd;background:white;cursor:pointer;color:#333}
    .btn-sm:hover{border-color:#82C112;color:#5b8e0d}
    .btn-danger{background:#fee2e2;color:#c00;border-color:#f5c6c6}
    .btn-danger:hover{background:#fca5a5;border-color:#f87171}
    .btn-ghost{background:white;border:1px solid #ccc;color:#555;padding:8px 14px;border-radius:6px;cursor:pointer;font-size:13px}
    .btn-ghost:hover{border-color:#82C112;color:#5b8e0d}

    /* Summary tiles */
    .mls-tiles{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}
    .mls-tile{flex:1;min-width:120px;border:1px solid #eee;border-radius:8px;padding:14px 16px;background:white}
    .mls-tile-val{font-size:26px;font-weight:800;color:#111;line-height:1}
    .mls-tile-lbl{font-size:11px;color:#888;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-top:4px}
    .mls-tile.blue{border-color:#bfdbfe;background:#eff6ff}
    .mls-tile.blue .mls-tile-val{color:#1d4ed8}
    .mls-tile.amber{border-color:#fde68a;background:#fffbeb}
    .mls-tile.amber .mls-tile-val{color:#b45309}

    /* Table */
    .mls-table{width:100%;border-collapse:collapse;font-size:13px}
    .mls-table th{text-align:left;padding:8px 12px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;border-bottom:2px solid #f0f0f0}
    .mls-table td{padding:10px 12px;border-bottom:1px solid #f5f5f5;vertical-align:middle}
    .mls-table tr:hover td{background:#fafafa;cursor:pointer}

    /* Status badges */
    .badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap}
    .badge-exp-ok{background:#d1fae5;color:#065f46}
    .badge-exp-soon{background:#fff7ed;color:#9a3412}
    .badge-exp-over{background:#fee2e2;color:#991b1b}
    .badge-exp-none{background:#f3f4f6;color:#9ca3af}

    /* Modal */
    .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:300;align-items:center;justify-content:center;padding:16px}
    .modal-overlay.open{display:flex}
    .modal{background:white;border-radius:12px;width:680px;max-width:98vw;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 12px 48px rgba(0,0,0,.2)}
    .modal-head{padding:20px 24px 0;display:flex;align-items:center;gap:12px}
    .modal-head h3{margin:0;font-size:16px;font-weight:800;flex:1}
    .modal-body{padding:20px 24px;overflow-y:auto;flex:1}
    .modal-foot{padding:16px 24px;border-top:1px solid #f0f0f0;display:flex;gap:8px;justify-content:flex-end}
    .modal-close{background:none;border:none;cursor:pointer;font-size:20px;color:#888;line-height:1;padding:0}
    .modal-close:hover{color:#333}

    /* Form sections */
    .form-section{margin-bottom:20px}
    .form-section-title{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#888;margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid #f0f0f0}
    .field-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .field-grid.cols-3{grid-template-columns:1fr 1fr 1fr}
    .field-full{grid-column:1/-1}
    .field{display:flex;flex-direction:column;gap:3px}
    .field label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888}
    .field input,.field select,.field textarea{padding:7px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;font-family:inherit}
    .field input:focus,.field select:focus,.field textarea:focus{outline:2px solid #82C112;outline-offset:-1px}
    .field textarea{resize:vertical;min-height:64px}

    .empty-note{color:#bbb;font-size:13px;padding:32px;text-align:center}
    .toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('bo_state_reports', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">State Annual Reports</div>
    </header>
    <main class="wrap">
      <div class="card" style="padding:20px 24px">

        <!-- Summary tiles -->
        <div class="mls-tiles" id="office-tiles">
          <div class="mls-tile"><div class="mls-tile-val" id="o-total">—</div><div class="mls-tile-lbl">Total Offices</div></div>
          <div class="mls-tile blue"><div class="mls-tile-val" id="o-states">—</div><div class="mls-tile-lbl">States Covered</div></div>
          <div class="mls-tile amber"><div class="mls-tile-val" id="o-expsoon">—</div><div class="mls-tile-lbl">Expiring ≤60 Days</div></div>
          <div class="mls-tile" style="border-color:#f5c6c6;background:#fef7f7"><div class="mls-tile-val" id="o-expired" style="color:#c00">—</div><div class="mls-tile-lbl">Expired</div></div>
        </div>

        <div class="toolbar">
          <?php if ($superAdmin): ?>
          <button class="btn-primary" onclick="openOfficeModal()">+ Add Office</button>
          <?php endif; ?>
        </div>

        <div style="overflow-x:auto">
          <table class="mls-table">
            <thead>
              <tr>
                <th>State</th>
                <th>Branch / Office</th>
                <th>Entity / DBA</th>
                <th>Office License #</th>
                <th>Lic. Expiration</th>
                <th>Designated Broker</th>
                <th>Market Leader</th>
                <th>Broker License #</th>
                <th>Broker Exp.</th>
                <th>Phone</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="office-tbody"><tr><td colspan="11" class="empty-note">Loading…</td></tr></tbody>
          </table>
        </div>

      </div>
    </main>
  </div>
</div>

<!-- Office Add / Edit Modal -->
<div class="modal-overlay" id="office-modal">
  <div class="modal">
    <div class="modal-head">
      <h3 id="office-modal-title">Add Office</h3>
      <button class="modal-close" onclick="closeOfficeModal()">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="o-id">

      <div class="form-section">
        <div class="form-section-title">Office</div>
        <div class="field-grid cols-3">
          <div class="field"><label>State</label><input type="text" id="o-state" maxlength="2" placeholder="e.g. SC" style="text-transform:uppercase" oninput="this.value=this.value.toUpperCase()"></div>
          <div class="field field-full" style="grid-column:span 2"><label>Branch / Office Name</label><input type="text" id="o-branch" placeholder="e.g. Hilton Head"></div>
          <div class="field field-full"><label>Entity Name</label><input type="text" id="o-entity" placeholder="e.g. INNOVATE Real Estate SC, LLC"></div>
          <div class="field field-full"><label>DBA</label><input type="text" id="o-dba" placeholder="e.g. INNOVATE Real Estate"></div>
          <div class="field"><label>Office Type</label><input type="text" id="o-office-type" placeholder="e.g. Corp Owned/State HQ"></div>
          <div class="field"><label>Firm Type</label>
            <select id="o-firm-type">
              <option value="Residential">Residential</option>
              <option value="Referral">Referral</option>
            </select>
          </div>
          <div class="field"><label>FUB Phone #</label><input type="text" id="o-phone"></div>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-title">Office License</div>
        <div class="field-grid cols-3">
          <div class="field"><label>Office License Number</label><input type="text" id="o-office-license"></div>
          <div class="field"><label>Expiration Date</label><input type="date" id="o-license-exp"></div>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-title">Broker</div>
        <div class="field-grid cols-3">
          <div class="field"><label>Designated Broker</label><input type="text" id="o-broker"></div>
          <div class="field"><label>Market Leader</label><input type="text" id="o-ml"></div>
          <div class="field"><label>Broker License Number</label><input type="text" id="o-broker-license"></div>
          <div class="field"><label>Broker Expiration Date</label><input type="date" id="o-broker-exp"></div>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-title">Address &amp; Lease</div>
        <div class="field-grid">
          <div class="field field-full"><label>Address</label><input type="text" id="o-address"></div>
          <div class="field field-full"><label>Lease Management / Payee</label><input type="text" id="o-lease" placeholder="e.g. Privately Held, Regus, Spaces"></div>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-title">Linked MLS Integration</div>
        <div class="field">
          <select id="o-mls-integration"><option value="">— None —</option></select>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-title">Notes</div>
        <div class="field"><textarea id="o-notes" rows="3" placeholder="Anything unusual about this office…"></textarea></div>
      </div>
    </div>
    <div class="modal-foot">
      <?php if ($superAdmin): ?>
      <button class="btn-danger btn-sm" id="office-modal-delete-btn" onclick="deleteOffice()" style="margin-right:auto;display:none">Delete</button>
      <?php endif; ?>
      <button class="btn-ghost" onclick="closeOfficeModal()">Cancel</button>
      <?php if ($superAdmin): ?>
      <button class="btn-primary" id="office-modal-save-btn" onclick="saveOffice()">Save</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
const SUPER = <?= $superAdmin ? 'true' : 'false' ?>;

function esc(s){return String(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
function fmt(d){if(!d)return'—';const p=d.split('-');return p[1]+'/'+p[2]+'/'+p[0];}

let allRows = [];
let officeRows = [];

function daysUntil(d){
  if(!d) return null;
  const today = new Date(); today.setHours(0,0,0,0);
  const target = new Date(d+'T00:00:00');
  return Math.round((target-today)/86400000);
}
function expBadge(d){
  const days = daysUntil(d);
  if(days===null) return `<span class="badge badge-exp-none">—</span>`;
  if(days<0) return `<span class="badge badge-exp-over">${fmt(d)}</span>`;
  if(days<=60) return `<span class="badge badge-exp-soon">${fmt(d)}</span>`;
  return `<span class="badge badge-exp-ok">${fmt(d)}</span>`;
}

function load(){
  fetch('api/mls_action.php',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    allRows = d.rows || [];
    loadOffices();
  });
}

function loadOffices(){
  fetch('api/mls_offices_action.php',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    officeRows = d.rows || [];
    renderOfficeTiles(officeRows);
    renderOfficeTable(officeRows);
    populateIntegrationSelect();
  });
}

function renderOfficeTiles(rows){
  const states = new Set(rows.map(r=>r.state).filter(Boolean));
  const expSoon = rows.filter(r=>{
    const dl = daysUntil(r.license_expiration), db = daysUntil(r.broker_expiration);
    return (dl!==null && dl>=0 && dl<=60) || (db!==null && db>=0 && db<=60);
  }).length;
  const expired = rows.filter(r=>{
    const dl = daysUntil(r.license_expiration), db = daysUntil(r.broker_expiration);
    return (dl!==null && dl<0) || (db!==null && db<0);
  }).length;
  document.getElementById('o-total').textContent = rows.length;
  document.getElementById('o-states').textContent = states.size;
  document.getElementById('o-expsoon').textContent = expSoon;
  document.getElementById('o-expired').textContent = expired;
}

function renderOfficeTable(rows){
  const tbody=document.getElementById('office-tbody');
  if(!rows.length){tbody.innerHTML='<tr><td colspan="11" class="empty-note">No offices on file yet. Click "+ Add Office" to get started.</td></tr>';return;}
  const sorted=[...rows].sort((a,b)=>(a.state||'').localeCompare(b.state||'')||(a.branch_office||'').localeCompare(b.branch_office||''));
  tbody.innerHTML=sorted.map(r=>{
    const entityDba=[r.entity_name,r.dba].filter(Boolean).join(' — ')||'—';
    return `<tr onclick="openOfficeModal(${r.id})" style="cursor:pointer">
      <td><strong>${esc(r.state||'—')}</strong></td>
      <td style="color:#555">${esc(r.branch_office||'—')}</td>
      <td style="font-size:12px;color:#555">${esc(entityDba)}</td>
      <td><code style="font-size:11px;background:#f3f4f6;padding:2px 5px;border-radius:3px">${esc(r.office_license_number||'—')}</code></td>
      <td>${expBadge(r.license_expiration)}</td>
      <td style="color:#555">${esc(r.designated_broker||'—')}</td>
      <td style="color:#555">${esc(r.market_leader||'—')}</td>
      <td><code style="font-size:11px;background:#f3f4f6;padding:2px 5px;border-radius:3px">${esc(r.broker_license_number||'—')}</code></td>
      <td>${expBadge(r.broker_expiration)}</td>
      <td style="color:#555;font-size:12px">${esc(r.fub_phone||'—')}</td>
      <td onclick="event.stopPropagation()">${SUPER?`<button class="btn-sm" onclick="openOfficeModal(${r.id})">Edit</button>`:''}</td>
    </tr>`;
  }).join('');
}

function populateIntegrationSelect(){
  const sel = document.getElementById('o-mls-integration');
  const current = sel.value;
  sel.innerHTML = '<option value="">— None —</option>' +
    allRows.map(r=>`<option value="${r.id}">${esc(r.mls_name)}</option>`).join('');
  sel.value = current;
}

function officeFieldIds(){
  return ['o-id','o-state','o-branch','o-entity','o-dba','o-office-type','o-phone',
    'o-office-license','o-license-exp','o-broker','o-ml','o-broker-license','o-broker-exp',
    'o-address','o-lease','o-notes'];
}

function openOfficeModal(id){
  const editing = id != null;
  document.getElementById('office-modal-title').textContent = editing ? 'Edit Office' : 'Add Office';
  const del = document.getElementById('office-modal-delete-btn');
  if(del) del.style.display = editing ? '' : 'none';

  officeFieldIds().forEach(k=>{ const el=document.getElementById(k); if(el) el.value=''; });
  document.getElementById('o-firm-type').value='Residential';
  document.getElementById('o-mls-integration').value='';

  if(editing){
    const r=officeRows.find(x=>x.id===id);
    if(!r)return;
    document.getElementById('o-id').value=r.id;
    document.getElementById('o-state').value=r.state||'';
    document.getElementById('o-branch').value=r.branch_office||'';
    document.getElementById('o-entity').value=r.entity_name||'';
    document.getElementById('o-dba').value=r.dba||'';
    document.getElementById('o-office-type').value=r.office_type||'';
    document.getElementById('o-firm-type').value=r.firm_type||'Residential';
    document.getElementById('o-phone').value=r.fub_phone||'';
    document.getElementById('o-office-license').value=r.office_license_number||'';
    document.getElementById('o-license-exp').value=r.license_expiration||'';
    document.getElementById('o-broker').value=r.designated_broker||'';
    document.getElementById('o-ml').value=r.market_leader||'';
    document.getElementById('o-broker-license').value=r.broker_license_number||'';
    document.getElementById('o-broker-exp').value=r.broker_expiration||'';
    document.getElementById('o-address').value=r.address||'';
    document.getElementById('o-lease').value=r.lease_payee||'';
    document.getElementById('o-notes').value=r.notes||'';
    document.getElementById('o-mls-integration').value=r.mls_integration_id||'';
  }
  document.getElementById('office-modal').querySelectorAll('input,select,textarea').forEach(el=>el.disabled=!SUPER);
  document.getElementById('office-modal').classList.add('open');
}

function closeOfficeModal(){document.getElementById('office-modal').classList.remove('open');}

function saveOffice(){
  const id = document.getElementById('o-id').value;
  const payload={
    action: id ? 'update' : 'add',
    id: id ? parseInt(id) : undefined,
    state: document.getElementById('o-state').value.trim(),
    branch_office: document.getElementById('o-branch').value.trim(),
    entity_name: document.getElementById('o-entity').value.trim(),
    dba: document.getElementById('o-dba').value.trim(),
    office_type: document.getElementById('o-office-type').value.trim(),
    firm_type: document.getElementById('o-firm-type').value,
    office_license_number: document.getElementById('o-office-license').value.trim(),
    license_expiration: document.getElementById('o-license-exp').value||null,
    designated_broker: document.getElementById('o-broker').value.trim(),
    market_leader: document.getElementById('o-ml').value.trim(),
    broker_license_number: document.getElementById('o-broker-license').value.trim(),
    broker_expiration: document.getElementById('o-broker-exp').value||null,
    fub_phone: document.getElementById('o-phone').value.trim(),
    address: document.getElementById('o-address').value.trim(),
    lease_payee: document.getElementById('o-lease').value.trim(),
    notes: document.getElementById('o-notes').value.trim(),
    mls_integration_id: document.getElementById('o-mls-integration').value||null,
  };
  if(!payload.state){alert('State is required.');return;}
  const btn=document.getElementById('office-modal-save-btn');
  btn.disabled=true; btn.textContent='Saving…';
  fetch('api/mls_offices_action.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})
    .then(r=>r.json()).then(d=>{
      btn.disabled=false; btn.textContent='Save';
      if(d.ok){closeOfficeModal();loadOffices();}else alert(d.error||'Save failed.');
    }).catch(()=>{btn.disabled=false;btn.textContent='Save';alert('Request failed.');});
}

function deleteOffice(){
  const id=parseInt(document.getElementById('o-id').value);
  if(!id)return;
  const r=officeRows.find(x=>x.id===id);
  if(!confirm('Delete the '+((r&&r.state)||'')+' '+((r&&r.branch_office)||'office')+' record? This cannot be undone.'))return;
  fetch('api/mls_offices_action.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',id})})
    .then(r=>r.json()).then(d=>{if(d.ok){closeOfficeModal();loadOffices();}else alert(d.error||'Delete failed.');});
}

load();
</script>
</body>
</html>
