<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
$db    = local_db();
$email = $agent['email'];

$lessonId = (int)($_GET['id'] ?? 0);
if (!$lessonId) { header('Location: university.php'); exit; }

$ls = $db->prepare(
    "SELECT l.*, c.id as course_id, c.title as course_title, c.published as course_published,
     c.invite_only, c.state_filter, c.role_filter,
     COALESCE(cat.name,'Uncategorized') as cat_name
     FROM uni_lessons l
     JOIN uni_courses c ON c.id=l.course_id
     LEFT JOIN uni_categories cat ON cat.id=c.category_id
     WHERE l.id=?"
);
$ls->execute([$lessonId]);
$lesson = $ls->fetch(PDO::FETCH_ASSOC);
if (!$lesson || (!$lesson['course_published'] && !is_admin())) { header('Location: university.php'); exit; }

// Enforce access control for non-admins
if (!is_admin()) {
    $cid = (int)$lesson['course_id'];
    if (!empty($lesson['invite_only'])) {
        $inv = $db->prepare("SELECT 1 FROM uni_course_invites WHERE course_id=? AND LOWER(agent_email)=?");
        $inv->execute([$cid, strtolower($email)]);
        if (!$inv->fetchColumn()) { header('Location: university.php'); exit; }
    }
    $sf = json_decode($lesson['state_filter'] ?? '[]', true);
    if (!empty($sf)) {
        $aiRow = $db->prepare("SELECT mc.state_code FROM agent_intake ai LEFT JOIN market_centers mc ON mc.slug=ai.office_location OR LOWER(mc.name)=LOWER(ai.office_location) WHERE LOWER(ai.email)=? LIMIT 1");
        $aiRow->execute([strtolower($email)]);
        $stateCode = ($aiRow->fetch(PDO::FETCH_ASSOC))['state_code'] ?? null;
        if (!$stateCode || !in_array($stateCode, $sf, true)) { header('Location: university.php'); exit; }
    }
    $rf = json_decode($lesson['role_filter'] ?? '[]', true);
    if (!empty($rf) && !array_intersect(my_roles(), $rf)) { header('Location: university.php'); exit; }
}

$courseId = (int)$lesson['course_id'];

// All lessons in this course for prev/next nav
$allLessons = $db->prepare("SELECT id, title, type FROM uni_lessons WHERE course_id=? ORDER BY sort_ord,id");
$allLessons->execute([$courseId]);
$allLessons = $allLessons->fetchAll(PDO::FETCH_ASSOC);
$lessonIndex = array_search($lessonId, array_column($allLessons, 'id'));
$prevLesson  = $lessonIndex > 0 ? $allLessons[$lessonIndex - 1] : null;
$nextLesson  = $lessonIndex < count($allLessons) - 1 ? $allLessons[$lessonIndex + 1] : null;

// This lesson's progress
$progQ = $db->prepare("SELECT * FROM uni_progress WHERE agent_email=? AND lesson_id=?");
$progQ->execute([$email, $lessonId]);
$progress = $progQ->fetch(PDO::FETCH_ASSOC);
$isComplete = $progress !== null;

// Quiz questions (without correct_index/correct_indexes — never sent to client)
$questions = [];
if ($lesson['type'] === 'quiz') {
    $qs = $db->prepare("SELECT id, question, options, qtype FROM uni_questions WHERE lesson_id=? ORDER BY sort_ord,id");
    $qs->execute([$lessonId]);
    $questions = $qs->fetchAll(PDO::FETCH_ASSOC);
    foreach ($questions as &$q) {
        $q['options'] = json_decode($q['options'] ?? '[]', true) ?: [];
        $q['qtype']   = $q['qtype'] ?: 'single';
    }
    unset($q);
}

// Attachments (extra downloadable files, alongside the primary video/doc file)
$attachments = [];
if (in_array($lesson['type'], ['video','doc','upload'])) {
    $as = $db->prepare("SELECT id, original_name FROM uni_lesson_files WHERE lesson_id=? ORDER BY sort_ord,id");
    $as->execute([$lessonId]);
    $attachments = $as->fetchAll(PDO::FETCH_ASSOC);
}

