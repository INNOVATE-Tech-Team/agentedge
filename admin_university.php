<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
if (!is_leader()) { header('Location: index.php'); exit; }
$canEdit = is_admin();
$db = local_db();

$categories = $db->query(
    "SELECT *, (SELECT COUNT(*) FROM uni_courses WHERE category_id=uni_categories.id) as course_count
     FROM uni_categories ORDER BY sort_ord,id"
)->fetchAll(PDO::FETCH_ASSOC);

$courses = $db->query(
    "SELECT c.*, COALESCE(cat.name,'Uncategorized') as cat_name, COALESCE(cat.icon,'📚') as cat_icon,
     (SELECT COUNT(*) FROM uni_lessons WHERE course_id=c.id) as lesson_count,
     (SELECT COUNT(*) FROM uni_certs WHERE course_id=c.id) as cert_count
     FROM uni_courses c LEFT JOIN uni_categories cat ON cat.id=c.category_id
     ORDER BY c.sort_ord,c.id"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>University — AgentEdge Admin</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .admin-section{margin-bottom:28px}
    .section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px}
    .section-title{font-size:16px;font-weight:900;color:#111}
    .btn-primary{padding:8px 16px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer}
    .btn-primary:hover{background:#5b8e0d;color:#fff}
    .btn-sm{padding:5px 12px;font-size:11px;font-weight:700;border-radius:4px;border:1px solid #ddd;background:white;cursor:pointer;color:#333}
    .btn-sm:hover{border-color:#82C112;color:#5b8e0d}
    .btn-danger{background:#fee2e2;color:#c00;border-color:#f5c6c6}
    .btn-danger:hover{background:#fecaca;border-color:#e53935}
    .cat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px}
    .cat-card{border:1px solid #e0e0e0;border-radius:8px;padding:14px 16px;background:white;display:flex;align-items:center;gap:10px}
    .cat-icon{font-size:24px;flex-shrink:0}
    .cat-name{font-size:13px;font-weight:800;color:#111;flex:1}
    .cat-count{font-size:11px;color:#aaa}
    .cat-actions{display:flex;gap:4px;flex-shrink:0}
    .course-table{width:100%;border-collapse:collapse}
    .course-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;padding:8px 12px;text-align:left;border-bottom:2px solid #eee;white-space:nowrap}
    .course-table td{padding:12px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle;font-size:13px}
    .course-table tr:last-child td{border-bottom:none}
    .course-table tr:hover td{background:#fafafa}
    .status-pub{background:#e8f5e9;color:#2e7d32;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;white-space:nowrap}
    .status-draft{background:#f5f5f5;color:#999;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;white-space:nowrap}
    .req-badge{background:#ff6b35;color:white;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;margin-left:4px}
    .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:300;align-items:center;justify-content:center}
    .modal-overlay.open{display:flex}
    .modal{background:white;border-radius:12px;padding:24px;width:440px;max-width:96vw;box-shadow:0 8px 40px rgba(0,0,0,.18)}
    .modal h3{margin:0 0 18px;font-size:15px;font-weight:800}
    .field{margin-bottom:12px}
    .field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px}
    .field input,.field select,.field textarea{width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;box-sizing:border-box}
    .field input:focus,.field select:focus,.field textarea:focus{outline:2px solid #82C112}
    .field textarea{resize:vertical;min-height:70px}
    .modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:16px}
    .btn-cancel{padding:8px 14px;border:1px solid #ccc;background:white;color:#555;border-radius:6px;cursor:pointer;font-size:13px}
    .emoji-row{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}
    .emoji-pick{font-size:18px;cursor:pointer;padding:4px;border-radius:4px;border:1.5px solid transparent}
    .emoji-pick:hover,.emoji-pick.active{border-color:#82C112;background:#f0f9e8}
    .empty-table{text-align:center;color:#bbb;padding:40px;font-size:13px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('admin_university', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">INNOVATE University</div>
    </header>
    <main class="wrap">

      <!-- Stats bar -->
      <div style="display:flex;gap:12px;margin-bottom:24px;flex-wrap:wrap">
        <?php
        $totalCerts   = (int)$db->query("SELECT COUNT(*) FROM uni_certs")->fetchColumn();
        $totalAgents  = (int)$db->query("SELECT COUNT(DISTINCT agent_email) FROM uni_certs")->fetchColumn();
        $pubCourses   = count(array_filter($courses, fn($c) => $c['published']));
        ?>
        <div class="card" style="padding:14px 20px;flex:1;min-width:120px;text-align:center">
          <div style="font-size:24px;font-weight:900;color:#82C112"><?= count($courses) ?></div>
          <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.06em">Courses</div>
          <div style="font-size:10px;color:#aaa;margin-top:2px"><?= $pubCourses ?> published</div>
        </div>
        <div class="card" style="padding:14px 20px;flex:1;min-width:120px;text-align:center">
          <div style="font-size:24px;font-weight:900;color:#82C112"><?= count($categories) ?></div>
          <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.06em">Categories</div>
        </div>
        <div class="card" style="padding:14px 20px;flex:1;min-width:120px;text-align:center">
          <div style="font-size:24px;font-weight:900;color:#82C112"><?= $totalCerts ?></div>
          <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.06em">Certs Issued</div>
          <div style="font-size:10px;color:#aaa;margin-top:2px"><?= $totalAgents ?> agent<?= $totalAgents !== 1 ? 's' : '' ?></div>
        </div>
      </div>

      <!-- Categories -->
      <div class="card" style="padding:20px 24px" class="admin-section">
        <div class="section-header">
          <div class="section-title">📚 Categories</div>
          <?php if ($canEdit): ?><button class="btn-primary" onclick="openCatModal()">+ New Category</button><?php endif; ?>
        </div>
        <?php if (!$categories): ?>
        <div style="color:#bbb;font-size:13px;padding:20px 0">No categories yet — create one to organize your courses.</div>
        <?php else: ?>
        <div class="cat-grid">
          <?php foreach ($categories as $cat): ?>
          <div class="cat-card">
            <div class="cat-icon"><?= htmlspecialchars($cat['icon']) ?></div>
            <div>
              <div class="cat-name"><?= htmlspecialchars($cat['name']) ?></div>
              <div class="cat-count"><?= $cat['course_count'] ?> course<?= $cat['course_count'] != 1 ? 's' : '' ?></div>
            </div>
            <?php if ($canEdit): ?>
            <div class="cat-actions">
              <button class="btn-sm" onclick='editCat(<?= htmlspecialchars(json_encode($cat)) ?>)'>Edit</button>
              <button class="btn-sm btn-danger" onclick="deleteCat(<?= (int)$cat['id'] ?>, '<?= htmlspecialchars(addslashes($cat['name'])) ?>')">Del</button>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Courses -->
      <div class="card" style="padding:20px 24px">
        <div class="section-header">
          <div class="section-title">🎓 Courses</div>
          <?php if ($canEdit): ?><button class="btn-primary" onclick="newCourse()">+ New Course</button><?php endif; ?>
        </div>
        <?php if (!$courses): ?>
        <div class="empty-table">No courses yet. Click <strong>+ New Course</strong> to create the first one.</div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="course-table">
          <thead>
            <tr>
              <th>Course</th>
              <th>Category</th>
              <th>Lessons</th>
              <th>Certs</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($courses as $c): ?>
            <tr>
              <td>
                <div style="font-weight:700;color:#111"><?= htmlspecialchars($c['title']) ?></div>
                <?php if ($c['is_required']): ?><span class="req-badge">Required</span><?php endif; ?>
              </td>
              <td><?= htmlspecialchars($c['cat_icon'] . ' ' . $c['cat_name']) ?></td>
              <td><?= $c['lesson_count'] ?></td>
              <td><?= $c['cert_count'] ?></td>
              <td>
                <span class="<?= $c['published'] ? 'status-pub' : 'status-draft' ?>"><?= $c['published'] ? '● Published' : '○ Draft' ?></span>
              </td>
              <td>
                <?php if ($canEdit): ?>
                <div style="display:flex;gap:4px">
                  <a class="btn-sm" href="admin_university_course.php?id=<?= (int)$c['id'] ?>">Edit</a>
                  <button class="btn-sm" onclick='togglePublish(<?= (int)$c['id'] ?>,<?= $c['published'] ? 0 : 1 ?>,"<?= $c['published'] ? 'Unpublish' : 'Publish' ?>")'><?= $c['published'] ? 'Unpublish' : 'Publish' ?></button>
                  <button class="btn-sm btn-danger" onclick="deleteCourse(<?= (int)$c['id'] ?>,'<?= htmlspecialchars(addslashes($c['title'])) ?>')">Delete</button>
                </div>
                <?php endif; ?>
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

<!-- Category modal -->
<div class="modal-overlay" id="cat-modal">
  <div class="modal">
    <h3 id="cat-modal-title">New Category</h3>
    <input type="hidden" id="cat-id" value="">
    <div class="field"><label>Name</label><input type="text" id="cat-name" placeholder="e.g. Productivity"></div>
    <div class="field">
      <label>Icon</label>
      <input type="text" id="cat-icon" value="📚" maxlength="4" style="width:60px">
      <div class="emoji-row">
        <?php foreach (['📚','🎓','🏆','💡','🏠','📋','🔑','💰','📊','🛠️','🌟','📱','🤝','🎯','⚡'] as $e): ?>
        <span class="emoji-pick" onclick="pickEmoji('<?= $e ?>')"><?= $e ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="field"><label>Sort Order</label><input type="number" id="cat-sort" value="0" min="0"></div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeModal('cat-modal')">Cancel</button>
      <button class="btn-primary" onclick="saveCategory()">Save</button>
    </div>
  </div>
</div>

<script>
function api(body){return fetch('api/uni_action.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}).then(r=>r.json());}
function reload(){location.reload();}
function closeModal(id){document.getElementById(id).classList.remove('open');}

function openCatModal(){
  document.getElementById('cat-modal-title').textContent='New Category';
  document.getElementById('cat-id').value='';
  document.getElementById('cat-name').value='';
  document.getElementById('cat-icon').value='📚';
  document.getElementById('cat-sort').value='0';
  document.getElementById('cat-modal').classList.add('open');
}
function editCat(cat){
  document.getElementById('cat-modal-title').textContent='Edit Category';
  document.getElementById('cat-id').value=cat.id;
  document.getElementById('cat-name').value=cat.name;
  document.getElementById('cat-icon').value=cat.icon;
  document.getElementById('cat-sort').value=cat.sort_ord;
  document.getElementById('cat-modal').classList.add('open');
}
function pickEmoji(e){
  document.getElementById('cat-icon').value=e;
  document.querySelectorAll('.emoji-pick').forEach(el=>el.classList.toggle('active',el.textContent===e));
}
function saveCategory(){
  const id=document.getElementById('cat-id').value;
  const name=document.getElementById('cat-name').value.trim();
  if(!name){alert('Name required');return;}
  const body={action:id?'update_category':'create_category',name,icon:document.getElementById('cat-icon').value,sort_ord:parseInt(document.getElementById('cat-sort').value)||0};
  if(id) body.id=parseInt(id);
  api(body).then(d=>{if(d.ok)reload();else alert(d.error);});
}
function deleteCat(id,name){
  if(!confirm(`Delete category "${name}"? Courses will become uncategorized.`))return;
  api({action:'delete_category',id}).then(()=>reload());
}

function newCourse(){location.href='admin_university_course.php?new=1';}
function togglePublish(id,pub,label){
  if(!confirm(`${label} this course?`))return;
  api({action:'update_course',id,published:pub}).then(d=>{if(d.ok)reload();else alert(d.error);});
}
function deleteCourse(id,title){
  if(!confirm(`Delete course "${title}" and all its lessons, progress, and certificates? This cannot be undone.`))return;
  api({action:'delete_course',id}).then(d=>{if(d.ok)reload();else alert(d.error);});
}
</script>
</body>
</html>
