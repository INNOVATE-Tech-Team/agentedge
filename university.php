<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
$db    = local_db();
$email = $agent['email'];

// Load published courses with category info + lesson counts
$courses = $db->query(
    "SELECT c.*, COALESCE(cat.name,'Uncategorized') as cat_name, COALESCE(cat.icon,'📚') as cat_icon,
     (SELECT COUNT(*) FROM uni_lessons WHERE course_id=c.id) as lesson_count
     FROM uni_courses c LEFT JOIN uni_categories cat ON cat.id=c.category_id
     WHERE c.published=1 ORDER BY c.sort_ord,c.id"
)->fetchAll(PDO::FETCH_ASSOC);

$categories = $db->query("SELECT * FROM uni_categories ORDER BY sort_ord,id")->fetchAll(PDO::FETCH_ASSOC);

// Per-course completion counts for this agent
$completedPerCourse = [];
$certCourseIds      = [];
if ($courses) {
    $courseIds    = array_column($courses, 'id');
    $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
    // Lessons this agent has completed, joined to their course
    $ps = $db->prepare("SELECT l.course_id, COUNT(*) as cnt FROM uni_progress p JOIN uni_lessons l ON l.id=p.lesson_id WHERE p.agent_email=? AND l.course_id IN ($placeholders) GROUP BY l.course_id");
    $ps->execute(array_merge([$email], $courseIds));
    foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $r) $completedPerCourse[$r['course_id']] = (int)$r['cnt'];
    // Certs
    $cs = $db->prepare("SELECT course_id FROM uni_certs WHERE agent_email=? AND course_id IN ($placeholders)");
    $cs->execute(array_merge([$email], $courseIds));
    $certCourseIds = array_flip($cs->fetchAll(PDO::FETCH_COLUMN, 0));
}

