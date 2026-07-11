<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/roles.php';
$agent = require_login();
if (!is_admin()) { header('Location: index.php'); exit; }
$superAdmin = is_super_admin();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>MLS Integrations — AgentEdge</title>
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
    .mls-table tr.no-click:hover td{cursor:default}

    /* Status badges */
    .badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap}
    .badge-active{background:#d1fae5;color:#065f46}
    .badge-approved{background:#e0f2fe;color:#0c4a6e}
    .badge-applied{background:#ede9fe;color:#4c1d95}
    .badge-researching{background:#f3f4f6;color:#6b7280}
    .badge-paused{background:#fff7ed;color:#9a3412}
    .badge-rejected{background:#fee2e2;color:#991b1b}

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

    /* Products checkboxes */
    .check-group{display:flex;gap:16px;flex-wrap:wrap}
    .check-item{display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer}
    .check-item input{width:14px;height:14px;cursor:pointer;accent-color:#82C112}

    .empty-note{color:#bbb;font-size:13px;padding:32px;text-align:center}
    .toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
    .fee-val{font-size:12px;font-weight:700;color:#555}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('bo_mls', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">MLS Integrations</div>
    </header>
    <main class="wrap">
      <div class="card" style="padding:20px 24px">

        <!-- Summary tiles -->
        <div class="mls-tiles" id="mls-tiles">
          <div class="mls-tile green"><div class="mls-tile-val" id="t-active">—</div><div class="mls-tile-lbl">Active</div></div>
          <div class="mls-tile blue"><div class="mls-tile-val" id="t-pipeline">—</div><div class="mls-tile-lbl">In Pipeline</div></div>
          <div class="mls-tile amber"><div class="mls-tile-val" id="t-monthly">—</div><div class="mls-tile-lbl">Monthly Fees</div></div>
          <div class="mls-tile"><div class="mls-tile-val" id="t-total">—</div><div class="mls-tile-lbl">Total MLSs</div></div>
        </div>

        <div class="toolbar">
          <?php if ($superAdmin): ?>
          <button class="btn-primary" onclick="openModal()">+ Add MLS</button>
          <?php endif; ?>
        </div>

        <div style="overflow-x:auto">
          <table class="mls-table">
            <thead>
              <tr>
                <th>MLS Name</th>
                <th>Code</th>
                <th>Region / States</th>
                <th>Feed Type</th>
                <th>Status</th>
                <th>Monthly Fee</th>
                <th>Go-Live</th>
                <th>Products</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="mls-tbody"><tr><td colspan="9" class="empty-note">Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
    </main>
  </div>
</div>

<!-- Add / Edit Modal -->
<div class="modal-overlay" id="mls-modal">
  <div class="modal">
    <div class="modal-head">
      <h3 id="modal-title">Add MLS Integration</h3>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="f-id">

      <!-- Basic Info -->
      <div class="form-section">
        <div class="form-section-title">Basic Information</div>
        <div class="field-grid">
          <div class="field field-full"><label>MLS Name</label><input type="text" id="f-name" placeholder="e.g. PrimeMLS"></div>
          <div class="field"><label>Short Code</label><input type="text" id="f-code" placeholder="e.g. PRIME" style="text-transform:uppercase" oninput="this.value=this.value.toUpperCase()"></div>
          <div class="field"><label>Status</label>
            <select id="f-status">
              <option value="researching">Researching</option>
              <option value="applied">Applied</option>
              <option value="approved">Approved (Pending Go-Live)</option>
              <option value="active">Active</option>
              <option value="paused">Paused</option>
              <option value="rejected">Rejected</option>
            </select>
          </div>
          <div class="field field-full"><label>Region / States Covered</label><input type="text" id="f-region" placeholder="e.g. NH, VT, ME, MA"></div>
          <div class="field"><label>Feed Type</label>
            <select id="f-feed-type">
              <option value="RETS">RETS</option>
              <option value="OIDH">OIDH / Bridge Interactive</option>
              <option value="Trestle">Trestle (CoreLogic)</option>
              <option value="Spark">Spark Platform</option>
              <option value="Other">Other</option>
            </select>
          </div>
          <div class="field"><label>Monthly Fee ($)</label><input type="number" id="f-fee" min="0" step="0.01" placeholder="0.00"></div>
        </div>
        <div style="margin-top:10px">
          <div class="field"><label>Products Using This MLS</label>
            <div class="check-group">
              <label class="check-item"><input type="checkbox" id="f-prod-idx" value="idx"> growwithinnovate.com (IDX)</label>
              <label class="check-item"><input type="checkbox" id="f-prod-crm" value="crm"> advantage.innovateonline.com (CRM)</label>
            </div>
          </div>
        </div>
      </div>

      <!-- Timeline -->
      <div class="form-section">
        <div class="form-section-title">Timeline</div>
        <div class="field-grid cols-3">
          <div class="field"><label>Application Date</label><input type="date" id="f-app-date"></div>
          <div class="field"><label>Approval Date</label><input type="date" id="f-appr-date"></div>
          <div class="field"><label>Go-Live Date</label><input type="date" id="f-live-date"></div>
        </div>
      </div>

      <!-- Agreement -->
      <div class="form-section">
        <div class="form-section-title">Agreement</div>
        <div class="field-grid">
          <div class="field field-full"><label>Agreement Document URL</label><input type="url" id="f-agreement-url" placeholder="https:// or leave blank"></div>
        </div>
      </div>

      <!-- Contact -->
      <div class="form-section">
        <div class="form-section-title">MLS Contact</div>
        <div class="field-grid">
          <div class="field"><label>Contact Name</label><input type="text" id="f-contact-name" placeholder="Full name"></div>
          <div class="field"><label>Organization / Title</label><input type="text" id="f-contact-org" placeholder="e.g. Data Coordinator"></div>
          <div class="field"><label>Email</label><input type="email" id="f-contact-email"></div>
          <div class="field"><label>Phone</label><input type="tel" id="f-contact-phone"></div>
        </div>
      </div>

      <!-- API Credentials -->
      <div class="form-section">
        <div class="form-section-title">API Credentials</div>
        <div class="field-grid">
          <div class="field field-full"><label>API Base URL</label><input type="url" id="f-api-url" placeholder="https://rets.primemls.com/..."></div>
          <div class="field"><label>Username / Client ID</label><input type="text" id="f-api-user" autocomplete="off"></div>
          <div class="field"><label>Password / Client Secret</label><input type="password" id="f-api-secret" autocomplete="new-password"></div>
          <div class="field field-full"><label>API Key / Access Token</label><input type="text" id="f-api-key" autocomplete="off"></div>
        </div>
      </div>

      <!-- Notes -->
      <div class="form-section">
        <div class="form-section-title">Notes</div>
        <div class="field"><textarea id="f-notes" rows="3" placeholder="Status updates, contact history, gotchas…"></textarea></div>
      </div>
    </div>
    <div class="modal-foot">
      <?php if ($superAdmin): ?>
      <button class="btn-danger btn-sm" id="modal-delete-btn" onclick="deleteMls()" style="margin-right:auto;display:none">Delete</button>
      <?php endif; ?>
      <button class="btn-ghost" onclick="closeModal()">Cancel</button>
      <?php if ($superAdmin): ?>
      <button class="btn-primary" id="modal-save-btn" onclick="saveMls()">Save</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- View Modal (read-only for non-superAdmin) -->
<div class="modal-overlay" id="view-modal">
  <div class="modal">
    <div class="modal-head">
      <h3 id="view-title">MLS Detail</h3>
      <button class="modal-close" onclick="closeViewModal()">✕</button>
    </div>
    <div class="modal-body" id="view-body" style="font-size:13px"></div>
    <div class="modal-foot">
      <?php if ($superAdmin): ?>
      <button class="btn-ghost" onclick="editFromView()">Edit</button>
      <?php endif; ?>
      <button class="btn-primary" onclick="closeViewModal()">Close</button>
    </div>
  </div>
</div>

<script>
const SUPER = <?= $superAdmin ? 'true' : 'false' ?>;

function esc(s){return String(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
function fmt(d){if(!d)return'—';const p=d.split('-');return p[1]+'/'+p[2]+'/'+p[0];}
function fmtFee(v){if(!v&&v!==0)return'—';return'$'+Number(v).toLocaleString('en-US',{minimumFractionDigits:0,maximumFractionDigits:0})+'/mo';}

const STATUS_LABELS={researching:'Researching',applied:'Applied',approved:'Approved',active:'Active',paused:'Paused',rejected:'Rejected'};
const FEED_LABELS={RETS:'RETS',OIDH:'OIDH/Bridge',Trestle:'Trestle',Spark:'Spark','Other':'Other'};
const PROD_LABELS={idx:'growwithinnovate.com',crm:'advantage.innovateonline.com'};

let allRows = [];
let viewId = null;

function load(){
  fetch('api/mls_action.php',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    allRows = d.rows || [];
    renderTiles(allRows);
    renderTable(allRows);
  });
}

function renderTiles(rows){
  const active = rows.filter(r=>r.status==='active').length;
  const pipeline = rows.filter(r=>['applied','approved','researching'].includes(r.status)).length;
  const monthly = rows.filter(r=>r.status==='active').reduce((a,r)=>a+parseFloat(r.monthly_fee||0),0);
  document.getElementById('t-active').textContent=active;
  document.getElementById('t-pipeline').textContent=pipeline;
  document.getElementById('t-monthly').textContent=monthly?'$'+monthly.toLocaleString('en-US',{minimumFractionDigits:0}):'$0';
  document.getElementById('t-total').textContent=rows.length;
}

function renderTable(rows){
  const tbody=document.getElementById('mls-tbody');
  if(!rows.length){tbody.innerHTML='<tr><td colspan="9" class="empty-note">No MLS integrations yet. Click "+ Add MLS" to get started.</td></tr>';return;}
  const order=['active','approved','applied','researching','paused','rejected'];
  rows=[...rows].sort((a,b)=>order.indexOf(a.status)-order.indexOf(b.status));
  tbody.innerHTML=rows.map(r=>{
    const prods=(r.products||'').split(',').filter(Boolean).map(p=>esc(PROD_LABELS[p]||p)).join(', ')||'—';
    return `<tr onclick="viewRow(${r.id})" style="cursor:pointer">
      <td><strong>${esc(r.mls_name)}</strong></td>
      <td><code style="font-size:11px;background:#f3f4f6;padding:2px 5px;border-radius:3px">${esc(r.mls_code||'—')}</code></td>
      <td style="color:#555">${esc(r.region||'—')}</td>
      <td style="color:#555">${esc(FEED_LABELS[r.feed_type]||r.feed_type||'—')}</td>
      <td><span class="badge badge-${esc(r.status)}">${esc(STATUS_LABELS[r.status]||r.status)}</span></td>
      <td class="fee-val">${fmtFee(r.monthly_fee)}</td>
      <td style="color:#555">${fmt(r.go_live_date)}</td>
      <td style="font-size:11px;color:#777">${esc(prods)}</td>
      <td onclick="event.stopPropagation()">${SUPER?`<button class="btn-sm" onclick="openModal(${r.id})">Edit</button>`:''}
      </td>
    </tr>`;
  }).join('');
}

function viewRow(id){
  const r=allRows.find(x=>x.id===id);
  if(!r)return;
  viewId=id;
  document.getElementById('view-title').textContent=r.mls_name;
  const prods=(r.products||'').split(',').filter(Boolean).map(p=>PROD_LABELS[p]||p).join(', ')||'None';
  document.getElementById('view-body').innerHTML=`
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
      <div>
        <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px">Status</div>
        <span class="badge badge-${esc(r.status)}">${esc(STATUS_LABELS[r.status]||r.status)}</span>
      </div>
      <div>
        <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px">Code</div>
        <code style="font-size:12px">${esc(r.mls_code||'—')}</code>
      </div>
      <div>
        <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px">Region / States</div>
        <div>${esc(r.region||'—')}</div>
      </div>
      <div>
        <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px">Feed Type</div>
        <div>${esc(FEED_LABELS[r.feed_type]||r.feed_type||'—')}</div>
      </div>
      <div>
        <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px">Monthly Fee</div>
        <div>${fmtFee(r.monthly_fee)}</div>
      </div>
      <div>
        <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px">Products</div>
        <div>${esc(prods)}</div>
      </div>
      <div>
        <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px">Application Date</div>
        <div>${fmt(r.application_date)}</div>
      </div>
      <div>
        <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px">Approval Date</div>
        <div>${fmt(r.approval_date)}</div>
      </div>
      <div>
        <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px">Go-Live Date</div>
        <div>${fmt(r.go_live_date)}</div>
      </div>
      <div>
        <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px">Agreement</div>
        <div>${r.agreement_url?`<a href="${esc(r.agreement_url)}" target="_blank" style="color:#5b8e0d">View Agreement ↗</a>`:'—'}</div>
      </div>
    </div>
    ${r.contact_name||r.contact_email?`
    <hr style="border:none;border-top:1px solid #f0f0f0;margin:16px 0">
    <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:8px">MLS Contact</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13px">
      <div><span style="color:#888">Name:</span> ${esc(r.contact_name||'—')}</div>
      <div><span style="color:#888">Org:</span> ${esc(r.contact_org||'—')}</div>
      <div><span style="color:#888">Email:</span> ${r.contact_email?`<a href="mailto:${esc(r.contact_email)}" style="color:#5b8e0d">${esc(r.contact_email)}</a>`:'—'}</div>
      <div><span style="color:#888">Phone:</span> ${esc(r.contact_phone||'—')}</div>
    </div>`:''}
    ${(r.api_base_url||r.api_username||r.api_key)&&SUPER?`
    <hr style="border:none;border-top:1px solid #f0f0f0;margin:16px 0">
    <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:8px">API Credentials</div>
    <div style="display:flex;flex-direction:column;gap:8px">
      ${r.api_base_url?`<div style="display:flex;gap:8px;align-items:center"><span style="font-size:11px;color:#888;min-width:90px">Base URL</span><div class="cred-val">${esc(r.api_base_url)}</div></div>`:''}
      ${r.api_username?`<div style="display:flex;gap:8px;align-items:center"><span style="font-size:11px;color:#888;min-width:90px">Username</span><div class="cred-val">${esc(r.api_username)}</div></div>`:''}
      ${r.api_secret?`<div style="display:flex;gap:8px;align-items:center"><span style="font-size:11px;color:#888;min-width:90px">Password</span><div class="cred-val" id="vs-secret">••••••••</div><button class="cred-reveal" onclick="toggleCred('vs-secret','${esc(r.api_secret).replace(/'/g,"\\'")}')">Reveal</button></div>`:''}
      ${r.api_key?`<div style="display:flex;gap:8px;align-items:center"><span style="font-size:11px;color:#888;min-width:90px">Key / Token</span><div class="cred-val" id="vs-key">••••••••••••</div><button class="cred-reveal" onclick="toggleCred('vs-key','${esc(r.api_key).replace(/'/g,"\\'")}')">Reveal</button></div>`:''}
    </div>`:''}
    ${r.notes?`
    <hr style="border:none;border-top:1px solid #f0f0f0;margin:16px 0">
    <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:6px">Notes</div>
    <div style="font-size:13px;color:#444;white-space:pre-wrap">${esc(r.notes)}</div>`:''}
  `;
  document.getElementById('view-modal').classList.add('open');
}

let credRevealed={};
function toggleCred(elId, val){
  if(credRevealed[elId]){document.getElementById(elId).textContent='••••••••';credRevealed[elId]=false;}
  else{document.getElementById(elId).textContent=val;credRevealed[elId]=true;}
}

function editFromView(){
  closeViewModal();
  openModal(viewId);
}

function closeViewModal(){document.getElementById('view-modal').classList.remove('open');credRevealed={};}

function openModal(id){
  const editing = id != null;
  document.getElementById('modal-title').textContent = editing ? 'Edit MLS Integration' : 'Add MLS Integration';
  const del = document.getElementById('modal-delete-btn');
  if(del) del.style.display = editing ? '' : 'none';

  // Clear
  ['f-id','f-name','f-code','f-region','f-fee','f-app-date','f-appr-date','f-live-date',
   'f-agreement-url','f-contact-name','f-contact-org','f-contact-email','f-contact-phone',
   'f-api-url','f-api-user','f-api-secret','f-api-key','f-notes'].forEach(k=>{
    const el=document.getElementById(k);
    if(el)el.value='';
  });
  document.getElementById('f-status').value='researching';
  document.getElementById('f-feed-type').value='RETS';
  ['f-prod-idx','f-prod-crm'].forEach(k=>document.getElementById(k).checked=false);

  if(editing){
    const r=allRows.find(x=>x.id===id);
    if(!r)return;
    document.getElementById('f-id').value=r.id;
    document.getElementById('f-name').value=r.mls_name||'';
    document.getElementById('f-code').value=r.mls_code||'';
    document.getElementById('f-region').value=r.region||'';
    document.getElementById('f-status').value=r.status||'researching';
    document.getElementById('f-feed-type').value=r.feed_type||'RETS';
    document.getElementById('f-fee').value=r.monthly_fee||'';
    document.getElementById('f-app-date').value=r.application_date||'';
    document.getElementById('f-appr-date').value=r.approval_date||'';
    document.getElementById('f-live-date').value=r.go_live_date||'';
    document.getElementById('f-agreement-url').value=r.agreement_url||'';
    document.getElementById('f-contact-name').value=r.contact_name||'';
    document.getElementById('f-contact-org').value=r.contact_org||'';
    document.getElementById('f-contact-email').value=r.contact_email||'';
    document.getElementById('f-contact-phone').value=r.contact_phone||'';
    document.getElementById('f-api-url').value=r.api_base_url||'';
    document.getElementById('f-api-user').value=r.api_username||'';
    document.getElementById('f-api-secret').value=r.api_secret||'';
    document.getElementById('f-api-key').value=r.api_key||'';
    document.getElementById('f-notes').value=r.notes||'';
    const prods=(r.products||'').split(',').filter(Boolean);
    if(prods.includes('idx')) document.getElementById('f-prod-idx').checked=true;
    if(prods.includes('crm')) document.getElementById('f-prod-crm').checked=true;
  }
  document.getElementById('mls-modal').classList.add('open');
}

function closeModal(){document.getElementById('mls-modal').classList.remove('open');}

function saveMls(){
  const id = document.getElementById('f-id').value;
  const prods = ['f-prod-idx','f-prod-crm']
    .filter(k=>document.getElementById(k).checked)
    .map(k=>document.getElementById(k).value).join(',');
  const payload={
    action: id ? 'update' : 'add',
    id: id ? parseInt(id) : undefined,
    mls_name:    document.getElementById('f-name').value.trim(),
    mls_code:    document.getElementById('f-code').value.trim(),
    region:      document.getElementById('f-region').value.trim(),
    status:      document.getElementById('f-status').value,
    feed_type:   document.getElementById('f-feed-type').value,
    monthly_fee: parseFloat(document.getElementById('f-fee').value)||0,
    products:    prods,
    application_date: document.getElementById('f-app-date').value||null,
    approval_date:    document.getElementById('f-appr-date').value||null,
    go_live_date:     document.getElementById('f-live-date').value||null,
    agreement_url:    document.getElementById('f-agreement-url').value.trim()||null,
    contact_name:     document.getElementById('f-contact-name').value.trim(),
    contact_org:      document.getElementById('f-contact-org').value.trim(),
    contact_email:    document.getElementById('f-contact-email').value.trim(),
    contact_phone:    document.getElementById('f-contact-phone').value.trim(),
    api_base_url: document.getElementById('f-api-url').value.trim()||null,
    api_username: document.getElementById('f-api-user').value.trim(),
    api_secret:   document.getElementById('f-api-secret').value,
    api_key:      document.getElementById('f-api-key').value.trim(),
    notes:        document.getElementById('f-notes').value.trim(),
  };
  if(!payload.mls_name){alert('MLS Name is required.');return;}
  const btn=document.getElementById('modal-save-btn');
  btn.disabled=true; btn.textContent='Saving…';
  fetch('api/mls_action.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})
    .then(r=>r.json()).then(d=>{
      btn.disabled=false; btn.textContent='Save';
      if(d.ok){closeModal();load();}else alert(d.error||'Save failed.');
    }).catch(()=>{btn.disabled=false;btn.textContent='Save';alert('Request failed.');});
}

function deleteMls(){
  const id=parseInt(document.getElementById('f-id').value);
  if(!id)return;
  const r=allRows.find(x=>x.id===id);
  if(!confirm('Delete "'+((r&&r.mls_name)||'this MLS')+'"? This cannot be undone.'))return;
  fetch('api/mls_action.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',id})})
    .then(r=>r.json()).then(d=>{if(d.ok){closeModal();load();}else alert(d.error||'Delete failed.');});
}

load();
</script>
</body>
</html>
