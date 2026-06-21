<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
$db    = local_db();
$email = $agent['email'];

$lessonId = (int)($_GET['id'] ?? 0);
if (!$lessonId) { header('Location: university.php'); exit; }

$ls = $db->prepare(
    "SELECT l.*, c.id as course_id, c.title as course_title, c.published as course_published,
     COALESCE(cat.name,'Uncategorized') as cat_name
     FROM uni_lessons l
     JOIN uni_courses c ON c.id=l.course_id
     LEFT JOIN uni_categories cat ON cat.id=c.category_id
     WHERE l.id=?"
);
$ls->execute([$lessonId]);
$lesson = $ls->fetch(PDO::FETCH_ASSOC);
if (!$lesson || (!$lesson['course_published'] && !is_admin())) { header('Location: university.php'); exit; }

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

// Quiz questions (without correct_index — never sent to client)
$questions = [];
if ($lesson['type'] === 'quiz') {
    $qs = $db->prepare("SELECT id, question, options FROM uni_questions WHERE lesson_id=? ORDER BY sort_ord,id");
    $qs->execute([$lessonId]);
    $questions = $qs->fetchAll(PDO::FETCH_ASSOC);
    foreach ($questions as &$q) { $q['options'] = json_decode($q['options'] ?? '[]', true) ?: []; }
    unset($q);
}

$typeIcons = ['video' => '🎥', 'doc' => '📄', 'quiz' => '📝'];
$lessonNum = $lessonIndex !== false ? $lessonIndex + 1 : 1;
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

      <!-- Video lesson -->
      <?php if ($lesson['type'] === 'video'): ?>
      <?php if ($lesson['file_key']): ?>
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
          <?php foreach ($questions as $qi => $q): ?>
          <div class="question-card" id="qcard-<?= $qi ?>" style="<?= $qi > 0 ? 'display:none' : '' ?>">
            <div class="question-text"><?= htmlspecialchars($q['question']) ?></div>
            <?php foreach ($q['options'] as $oi => $opt): ?>
            <label class="option-label" id="opt-<?= $qi ?>-<?= $oi ?>" onclick="selectOption(<?= $qi ?>,<?= $oi ?>,this)">
              <input type="radio" name="q<?= $qi ?>" value="<?= $oi ?>">
              <?= htmlspecialchars($opt) ?>
            </label>
            <?php endforeach; ?>
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

// ── Quiz logic ─────────────────────────────────────────────────────────────
const TOTAL_Q = <?= count($questions) ?>;
let currentQ = 0;
const answers = new Array(TOTAL_Q).fill(null);

function selectOption(qIdx, optIdx, el) {
  document.querySelectorAll(`#qcard-${qIdx} .option-label`).forEach(l => l.classList.remove('selected'));
  el.classList.add('selected');
  answers[qIdx] = optIdx;
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
  if (answers.includes(null)) { alert('Please answer all questions before submitting.'); return; }
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