$totalCourses    = count($courses);
$completedCount  = count(array_filter($courses, fn($c) => ($completedPerCourse[$c['id']] ?? 0) >= $c['lesson_count'] && $c['lesson_count'] > 0));
$totalCerts      = count($certCourseIds);
$requiredCourses = array_filter($courses, fn($c) => $c['is_required']);
$requiredDone    = count(array_filter($requiredCourses, fn($c) => isset($certCourseIds[$c['id']])));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>INNOVATE University — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .uni-hero{background:linear-gradient(135deg,#1a1a1a 0%,#2d3a1e 100%);border-radius:12px;padding:28px 32px;color:white;margin-bottom:20px;display:flex;align-items:center;gap:20px}
    .uni-hero-icon{font-size:48px;line-height:1}
    .uni-hero-title{font-size:22px;font-weight:900;margin:0 0 4px}
    .uni-hero-sub{font-size:13px;color:rgba(255,255,255,.7);margin:0}
    .uni-stats{display:flex;gap:16px;margin-top:14px;flex-wrap:wrap}
    .uni-stat{background:rgba(255,255,255,.1);border-radius:8px;padding:8px 16px;text-align:center}
    .uni-stat-num{font-size:20px;font-weight:900;color:#82C112;line-height:1}
    .uni-stat-label{font-size:10px;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.06em}
    .uni-certs-link{margin-left:auto;background:#82C112;color:#000;font-weight:800;font-size:12px;padding:8px 16px;border-radius:6px;text-decoration:none;white-space:nowrap;align-self:flex-start}
    .uni-certs-link:hover{background:#5b8e0d;color:#fff}
    .cat-pills{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}
    .cat-pill{padding:6px 14px;border-radius:20px;font-size:12px;font-weight:700;border:1.5px solid #e0e0e0;background:white;cursor:pointer;color:#555;transition:all 100ms}
    .cat-pill:hover,.cat-pill.active{background:#82C112;border-color:#82C112;color:#000}
    .course-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
    .course-card{border:1px solid #e5e5e5;border-radius:12px;overflow:hidden;background:white;transition:box-shadow 150ms,border-color 150ms;display:flex;flex-direction:column}
    .course-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.1);border-color:#c3dfa8}
    .course-thumb{height:140px;background:linear-gradient(135deg,#f0f0f0,#e0e0e0);position:relative;overflow:hidden}
    .course-thumb img{width:100%;height:100%;object-fit:cover}
    .course-thumb-placeholder{width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:48px}
    .course-badge-req{position:absolute;top:8px;left:8px;background:#ff6b35;color:white;font-size:10px;font-weight:800;padding:2px 8px;border-radius:10px;text-transform:uppercase}
    .course-badge-done{position:absolute;top:8px;right:8px;background:#82C112;color:#000;font-size:10px;font-weight:800;padding:2px 8px;border-radius:10px}
    .course-body{padding:14px 16px;flex:1;display:flex;flex-direction:column}
    .course-cat{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#82C112;margin-bottom:4px}
    .course-title{font-size:14px;font-weight:800;color:#111;margin:0 0 6px;line-height:1.3}
    .course-desc{font-size:12px;color:#777;margin:0 0 10px;line-height:1.4;flex:1;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
    .course-progress{margin-bottom:10px}
    .progress-bar-bg{height:5px;background:#eee;border-radius:3px;overflow:hidden}
    .progress-bar-fill{height:100%;background:#82C112;border-radius:3px;transition:width 400ms}
    .progress-label{font-size:10px;color:#aaa;margin-top:3px}
    .course-cta{display:inline-block;padding:7px 14px;background:#82C112;color:#000;font-weight:800;font-size:12px;border-radius:6px;text-decoration:none;text-align:center}
    .course-cta:hover{background:#5b8e0d;color:#fff}
    .course-cta.done{background:#e8f5e9;color:#2e7d32}
    .empty-state{text-align:center;padding:60px 20px;color:#bbb}
    .empty-state .es-icon{font-size:48px;margin-bottom:12px}
    .empty-state p{font-size:14px}
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

      <!-- Hero bar -->
      <div class="uni-hero">
        <div class="uni-hero-icon">🎓</div>
        <div>
          <div class="uni-hero-title">INNOVATE University</div>
          <div class="uni-hero-sub">Sharpen your skills. Earn certifications. Grow your business.</div>
          <div class="uni-stats">
            <div class="uni-stat">
              <div class="uni-stat-num"><?= $completedCount ?>/<?= $totalCourses ?></div>
              <div class="uni-stat-label">Courses Complete</div>
            </div>
            <div class="uni-stat">
              <div class="uni-stat-num"><?= $totalCerts ?></div>
              <div class="uni-stat-label">Certifications</div>
            </div>
            <?php if (count($requiredCourses) > 0): ?>
            <div class="uni-stat">
              <div class="uni-stat-num"><?= $requiredDone ?>/<?= count($requiredCourses) ?></div>
              <div class="uni-stat-label">Required Done</div>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($totalCerts > 0): ?>
        <a class="uni-certs-link" href="university_certs.php">🏆 My Certificates</a>
        <?php endif; ?>
      </div>

      <!-- Category filter pills -->
      <?php if (count($categories) > 1): ?>
      <div class="cat-pills">
        <div class="cat-pill active" data-cat="all" onclick="filterCat('all',this)">All Courses</div>
        <?php foreach ($categories as $cat): ?>
        <div class="cat-pill" data-cat="<?= (int)$cat['id'] ?>" onclick="filterCat(<?= (int)$cat['id'] ?>,this)">
          <?= htmlspecialchars($cat['icon']) ?> <?= htmlspecialchars($cat['name']) ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Course grid -->
      <?php if (!$courses): ?>
      <div class="empty-state">
        <div class="es-icon">📚</div>
        <p>No courses published yet — check back soon!</p>
      </div>
      <?php else: ?>
      <div class="course-grid" id="course-grid">
        <?php foreach ($courses as $c):
          $done     = $completedPerCourse[$c['id']] ?? 0;
          $total    = (int)$c['lesson_count'];
          $pct      = $total > 0 ? round($done / $total * 100) : 0;
          $hasCert  = isset($certCourseIds[$c['id']]);
          $started  = $done > 0;
          $finished = $hasCert || ($total > 0 && $done >= $total);
          $ctaLabel = $finished ? '✓ Review' : ($started ? 'Continue →' : 'Start Course');
          $ctaClass = $finished ? 'course-cta done' : 'course-cta';
        ?>
        <div class="course-card" data-cat="<?= (int)$c['category_id'] ?>">
          <div class="course-thumb">
            <?php if ($c['thumb_key']): ?>
            <img src="api/uni_download.php?thumb=1&course_id=<?= (int)$c['id'] ?>" alt="">
            <?php else: ?>
            <div class="course-thumb-placeholder"><?= htmlspecialchars($c['cat_icon']) ?></div>
            <?php endif; ?>
            <?php if ($c['is_required']): ?><span class="course-badge-req">Required</span><?php endif; ?>
            <?php if ($hasCert): ?><span class="course-badge-done">🏆 Certified</span><?php endif; ?>
          </div>
          <div class="course-body">
            <div class="course-cat"><?= htmlspecialchars($c['cat_name']) ?></div>
            <div class="course-title"><?= htmlspecialchars($c['title']) ?></div>
            <?php if ($c['description']): ?>
            <div class="course-desc"><?= htmlspecialchars($c['description']) ?></div>
            <?php endif; ?>
            <div class="course-progress">
              <div class="progress-bar-bg"><div class="progress-bar-fill" style="width:<?= $pct ?>%"></div></div>
              <div class="progress-label"><?= $done ?> of <?= $total ?> lesson<?= $total !== 1 ? 's' : '' ?> complete</div>
            </div>
            <a class="<?= $ctaClass ?>" href="university_course.php?id=<?= (int)$c['id'] ?>"><?= $ctaLabel ?></a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </main>
  </div>
</div>
<script>
function filterCat(cat, el) {
  document.querySelectorAll('.cat-pill').forEach(p => p.classList.remove('active'));
  el.classList.add('active');
  document.querySelectorAll('.course-card').forEach(card => {
    card.style.display = (cat === 'all' || String(card.dataset.cat) === String(cat)) ? '' : 'none';
  });
}
</script>
</body>
</html>
