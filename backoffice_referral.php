<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
$agent = require_login();
$perms = current_perms();
if (empty($perms['isSuperAdmin'])) {
    header('Location: index.php'); exit;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Referral — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .btn-primary{padding:8px 16px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer}
    .btn-primary:hover{background:#5b8e0d;color:#fff}
    .btn-ghost{background:white;border:1px solid #ccc;color:#555;padding:8px 14px;border-radius:6px;cursor:pointer;font-size:13px}
    .btn-ghost:hover{border-color:#82C112;color:#5b8e0d}
    .btn-sm{padding:4px 10px;font-size:11px;font-weight:700;border-radius:4px;border:1px solid #ddd;background:white;cursor:pointer;color:#333}
    .btn-sm:hover{border-color:#82C112;color:#5b8e0d}
    .btn-danger{background:#fee2e2;color:#c00;border-color:#f5c6c6}
    .btn-danger:hover{background:#fca5a5;border-color:#f87171}

    .ref-intro{font-size:13px;color:#666;margin-bottom:16px;line-height:1.5}
    .ref-table{width:100%;border-collapse:collapse;font-size:13px}
    .ref-table th{text-align:left;padding:8px 12px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;border-bottom:2px solid #f0f0f0}
    .ref-table td{padding:10px 12px;border-bottom:1px solid #f5f5f5;vertical-align:top}
    .ref-table tr:hover td{background:#fafafa}
    .ref-count{display:inline-block;background:#eef5e8;color:#5b8e0d;font-size:11px;font-weight:700;padding:1px 7px;border-radius:10px;margin-right:4px}
    .ref-empty-cell{color:#bbb;font-style:italic}
    .empty-note{color:#bbb;font-size:13px;padding:32px;text-align:center}

    .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:300;align-items:center;justify-content:center;padding:16px}
    .modal-overlay.open{display:flex}
    .modal{background:white;border-radius:12px;width:640px;max-width:98vw;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 12px 48px rgba(0,0,0,.2)}
    .modal-head{padding:20px 24px 0;display:flex;align-items:center;gap:12px}
    .modal-head h3{margin:0;font-size:16px;font-weight:800;flex:1}
    .modal-body{padding:20px 24px;overflow-y:auto;flex:1}
    .modal-foot{padding:16px 24px;border-top:1px solid #f0f0f0;display:flex;gap:8px;justify-content:flex-end}
    .modal-close{background:none;border:none;cursor:pointer;font-size:20px;color:#888;line-height:1;padding:0}
    .modal-close:hover{color:#333}

    .field{display:flex;flex-direction:column;gap:3px;margin-bottom:14px}
    .field label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888}
    .field .hint{font-size:11px;color:#999;font-weight:400;text-transform:none;letter-spacing:0}
    .field input,.field textarea{padding:7px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;font-family:inherit}
    .field input:focus,.field textarea:focus{outline:2px solid #82C112;outline-offset:-1px}
    .field textarea{resize:vertical;min-height:56px}
  </style>
</head>
<body>
<div class="layout">
<?php render_sidebar('bo_referral', $agent); ?>
<div class="content">
  <div class="content-top">
    <div>
      <div class="content-title">Referral</div>
    </div>
    <div class="content-hello">MLS coverage areas for the Agent Roster's referral filter</div>
  </div>
  <div class="wrap">
    <div class="card" style="padding:20px 24px">
      <p class="ref-intro">
        Each row is an MLS or board association INNOVATE belongs to. List the counties,
        cities, townships, and zip codes it covers, and agents whose MLS Board matches
        that association will surface when someone searches that location in the Agent
        Roster's referral filter — e.g. add "Mullins" as a city under CCAR and Pee Dee,
        and anyone with either as their MLS Board shows up for a "Mullins" search.
      </p>
      <div class="toolbar" style="margin-bottom:14px">
        <button class="btn-primary" onclick="openModal()">+ Add MLS / Association</button>
      </div>
      <div style="overflow-x:auto">
        <table class="ref-table">
          <thead>
            <tr>
              <th style="width:200px">MLS / Association</th>
              <th>Counties</th>
              <th>Cities</th>
              <th>Townships</th>
              <th>Zip Codes</th>
              <th style="width:70px"></th>
            </tr>
          </thead>
          <tbody id="ref-tbody"><tr><td colspan="6" class="empty-note">Loading…</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>
</div>
</div>

<!-- Add / Edit Modal -->
<div class="modal-overlay" id="ref-modal">
  <div class="modal">
    <div class="modal-head">
      <h3 id="modal-title">Add MLS / Association</h3>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="f-id">
      <div class="field">
        <label>MLS / Association Name</label>
        <input type="text" id="f-name" placeholder="e.g. CCAR, Pee Dee Realtor Association">
      </div>
      <div class="field">
        <label>Counties <span class="hint">— comma-separated</span></label>
        <textarea id="f-counties" placeholder="e.g. Horry, Georgetown"></textarea>
      </div>
      <div class="field">
        <label>Cities <span class="hint">— comma-separated</span></label>
        <textarea id="f-cities" placeholder="e.g. Myrtle Beach, Conway, Mullins"></textarea>
      </div>
      <div class="field">
        <label>Townships <span class="hint">— comma-separated</span></label>
        <textarea id="f-townships" placeholder="e.g. Little River Township"></textarea>
      </div>
      <div class="field">
        <label>Zip Codes <span class="hint">— comma-separated</span></label>
        <textarea id="f-zips" placeholder="e.g. 29526, 29527, 29572"></textarea>
      </div>
      <div class="field">
        <label>Notes <span class="hint">— optional</span></label>
        <textarea id="f-notes" placeholder="Anything worth flagging for whoever edits this next"></textarea>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn-danger btn-sm" id="modal-delete-btn" onclick="deleteRef()" style="margin-right:auto;display:none">Delete</button>
      <button class="btn-ghost" onclick="closeModal()">Cancel</button>
      <button class="btn-primary" onclick="saveRef()">Save</button>
    </div>
  </div>
</div>

<script>
let ALL_REF = [];

function esc(s) { return (s == null ? '' : String(s)).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }

function listCell(csv) {
  const v = (csv || '').trim();
  return v ? esc(v) : '<span class="ref-empty-cell">—</span>';
}

function render(rows) {
  const tbody = document.getElementById('ref-tbody');
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="6" class="empty-note">No MLS associations yet. Add one above.</td></tr>';
    return;
  }
  tbody.innerHTML = rows.map(r => `
    <tr>
      <td><strong>${esc(r.mls_name)}</strong></td>
      <td>${listCell(r.counties)}</td>
      <td>${listCell(r.cities)}</td>
      <td>${listCell(r.townships)}</td>
      <td>${listCell(r.zips)}</td>
      <td><button class="btn-sm" onclick="editRef(${r.id})">Edit</button></td>
    </tr>`).join('');
}

function fetchList() {
  return fetch('api/backoffice_referral.php', { credentials: 'same-origin' })
    .then(r => r.ok ? r.json() : Promise.reject(r.status))
    .then(d => { ALL_REF = d.items || []; render(ALL_REF); })
    .catch(() => { document.getElementById('ref-tbody').innerHTML = '<tr><td colspan="6" class="empty-note">Could not load the list.</td></tr>'; });
}

function openModal() {
  document.getElementById('modal-title').textContent = 'Add MLS / Association';
  document.getElementById('f-id').value = '';
  ['f-name','f-counties','f-cities','f-townships','f-zips','f-notes'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('modal-delete-btn').style.display = 'none';
  document.getElementById('ref-modal').classList.add('open');
}

function editRef(id) {
  const r = ALL_REF.find(x => x.id === id);
  if (!r) return;
  document.getElementById('modal-title').textContent = 'Edit MLS / Association';
  document.getElementById('f-id').value = r.id;
  document.getElementById('f-name').value = r.mls_name || '';
  document.getElementById('f-counties').value = r.counties || '';
  document.getElementById('f-cities').value = r.cities || '';
  document.getElementById('f-townships').value = r.townships || '';
  document.getElementById('f-zips').value = r.zips || '';
  document.getElementById('f-notes').value = r.notes || '';
  document.getElementById('modal-delete-btn').style.display = 'inline-block';
  document.getElementById('ref-modal').classList.add('open');
}

function closeModal() {
  document.getElementById('ref-modal').classList.remove('open');
}

function saveRef() {
  const id = document.getElementById('f-id').value;
  const name = document.getElementById('f-name').value.trim();
  if (!name) return alert('MLS / Association name is required.');
  const payload = {
    action: id ? 'update' : 'add',
    mls_name: name,
    counties: document.getElementById('f-counties').value.trim(),
    cities: document.getElementById('f-cities').value.trim(),
    townships: document.getElementById('f-townships').value.trim(),
    zips: document.getElementById('f-zips').value.trim(),
    notes: document.getElementById('f-notes').value.trim(),
  };
  if (id) payload.id = Number(id);
  fetch('api/backoffice_referral.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  .then(r => r.json())
  .then(d => {
    if (!d.ok) return alert(d.error || 'Could not save.');
    closeModal();
    fetchList();
  })
  .catch(() => alert('Could not save — please try again.'));
}

function deleteRef() {
  const id = document.getElementById('f-id').value;
  if (!id) return;
  if (!confirm('Remove this MLS / association and its coverage areas?')) return;
  fetch('api/backoffice_referral.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'delete', id: Number(id) }),
  })
  .then(r => r.json())
  .then(d => {
    if (!d.ok) return alert(d.error || 'Could not delete.');
    closeModal();
    fetchList();
  })
  .catch(() => alert('Could not delete — please try again.'));
}

document.getElementById('ref-modal').addEventListener('click', e => {
  if (e.target === document.getElementById('ref-modal')) closeModal();
});

fetchList();
</script>
</body>
</html>
