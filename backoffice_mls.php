<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
$agent = require_login();
if (!is_leader()) { header('Location: index.php'); exit; }
$superAdmin = is_super_admin();

// Staff/admin pool for the "tag a teammate" picker on the Activity modal —
// same pool used by admin_step_notify.php's staff assignment.
$staffList = local_db()->query(
    "SELECT email FROM agent_roles WHERE role IN ('super_admin','staff') ORDER BY email"
)->fetchAll(PDO::FETCH_COLUMN);
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

    /* Notes button + hover preview */
    .notes-actions{display:flex;gap:6px;align-items:center;flex-wrap:nowrap}
    .notes-btn[data-note]:not([data-note=""]){border-color:#c3dfa8;color:#5b8e0d}
    #notes-tip{
      position:fixed;display:none;max-width:280px;white-space:pre-wrap;
      background:#222;color:#fff;font-size:11px;font-weight:500;
      padding:8px 10px;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.25);
      z-index:9999;line-height:1.4;pointer-events:none;
    }
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
                <th>Feed Source</th>
                <th>Feed Type</th>
                <th>Status</th>
                <th>Monthly Fee</th>
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
          <div class="field"><label>Feed Source</label>
            <select id="f-feed-source">
              <option value="RETS">RETS</option>
              <option value="OIDH">OIDH / Bridge Interactive</option>
              <option value="Trestle">Trestle (CoreLogic)</option>
              <option value="Spark">Spark Platform</option>
              <option value="Bridge">Bridge</option>
              <option value="Self">Self</option>
            </select>
          </div>
          <div class="field"><label>Monthly Fee ($)</label>
            <div class="field-row" style="display:flex;gap:6px">
              <input type="number" id="f-fee" min="0" step="0.01" placeholder="0.00" style="flex:1">
              <button type="button" class="btn-sm" title="Recalculate from Office/Broker/Admin fees below" onclick="recalcMonthlyFee()">↻</button>
            </div>
          </div>
        </div>
        <div style="margin-top:10px">
          <div class="field"><label>Feed Type</label>
            <div class="check-group">
              <label class="check-item"><input type="checkbox" id="f-feed-bbo"> BBO</label>
              <label class="check-item"><input type="checkbox" id="f-feed-idx"> IDX</label>
            </div>
          </div>
        </div>
        <div style="margin-top:10px">
          <div class="field"><label>Products Using This MLS</label>
            <div class="check-group">
              <label class="check-item"><input type="checkbox" id="f-prod-idx" value="idx"> Website</label>
              <label class="check-item"><input type="checkbox" id="f-prod-crm" value="crm"> Advantage</label>
            </div>
          </div>
        </div>
      </div>

      <!-- Membership / Login -->
      <div class="form-section">
        <div class="form-section-title">Membership / Login</div>
        <div class="field-grid cols-3">
          <div class="field"><label>Board / MLS</label>
            <select id="f-board-or-mls">
              <option value="">—</option>
              <option value="Board">Board</option>
              <option value="MLS">MLS</option>
              <option value="Board & MLS">Board &amp; MLS</option>
            </select>
          </div>
          <div class="field"><label>Membership Type</label><input type="text" id="f-membership-type" placeholder="e.g. MLS, Matrix, Primary (Board)"></div>
          <div class="field"><label>Office ID</label><input type="text" id="f-office-id"></div>
          <div class="field"><label>Broker of Record</label><input type="text" id="f-broker-of-record"></div>
          <div class="field"><label>Login Username</label><input type="text" id="f-login-username" autocomplete="off"></div>
          <div class="field"><label>Login Password</label><input type="password" id="f-login-password" autocomplete="new-password"></div>
        </div>
        <div class="field-grid" style="margin-top:10px">
          <div class="field field-full"><label>Address</label><input type="text" id="f-mm-address"></div>
          <div class="field"><label>Phone</label><input type="text" id="f-mm-phone"></div>
          <div class="field"><label>Login Link</label><input type="text" id="f-login-link" placeholder="URL or portal name"></div>
        </div>
        <div class="field-grid cols-3" style="margin-top:10px">
          <div class="field"><label>Office Fee</label><input type="text" id="f-office-fees" placeholder="e.g. $313.13/quarter" oninput="recalcMonthlyFee()"></div>
          <div class="field"><label>Broker Fee</label><input type="text" id="f-broker-fees" placeholder="e.g. $75/quarter" oninput="recalcMonthlyFee()"></div>
          <div class="field"><label>Admin Fee</label><input type="text" id="f-admin-fees" placeholder="e.g. $30/quarterly" oninput="recalcMonthlyFee()"></div>
        </div>
        <div style="font-size:11px;color:#999;margin-top:4px">Monthly Fee above auto-recalculates from these three as you type (converts quarterly/yearly/weekly amounts to a monthly equivalent) — edit Monthly Fee directly afterward to override.</div>
      </div>

      <!-- Billing -->
      <div class="form-section">
        <div class="form-section-title">Billing</div>
        <div class="field-grid">
          <div class="field field-full"><label>Billing Site</label><input type="text" id="f-billing-site" placeholder="URL"></div>
          <div class="field"><label>Billing Frequency</label><input type="text" id="f-billing-frequency" placeholder="e.g. quarterly"></div>
          <div class="field"><label>Billing Username</label><input type="text" id="f-billing-username" autocomplete="off"></div>
          <div class="field"><label>Billing Password</label><input type="password" id="f-billing-password" autocomplete="new-password"></div>
        </div>
      </div>

      <!-- Timeline -->
      <div class="form-section">
        <div class="form-section-title">Timeline</div>
        <div class="field-grid">
          <div class="field"><label>Application Date</label><input type="date" id="f-app-date"></div>
          <div class="field"><label>Approval Date</label><input type="date" id="f-appr-date"></div>
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

