<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
if (!is_admin()) { header('Location: index.php'); exit; }
$db = local_db();

$isNew    = !empty($_GET['new']);
$courseId = (int)($_GET['id'] ?? 0);

if (!$isNew && !$courseId) { header('Location: admin_university.php'); exit; }

$course   = null;
$lessons  = [];

if ($courseId) {
    $cs = $db->prepare("SELECT * FROM uni_courses WHERE id=?"); $cs->execute([$courseId]);
    $course = $cs->fetch(PDO::FETCH_ASSOC);
    if (!$course) { header('Location: admin_university.php'); exit; }
    $ls = $db->prepare("SELECT *, (SELECT COUNT(*) FROM uni_questions WHERE lesson_id=uni_lessons.id) as question_count FROM uni_lessons WHERE course_id=? ORDER BY sort_ord,id");
    $ls->execute([$courseId]);
    $lessons = $ls->fetchAll(PDO::FETCH_ASSOC);
}

$categories = $db->query("SELECT * FROM uni_categories ORDER BY sort_ord,id")->fetchAll(PDO::FETCH_ASSOC);
$pageTitle  = $isNew ? 'New Course' : htmlspecialchars($course['title'] ?? '');

$allStates  = ['FL','GA','MD','MA','NC','NJ','NH','OH','PA','RI','SC','TN','VA','DE'];
$stateNames = ['FL'=>'Florida','GA'=>'Georgia','MD'=>'Maryland','MA'=>'Massachusetts','NC'=>'North Carolina','NJ'=>'New Jersey','NH'=>'New Hampshire','OH'=>'Ohio','PA'=>'Pennsylvania','RI'=>'Rhode Island','SC'=>'South Carolina','TN'=>'Tennessee','VA'=>'Virginia','DE'=>'Delaware'];
$allRoles   = ['agent'=>'Agent','recruiter'=>'Recruiter','bic'=>'Broker in Charge','mc_leader'=>'Market Center Leader','staff'=>'Staff','super_admin'=>'Super Admin'];

$courseInviteOnly  = (int)($course['invite_only']  ?? 0);
$courseStateFilter = json_decode($course['state_filter'] ?? '[]', true) ?: [];
$courseRoleFilter  = json_decode($course['role_filter']  ?? '[]', true) ?: [];

