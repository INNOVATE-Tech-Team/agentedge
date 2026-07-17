<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';

$agent = require_login();
if (!is_super_admin()) { header('Location: index.php'); exit; }

$templates = array_values(pandadoc_state_templates_all());

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>PandaDoc Templates — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .add-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:20px 24px;margin-bottom:24px}
    .add-card h3{margin:0 0 14px;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
    .add-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
    .field-group{display:flex;flex-direction:column;gap:4px}
    .field-group.grow{flex:1;min-width:220px}
    .field-group.sm{min-width:80px;width:80px}
    .field-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint)}
    .field-input{padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;width:100%;box-sizing:border-box}
    .field-input:focus{outline:2px solid var(--green);border-color:var(--green)}
    .btn-add{padding:9px 20px;background:var(--green);color:#111;font-weight:800;font-size:13px;border:0;border-radius:6px;cursor:pointer;white-space:nowrap;align-self:flex-end}
    .btn-add:hover{background:var(--green-d,#5b8e0d);color:#fff}

    .pt-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;
              border:1px solid var(--border);border-radius:10px;overflow:hidden}
    .pt-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
                 color:var(--faint);padding:10px 14px;text-align:left;border-bottom:1px solid var(--border)}
    .pt-table td{padding:9px 14px;border-top:1px solid var(--border);vertical-align:middle}
    .pt-table tr:last-child td{border-bottom:none}
    .pt-table tr.data-row:hover td{background:#fafafa}

    .state-chip{font-size:11px;font-weight:700;background:#eef5e8;color:#5b8e0d;padding:2px 7px;border-radius:4px}
    .tmpl-id{font-size:12px;font-family:monospace;color:#555}
    .btn-delete{padding:4px 10px;border:1px solid #fcc;background:#fff;color:#c00;border-radius:4px;font-size:12px;cursor:pointer}
    .btn-delete:hover{background:#fff0f0}

    .empty-state{text-align:center;padding:40px;color:var(--faint);font-size:14px}
    .flash-ok{padding:10px 14px;background:#eef5e8;border:1px solid #c3dfa8;border-radius:6px;color:#3a6b1a;font-size:13px;margin-bottom:16px}
    .flash-err{padding:10px 14px;background:#fff0f0;border:1px solid #f5c6c6;border-radius:6px;color:#c00;font-size:13px;margin-bottom:16px}
    .count-strip{font-size:12px;color:var(--faint);margin-bottom:10px}
    .default-note{font-size:12px;color:var(--faint);margin-bottom:20px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('admin_pandadoc_templates', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)">Technology</div>
        <div class="content-title">PandaDoc Templates</div>
      </div>
    </header>
    <main class="wrap">

      <div id="flash-area"></div>
      <div class="default-note">
        Onboarding document signing uses the template mapped to the agent's state below. Any state without
        a row here falls back to the global default template configured in <code>config.php</code>
        (<code>pandadoc_template_id</code>).
      </div>

      <!-- Add form -->
      <div class="add-card">
        <h3>Add / Update State Template</h3>
        <div class="add-row">
          <div class="field-group sm">
            <div class="field-label">State</div>
            <input type="text" id="add-state" class="field-input" placeholder="PA" maxlength="2"
                   style="text-transform:uppercase" autocomplete="off">
          </div>
          <div class="field-group grow">
            <div class="field-label">Template ID</div>
            <input type="text" id="add-template" class="field-input" placeholder="PandaDoc template UUID"
                   autocomplete="off">
          </div>
          <button class="btn-add" onclick="saveTemplate()">Save</button>
        </div>
      </div>

      <?php $count = count($templates); ?>
      <div class="count-strip"><?= $count ?> state template<?= $count === 1 ? '' : 's' ?> configured</div>

      <?php if (!$templates): ?>
        <div class="pt-table" style="border-radius:10px">
          <div class="empty-state">No state-specific templates yet. Add one above — every state uses the global default until then.</div>
        </div>
      <?php else: ?>
      <table class="pt-table" id="pt-table">
        <thead>
          <tr>
            <th>State</th>
            <th>Template ID</th>
            <th>Last Updated</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="pt-tbody">
        <?php foreach ($templates as $t): ?>
          <tr class="data-row" id="row-<?= h($t['state_code']) ?>">
            <td><span class="state-chip"><?= h($t['state_code']) ?></span></td>
            <td><span class="tmpl-id"><?= h($t['template_id']) ?></span></td>
            <td style="color:var(--faint)"><?= h($t['updated_at']) ?><?= $t['updated_by'] ? ' — ' . h($t['updated_by']) : '' ?></td>
            <td style="text-align:right">
              <button class="btn-delete" onclick="deleteTemplate('<?= h(addslashes($t['state_code'])) ?>')">Delete</button>
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
  setTimeout(() => el.innerHTML='', 4000);
}

function post(data) {
  return fetch('api/pandadoc_template_action.php', {
    method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(data)
  }).then(r => r.json());
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function saveTemplate() {
  const state = document.getElementById('add-state').value.trim().toUpperCase();
  const tmpl  = document.getElementById('add-template').value.trim();
  if (state.length !== 2) { flash('State code must be 2 letters.', 'err'); return; }
  if (!tmpl) { flash('Template ID is required.', 'err'); return; }
  post({action:'save', state_code:state, template_id:tmpl}).then(d => {
    if (!d.ok) { flash(d.error || 'Save failed', 'err'); return; }
    flash(`Template saved for <strong>${esc(state)}</strong>.`);
    location.reload();
  });
}

function deleteTemplate(state) {
  if (!confirm(`Remove the template for "${state}"? Agents in this state will fall back to the global default.`)) return;
  post({action:'delete', state_code:state}).then(d => {
    if (!d.ok) { flash(d.error||'Delete failed','err'); return; }
    const row = document.getElementById('row-' + state);
    if (row) row.remove();
    flash(`Removed template for <strong>${esc(state)}</strong>.`);
  });
}
</script>
</body>
</html>
