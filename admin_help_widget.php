<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';

$agent = require_login();
if (!is_super_admin()) { header('Location: index.php'); exit; }

$db        = local_db();
$shortcuts = $db->query("SELECT * FROM help_shortcuts ORDER BY sort_ord,id")->fetchAll(PDO::FETCH_ASSOC);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Help Widget — AgentEdge Admin</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .btn-primary{padding:8px 16px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer}
    .btn-primary:hover{background:#5b8e0d;color:#fff}
    .btn-sm{padding:5px 12px;font-size:11px;font-weight:700;border-radius:4px;border:1px solid #ddd;background:white;cursor:pointer;color:#333}
    .btn-sm:hover{border-color:#82C112;color:#5b8e0d}
    .btn-danger{background:#fee2e2;color:#c00;border-color:#f5c6c6}
    .btn-danger:hover{background:#fecaca;border-color:#e53935}
    .section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px}
    .section-title{font-size:16px;font-weight:900;color:#111}
    .settings-table{width:100%;border-collapse:collapse;font-size:13px}
    .settings-table th{text-align:left;padding:8px 10px;background:#f5f5f5;border-bottom:2px solid #e0e0e0;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#555}
    .settings-table td{padding:8px 10px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
    .settings-table tr:last-child td{border-bottom:none}
    .settings-table tr.dragging{opacity:.4}
    .settings-table tr.drag-over{outline:2px solid #82C112;outline-offset:-2px}
    .drag-handle{cursor:grab;color:#bbb;font-size:16px;padding:4px 6px;user-select:none;line-height:1}
    .drag-handle:hover{color:#666}
    .drag-handle:active{cursor:grabbing}
    .shortcut-icon-cell{font-size:20px;text-align:center;width:40px}
    .shortcut-icon-cell img{width:22px;height:22px;object-fit:contain;vertical-align:middle}
    .shortcut-url{color:#888;font-size:12px;word-break:break-all}
    .empty-table{text-align:center;color:#bbb;padding:40px;font-size:13px}
    .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:300;align-items:center;justify-content:center}
    .modal-overlay.open{display:flex}
    .modal{background:white;border-radius:12px;padding:24px;width:440px;max-width:96vw;box-shadow:0 8px 40px rgba(0,0,0,.18)}
    .modal h3{margin:0 0 18px;font-size:15px;font-weight:800}
    .field{margin-bottom:12px}
    .field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px}
    .field input{width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;box-sizing:border-box}
    .field input:focus{outline:2px solid #82C112}
    .modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:16px}
    .btn-cancel{padding:8px 14px;border:1px solid #ccc;background:white;color:#555;border-radius:6px;cursor:pointer;font-size:13px}
    .emoji-row{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}
    .emoji-pick{font-size:18px;cursor:pointer;padding:4px;border-radius:4px;border:1.5px solid transparent}
    .emoji-pick:hover,.emoji-pick.active{border-color:#82C112;background:#f0f9e8}
    .icon-input-row{display:flex;align-items:center;gap:10px}
    .icon-preview{width:36px;height:36px;border-radius:8px;border:1.5px dashed #ccc;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;overflow:hidden;background:#fafafa}
    .icon-preview img{width:100%;height:100%;object-fit:contain}
    .icon-upload-link{font-size:11px;color:#5b8e0d;font-weight:700;cursor:pointer;text-decoration:underline;background:none;border:none;padding:0}
    .save-toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(20px);background:#111;color:#fff;padding:10px 20px;border-radius:8px;font-size:13px;font-weight:700;opacity:0;pointer-events:none;transition:all 200ms;z-index:500}
    .save-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('admin_help_widget', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Help Widget</div>
    </header>
    <main class="wrap">

      <div class="card" style="padding:20px 24px">
        <div class="section-header">
          <div>
            <div class="section-title">Shortcuts</div>
            <p style="font-size:13px;color:#666;margin:4px 0 0">
              Shown in the floating "?" Help Center panel, below the lesson search. Drag <span style="font-size:16px">⠿</span> to reorder.
              Hidden shortcuts stay in this list but won't show to agents.
            </p>
          </div>
          <button class="btn-primary" onclick="openShortcutModal()">+ Add shortcut</button>
        </div>

        <table class="settings-table">
          <thead><tr>
            <th style="width:28px"></th>
            <th style="width:40px">Icon</th>
            <th>Label</th>
            <th>Link</th>
            <th style="width:70px">Visible</th>
            <th style="width:110px"></th>
          </tr></thead>
          <tbody id="sc-tbody">
          <?php if (empty($shortcuts)): ?>
            <tr id="sc-empty"><td colspan="6" class="empty-table">No shortcuts yet — add one above.</td></tr>
          <?php else: foreach ($shortcuts as $s): ?>
            <tr class="sc-row" data-id="<?= (int)$s['id'] ?>">
              <td><span class="drag-handle" title="Drag to reorder">⠿</span></td>
              <td class="shortcut-icon-cell">
                <?php if (str_starts_with($s['icon'], 'img:')): ?>
                <img src="api/help_action.php?icon=<?= urlencode(substr($s['icon'], 4)) ?>" alt="">
                <?php else: ?>
                <?= h($s['icon']) ?>
                <?php endif; ?>
              </td>
              <td style="font-weight:700"><?= h($s['label']) ?></td>
              <td class="shortcut-url"><?= h($s['url']) ?><?php if (!empty($s['is_ext'])): ?> <span title="Opens in a new tab" style="color:#5b8e0d">&#8599;</span><?php endif; ?></td>
              <td style="text-align:center">
                <input type="checkbox" style="accent-color:#82C112" <?= $s['visible'] ? 'checked' : '' ?> onchange="toggleVisible(<?= (int)$s['id'] ?>,this.checked)">
              </td>
              <td>
                <button class="btn-sm" onclick='editShortcut(<?= json_encode($s, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_HEX_TAG) ?>)'>Edit</button>
                <button class="btn-sm btn-danger" onclick="deleteShortcut(<?= (int)$s['id'] ?>,'<?= h(addslashes($s['label'])) ?>')">Delete</button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

    </main>
  </div>
</div>

<!-- Shortcut modal -->
<div class="modal-overlay" id="sc-modal">
  <div class="modal">
    <h3 id="sc-modal-title">Add Shortcut</h3>
    <input type="hidden" id="sc-id" value="">
    <div class="field"><label>Label</label><input type="text" id="sc-label" placeholder="e.g. Contact Support"></div>
    <div class="field">
      <label>Link (URL or internal page)</label>
      <input type="text" id="sc-url" placeholder="e.g. https://... or backoffice_tickets.php">
      <div style="font-size:11px;color:#888;margin-top:4px">Use <code>action:get_support</code> to open the Get Support ticket modal instead of navigating.</div>
    </div>
    <div class="field">
      <label>Icon</label>
      <div class="icon-input-row">
        <div class="icon-preview" id="sc-icon-preview">🔗</div>
        <input type="text" id="sc-icon" value="🔗" maxlength="4" style="width:70px">
        <input type="file" id="sc-icon-file" accept="image/*" style="display:none" onchange="uploadShortcutIcon(this.files[0])">
        <button type="button" class="icon-upload-link" onclick="document.getElementById('sc-icon-file').click()">Upload image…</button>
      </div>
      <div class="emoji-row">
        <?php foreach (['🔗','❓','📞','✉️','📚','🎓','🏠','📋','🔑','💰','📊','🛠️','🌟','🤝','🎯'] as $e): ?>
        <span class="emoji-pick" onclick="pickEmoji('<?= $e ?>')"><?= $e ?></span>
        <?php endforeach; ?>
      </div>
      <div id="sc-icon-status" style="font-size:11px;color:#888;margin-top:4px"></div>
    </div>
    <div class="field" style="display:flex;align-items:center;gap:8px">
      <input type="checkbox" id="sc-visible" checked style="width:auto;accent-color:#82C112">
      <label for="sc-visible" style="margin:0;text-transform:none;font-weight:600;font-size:13px;color:#333">Visible in Help Center</label>
    </div>
    <div class="field" style="display:flex;align-items:center;gap:8px">
      <input type="checkbox" id="sc-ext" style="width:auto;accent-color:#82C112">
      <label for="sc-ext" style="margin:0;text-transform:none;font-weight:600;font-size:13px;color:#333">Open in a new tab (use for external links)</label>
    </div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeModal('sc-modal')">Cancel</button>
      <button class="btn-primary" onclick="saveShortcut()">Save</button>
    </div>
  </div>
</div>

<div class="save-toast" id="save-toast">Saved ✓</div>

<script>
function api(body){return fetch('api/help_action.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}).then(r=>r.json());}
function toast(msg){const t=document.getElementById('save-toast');t.textContent=msg||'Saved ✓';t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2000);}
function closeModal(id){document.getElementById(id).classList.remove('open');}

function setIconPreview(icon){
  const prev = document.getElementById('sc-icon-preview');
  if ((icon||'').startsWith('img:')) {
    prev.innerHTML = `<img src="api/help_action.php?icon=${encodeURIComponent(icon.slice(4))}" alt="">`;
  } else {
    prev.textContent = icon || '🔗';
  }
}

function openShortcutModal(){
  document.getElementById('sc-modal-title').textContent='Add Shortcut';
  document.getElementById('sc-id').value='';
  document.getElementById('sc-label').value='';
  document.getElementById('sc-url').value='';
  document.getElementById('sc-icon').value='🔗';
  document.getElementById('sc-visible').checked=true;
  document.getElementById('sc-ext').checked=false;
  document.getElementById('sc-icon-status').textContent='';
  setIconPreview('🔗');
  document.querySelectorAll('.emoji-pick').forEach(el=>el.classList.remove('active'));
  document.getElementById('sc-modal').classList.add('open');
}

function editShortcut(s){
  document.getElementById('sc-modal-title').textContent='Edit Shortcut';
  document.getElementById('sc-id').value=s.id;
  document.getElementById('sc-label').value=s.label;
  document.getElementById('sc-url').value=s.url;
  document.getElementById('sc-icon').value=s.icon;
  document.getElementById('sc-visible').checked=!!parseInt(s.visible);
  document.getElementById('sc-ext').checked=!!parseInt(s.is_ext);
  document.getElementById('sc-icon-status').textContent='';
  setIconPreview(s.icon);
  document.querySelectorAll('.emoji-pick').forEach(el=>el.classList.toggle('active', el.textContent===s.icon));
  document.getElementById('sc-modal').classList.add('open');
}

function pickEmoji(e){
  document.getElementById('sc-icon').value=e;
  setIconPreview(e);
  document.querySelectorAll('.emoji-pick').forEach(el=>el.classList.toggle('active',el.textContent===e));
}

document.getElementById('sc-icon').addEventListener('input', function(){ setIconPreview(this.value); });

function uploadShortcutIcon(file){
  if(!file) return;
  const status = document.getElementById('sc-icon-status');
  status.textContent = 'Uploading…';
  const fd = new FormData();
  fd.append('file', file);
  fetch('api/help_action.php', {method:'POST', credentials:'same-origin', body: fd})
    .then(r=>r.json()).then(d=>{
      if(d.ok){
        document.getElementById('sc-icon').value = d.icon;
        setIconPreview(d.icon);
        document.querySelectorAll('.emoji-pick').forEach(el=>el.classList.remove('active'));
        status.textContent = 'Image uploaded ✓';
      } else {
        status.textContent = 'Error: ' + (d.error||'upload failed');
      }
    })
    .catch(()=>{ status.textContent = 'Upload failed'; });
}

function saveShortcut(){
  const id = document.getElementById('sc-id').value;
  const label = document.getElementById('sc-label').value.trim();
  const url = document.getElementById('sc-url').value.trim();
  const icon = document.getElementById('sc-icon').value.trim() || '🔗';
  const visible = document.getElementById('sc-visible').checked ? 1 : 0;
  const is_ext = document.getElementById('sc-ext').checked ? 1 : 0;
  if(!label || !url){ alert('Label and Link are required.'); return; }
  const body = {action:'save_shortcut', label, url, icon, visible, is_ext};
  if(id) body.id = parseInt(id);
  api(body).then(d=>{ if(d.ok) location.reload(); else alert(d.error||'Save failed'); });
}

function toggleVisible(id, checked){
  api({action:'toggle_shortcut_visible', id, visible: checked?1:0}).then(d=>{ if(d.ok) toast('Saved ✓'); });
}

function deleteShortcut(id, label){
  if(!confirm(`Delete shortcut "${label}"?`)) return;
  api({action:'delete_shortcut', id}).then(d=>{ if(d.ok) location.reload(); else alert(d.error||'Delete failed'); });
}

// ── Drag-to-reorder (same convention as admin_links.php) ────────────────────
function initDragSort(container, rowSelector, onSave) {
  let dragging = null;
  function getRows() { return [...container.querySelectorAll(rowSelector)]; }
  container.addEventListener('dragstart', e => {
    const row = e.target.closest(rowSelector);
    if (!row) return;
    dragging = row;
    row.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', row.dataset.id || '');
  });
  container.addEventListener('dragend', e => {
    const row = e.target.closest(rowSelector);
    if (!row) return;
    row.classList.remove('dragging');
    container.querySelectorAll(rowSelector).forEach(r => r.classList.remove('drag-over'));
    dragging = null;
    onSave(getRows());
  });
  container.addEventListener('dragover', e => {
    e.preventDefault();
    if (!dragging) return;
    const row = e.target.closest(rowSelector);
    if (!row || row === dragging) return;
    container.querySelectorAll(rowSelector).forEach(r => r.classList.remove('drag-over'));
    row.classList.add('drag-over');
    const rect = row.getBoundingClientRect();
    const after = e.clientY > rect.top + rect.height / 2;
    if (after) row.after(dragging); else row.before(dragging);
  });
  container.addEventListener('drop', e => e.preventDefault());
  container.addEventListener('mousedown', e => {
    const handle = e.target.closest('.drag-handle');
    if (!handle) return;
    const row = handle.closest(rowSelector);
    if (row) row.setAttribute('draggable', 'true');
  });
  container.addEventListener('mouseup', () => {
    getRows().forEach(r => r.removeAttribute('draggable'));
  });
}

const scTbody = document.getElementById('sc-tbody');
if (scTbody) initDragSort(scTbody, 'tr.sc-row', rows => {
  const ids = rows.map(r => r.dataset.id).filter(Boolean).map(Number);
  if (ids.length) api({action:'reorder_shortcuts', ids}).then(()=>toast('Order saved ✓'));
});
</script>
</body>
</html>