$currentInvites = [];
if ($courseId) {
    $invStmt = $db->prepare("SELECT agent_email, invited_by, invited_at FROM uni_course_invites WHERE course_id=? ORDER BY invited_at DESC");
    $invStmt->execute([$courseId]);
    $currentInvites = $invStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $pageTitle ?> — University Admin</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <link rel="stylesheet" href="https://cdn.quilljs.com/1.3.7/quill.snow.css">
  <style>
    .ql-container { font-family: inherit; font-size: 13px; border-radius: 0 0 6px 6px; }
    .ql-toolbar { border-radius: 6px 6px 0 0; border-color: #ccc !important; background: #fafafa; }
    .ql-container { border-color: #ccc !important; min-height: 120px; }
    .ql-editor { min-height: 120px; }
    .ql-container:focus-within { outline: 2px solid #82C112; border-color: transparent !important; }
    .ql-container:focus-within + .ql-toolbar { border-color: transparent !important; }
  </style>
  <style>
    .back-link{font-size:12px;color:#5b8e0d;text-decoration:none;font-weight:700;display:inline-flex;align-items:center;gap:4px;margin-bottom:16px}
    .back-link:hover{text-decoration:underline}
    .tabs{display:flex;gap:0;border-bottom:2px solid #e0e0e0;margin-bottom:24px}
    .tab{padding:10px 20px;font-size:13px;font-weight:700;color:#888;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;background:none;border-top:none;border-left:none;border-right:none}
    .tab.active{color:#82C112;border-bottom-color:#82C112}
    .tab-panel{display:none}.tab-panel.active{display:block}
    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    .form-grid .full{grid-column:1/-1}
    .field{margin-bottom:14px}
    .field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px}
    .field input,.field select,.field textarea{width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:6px;font-size:13px;box-sizing:border-box}
    .field input:focus,.field select:focus,.field textarea:focus{outline:2px solid #82C112}
    .field textarea{resize:vertical;min-height:80px}
    .field-row{display:flex;align-items:center;gap:10px}
    .toggle-label{font-size:13px;font-weight:600;color:#333;cursor:pointer;display:flex;align-items:center;gap:8px}
    .toggle-label input[type=checkbox]{width:16px;height:16px;accent-color:#82C112;cursor:pointer}
    .btn-primary{padding:9px 20px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer}
    .btn-primary:hover{background:#5b8e0d;color:#fff}
    .btn-secondary{padding:9px 16px;background:white;color:#333;border:1.5px solid #ddd;border-radius:6px;font-weight:700;font-size:13px;cursor:pointer}
    .btn-secondary:hover{border-color:#82C112;color:#5b8e0d}
    .btn-sm{padding:5px 12px;font-size:11px;font-weight:700;border-radius:4px;border:1px solid #ddd;background:white;cursor:pointer;color:#333}
    .btn-sm:hover{border-color:#82C112;color:#5b8e0d}
    .btn-danger{background:#fee2e2;color:#c00;border-color:#f5c6c6}
    .btn-danger:hover{background:#fecaca}
    .thumb-preview{width:160px;height:100px;border-radius:8px;border:2px dashed #ccc;object-fit:cover;background:#f5f5f5;display:flex;align-items:center;justify-content:center;font-size:32px;overflow:hidden}
    .thumb-preview img{width:100%;height:100%;object-fit:cover}
    .lesson-list{display:flex;flex-direction:column;gap:8px;margin-bottom:16px}
    .lesson-row{display:flex;align-items:center;gap:10px;padding:12px 16px;background:white;border:1px solid #e0e0e0;border-radius:8px;cursor:default}
    .lesson-row:hover{border-color:#c3dfa8}
    .drag-handle{color:#ccc;cursor:grab;font-size:16px;padding:2px 4px;user-select:none}
    .drag-handle:active{cursor:grabbing}
    .lesson-num{font-size:11px;color:#bbb;font-weight:700;width:20px;text-align:right;flex-shrink:0}
    .lesson-type-badge{font-size:16px;flex-shrink:0}
    .lesson-title-text{flex:1;font-size:13px;font-weight:700;color:#111}
    .lesson-meta{font-size:11px;color:#aaa}
    .lesson-actions{display:flex;gap:4px;flex-shrink:0}
    .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:300;align-items:center;justify-content:center}
    .modal-overlay.open{display:flex}
    .modal{background:white;border-radius:12px;padding:24px;width:560px;max-width:96vw;max-height:92vh;overflow-y:auto;box-shadow:0 8px 40px rgba(0,0,0,.18)}
    .modal h3{margin:0 0 18px;font-size:15px;font-weight:800}
    .modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:20px;border-top:1px solid #eee;padding-top:16px}
    .btn-cancel{padding:8px 14px;border:1px solid #ccc;background:white;color:#555;border-radius:6px;cursor:pointer;font-size:13px}
    .upload-area{border:2px dashed #ccc;border-radius:8px;padding:20px;text-align:center;cursor:pointer;transition:border-color 100ms;margin-bottom:12px}
    .upload-area:hover,.upload-area.drag{border-color:#82C112;background:#f9fdf5}
    .upload-area p{margin:4px 0;font-size:13px;color:#888}
    .upload-status{font-size:12px;color:#888;margin-top:6px;min-height:18px}
    .file-current{font-size:11px;color:#5b8e0d;background:#e8f5e9;padding:3px 10px;border-radius:4px;display:inline-block;margin-bottom:8px}
    /* Quiz questions list */
    .q-list{display:flex;flex-direction:column;gap:8px;margin-bottom:12px}
    .q-row{background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:10px 14px;display:flex;gap:10px;align-items:flex-start}
    .q-num{font-size:11px;color:#bbb;font-weight:700;width:18px;flex-shrink:0;padding-top:2px}
    .q-text{flex:1;font-size:12px;color:#333;line-height:1.4}
    .q-actions{flex-shrink:0;display:flex;gap:4px}
    /* sub-modal for questions */
    .sub-modal{background:white;border-radius:10px;padding:20px;width:500px;max-width:96vw;max-height:80vh;overflow-y:auto;box-shadow:0 8px 40px rgba(0,0,0,.25)}
    .option-row{display:flex;gap:8px;align-items:center;margin-bottom:8px}
    .option-row input[type=text]{flex:1;padding:7px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px}
    .option-row input[type=radio]{accent-color:#82C112;width:16px;height:16px;flex-shrink:0}
    .correct-label{font-size:11px;color:#82C112;font-weight:700;white-space:nowrap}
    .save-toast{position:fixed;bottom:24px;right:24px;background:#1a1a1a;color:white;padding:10px 20px;border-radius:8px;font-size:13px;font-weight:700;opacity:0;transition:opacity 200ms;z-index:999}
    .save-toast.show{opacity:1}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('admin_university', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title"><?= $isNew ? 'New Course' : 'Edit Course' ?></div>
    </header>
    <main class="wrap">

      <a class="back-link" href="admin_university.php">← Back to University</a>

      <!-- Tabs -->
      <?php if (!$isNew): ?>
      <div class="tabs">
        <button class="tab active" onclick="switchTab('info',this)">Course Info</button>
        <button class="tab" onclick="switchTab('lessons',this)">Lessons (<?= count($lessons) ?>)</button>
        <button class="tab" onclick="switchTab('access',this)">Access <?= ($courseInviteOnly || !empty($courseStateFilter) || !empty($courseRoleFilter)) ? '🔒' : '' ?></button>
      </div>
      <?php endif; ?>

      <!-- Tab: Course Info -->
      <div class="tab-panel active" id="tab-info">
        <div class="card" style="padding:24px">
          <div class="form-grid">
            <div class="full field">
              <label>Course Title</label>
              <input type="text" id="c-title" value="<?= htmlspecialchars($course['title'] ?? '') ?>" placeholder="e.g. Getting Started with AgentEdge">
            </div>
            <div class="full field">
              <label>Description</label>
              <textarea id="c-desc" placeholder="Brief overview of what agents will learn…"><?= htmlspecialchars($course['description'] ?? '') ?></textarea>
            </div>
            <div class="field">
              <label>Category</label>
              <select id="c-cat">
                <option value="">— Uncategorized —</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= (int)$cat['id'] ?>" <?= isset($course['category_id']) && $course['category_id'] == $cat['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($cat['icon'] . ' ' . $cat['name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>Sort Order</label>
              <input type="number" id="c-sort" value="<?= (int)($course['sort_ord'] ?? 0) ?>" min="0">
            </div>
            <div class="field full">
              <div class="field-row">
                <label class="toggle-label">
                  <input type="checkbox" id="c-required" <?= !empty($course['is_required']) ? 'checked' : '' ?>>
                  Mark as Required
                </label>
                <?php if (!$isNew): ?>
                <label class="toggle-label" style="margin-left:24px">
                  <input type="checkbox" id="c-published" <?= !empty($course['published']) ? 'checked' : '' ?>>
                  Published
                </label>
                <?php endif; ?>
              </div>
            </div>
            <?php if (!$isNew && $courseId): ?>
            <div class="field full">
              <label>Thumbnail</label>
              <?php if ($course['thumb_key']): ?>
              <div style="margin-bottom:8px">
                <img src="api/uni_download.php?thumb=1&course_id=<?= $courseId ?>" style="width:160px;height:100px;border-radius:8px;object-fit:cover;border:1px solid #eee">
              </div>
              <?php else: ?>
              <div class="thumb-preview">🖼</div>
              <?php endif; ?>
              <div style="margin-top:8px">
                <div class="upload-area" onclick="document.getElementById('thumb-input').click()" ondragover="event.preventDefault();this.classList.add('drag')" ondragleave="this.classList.remove('drag')" ondrop="handleThumbDrop(event)">
                  <p><strong>Click to upload</strong> or drag image here</p>
                  <p style="font-size:11px">JPEG, PNG, WebP — max 10 MB</p>
                  <p id="thumb-filename" style="color:#82C112;font-weight:700;font-size:12px"></p>
                </div>
                <input type="file" id="thumb-input" accept="image/*" style="display:none" onchange="uploadThumb(this.files[0])">
                <div class="upload-status" id="thumb-status"></div>
              </div>
            </div>
            <?php endif; ?>
          </div>
          <div style="display:flex;gap:10px;margin-top:8px">
            <button class="btn-primary" onclick="saveCourseInfo()"><?= $isNew ? 'Create Course' : 'Save Changes' ?></button>
            <?php if (!$isNew): ?>
            <a class="btn-secondary" href="university_course.php?id=<?= $courseId ?>" target="_blank" style="text-decoration:none;display:inline-flex;align-items:center">Preview ↗</a>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Tab: Lessons -->
      <?php if (!$isNew): ?>
      <div class="tab-panel" id="tab-lessons">
        <div class="card" style="padding:20px 24px">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px">
            <div style="font-size:14px;font-weight:800;color:#111">Lessons</div>
            <button class="btn-primary" onclick="openAddLesson()">+ Add Lesson</button>
          </div>
          <?php if (!$lessons): ?>
          <div style="text-align:center;color:#bbb;padding:40px;font-size:13px;border:1px dashed #eee;border-radius:8px">
            No lessons yet — click <strong>+ Add Lesson</strong> to begin building this course.
          </div>
          <?php else: ?>
          <div class="lesson-list" id="lesson-list">
            <?php foreach ($lessons as $i => $lesson):
              $typeIcons = ['video'=>'🎥','doc'=>'📄','quiz'=>'📝'];
              $typeLabel = ['video'=>'Video','doc'=>'Document','quiz'=>'Quiz'];
              $dur = $lesson['duration_sec'] > 0 ? gmdate($lesson['duration_sec'] >= 3600 ? 'G\h i\m' : 'i\m', $lesson['duration_sec']) : '';
            ?>
            <div class="lesson-row" data-id="<?= (int)$lesson['id'] ?>">
              <span class="drag-handle" title="Drag to reorder">⠿</span>
              <span class="lesson-num"><?= $i + 1 ?></span>
              <span class="lesson-type-badge"><?= $typeIcons[$lesson['type']] ?? '📄' ?></span>
              <div style="flex:1">
                <div class="lesson-title-text"><?= htmlspecialchars($lesson['title']) ?></div>
                <div class="lesson-meta">
                  <?= htmlspecialchars($typeLabel[$lesson['type']] ?? '') ?>
                  <?php if ($lesson['file_key']): ?> · File uploaded<?php endif; ?>
                  <?php if ($lesson['type'] === 'quiz'): ?> · <?= $lesson['question_count'] ?> question<?= $lesson['question_count'] != 1 ? 's' : '' ?><?php endif; ?>
                  <?php if ($dur): ?> · <?= $dur ?><?php endif; ?>
                </div>
              </div>
              <div class="lesson-actions">
                <button class="btn-sm" onclick='editLesson(<?= htmlspecialchars(json_encode($lesson)) ?>)'>Edit</button>
                <?php if ($lesson['type'] === 'quiz'): ?>
                <button class="btn-sm" onclick="manageQuestions(<?= (int)$lesson['id'] ?>, '<?= htmlspecialchars(addslashes($lesson['title'])) ?>')">Questions</button>
                <?php endif; ?>
                <button class="btn-sm btn-danger" onclick="deleteLesson(<?= (int)$lesson['id'] ?>, '<?= htmlspecialchars(addslashes($lesson['title'])) ?>')">Del</button>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <div style="font-size:11px;color:#bbb;text-align:center">Drag lessons to reorder them.</div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Tab: Access -->
      <?php if (!$isNew && $courseId): ?>
      <div class="tab-panel" id="tab-access">
        <div class="card" style="padding:24px;display:flex;flex-direction:column;gap:24px">

          <!-- Invite Only -->
          <div>
            <div style="font-size:13px;font-weight:800;color:#111;margin-bottom:8px">Invite Only</div>
            <label class="toggle-label">
              <input type="checkbox" id="a-invite-only" <?= $courseInviteOnly ? 'checked' : '' ?> onchange="toggleInvitePanel()">
              Hide this course from everyone — only invited agents can see it
            </label>
            <div id="invite-panel" style="margin-top:16px;<?= $courseInviteOnly ? '' : 'display:none' ?>">
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:8px">Add agents by name or email</div>
              <div style="display:flex;gap:8px;margin-bottom:12px">
                <input type="text" id="invite-search" placeholder="Search agents…" style="flex:1;padding:9px 12px;border:1px solid #ccc;border-radius:6px;font-size:13px" oninput="searchAgents(this.value)">
              </div>
              <div id="invite-results" style="background:white;border:1px solid #e0e0e0;border-radius:6px;max-height:180px;overflow-y:auto;display:none"></div>
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin:14px 0 8px">Currently invited</div>
              <div id="invite-list">
                <?php if (!$currentInvites): ?>
                <div style="color:#bbb;font-size:13px;padding:8px 0">No invites yet — search for agents above to add them.</div>
                <?php else: ?>
                <?php foreach ($currentInvites as $inv): ?>
                <div class="invite-chip" data-email="<?= htmlspecialchars($inv['agent_email']) ?>" style="display:flex;align-items:center;gap:8px;padding:7px 10px;background:#f5f5f5;border-radius:6px;margin-bottom:6px;font-size:13px">
                  <span style="flex:1"><?= htmlspecialchars($inv['agent_email']) ?></span>
                  <span style="font-size:10px;color:#aaa"><?= substr($inv['invited_at'],0,10) ?></span>
                  <button class="btn-sm btn-danger" onclick="removeInvite('<?= htmlspecialchars($inv['agent_email']) ?>', this.closest('.invite-chip'))" style="padding:3px 8px">✕</button>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- State Filter -->
          <div>
            <div style="font-size:13px;font-weight:800;color:#111;margin-bottom:4px">State Filter</div>
            <div style="font-size:12px;color:#888;margin-bottom:12px">Only agents whose office is in a selected state can see this course. Leave all unchecked = visible in all states.</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px">
              <?php foreach ($allStates as $sc): ?>
              <label class="toggle-label" style="font-size:13px;background:#f9f9f9;border:1px solid #e5e5e5;border-radius:6px;padding:7px 10px">
                <input type="checkbox" class="a-state" value="<?= $sc ?>" <?= in_array($sc, $courseStateFilter) ? 'checked' : '' ?>>
                <?= $sc ?> — <?= $stateNames[$sc] ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Role Filter -->
          <div>
            <div style="font-size:13px;font-weight:800;color:#111;margin-bottom:4px">Role Filter</div>
            <div style="font-size:12px;color:#888;margin-bottom:12px">Only agents with a selected role can see this course. Leave all unchecked = visible to all roles.</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px">
              <?php foreach ($allRoles as $roleKey => $roleLabel): ?>
              <label class="toggle-label" style="font-size:13px;background:#f9f9f9;border:1px solid #e5e5e5;border-radius:6px;padding:7px 10px">
                <input type="checkbox" class="a-role" value="<?= $roleKey ?>" <?= in_array($roleKey, $courseRoleFilter) ? 'checked' : '' ?>>
                <?= htmlspecialchars($roleLabel) ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div>
            <button class="btn-primary" onclick="saveAccess()">Save Access Settings</button>
          </div>
        </div>
      </div>
      <?php endif; ?>

    </main>
  </div>
</div>

<!-- Lesson modal -->
<div class="modal-overlay" id="lesson-modal">
  <div class="modal">
    <h3 id="lesson-modal-title">Add Lesson</h3>
    <input type="hidden" id="l-id" value="">
    <div class="field"><label>Title</label><input type="text" id="l-title" placeholder="e.g. Welcome to INNOVATE"></div>
    <div class="field">
      <label>Type</label>
      <select id="l-type" onchange="onTypeChange()">
        <option value="video">🎥 Video</option>
        <option value="doc">📄 Document</option>
        <option value="quiz">📝 Quiz</option>
      </select>
    </div>
    <div id="l-file-section">
      <div class="field" id="l-embed-field" style="display:none">
        <label>Embed URL (YouTube or Vimeo)</label>
        <input type="url" id="l-embed-url" placeholder="https://www.youtube.com/watch?v=… or https://vimeo.com/…">
        <div style="font-size:11px;color:#aaa;margin-top:4px">Paste the regular watch URL — it will be converted to embed format automatically.</div>
      </div>
      <div style="font-size:11px;color:#aaa;text-align:center;margin-bottom:8px;display:none" id="l-or-divider">— or upload a file —</div>
      <div class="field" id="l-file-field">
        <label id="l-file-label">Video File</label>
        <div class="file-current" id="l-file-current" style="display:none">File uploaded ✓</div>
        <div class="upload-area" id="l-upload-area" onclick="document.getElementById('l-file-input').click()" ondragover="event.preventDefault();this.classList.add('drag')" ondragleave="this.classList.remove('drag')" ondrop="handleLessonFileDrop(event)">
          <p><strong>Click to upload</strong> or drag file here</p>
          <p id="l-file-name" style="color:#82C112;font-weight:700;font-size:12px"></p>
        </div>
        <input type="file" id="l-file-input" style="display:none" onchange="pendingLessonFile=this.files[0];document.getElementById('l-file-name').textContent=this.files[0].name">
        <div class="upload-status" id="l-upload-status"></div>
      </div>
    </div>
    <div class="field">
      <label>Notes / Description</label>
      <div id="l-content-editor" style="background:white"></div>
      <input type="hidden" id="l-content">
    </div>
    <div class="field" id="l-duration-field">
      <label>Duration (seconds, optional)</label>
      <input type="number" id="l-duration" value="0" min="0">
    </div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeModal('lesson-modal')">Cancel</button>
      <button class="btn-primary" id="l-save-btn" onclick="saveLesson()">Save Lesson</button>
    </div>
  </div>
</div>

<!-- Questions modal -->
<div class="modal-overlay" id="q-modal">
  <div class="modal" style="width:620px">
    <h3 id="q-modal-title">Quiz Questions</h3>
    <div id="q-list" class="q-list"></div>
    <button class="btn-secondary" onclick="openAddQuestion()" style="margin-bottom:8px">+ Add Question</button>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeModal('q-modal')">Close</button>
    </div>
  </div>
</div>

<!-- Question editor (nested within q-modal flow) -->
<div class="modal-overlay" id="qe-modal" style="z-index:400">
  <div class="sub-modal">
    <h3 id="qe-title">Add Question</h3>
    <input type="hidden" id="qe-id" value="">
    <div class="field"><label>Question</label><textarea id="qe-question" rows="2" placeholder="e.g. What is the first step when meeting a new client?"></textarea></div>
    <div class="field">
      <label>Options (select the correct answer)</label>
      <div id="qe-options">
        <?php for ($i=0;$i<4;$i++): ?>
        <div class="option-row">
          <input type="radio" name="qe-correct" value="<?= $i ?>" id="qe-opt-radio-<?= $i ?>">
          <input type="text" id="qe-opt-<?= $i ?>" placeholder="Option <?= $i+1 ?>">
          <label for="qe-opt-radio-<?= $i ?>" class="correct-label">✓ Correct</label>
        </div>
        <?php endfor; ?>
      </div>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px">
      <button class="btn-cancel" onclick="closeModal('qe-modal')">Cancel</button>
      <button class="btn-primary" onclick="saveQuestion()">Save Question</button>
    </div>
  </div>
</div>

<div class="save-toast" id="save-toast">Saved ✓</div>

<!-- Background upload progress bar -->
<div id="bg-upload" style="display:none;position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#1a1a1a;color:#fff;padding:12px 20px;border-radius:8px;font-size:13px;z-index:9999;min-width:320px;text-align:center;box-shadow:0 4px 16px rgba(0,0,0,.3)">
  <div id="bg-upload-text">Uploading…</div>
  <progress id="bg-upload-bar" max="100" value="0" style="width:100%;margin-top:8px;height:5px;accent-color:#82C112"></progress>
</div>

<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<script>
const COURSE_ID = <?= $courseId ?: 'null' ?>;
let pendingLessonFile = null;
let activeLessonId   = null;

// Quill rich text editor for lesson notes
let quill = null;
document.addEventListener('DOMContentLoaded', () => {
  quill = new Quill('#l-content-editor', {
    theme: 'snow',
    placeholder: 'Optional notes, key takeaways, or links shown below the lesson…',
    modules: {
      toolbar: [
        ['bold', 'italic', 'underline'],
        [{ 'list': 'ordered' }, { 'list': 'bullet' }],
        [{ 'header': [2, 3, false] }],
        ['link'],
        ['clean'],
      ]
    }
  });
});

function api(body){return fetch('api/uni_action.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}).then(r=>r.json());}
function closeModal(id){document.getElementById(id).classList.remove('open');}
function toast(msg){const t=document.getElementById('save-toast');t.textContent=msg||'Saved ✓';t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2000);}

function switchTab(name, el) {
  document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
  el.classList.add('active');
  document.getElementById('tab-'+name).classList.add('active');
}

// ── Course Info ───────────────────────────────────────────────────────────
function saveCourseInfo() {
  const title = document.getElementById('c-title').value.trim();
  if (!title) { alert('Title required'); return; }
  const catEl  = document.getElementById('c-cat');
  const catId  = catEl ? (parseInt(catEl.value)||null) : null;
  const pubEl  = document.getElementById('c-published');
  const body   = {
    action: COURSE_ID ? 'update_course' : 'create_course',
    title, description: document.getElementById('c-desc').value.trim(),
    category_id: catId,
    is_required: document.getElementById('c-required').checked ? 1 : 0,
    sort_ord: parseInt(document.getElementById('c-sort').value)||0,
    published: pubEl && pubEl.checked ? 1 : 0,
  };
  if (COURSE_ID) body.id = COURSE_ID;
  api(body).then(d => {
    if (d.ok) {
      if (!COURSE_ID && d.id) { location.href = 'admin_university_course.php?id=' + d.id; }
      else toast('Saved ✓');
    } else alert(d.error);
  });
}

// ── Thumbnail ────────────────────────────────────────────────────────────
function handleThumbDrop(e){e.preventDefault();e.currentTarget.classList.remove('drag');if(e.dataTransfer.files[0])uploadThumb(e.dataTransfer.files[0]);}
function uploadThumb(file) {
  const fd = new FormData();
  fd.append('action','upload_thumbnail');
  fd.append('course_id', COURSE_ID);
  fd.append('file', file);
  document.getElementById('thumb-status').textContent = 'Uploading…';
  document.getElementById('thumb-filename').textContent = file.name;
  fetch('api/uni_action.php',{method:'POST',credentials:'same-origin',body:fd})
    .then(r=>r.json()).then(d=>{
      if(d.ok){document.getElementById('thumb-status').textContent='';toast('Thumbnail saved ✓');location.reload();}
      else document.getElementById('thumb-status').textContent='Error: '+(d.error||'upload failed');
    });
}

// ── Lessons ───────────────────────────────────────────────────────────────
function onTypeChange() {
  const type      = document.getElementById('l-type').value;
  const fileSection = document.getElementById('l-file-section');
  const durField    = document.getElementById('l-duration-field');
  const embedField  = document.getElementById('l-embed-field');
  const orDivider   = document.getElementById('l-or-divider');
  document.getElementById('l-file-label').textContent = type === 'video' ? 'Video File (optional if using embed URL)' : 'Document File';
  fileSection.style.display = type === 'quiz' ? 'none' : '';
  durField.style.display    = type === 'video' ? '' : 'none';
  embedField.style.display  = type === 'video' ? '' : 'none';
  orDivider.style.display   = type === 'video' ? '' : 'none';
}

function openAddLesson() {
  document.getElementById('lesson-modal-title').textContent = 'Add Lesson';
  document.getElementById('l-id').value = '';
  document.getElementById('l-title').value = '';
  document.getElementById('l-type').value = 'video';
  document.getElementById('l-embed-url').value = '';
  document.getElementById('l-content').value = '';
  if (quill) quill.setContents([]);
  document.getElementById('l-duration').value = '0';
  document.getElementById('l-file-name').textContent = '';
  document.getElementById('l-file-current').style.display = 'none';
  document.getElementById('l-upload-status').textContent = '';
  pendingLessonFile = null;
  onTypeChange();
  document.getElementById('lesson-modal').classList.add('open');
}

function editLesson(lesson) {
  document.getElementById('lesson-modal-title').textContent = 'Edit Lesson';
  document.getElementById('l-id').value = lesson.id;
  document.getElementById('l-title').value = lesson.title;
  document.getElementById('l-type').value = lesson.type;
  document.getElementById('l-embed-url').value = lesson.embed_url || '';
  document.getElementById('l-content').value = lesson.content_html || '';
  if (quill) quill.root.innerHTML = lesson.content_html || '';
  document.getElementById('l-duration').value = lesson.duration_sec || 0;
  document.getElementById('l-file-name').textContent = '';
  document.getElementById('l-upload-status').textContent = '';
  const cur = document.getElementById('l-file-current');
  cur.style.display = lesson.file_key ? '' : 'none';
  pendingLessonFile = null;
  onTypeChange();
  document.getElementById('lesson-modal').classList.add('open');
}

function handleLessonFileDrop(e){e.preventDefault();e.currentTarget.classList.remove('drag');if(e.dataTransfer.files[0]){pendingLessonFile=e.dataTransfer.files[0];document.getElementById('l-file-name').textContent=pendingLessonFile.name;}}

function saveLesson() {
  const title = document.getElementById('l-title').value.trim();
  if (!title) { alert('Title required'); return; }
  const id = document.getElementById('l-id').value;
  const btn = document.getElementById('l-save-btn');
  btn.disabled = true; btn.textContent = 'Saving…';

  const afterSave = (lessonId) => {
    btn.disabled = false; btn.textContent = 'Save Lesson';
    closeModal('lesson-modal');

    if (pendingLessonFile) {
      const file = pendingLessonFile;
      pendingLessonFile = null;

      const indicator = document.getElementById('bg-upload');
      const text      = document.getElementById('bg-upload-text');
      const bar       = document.getElementById('bg-upload-bar');
      const fmtMB     = (b) => (b / 1048576).toFixed(1) + ' MB';
      indicator.style.display = 'block';
      text.textContent = `Uploading ${file.name}…`;
      bar.value = 0;

      const fd = new FormData();
      fd.append('action', 'upload_lesson_file');
      fd.append('lesson_id', lessonId);
      fd.append('file', file);

      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'api/uni_action.php', true);
      xhr.withCredentials = true;
      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) {
          bar.value = Math.round(e.loaded / e.total * 100);
          text.textContent = `Uploading ${file.name} — ${fmtMB(e.loaded)} / ${fmtMB(e.total)}`;
        }
      };
      xhr.onload = () => {
        indicator.style.display = 'none';
        try {
          const d = JSON.parse(xhr.responseText);
          if (d.ok) { toast('File uploaded ✓'); location.reload(); }
          else toast('Upload failed: ' + (d.error || 'unknown'));
        } catch(e) { toast('Upload error'); }
      };
      xhr.onerror = () => { indicator.style.display = 'none'; toast('Upload failed — network error'); };
      xhr.send(fd);
    } else {
      location.reload();
    }
  };

  const contentHtml = quill ? quill.root.innerHTML.replace(/<p><br><\/p>/g,'').trim() : document.getElementById('l-content').value.trim();
  const body = {
    action: id ? 'update_lesson' : 'create_lesson',
    title,
    type: document.getElementById('l-type').value,
    embed_url: document.getElementById('l-embed-url').value.trim(),
    content_html: contentHtml === '<p><br></p>' ? '' : contentHtml,
    duration_sec: parseInt(document.getElementById('l-duration').value)||0,
  };
  if (id) { body.id = parseInt(id); }
  else { body.course_id = COURSE_ID; }

  api(body).then(d => {
    if (d.ok) afterSave(id ? parseInt(id) : d.id);
    else { btn.disabled=false; btn.textContent='Save Lesson'; alert(d.error); }
  });
}

function deleteLesson(id, title) {
  if (!confirm(`Delete lesson "${title}"? Agent progress for this lesson will also be removed.`)) return;
  api({action:'delete_lesson',id}).then(d=>{if(d.ok)location.reload();else alert(d.error);});
}

// ── Drag-to-reorder ────────────────────────────────────────────────────────
let dragSrc = null;
document.addEventListener('DOMContentLoaded', () => {
  initDrag();
});
function initDrag() {
  const list = document.getElementById('lesson-list');
  if (!list) return;
  list.querySelectorAll('.lesson-row').forEach(row => {
    row.setAttribute('draggable','true');
    row.addEventListener('dragstart', e => { dragSrc = row; row.style.opacity = '.4'; });
    row.addEventListener('dragend', () => { dragSrc.style.opacity=''; saveOrder(); });
    row.addEventListener('dragover', e => { e.preventDefault(); if (dragSrc && dragSrc !== row) { const rect=row.getBoundingClientRect(); const mid=rect.top+rect.height/2; row.parentNode.insertBefore(dragSrc, e.clientY<mid ? row : row.nextSibling); } });
  });
}
function saveOrder() {
  const order = [...document.querySelectorAll('.lesson-row')].map(r=>parseInt(r.dataset.id));
  api({action:'reorder_lessons',order}).then(() => {
    // Update numbers
    document.querySelectorAll('.lesson-num').forEach((el,i) => el.textContent = i+1);
  });
}

// ── Quiz Questions ─────────────────────────────────────────────────────────
let activeQuesLessonId = null;
let editingQId = null;

function manageQuestions(lessonId, title) {
  activeQuesLessonId = lessonId;
  document.getElementById('q-modal-title').textContent = `Questions — ${title}`;
  loadQuestions();
  document.getElementById('q-modal').classList.add('open');
}

function loadQuestions() {
  api({action:'list_questions',lesson_id:activeQuesLessonId}).then(d => {
    const list = document.getElementById('q-list');
    if (!d.questions || !d.questions.length) { list.innerHTML='<div style="color:#bbb;font-size:13px;text-align:center;padding:20px">No questions yet.</div>'; return; }
    list.innerHTML = d.questions.map((q,i) => {
      const opts = JSON.parse(q.options||'[]');
      return `<div class="q-row">
        <div class="q-num">${i+1}</div>
        <div class="q-text"><strong>${esc(q.question)}</strong><br>
          ${opts.map((o,oi)=>`<span style="color:${oi==q.correct_index?'#5b8e0d':'#888'}">${oi==q.correct_index?'✓ ':'○ '}${esc(o)}</span>`).join(' &nbsp;')}
        </div>
        <div class="q-actions">
          <button class="btn-sm" onclick='openEditQuestion(${JSON.stringify(q)})'>Edit</button>
          <button class="btn-sm btn-danger" onclick="deleteQuestion(${q.id})">Del</button>
        </div>
      </div>`;
    }).join('');
  });
}

function openAddQuestion() {
  editingQId = null;
  document.getElementById('qe-title').textContent = 'Add Question';
  document.getElementById('qe-id').value = '';
  document.getElementById('qe-question').value = '';
  for (let i=0;i<4;i++) { document.getElementById(`qe-opt-${i}`).value=''; }
  document.getElementById('qe-opt-radio-0').checked = true;
  document.getElementById('qe-modal').classList.add('open');
}

function openEditQuestion(q) {
  const opts = JSON.parse(q.options || '[]');
  editingQId = q.id;
  document.getElementById('qe-title').textContent = 'Edit Question';
  document.getElementById('qe-id').value = q.id;
  document.getElementById('qe-question').value = q.question;
  for (let i=0;i<4;i++) { document.getElementById(`qe-opt-${i}`).value = opts[i]||''; }
  const radio = document.getElementById(`qe-opt-radio-${q.correct_index}`);
  if (radio) radio.checked = true;
  document.getElementById('qe-modal').classList.add('open');
}

function saveQuestion() {
  const question = document.getElementById('qe-question').value.trim();
  if (!question) { alert('Question text required'); return; }
  const options = [];
  for (let i=0;i<4;i++) { const v=document.getElementById(`qe-opt-${i}`).value.trim(); if(v) options.push(v); }
  if (options.length < 2) { alert('Need at least 2 options'); return; }
  const correctIdx = parseInt(document.querySelector('input[name=qe-correct]:checked')?.value||'0');
  const id = document.getElementById('qe-id').value;
  const body = { question, options, correct_index: correctIdx };
  if (id) { body.action='update_question'; body.id=parseInt(id); }
  else { body.action='create_question'; body.lesson_id=activeQuesLessonId; }
  api(body).then(d => {
    if (d.ok) { closeModal('qe-modal'); loadQuestions(); }
    else alert(d.error);
  });
}

function deleteQuestion(id) {
  if (!confirm('Delete this question?')) return;
  api({action:'delete_question',id}).then(d=>{if(d.ok)loadQuestions();});
}

function esc(s){return String(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}

// ── Access Tab ────────────────────────────────────────────────────────────
function toggleInvitePanel() {
  const on = document.getElementById('a-invite-only').checked;
  document.getElementById('invite-panel').style.display = on ? '' : 'none';
}

function saveAccess() {
  const inviteOnly  = document.getElementById('a-invite-only').checked ? 1 : 0;
  const stateFilter = [...document.querySelectorAll('.a-state:checked')].map(el => el.value);
  const roleFilter  = [...document.querySelectorAll('.a-role:checked')].map(el => el.value);
  api({action:'update_course', id:COURSE_ID, invite_only:inviteOnly, state_filter:stateFilter, role_filter:roleFilter,
       title:document.getElementById('c-title').value.trim(),
       description:document.getElementById('c-desc').value.trim(),
       is_required:document.getElementById('c-required').checked?1:0,
       sort_ord:parseInt(document.getElementById('c-sort').value)||0,
       published:document.getElementById('c-published')?.checked?1:0
  }).then(d => { if (d.ok) toast('Access settings saved ✓'); else alert(d.error); });
}

let searchTimer = null;
function searchAgents(q) {
  clearTimeout(searchTimer);
  const res = document.getElementById('invite-results');
  if (q.length < 2) { res.style.display='none'; return; }
  searchTimer = setTimeout(() => {
    api({action:'search_agents', q}).then(d => {
      if (!d.ok || !d.agents.length) { res.style.display='none'; return; }
      res.innerHTML = d.agents.map(a =>
        `<div style="padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #f0f0f0" onmouseover="this.style.background='#f5f5f5'" onmouseout="this.style.background=''" onclick="addInvite('${esc(a.email)}','${esc(a.name)}',this.parentElement)">${esc(a.name)} <span style="color:#aaa;font-size:11px">${esc(a.email)}</span></div>`
      ).join('');
      res.style.display = '';
    });
  }, 300);
}

function addInvite(email, name, resultsEl) {
  api({action:'add_invite', course_id:COURSE_ID, agent_email:email}).then(d => {
    if (!d.ok) { alert(d.error); return; }
    resultsEl.style.display = 'none';
    document.getElementById('invite-search').value = '';
    const list = document.getElementById('invite-list');
    if (list.querySelector('[style*="color:#bbb"]')) list.innerHTML = '';
    const chip = document.createElement('div');
    chip.className = 'invite-chip';
    chip.dataset.email = email;
    chip.style.cssText = 'display:flex;align-items:center;gap:8px;padding:7px 10px;background:#f5f5f5;border-radius:6px;margin-bottom:6px;font-size:13px';
    chip.innerHTML = `<span style="flex:1">${esc(email)}</span><span style="font-size:10px;color:#aaa">today</span><button class="btn-sm btn-danger" style="padding:3px 8px" onclick="removeInvite('${esc(email)}',this.closest('.invite-chip'))">✕</button>`;
    list.prepend(chip);
    toast(`${name || email} invited ✓`);
  });
}

function removeInvite(email, chipEl) {
  api({action:'remove_invite', course_id:COURSE_ID, agent_email:email}).then(d => {
    if (!d.ok) { alert(d.error); return; }
    chipEl.remove();
    if (!document.querySelectorAll('.invite-chip').length) {
      document.getElementById('invite-list').innerHTML = '<div style="color:#bbb;font-size:13px;padding:8px 0">No invites yet — search for agents above to add them.</div>';
    }
    toast('Invite removed');
  });
}
</script>
</body>
</html>
