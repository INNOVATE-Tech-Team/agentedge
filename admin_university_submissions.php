<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
if (!is_admin()) { header('Location: index.php'); exit; }
$db = local_db();

$lessonId = (int)($_GET['lesson_id'] ?? 0);
if (!$lessonId) { header('Location: admin_university.php'); exit; }

$ls = $db->prepare(
    "SELECT l.*, c.id as course_id, c.title as course_title
     FROM uni_lessons l JOIN uni_courses c ON c.id=l.course_id WHERE l.id=?"
);
$ls->execute([$lessonId]);
$lesson = $ls->fetch(PDO::FETCH_ASSOC);
if (!$lesson) { header('Location: admin_university.php'); exit; }

$uploads  = [];
$answers  = [];
if ($lesson['type'] === 'upload') {
    $us = $db->prepare("SELECT * FROM uni_learner_uploads WHERE lesson_id=? ORDER BY submitted_at DESC");
    $us->execute([$lessonId]);
    $uploads = $us->fetchAll(PDO::FETCH_ASSOC);
} elseif ($lesson['type'] === 'quiz') {
    $as = $db->prepare(
        "SELECT qa.*, q.question, q.qtype, q.options
         FROM uni_quiz_answers qa
         JOIN uni_questions q ON q.id=qa.question_id
         WHERE qa.lesson_id=?
         ORDER BY qa.agent_email, q.sort_ord, q.id"
    );
    $as->execute([$lessonId]);
    $answers = $as->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($lesson['title']) ?> — Submissions — University Admin</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .back-link{font-size:12px;color:#5b8e0d;text-decoration:none;font-weight:700;display:inline-flex;align-items:center;gap:4px;margin-bottom:16px}
    .back-link:hover{text-decoration:underline}
    table.sub-table{width:100%;border-collapse:collapse;font-size:13px}
    table.sub-table th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#888;padding:8px 12px;border-bottom:2px solid #eee}
    table.sub-table td{padding:10px 12px;border-bottom:1px solid #f0f0f0;vertical-align:top}
    table.sub-table tr:last-child td{border-bottom:none}
    .dl-btn{padding:5px 12px;font-size:11px;font-weight:700;border-radius:4px;border:1px solid #ddd;background:white;cursor:pointer;color:#333;text-decoration:none;display:inline-block}
    .dl-btn:hover{border-color:#82C112;color:#5b8e0d}
    .empty{text-align:center;color:#bbb;padding:40px;font-size:13px;border:1px dashed #eee;border-radius:8px}
    .agent-email{font-weight:700;color:#111}
    .qtype-tag{font-size:10px;color:#aaa;font-weight:700;text-transform:uppercase}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('admin_university', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Submissions</div>
    </header>
    <main class="wrap">
      <a class="back-link" href="admin_university_course.php?id=<?= (int)$lesson['course_id'] ?>">← Back to <?= htmlspecialchars($lesson['course_title']) ?></a>

      <div class="card" style="padding:20px 24px">
        <div style="font-size:14px;font-weight:800;color:#111;margin-bottom:4px"><?= htmlspecialchars($lesson['title']) ?></div>
        <div style="font-size:12px;color:#888;margin-bottom:18px">
          <?= $lesson['type'] === 'upload' ? 'Learner file submissions' : 'Quiz responses (open-ended and selected answers)' ?>
        </div>

        <?php if ($lesson['type'] === 'upload'): ?>
          <?php if (!$uploads): ?>
          <div class="empty">No submissions yet.</div>
          <?php else: ?>
          <table class="sub-table">
            <thead><tr><th>Agent</th><th>File</th><th>Submitted</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($uploads as $u): ?>
              <tr>
                <td class="agent-email"><?= htmlspecialchars($u['agent_email']) ?></td>
                <td><?= htmlspecialchars($u['original_name']) ?></td>
                <td><?= date('M j, Y g:ia', strtotime($u['submitted_at'])) ?></td>
                <td><a class="dl-btn" href="api/uni_download.php?submission=<?= (int)$u['id'] ?>" target="_blank">Download</a></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>

        <?php elseif ($lesson['type'] === 'quiz'): ?>
          <?php if (!$answers): ?>
          <div class="empty">No responses recorded yet.</div>
          <?php else: ?>
          <table class="sub-table">
            <thead><tr><th>Agent</th><th>Question</th><th>Response</th><th>Submitted</th></tr></thead>
            <tbody>
              <?php foreach ($answers as $a):
                $opts = json_decode($a['options'] ?? '[]', true) ?: [];
                if ($a['qtype'] === 'text') {
                    $resp = nl2br(htmlspecialchars($a['answer_text']));
                } else {
                    $sel = json_decode($a['selected_indexes'] ?? '[]', true) ?: [];
                    $resp = htmlspecialchars(implode(', ', array_map(fn($i) => $opts[$i] ?? '?', $sel)));
                }
              ?>
              <tr>
                <td class="agent-email"><?= htmlspecialchars($a['agent_email']) ?></td>
                <td><?= htmlspecialchars($a['question']) ?><br><span class="qtype-tag"><?= htmlspecialchars($a['qtype']) ?></span></td>
                <td><?= $resp ?></td>
                <td><?= date('M j, Y g:ia', strtotime($a['submitted_at'])) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>

        <?php else: ?>
        <div class="empty">This lesson type doesn't have submissions to review.</div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</div>
</body>
</html>
