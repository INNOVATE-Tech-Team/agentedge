<?php
// Market Center management has moved to the Back Office Roster page.
header('Location: backoffice_roster.php');
exit;

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';

$agent = require_login();
if (!is_super_admin()) { header('Location: index.php'); exit; }

$db  = local_db();
$mcs = $db->query("SELECT * FROM market_centers ORDER BY state_code, sort_ord, name")->fetchAll(PDO::FETCH_ASSOC);

$bicList    = $db->query("SELECT email FROM agent_roles WHERE role='bic'       ORDER BY email")->fetchAll(PDO::FETCH_COLUMN);
$leaderList = $db->query("SELECT email FROM agent_roles WHERE role='mc_leader' ORDER BY email")->fetchAll(PDO::FETCH_COLUMN);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Market Centers — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .mc-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:22px}
    .btn-import{padding:8px 16px;background:#f0f0f0;color:#333;border:1px solid #ddd;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer}
    .btn-import:hover{background:#e4e4e4}
    .btn-import.loading{opacity:.6;pointer-events:none}

    .add-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:20px 24px;margin-bottom:24px}
    .add-card h3{margin:0 0 14px;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
    .add-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px}
    .add-row-2{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
    .field-group{display:flex;flex-direction:column;gap:4px}
    .field-group.grow{flex:1;min-width:180px}
    .field-group.sm{min-width:80px;width:80px}
    .field-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint)}
    .field-input{padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;width:100%;box-sizing:border-box}
    .field-input:focus{outline:2px solid var(--green);border-color:var(--green)}
    .field-select{padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:12px;width:100%;box-sizing:border-box;background:#fff}
    .field-select:focus{outline:2px solid var(--green);border-color:var(--green)}
    .slug-preview{font-size:11px;color:var(--faint);margin-top:3px;min-height:16px}
    .btn-add{padding:9px 20px;background:var(--green);color:#111;font-weight:800;font-size:13px;border:0;border-radius:6px;cursor:pointer;white-space:nowrap;align-self:flex-end}
    .btn-add:hover{background:var(--green-d,#5b8e0d);color:#fff}

    .mc-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;
              border:1px solid var(--border);border-radius:10px;overflow:hidden}
    .mc-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
                 color:var(--faint);padding:10px 14px;text-align:left;border-bottom:1px solid var(--border)}
    .mc-table td{padding:9px 14px;border-top:1px solid var(--border);vertical-align:middle}
    .mc-table tr.edit-row td{padding:0;background:#f9fdf5;border-top:2px solid var(--green)}
    .mc-table tr:last-child td{border-bottom:none}
    .mc-table tr.data-row:hover td{background:#fafafa}
    .mc-table tr.data-row.disabled td{opacity:.5}

    .slug-chip{font-size:11px;font-family:monospace;background:#f0f0f0;color:#555;padding:2px 7px;border-radius:4px}
    .state-chip{font-size:11px;font-weight:700;background:#eef5e8;color:#5b8e0d;padding:2px 7px;border-radius:4px}
    .role-chip{font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px;white-space:nowrap;display:inline-block;margin-top:3px}
    .bic-chip{background:#fff4e0;color:#a07221}
    .leader-chip{background:#eef5e8;color:#5b8e0d}
    .toggle-btn{padding:4px 10px;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer;border:1px solid;white-space:nowrap}
    .toggle-btn.enabled{background:#eef5e8;color:#5b8e0d;border-color:#c3dfa8}
    .toggle-btn.disabled{background:#f5f5f5;color:#999;border-color:#ddd}
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
  <?php render_sidebar('admin_market_centers', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)">Back Office</div>
        <div class="content-title">Market Centers</div>
      </div>
      <div class="content-hello">
        <button class="btn-import" id="btn-import" onclick="importFromRoster()">Import from Roster</button>
      </div>
    </header>
    <main class="wrap">

      <div id="flash-area"></div>

      <!-- Add form -->
      <div class="add-card">
        <h3>Add Market Center</h3>
        <div class="add-row">
          <div class="field-group grow">
            <div class="field-label">Name</div>
            <input type="text" id="add-name" class="field-input" placeholder="e.g. SC - Myrtle Beach"
                   oninput="previewSlug(this.value,'add-slug-preview')" autocomplete="off">
            <div class="slug-preview" id="add-slug-preview"></div>
          </div>
          <div class="field-group sm">
            <div class="field-label">State</div>
            <input type="text" id="add-state" class="field-input" placeholder="SC" maxlength="2"
                   style="text-transform:uppercase">
          </div>
          <div class="field-group sm">
            <div class="field-label">Sort</div>
            <input type="number" id="add-sort" class="field-input" value="0" min="0">
          </div>
        </div>
        <div class="add-row-2">
          <div class="field-group grow">
            <div class="field-label">BIC (Broker in Charge)</div>
            <?php if ($bicList): ?>
            <select id="add-bic" class="field-select">
              <option value="">— none —</option>
              <?php foreach ($bicList as $be): ?><option value="<?= h($be) ?>"><?= h($be) ?></option><?php endforeach; ?>
            </select>
            <?php else: ?>
            <input type="email" id="add-bic" class="field-input" placeholder="bic@example.com" autocomplete="off"
                   list="bic-datalist">
            <?php endif; ?>
          </div>
          <div class="field-group grow">
            <div class="field-label">MC Leader</div>
            <?php if ($leaderList): ?>
            <select id="add-leader" class="field-select">
              <option value="">— none —</option>
              <?php foreach ($leaderList as $le): ?><option value="<?= h($le) ?>"><?= h($le) ?></option><?php endforeach; ?>
            </select>
            <?php else: ?>
            <input type="email" id="add-leader" class="field-input" placeholder="leader@example.com" autocomplete="off"
                   list="leader-datalist">
            <?php endif; ?>
          </div>
          <button class="btn-add" onclick="addMC()">Add</button>
        </div>
      </div>

      <!-- Datalists for free-text email inputs -->
      <datalist id="bic-datalist"><?php foreach ($bicList as $be): ?><option value="<?= h($be) ?>"><?php endforeach; ?></datalist>
      <datalist id="leader-datalist"><?php foreach ($leaderList as $le): ?><option value="<?= h($le) ?>"><?php endforeach; ?></datalist>

      <!-- Table -->
      <?php
        $enabled  = count(array_filter($mcs, fn($r) => $r['enabled']));
        $disabled = count($mcs) - $enabled;
      ?>
      <div class="count-strip"><?= count($mcs) ?> market centers &mdash; <?= $enabled ?> enabled, <?= $disabled ?> disabled</div>

      <?php if (!$mcs): ?>
        <div class="mc-table" style="border-radius:10px">
          <div class="empty-state">No market centers yet. Add one above or use <strong>Import from Roster</strong> to pull from the CRM.</div>
        </div>
      <?php else: ?>
      <table class="mc-table" id="mc-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Slug</th>
            <th>State</th>
            <th>Sort</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="mc-tbody">
        <?php foreach ($mcs as $mc): $rowId = 'edit-' . md5($mc['slug']); ?>
          <tr class="data-row<?= $mc['enabled'] ? '' : ' disabled' ?>" id="row-<?= h($mc['slug']) ?>">
            <td>
              <strong><?= h($mc['name']) ?></strong>
              <div style="margin-top:3px;display:flex;gap:5px;flex-wrap:wrap">
                <?php if (!empty($mc['bic_email'])): ?>
                <span class="role-chip bic-chip">BIC: <?= h($mc['bic_email']) ?></span>
                <?php endif; ?>
                <?php if (!empty($mc['mc_leader_email'])): ?>
                <span class="role-chip leader-chip">Leader: <?= h($mc['mc_leader_email']) ?></span>
                <?php endif; ?>
              </div>
            </td>
            <td><span class="slug-chip"><?= h($mc['slug']) ?></span></td>
            <td><?= $mc['state_code'] ? '<span class="state-chip">' . h($mc['state_code']) . '</span>' : '<span style="color:var(--faint)">—</span>' ?></td>
            <td style="color:var(--faint)"><?= (int)$mc['sort_ord'] ?></td>
            <td>
              <button class="toggle-btn <?= $mc['enabled'] ? 'enabled' : 'disabled' ?>"
                      data-slug="<?= h($mc['slug']) ?>"
                      onclick="toggleMC(this, '<?= h(addslashes($mc['slug'])) ?>')">
                <?= $mc['enabled'] ? 'Enabled' : 'Disabled' ?>
              </button>
            </td>
            <td style="text-align:right;white-space:nowrap;display:flex;gap:6px;justify-content:flex-end">
              <button class="btn-edit-row" onclick="openEditRow('<?= h($rowId) ?>', this)">Edit</button>
              <button class="btn-delete" onclick="deleteMC('<?= h(addslashes($mc['slug'])) ?>', '<?= h(addslashes($mc['name'])) ?>')">Delete</button>
            </td>
          </tr>
          <tr id="<?= h($rowId) ?>" class="edit-row" style="display:none">
            <td colspan="6">
              <div class="edit-panel">
                <!-- Row 1: Name / State / Sort -->
                <div class="edit-row-1">
                  <div class="field-group grow">
                    <div class="field-label">Name</div>
                    <input type="text" class="field-input edit-name" value="<?= h($mc['name']) ?>"
                           oninput="previewSlug(this.value,'slug-prev-<?= h($rowId) ?>')" autocomplete="off">
                    <div class="slug-preview" id="slug-prev-<?= h($rowId) ?>">slug: <?= h($mc['slug']) ?> (unchanging)</div>
                  </div>
                  <div class="field-group sm">
                    <div class="field-label">State</div>
                    <input type="text" class="field-input edit-state" value="<?= h($mc['state_code']) ?>"
                           maxlength="2" style="text-transform:uppercase">
                  </div>
                  <div class="field-group sm">
                    <div class="field-label">Sort</div>
                    <input type="number" class="field-input edit-sort" value="<?= (int)$mc['sort_ord'] ?>" min="0">
                  </div>
                </div>
                <!-- Row 2: BIC / MC Leader / Save / Cancel -->
                <div class="edit-row-2">
                  <div class="field-group grow">
                    <div class="field-label">BIC (Broker in Charge)</div>
                    <?php if ($bicList): ?>
                    <select class="field-select edit-bic">
                      <option value="">— none —</option>
                      <?php foreach ($bicList as $be): ?>
                      <option value="<?= h($be) ?>"<?= $mc['bic_email']===$be?' selected':'' ?>><?= h($be) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <input type="email" class="field-input edit-bic" value="<?= h($mc['bic_email']) ?>"
                           placeholder="bic@example.com" autocomplete="off" list="bic-datalist">
                    <?php endif; ?>
                  </div>
                  <div class="field-group grow">
                    <div class="field-label">MC Leader</div>
                    <?php if ($leaderList): ?>
                    <select class="field-select edit-leader">
                      <option value="">— none —</option>
                      <?php foreach ($leaderList as $le): ?>
                      <option value="<?= h($le) ?>"<?= $mc['mc_leader_email']===$le?' selected':'' ?>><?= h($le) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <input type="email" class="field-input edit-leader" value="<?= h($mc['mc_leader_email']) ?>"
                           placeholder="leader@example.com" autocomplete="off" list="leader-datalist">
                    <?php endif; ?>
                  </div>
                  <button class="btn-save-row" onclick="saveEdit('<?= h(addslashes($mc['slug'])) ?>', '<?= h($rowId) ?>')">Save</button>
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
function slugify(s) {
  return s.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
}
function previewSlug(val, previewId) {
  const el = document.getElementById(previewId);
  // On edit rows the slug note says "unchanging" — don't overwrite it
  if (el && !el.textContent.includes('unchanging')) {
    const slug = slugify(val);
    el.textContent = slug ? 'slug: ' + slug : '';
  }
}

function flash(msg, type='ok') {
  const el = document.getElementById('flash-area');
  el.innerHTML = `<div class="flash-${type}">${msg}</div>`;
  setTimeout(() => el.innerHTML='', 4000);
}

function post(data) {
  return fetch('api/mc_action.php', {
    method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(data)
  }).then(r => r.json());
}

function addMC() {
  const name   = document.getElementById('add-name').value.trim();
  const state  = document.getElementById('add-state').value.trim().toUpperCase();
  const sort   = parseInt(document.getElementById('add-sort').value) || 0;
  const bic    = document.getElementById('add-bic').value.trim();
  const leader = document.getElementById('add-leader').value.trim();
  if (!name) { flash('Name is required.', 'err'); return; }
  post({action:'save', name, state_code:state, sort_ord:sort, bic_email:bic, mc_leader_email:leader})
    .then(d => {
      if (!d.ok) { flash(d.error || 'Save failed', 'err'); return; }
      flash(`Market center <strong>${esc(name)}</strong> added.`);
      document.getElementById('add-name').value   = '';
      document.getElementById('add-state').value  = '';
      document.getElementById('add-sort').value   = '0';
      document.getElementById('add-slug-preview').textContent = '';
      const bicEl = document.getElementById('add-bic');
      const ldrEl = document.getElementById('add-leader');
      if (bicEl.tagName === 'SELECT') bicEl.selectedIndex = 0; else bicEl.value = '';
      if (ldrEl.tagName === 'SELECT') ldrEl.selectedIndex = 0; else ldrEl.value = '';
      addRowToTable(d);
    });
}

function addRowToTable(mc) {
  const tbody = document.getElementById('mc-tbody');
  if (!tbody) { location.reload(); return; }
  const rowId = 'edit-' + Math.random().toString(36).slice(2);
  const slug  = mc.slug;
  const stateHtml = mc.state_code
    ? `<span class="state-chip">${esc(mc.state_code)}</span>`
    : `<span style="color:var(--faint)">—</span>`;
  const chipsHtml = [
    mc.bic_email    ? `<span class="role-chip bic-chip">BIC: ${esc(mc.bic_email)}</span>`        : '',
    mc.mc_leader_email ? `<span class="role-chip leader-chip">Leader: ${esc(mc.mc_leader_email)}</span>` : '',
  ].filter(Boolean).join('');
  const tr = document.createElement('tr');
  tr.className = 'data-row';
  tr.id = 'row-' + slug;
  tr.innerHTML = `
    <td><strong>${esc(mc.name)}</strong>${chipsHtml ? '<div style="margin-top:3px;display:flex;gap:5px;flex-wrap:wrap">' + chipsHtml + '</div>' : ''}</td>
    <td><span class="slug-chip">${esc(slug)}</span></td>
    <td>${stateHtml}</td>
    <td style="color:var(--faint)">${mc.sort_ord}</td>
    <td><button class="toggle-btn enabled" data-slug="${esc(slug)}" onclick="toggleMC(this,'${esc(slug)}')">Enabled</button></td>
    <td style="text-align:right;white-space:nowrap;display:flex;gap:6px;justify-content:flex-end">
      <button class="btn-edit-row" onclick="openEditRow('${rowId}',this)">Edit</button>
      <button class="btn-delete" onclick="deleteMC('${esc(slug)}','${esc(mc.name)}')">Delete</button>
    </td>`;
  tbody.appendChild(tr);
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

function saveEdit(origSlug, rowId) {
  const row    = document.getElementById(rowId);
  const name   = row.querySelector('.edit-name').value.trim();
  const state  = row.querySelector('.edit-state').value.trim().toUpperCase();
  const sort   = parseInt(row.querySelector('.edit-sort').value) || 0;
  const bic    = row.querySelector('.edit-bic').value.trim();
  const leader = row.querySelector('.edit-leader').value.trim();
  if (!name) { flash('Name is required.', 'err'); return; }
  post({action:'save', name, state_code:state, sort_ord:sort, edit_slug:origSlug,
        bic_email:bic, mc_leader_email:leader})
    .then(d => {
      if (!d.ok) { flash(d.error || 'Save failed', 'err'); return; }
      flash('Saved. ' + (d.bic_email ? 'BIC synced to all agents in this MC.' : ''));
      location.reload();
    });
}

function toggleMC(btn, slug) {
  post({action:'toggle', slug}).then(d => {
    if (!d.ok) { flash(d.error||'Toggle failed','err'); return; }
    const enabled = d.enabled === 1;
    btn.textContent = enabled ? 'Enabled' : 'Disabled';
    btn.className = 'toggle-btn ' + (enabled ? 'enabled' : 'disabled');
    const dataRow = document.getElementById('row-' + slug);
    if (dataRow) dataRow.classList.toggle('disabled', !enabled);
  });
}

function deleteMC(slug, name) {
  if (!confirm(`Delete "${name}"?\n\nThis removes it from the master list but does not change existing role assignments or roster rows.`)) return;
  post({action:'delete', slug}).then(d => {
    if (!d.ok) { flash(d.error||'Delete failed','err'); return; }
    const row = document.getElementById('row-' + slug);
    if (row) row.remove();
    flash(`Deleted <strong>${esc(name)}</strong>.`);
  });
}

function importFromRoster() {
  const btn = document.getElementById('btn-import');
  btn.classList.add('loading');
  btn.textContent = 'Importing…';
  post({action:'import'}).then(d => {
    btn.classList.remove('loading');
    btn.textContent = 'Import from Roster';
    if (!d.ok) { flash(d.error||'Import failed','err'); return; }
    if (d.added === 0) {
      flash('All roster market centers are already in the list.');
    } else {
      flash(`Imported ${d.added} new market center${d.added===1?'':'s'} from the roster.`);
      setTimeout(() => location.reload(), 1200);
    }
  });
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