// Existing learner-upload submission
$mySubmission = null;
if ($lesson['type'] === 'upload') {
    $us = $db->prepare("SELECT original_name, submitted_at FROM uni_learner_uploads WHERE lesson_id=? AND agent_email=?");
    $us->execute([$lessonId, $email]);
    $mySubmission = $us->fetch(PDO::FETCH_ASSOC) ?: null;
}

$typeIcons = ['video' => '🎥', 'doc' => '📄', 'quiz' => '📝', 'placeholder' => '🧩', 'upload' => '📤'];
$lessonNum = $lessonIndex !== false ? $lessonIndex + 1 : 1;

function make_embed_url(string $url): string {
    if (preg_match('/youtube\.com\/watch\?.*v=([a-zA-Z0-9_-]+)/', $url, $m))
        return 'https://www.youtube.com/embed/' . $m[1];
    if (preg_match('/youtu\.be\/([a-zA-Z0-9_-]+)/', $url, $m))
        return 'https://www.youtube.com/embed/' . $m[1];
    if (preg_match('/vimeo\.com\/(\d+)(?:\/([a-f0-9]+))?/', $url, $m))
        return 'https://player.vimeo.com/video/' . $m[1] . (!empty($m[2]) ? '?h=' . $m[2] : '');
    if (preg_match('/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/', $url, $m))
        return 'https://drive.google.com/file/d/' . $m[1] . '/preview';
    return $url;
}
$embedUrl = !empty($lesson['embed_url']) ? make_embed_url($lesson['embed_url']) : '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($lesson['title']) ?> — INNOVATE University</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .lesson-breadcrumb{font-size:12px;color:#888;margin-bottom:14px;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
    .lesson-breadcrumb a{color:#5b8e0d;text-decoration:none;font-weight:700}
    .lesson-breadcrumb a:hover{text-decoration:underline}
    .lesson-breadcrumb span{color:#ccc}
    .lesson-header{margin-bottom:20px}
    .lesson-num{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#82C112;margin-bottom:4px}
    .lesson-title{font-size:20px;font-weight:900;color:#111;margin:0 0 6px}
    .lesson-type-tag{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;background:#f0f0f0;border-radius:10px;font-size:11px;font-weight:700;color:#555}
    /* Video player */
    .video-wrap{background:#000;border-radius:10px;overflow:hidden;margin-bottom:20px;position:relative}
    .video-wrap video{width:100%;max-height:500px;display:block}
    /* Doc viewer */
    .doc-wrap{border:1px solid #e0e0e0;border-radius:10px;padding:32px;text-align:center;background:#f9f9f9;margin-bottom:20px}
    .doc-icon{font-size:48px;margin-bottom:12px}
    .doc-title{font-size:15px;font-weight:700;color:#111;margin-bottom:8px}
    .doc-dl{display:inline-block;padding:10px 24px;background:#82C112;color:#000;font-weight:800;font-size:13px;border-radius:6px;text-decoration:none}
    .doc-dl:hover{background:#5b8e0d;color:#fff}
    /* Content HTML */
    .lesson-content{line-height:1.7;font-size:14px;color:#333;margin-bottom:20px}
    .lesson-content p{margin:0 0 12px}
    /* Quiz */
    .quiz-wrap{margin-bottom:20px}
    .quiz-progress{font-size:12px;color:#888;margin-bottom:16px;font-weight:700}
    .question-card{background:white;border:1px solid #e0e0e0;border-radius:10px;padding:20px 24px;margin-bottom:16px}
    .question-text{font-size:15px;font-weight:700;color:#111;margin-bottom:16px;line-height:1.4}
    .option-label{display:flex;align-items:center;gap:10px;padding:10px 14px;border:1.5px solid #e0e0e0;border-radius:8px;cursor:pointer;margin-bottom:8px;font-size:13px;transition:all 100ms}
    .option-label:hover{border-color:#82C112;background:#f9fdf5}
    .option-label input[type=radio]{accent-color:#82C112;width:16px;height:16px;flex-shrink:0}
    .option-label.selected{border-color:#82C112;background:#f0f9e8}
    .option-label.correct{border-color:#82C112;background:#e8f5e9}
    .option-label.wrong{border-color:#e53935;background:#fee}
    .quiz-nav{display:flex;gap:10px;align-items:center;margin-top:16px}
    .quiz-result{border-radius:10px;padding:24px;text-align:center;margin-bottom:20px}
    .quiz-result.pass{background:#e8f5e9;border:2px solid #82C112}
    .quiz-result.fail{background:#fff3f3;border:2px solid #e53935}
    .qr-icon{font-size:36px;margin-bottom:8px}
    .qr-score{font-size:28px;font-weight:900;margin-bottom:4px}
    .qr-msg{font-size:14px;color:#555}
    /* Nav bar */
    .lesson-nav{display:flex;justify-content:space-between;align-items:center;padding:14px 0;border-top:1px solid #eee;margin-top:8px;flex-wrap:wrap;gap:10px}
    .lesson-nav-btn{padding:9px 18px;border-radius:6px;font-weight:800;font-size:13px;text-decoration:none;border:1.5px solid #e0e0e0;color:#555;background:white}
    .lesson-nav-btn:hover{border-color:#82C112;color:#5b8e0d}
    .lesson-nav-btn.primary{background:#82C112;border-color:#82C112;color:#000}
    .lesson-nav-btn.primary:hover{background:#5b8e0d;color:#fff}
    .mark-done-btn{padding:10px 24px;background:#82C112;color:#000;font-weight:800;font-size:13px;border:none;border-radius:6px;cursor:pointer}
    .mark-done-btn:hover{background:#5b8e0d;color:#fff}
    .mark-done-btn:disabled{background:#c3dfa8;cursor:default}
    .done-badge{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#e8f5e9;color:#2e7d32;font-weight:800;font-size:13px;border-radius:6px}
    .cert-banner{background:linear-gradient(135deg,#fffbea,#fff3c0);border:2px solid #f5c842;border-radius:10px;padding:18px 24px;display:flex;align-items:center;gap:16px;margin-top:16px}
    .cert-banner-icon{font-size:32px}
    .cert-banner-text{flex:1}
    .cert-banner-title{font-size:15px;font-weight:800;color:#111;margin-bottom:2px}
    .cert-banner-sub{font-size:12px;color:#888}
    .cert-banner a{padding:8px 16px;background:#f5c842;color:#000;font-weight:800;font-size:12px;border-radius:6px;text-decoration:none;white-space:nowrap}
    .cert-banner a:hover{background:#d4a800}
    /* Attachments */
    .attach-list{display:flex;flex-direction:column;gap:8px;margin-bottom:20px}
    .attach-item{display:flex;align-items:center;gap:10px;padding:10px 16px;background:white;border:1px solid #e5e5e5;border-radius:8px;text-decoration:none;color:inherit;font-size:13px;font-weight:700}
    .attach-item:hover{border-color:#82C112;color:#5b8e0d}
    /* Learner upload lesson */
    .upload-dropzone{border:2px dashed #ccc;border-radius:10px;padding:36px;text-align:center;cursor:pointer;margin-bottom:20px}
    .upload-dropzone:hover,.upload-dropzone.drag{border-color:#82C112;background:#f9fdf5}
    .upload-dropzone p{margin:4px 0;font-size:13px;color:#888}
    .upload-submitted{background:#e8f5e9;border:2px solid #82C112;border-radius:10px;padding:20px 24px;margin-bottom:20px}
    .upload-submitted-title{font-size:14px;font-weight:800;color:#2e7d32;margin-bottom:4px}
    .upload-submitted-sub{font-size:12px;color:#555;margin-bottom:10px}
    /* Placeholder lesson */
    .placeholder-wrap{border:1px dashed #ddd;border-radius:10px;padding:48px;text-align:center;background:#fafafa;margin-bottom:20px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('university', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">INNOVATE University</div>
    </header>
    <main class="wrap">

      <!-- Breadcrumb -->
      <div class="lesson-breadcrumb">
        <a href="university.php">University</a>
        <span>/</span>
        <a href="university_course.php?id=<?= $courseId ?>"><?= htmlspecialchars($lesson['course_title']) ?></a>
        <span>/</span>
        <?= htmlspecialchars($lesson['title']) ?>
      </div>

      <!-- Lesson header -->
      <div class="lesson-header">
        <div class="lesson-num">Lesson <?= $lessonNum ?> of <?= count($allLessons) ?></div>
        <div class="lesson-title"><?= htmlspecialchars($lesson['title']) ?></div>
        <span class="lesson-type-tag"><?= $typeIcons[$lesson['type']] ?? '📄' ?> <?= ucfirst($lesson['type']) ?></span>
        <?php if ($isComplete): ?>&nbsp;<span style="color:#82C112;font-size:13px;font-weight:700">✓ Completed</span><?php endif; ?>
      </div>

      <?php
        function render_attachments($attachments) {
          if (!$attachments) return;
          ?>
          <div class="attach-list">
            <?php foreach ($attachments as $att): ?>
            <a class="attach-item" href="api/uni_download.php?attachment=<?= (int)$att['id'] ?>" target="_blank">📎 <?= htmlspecialchars($att['original_name'] ?: 'Download file') ?></a>
            <?php endforeach; ?>
          </div>
          <?php
        }
      ?>

      <!-- Placeholder lesson (not yet available) -->
      <?php if ($lesson['type'] === 'placeholder'): ?>
      <div class="placeholder-wrap">
        <div style="font-size:40px;margin-bottom:10px">🧩</div>
        <div style="font-size:15px;font-weight:800;color:#888">This lesson isn't available yet</div>
        <div style="font-size:13px;color:#bbb;margin-top:4px">Check back soon — this content is coming.</div>
      </div>

      <!-- Video lesson -->
      <?php elseif ($lesson['type'] === 'video'): ?>
      <?php if ($embedUrl): ?>
      <div class="video-wrap" style="padding-top:56.25%;position:relative;background:#000;border-radius:10px;overflow:hidden;margin-bottom:20px">
        <iframe src="<?= htmlspecialchars($embedUrl) ?>" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0" allowfullscreen allow="autoplay; fullscreen; picture-in-picture" onload="scheduleEmbedComplete()"></iframe>
      </div>
      <?php elseif ($lesson['file_key']): ?>
      <div class="video-wrap">
        <video id="lesson-video" controls preload="metadata" onended="onVideoEnd()">
          <source src="api/uni_download.php?id=<?= $lessonId ?>" type="video/mp4">
          Your browser does not support video playback.
        </video>
      </div>
      <?php else: ?>
      <div class="doc-wrap" style="background:#fff3cd;border-color:#ffc107">
        <div class="doc-icon">⚠️</div>
        <div class="doc-title">Video not uploaded yet</div>
      </div>
      <?php endif; ?>
      <?php if ($lesson['content_html']): ?>
      <div class="card" style="padding:20px 24px;margin-bottom:20px">
        <div class="lesson-content"><?= $lesson['content_html'] ?></div>
      </div>
      <?php endif; ?>
      <?php render_attachments($attachments); ?>
      <div id="complete-area">
        <?php if ($isComplete): ?>
        <span class="done-badge">✓ Lesson Complete</span>
        <?php else: ?>
        <button class="mark-done-btn" id="mark-done-btn" onclick="markComplete()">Mark as Complete</button>
        <?php endif; ?>
      </div>

      <!-- Document lesson -->
      <?php elseif ($lesson['type'] === 'doc'): ?>
      <?php if ($lesson['file_key']): ?>
      <div class="doc-wrap">
        <div class="doc-icon">📄</div>
        <div class="doc-title"><?= htmlspecialchars($lesson['title']) ?></div>
        <a class="doc-dl" href="api/uni_download.php?id=<?= $lessonId ?>" target="_blank" onclick="scheduleComplete()">⬇ Open / Download</a>
      </div>
      <?php else: ?>
      <div class="doc-wrap" style="background:#fff3cd;border-color:#ffc107">
        <div class="doc-icon">⚠️</div>
        <div class="doc-title">Document not uploaded yet</div>
      </div>
      <?php endif; ?>
      <?php if ($lesson['content_html']): ?>
      <div class="card" style="padding:20px 24px;margin-bottom:20px">
        <div class="lesson-content"><?= $lesson['content_html'] ?></div>
      </div>
      <?php endif; ?>
      <?php render_attachments($attachments); ?>
      <div id="complete-area">
        <?php if ($isComplete): ?>
        <span class="done-badge">✓ Lesson Complete</span>
        <?php else: ?>
        <button class="mark-done-btn" id="mark-done-btn" onclick="markComplete()">Mark as Complete</button>
        <?php endif; ?>
      </div>

      <!-- Quiz lesson -->
      <?php elseif ($lesson['type'] === 'quiz'): ?>
      <?php if ($lesson['content_html']): ?>
      <div class="card" style="padding:20px 24px;margin-bottom:20px">
        <div class="lesson-content"><?= $lesson['content_html'] ?></div>
      </div>
      <?php endif; ?>
      <div id="quiz-container">
        <?php if (!$questions): ?>
        <div style="color:#bbb;text-align:center;padding:40px;font-size:13px;border:1px dashed #eee;border-radius:10px">
          No questions have been added to this quiz yet.
        </div>
        <?php elseif ($isComplete): ?>
        <div class="quiz-result pass">
          <div class="qr-icon">🏆</div>
          <div class="qr-score" style="color:#2e7d32"><?= $progress['score'] ?>%</div>
          <div class="qr-msg">You passed this quiz! <?php if ($progress['attempts'] > 1): ?>(Attempt <?= $progress['attempts'] ?>)<?php endif; ?></div>
        </div>
        <div id="complete-area"><span class="done-badge">✓ Quiz Passed</span></div>
        <?php else: ?>
        <div id="quiz-form">
          <div class="quiz-progress" id="quiz-progress">Question 1 of <?= count($questions) ?></div>
          <?php foreach ($questions as $qi => $q): $qtype = $q['qtype'] ?: 'single'; ?>
          <div class="question-card" id="qcard-<?= $qi ?>" style="<?= $qi > 0 ? 'display:none' : '' ?>">
            <div class="question-text"><?= htmlspecialchars($q['question']) ?></div>
            <?php if ($qtype === 'text'): ?>
            <textarea id="qtext-<?= $qi ?>" rows="4" style="width:100%;box-sizing:border-box;padding:10px 14px;border:1.5px solid #e0e0e0;border-radius:8px;font-size:13px;font-family:inherit" placeholder="Type your answer…" oninput="answers[<?= $qi ?>]=this.value"></textarea>
            <div style="font-size:11px;color:#aaa;margin-top:6px">This response is reviewed manually and doesn't affect your score.</div>
            <?php else: ?>
            <?php foreach ($q['options'] as $oi => $opt): ?>
            <label class="option-label" id="opt-<?= $qi ?>-<?= $oi ?>" onclick="selectOption(<?= $qi ?>,<?= $oi ?>,this,'<?= $qtype ?>')">
              <input type="<?= $qtype === 'multiple' ? 'checkbox' : 'radio' ?>" name="q<?= $qi ?>" value="<?= $oi ?>">
              <?= htmlspecialchars($opt) ?>
            </label>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
          <div class="quiz-nav">
            <button class="lesson-nav-btn" id="quiz-prev" onclick="quizNav(-1)" style="display:none">← Previous</button>
            <span style="flex:1"></span>
            <button class="lesson-nav-btn primary" id="quiz-next" onclick="quizNav(1)">Next →</button>
            <button class="lesson-nav-btn primary" id="quiz-submit" onclick="submitQuiz()" style="display:none">Submit Quiz</button>
          </div>
        </div>
        <div id="quiz-result" style="display:none"></div>
        <div id="complete-area"></div>
        <?php endif; ?>
      </div>

      <!-- Learner upload lesson -->
      <?php elseif ($lesson['type'] === 'upload'): ?>
      <?php if ($lesson['content_html']): ?>
      <div class="card" style="padding:20px 24px;margin-bottom:20px">
        <div class="lesson-content"><?= $lesson['content_html'] ?></div>
      </div>
      <?php endif; ?>
      <?php render_attachments($attachments); ?>
      <?php if ($mySubmission): ?>
      <div class="upload-submitted">
        <div class="upload-submitted-title">✓ Submitted</div>
        <div class="upload-submitted-sub"><?= htmlspecialchars($mySubmission['original_name']) ?> — <?= date('F j, Y g:ia', strtotime($mySubmission['submitted_at'])) ?></div>
        <button class="btn-cancel" style="background:white;border:1.5px solid #ccc;padding:8px 16px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700" onclick="document.getElementById('upload-input').click()">Re-upload</button>
      </div>
      <?php else: ?>
      <div class="upload-dropzone" onclick="document.getElementById('upload-input').click()" ondragover="event.preventDefault();this.classList.add('drag')" ondragleave="this.classList.remove('drag')" ondrop="handleUploadDrop(event)">
        <div style="font-size:32px;margin-bottom:6px">📤</div>
        <p><strong>Click to upload</strong> or drag your file here</p>
      </div>
      <?php endif; ?>
      <input type="file" id="upload-input" style="display:none" onchange="submitLearnerUpload(this.files[0])">
      <div class="upload-status" id="upload-status" style="font-size:12px;color:#888;min-height:18px"></div>
      <div id="complete-area"></div>
      <?php endif; ?>

      <!-- Cert banner (shown after completion if earned) -->
      <div id="cert-banner" style="display:none"></div>

      <!-- Lesson navigation -->
      <div class="lesson-nav">
        <?php if ($prevLesson): ?>
        <a class="lesson-nav-btn" href="university_lesson.php?id=<?= (int)$prevLesson['id'] ?>">← <?= htmlspecialchars($prevLesson['title']) ?></a>
        <?php else: ?>
        <a class="lesson-nav-btn" href="university_course.php?id=<?= $courseId ?>">← Back to Course</a>
        <?php endif; ?>
        <?php if ($nextLesson): ?>
        <a class="lesson-nav-btn primary" id="next-btn" href="university_lesson.php?id=<?= (int)$nextLesson['id'] ?>">
          <?= htmlspecialchars($nextLesson['title']) ?> →
        </a>
        <?php else: ?>
        <a class="lesson-nav-btn primary" href="university_course.php?id=<?= $courseId ?>">Finish Course →</a>
        <?php endif; ?>
      </div>

    </main>
  </div>
</div>
<script>
const LESSON_ID = <?= $lessonId ?>;
const COURSE_ID = <?= $courseId ?>;
let alreadyDone = <?= $isComplete ? 'true' : 'false' ?>;

function onVideoEnd() { if (!alreadyDone) markComplete(); }

function scheduleEmbedComplete() {
  // For embedded videos: show the Mark as Complete button immediately (can't detect end via iframe).
}

function scheduleComplete() {
  // For doc lessons: mark complete 2s after opening
  if (!alreadyDone) setTimeout(markComplete, 2000);
}

function markComplete() {
  const btn = document.getElementById('mark-done-btn');
  if (btn) btn.disabled = true;
  fetch('api/uni_progress.php', {
    method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action:'complete', lesson_id: LESSON_ID}),
  }).then(r => r.json()).then(d => {
    if (d.ok) {
      alreadyDone = true;
      const area = document.getElementById('complete-area');
      if (area) area.innerHTML = '<span class="done-badge">✓ Lesson Complete</span>';
      if (d.cert) showCertBanner(d.cert);
    }
  });
}

function showCertBanner(cert) {
  const banner = document.getElementById('cert-banner');
  banner.style.display = 'flex';
  banner.innerHTML = `<div class="cert-banner">
    <div class="cert-banner-icon">🏆</div>
    <div class="cert-banner-text">
      <div class="cert-banner-title">Certificate Earned!</div>
      <div class="cert-banner-sub">You completed this course. Code: ${cert.cert_code}</div>
    </div>
    <a href="university_certs.php?print=1&code=${encodeURIComponent(cert.cert_code)}" target="_blank">Print Certificate</a>
  </div>`;
}

// ── Learner upload lesson ────────────────────────────────────────────────────
function handleUploadDrop(e) { e.preventDefault(); e.currentTarget.classList.remove('drag'); if (e.dataTransfer.files[0]) submitLearnerUpload(e.dataTransfer.files[0]); }
function submitLearnerUpload(file) {
  if (!file) return;
  const status = document.getElementById('upload-status');
  status.textContent = 'Uploading…';
  const fd = new FormData();
  fd.append('action', 'submit_learner_upload');
  fd.append('lesson_id', LESSON_ID);
  fd.append('file', file);
  fetch('api/uni_progress.php', { method: 'POST', credentials: 'same-origin', body: fd })
    .then(r => r.json()).then(d => {
      if (d.ok) { status.textContent = ''; location.reload(); }
      else status.textContent = 'Error: ' + (d.error || 'upload failed');
    });
}

// ── Quiz logic ─────────────────────────────────────────────────────────────
const TOTAL_Q = <?= count($questions) ?>;
const QTYPES = <?= json_encode(array_map(fn($q) => $q['qtype'] ?: 'single', $questions)) ?>;
let currentQ = 0;
const answers = QTYPES.map(t => t === 'multiple' ? [] : (t === 'text' ? '' : null));

function selectOption(qIdx, optIdx, el, qtype) {
  if (qtype === 'multiple') {
    el.classList.toggle('selected');
    const set = new Set(answers[qIdx]);
    if (set.has(optIdx)) set.delete(optIdx); else set.add(optIdx);
    answers[qIdx] = [...set];
  } else {
    document.querySelectorAll(`#qcard-${qIdx} .option-label`).forEach(l => l.classList.remove('selected'));
    el.classList.add('selected');
    answers[qIdx] = optIdx;
  }
}

function quizNav(dir) {
  document.getElementById(`qcard-${currentQ}`).style.display = 'none';
  currentQ += dir;
  document.getElementById(`qcard-${currentQ}`).style.display = '';
  document.getElementById('quiz-progress').textContent = `Question ${currentQ + 1} of ${TOTAL_Q}`;
  document.getElementById('quiz-prev').style.display = currentQ > 0 ? '' : 'none';
  const isLast = currentQ === TOTAL_Q - 1;
  document.getElementById('quiz-next').style.display = isLast ? 'none' : '';
  document.getElementById('quiz-submit').style.display = isLast ? '' : 'none';
}

function submitQuiz() {
  const unanswered = answers.some((a, i) => QTYPES[i] === 'multiple' ? a.length === 0 : (QTYPES[i] === 'text' ? !String(a || '').trim() : a === null));
  if (unanswered) { alert('Please answer all questions before submitting.'); return; }
  const btn = document.getElementById('quiz-submit');
  btn.disabled = true;
  btn.textContent = 'Grading…';
  fetch('api/uni_progress.php', {
    method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action:'submit_quiz', lesson_id: LESSON_ID, answers}),
  }).then(r => r.json()).then(d => {
    const form   = document.getElementById('quiz-form');
    const result = document.getElementById('quiz-result');
    const area   = document.getElementById('complete-area');
    if (d.passed) {
      form.style.display = 'none';
      result.style.display = '';
      result.innerHTML = `<div class="quiz-result pass">
        <div class="qr-icon">🎉</div>
        <div class="qr-score" style="color:#2e7d32">${d.score}%</div>
        <div class="qr-msg">Passed! ${d.correct} of ${d.total} correct.</div>
      </div>`;
      area.innerHTML = '<span class="done-badge">✓ Quiz Passed</span>';
      alreadyDone = true;
      if (d.cert) showCertBanner(d.cert);
    } else {
      result.style.display = '';
      result.innerHTML = `<div class="quiz-result fail">
        <div class="qr-icon">😕</div>
        <div class="qr-score" style="color:#e53935">${d.score}%</div>
        <div class="qr-msg">${d.correct} of ${d.total} correct — need 70% to pass. <a href="university_lesson.php?id=${LESSON_ID}" style="color:#e53935;font-weight:700">Retake</a></div>
      </div>`;
      btn.disabled = false;
      btn.textContent = 'Submit Quiz';
    }
  });
}

// Init quiz nav buttons
if (TOTAL_Q > 0) {
  document.addEventListener('DOMContentLoaded', () => {
    const prev = document.getElementById('quiz-prev');
    const next = document.getElementById('quiz-next');
    const sub  = document.getElementById('quiz-submit');
    if (prev) prev.style.display = 'none';
    if (TOTAL_Q === 1) { if (next) next.style.display = 'none'; if (sub) sub.style.display = ''; }
  });
}
</script>
</body>
</html>
