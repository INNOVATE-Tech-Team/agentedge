<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/markdown.php';

$agent = require_login();
if (!can_view_launch_curriculum()) { header('Location: index.php'); exit; }

$sessionNumber = (int)($_GET['session'] ?? 0);
$db = local_db();
$st = $db->prepare("SELECT * FROM launch_sessions WHERE session_number=?");
$st->execute([$sessionNumber]);
$session = $st->fetch(PDO::FETCH_ASSOC);
if (!$session) { header('Location: launch_curriculum.php'); exit; }

$maxSession = (int)$db->query("SELECT MAX(session_number) FROM launch_sessions")->fetchColumn();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Session <?= (int)$session['session_number'] ?>: <?= h($session['title']) ?> — LAUNCH Curriculum</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .lw-nav{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
    .lw-nav a{font-size:12px;font-weight:700;color:#555;text-decoration:none}
    .lw-nav a:hover{color:#82C112}
    .lw-header{border:1px solid var(--border);border-radius:10px;background:#fff;padding:20px 24px;margin-bottom:20px}
    .lw-week-num{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#82C112;margin-bottom:6px}
    .lw-title{font-size:22px;font-weight:900;color:#111;margin-bottom:8px}
    .lw-quote{font-style:italic;color:var(--faint);margin-bottom:12px}
    .lw-meta-row{display:flex;flex-direction:column;gap:6px;font-size:13px}
    .lw-meta-row strong{color:#333}
    .lw-actions{margin-top:14px;display:flex;gap:8px}
    .btn-sm{padding:6px 14px;font-size:12px;font-weight:700;border-radius:5px;border:1px solid #ddd;background:#fff;cursor:pointer;color:#333}
    .btn-sm:hover{border-color:#82C112;color:#5b8e0d}
    .btn-primary{padding:6px 14px;font-size:12px;font-weight:800;border-radius:5px;border:0;background:#82C112;color:#111;cursor:pointer}
    .btn-primary:hover{background:#5b8e0d;color:#fff}

    .lw-body{border:1px solid var(--border);border-radius:10px;background:#fff;padding:24px 28px}
    .lw-body h2{font-size:18px;font-weight:800;color:#111;margin:28px 0 10px;padding-top:14px;border-top:1px solid #f0f0f0}
    .lw-body h2:first-child{margin-top:0;padding-top:0;border-top:0}
    .lw-body h3{font-size:15px;font-weight:800;color:#222;margin:20px 0 8px}
    .lw-body h4{font-size:13px;font-weight:800;color:#333;margin:16px 0 6px}
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
      <div class="lw-nav">
        <a href="launch_curriculum.php">&larr; All Sessions</a>
        <div>
          <?php if ($sessionNumber > 1): ?><a href="launch_session.php?session=<?= $sessionNumber - 1 ?>">&larr; Session <?= $sessionNumber - 1 ?></a><?php endif; ?>
          <?php if ($sessionNumber < $maxSession): ?> &nbsp;|&nbsp; <a href="launch_session.php?session=<?= $sessionNumber + 1 ?>">Session <?= $sessionNumber + 1 ?> &rarr;</a><?php endif; ?>
        </div>
      </div>

      <div id="flash-area"></div>

      <div id="view-panel">
        <div class="lw-header">
          <div class="lw-week-num">Session <?= (int)$session['session_number'] ?></div>
          <div class="lw-title"><?= h($session['title']) ?></div>
          <?php if ($session['theme_quote']): ?><div class="lw-quote">"<?= h($session['theme_quote']) ?>"</div><?php endif; ?>
          <div class="lw-meta-row">
            <?php if ($session['the_goal']): ?><div><strong>The Goal:</strong> <?= h($session['the_goal']) ?></div><?php endif; ?>
            <?php if ($session['primary_jobs']): ?><div><strong>Primary Job(s) This Session:</strong> <?= h($session['primary_jobs']) ?></div><?php endif; ?>
          </div>
          <div class="lw-actions">
            <button class="btn-sm" onclick="openEdit()">Edit</button>
          </div>
        </div>
        <div class="lw-body"><?= render_launch_markdown($session['content_md']) ?></div>
      </div>

      <div id="edit-panel">
        <div class="lw-header">
          <label class="field-label">Title</label>
          <input type="text" id="e-title" class="field-input" value="<?= h($session['title']) ?>">
          <label class="field-label">Theme Quote</label>
          <input type="text" id="e-quote" class="field-input" value="<?= h($session['theme_quote']) ?>">
          <label class="field-label">The Goal</label>
          <input type="text" id="e-goal" class="field-input" value="<?= h($session['the_goal']) ?>">
          <label class="field-label">Primary Job(s) This Session</label>
          <input type="text" id="e-jobs" class="field-input" value="<?= h($session['primary_jobs']) ?>">
        </div>
        <div class="lw-body">
          <label class="field-label">Content (Markdown)</label>
          <textarea id="content-md-input"><?= h($session['content_md']) ?></textarea>
          <div class="lw-actions">
            <button class="btn-primary" onclick="saveSession()">Save</button>
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
function saveSession() {
  const body = {
    action: 'update_session',
    session_number: <?= (int)$session['session_number'] ?>,
    title: document.getElementById('e-title').value.trim(),
    theme_quote: document.getElementById('e-quote').value.trim(),
    the_goal: document.getElementById('e-goal').value.trim(),
    primary_jobs: document.getElementById('e-jobs').value.trim(),
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
