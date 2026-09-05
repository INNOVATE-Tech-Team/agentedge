<?php
// Back Office → Technology → Who Does What: the editor for the public
// who_does_what.php directory. One table (team_directory) drives both —
// there is no separate publish step, saving here is what agents see.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/who_does_what.php';

$agent = require_login();
require_admin_page();

$people = team_directory_list_all();
$lookup = wdw_agent_lookup();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Who Does What — AgentEdge Back Office</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .wdwa-add{background:#fff;border:1px solid var(--border);border-radius:10px;padding:20px 24px;margin-bottom:24px}
    .wdwa-add h3{margin:0 0 14px;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
    .wdwa-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px}
    .wdwa-fg{display:flex;flex-direction:column;gap:4px}
    .wdwa-fg.grow{flex:1;min-width:200px}
    .wdwa-fg.sm{min-width:90px;width:110px}
    .wdwa-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint)}
    .wdwa-input,.wdwa-select,.wdwa-textarea{padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;width:100%;box-sizing:border-box;font-family:inherit}
    .wdwa-input:focus,.wdwa-select:focus,.wdwa-textarea:focus{outline:2px solid var(--green);border-color:var(--green)}
    .wdwa-textarea{resize:vertical;min-height:52px}
    .wdwa-tagbox{display:flex;flex-wrap:wrap;gap:8px;padding:8px 0}
    .wdwa-tagbox label{display:flex;align-items:center;gap:5px;font-size:12px;background:var(--bg);border:1px solid var(--border);border-radius:999px;padding:5px 10px;cursor:pointer}
    .wdwa-photo-row{display:flex;align-items:center;gap:10px}
    .wdwa-thumb{width:44px;height:52px;border-radius:6px;object-fit:cover;background:var(--bg);border:1px solid var(--border)}
    .wdwa-photo-status{font-size:11px;color:var(--faint)}
    .btn-add{padding:9px 20px;background:var(--green);color:#111;font-weight:800;font-size:13px;border:0;border-radius:6px;cursor:pointer;white-space:nowrap;align-self:flex-end}
    .btn-add:hover{background:var(--green-d);color:#fff}

    .wdwa-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden}
    .wdwa-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);padding:10px 14px;text-align:left;border-bottom:1px solid var(--border)}
    .wdwa-table td{padding:9px 14px;border-top:1px solid var(--border);vertical-align:middle}
    .wdwa-table tr.edit-row td{padding:0;background:#f9fdf5;border-top:2px solid var(--green)}
    .wdwa-table tr.data-row:hover td{background:#fafafa}
    .wdwa-table tr.data-row.disabled td{opacity:.5}

    .group-chip{font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px;white-space:nowrap;display:inline-block;background:#eef5e8;color:var(--green-d)}
    .tag-chip{font-size:10px;font-weight:600;padding:2px 6px;border-radius:4px;background:#eceeea;color:var(--ink);margin:2px 3px 0 0;display:inline-block}
    .toggle-btn{padding:4px 10px;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer;border:1px solid;white-space:nowrap}
    .toggle-btn.enabled{background:#eef5e8;color:var(--green-d);border-color:#c3dfa8}
    .toggle-btn.disabled{background:#f5f5f5;color:#999;border-color:#ddd}
    .btn-edit-row{padding:4px 10px;border:1px solid var(--border);background:#fff;border-radius:4px;font-size:12px;cursor:pointer}

    .wdwa-edit-panel{padding:16px 20px}
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
  <?php render_sidebar('admin_who_does_what', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)">Back Office · Technology</div>
        <div class="content-title">Who Does What</div>
      </div>
      <div class="content-hello">Drives the public "Who Does What" directory — saves here go live immediately.</div>
    </header>
    <main class="wrap">

      <div id="flash-area"></div>

      <!-- Add form -->
      <div class="wdwa-add">
        <h3>Add Person</h3>
        <div class="wdwa-row">
          <div class="wdwa-fg grow">
            <div class="wdwa-label">Email</div>
            <input type="email" id="add-email" class="wdwa-input" placeholder="name@innovate.com" autocomplete="off" list="agent-datalist" oninput="autofillFromLookup('add')">
          </div>
          <div class="wdwa-fg grow">
            <div class="wdwa-label">Name</div>
            <input type="text" id="add-name" class="wdwa-input" placeholder="Full name" autocomplete="off">
          </div>
          <div class="wdwa-fg grow">
            <div class="wdwa-label">Title</div>
            <input type="text" id="add-title" class="wdwa-input" placeholder="e.g. Commissions & Accounting" autocomplete="off">
          </div>
        </div>
        <div class="wdwa-row">
          <div class="wdwa-fg grow">
            <div class="wdwa-label">Group</div>
            <div class="wdwa-tagbox" id="add-groups">
              <?php foreach (WDW_GROUPS as $g): ?>
                <label><input type="checkbox" value="<?= h($g) ?>"> <?= h($g) ?></label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="wdwa-row">
          <div class="wdwa-fg grow">
            <div class="wdwa-label">What they handle</div>
            <textarea id="add-handles" class="wdwa-textarea" placeholder="Come-to-them-for sentence shown on their card"></textarea>
          </div>
        </div>
        <div class="wdwa-row">
          <div class="wdwa-fg grow">
            <div class="wdwa-label">Task tags</div>
            <div class="wdwa-tagbox" id="add-tags">
              <?php foreach (WDW_TAGS as $t): ?>
                <label><input type="checkbox" value="<?= h($t) ?>"> <?= h($t) ?></label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="wdwa-row">
          <div class="wdwa-fg grow">
            <div class="wdwa-label">Phone</div>
            <input type="text" id="add-phone" class="wdwa-input" placeholder="(000) 000-0000" autocomplete="off">
          </div>
          <div class="wdwa-fg grow">
            <div class="wdwa-label">Booking / Calendly URL</div>
            <input type="url" id="add-booking" class="wdwa-input" placeholder="https://calendly.com/…" autocomplete="off">
          </div>
          <div class="wdwa-fg sm">
            <div class="wdwa-label">Sort</div>
            <input type="number" id="add-sort" class="wdwa-input" value="0" min="0">
          </div>
        </div>
        <div class="wdwa-row">
          <div class="wdwa-fg grow">
            <div class="wdwa-label">Photo</div>
            <div class="wdwa-photo-row">
              <img class="wdwa-thumb" id="add-photo-thumb" src="" style="display:none" alt="">
              <input type="file" id="add-photo-file" accept="image/*" onchange="uploadPhoto('add')">
              <span class="wdwa-photo-status" id="add-photo-status"></span>
              <input type="hidden" id="add-photo-key" value="">
            </div>
          </div>
          <button class="btn-add" onclick="addPerson()">Add Person</button>
        </div>
      </div>

      <datalist id="agent-datalist">
        <?php foreach ($lookup as $email => $info): ?>
          <option value="<?= h($email) ?>"><?= h($info['name'] ?: $email) ?></option>
        <?php endforeach; ?>
      </datalist>

      <?php
        $enabledCount = count(array_filter($people, fn($r) => $r['enabled']));
        $disabledCount = count($people) - $enabledCount;
      ?>
      <div class="count-strip"><?= count($people) ?> people — <?= $enabledCount ?> active on the public page, <?= $disabledCount ?> inactive</div>

      <?php if (!$people): ?>
        <div class="wdwa-table"><div class="empty-state">No one in the directory yet. Add the first person above.</div></div>
      <?php else: ?>
      <table class="wdwa-table" id="wdwa-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Group / Tags</th>
            <th>Contact</th>
            <th>Sort</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="wdwa-tbody">
        <?php foreach ($people as $p): $rowId = 'edit-' . (int)$p['id']; $tags = wdw_tags_decode($p['tags']); $groups = wdw_groups_decode($p['group_label']); ?>
          <tr class="data-row<?= $p['enabled'] ? '' : ' disabled' ?>" id="row-<?= (int)$p['id'] ?>">
            <td>
              <strong><?= h($p['name']) ?></strong><br>
              <span style="color:var(--faint);font-size:12px"><?= h($p['title']) ?></span>
            </td>
            <td>
              <span class="group-chip"><?= h(implode(' · ', $groups)) ?></span><br>
              <?php foreach ($tags as $t): ?><span class="tag-chip"><?= h($t) ?></span><?php endforeach; ?>
            </td>
            <td style="font-size:12px">
              <?= h($p['email']) ?><br><span style="color:var(--faint)"><?= h($p['phone']) ?></span>
            </td>
            <td style="color:var(--faint)"><?= (int)$p['sort_ord'] ?></td>
            <td>
              <button class="toggle-btn <?= $p['enabled'] ? 'enabled' : 'disabled' ?>" onclick="toggleActive(this, <?= (int)$p['id'] ?>)">
                <?= $p['enabled'] ? 'Active' : 'Inactive' ?>
              </button>
            </td>
            <td style="text-align:right;white-space:nowrap">
              <button class="btn-edit-row" onclick="openEditRow('<?= h($rowId) ?>', this)">Edit</button>
            </td>
          </tr>
          <tr id="<?= h($rowId) ?>" class="edit-row" style="display:none">
            <td colspan="6">
              <div class="wdwa-edit-panel">
                <div class="wdwa-row">
                  <div class="wdwa-fg grow"><div class="wdwa-label">Email</div><input type="email" class="wdwa-input e-email" value="<?= h($p['email']) ?>"></div>
                  <div class="wdwa-fg grow"><div class="wdwa-label">Name</div><input type="text" class="wdwa-input e-name" value="<?= h($p['name']) ?>"></div>
                  <div class="wdwa-fg grow"><div class="wdwa-label">Title</div><input type="text" class="wdwa-input e-title" value="<?= h($p['title']) ?>"></div>
                </div>
                <div class="wdwa-row">
                  <div class="wdwa-fg grow">
                    <div class="wdwa-label">Group</div>
                    <div class="wdwa-tagbox e-groups">
                      <?php foreach (WDW_GROUPS as $g): ?>
                        <label><input type="checkbox" value="<?= h($g) ?>"<?= in_array($g,$groups,true)?' checked':'' ?>> <?= h($g) ?></label>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>
                <div class="wdwa-row">
                  <div class="wdwa-fg grow"><div class="wdwa-label">What they handle</div><textarea class="wdwa-textarea e-handles"><?= h($p['handles']) ?></textarea></div>
                </div>
                <div class="wdwa-row">
                  <div class="wdwa-fg grow">
                    <div class="wdwa-label">Task tags</div>
                    <div class="wdwa-tagbox e-tags">
                      <?php foreach (WDW_TAGS as $t): ?>
                        <label><input type="checkbox" value="<?= h($t) ?>"<?= in_array($t,$tags,true)?' checked':'' ?>> <?= h($t) ?></label>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>
                <div class="wdwa-row">
                  <div class="wdwa-fg grow"><div class="wdwa-label">Phone</div><input type="text" class="wdwa-input e-phone" value="<?= h($p['phone']) ?>"></div>
                  <div class="wdwa-fg grow"><div class="wdwa-label">Booking / Calendly URL</div><input type="url" class="wdwa-input e-booking" value="<?= h($p['booking_url']) ?>"></div>
                  <div class="wdwa-fg sm"><div class="wdwa-label">Sort</div><input type="number" class="wdwa-input e-sort" value="<?= (int)$p['sort_ord'] ?>" min="0"></div>
                </div>
                <div class="wdwa-row">
                  <div class="wdwa-fg grow">
                    <div class="wdwa-label">Photo</div>
                    <div class="wdwa-photo-row">
                      <?php $thumb = wdw_photo_url($p); ?>
                      <img class="wdwa-thumb e-photo-thumb" src="<?= $thumb ? h($thumb) : '' ?>" style="<?= $thumb ? '' : 'display:none' ?>" alt="">
                      <input type="file" class="e-photo-file" accept="image/*" onchange="uploadPhoto('<?= h($rowId) ?>', this)">
                      <span class="wdwa-photo-status e-photo-status"></span>
                      <input type="hidden" class="e-photo-key" value="<?= h($p['photo_key']) ?>">
                    </div>
                  </div>
                  <label style="display:flex;align-items:center;gap:6px;font-size:13px"><input type="checkbox" class="e-active"<?= $p['enabled']?' checked':'' ?>> Active</label>
                  <button class="btn-save-row" onclick="saveEdit(<?= (int)$p['id'] ?>, '<?= h($rowId) ?>')">Save</button>
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
var AGENT_LOOKUP = <?= json_encode($lookup, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;

function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function flash(msg, type) {
  type = type || 'ok';
  var el = document.getElementById('flash-area');
  el.innerHTML = '<div class="flash-' + type + '">' + msg + '</div>';
  setTimeout(function () { el.innerHTML = ''; }, 4500);
}

function post(data) {
  return fetch('api/who_does_what_action.php', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(Object.assign({ csrf: window.AE_CSRF || '' }, data))
  }).then(function (r) { return r.json(); });
}

function autofillFromLookup(prefix) {
  var email = document.getElementById(prefix + '-email').value.trim().toLowerCase();
  var info = AGENT_LOOKUP[email];
  if (!info) return;
  var nameEl = document.getElementById(prefix + '-name');
  var phoneEl = document.getElementById(prefix + '-phone');
  if (nameEl && !nameEl.value && info.name) nameEl.value = info.name;
  if (phoneEl && !phoneEl.value && info.phone) phoneEl.value = info.phone;
}

function uploadPhoto(scope, fileInput) {
  var isAdd = scope === 'add';
  fileInput = fileInput || document.getElementById('add-photo-file');
  var file = fileInput.files[0];
  if (!file) return;
  var statusEl = isAdd ? document.getElementById('add-photo-status') : fileInput.closest('.wdwa-photo-row').querySelector('.e-photo-status');
  var thumbEl  = isAdd ? document.getElementById('add-photo-thumb')  : fileInput.closest('.wdwa-photo-row').querySelector('.e-photo-thumb');
  var keyEl    = isAdd ? document.getElementById('add-photo-key')    : fileInput.closest('.wdwa-photo-row').querySelector('.e-photo-key');
  statusEl.textContent = 'Uploading…';
  var fd = new FormData();
  fd.append('photo', file);
  fd.append('csrf', window.AE_CSRF || '');
  fetch('api/who_does_what_action.php?action=upload_photo', { method: 'POST', credentials: 'same-origin', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d.ok) { statusEl.textContent = ''; flash(d.error || 'Upload failed', 'err'); return; }
      keyEl.value = d.photo_key;
      thumbEl.src = 'api/who_does_what_action.php?action=photo&key=' + encodeURIComponent(d.photo_key);
      thumbEl.style.display = '';
      statusEl.textContent = 'Uploaded';
      setTimeout(function () { statusEl.textContent = ''; }, 2000);
    })
    .catch(function () { statusEl.textContent = ''; flash('Upload failed', 'err'); });
}

function collectTags(container) {
  return Array.prototype.slice.call(container.querySelectorAll('input[type=checkbox]:checked')).map(function (c) { return c.value; });
}

function addPerson() {
  var email = document.getElementById('add-email').value.trim();
  var name  = document.getElementById('add-name').value.trim();
  var title = document.getElementById('add-title').value.trim();
  if (!email || !name) { flash('Email and name are required.', 'err'); return; }
  post({
    action: 'save',
    email: email, name: name, title: title,
    group_label: collectTags(document.getElementById('add-groups')),
    handles: document.getElementById('add-handles').value.trim(),
    tags: collectTags(document.getElementById('add-tags')),
    phone: document.getElementById('add-phone').value.trim(),
    booking_url: document.getElementById('add-booking').value.trim(),
    photo_key: document.getElementById('add-photo-key').value,
    sort_ord: parseInt(document.getElementById('add-sort').value) || 0,
    enabled: true
  }).then(function (d) {
    if (!d.ok) { flash(d.error || 'Save failed', 'err'); return; }
    flash('Added <strong>' + esc(name) + '</strong> to the directory.');
    setTimeout(function () { location.reload(); }, 700);
  });
}

function openEditRow(rowId, btn) {
  document.querySelectorAll('.edit-row').forEach(function (r) { r.style.display = 'none'; });
  document.querySelectorAll('.btn-edit-row').forEach(function (b) { b.textContent = 'Edit'; });
  document.getElementById(rowId).style.display = '';
  btn.textContent = 'Cancel';
  btn.onclick = function () { closeEditRow(rowId); };
}
function closeEditRow(rowId) {
  document.getElementById(rowId).style.display = 'none';
  document.querySelectorAll('.btn-edit-row').forEach(function (b) {
    b.textContent = 'Edit';
    b.onclick = null;
  });
  location.reload();
}

function saveEdit(id, rowId) {
  var row = document.getElementById(rowId);
  var email = row.querySelector('.e-email').value.trim();
  var name  = row.querySelector('.e-name').value.trim();
  if (!email || !name) { flash('Email and name are required.', 'err'); return; }
  post({
    action: 'save', id: id,
    email: email, name: name,
    title: row.querySelector('.e-title').value.trim(),
    group_label: collectTags(row.querySelector('.e-groups')),
    handles: row.querySelector('.e-handles').value.trim(),
    tags: collectTags(row.querySelector('.e-tags')),
    phone: row.querySelector('.e-phone').value.trim(),
    booking_url: row.querySelector('.e-booking').value.trim(),
    photo_key: row.querySelector('.e-photo-key').value,
    sort_ord: parseInt(row.querySelector('.e-sort').value) || 0,
    enabled: row.querySelector('.e-active').checked
  }).then(function (d) {
    if (!d.ok) { flash(d.error || 'Save failed', 'err'); return; }
    flash('Saved <strong>' + esc(name) + '</strong>.');
    setTimeout(function () { location.reload(); }, 500);
  });
}

function toggleActive(btn, id) {
  post({ action: 'toggle', id: id }).then(function (d) {
    if (!d.ok) { flash(d.error || 'Toggle failed', 'err'); return; }
    var enabled = d.enabled === 1;
    btn.textContent = enabled ? 'Active' : 'Inactive';
    btn.className = 'toggle-btn ' + (enabled ? 'enabled' : 'disabled');
    var row = document.getElementById('row-' + id);
    if (row) row.classList.toggle('disabled', !enabled);
  });
}
</script>
</body>
</html>
