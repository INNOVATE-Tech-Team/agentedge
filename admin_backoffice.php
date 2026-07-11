<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
$perms = current_perms();
if (empty($perms['isSuperAdmin'])) {
    header('Location: index.php'); exit;
}

// Load all items (including disabled) for the builder table.
$items = local_db()->query("SELECT * FROM backoffice_items ORDER BY sort_ord,id")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Back Office Menu Builder — AgentEdge</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
<style>
.bo-eyebrow { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--faint); }

/* Add-item form */
.add-form { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:20px; }
.add-form .field { margin:0; }
.add-form .field label { font-size:11px; font-weight:700; text-transform:uppercase;
                         letter-spacing:.05em; color:var(--faint); display:block; margin-bottom:4px; }
.add-form input[type=text] { padding:8px 10px; border:1px solid var(--border); border-radius:8px;
                              font-size:14px; background:#fafafa; min-width:180px; }
.add-form input[type=text]:focus { outline:2px solid var(--green); }
.add-form .cb-row { display:flex; align-items:center; gap:6px; font-size:13px; padding-bottom:2px; }
.btn-add { padding:9px 18px; background:var(--green); color:#111; border:0; border-radius:8px;
           font-weight:800; font-size:14px; cursor:pointer; white-space:nowrap; }
.btn-add:hover { background:var(--green-d); color:#fff; }

/* Builder table */
.mb-table { width:100%; border-collapse:collapse; font-size:13px; }
.mb-table th { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em;
               color:var(--faint); border-bottom:1px solid var(--border); padding:8px 10px;
               text-align:left; white-space:nowrap; }
.mb-table td { padding:9px 10px; border-bottom:1px solid var(--border); vertical-align:middle; }
.mb-table tr:last-child td { border-bottom:none; }
.mb-table tr:hover td { background:#fafbfa; }

/* Inline edit inputs */
.mb-input { font-size:13px; padding:5px 8px; border:1px solid transparent; border-radius:6px;
            background:transparent; width:100%; }
.mb-input:hover, .mb-input:focus { border-color:var(--border); background:#fff; outline:2px solid var(--green); }

/* Toggle enable */
.toggle-cb { width:16px; height:16px; cursor:pointer; accent-color:var(--green); }

/* Order buttons */
.btn-ord { background:none; border:1px solid var(--border); border-radius:5px; padding:3px 7px;
           font-size:12px; cursor:pointer; color:var(--muted); line-height:1; }
.btn-ord:hover { background:var(--bg); color:var(--ink); }

/* Delete */
.btn-del { background:none; border:none; color:var(--faint); font-size:16px; cursor:pointer;
           padding:2px 6px; border-radius:4px; line-height:1; }
.btn-del:hover { background:#fdecea; color:var(--red); }

/* Save row button (appears on change) */
.btn-row-save { display:none; font-size:11px; font-weight:700; padding:4px 10px; border-radius:6px;
                border:0; background:var(--green); color:#111; cursor:pointer; white-space:nowrap; }
.btn-row-save.visible { display:inline-block; }
.saved-flash { font-size:11px; color:var(--green-d); font-weight:700; display:none;
               vertical-align:middle; margin-left:6px; }

/* Built-in rows */
.built-in-row td { color:var(--faint); }
.built-in-badge { font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.06em;
                  background:#f0f0f0; color:#999; padding:2px 6px; border-radius:4px; white-space:nowrap; }
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('admin_backoffice', $agent); ?>
<div class="content">
  <div class="content-top">
    <div>
      <div class="bo-eyebrow">Back Office</div>
      <div class="content-title">Menu Builder</div>
    </div>
    <div class="content-hello">Organize the Back Office sidebar section</div>
  </div>
  <div class="wrap">

    <div class="card" style="margin-bottom:16px">
      <h2 style="margin-bottom:4px">Add a new item</h2>
      <p style="font-size:12px;color:var(--faint);margin-bottom:14px">
        Items you add here appear in the Back Office section of the sidebar for all admin users.
        Built-in items (State Rosters, Menu Builder) always appear and cannot be removed here.
      </p>
      <form class="add-form" id="addForm" onsubmit="addItem(event)">
        <div class="field">
          <label>Label</label>
          <input type="text" id="newLabel" placeholder="e.g. Recruiting Reports" required maxlength="80">
        </div>
        <div class="field">
          <label>URL</label>
          <input type="text" id="newUrl" placeholder="e.g. reports.php or https://..." required maxlength="500">
        </div>
        <div class="field">
          <label>Department</label>
          <select id="newDept" style="padding:6px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
            <option value="Operations">Operations</option>
            <option value="Finance">Finance</option>
            <option value="Broker Files">Broker Files</option>
            <option value="Events">Events</option>
            <option value="Agent Development">Agent Development</option>
            <option value="Technology">Technology</option>
            <option value="Human Resources">Human Resources</option>
          </select>
        </div>
        <div class="field">
          <label>Opens in</label>
          <div class="cb-row">
            <input type="checkbox" id="newExt" style="accent-color:var(--green)">
            <label for="newExt" style="font-size:13px;font-weight:400;text-transform:none;letter-spacing:0;color:var(--ink);cursor:pointer">New tab (external link)</label>
          </div>
        </div>
        <button type="submit" class="btn-add">+ Add Item</button>
      </form>
    </div>

    <div class="card" style="padding:0;overflow:hidden">
      <div style="padding:14px 16px 0;border-bottom:1px solid var(--border)">
        <h2 style="margin-bottom:12px">Back Office sidebar — current items</h2>
      </div>
      <table class="mb-table" id="mbTable" aria-label="Back office menu items">
        <thead>
          <tr>
            <th style="width:40px">Order</th>
            <th>Label</th>
            <th>URL</th>
            <th style="width:130px">Department</th>
            <th style="width:70px">External</th>
            <th style="width:70px">Enabled</th>
            <th style="width:110px"></th>
          </tr>
        </thead>
        <tbody>
          <!-- Built-in: State Rosters -->
          <tr class="built-in-row">
            <td>—</td>
            <td>State Rosters <span class="built-in-badge">built-in</span></td>
            <td style="font-size:11px;color:var(--faint)">backoffice_state_rosters.php</td>
            <td style="font-size:11px;color:var(--faint)">Operations</td>
            <td></td>
            <td></td>
            <td></td>
          </tr>
          <!-- Dynamic items -->
          <?php foreach ($items as $item): ?>
          <tr id="row-<?= $item['id'] ?>" data-id="<?= $item['id'] ?>">
            <td>
              <button class="btn-ord" onclick="moveRow(<?= $item['id'] ?>, -1)" aria-label="Move up">↑</button>
              <button class="btn-ord" onclick="moveRow(<?= $item['id'] ?>,  1)" aria-label="Move down">↓</button>
            </td>
            <td>
              <input class="mb-input" type="text" value="<?= htmlspecialchars($item['label']) ?>"
                     data-field="label" data-id="<?= $item['id'] ?>" oninput="markDirty(this)">
            </td>
            <td>
              <input class="mb-input" type="text" value="<?= htmlspecialchars($item['url']) ?>"
                     data-field="url" data-id="<?= $item['id'] ?>" oninput="markDirty(this)">
            </td>
            <td>
              <?php $deptVal = htmlspecialchars($item['department'] ?? 'Operations'); ?>
              <select class="mb-input" style="padding:3px 6px;font-size:12px" data-field="department" data-id="<?= $item['id'] ?>" onchange="markDirty(this)">
                <?php foreach (['Operations','Finance','Broker Files','Events','Agent Development','Technology','Human Resources'] as $dopt): ?>
                <option value="<?= htmlspecialchars($dopt) ?>" <?= ($item['department'] ?? 'Operations') === $dopt ? 'selected' : '' ?>><?= htmlspecialchars($dopt) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td style="text-align:center">
              <input type="checkbox" class="toggle-cb" data-field="is_ext" data-id="<?= $item['id'] ?>"
                     <?= $item['is_ext'] ? 'checked' : '' ?> onchange="markDirty(this)">
            </td>
            <td style="text-align:center">
              <input type="checkbox" class="toggle-cb" data-field="enabled" data-id="<?= $item['id'] ?>"
                     <?= $item['enabled'] ? 'checked' : '' ?> onchange="markDirty(this)">
            </td>
            <td>
              <button class="btn-row-save" id="save-<?= $item['id'] ?>" onclick="saveRow(<?= $item['id'] ?>)">Save</button>
              <span class="saved-flash" id="flash-<?= $item['id'] ?>">Saved ✓</span>
              <button class="btn-del" onclick="deleteItem(<?= $item['id'] ?>)" aria-label="Delete">✕</button>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($items)): ?>
          <tr id="emptyRow">
            <td colspan="7" style="text-align:center;color:var(--faint);padding:28px;font-style:italic">
              No custom items yet. Add one above.
            </td>
          </tr>
          <?php endif; ?>
          <!-- Built-in: Menu Builder (always last) -->
          <tr class="built-in-row">
            <td>—</td>
            <td>Menu Builder <span class="built-in-badge">built-in</span></td>
            <td style="font-size:11px;color:var(--faint)">admin_backoffice.php</td>
            <td style="font-size:11px;color:var(--faint)">Technology</td>
            <td></td>
            <td></td>
            <td></td>
          </tr>
        </tbody>
      </table>
    </div>

  </div><!-- /wrap -->
</div><!-- /content -->
</div><!-- /layout -->

<script>
function markDirty(el) {
    const id = el.dataset.id;
    document.getElementById('save-' + id).classList.add('visible');
}

function saveRow(id) {
    const row = document.getElementById('row-' + id);
    const label      = row.querySelector('[data-field="label"]').value.trim();
    const url        = row.querySelector('[data-field="url"]').value.trim();
    const is_ext     = row.querySelector('[data-field="is_ext"]').checked ? 1 : 0;
    const enabled    = row.querySelector('[data-field="enabled"]').checked ? 1 : 0;
    const department = row.querySelector('[data-field="department"]').value;
    if (!label || !url) return alert('Label and URL are required.');
    fetch('api/backoffice_menu.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'update', id, label, url, is_ext, enabled, department})
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) {
            document.getElementById('save-' + id).classList.remove('visible');
            const flash = document.getElementById('flash-' + id);
            flash.style.display = 'inline';
            setTimeout(() => flash.style.display = 'none', 1800);
        }
    });
}

function deleteItem(id) {
    if (!confirm('Remove this item from the Back Office menu?')) return;
    fetch('api/backoffice_menu.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'delete', id})
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) {
            const row = document.getElementById('row-' + id);
            if (row) row.remove();
            // Show empty state if no rows left
            const tbody = document.querySelector('#mbTable tbody');
            const dynamic = tbody.querySelectorAll('tr[data-id]');
            if (dynamic.length === 0 && !document.getElementById('emptyRow')) {
                const tr = document.createElement('tr');
                tr.id = 'emptyRow';
                tr.innerHTML = '<td colspan="7" style="text-align:center;color:var(--faint);padding:28px;font-style:italic">No custom items yet. Add one above.</td>';
                // Insert after first built-in row
                const builtins = tbody.querySelectorAll('.built-in-row');
                builtins[0].after(tr);
            }
        }
    });
}

function addItem(e) {
    e.preventDefault();
    const label      = document.getElementById('newLabel').value.trim();
    const url        = document.getElementById('newUrl').value.trim();
    const is_ext     = document.getElementById('newExt').checked ? 1 : 0;
    const department = document.getElementById('newDept').value;
    if (!label || !url) return;
    fetch('api/backoffice_menu.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'add', label, url, is_ext, department})
    })
    .then(r => r.json())
    .then(d => {
        if (!d.ok || !d.item) return;
        const item = d.item;
        // Remove empty state row if present
        const emp = document.getElementById('emptyRow');
        if (emp) emp.remove();
        // Append before the last built-in row (Menu Builder)
        const tbody = document.querySelector('#mbTable tbody');
        const lastBuiltin = tbody.querySelectorAll('.built-in-row');
        const newRow = document.createElement('tr');
        newRow.id = 'row-' + item.id;
        newRow.dataset.id = item.id;
        newRow.innerHTML = `
          <td>
            <button class="btn-ord" onclick="moveRow(${item.id}, -1)" aria-label="Move up">↑</button>
            <button class="btn-ord" onclick="moveRow(${item.id},  1)" aria-label="Move down">↓</button>
          </td>
          <td><input class="mb-input" type="text" value="${esc(item.label)}" data-field="label" data-id="${item.id}" oninput="markDirty(this)"></td>
          <td><input class="mb-input" type="text" value="${esc(item.url)}"   data-field="url"   data-id="${item.id}" oninput="markDirty(this)"></td>
          <td>${deptSelect(item.id, item.department || 'Operations')}</td>
          <td style="text-align:center"><input type="checkbox" class="toggle-cb" data-field="is_ext"  data-id="${item.id}" ${item.is_ext  ? 'checked' : ''} onchange="markDirty(this)"></td>
          <td style="text-align:center"><input type="checkbox" class="toggle-cb" data-field="enabled" data-id="${item.id}" checked onchange="markDirty(this)"></td>
          <td>
            <button class="btn-row-save" id="save-${item.id}" onclick="saveRow(${item.id})">Save</button>
            <span class="saved-flash" id="flash-${item.id}">Saved ✓</span>
            <button class="btn-del" onclick="deleteItem(${item.id})" aria-label="Delete">✕</button>
          </td>`;
        lastBuiltin[lastBuiltin.length - 1].before(newRow);
        // Reset form
        document.getElementById('newLabel').value = '';
        document.getElementById('newUrl').value   = '';
        document.getElementById('newExt').checked = false;
        document.getElementById('newDept').value  = 'Operations';
    });
}

function moveRow(id, dir) {
    fetch('api/backoffice_menu.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'move', id, dir})
    })
    .then(r => r.json())
    .then(d => { if (d.ok) location.reload(); });
}

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
}

const DEPTS = ['Operations','Finance','Broker Files','Events','Agent Development','Technology','Human Resources'];
function deptSelect(id, current) {
    const opts = DEPTS.map(d => `<option value="${esc(d)}"${d === current ? ' selected' : ''}>${esc(d)}</option>`).join('');
    return `<select class="mb-input" style="padding:3px 6px;font-size:12px" data-field="department" data-id="${id}" onchange="markDirty(this)">${opts}</select>`;
}
</script>
</body>
</html>