<!-- Notes Modal -->
<div class="modal-overlay" id="notes-modal">
  <div class="modal" style="width:480px">
    <div class="modal-head">
      <h3 id="notes-modal-title">Notes</h3>
      <button class="modal-close" onclick="closeNotesModal()">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="n-id">
      <textarea id="n-notes" rows="8" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:13px;font-family:inherit;resize:vertical" placeholder="No notes yet."></textarea>
    </div>
    <div class="modal-foot">
      <button class="btn-ghost" onclick="closeNotesModal()">Cancel</button>
      <button class="btn-primary" id="notes-save-btn" onclick="saveNotes()">Save</button>
    </div>
  </div>
</div>

<!-- Activity Modal: tag-a-teammate notes thread + agreement document uploads.
     Deliberately separate IDs/functions from the Notes modal above (which
     edits the single mls_integrations.notes field) — this is a running
     thread (mls_notes table) plus file storage (mls_agreements table). -->
<div class="modal-overlay" id="mls-activity-modal">
  <div class="modal">
    <div class="modal-head">
      <h3 id="activity-modal-title">Activity</h3>
      <button class="modal-close" onclick="closeActivityModal()">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-section">
        <div class="form-section-title">Add a Note</div>
        <div class="field field-full"><textarea id="activity-note-text" rows="2" placeholder="Status update, contact response, next step…"></textarea></div>
        <div class="field-grid" style="margin-top:8px">
          <div class="field"><label>Tag a Teammate (optional)</label>
            <select id="activity-note-tag">
              <option value="">— No tag —</option>
              <?php foreach ($staffList as $email): ?>
              <option value="<?= htmlspecialchars($email, ENT_QUOTES) ?>"><?= htmlspecialchars($email, ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" style="justify-content:flex-end">
            <button class="btn-primary" id="activity-note-post-btn" onclick="postActivityNote()">Post Note</button>
          </div>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-title">Activity</div>
        <div id="activity-notes-list" style="display:flex;flex-direction:column;gap:10px"></div>
      </div>

      <div class="form-section">
        <div class="form-section-title">Agreements</div>
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
          <input type="file" id="activity-agreement-file" style="font-size:13px">
          <button class="btn-ghost" id="activity-agreement-upload-btn" onclick="uploadActivityAgreement()">Upload</button>
        </div>
        <div id="activity-agreements-list" style="display:flex;flex-direction:column;gap:6px"></div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn-primary" onclick="closeActivityModal()">Close</button>
    </div>
  </div>
</div>

<script>
const SUPER = <?= $superAdmin ? 'true' : 'false' ?>;

function esc(s){return String(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
function fmt(d){if(!d)return'—';const p=d.split('-');return p[1]+'/'+p[2]+'/'+p[0];}
function fmtFee(v){if(!v&&v!==0)return'—';return'$'+Number(v).toLocaleString('en-US',{minimumFractionDigits:0,maximumFractionDigits:0})+'/mo';}

const STATUS_LABELS={researching:'Researching',applied:'Applied',approved:'Approved',active:'Active',paused:'Paused',rejected:'Rejected'};
const FEED_SOURCE_LABELS={RETS:'RETS',OIDH:'OIDH/Bridge',Trestle:'Trestle',Spark:'Spark',Bridge:'Bridge',Self:'Self'};
const PROD_LABELS={idx:'Website',crm:'Advantage'};

// Parses free-text fee strings like "$313.13/quarter", "$75/quarter", "$30/quarterly",
// "$10/monthly", "$120/year", "None" into a monthly-equivalent dollar amount.
// Unrecognized frequency defaults to treating the number as already monthly.
function parseMonthlyEquivalent(str){
  if(!str) return 0;
  const s=String(str).toLowerCase();
  const numMatch=s.match(/[\d,]+\.?\d*/);
  if(!numMatch) return 0;
  const amount=parseFloat(numMatch[0].replace(/,/g,''));
  if(!isFinite(amount)) return 0;
  if(/year|annual/.test(s)) return amount/12;
  if(/quarter/.test(s)) return amount/3;
  if(/week/.test(s)) return amount*52/12;
  return amount; // month/monthly, or no unit given
}

function recalcMonthlyFee(){
  const total = parseMonthlyEquivalent(document.getElementById('f-office-fees').value)
              + parseMonthlyEquivalent(document.getElementById('f-broker-fees').value)
              + parseMonthlyEquivalent(document.getElementById('f-admin-fees').value);
  document.getElementById('f-fee').value = total ? Math.round(total*100)/100 : '';
}

function feedTypeLabel(r){
  const parts=[];
  if(r.feed_bbo==1||r.feed_bbo===true) parts.push('BBO');
  if(r.feed_idx==1||r.feed_idx===true) parts.push('IDX');
  return parts.join(', ')||'—';
}

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
      <td><strong>${esc(r.mls_name)}</strong>${(r.membership_type||r.office_id||r.broker_of_record)?`<div style="font-size:10px;color:#999;margin-top:2px">${esc([r.membership_type,r.office_id?('#'+r.office_id):'',r.broker_of_record].filter(Boolean).join(' · '))}</div>`:''}</td>
      <td><code style="font-size:11px;background:#f3f4f6;padding:2px 5px;border-radius:3px">${esc(r.mls_code||'—')}</code></td>
      <td style="color:#555">${esc(r.region||'—')}</td>
      <td style="color:#555">${esc(FEED_SOURCE_LABELS[r.feed_type]||r.feed_type||'—')}</td>
      <td style="color:#555">${feedTypeLabel(r)}</td>
      <td><span class="badge badge-${esc(r.status)}">${esc(STATUS_LABELS[r.status]||r.status)}</span></td>
      <td class="fee-val">${fmtFee(r.monthly_fee)}</td>
      <td style="font-size:11px;color:#777">${esc(prods)}</td>
      <td onclick="event.stopPropagation()">
        <div class="notes-actions">
          <button class="btn-sm notes-btn" data-note="${esc(r.notes||'')}" onclick="openNotesModal(${r.id})">Notes</button>
          <button class="btn-sm" onclick="openActivityModal(${r.id})">Activity</button>
          ${SUPER?`<button class="btn-sm" onclick="openModal(${r.id})">Edit</button>`:''}
        </div>
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
        <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px">Feed Source</div>
        <div>${esc(FEED_SOURCE_LABELS[r.feed_type]||r.feed_type||'—')}</div>
      </div>
      <div>
        <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px">Feed Type</div>
        <div>${feedTypeLabel(r)}</div>
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
    ${(r.membership_type||r.office_id||r.broker_of_record||r.login_username||r.office_fees||r.broker_fees||r.admin_fees||r.address||r.phone||r.login_link||r.board_or_mls)?`
    <hr style="border:none;border-top:1px solid #f0f0f0;margin:16px 0">
    <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:8px">Membership / Login</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13px;margin-bottom:8px">
      <div><span style="color:#888">Board / MLS:</span> ${esc(r.board_or_mls||'—')}</div>
      <div><span style="color:#888">Membership Type:</span> ${esc(r.membership_type||'—')}</div>
      <div><span style="color:#888">Office ID:</span> ${esc(r.office_id||'—')}</div>
      <div><span style="color:#888">Broker of Record:</span> ${esc(r.broker_of_record||'—')}</div>
      <div><span style="color:#888">Phone:</span> ${esc(r.phone||'—')}</div>
      <div><span style="color:#888">Address:</span> ${esc(r.address||'—')}</div>
      <div><span style="color:#888">Login Link:</span> ${r.login_link?(/^https?:\/\//i.test(r.login_link)?`<a href="${esc(r.login_link)}" target="_blank" style="color:#5b8e0d">Open ↗</a>`:esc(r.login_link)):'—'}</div>
      <div><span style="color:#888">Office/Broker/Admin Fees:</span> ${esc([r.office_fees,r.broker_fees,r.admin_fees].filter(Boolean).join(' · ')||'—')}</div>
    </div>
    ${SUPER?`
    <div style="display:flex;flex-direction:column;gap:8px">
      ${r.login_username?`<div style="display:flex;gap:8px;align-items:center"><span style="font-size:11px;color:#888;min-width:90px">Login Username</span><div class="cred-val">${esc(r.login_username)}</div></div>`:''}
      ${r.login_password?`<div style="display:flex;gap:8px;align-items:center"><span style="font-size:11px;color:#888;min-width:90px">Login Password</span><div class="cred-val" id="vs-loginpw">••••••••</div><button class="cred-reveal" onclick="toggleCred('vs-loginpw','${esc(r.login_password).replace(/'/g,"\\'")}')">Reveal</button></div>`:''}
      ${r.billing_site?`<div style="display:flex;gap:8px;align-items:center"><span style="font-size:11px;color:#888;min-width:90px">Billing Site</span><div class="cred-val">${esc(r.billing_site)}</div></div>`:''}
      ${r.billing_frequency?`<div style="display:flex;gap:8px;align-items:center"><span style="font-size:11px;color:#888;min-width:90px">Billing Freq.</span><div class="cred-val">${esc(r.billing_frequency)}</div></div>`:''}
      ${r.billing_username?`<div style="display:flex;gap:8px;align-items:center"><span style="font-size:11px;color:#888;min-width:90px">Billing User</span><div class="cred-val">${esc(r.billing_username)}</div></div>`:''}
      ${r.billing_password?`<div style="display:flex;gap:8px;align-items:center"><span style="font-size:11px;color:#888;min-width:90px">Billing Pass</span><div class="cred-val" id="vs-billingpw">••••••••</div><button class="cred-reveal" onclick="toggleCred('vs-billingpw','${esc(r.billing_password).replace(/'/g,"\\'")}')">Reveal</button></div>`:''}
    </div>`:''}`:''}
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
  ['f-id','f-name','f-code','f-region','f-fee','f-app-date','f-appr-date',
   'f-agreement-url','f-contact-name','f-contact-org','f-contact-email','f-contact-phone',
   'f-api-url','f-api-user','f-api-secret','f-api-key','f-notes',
   'f-membership-type','f-office-id','f-broker-of-record','f-login-username','f-login-password',
   'f-office-fees','f-broker-fees','f-admin-fees',
   'f-mm-address','f-mm-phone','f-login-link',
   'f-billing-site','f-billing-frequency','f-billing-username','f-billing-password'].forEach(k=>{
    const el=document.getElementById(k);
    if(el)el.value='';
  });
  document.getElementById('f-status').value='researching';
  document.getElementById('f-feed-source').value='RETS';
  document.getElementById('f-board-or-mls').value='';
  ['f-prod-idx','f-prod-crm','f-feed-bbo','f-feed-idx'].forEach(k=>document.getElementById(k).checked=false);

  if(editing){
    const r=allRows.find(x=>x.id===id);
    if(!r)return;
    document.getElementById('f-id').value=r.id;
    document.getElementById('f-name').value=r.mls_name||'';
    document.getElementById('f-code').value=r.mls_code||'';
    document.getElementById('f-region').value=r.region||'';
    document.getElementById('f-status').value=r.status||'researching';
    document.getElementById('f-feed-source').value=r.feed_type||'RETS';
    document.getElementById('f-feed-bbo').checked=(r.feed_bbo==1||r.feed_bbo===true);
    document.getElementById('f-feed-idx').checked=(r.feed_idx==1||r.feed_idx===true);
    document.getElementById('f-fee').value=r.monthly_fee||'';
    document.getElementById('f-app-date').value=r.application_date||'';
    document.getElementById('f-appr-date').value=r.approval_date||'';
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
    document.getElementById('f-membership-type').value=r.membership_type||'';
    document.getElementById('f-office-id').value=r.office_id||'';
    document.getElementById('f-broker-of-record').value=r.broker_of_record||'';
    document.getElementById('f-login-username').value=r.login_username||'';
    document.getElementById('f-login-password').value=r.login_password||'';
    document.getElementById('f-office-fees').value=r.office_fees||'';
    document.getElementById('f-broker-fees').value=r.broker_fees||'';
    document.getElementById('f-admin-fees').value=r.admin_fees||'';
    document.getElementById('f-board-or-mls').value=r.board_or_mls||'';
    document.getElementById('f-mm-address').value=r.address||'';
    document.getElementById('f-mm-phone').value=r.phone||'';
    document.getElementById('f-login-link').value=r.login_link||'';
    document.getElementById('f-billing-site').value=r.billing_site||'';
    document.getElementById('f-billing-frequency').value=r.billing_frequency||'';
    document.getElementById('f-billing-username').value=r.billing_username||'';
    document.getElementById('f-billing-password').value=r.billing_password||'';
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
    feed_type:   document.getElementById('f-feed-source').value,
    feed_bbo:    document.getElementById('f-feed-bbo').checked,
    feed_idx:    document.getElementById('f-feed-idx').checked,
    monthly_fee: parseFloat(document.getElementById('f-fee').value)||0,
    products:    prods,
    application_date: document.getElementById('f-app-date').value||null,
    approval_date:    document.getElementById('f-appr-date').value||null,
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
    membership_type:  document.getElementById('f-membership-type').value.trim(),
    office_id:        document.getElementById('f-office-id').value.trim(),
    broker_of_record: document.getElementById('f-broker-of-record').value.trim(),
    login_username:   document.getElementById('f-login-username').value.trim(),
    login_password:   document.getElementById('f-login-password').value,
    office_fees:  document.getElementById('f-office-fees').value.trim(),
    broker_fees:  document.getElementById('f-broker-fees').value.trim(),
    admin_fees:   document.getElementById('f-admin-fees').value.trim(),
    board_or_mls: document.getElementById('f-board-or-mls').value,
    address: document.getElementById('f-mm-address').value.trim(),
    phone:   document.getElementById('f-mm-phone').value.trim(),
    login_link: document.getElementById('f-login-link').value.trim(),
    billing_site:      document.getElementById('f-billing-site').value.trim(),
    billing_frequency: document.getElementById('f-billing-frequency').value.trim(),
    billing_username:  document.getElementById('f-billing-username').value.trim(),
    billing_password:  document.getElementById('f-billing-password').value,
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

function openNotesModal(id){
  const r=allRows.find(x=>x.id===id);
  if(!r)return;
  document.getElementById('notes-modal-title').textContent = r.mls_name + ' — Notes';
  document.getElementById('n-id').value=r.id;
  document.getElementById('n-notes').value=r.notes||'';
  document.getElementById('n-notes').disabled=!SUPER;
  document.getElementById('notes-save-btn').style.display=SUPER?'':'none';
  document.getElementById('notes-modal').classList.add('open');
}

function closeNotesModal(){document.getElementById('notes-modal').classList.remove('open');}

function saveNotes(){
  const id=parseInt(document.getElementById('n-id').value);
  const notes=document.getElementById('n-notes').value;
  const btn=document.getElementById('notes-save-btn');
  btn.disabled=true; btn.textContent='Saving…';
  fetch('api/mls_action.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'update_notes',id,notes})})
    .then(r=>r.json()).then(d=>{
      btn.disabled=false; btn.textContent='Save';
      if(d.ok){closeNotesModal();load();}else alert(d.error||'Save failed.');
    }).catch(()=>{btn.disabled=false;btn.textContent='Save';alert('Request failed.');});
}

// ── Activity modal: tag-a-teammate notes thread + agreement uploads ────────
let activityMlsId = null;

function activityFmtDT(s){
  if(!s) return '—';
  // SQLite datetime('now') is UTC without a timezone suffix — append one so
  // the browser doesn't parse it as local time and skew every timestamp.
  const d = new Date(s.replace(' ', 'T') + 'Z');
  if (isNaN(d)) return esc(s);
  return d.toLocaleString('en-US', {month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'2-digit'});
}

function activityFmtBytes(n){
  n = Number(n) || 0;
  if (n < 1024) return n + ' B';
  if (n < 1048576) return (n/1024).toFixed(1) + ' KB';
  if (n < 1073741824) return (n/1048576).toFixed(1) + ' MB';
  return (n/1073741824).toFixed(2) + ' GB';
}

function openActivityModal(id){
  activityMlsId = id;
  const r = allRows.find(x=>x.id===id);
  document.getElementById('activity-modal-title').textContent = (r?r.mls_name:'MLS') + ' — Activity';
  document.getElementById('activity-note-text').value = '';
  document.getElementById('activity-note-tag').value = '';
  document.getElementById('activity-agreement-file').value = '';
  document.getElementById('mls-activity-modal').classList.add('open');
  loadActivityNotes();
  loadActivityAgreements();
}

function closeActivityModal(){
  document.getElementById('mls-activity-modal').classList.remove('open');
  activityMlsId = null;
}

function loadActivityNotes(){
  const list = document.getElementById('activity-notes-list');
  list.innerHTML = '<div class="empty-note" style="padding:12px">Loading…</div>';
  fetch('api/mls_notes.php?mls_id='+activityMlsId, {credentials:'same-origin'})
    .then(r=>r.json()).then(d=>{
      if(!d.ok){ list.innerHTML = '<div class="empty-note" style="padding:12px">'+esc(d.error||'Failed to load')+'</div>'; return; }
      if(!d.notes.length){ list.innerHTML = '<div class="empty-note" style="padding:12px">No notes yet.</div>'; return; }
      list.innerHTML = d.notes.map(n=>`
        <div style="border:1px solid #f0f0f0;border-radius:8px;padding:10px 12px">
          <div style="font-size:13px;color:#333;white-space:pre-wrap">${esc(n.note)}</div>
          <div style="margin-top:6px;font-size:11px;color:#999">
            ${esc(n.created_by||'—')} · ${activityFmtDT(n.created_at)}
            ${n.tagged_email?` · <span style="color:#5b8e0d;font-weight:700">tagged ${esc(n.tagged_email)}</span>`:''}
          </div>
        </div>`).join('');
    }).catch(()=>{ list.innerHTML = '<div class="empty-note" style="padding:12px">Request failed.</div>'; });
}

function postActivityNote(){
  const note = document.getElementById('activity-note-text').value.trim();
  if(!note){ alert('Enter a note first.'); return; }
  const tagged_email = document.getElementById('activity-note-tag').value;
  const btn = document.getElementById('activity-note-post-btn');
  btn.disabled = true; btn.textContent = 'Posting…';
  fetch('api/mls_notes.php', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({mls_id: activityMlsId, note, tagged_email})})
    .then(r=>r.json()).then(d=>{
      btn.disabled = false; btn.textContent = 'Post Note';
      if(d.ok){ document.getElementById('activity-note-text').value=''; document.getElementById('activity-note-tag').value=''; loadActivityNotes(); }
      else alert(d.error || 'Failed to post note.');
    }).catch(()=>{ btn.disabled=false; btn.textContent='Post Note'; alert('Request failed.'); });
}

function loadActivityAgreements(){
  const list = document.getElementById('activity-agreements-list');
  list.innerHTML = '<div class="empty-note" style="padding:12px">Loading…</div>';
  fetch('api/mls_agreements.php?mls_id='+activityMlsId, {credentials:'same-origin'})
    .then(r=>r.json()).then(d=>{
      if(!d.ok){ list.innerHTML = '<div class="empty-note" style="padding:12px">'+esc(d.error||'Failed to load')+'</div>'; return; }
      if(!d.files.length){ list.innerHTML = '<div class="empty-note" style="padding:12px">No agreements uploaded yet.</div>'; return; }
      list.innerHTML = d.files.map(f=>`
        <div style="display:flex;align-items:center;gap:10px;border:1px solid #f0f0f0;border-radius:8px;padding:8px 12px">
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:600;color:#333;overflow:hidden;text-overflow:ellipsis">${esc(f.name)}</div>
            <div style="font-size:11px;color:#999">${activityFmtBytes(f.size_bytes)} · ${esc(f.uploaded_by||'—')} · ${activityFmtDT(f.created_at)}</div>
          </div>
          <button class="btn-sm" onclick="downloadActivityAgreement('${esc(f.id)}')">Download</button>
          <button class="btn-sm btn-danger" onclick="deleteActivityAgreement('${esc(f.id)}')">Delete</button>
        </div>`).join('');
    }).catch(()=>{ list.innerHTML = '<div class="empty-note" style="padding:12px">Request failed.</div>'; });
}

function uploadActivityAgreement(){
  const input = document.getElementById('activity-agreement-file');
  if(!input.files.length){ alert('Choose a file first.'); return; }
  const fd = new FormData();
  fd.append('mls_id', activityMlsId);
  fd.append('file', input.files[0]);
  const btn = document.getElementById('activity-agreement-upload-btn');
  btn.disabled = true; btn.textContent = 'Uploading…';
  fetch('api/mls_agreement_upload.php', {method:'POST', credentials:'same-origin', body: fd})
    .then(r=>r.json()).then(d=>{
      btn.disabled = false; btn.textContent = 'Upload';
      if(d.ok){ input.value=''; loadActivityAgreements(); }
      else alert(d.error || 'Upload failed.');
    }).catch(()=>{ btn.disabled=false; btn.textContent='Upload'; alert('Request failed.'); });
}

function downloadActivityAgreement(id){
  fetch('api/mls_agreement_download.php?id='+encodeURIComponent(id), {credentials:'same-origin'})
    .then(r=>r.json()).then(d=>{
      if(d.url) window.open(d.url, '_blank');
      else alert(d.error || 'Download failed.');
    }).catch(()=>alert('Request failed.'));
}

function deleteActivityAgreement(id){
  if(!confirm('Delete this agreement file? This cannot be undone.')) return;
  fetch('api/mls_agreements.php', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'delete', id})})
    .then(r=>r.json()).then(d=>{
      if(d.ok) loadActivityAgreements();
      else alert(d.error || 'Delete failed.');
    }).catch(()=>alert('Request failed.'));
}

function deleteMls(){
  const id=parseInt(document.getElementById('f-id').value);
  if(!id)return;
  const r=allRows.find(x=>x.id===id);
  if(!confirm('Delete "'+((r&&r.mls_name)||'this MLS')+'"? This cannot be undone.'))return;
  fetch('api/mls_action.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',id})})
    .then(r=>r.json()).then(d=>{if(d.ok){closeModal();load();}else alert(d.error||'Delete failed.');});
}

/* Shared hover preview for .notes-btn — fixed-position so it's never clipped
   by the table's scrolling wrapper (an ancestor with overflow-x:auto also
   clips vertical overflow per the CSS overflow spec). */
const notesTip=document.createElement('div');
notesTip.id='notes-tip';
document.body.appendChild(notesTip);
document.addEventListener('mouseover',e=>{
  const btn=e.target.closest && e.target.closest('.notes-btn');
  if(!btn||!btn.dataset.note)return;
  notesTip.textContent=btn.dataset.note;
  notesTip.style.display='block';
  const r=btn.getBoundingClientRect();
  let top=r.top-notesTip.offsetHeight-8;
  if(top<4) top=r.bottom+8;
  let left=Math.min(r.left, window.innerWidth-notesTip.offsetWidth-8);
  if(left<4) left=4;
  notesTip.style.top=top+'px';
  notesTip.style.left=left+'px';
});
document.addEventListener('mouseout',e=>{
  const btn=e.target.closest && e.target.closest('.notes-btn');
  if(btn) notesTip.style.display='none';
});

load();
</script>
</body>
</html>
