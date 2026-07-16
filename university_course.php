<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
$db    = local_db();
$email = $agent['email'];

$courseId = (int)($_GET['id'] ?? 0);
if (!$courseId) { header('Location: university.php'); exit; }

$cs = $db->prepare(
    "SELECT c.*, COALESCE(cat.name,'Uncategorized') as cat_name, COALESCE(cat.icon,'📚') as cat_icon
     FROM uni_courses c LEFT JOIN uni_categories cat ON cat.id=c.category_id WHERE c.id=?"
);
$cs->execute([$courseId]);
$course = $cs->fetch(PDO::FETCH_ASSOC);
if (!$course || (!$course['published'] && !is_admin())) { header('Location: university.php'); exit; }

// Enforce access control for non-admins
if (!is_admin()) {
    // Invite-only check
    if (!empty($course['invite_only'])) {
        $inv = $db->prepare("SELECT 1 FROM uni_course_invites WHERE course_id=? AND LOWER(agent_email)=?");
        $inv->execute([$courseId, strtolower($email)]);
        if (!$inv->fetchColumn()) { header('Location: university.php'); exit; }
    }
    // State filter check
    $sf = json_decode($course['state_filter'] ?? '[]', true);
    if (!empty($sf)) {
        $aiRow = $db->prepare("SELECT mc.state_code FROM agent_intake ai LEFT JOIN market_centers mc ON mc.slug=ai.office_location OR LOWER(mc.name)=LOWER(ai.office_location) WHERE LOWER(ai.email)=? LIMIT 1");
        $aiRow->execute([strtolower($email)]);
        $stateCode = ($aiRow->fetch(PDO::FETCH_ASSOC))['state_code'] ?? null;
        if (!$stateCode || !in_array($stateCode, $sf, true)) { header('Location: university.php'); exit; }
    }
    // Role filter check
    $rf = json_decode($course['role_filter'] ?? '[]', true);
    if (!empty($rf) && !in_array(my_role(), $rf, true)) { header('Location: university.php'); exit; }
}

// Lessons
$ls = $db->prepare("SELECT * FROM uni_lessons WHERE course_id=? ORDER BY sort_ord,id");
$ls->execute([$courseId]);
$lessons = $ls->fetchAll(PDO::FETCH_ASSOC);

// This agent's progress
$progressMap = [];
if ($lessons) {
    $lessonIds    = array_column($lessons, 'id');
    $placeholders = implode(',', array_fill(0, count($lessonIds), '?'));
    $ps = $db->prepare("SELECT lesson_id, score, completed_at FROM uni_progress WHERE agent_email=? AND lesson_id IN ($placeholders)");
    $ps->execute(array_merge([$email], $lessonIds));
    foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $r) $progressMap[$r['lesson_id']] = $r;
}

// Cert
$certQ = $db->prepare("SELECT cert_code, issued_at FROM uni_certs WHERE agent_email=? AND course_id=?");
$certQ->execute([$email, $courseId]);
$cert = $certQ->fetch(PDO::FETCH_ASSOC);

$totalLessons = count($lessons);
$doneLessons  = count($progressMap);
$pct          = $totalLessons > 0 ? round($doneLessons / $totalLessons * 100) : 0;

