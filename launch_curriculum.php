<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';

$agent = require_login();
if (!can_view_launch_curriculum()) { header('Location: index.php'); exit; }

$sessions = local_db()->query("SELECT * FROM launch_sessions ORDER BY session_number")->fetchAll(PDO::FETCH_ASSOC);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>LAUNCH Curriculum — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .lc-intro{color:var(--faint);font-size:13px;margin-bottom:20px;max-width:70ch}
    .lc-week-grid{display:flex;flex-direction:column;gap:12px}
    .lc-week-card{display:block;border:1px solid var(--border);border-radius:10px;padding:16px 20px;background:#fff;text-decoration:none;color:inherit;transition:border-color .15s}
    .lc-week-card:hover{border-color:#82C112}
    .lc-week-num{display:inline-block;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#82C112;margin-bottom:4px}
    .lc-week-title{font-size:16px;font-weight:800;color:#111;margin-bottom:4px}
    .lc-week-quote{font-size:12px;font-style:italic;color:var(--faint);margin-bottom:6px}
    .lc-week-jobs{font-size:12px;color:#555}
    .lc-week-jobs strong{color:#333}
    .lc-empty{text-align:center;color:var(--faint);padding:40px}
    .lc-framework-card{display:flex;align-items:center;gap:12px;border:1px solid var(--border);border-radius:10px;padding:14px 20px;background:#fafcf6;text-decoration:none;color:inherit;margin-bottom:20px}
    .lc-framework-card:hover{border-color:#82C112}
    .lc-framework-icon{font-size:20px}
    .lc-framework-title{font-size:14px;font-weight:800;color:#111}
    .lc-framework-sub{font-size:12px;color:var(--faint)}
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
      <div class="lc-intro">Facilitator reference for all 8 LAUNCH sessions, session overview, facilitator guide, participant content, worksheets, homework, and KPI targets for each week. Visible to coaching staff and facilitators only; agents don't see this page.</div>

      <a class="lc-framework-card" href="launch_framework.php">
        <div class="lc-framework-icon">📋</div>
        <div>
          <div class="lc-framework-title">Program-Level Accountability System</div>
          <div class="lc-framework-sub">The master framework every week's KPI Tracking and Accountability Standards sections reference, scorecards, weekly targets, flag/escalation criteria</div>
        </div>
      </a>

      <div class="lc-week-grid">
        <?php foreach ($sessions as $w): ?>
        <a class="lc-week-card" href="launch_session.php?session=<?= (int)$w['session_number'] ?>">
          <div class="lc-week-num">Session <?= (int)$w['session_number'] ?></div>
          <div class="lc-week-title"><?= h($w['title']) ?></div>
          <?php if ($w['theme_quote']): ?><div class="lc-week-quote">"<?= h($w['theme_quote']) ?>"</div><?php endif; ?>
          <?php if ($w['primary_jobs']): ?><div class="lc-week-jobs"><strong>Primary job(s):</strong> <?= h($w['primary_jobs']) ?></div><?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php if (!$sessions): ?>
        <div class="lc-empty">No curriculum content yet.</div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</div>
</body>
</html>
