<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
$agent = require_login();
if (!can_access_finance_checklists()) { header('Location: index.php'); exit; }

$db = local_db();
$templates = $db->query("SELECT * FROM finance_checklist_templates ORDER BY active DESC, name")->fetchAll(PDO::FETCH_ASSOC);

$templateItemCounts = [];
foreach ($db->query("SELECT template_id, COUNT(*) AS n FROM finance_checklist_template_items GROUP BY template_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $templateItemCounts[$r['template_id']] = (int)$r['n'];
}

$runs = $db->query("SELECT r.*, t.name AS template_name, t.recurrence AS template_recurrence
    FROM finance_checklist_runs r
    JOIN finance_checklist_templates t ON t.id = r.template_id
    ORDER BY r.created_at DESC, r.id DESC")->fetchAll(PDO::FETCH_ASSOC);

$selectedRunId = (int)($_GET['run'] ?? ($runs[0]['id'] ?? 0));
$selectedRun = null;
foreach ($runs as $r) { if ($r['id'] == $selectedRunId) { $selectedRun = $r; break; } }

$runItems = [];
if ($selectedRunId) {
    $st = $db->prepare("SELECT * FROM finance_checklist_run_items WHERE run_id=? ORDER BY sort_ord, id");
    $st->execute([$selectedRunId]);
    $runItems = $st->fetchAll(PDO::FETCH_ASSOC);
}

$recurrenceLabels = ['weekly'=>'Weekly', 'monthly'=>'Monthly', 'quarterly'=>'Quarterly', 'annual'=>'Annual', 'one_time'=>'One-Time Project'];
$doneCount  = count(array_filter($runItems, fn($it) => $it['status'] === 'done'));
$totalCount = count($runItems);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Accounting Checklists — AgentEdge</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="stylesheet" href="assets/app.css">
<style>
.fin-eyebrow { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--faint); }

/* ── templates grid ── */
.tmpl-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; margin-bottom:24px; }
.tmpl-card { background:#fff; border:1px solid var(--border); border-radius:10px; padding:14px 16px; }
.tmpl-card.archived { opacity:.55; }
.tmpl-hdr { display:flex; align-items:center; gap:8px; margin-bottom:6px; }
.tmpl-name { font-size:15px; font-weight:700; flex:1; }
.rec-badge { font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; padding:2px 7px; border-radius:4px; white-space:nowrap; background:#e8f0ff; color:#3a4a9a; }
.rec-badge.one_time { background:#f4f9ec; color:#3a6b00; }
.rec-badge.weekly { background:#fdf0e0; color:#8a5a00; }
.tmpl-desc { font-size:12px; color:var(--muted); line-height:1.5; margin-bottom:10px; min-height:16px; }
.tmpl-meta { font-size:11px; color:var(--faint); margin-bottom:10px; }
.tmpl-actions { display:flex; gap:6px; flex-wrap:wrap; }
.btn-mini { padding:5px 10px; font-size:12px; border:1px solid var(--border); border-radius:6px; background:#fff; cursor:pointer; color:var(--ink); }
.btn-mini:hover { background:#f5f5f5; }
.btn-mini.primary { background:var(--green); border-color:var(--green); font-weight:700; }
.btn-mini.primary:hover { background:var(--green-d); color:#fff; }
.btn-mini.danger { color:#d73a49; border-color:#ffd7d7; }
.btn-mini.danger:hover { background:#fdecea; }
.btn-new-template { padding:7px 16px; background:var(--green); color:#111; border:0; border-radius:8px; font-weight:700; font-size:13px; cursor:pointer; margin-bottom:14px; }
.btn-new-template:hover { background:var(--green-d); color:#fff; }

/* ── run bar ── */
.run-bar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:16px; }
.run-select { padding:7px 12px; border:1px solid var(--border); border-radius:8px; font-size:14px; background:#fff; cursor:pointer; }
.run-select:focus { outline:2px solid var(--green); }
.run-progress-txt { font-size:13px; color:var(--muted); }

/* ── checklist table ── */
.chk-table { width:100%; border-collapse:collapse; font-size:13px; background:#fff; border:1px solid var(--border); border-radius:10px; overflow:hidden; }
.chk-table th { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--faint); padding:8px 12px; border-bottom:1px solid var(--border); text-align:left; white-space:nowrap; }
.chk-table td { padding:9px 12px; border-bottom:1px solid var(--border); vertical-align:middle; }
.chk-table tr:last-child td { border-bottom:none; }
.chk-table tr.done td { color:var(--faint); }
.chk-table tr.done .chk-title { text-decoration:line-through; }
.chk-table tr:hover td { background:#fafbfa; }
.chk-check { width:18px; height:18px; cursor:pointer; }
.chk-title { font-weight:600; }
.chk-desc { font-size:12px; color:var(--muted); margin-top:2px; }
.st-badge { font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; padding:2px 7px; border-radius:4px; white-space:nowrap; }
.st-todo { background:#fff3cd; color:#856404; }
.st-done { background:#e8f5d0; color:#2e7d32; }
.btn-icon { background:none; border:none; cursor:pointer; padding:3px 6px; border-radius:4px; color:var(--faint); font-size:14px; line-height:1; }
.btn-icon:hover { background:#f0f0f0; color:var(--ink); }
.btn-icon.del:hover { background:#fdecea; color:#d73a49; }
.add-line-btn { display:flex; align-items:center; gap:6px; padding:8px 12px; font-size:13px; color:var(--green-d); font-weight:600; cursor:pointer; border:none; background:none; }
.add-line-btn:hover { color:var(--green); }

/* ── modals ── */
.modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:200; display:none; align-items:center; justify-content:center; }
.modal-backdrop.open { display:flex; }
.modal { background:#fff; border-radius:12px; padding:24px; width:460px; max-width:calc(100vw - 32px); box-shadow:0 8px 32px rgba(0,0,0,.18); max-height:calc(100vh - 64px); overflow-y:auto; }
.modal h3 { font-size:17px; font-weight:700; margin-bottom:16px; }
.modal .field { margin-bottom:14px; }
.modal .field label { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--faint); margin-bottom:4px; }
.modal input, .modal select, .modal textarea { width:100%; padding:8px 10px; border:1px solid var(--border); border-radius:8px; font-size:14px; box-sizing:border-box; }
.modal input:focus, .modal select:focus, .modal textarea:focus { outline:2px solid var(--green); }
.modal textarea { resize:vertical; min-height:60px; }
.modal-row { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.modal-actions { display:flex; gap:8px; justify-content:flex-end; margin-top:20px; }
.btn-cancel { padding:8px 18px; border:1px solid var(--border); border-radius:8px; background:#fff; font-size:14px; cursor:pointer; }
.btn-cancel:hover { background:#f5f5f5; }
.btn-save { padding:8px 18px; background:var(--green); color:#111; border:0; border-radius:8px; font-weight:700; font-size:14px; cursor:pointer; }
.btn-save:hover { background:var(--green-d); color:#fff; }

/* ── template items list (inside Manage Items modal) ── */
.ti-row { display:flex; align-items:center; gap:8px; padding:8px 0; border-bottom:1px solid var(--border); }
.ti-row:last-child { border-bottom:none; }
.ti-main { flex:1; font-size:13px; }
.ti-assignee { font-size:11px; color:var(--faint); }

/* ── empty state ── */
.chk-empty { text-align:center; padding:60px 24px; color:var(--faint); background:#fff; border:1px solid var(--border); border-radius:10px; }
.chk-empty h3 { font-size:17px; font-weight:700; margin-bottom:6px; color:var(--ink); }
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('finance_checklists', $agent); ?>
<div class="content">
  <div class="content-top">
    <div>
      <div class="fin-eyebrow">Back Office / Finance</div>
      <div class="content-title">Accounting Checklists</div>
    </div>
    <div class="content-hello">Recurring close checklists and one-off projects for the accounting team</div>
  </div>
  <div class="wrap">

    <button class="btn-new-template" onclick="openTemplateModal()">+ New Template / Project</button>

    <?php if (empty($templates)): ?>
    <div class="chk-empty" style="margin-bottom:24px">
      <h3>No templates yet</h3>
      <p>Create a recurring checklist (e.g. "Monthly Close") or a one-off project to get started.</p>
    </div>
    <?php else: ?>
    <div class="tmpl-grid">
      <?php foreach ($templates as $t):
        $recLabel = $recurrenceLabels[$t['recurrence']] ?? $t['recurrence'];
        $itemCount = $templateItemCounts[$t['id']] ?? 0;
      ?>
      <div class="tmpl-card<?= $t['active'] ? '' : ' archived' ?>" id="tc-<?= $t['id'] ?>">
        <div class="tmpl-hdr">
          <span class="tmpl-name"><?= htmlspecialchars($t['name']) ?></span>
          <span class="rec-badge <?= $t['recurrence'] ?>"><?= htmlspecialchars($recLabel) ?></span>
        </div>
        <div class="tmpl-desc"><?= htmlspecialchars($t['description']) ?></div>
        <div class="tmpl-meta"><?= $itemCount ?> checklist item<?= $itemCount !== 1 ? 's' : '' ?><?= $t['active'] ? '' : ' · Archived' ?></div>
        <div class="tmpl-actions">
          <button class="btn-mini primary" onclick="openRunModal(<?= $t['id'] ?>, <?= htmlspecialchars(json_encode($t['name'])) ?>)">+ Start Run</button>
          <button class="btn-mini" onclick="openItemsModal(<?= $t['id'] ?>, <?= htmlspecialchars(json_encode($t['name'])) ?>)">Manage Items</button>
          <button class="btn-mini" onclick="openTemplateModal(<?= htmlspecialchars(json_encode($t)) ?>)">Edit</button>
          <?php if ($t['active']): ?>
          <button class="btn-mini" onclick="archiveTemplate(<?= $t['id'] ?>, true)">Archive</button>
          <?php else: ?>
          <button class="btn-mini" onclick="archiveTemplate(<?= $t['id'] ?>, false)">Unarchive</button>
          <?php endif; ?>
          <button class="btn-mini danger" onclick="deleteTemplate(<?= $t['id'] ?>, <?= htmlspecialchars(json_encode($t['name'])) ?>)">Delete</button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Run selector -->
    <div class="run-bar">
      <?php if (!empty($runs)): ?>
      <form method="get" style="display:flex;align-items:center;gap:8px">
        <label style="font-size:13px;font-weight:600;color:var(--muted)">Run:</label>
        <select class="run-select" name="run" onchange="this.form.submit()">
          <?php foreach ($runs as $r): ?>
          <option value="<?= $r['id'] ?>" <?= $r['id']==$selectedRunId?'selected':'' ?>>
            <?= htmlspecialchars($r['template_name']) ?> — <?= htmlspecialchars($r['period_label']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php if ($selectedRun): ?>
      <span class="run-progress-txt"><?= $doneCount ?> / <?= $totalCount ?> complete</span>
      <button class="btn-icon del" style="border:1px solid #ffd7d7;color:#d73a49;border-radius:6px;padding:5px 10px;font-size:12px;margin-left:auto"
              onclick="deleteRun(<?= $selectedRunId ?>, <?= htmlspecialchars(json_encode($selectedRun['template_name'] . ' — ' . $selectedRun['period_label'])) ?>)">
        Delete Run
      </button>
      <?php endif; ?>
      <?php else: ?>
      <span style="font-size:13px;color:var(--muted)">No runs yet — start one from a template above.</span>
      <?php endif; ?>
    </div>

    <?php if ($selectedRun): ?>
    <table class="chk-table">
      <thead>
        <tr>
          <th style="width:36px"></th>
          <th>Task</th>
          <th>Assigned To</th>
          <th>Due Date</th>
          <th style="width:80px">Status</th>
          <th>Notes</th>
          <th style="width:70px"></th>
        </tr>
      </thead>
      <tbody id="chkBody">
        <?php foreach ($runItems as $it): ?>
        <tr id="ri-<?= $it['id'] ?>" class="<?= $it['status'] === 'done' ? 'done' : '' ?>">
          <td><input type="checkbox" class="chk-check" <?= $it['status']==='done'?'checked':'' ?> onchange="toggleItem(<?= $it['id'] ?>)"></td>
          <td>
            <div class="chk-title"><?= htmlspecialchars($it['title']) ?></div>
            <?php if ($it['description']): ?><div class="chk-desc"><?= htmlspecialchars($it['description']) ?></div><?php endif; ?>
          </td>
          <td style="font-size:12px"><?= htmlspecialchars($it['assigned_to_email']) ?></td>
          <td style="font-size:12px"><?= htmlspecialchars($it['due_date']) ?></td>
          <td><span class="st-badge <?= $it['status']==='done'?'st-done':'st-todo' ?>"><?= $it['status']==='done'?'Done':'To Do' ?></span></td>
          <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($it['notes']) ?></td>
          <td>
            <button class="btn-icon" onclick="editRunItem(<?= htmlspecialchars(json_encode($it)) ?>)" title="Edit">✏️</button>
            <button class="btn-icon del" onclick="deleteRunItem(<?= $it['id'] ?>)" title="Delete">✕</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:#fafbfa">
          <td colspan="7">
            <button class="add-line-btn" onclick="openAddRunItem()">+ Add ad-hoc item</button>
          </td>
        </tr>
      </tfoot>
    </table>
    <?php elseif (!empty($runs)): ?>
    <div class="chk-empty"><h3>Select a run above</h3></div>
    <?php endif; ?>

  </div><!-- /wrap -->
</div><!-- /content -->
</div><!-- /layout -->

<!-- New / Edit Template Modal -->
<div class="modal-backdrop" id="templateModal">
  <div class="modal">
    <h3 id="templateModalTitle">New Template / Project</h3>
    <input type="hidden" id="tId" value="">
    <div class="field">
      <label>Name</label>
      <input type="text" id="tName" placeholder="e.g. Monthly Close, Quarterly 1099 Prep, RV Accountable Plan Setup">
    </div>
    <div class="field">
      <label>Description</label>
      <textarea id="tDesc" placeholder="Optional detail"></textarea>
    </div>
    <div class="field">
      <label>Recurrence</label>
      <select id="tRecurrence">
        <option value="weekly">Weekly</option>
        <option value="monthly">Monthly</option>
        <option value="quarterly">Quarterly</option>
        <option value="annual">Annual</option>
        <option value="one_time">One-Time Project</option>
      </select>
    </div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeTemplateModal()">Cancel</button>
      <button class="btn-save" onclick="saveTemplate()">Save</button>
    </div>
  </div>
</div>

<!-- Manage Template Items Modal -->
<div class="modal-backdrop" id="itemsModal">
  <div class="modal" style="width:520px">
    <h3 id="itemsModalTitle">Checklist Items</h3>
    <input type="hidden" id="itTemplateId" value="">
    <div id="itemsList"></div>
    <div class="field" style="margin-top:14px;border-top:1px solid var(--border);padding-top:14px">
      <label>New Item Title</label>
      <input type="text" id="newItemTitle" placeholder="e.g. Reconcile SeaShore checking">
      <div class="modal-row" style="margin-top:10px">
        <input type="text" id="newItemAssignee" placeholder="Default assignee email (optional)">
        <input type="text" id="newItemDesc" placeholder="Description (optional)">
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeItemsModal()">Close</button>
      <button class="btn-save" onclick="addTemplateItem()">+ Add Item</button>
    </div>
  </div>
</div>

<!-- New Run Modal -->
<div class="modal-backdrop" id="runModal">
  <div class="modal">
    <h3 id="runModalTitle">Start New Run</h3>
    <input type="hidden" id="runTemplateId" value="">
    <div class="field">
      <label>Period Label</label>
      <input type="text" id="runPeriodLabel" placeholder="e.g. July 2026, Q3 2026">
    </div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeRunModal()">Cancel</button>
      <button class="btn-save" onclick="saveRun()">Start Run</button>
    </div>
  </div>
</div>

<!-- Add / Edit Run Item Modal -->
<div class="modal-backdrop" id="runItemModal">
  <div class="modal">
    <h3 id="runItemModalTitle">Add Item</h3>
    <input type="hidden" id="riId" value="">
    <div class="field">
      <label>Title</label>
      <input type="text" id="riTitle" placeholder="e.g. Reconcile SeaShore checking">
    </div>
    <div class="field">
      <label>Description</label>
      <textarea id="riDesc" placeholder="Optional detail"></textarea>
    </div>
    <div class="modal-row">
      <div class="field">
        <label>Assigned To (email)</label>
        <input type="text" id="riAssignee" placeholder="dominic@innovateonline.com">
      </div>
      <div class="field">
        <label>Due Date</label>
        <input type="date" id="riDue">
      </div>
    </div>
    <div class="field">
      <label>Notes</label>
      <textarea id="riNotes" placeholder="Optional notes"></textarea>
    </div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeRunItemModal()">Cancel</button>
      <button class="btn-save" onclick="saveRunItem()">Save</button>
    </div>
  </div>
</div>

<script>
const SELECTED_RUN_ID = <?= $selectedRunId ?: 'null' ?>;

function api(action, payload) {
    return fetch('api/finance_checklists.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify(Object.assign({action}, payload))
    }).then(r => r.json());
}

// ── Template modal ────────────────────────────────────────────────────────────
function openTemplateModal(t) {
    document.getElementById('tId').value = t ? t.id : '';
    document.getElementById('templateModalTitle').textContent = t ? 'Edit Template' : 'New Template / Project';
    document.getElementById('tName').value = t ? t.name : '';
    document.getElementById('tDesc').value = t ? t.description : '';
    document.getElementById('tRecurrence').value = t ? t.recurrence : 'monthly';
    document.getElementById('templateModal').classList.add('open');
}
function closeTemplateModal() { document.getElementById('templateModal').classList.remove('open'); }
function saveTemplate() {
    const id   = document.getElementById('tId').value;
    const name = document.getElementById('tName').value.trim();
    const description = document.getElementById('tDesc').value.trim();
    const recurrence  = document.getElementById('tRecurrence').value;
    if (!name) { alert('Name is required.'); return; }
    api(id ? 'update_template' : 'create_template', {id: id || null, name, description, recurrence})
        .then(d => { if (d.ok) location.reload(); else alert(d.error || 'Save failed.'); });
}
function archiveTemplate(id, archive) {
    api(archive ? 'archive_template' : 'unarchive_template', {id}).then(d => { if (d.ok) location.reload(); });
}
function deleteTemplate(id, name) {
    if (!confirm('Delete template "' + name + '"? This only works if it has no runs yet.')) return;
    api('delete_template', {id}).then(d => { if (d.ok) location.reload(); else alert(d.error || 'Delete failed.'); });
}

// ── Template items modal ──────────────────────────────────────────────────────
function openItemsModal(templateId, templateName) {
    document.getElementById('itTemplateId').value = templateId;
    document.getElementById('itemsModalTitle').textContent = 'Checklist Items — ' + templateName;
    document.getElementById('newItemTitle').value = '';
    document.getElementById('newItemAssignee').value = '';
    document.getElementById('newItemDesc').value = '';
    loadTemplateItems(templateId);
    document.getElementById('itemsModal').classList.add('open');
}
function closeItemsModal() { document.getElementById('itemsModal').classList.remove('open'); }
function loadTemplateItems(templateId) {
    api('list_template_items', {template_id: templateId}).then(d => {
        const list = document.getElementById('itemsList');
        if (!d.ok || !d.items.length) { list.innerHTML = '<div style="font-size:13px;color:var(--muted)">No items yet.</div>'; return; }
        list.innerHTML = d.items.map(it => `
            <div class="ti-row">
              <div class="ti-main">${esc(it.title)}${it.default_assignee_email ? '<div class="ti-assignee">' + esc(it.default_assignee_email) + '</div>' : ''}</div>
              <button class="btn-icon del" onclick="deleteTemplateItem(${it.id}, ${templateId})" title="Remove">✕</button>
            </div>`).join('');
    });
}
function addTemplateItem() {
    const templateId = document.getElementById('itTemplateId').value;
    const title = document.getElementById('newItemTitle').value.trim();
    const default_assignee_email = document.getElementById('newItemAssignee').value.trim();
    const description = document.getElementById('newItemDesc').value.trim();
    if (!title) { alert('Item title is required.'); return; }
    api('add_template_item', {template_id: templateId, title, default_assignee_email, description}).then(d => {
        if (d.ok) {
            document.getElementById('newItemTitle').value = '';
            document.getElementById('newItemAssignee').value = '';
            document.getElementById('newItemDesc').value = '';
            loadTemplateItems(templateId);
        } else alert(d.error || 'Add failed.');
    });
}
function deleteTemplateItem(id, templateId) {
    api('delete_template_item', {id}).then(d => { if (d.ok) loadTemplateItems(templateId); });
}

// ── Run modal ─────────────────────────────────────────────────────────────────
function openRunModal(templateId, templateName) {
    document.getElementById('runTemplateId').value = templateId;
    document.getElementById('runModalTitle').textContent = 'Start New Run — ' + templateName;
    document.getElementById('runPeriodLabel').value = '';
    document.getElementById('runModal').classList.add('open');
}
function closeRunModal() { document.getElementById('runModal').classList.remove('open'); }
function saveRun() {
    const template_id = document.getElementById('runTemplateId').value;
    const period_label = document.getElementById('runPeriodLabel').value.trim();
    if (!period_label) { alert('Period label is required.'); return; }
    api('start_run', {template_id, period_label}).then(d => {
        if (d.ok) location.href = 'finance_checklists.php?run=' + d.id;
        else alert(d.error || 'Could not start run.');
    });
}
function deleteRun(id, label) {
    if (!confirm('Delete run "' + label + '" and all its checklist items? This cannot be undone.')) return;
    api('delete_run', {id}).then(d => { if (d.ok) location.href = 'finance_checklists.php'; });
}

// ── Run item modal ────────────────────────────────────────────────────────────
function openAddRunItem() {
    document.getElementById('riId').value = '';
    document.getElementById('runItemModalTitle').textContent = 'Add Item';
    document.getElementById('riTitle').value = '';
    document.getElementById('riDesc').value = '';
    document.getElementById('riAssignee').value = '';
    document.getElementById('riDue').value = '';
    document.getElementById('riNotes').value = '';
    document.getElementById('runItemModal').classList.add('open');
}
function editRunItem(it) {
    document.getElementById('riId').value = it.id;
    document.getElementById('runItemModalTitle').textContent = 'Edit Item';
    document.getElementById('riTitle').value = it.title;
    document.getElementById('riDesc').value = it.description;
    document.getElementById('riAssignee').value = it.assigned_to_email;
    document.getElementById('riDue').value = it.due_date;
    document.getElementById('riNotes').value = it.notes;
    document.getElementById('runItemModal').classList.add('open');
}
function closeRunItemModal() { document.getElementById('runItemModal').classList.remove('open'); }
function saveRunItem() {
    const id = document.getElementById('riId').value;
    const title = document.getElementById('riTitle').value.trim();
    const description = document.getElementById('riDesc').value.trim();
    const assigned_to_email = document.getElementById('riAssignee').value.trim();
    const due_date = document.getElementById('riDue').value;
    const notes = document.getElementById('riNotes').value.trim();
    if (!title) { alert('Title is required.'); return; }
    const payload = {title, description, assigned_to_email, due_date, notes};
    if (id) payload.id = id; else payload.run_id = SELECTED_RUN_ID;
    api(id ? 'update_run_item' : 'add_run_item', payload).then(d => { if (d.ok) location.reload(); else alert(d.error || 'Save failed.'); });
}
function deleteRunItem(id) {
    if (!confirm('Remove this item?')) return;
    api('delete_run_item', {id}).then(d => { if (d.ok) document.getElementById('ri-'+id)?.remove(); });
}
function toggleItem(id) {
    api('toggle_run_item', {id}).then(d => { if (d.ok) location.reload(); });
}

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

document.querySelectorAll('.modal-backdrop').forEach(bd => {
    bd.addEventListener('click', e => { if (e.target === bd) bd.classList.remove('open'); });
});
</script>
</body>
</html>
