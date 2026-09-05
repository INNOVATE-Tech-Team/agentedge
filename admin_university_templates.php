<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
if (!can_edit_uni_templates()) { header('Location: index.php'); exit; }
$db = local_db();

$templates = $db->query(
    "SELECT t.*, (SELECT COUNT(*) FROM uni_courses WHERE template_id=t.id) as course_count
     FROM uni_templates t ORDER BY t.archived, t.name"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>On Demand Templates — AgentEdge Admin</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px}
    .section-title{font-size:16px;font-weight:900;color:#111}
    .btn-primary{padding:8px 16px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer}
    .btn-primary:hover{background:#5b8e0d;color:#fff}
    .btn-sm{padding:5px 12px;font-size:11px;font-weight:700;border-radius:4px;border:1px solid #ddd;background:white;cursor:pointer;color:#333;text-decoration:none;display:inline-block}
    .btn-sm:hover{border-color:#82C112;color:#5b8e0d}
    .btn-danger{background:#fee2e2;color:#c00;border-color:#f5c6c6}
    .btn-danger:hover{background:#fecaca;border-color:#e53935}
    .tpl-table{width:100%;border-collapse:collapse}
    .tpl-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;padding:8px 12px;text-align:left;border-bottom:2px solid #eee;white-space:nowrap}
    .tpl-table td{padding:12px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle;font-size:13px}
    .tpl-table tr:last-child td{border-bottom:none}
    .tpl-table tr:hover td{background:#fafafa}
    .archived-badge{background:#f5f5f5;color:#999;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700}
    .empty-table{text-align:center;color:#bbb;padding:40px;font-size:13px}
    .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:300;align-items:center;justify-content:center}
    .modal-overlay.open{display:flex}
    .modal{background:white;border-radius:12px;padding:24px;width:440px;max-width:96vw;box-shadow:0 8px 40px rgba(0,0,0,.18)}
    .modal h3{margin:0 0 18px;font-size:15px;font-weight:800}
    .field{margin-bottom:12px}
    .field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px}
    .field input,.field textarea{width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;box-sizing:border-box}
    .field textarea{resize:vertical;min-height:70px}
    .modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:16px}
    .btn-cancel{padding:8px 14px;border:1px solid #ccc;background:white;color:#555;border-radius:6px;cursor:pointer;font-size:13px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('admin_university_templates', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">University &gt; Agent Development &gt; On Demand</div>
    </header>
    <main class="wrap">
      <p style="color:#666;font-size:13px;max-width:640px">
        Master templates that new On-Demand courses are created from — module scaffolding, quiz defaults,
        certificate rules, sequencing, and layout, defined once and reused. Editing a template never changes
        courses already created from it; use <strong>Apply Template Update</strong> on a course to pull in changes explicitly.
      </p>

      <div class="card" style="padding:20px 24px">
        <div class="section-header">
          <div class="section-title">📦 Templates</div>
          <button class="btn-primary" onclick="openNewModal()">+ New Template</button>
        </div>
        <?php if (!$templates): ?>
        <div class="empty-table">No templates yet. Click <strong>+ New Template</strong>, or build a course normally and use "Save as Template" from its Course Info tab.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="tpl-table">
          <thead><tr><th>Template</th><th>Sequencing</th><th>Quiz pass score</th><th>Cert</th><th>Courses</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($templates as $t): ?>
            <tr>
              <td>
                <div style="font-weight:700;color:#111"><?= htmlspecialchars($t['name']) ?></div>
                <?php if ($t['archived']): ?><span class="archived-badge">Archived</span><?php endif; ?>
              </td>
              <td><?= $t['sequencing_mode'] === 'in_order' ? 'In order' : 'Free navigation' ?></td>
              <td><?= (int)$t['quiz_pass_score'] ?>%</td>
              <td><?= $t['cert_enabled'] ? ($t['cert_expiry_months'] ? 'On, expires ' . (int)$t['cert_expiry_months'] . 'mo' : 'On, never expires') : 'Off' ?></td>
              <td><?= (int)$t['course_count'] ?></td>
              <td>
                <div style="display:flex;gap:4px">
                  <a class="btn-sm" href="admin_university_template.php?id=<?= (int)$t['id'] ?>">Edit</a>
                  <button class="btn-sm <?= $t['archived'] ? '' : 'btn-danger' ?>" onclick="toggleArchive(<?= (int)$t['id'] ?>,<?= $t['archived'] ? 0 : 1 ?>)"><?= $t['archived'] ? 'Restore' : 'Archive' ?></button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</div>

<div class="modal-overlay" id="new-modal">
  <div class="modal">
    <h3>New Template</h3>
    <div class="field"><label>Name</label><input type="text" id="new-name" placeholder="e.g. Contract Training"></div>
    <div class="field"><label>Description</label><textarea id="new-desc" placeholder="What this template is for"></textarea></div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeModal()">Cancel</button>
      <button class="btn-primary" onclick="createTemplate()">Create</button>
    </div>
  </div>
</div>

<script>
function api(body){return fetch('api/uni_template_action.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}).then(r=>r.json());}
function openNewModal(){document.getElementById('new-name').value='';document.getElementById('new-desc').value='';document.getElementById('new-modal').classList.add('open');}
function closeModal(){document.getElementById('new-modal').classList.remove('open');}
function createTemplate(){
  const name=document.getElementById('new-name').value.trim();
  if(!name){alert('Name required');return;}
  api({action:'create_template',name,description:document.getElementById('new-desc').value.trim()}).then(d=>{
    if(d.ok) location.href='admin_university_template.php?id='+d.id;
    else alert(d.error);
  });
}
function toggleArchive(id,archived){
  api({action:'archive_template',id,archived}).then(d=>{if(d.ok)location.reload();else alert(d.error);});
}
</script>
</body>
</html>
