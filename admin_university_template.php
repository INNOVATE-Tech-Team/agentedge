<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/uni_templates.php';
$agent = require_login();
if (!can_edit_uni_templates()) { header('Location: index.php'); exit; }
$db = local_db();

$isNew = isset($_GET['new']);
$id    = (int)($_GET['id'] ?? 0);
if (!$isNew && !$id) { header('Location: admin_university_templates.php'); exit; }

if ($isNew) {
    $tpl = ['id' => 0, 'name' => '', 'description' => '', 'sequencing_mode' => 'free', 'quiz_pass_score' => 70,
            'quiz_retake_policy' => 'unlimited', 'quiz_max_attempts' => 0, 'quiz_block_on_fail' => 0, 'quiz_question_count_hint' => 0,
            'cert_enabled' => 1, 'cert_expiry_months' => 0, 'cert_design' => 'default', 'layout_style' => 'standard',
            'overview_audience' => '', 'overview_outcome' => '', 'overview_estimated_minutes' => 0, 'archived' => 0];
} else {
    $s = $db->prepare("SELECT * FROM uni_templates WHERE id=?"); $s->execute([$id]);
    $tpl = $s->fetch(PDO::FETCH_ASSOC);
    if (!$tpl) { header('Location: admin_university_templates.php'); exit; }
}
$pageTitle = $isNew ? 'New Template' : htmlspecialchars($tpl['name']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $pageTitle ?> — Template — AgentEdge Admin</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .tabs{display:flex;gap:4px;border-bottom:2px solid #eee;margin-bottom:20px}
    .tab{padding:10px 18px;font-size:13px;font-weight:700;color:#888;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px}
    .tab.active{color:#111;border-bottom-color:#82C112}
    .tab-panel{display:none}
    .tab-panel.active{display:block}
    .field{margin-bottom:14px}
    .field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px}
    .field input,.field select,.field textarea{width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;box-sizing:border-box}
    .field textarea{resize:vertical;min-height:60px}
    .field .hint{font-size:11px;color:#999;margin-top:4px}
    .row2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    .row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}
    .btn-primary{padding:8px 16px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer}
    .btn-primary:hover{background:#5b8e0d;color:#fff}
    .btn-sm{padding:5px 12px;font-size:11px;font-weight:700;border-radius:4px;border:1px solid #ddd;background:white;cursor:pointer;color:#333}
    .btn-sm:hover{border-color:#82C112;color:#5b8e0d}
    .btn-danger{background:#fee2e2;color:#c00;border-color:#f5c6c6}
    .folder-card{border:1px solid #e0e0e0;border-radius:8px;margin-bottom:14px;background:white}
    .folder-header{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #f0f0f0}
    .folder-title{font-weight:800;font-size:13px}
    .lesson-row{display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid #f6f6f6}
    .lesson-row:last-child{border-bottom:none}
    .lesson-kind{font-size:10px;font-weight:700;text-transform:uppercase;padding:2px 7px;border-radius:10px;background:#eef6e0;color:#5b8e0d;margin-right:8px}
    .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:300;align-items:center;justify-content:center;overflow-y:auto;padding:30px 0}
    .modal-overlay.open{display:flex}
    .modal{background:white;border-radius:12px;padding:24px;width:560px;max-width:96vw;box-shadow:0 8px 40px rgba(0,0,0,.18)}
    .modal h3{margin:0 0 18px;font-size:15px;font-weight:800}
    .modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:16px}
    .btn-cancel{padding:8px 14px;border:1px solid #ccc;background:white;color:#555;border-radius:6px;cursor:pointer;font-size:13px}
    .q-item{border:1px solid #eee;border-radius:6px;padding:10px 12px;margin-bottom:8px}
    .empty{color:#bbb;font-size:13px;padding:24px;text-align:center}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('admin_university_templates', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">
        <a href="admin_university_templates.php" style="color:#888;text-decoration:none">On Demand</a> / <?= $pageTitle ?>
      </div>
    </header>
    <main class="wrap">

      <div class="tabs">
        <div class="tab active" onclick="showTab('settings')">Overview &amp; Settings</div>
        <div class="tab" onclick="showTab('modules')" id="modules-tab-btn" <?= $isNew ? 'style="opacity:.4;pointer-events:none"' : '' ?>>Modules</div>
      </div>

      <!-- Overview & Settings -->
      <div class="tab-panel active" id="tab-settings">
        <div class="card" style="padding:20px 24px;margin-bottom:16px">
          <div class="field"><label>Name</label><input type="text" id="f-name" value="<?= htmlspecialchars($tpl['name']) ?>"></div>
          <div class="field"><label>Description</label><textarea id="f-description"><?= htmlspecialchars($tpl['description']) ?></textarea></div>
        </div>

        <div class="card" style="padding:20px 24px;margin-bottom:16px">
          <div style="font-weight:800;font-size:13px;margin-bottom:14px">Sequencing &amp; Quiz Defaults</div>
          <div class="row2">
            <div class="field">
              <label>Sequencing</label>
              <select id="f-sequencing_mode">
                <option value="free" <?= $tpl['sequencing_mode']==='free'?'selected':'' ?>>Free navigation</option>
                <option value="in_order" <?= $tpl['sequencing_mode']==='in_order'?'selected':'' ?>>Must complete in order</option>
              </select>
            </div>
            <div class="field"><label>Quiz pass score (%)</label><input type="number" id="f-quiz_pass_score" min="0" max="100" value="<?= (int)$tpl['quiz_pass_score'] ?>"></div>
          </div>
          <div class="row3">
            <div class="field">
              <label>Retake policy</label>
              <select id="f-quiz_retake_policy" onchange="document.getElementById('max-attempts-wrap').style.display=this.value==='limited'?'block':'none'">
                <option value="unlimited" <?= $tpl['quiz_retake_policy']==='unlimited'?'selected':'' ?>>Unlimited</option>
                <option value="limited" <?= $tpl['quiz_retake_policy']==='limited'?'selected':'' ?>>Limited</option>
              </select>
            </div>
            <div class="field" id="max-attempts-wrap" style="display:<?= $tpl['quiz_retake_policy']==='limited'?'block':'none' ?>">
              <label>Max attempts</label><input type="number" id="f-quiz_max_attempts" min="0" value="<?= (int)$tpl['quiz_max_attempts'] ?>">
            </div>
            <div class="field">
              <label>Failed quiz blocks progression?</label>
              <select id="f-quiz_block_on_fail">
                <option value="0" <?= !$tpl['quiz_block_on_fail']?'selected':'' ?>>No</option>
                <option value="1" <?= $tpl['quiz_block_on_fail']?'selected':'' ?>>Yes</option>
              </select>
            </div>
          </div>
          <div class="field">
            <label>Suggested question count (advisory only)</label>
            <input type="number" id="f-quiz_question_count_hint" min="0" value="<?= (int)$tpl['quiz_question_count_hint'] ?>">
            <div class="hint">UI copy only — never enforced, never copied onto a course. Question content is always human-authored.</div>
          </div>
        </div>

        <div class="card" style="padding:20px 24px;margin-bottom:16px">
          <div style="font-weight:800;font-size:13px;margin-bottom:14px">Certificate</div>
          <div class="row3">
            <div class="field">
              <label>Certificate</label>
              <select id="f-cert_enabled">
                <option value="1" <?= $tpl['cert_enabled']?'selected':'' ?>>Enabled</option>
                <option value="0" <?= !$tpl['cert_enabled']?'selected':'' ?>>Disabled</option>
              </select>
            </div>
            <div class="field">
              <label>Expiry (months, 0 = never)</label><input type="number" id="f-cert_expiry_months" min="0" value="<?= (int)$tpl['cert_expiry_months'] ?>">
            </div>
            <div class="field"><label>Certificate design</label><input type="text" id="f-cert_design" value="<?= htmlspecialchars($tpl['cert_design']) ?>"></div>
          </div>
        </div>

        <div class="card" style="padding:20px 24px;margin-bottom:16px">
          <div style="font-weight:800;font-size:13px;margin-bottom:14px">Layout &amp; Course Overview Defaults</div>
          <div class="field"><label>Layout style</label><input type="text" id="f-layout_style" value="<?= htmlspecialchars($tpl['layout_style']) ?>"></div>
          <div class="row2">
            <div class="field"><label>Default audience</label><input type="text" id="f-overview_audience" value="<?= htmlspecialchars($tpl['overview_audience']) ?>"></div>
            <div class="field"><label>Default outcome</label><input type="text" id="f-overview_outcome" value="<?= htmlspecialchars($tpl['overview_outcome']) ?>"></div>
          </div>
          <div class="field" style="max-width:220px"><label>Default estimated minutes</label><input type="number" id="f-overview_estimated_minutes" min="0" value="<?= (int)$tpl['overview_estimated_minutes'] ?>"></div>
        </div>

        <button class="btn-primary" onclick="saveTemplate()"><?= $isNew ? 'Create Template' : 'Save Changes' ?></button>
      </div>

      <!-- Modules -->
      <div class="tab-panel" id="tab-modules">
        <?php if (!$isNew): ?>
        <div class="section-header" style="display:flex;justify-content:space-between;margin-bottom:14px">
          <div style="font-weight:800;font-size:14px">Modules</div>
          <button class="btn-primary" onclick="openFolderModal()">+ New Module</button>
        </div>
        <div id="folders-list"><div class="empty">Loading…</div></div>
        <?php endif; ?>
      </div>

    </main>
  </div>
</div>

<!-- Folder modal -->
<div class="modal-overlay" id="folder-modal">
  <div class="modal">
    <h3 id="folder-modal-title">New Module</h3>
    <input type="hidden" id="folder-id">
    <div class="field"><label>Code</label><input type="text" id="folder-code" placeholder="e.g. ONB-101"></div>
    <div class="field"><label>Title</label><input type="text" id="folder-title"></div>
    <div class="field"><label>Description</label><textarea id="folder-description"></textarea></div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeModal('folder-modal')">Cancel</button>
      <button class="btn-primary" onclick="saveFolder()">Save</button>
    </div>
  </div>
</div>

<!-- Lesson modal -->
<div class="modal-overlay" id="lesson-modal">
  <div class="modal">
    <h3 id="lesson-modal-title">New Lesson</h3>
    <input type="hidden" id="lesson-id"><input type="hidden" id="lesson-folder-id">
    <div class="field"><label>Title</label><input type="text" id="lesson-title"></div>
    <div class="row2">
      <div class="field">
        <label>Default section</label>
        <select id="lesson-section-kind" onchange="onSectionKindChange()">
          <option value="video">Video</option>
          <option value="doc">Attached Documents</option>
          <option value="quiz">Knowledge Check</option>
          <option value="complete">Mark Complete</option>
        </select>
      </div>
      <div class="field"><label>Underlying type</label><input type="text" id="lesson-type" readonly style="background:#f7f7f7"></div>
    </div>
    <div class="field"><label>Learning objective</label><input type="text" id="lesson-objective"></div>
    <div class="field"><label>Embed URL (video)</label><input type="text" id="lesson-embed"></div>
    <div class="field"><label>Content</label><textarea id="lesson-content" style="min-height:120px"></textarea></div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeModal('lesson-modal')">Cancel</button>
      <button class="btn-primary" onclick="saveLesson()">Save</button>
    </div>
  </div>
</div>

<!-- Questions modal -->
<div class="modal-overlay" id="questions-modal">
  <div class="modal" style="width:640px">
    <h3>Knowledge Check Questions</h3>
    <input type="hidden" id="q-lesson-id">
    <div id="questions-list"></div>
    <button class="btn-sm" onclick="openQuestionForm()">+ Add Question</button>
    <div class="modal-actions"><button class="btn-cancel" onclick="closeModal('questions-modal')">Close</button></div>
  </div>
</div>

<!-- Single question form modal -->
<div class="modal-overlay" id="question-form-modal">
  <div class="modal">
    <h3 id="question-form-title">New Question</h3>
    <input type="hidden" id="qf-id">
    <div class="field"><label>Question</label><input type="text" id="qf-question"></div>
    <div class="field"><label>Options (one per line, mark the correct one with a leading *)</label>
      <textarea id="qf-options" style="min-height:110px" placeholder="*Correct option&#10;Wrong option&#10;Wrong option&#10;Wrong option"></textarea>
    </div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeModal('question-form-modal')">Cancel</button>
      <button class="btn-primary" onclick="saveQuestion()">Save</button>
    </div>
  </div>
</div>

<script>
const TEMPLATE_ID = <?= (int)$tpl['id'] ?>;
const IS_NEW = <?= $isNew ? 'true' : 'false' ?>;

function api(body){return fetch('api/uni_template_action.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}).then(r=>r.json());}
function closeModal(id){document.getElementById(id).classList.remove('open');}
function showTab(name){
  document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
  document.querySelector(`.tab[onclick="showTab('${name}')"]`).classList.add('active');
  document.getElementById('tab-'+name).classList.add('active');
  if(name==='modules' && !IS_NEW) loadFolders();
}

function saveTemplate(){
  const name=document.getElementById('f-name').value.trim();
  if(!name){alert('Name required');return;}
  const body={
    action: IS_NEW ? 'create_template' : 'update_template',
    id: TEMPLATE_ID,
    name,
    description: document.getElementById('f-description').value.trim(),
    sequencing_mode: document.getElementById('f-sequencing_mode').value,
    quiz_pass_score: parseInt(document.getElementById('f-quiz_pass_score').value)||70,
    quiz_retake_policy: document.getElementById('f-quiz_retake_policy').value,
    quiz_max_attempts: parseInt(document.getElementById('f-quiz_max_attempts').value)||0,
    quiz_block_on_fail: parseInt(document.getElementById('f-quiz_block_on_fail').value),
    quiz_question_count_hint: parseInt(document.getElementById('f-quiz_question_count_hint').value)||0,
    cert_enabled: parseInt(document.getElementById('f-cert_enabled').value),
    cert_expiry_months: parseInt(document.getElementById('f-cert_expiry_months').value)||0,
    cert_design: document.getElementById('f-cert_design').value.trim(),
    layout_style: document.getElementById('f-layout_style').value.trim(),
    overview_audience: document.getElementById('f-overview_audience').value.trim(),
    overview_outcome: document.getElementById('f-overview_outcome').value.trim(),
    overview_estimated_minutes: parseInt(document.getElementById('f-overview_estimated_minutes').value)||0,
  };
  api(body).then(d=>{
    if(!d.ok){alert(d.error);return;}
    if(IS_NEW) location.href='admin_university_template.php?id='+d.id;
    else location.reload();
  });
}

// ── Modules (folders/lessons/questions) ─────────────────────────────────
let FOLDERS=[], LESSONS=[];
function loadFolders(){
  Promise.all([
    api({action:'list_template_folders',template_id:TEMPLATE_ID}),
    api({action:'list_template_lessons',template_id:TEMPLATE_ID}),
  ]).then(([fr,lr])=>{
    FOLDERS=fr.folders||[]; LESSONS=lr.lessons||[];
    renderFolders();
  });
}
function renderFolders(){
  const el=document.getElementById('folders-list');
  if(!FOLDERS.length){el.innerHTML='<div class="empty">No modules yet. Click + New Module to add one.</div>';return;}
  el.innerHTML=FOLDERS.map(f=>{
    const lessons=LESSONS.filter(l=>l.folder_id==f.id);
    const lessonRows=lessons.map(l=>`
      <div class="lesson-row">
        <div><span class="lesson-kind">${l.section_kind||l.type}</span>${escapeHtml(l.title)}${l.type==='quiz'?` <span style="color:#999;font-size:11px">(${l.question_count} question${l.question_count==1?'':'s'})</span>`:''}</div>
        <div style="display:flex;gap:4px">
          ${l.type==='quiz'?`<button class="btn-sm" onclick="openQuestions(${l.id})">Questions</button>`:''}
          <button class="btn-sm" onclick='openLessonModal(${f.id},${JSON.stringify(l)})'>Edit</button>
          <button class="btn-sm btn-danger" onclick="deleteLesson(${l.id})">Del</button>
        </div>
      </div>`).join('') || '<div class="empty" style="padding:14px">No lessons in this module yet.</div>';
    return `
      <div class="folder-card">
        <div class="folder-header">
          <div class="folder-title">${escapeHtml(f.code?f.code+': ':'')}${escapeHtml(f.title)}</div>
          <div style="display:flex;gap:4px">
            <button class="btn-sm" onclick="openLessonModal(${f.id},null)">+ Lesson</button>
            <button class="btn-sm" onclick='openFolderModal(${JSON.stringify(f)})'>Edit</button>
            <button class="btn-sm btn-danger" onclick="deleteFolder(${f.id})">Delete</button>
          </div>
        </div>
        ${lessonRows}
      </div>`;
  }).join('');
}
function escapeHtml(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}

function openFolderModal(f){
  document.getElementById('folder-modal-title').textContent = f ? 'Edit Module' : 'New Module';
  document.getElementById('folder-id').value = f ? f.id : '';
  document.getElementById('folder-code').value = f ? f.code : '';
  document.getElementById('folder-title').value = f ? f.title : '';
  document.getElementById('folder-description').value = f ? f.description : '';
  document.getElementById('folder-modal').classList.add('open');
}
function saveFolder(){
  const id=document.getElementById('folder-id').value;
  const title=document.getElementById('folder-title').value.trim();
  if(!title){alert('Title required');return;}
  const body={action:id?'update_template_folder':'create_template_folder',template_id:TEMPLATE_ID,title,
    code:document.getElementById('folder-code').value.trim(),description:document.getElementById('folder-description').value.trim()};
  if(id) body.id=parseInt(id);
  api(body).then(d=>{if(d.ok){closeModal('folder-modal');loadFolders();}else alert(d.error);});
}
function deleteFolder(id){
  if(!confirm('Delete this module? Its lessons will be unassigned, not deleted.'))return;
  api({action:'delete_template_folder',id}).then(()=>loadFolders());
}

function openLessonModal(folderId, l){
  document.getElementById('lesson-modal-title').textContent = l ? 'Edit Lesson' : 'New Lesson';
  document.getElementById('lesson-id').value = l ? l.id : '';
  document.getElementById('lesson-folder-id').value = folderId;
  document.getElementById('lesson-title').value = l ? l.title : '';
  document.getElementById('lesson-section-kind').value = l ? (l.section_kind||'video') : 'video';
  document.getElementById('lesson-objective').value = l ? l.learning_objective : '';
  document.getElementById('lesson-embed').value = l ? l.embed_url : '';
  document.getElementById('lesson-content').value = l ? l.content_html : '';
  onSectionKindChange();
  document.getElementById('lesson-modal').classList.add('open');
}
function onSectionKindChange(){
  const k=document.getElementById('lesson-section-kind').value;
  const typeMap={video:'video',doc:'doc',quiz:'quiz',complete:'doc'};
  document.getElementById('lesson-type').value=typeMap[k];
}
function saveLesson(){
  const id=document.getElementById('lesson-id').value;
  const title=document.getElementById('lesson-title').value.trim();
  if(!title){alert('Title required');return;}
  const body={
    action:id?'update_template_lesson':'create_template_lesson', template_id:TEMPLATE_ID,
    folder_id:parseInt(document.getElementById('lesson-folder-id').value),
    title, type:document.getElementById('lesson-type').value, section_kind:document.getElementById('lesson-section-kind').value,
    learning_objective:document.getElementById('lesson-objective').value.trim(),
    embed_url:document.getElementById('lesson-embed').value.trim(),
    content_html:document.getElementById('lesson-content').value,
  };
  if(id) body.id=parseInt(id);
  api(body).then(d=>{if(d.ok){closeModal('lesson-modal');loadFolders();}else alert(d.error);});
}
function deleteLesson(id){
  if(!confirm('Delete this lesson?'))return;
  api({action:'delete_template_lesson',id}).then(()=>loadFolders());
}

// ── Questions ────────────────────────────────────────────────────────────
let CURRENT_QUESTIONS=[];
function openQuestions(lessonId){
  document.getElementById('q-lesson-id').value=lessonId;
  api({action:'list_template_questions',template_lesson_id:lessonId}).then(d=>{
    CURRENT_QUESTIONS=d.questions||[];
    renderQuestions();
    document.getElementById('questions-modal').classList.add('open');
  });
}
function renderQuestions(){
  const el=document.getElementById('questions-list');
  if(!CURRENT_QUESTIONS.length){el.innerHTML='<div class="empty">No questions yet.</div>';return;}
  el.innerHTML=CURRENT_QUESTIONS.map(q=>{
    const opts=JSON.parse(q.options||'[]');
    return `<div class="q-item">
      <div style="font-weight:700;margin-bottom:4px">${escapeHtml(q.question)}</div>
      <div style="font-size:12px;color:#666">${opts.map((o,i)=>(i===q.correct_index?'✅ ':'— ')+escapeHtml(o)).join('<br>')}</div>
      <div style="margin-top:6px;display:flex;gap:4px">
        <button class="btn-sm" onclick='openQuestionForm(${JSON.stringify(q)})'>Edit</button>
        <button class="btn-sm btn-danger" onclick="deleteQuestion(${q.id})">Del</button>
      </div>
    </div>`;
  }).join('');
}
function openQuestionForm(q){
  document.getElementById('question-form-title').textContent = q ? 'Edit Question' : 'New Question';
  document.getElementById('qf-id').value = q ? q.id : '';
  document.getElementById('qf-question').value = q ? q.question : '';
  if(q){
    const opts=JSON.parse(q.options||'[]');
    document.getElementById('qf-options').value = opts.map((o,i)=> (i===q.correct_index?'*':'')+o).join('\n');
  } else {
    document.getElementById('qf-options').value = '';
  }
  document.getElementById('question-form-modal').classList.add('open');
}
function saveQuestion(){
  const id=document.getElementById('qf-id').value;
  const question=document.getElementById('qf-question').value.trim();
  const lines=document.getElementById('qf-options').value.split('\n').map(l=>l.trim()).filter(Boolean);
  if(!question||lines.length<2){alert('Question and at least 2 options required');return;}
  let correctIndex=0; const options=lines.map((l,i)=>{ if(l.startsWith('*')){correctIndex=i; return l.slice(1).trim();} return l; });
  const body={action:id?'update_template_question':'create_template_question',template_lesson_id:parseInt(document.getElementById('q-lesson-id').value),
    question, options, qtype:'single', correct_index:correctIndex, correct_indexes:[correctIndex]};
  if(id) body.id=parseInt(id);
  api(body).then(d=>{if(d.ok){closeModal('question-form-modal');openQuestions(parseInt(document.getElementById('q-lesson-id').value));}else alert(d.error);});
}
function deleteQuestion(id){
  if(!confirm('Delete this question?'))return;
  api({action:'delete_template_question',id}).then(()=>openQuestions(parseInt(document.getElementById('q-lesson-id').value)));
}
</script>
</body>
</html>