// Find first incomplete lesson for "Continue" button
$firstIncomplete = null;
foreach ($lessons as $lesson) {
    if (!isset($progressMap[$lesson['id']])) { $firstIncomplete = $lesson['id']; break; }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($course['title']) ?> — INNOVATE University</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .course-header{background:linear-gradient(135deg,#1a1a1a 0%,#2d3a1e 100%);border-radius:12px;padding:28px 32px;color:white;margin-bottom:20px;display:flex;gap:24px;align-items:flex-start}
    .course-header-thumb{width:140px;height:100px;border-radius:8px;object-fit:cover;flex-shrink:0;background:#333;display:flex;align-items:center;justify-content:center;font-size:40px;overflow:hidden}
    .course-header-thumb img{width:100%;height:100%;object-fit:cover}
    .course-header-meta{flex:1}
    .course-back{font-size:11px;color:rgba(255,255,255,.6);text-decoration:none;display:inline-flex;align-items:center;gap:4px;margin-bottom:8px}
    .course-back:hover{color:white}
    .course-header-cat{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#82C112;margin-bottom:6px}
    .course-header-title{font-size:20px;font-weight:900;margin:0 0 8px}
    .course-header-desc{font-size:13px;color:rgba(255,255,255,.75);margin:0 0 14px;line-height:1.5}
    .course-header-stats{display:flex;gap:16px;align-items:center;flex-wrap:wrap}
    .ch-stat{font-size:12px;color:rgba(255,255,255,.7)}
    .ch-stat strong{color:white}
    .course-progress-bar{height:6px;background:rgba(255,255,255,.2);border-radius:3px;overflow:hidden;margin-top:10px}
    .course-progress-fill{height:100%;background:#82C112;border-radius:3px;transition:width 400ms}
    .course-header-cta{padding:10px 20px;background:#82C112;color:#000;font-weight:800;font-size:13px;border-radius:6px;text-decoration:none;white-space:nowrap;align-self:center}
    .course-header-cta:hover{background:#5b8e0d;color:#fff}
    .lesson-list{display:flex;flex-direction:column;gap:8px}
    .lesson-row{display:flex;align-items:center;gap:14px;padding:14px 18px;background:white;border:1px solid #e5e5e5;border-radius:8px;text-decoration:none;color:inherit;transition:border-color 100ms,box-shadow 100ms}
    .lesson-row:hover{border-color:#c3dfa8;box-shadow:0 2px 8px rgba(0,0,0,.06)}
    .lesson-row.completed{border-left:3px solid #82C112}
    .lesson-num{font-size:12px;font-weight:800;color:#bbb;width:24px;flex-shrink:0;text-align:right}
    .lesson-type-icon{font-size:18px;flex-shrink:0}
    .lesson-info{flex:1}
    .lesson-title{font-size:13px;font-weight:700;color:#111;line-height:1.3}
    .lesson-meta{font-size:11px;color:#aaa;margin-top:2px}
    .lesson-status{flex-shrink:0;text-align:right}
    .status-done{background:#e8f5e9;color:#2e7d32;font-size:11px;font-weight:700;padding:3px 10px;border-radius:10px}
    .status-quiz-score{background:#e8f0ff;color:#2255cc;font-size:11px;font-weight:700;padding:3px 10px;border-radius:10px}
    .status-todo{color:#ccc;font-size:18px}
    .cert-card{background:linear-gradient(135deg,#fffbea,#fff8d0);border:2px solid #f5c842;border-radius:12px;padding:24px;text-align:center;margin-top:24px}
    .cert-card-icon{font-size:40px;margin-bottom:8px}
    .cert-card-title{font-size:16px;font-weight:900;color:#111;margin-bottom:4px}
    .cert-card-sub{font-size:12px;color:#888;margin-bottom:14px}
    .cert-card-code{font-size:11px;font-family:monospace;background:#fff;border:1px solid #e0d080;padding:4px 10px;border-radius:4px;color:#555}
    .btn-cert{display:inline-block;padding:8px 20px;background:#f5c842;color:#000;font-weight:800;font-size:13px;border-radius:6px;text-decoration:none;margin-top:12px}
    .btn-cert:hover{background:#d4a800}
    .draft-banner{background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:10px 16px;font-size:12px;color:#856404;margin-bottom:16px}
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

      <?php if (!$course['published']): ?>
      <div class="draft-banner">⚠️ This course is unpublished — only admins can view it.</div>
      <?php endif; ?>

      <!-- Course header -->
      <div class="course-header">
        <div class="course-header-thumb">
          <?php if ($course['thumb_key']): ?>
          <img src="api/uni_download.php?thumb=1&course_id=<?= $courseId ?>" alt="">
          <?php else: ?>
          <?= htmlspecialchars($course['cat_icon']) ?>
          <?php endif; ?>
        </div>
        <div class="course-header-meta">
          <a class="course-back" href="university.php">← Back to Catalog</a>
          <div class="course-header-cat"><?= htmlspecialchars($course['cat_name']) ?></div>
          <div class="course-header-title">
            <?= htmlspecialchars($course['title']) ?>
            <?php if ($course['is_required']): ?><span style="font-size:11px;background:#ff6b35;color:white;padding:2px 8px;border-radius:10px;margin-left:8px;font-weight:700;vertical-align:middle">Required</span><?php endif; ?>
          </div>
          <?php if ($course['description']): ?>
          <div class="course-header-desc"><?= htmlspecialchars($course['description']) ?></div>
          <?php endif; ?>
          <div class="course-header-stats">
            <div class="ch-stat"><strong><?= $totalLessons ?></strong> lesson<?= $totalLessons !== 1 ? 's' : '' ?></div>
            <div class="ch-stat"><strong><?= $pct ?>%</strong> complete</div>
          </div>
          <div class="course-progress-bar"><div class="course-progress-fill" style="width:<?= $pct ?>%"></div></div>
        </div>
        <?php if ($firstIncomplete): ?>
        <a class="course-header-cta" href="university_lesson.php?id=<?= $firstIncomplete ?>">
          <?= $doneLessons > 0 ? 'Continue →' : 'Start Course' ?>
        </a>
        <?php endif; ?>
      </div>

      <!-- Lessons -->
      <div class="card" style="padding:20px 24px">
        <div style="font-size:13px;font-weight:800;color:#555;text-transform:uppercase;letter-spacing:.06em;margin-bottom:14px">
          Lessons (<?= $doneLessons ?>/<?= $totalLessons ?> complete)
        </div>
        <?php if (!$lessons): ?>
        <div style="color:#bbb;text-align:center;padding:32px;font-size:13px">No lessons added to this course yet.</div>
        <?php else: ?>
        <div class="lesson-list">
          <?php foreach ($lessons as $i => $lesson):
            $prog      = $progressMap[$lesson['id']] ?? null;
            $isDone    = $prog !== null;
            $typeIcons = ['video' => '🎥', 'doc' => '📄', 'quiz' => '📝'];
            $typeIcon  = $typeIcons[$lesson['type']] ?? '📄';
            $dur       = $lesson['duration_sec'] > 0 ? gmdate($lesson['duration_sec'] >= 3600 ? 'G\h i\m' : 'i\m s\s', $lesson['duration_sec']) : '';
            $typeLabel = ['video' => 'Video', 'doc' => 'Document', 'quiz' => 'Quiz'][$lesson['type']] ?? '';
          ?>
          <a class="lesson-row<?= $isDone ? ' completed' : '' ?>" href="university_lesson.php?id=<?= (int)$lesson['id'] ?>">
            <div class="lesson-num"><?= $i + 1 ?></div>
            <div class="lesson-type-icon"><?= $typeIcon ?></div>
            <div class="lesson-info">
              <div class="lesson-title"><?= htmlspecialchars($lesson['title']) ?></div>
              <div class="lesson-meta">
                <?= $typeLabel ?>
                <?php if ($dur): ?>&nbsp;·&nbsp;<?= $dur ?><?php endif; ?>
              </div>
            </div>
            <div class="lesson-status">
              <?php if ($isDone && $lesson['type'] === 'quiz' && $prog['score'] !== null): ?>
              <span class="status-quiz-score"><?= $prog['score'] ?>%</span>
              <?php elseif ($isDone): ?>
              <span class="status-done">✓ Done</span>
              <?php else: ?>
              <span class="status-todo">○</span>
              <?php endif; ?>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Certificate -->
      <?php if ($cert): ?>
      <div class="cert-card">
        <div class="cert-card-icon">🏆</div>
        <div class="cert-card-title">Certificate Earned!</div>
        <div class="cert-card-sub">You completed <strong><?= htmlspecialchars($course['title']) ?></strong> on <?= date('F j, Y', strtotime($cert['issued_at'])) ?></div>
        <div class="cert-card-code"><?= htmlspecialchars($cert['cert_code']) ?></div>
        <a class="btn-cert" href="university_certs.php?print=1&code=<?= urlencode($cert['cert_code']) ?>" target="_blank">Print Certificate</a>
      </div>
      <?php elseif ($totalLessons > 0 && $doneLessons >= $totalLessons): ?>
      <div class="cert-card" style="background:#f9fdf5;border-color:#c3dfa8">
        <div class="cert-card-icon">🎉</div>
        <div class="cert-card-title">Course Complete!</div>
        <div class="cert-card-sub">You finished all lessons — your certificate will appear here momentarily.</div>
      </div>
      <?php endif; ?>

    </main>
  </div>
</div>
</body>
</html>
