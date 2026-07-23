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
  <title>Licensing and Renewals — AgentEdge</title>
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
    .mls-tile.green{border-color:#c3dfa8;background:#f9fdf5}
    .mls-tile.green .mls-tile-val{color:#5b8e0d}
    .mls-tile.blue{border-color:#bfdbfe;background:#eff6ff}
    .mls-tile.blue .mls-tile-val{color:#1d4ed8}
    .mls-tile.amber{border-color:#fde68a;background:#fffbeb}
    .mls-tile.amber .mls-tile-val{color:#b45309}

    /* Table */
    .mls-table{width:100%;border-collapse:collapse;font-size:13px}
    .mls-table th{text-align:left;padding:8px 12px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;border-bottom:2px solid #f0f0f0}
    .mls-table td{padding:10px 12px;border-bottom:1px solid #f5f5f5;vertical-align:middle}
    .mls-table tr:hover td{background:#fafafa;cursor:pointer}

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
    .field-row{display:flex;gap:6px;align-items:center}
    .field-row input{flex:1}

    /* Credential masking */
    .cred-val{font-family:monospace;font-size:12px;background:#f8f8f8;border:1px solid #eee;border-radius:4px;padding:5px 9px;flex:1;word-break:break-all}
    .cred-reveal{background:none;border:none;cursor:pointer;font-size:11px;color:#5b8e0d;font-weight:700;white-space:nowrap}
    .cred-reveal:hover{text-decoration:underline}

    .empty-note{color:#bbb;font-size:13px;padding:32px;text-align:center}
    .toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px}

    /* Tabs */
    .mls-tabs{display:flex;gap:4px;border-bottom:2px solid #f0f0f0;margin-bottom:20px}
    .mls-tab{padding:10px 4px;margin-bottom:-2px;background:none;border:none;border-bottom:2px solid transparent;font-size:13px;font-weight:700;color:#888;cursor:pointer}
    .mls-tab+.mls-tab{margin-left:16px}
    .mls-tab:hover{color:#333}
    .mls-tab.active{color:#5b8e0d;border-bottom-color:#82C112}
    .tab-panel{display:none}
    .tab-panel.active{display:block}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('bo_licensing', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Licensing and Renewals</div>
    </header>
    <main class="wrap">
      <div class="card" style="padding:20px 24px">

        <div class="mls-tabs">
          <button class="mls-tab active" id="tab-btn-board" onclick="switchTab('board')">Board Memberships</button>
          <button class="mls-tab" id="tab-btn-mls" onclick="switchTab('mls')">MLS Memberships</button>
        </div>

        <!-- ═══ Board Memberships tab ═══ -->
        <div class="tab-panel active" id="tab-board">

        <!-- Summary tiles -->
        <div class="mls-tiles" id="board-tiles">
          <div class="mls-tile"><div class="mls-tile-val" id="bd-total">—</div><div class="mls-tile-lbl">Total Board Memberships</div></div>
          <div class="mls-tile blue"><div class="mls-tile-val" id="bd-states">—</div><div class="mls-tile-lbl">States Covered</div></div>
          <div class="mls-tile green"><div class="mls-tile-val" id="bd-primary">—</div><div class="mls-tile-lbl">Primary</div></div>
          <div class="mls-tile amber"><div class="mls-tile-val" id="bd-secondary">—</div><div class="mls-tile-lbl">Secondary</div></div>
        </div>

        <div class="toolbar">
          <?php if ($superAdmin): ?>
          <button class="btn-primary" onclick="openMembershipModal(null,'Board')">+ Add Board Membership</button>
          <?php endif; ?>
        </div>

        <div style="overflow-x:auto">
          <table class="mls-table">
            <thead>
              <tr>
                <th>State</th>
                <th>Name</th>
                <th>Membership Type</th>
                <th>NRDS# / Office ID</th>
                <th>Broker of Record</th>
                <th>Username</th>
                <th>Password</th>
                <th>Log In</th>
                <th>Fees</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="board-tbody"><tr><td colspan="10" class="empty-note">Loading…</td></tr></tbody>
          </table>
        </div>
        </div>

        <!-- ═══ MLS Memberships tab ═══ -->
        <div class="tab-panel" id="tab-mls">

        <!-- Summary tiles -->
        <div class="mls-tiles" id="mls-membership-tiles">
          <div class="mls-tile"><div class="mls-tile-val" id="ml-total">—</div><div class="mls-tile-lbl">Total MLS Accounts</div></div>
          <div class="mls-tile blue"><div class="mls-tile-val" id="ml-states">—</div><div class="mls-tile-lbl">States Covered</div></div>
          <div class="mls-tile green"><div class="mls-tile-val" id="ml-loginset">—</div><div class="mls-tile-lbl">With Login Saved</div></div>
          <div class="mls-tile amber"><div class="mls-tile-val" id="ml-feeset">—</div><div class="mls-tile-lbl">With Fees On File</div></div>
        </div>

        <div class="toolbar">
          <?php if ($superAdmin): ?>
          <button class="btn-primary" onclick="openMembershipModal(null,'MLS')">+ Add MLS Membership</button>
          <?php endif; ?>
        </div>

        <div style="overflow-x:auto">
          <table class="mls-table">
            <thead>
              <tr>
                <th>State</th>
                <th>Name</th>
                <th>Membership Type</th>
                <th>Office ID</th>
                <th>Broker of Record</th>
                <th>Username</th>
                <th>Password</th>
                <th>Log In</th>
                <th>Fees</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="mls-membership-tbody"><tr><td colspan="10" class="empty-note">Loading…</td></tr></tbody>
          </table>
        </div>
        </div>

      </div>
    </main>
  </div>
</div>

<!-- Membership Add / Edit Modal -->
<div class="modal-overlay" id="membership-modal">
  <div class="modal">
    <div class="modal-head">
      <h3 id="membership-modal-title">Add Membership</h3>
      <button class="modal-close" onclick="closeMembershipModal()">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="m-id">

      <div class="form-section">
        <div class="form-section-title">Board / MLS</div>
        <div class="field-grid cols-3">
          <div class="field"><label>State</label><input type="text" id="m-state" placeholder="e.g. SC or SC | HH"></div>
          <div class="field"><label>Board / MLS</label>
            <select id="m-board-or-mls">
              <option value="Board">Board</option>
              <option value="MLS">MLS</option>
              <option value="Board & MLS">Board &amp; MLS</option>
            </select>
          </div>
          <div class="field"><label>Membership Type</label><input type="text" id="m-membership-type" placeholder="e.g. Primary (Board)"></div>
          <div class="field field-full" style="grid-column:1/-1"><label>Name</label><input type="text" id="m-name" placeholder="e.g. Coastal Carolina Association of Realtors"></div>
          <div class="field field-full" style="grid-column:1/-1"><label>Address</label><input type="text" id="m-address"></div>
          <div class="field"><label>Phone</label><input type="text" id="m-phone"></div>
          <div class="field"><label>Office ID (MLS) / NRDS# (Board)</label><input type="text" id="m-office-id"></div>
          <div class="field"><label>Broker of Record</label><input type="text" id="m-broker"></div>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-title">Login Credentials</div>
        <div class="field-grid">
          <div class="field"><label>Username</label><input type="text" id="m-username" autocomplete="off"></div>
          <div class="field"><label>Password</label>
            <div class="field-row">
              <input type="password" id="m-password" autocomplete="new-password">
              <button type="button" class="btn-sm" onclick="togglePwd('m-password', this)">Show</button>
            </div>
          </div>
          <div class="field field-full"><label>Log In Link</label><input type="text" id="m-login-link" placeholder="URL or portal name"></div>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-title">Billing</div>
        <div class="field-grid">
          <div class="field field-full"><label>Billing Site</label><input type="text" id="m-billing-site" placeholder="URL"></div>
          <div class="field"><label>Billing Frequency</label><input type="text" id="m-billing-frequency" placeholder="e.g. quarterly"></div>
          <div class="field"><label>Billing Username</label><input type="text" id="m-billing-username" autocomplete="off"></div>
          <div class="field"><label>Billing Password</label>
            <div class="field-row">
              <input type="password" id="m-billing-password" autocomplete="new-password">
              <button type="button" class="btn-sm" onclick="togglePwd('m-billing-password', this)">Show</button>
            </div>
          </div>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-title">Fees</div>
        <div class="field-grid cols-3">
          <div class="field"><label>Office Fees</label><input type="text" id="m-office-fees" placeholder="e.g. $45/quarterly"></div>
          <div class="field"><label>Broker Fees</label><input type="text" id="m-broker-fees"></div>
          <div class="field"><label>Admin Fees</label><input type="text" id="m-admin-fees"></div>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-title">Notes</div>
        <div class="field"><textarea id="m-notes" rows="3"></textarea></div>
      </div>
    </div>
    <div class="modal-foot">
      <?php if ($superAdmin): ?>
      <button class="btn-danger btn-sm" id="membership-modal-delete-btn" onclick="deleteMembership()" style="margin-right:auto;display:none">Delete</button>
      <?php endif; ?>
      <button class="btn-ghost" onclick="closeMembershipModal()">Cancel</button>
      <?php if ($superAdmin): ?>
      <button class="btn-primary" id="membership-modal-save-btn" onclick="saveMembership()">Save</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
const SUPER = <?= $superAdmin ? 'true' : 'false' ?>;

function esc(s){return String(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}

let membershipRows = [];

/* ══════════════ Tabs ══════════════ */
function switchTab(name){
  ['board','mls'].forEach(n=>{
    document.getElementById('tab-'+n).classList.toggle('active', n===name);
    document.getElementById('tab-btn-'+n).classList.toggle('active', n===name);
  });
}

function togglePwd(id, btn){
  const el = document.getElementById(id);
  if(el.type === 'password'){ el.type = 'text'; btn.textContent = 'Hide'; }
  else { el.type = 'password'; btn.textContent = 'Show'; }
}

function load(){
  fetch('api/mls_memberships_action.php',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    membershipRows = d.rows || [];
    renderMembershipTiles(membershipRows);
    renderMembershipTable(membershipRows);
  });
}

function renderMembershipTiles(rows){
  const boardRows = rows.filter(r=>(r.board_or_mls||'').includes('Board'));
  const mlsRows = rows.filter(r=>(r.board_or_mls||'').includes('MLS'));
  const boardStates = new Set(boardRows.map(r=>(r.state||'').split('|')[0].trim()).filter(Boolean));
  const mlsStates = new Set(mlsRows.map(r=>(r.state||'').split('|')[0].trim()).filter(Boolean));

  document.getElementById('bd-total').textContent = boardRows.length;
  document.getElementById('bd-states').textContent = boardStates.size;
  document.getElementById('bd-primary').textContent = boardRows.filter(r=>(r.membership_type||'').toLowerCase().includes('primary')).length;
  document.getElementById('bd-secondary').textContent = boardRows.filter(r=>(r.membership_type||'').toLowerCase().includes('secondary')).length;

  document.getElementById('ml-total').textContent = mlsRows.length;
  document.getElementById('ml-states').textContent = mlsStates.size;
  document.getElementById('ml-loginset').textContent = mlsRows.filter(r=>r.username).length;
  document.getElementById('ml-feeset').textContent = mlsRows.filter(r=>r.office_fees||r.broker_fees||r.admin_fees).length;
}

function feesSummary(r){
  const parts = [];
  if(r.office_fees) parts.push('Office: '+r.office_fees);
  if(r.broker_fees) parts.push('Broker: '+r.broker_fees);
  if(r.admin_fees) parts.push('Admin: '+r.admin_fees);
  return parts.join(' · ') || '—';
}

function loginLinkCell(r){
  const v = r.login_link || '';
  if(!v) return '—';
  if(/^https?:\/\//i.test(v)) return `<a href="${esc(v)}" target="_blank" onclick="event.stopPropagation()" style="color:#5b8e0d;font-size:11px">Open ↗</a>`;
  return `<span style="font-size:11px;color:#777">${esc(v)}</span>`;
}

function passwordCell(r){
  if(!r.password) return '—';
  const elId = 'mm-pwd-'+r.id;
  const safeVal = esc(r.password).replace(/'/g,"\\'");
  return `<span class="cred-val" id="${elId}" style="display:inline-block;padding:2px 6px;min-width:0">••••••••</span>` +
    (SUPER ? ` <button class="cred-reveal" onclick="event.stopPropagation();toggleCred('${elId}','${safeVal}')">Reveal</button>` : '');
}

let credRevealed={};
function toggleCred(elId, val){
  if(credRevealed[elId]){document.getElementById(elId).textContent='••••••••';credRevealed[elId]=false;}
  else{document.getElementById(elId).textContent=val;credRevealed[elId]=true;}
}

function renderMembershipRow(r){
  return `<tr onclick="openMembershipModal(${r.id})" style="cursor:pointer">
    <td><strong>${esc(r.state||'—')}</strong></td>
    <td style="font-size:12px;color:#555">${esc(r.name||'—')}</td>
    <td style="color:#555;font-size:12px">${esc(r.membership_type||'—')}</td>
    <td><code style="font-size:11px;background:#f3f4f6;padding:2px 5px;border-radius:3px">${esc(r.office_id||'—')}</code></td>
    <td style="color:#555">${esc(r.broker_of_record||'—')}</td>
    <td style="font-size:12px;color:#555">${esc(r.username||'—')}</td>
    <td onclick="event.stopPropagation()">${passwordCell(r)}</td>
    <td onclick="event.stopPropagation()">${loginLinkCell(r)}</td>
    <td style="font-size:11px;color:#777">${esc(feesSummary(r))}</td>
    <td onclick="event.stopPropagation()">${SUPER?`<button class="btn-sm" onclick="openMembershipModal(${r.id})">Edit</button>`:''}</td>
  </tr>`;
}

function renderMembershipTable(rows){
  const sortFn=(a,b)=>(a.state||'').localeCompare(b.state||'')||(a.name||'').localeCompare(b.name||'');

  const boardRows=[...rows.filter(r=>(r.board_or_mls||'').includes('Board'))].sort(sortFn);
  const boardTbody=document.getElementById('board-tbody');
  boardTbody.innerHTML = boardRows.length
    ? boardRows.map(renderMembershipRow).join('')
    : '<tr><td colspan="10" class="empty-note">No board memberships on file yet. Click "+ Add Board Membership" to get started.</td></tr>';

  const mlsRows=[...rows.filter(r=>(r.board_or_mls||'').includes('MLS'))].sort(sortFn);
  const mlsTbody=document.getElementById('mls-membership-tbody');
  mlsTbody.innerHTML = mlsRows.length
    ? mlsRows.map(renderMembershipRow).join('')
    : '<tr><td colspan="10" class="empty-note">No MLS memberships on file yet. Click "+ Add MLS Membership" to get started.</td></tr>';
}

function membershipFieldIds(){
  return ['m-id','m-state','m-membership-type','m-name','m-address','m-phone','m-office-id','m-broker',
    'm-username','m-password','m-login-link','m-billing-site','m-billing-frequency','m-billing-username',
    'm-billing-password','m-office-fees','m-broker-fees','m-admin-fees','m-notes'];
}

function openMembershipModal(id, defaultType){
  const editing = id != null;
  document.getElementById('membership-modal-title').textContent = editing ? 'Edit Membership' : ('Add ' + (defaultType||'Board') + ' Membership');
  const del = document.getElementById('membership-modal-delete-btn');
  if(del) del.style.display = editing ? '' : 'none';

  membershipFieldIds().forEach(k=>{ const el=document.getElementById(k); if(el) el.value=''; });
  document.getElementById('m-board-or-mls').value = defaultType || 'Board';
  ['m-password','m-billing-password'].forEach(id=>{
    document.getElementById(id).type='password';
  });
  document.querySelectorAll('#membership-modal button.btn-sm[onclick^="togglePwd"]').forEach(b=>b.textContent='Show');

  if(editing){
    const r=membershipRows.find(x=>x.id===id);
    if(!r)return;
    document.getElementById('m-id').value=r.id;
    document.getElementById('m-state').value=r.state||'';
    document.getElementById('m-board-or-mls').value=r.board_or_mls||'Board';
    document.getElementById('m-membership-type').value=r.membership_type||'';
    document.getElementById('m-name').value=r.name||'';
    document.getElementById('m-address').value=r.address||'';
    document.getElementById('m-phone').value=r.phone||'';
    document.getElementById('m-office-id').value=r.office_id||'';
    document.getElementById('m-broker').value=r.broker_of_record||'';
    document.getElementById('m-username').value=r.username||'';
    document.getElementById('m-password').value=r.password||'';
    document.getElementById('m-login-link').value=r.login_link||'';
    document.getElementById('m-billing-site').value=r.billing_site||'';
    document.getElementById('m-billing-frequency').value=r.billing_frequency||'';
    document.getElementById('m-billing-username').value=r.billing_username||'';
    document.getElementById('m-billing-password').value=r.billing_password||'';
    document.getElementById('m-office-fees').value=r.office_fees||'';
    document.getElementById('m-broker-fees').value=r.broker_fees||'';
    document.getElementById('m-admin-fees').value=r.admin_fees||'';
    document.getElementById('m-notes').value=r.notes||'';
  }
  document.getElementById('membership-modal').querySelectorAll('input,select,textarea').forEach(el=>el.disabled=!SUPER);
  document.getElementById('membership-modal').querySelectorAll('button.btn-sm[onclick^="togglePwd"]').forEach(b=>b.disabled=!SUPER);
  document.getElementById('membership-modal').classList.add('open');
}

function closeMembershipModal(){document.getElementById('membership-modal').classList.remove('open');}

function saveMembership(){
  const id = document.getElementById('m-id').value;
  const payload={
    action: id ? 'update' : 'add',
    id: id ? parseInt(id) : undefined,
    state: document.getElementById('m-state').value.trim(),
    board_or_mls: document.getElementById('m-board-or-mls').value,
    membership_type: document.getElementById('m-membership-type').value.trim(),
    name: document.getElementById('m-name').value.trim(),
    address: document.getElementById('m-address').value.trim(),
    phone: document.getElementById('m-phone').value.trim(),
    office_id: document.getElementById('m-office-id').value.trim(),
    broker_of_record: document.getElementById('m-broker').value.trim(),
    username: document.getElementById('m-username').value.trim(),
    password: document.getElementById('m-password').value,
    login_link: document.getElementById('m-login-link').value.trim(),
    billing_site: document.getElementById('m-billing-site').value.trim(),
    billing_frequency: document.getElementById('m-billing-frequency').value.trim(),
    billing_username: document.getElementById('m-billing-username').value.trim(),
    billing_password: document.getElementById('m-billing-password').value,
    office_fees: document.getElementById('m-office-fees').value.trim(),
    broker_fees: document.getElementById('m-broker-fees').value.trim(),
    admin_fees: document.getElementById('m-admin-fees').value.trim(),
    notes: document.getElementById('m-notes').value.trim(),
  };
  if(!payload.state || !payload.name){alert('State and Name are required.');return;}
  const btn=document.getElementById('membership-modal-save-btn');
  btn.disabled=true; btn.textContent='Saving…';
  fetch('api/mls_memberships_action.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})
    .then(r=>r.json()).then(d=>{
      btn.disabled=false; btn.textContent='Save';
      if(d.ok){closeMembershipModal();load();}else alert(d.error||'Save failed.');
    }).catch(()=>{btn.disabled=false;btn.textContent='Save';alert('Request failed.');});
}

function deleteMembership(){
  const id=parseInt(document.getElementById('m-id').value);
  if(!id)return;
  const r=membershipRows.find(x=>x.id===id);
  if(!confirm('Delete the membership "'+((r&&r.name)||'')+'"? This cannot be undone.'))return;
  fetch('api/mls_memberships_action.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',id})})
    .then(r=>r.json()).then(d=>{if(d.ok){closeMembershipModal();load();}else alert(d.error||'Delete failed.');});
}

load();
</script>
</body>
</html>
