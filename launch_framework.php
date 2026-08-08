<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/markdown.php';

$agent = require_login();
if (!can_view_launch_curriculum()) { header('Location: index.php'); exit; }

$db = local_db();
$fw = $db->query("SELECT * FROM launch_framework WHERE id=1")->fetch(PDO::FETCH_ASSOC);
if (!$fw) { header('Location: launch_curriculum.php'); exit; }

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($fw['title']) ?> — LAUNCH Curriculum</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .lw-nav{margin-bottom:16px}
    .lw-nav a{font-size:12px;font-weight:700;color:#555;text-decoration:none}
    .lw-nav a:hover{color:#82C112}
    .lw-header{border:1px solid var(--border);border-radius:10px;background:#fff;padding:20px 24px;margin-bottom:20px}
    .lw-title{font-size:22px;font-weight:900;color:#111;margin-bottom:8px}
    .lw-actions{margin-top:14px;display:flex;gap:8px}
    .btn-sm{padding:6px 14px;font-size:12px;font-weight:700;border-radius:5px;border:1px solid #ddd;background:#fff;cursor:pointer;color:#333}
    .btn-sm:hover{border-color:#82C112;color:#5b8e0d}
    .btn-primary{padding:6px 14px;font-size:12px;font-weight:800;border-radius:5px;border:0;background:#82C112;color:#111;cursor:pointer}
    .btn-primary:hover{background:#5b8e0d;color:#fff}

    .lw-body{border:1px solid var(--border);border-radius:10px;background:#fff;padding:24px 28px}
    .lw-body h2{font-size:18px;font-weight:800;color:#111;margin:28px 0 10px;padding-top:14px;border-top:1px solid #f0f0f0}
    .lw-body h2:first-child{margin-top:0;padding-top:0;border-top:0}
    .lw-body h3{font-size:15px;font-weight:800;color:#222;margin:20px 0 8px}
    .lw-body p{font-size:13.5px;line-height:1.65;color:#2a2a2a;margin:0 0 12px}
    .lw-body ul,.lw-body ol{margin:0 0 12px;padding-left:22px;font-size:13.5px;line-height:1.65;color:#2a2a2a}
    .lw-body li{margin-bottom:4px}
    .lw-body strong{color:#111}
    .lw-body code{background:#f3f3f3;padding:1px 5px;border-radius:3px;font-size:12.5px}
    .lw-body pre.lc-code{background:#f7f7f5;border:1px solid #e5e5e0;border-radius:6px;padding:12px 16px;font-size:12.5px;line-height:1.6;overflow-x:auto;white-space:pre;font-family:ui-monospace,Menlo,Consolas,monospace;margin:0 0 14px}
    .lc-table-wrap{overflow-x:auto;margin:0 0 14px}
    .lc-table{width:100%;border-collapse:collapse;font-size:12.5px}
    .lc-table th{background:#fafafa;font-weight:700;text-align:left;padding:7px 10px;border:1px solid #eee;white-space:nowrap}
    .lc-table td{padding:7px 10px;border:1px solid #eee;vertical-align:top}

    #edit-panel{display:none}
    #edit-panel.open{display:block}
    #view-panel.hidden{display:none}
    .field-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);margin-bottom:4px;display:block}
    .field-input{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;box-sizing:border-box;margin-bottom:12px}
    #content-md-input{width:100%;min-height:600px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;line-height:1.6;padding:14px;border:1px solid var(--border);border-radius:6px;box-sizing:border-box}
    .flash-ok{padding:10px 14px;background:#eef5e8;border:1px solid #c3dfa8;border-radius:6px;color:#3a6b1a;font-size:13px;margin-bottom:16px}
    .flash-err{padding:10px 14px;background:#fff0f0;border:1px solid #f5c6c6;border-radius:6px;color:#c00;font-size:13px;margin-bottom:16px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('launch_curriculum', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)">Agent Development</div>
        <div class="content-title">LAUNCH Curriculum</div>
      </div>
    </header>
    <main class="wrap">
      <div class="lw-nav"><a href="launch_curriculum.php">&larr; All Weeks</a></div>

      <div id="flash-area"></div>

      <div id="view-panel">
        <div class="lw-header">
          <div class="lw-title"><?= h($fw['title']) ?></div>
          <div class="lw-actions">
            <button class="btn-sm" onclick="openEdit()">Edit</button>
          </div>
        </div>
        <div class="lw-body"><?= render_launch_markdown($fw['content_md']) ?></div>
      </div>

      <div id="edit-panel">
        <div class="lw-header">
          <label class="field-label">Title</label>
          <input type="text" id="e-title" class="field-input" value="<?= h($fw['title']) ?>">
        </div>
        <div class="lw-body">
          <label class="field-label">Content (Markdown)</label>
          <textarea id="content-md-input"><?= h($fw['content_md']) ?></textarea>
          <div class="lw-actions">
            <button class="btn-primary" onclick="saveFramework()">Save</button>
            <button class="btn-sm" onclick="closeEdit()">Cancel</button>
          </div>
        </div>
      </div>
    </main>
  </div>
</div>
<script>
function flash(msg, type) {
  const el = document.getElementById('flash-area');
  el.innerHTML = `<div class="flash-${type||'ok'}">${msg}</div>`;
  if (type !== 'err') setTimeout(() => el.innerHTML = '', 4000);
}
function openEdit() {
  document.getElementById('view-panel').classList.add('hidden');
  document.getElementById('edit-panel').classList.add('open');
}
function closeEdit() {
  document.getElementById('edit-panel').classList.remove('open');
  document.getElementById('view-panel').classList.remove('hidden');
}
function saveFramework() {
  const body = {
    action: 'update_framework',
    title: document.getElementById('e-title').value.trim(),
    content_md: document.getElementById('content-md-input').value,
  };
  fetch('api/launch_curriculum_action.php', {
    method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)
  }).then(r => r.json()).then(d => {
    if (!d.ok) { flash(d.error || 'Save failed', 'err'); return; }
    flash('Saved.');
    setTimeout(() => location.reload(), 700);
  });
}
</script>
</body>
</html>
