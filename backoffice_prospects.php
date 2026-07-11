<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/geocode.php';

$agent = require_login();
if (!is_super_admin()) { header('Location: index.php'); exit; }

$db = local_db();

$statusLabels = [
    'new'            => 'New',
    'contacted'      => 'Contacted',
    'interested'     => 'Interested',
    'not_interested' => 'Not Interested',
    'signed'         => 'Signed',
];

$prospects = $db->query("SELECT * FROM recruit_prospects ORDER BY updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$mcs       = $db->query("SELECT name, lat, lng FROM market_centers WHERE enabled=1 AND lat IS NOT NULL AND lng IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);

// Nearest-MC lookup, computed once per prospect against every geocoded, enabled MC.
function nearest_mc(array $prospect, array $mcs): ?array {
    if ($prospect['lat'] === null || $prospect['lng'] === null || !$mcs) return null;
    $best = null;
    foreach ($mcs as $mc) {
        $miles = haversine_miles((float)$prospect['lat'], (float)$prospect['lng'], (float)$mc['lat'], (float)$mc['lng']);
        if ($best === null || $miles < $best['miles']) {
            $best = ['name' => $mc['name'], 'miles' => $miles];
        }
    }
    return $best;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Recruiting Prospects — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .add-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:20px 24px;margin-bottom:24px}
    .add-card h3{margin:0 0 14px;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
    .add-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px}
    .field-group{display:flex;flex-direction:column;gap:4px}
    .field-group.grow{flex:1;min-width:180px}
    .field-group.sm{min-width:70px;width:70px}
    .field-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint)}
    .field-input,.field-select,.field-textarea{padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;width:100%;box-sizing:border-box;background:#fff}
    .field-input:focus,.field-select:focus,.field-textarea:focus{outline:2px solid var(--green);border-color:var(--green)}
    .field-textarea{resize:vertical;min-height:38px;font-family:inherit}
    .btn-add{padding:9px 20px;background:var(--green);color:#111;font-weight:800;font-size:13px;border:0;border-radius:6px;cursor:pointer;white-space:nowrap;align-self:flex-end}
    .btn-add:hover{background:var(--green-d,#5b8e0d);color:#fff}

    .p-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;
              border:1px solid var(--border);border-radius:10px;overflow:hidden}
    .p-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
                 color:var(--faint);padding:10px 14px;text-align:left;border-bottom:1px solid var(--border)}
    .p-table td{padding:9px 14px;border-top:1px solid var(--border);vertical-align:middle}
    .p-table tr.edit-row td{padding:0;background:#f9fdf5;border-top:2px solid var(--green)}
    .p-table tr:last-child td{border-bottom:none}
    .p-table tr.data-row:hover td{background:#fafafa}

    .status-chip{font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px;white-space:nowrap;display:inline-block}
    .status-new{background:#eef2fb;color:#3a5ba0}
    .status-contacted{background:#fff4e0;color:#a07221}
    .status-interested{background:#eef5e8;color:#5b8e0d}
    .status-not_interested{background:#f5f5f5;color:#888}
    .status-signed{background:#e6f7ee;color:#1f8a4c}

    .dist-chip{font-size:12px;color:#333}
    .dist-none{color:var(--faint)}

    .btn-edit-row{padding:4px 10px;border:1px solid var(--border);background:#fff;border-radius:4px;font-size:12px;cursor:pointer}
    .btn-delete{padding:4px 10px;border:1px solid #fcc;background:#fff;color:#c00;border-radius:4px;font-size:12px;cursor:pointer}
    .btn-delete:hover{background:#fff0f0}

    .edit-panel{padding:16px 20px}
    .edit-row-1{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px}
    .edit-row-2{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
    .btn-save-row{padding:8px 16px;background:var(--green);color:#111;font-weight:800;font-size:12px;border:0;border-radius:5px;cursor:pointer;white-space:nowrap}
    .btn-cancel-row{padding:8px 12px;border:1px solid var(--border);background:#fff;color:#555;font-size:12px;border-radius:5px;cursor:pointer;white-space:nowrap}

    .empty-state{text-align:center;padding:40px;color:var(--faint);font-size:14px}
    .flash-ok{padding:10px 14px;background:#eef5e8;border:1px solid #c3dfa8;border-radius:6px;color:#3a6b1a;font-size:13px;margin-bottom:16px}
    .flash-err{padding:10px 14px;background:#fff0f0;border:1px solid #f5c6c6;border-radius:6px;color:#c00;font-size:13px;margin-bottom:16px}
    .count-strip{font-size:12px;color:var(--faint);margin-bottom:10px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('recruit_prospects', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)">Back Office</div>
        <div class="content-title">Recruiting Prospects</div>
      </div>
    </header>
    <main class="wrap">

      <div id="flash-area"></div>
      <?php if (!$mcs): ?>
      <div class="flash-err">No Market Centers have a located address yet — add one on the <a href="backoffice_roster.php">Agent Roster</a> page before distances will show here.</div>
      <?php endif; ?>

      <!-- Add form -->
      <div class="add-card">
        <h3>Add Prospect</h3>
        <div class="add-row">
          <div class="field-group grow">
            <div class="field-label">Name</div>
            <input type="text" id="add-name" class="field-input" placeholder="Full name" autocomplete="off">
          </div>
          <div class="field-group grow">
            <div class="field-label">Current Brokerage</div>
            <input type="text" id="add-brokerage" class="field-input" placeholder="e.g. Century 21" autocomplete="off">
          </div>
          <div class="field-group grow">
            <div class="field-label">Phone</div>
            <input type="tel" id="add-phone" class="field-input" autocomplete="off">
          </div>
          <div class="field-group grow">
            <div class="field-label">Email</div>
            <input type="email" id="add-email" class="field-input" autocomplete="off">
          </div>
        </div>
        <div class="add-row">
          <div class="field-group grow" style="flex:2">
            <div class="field-label">Office Address</div>
            <input type="text" id="add-address" class="field-input" placeholder="123 Main St" autocomplete="off">
          </div>
          <div class="field-group grow">
            <div class="field-label">City</div>
            <input type="text" id="add-city" class="field-input" autocomplete="off">
          </div>
          <div class="field-group sm">
            <div class="field-label">State</div>
            <input type="text" id="add-state" class="field-input" maxlength="2" style="text-transform:uppercase">
          </div>
          <div class="field-group sm">
            <div class="field-label">Zip</div>
            <input type="text" id="add-zip" class="field-input" maxlength="10">
          </div>
          <div class="field-group grow">
            <div class="field-label">Status</div>
            <select id="add-status" class="field-select">
              <?php foreach ($statusLabels as $val => $label): ?>
              <option value="<?= h($val) ?>"><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn-add" onclick="addProspect()">Add</button>
        </div>
      </div>

      <!-- Table -->
      <div class="count-strip"><?= count($prospects) ?> prospect<?= count($prospects)!==1?'s':'' ?></div>

      <?php if (!$prospects): ?>
        <div class="p-table" style="border-radius:10px">
          <div class="empty-state">No prospects yet. Add one above to start tracking recruiting outreach.</div>
        </div>
      <?php else: ?>
      <table class="p-table" id="p-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Brokerage</th>
            <th>Contact</th>
            <th>Address</th>
            <th>Nearest MC</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="p-tbody">
        <?php foreach ($prospects as $p): $rowId = 'edit-' . $p['id']; $near = nearest_mc($p, $mcs); ?>
          <tr class="data-row" id="row-<?= $p['id'] ?>">
            <td><strong><?= h($p['full_name']) ?></strong></td>
            <td><?= h($p['current_brokerage']) ?: '<span style="color:var(--faint)">—</span>' ?></td>
            <td>
              <?php if ($p['phone']): ?><div><?= h($p['phone']) ?></div><?php endif; ?>
              <?php if ($p['email']): ?><div style="font-size:11px;color:var(--faint)"><?= h($p['email']) ?></div><?php endif; ?>
            </td>
            <td style="font-size:12px;color:var(--faint)">
              <?= h(trim($p['address'] . ' ' . $p['city'] . ' ' . $p['state'] . ' ' . $p['zip'])) ?: '—' ?>
            </td>
            <td>
              <?php if ($near): ?>
                <span class="dist-chip"><?= h($near['name']) ?> — <?= number_format($near['miles'], 1) ?> mi</span>
              <?php elseif ($p['address'] || $p['city'] || $p['zip']): ?>
                <span class="dist-none">Not located yet</span>
              <?php else: ?>
                <span class="dist-none">—</span>
              <?php endif; ?>
            </td>
            <td><span class="status-chip status-<?= h($p['status']) ?>"><?= h($statusLabels[$p['status']] ?? $p['status']) ?></span></td>
            <td style="text-align:right;white-space:nowrap;display:flex;gap:6px;justify-content:flex-end">
              <button class="btn-edit-row" onclick="openEditRow('<?= h($rowId) ?>', this)">Edit</button>
              <button class="btn-delete" onclick="deleteProspect(<?= $p['id'] ?>, '<?= h(addslashes($p['full_name'])) ?>')">Delete</button>
            </td>
          </tr>
          <tr id="<?= h($rowId) ?>" class="edit-row" style="display:none">
            <td colspan="7">
              <div class="edit-panel">
                <div class="edit-row-1">
                  <div class="field-group grow">
                    <div class="field-label">Name</div>
                    <input type="text" class="field-input edit-name" value="<?= h($p['full_name']) ?>" autocomplete="off">
                  </div>
                  <div class="field-group grow">
                    <div class="field-label">Current Brokerage</div>
                    <input type="text" class="field-input edit-brokerage" value="<?= h($p['current_brokerage']) ?>" autocomplete="off">
                  </div>
                  <div class="field-group grow">
                    <div class="field-label">Phone</div>
                    <input type="tel" class="field-input edit-phone" value="<?= h($p['phone']) ?>" autocomplete="off">
                  </div>
                  <div class="field-group grow">
                    <div class="field-label">Email</div>
                    <input type="email" class="field-input edit-email" value="<?= h($p['email']) ?>" autocomplete="off">
                  </div>
                </div>
                <div class="edit-row-1">
                  <div class="field-group grow" style="flex:2">
                    <div class="field-label">Office Address</div>
                    <input type="text" class="field-input edit-address" value="<?= h($p['address']) ?>" autocomplete="off">
                  </div>
                  <div class="field-group grow">
                    <div class="field-label">City</div>
                    <input type="text" class="field-input edit-city" value="<?= h($p['city']) ?>" autocomplete="off">
                  </div>
                  <div class="field-group sm">
                    <div class="field-label">State</div>
                    <input type="text" class="field-input edit-state" value="<?= h($p['state']) ?>" maxlength="2" style="text-transform:uppercase">
                  </div>
                  <div class="field-group sm">
                    <div class="field-label">Zip</div>
                    <input type="text" class="field-input edit-zip" value="<?= h($p['zip']) ?>" maxlength="10">
                  </div>
                  <div class="field-group grow">
                    <div class="field-label">Status</div>
                    <select class="field-select edit-status">
                      <?php foreach ($statusLabels as $val => $label): ?>
                      <option value="<?= h($val) ?>"<?= $val===$p['status']?' selected':'' ?>><?= h($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="edit-row-2">
                  <div class="field-group grow" style="flex:1;min-width:100%">
                    <div class="field-label">Notes</div>
                    <textarea class="field-textarea edit-notes" rows="2"><?= h($p['notes']) ?></textarea>
                  </div>
                </div>
                <div class="edit-row-2">
                  <button class="btn-save-row" onclick="saveEdit(<?= $p['id'] ?>, '<?= h($rowId) ?>')">Save</button>
                  <button class="btn-cancel-row" onclick="closeEditRow('<?= h($rowId) ?>')">Cancel</button>
                </div>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>

    </main>
  </div>
</div>
<script>
function flash(msg, type='ok') {
  const el = document.getElementById('flash-area');
  el.innerHTML = `<div class="flash-${type}">${msg}</div>`;
  setTimeout(() => el.innerHTML='', 5000);
}

function post(data) {
  return fetch('api/prospect_action.php', {
    method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(data)
  }).then(r => r.json());
}

function addProspect() {
  const name = document.getElementById('add-name').value.trim();
  if (!name) { flash('Name is required.', 'err'); return; }
  post({
    action: 'save',
    full_name: name,
    current_brokerage: document.getElementById('add-brokerage').value.trim(),
    phone: document.getElementById('add-phone').value.trim(),
    email: document.getElementById('add-email').value.trim(),
    address: document.getElementById('add-address').value.trim(),
    city: document.getElementById('add-city').value.trim(),
    state: document.getElementById('add-state').value.trim().toUpperCase(),
    zip: document.getElementById('add-zip').value.trim(),
    status: document.getElementById('add-status').value,
  }).then(d => {
    if (!d.ok) { flash(d.error || 'Save failed', 'err'); return; }
    flash(`Prospect <strong>${esc(name)}</strong> added.` + (d.geocode_ok === false ? ' Address could not be located yet.' : ''));
    setTimeout(() => location.reload(), 900);
  });
}

function openEditRow(rowId, btn) {
  document.querySelectorAll('.edit-row').forEach(r => r.style.display='none');
  document.querySelectorAll('.btn-edit-row').forEach(b => { b.textContent='Edit'; b.onclick = function(){ openEditRow(rowId, this); }; });
  document.getElementById(rowId).style.display='';
  btn.textContent = 'Cancel';
  btn.onclick = () => closeEditRow(rowId);
}
function closeEditRow(rowId) {
  document.getElementById(rowId).style.display='none';
  document.querySelectorAll('.btn-edit-row').forEach(b => {
    b.textContent='Edit';
    b.onclick = function(){ openEditRow(rowId, this); };
  });
}

function saveEdit(id, rowId) {
  const row  = document.getElementById(rowId);
  const name = row.querySelector('.edit-name').value.trim();
  if (!name) { flash('Name is required.', 'err'); return; }
  post({
    action: 'save',
    id: id,
    full_name: name,
    current_brokerage: row.querySelector('.edit-brokerage').value.trim(),
    phone: row.querySelector('.edit-phone').value.trim(),
    email: row.querySelector('.edit-email').value.trim(),
    address: row.querySelector('.edit-address').value.trim(),
    city: row.querySelector('.edit-city').value.trim(),
    state: row.querySelector('.edit-state').value.trim().toUpperCase(),
    zip: row.querySelector('.edit-zip').value.trim(),
    status: row.querySelector('.edit-status').value,
    notes: row.querySelector('.edit-notes').value.trim(),
  }).then(d => {
    if (!d.ok) { flash(d.error || 'Save failed', 'err'); return; }
    flash('Saved.' + (d.geocode_ok === false ? ' Address could not be located yet.' : ''));
    setTimeout(() => location.reload(), 900);
  });
}

function deleteProspect(id, name) {
  if (!confirm(`Delete prospect "${name}"?`)) return;
  post({action:'delete', id}).then(d => {
    if (!d.ok) { flash(d.error||'Delete failed','err'); return; }
    const row = document.getElementById('row-' + id);
    if (row) row.remove();
    flash(`Deleted <strong>${esc(name)}</strong>.`);
  });
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
