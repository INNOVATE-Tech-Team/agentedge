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
  <title>MLS — AgentEdge</title>
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

    /* Tabs */
    .mls-tabs{display:flex;gap:4px;border-bottom:2px solid #f0f0f0;margin-bottom:20px}
    .mls-tab{padding:10px 4px;margin-bottom:-2px;background:none;border:none;border-bottom:2px solid transparent;font-size:13px;font-weight:700;color:#888;cursor:pointer}
    .mls-tab+.mls-tab{margin-left:16px}
    .mls-tab:hover{color:#333}
    .mls-tab.active{color:#5b8e0d;border-bottom-color:#82C112}
    .tab-panel{display:none}
    .tab-panel.active{display:block}

    /* Offices table extras */
    .badge-exp-ok{background:#d1fae5;color:#065f46}
    .badge-exp-soon{background:#fff7ed;color:#9a3412}
    .badge-exp-over{background:#fee2e2;color:#991b1b}
    .badge-exp-none{background:#f3f4f6;color:#9ca3af}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('bo_mls', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">MLS</div>
    </header>
    <main class="wrap">
      <div class="card" style="padding:20px 24px">

        <div class="mls-tabs">
          <button class="mls-tab active" id="tab-btn-vendors" onclick="switchTab('vendors')">Vendor Integrations</button>
          <button class="mls-tab" id="tab-btn-offices" onclick="switchTab('offices')">State Offices &amp; Licenses</button>
          <button class="mls-tab" id="tab-btn-board" onclick="switchTab('board')">Board Memberships</button>
          <button class="mls-tab" id="tab-btn-mls" onclick="switchTab('mls')">MLS Memberships</button>
        </div>

        <!-- ═══ Vendor Integrations tab ═══ -->
        <div class="tab-panel active" id="tab-vendors">

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

        <!-- ═══ State Offices & Licenses tab ═══ -->
        <div class="tab-panel" id="tab-offices">

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

        <!-- ═══ Board Memberships tab ═══ -->
        <div class="tab-panel" id="tab-board">

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

/* ══════════════ Tabs ══════════════ */
let officesLoaded = false;
let membershipsLoaded = false;
function switchTab(name){
  ['vendors','offices','board','mls'].forEach(n=>{
    document.getElementById('tab-'+n).classList.toggle('active', n===name);
    document.getElementById('tab-btn-'+n).classList.toggle('active', n===name);
  });
  if(name==='offices' && !officesLoaded){ officesLoaded = true; loadOffices(); }
  if((name==='board'||name==='mls') && !membershipsLoaded){ membershipsLoaded = true; loadMemberships(); }
}

function togglePwd(id, btn){
  const el = document.getElementById(id);
  if(el.type === 'password'){ el.type = 'text'; btn.textContent = 'Hide'; }
  else { el.type = 'password'; btn.textContent = 'Show'; }
}

/* ══════════════ State Offices & Licenses ══════════════ */
let officeRows = [];
let viewOfficeId = null;

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
    return `<tr onclick="viewOffice(${r.id})" style="cursor:pointer">
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

function viewOffice(id){
  openOfficeModal(id);
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

/* ══════════════ Board & MLS Memberships ══════════════ */
let membershipRows = [];

function loadMemberships(){
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
      if(d.ok){closeMembershipModal();loadMemberships();}else alert(d.error||'Save failed.');
    }).catch(()=>{btn.disabled=false;btn.textContent='Save';alert('Request failed.');});
}

function deleteMembership(){
  const id=parseInt(document.getElementById('m-id').value);
  if(!id)return;
  const r=membershipRows.find(x=>x.id===id);
  if(!confirm('Delete the membership "'+((r&&r.name)||'')+'"? This cannot be undone.'))return;
  fetch('api/mls_memberships_action.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',id})})
    .then(r=>r.json()).then(d=>{if(d.ok){closeMembershipModal();loadMemberships();}else alert(d.error||'Delete failed.');});
}

load();
</script>
</body>
</html>
