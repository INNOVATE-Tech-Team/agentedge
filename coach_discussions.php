<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';

// V1: super-admin-only, same gate as coach_dashboard.php. This is a
// structural page shell only — no discussion data model exists yet
// (see build report). Do not wire this to a real table without a
// separate data-model pass.
$agent = require_login();
if (!is_super_admin()) { header('Location: index.php'); exit; }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Coach Discussions — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .bo-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
    .content-sub{font-size:13px;color:var(--faint);margin-top:2px}
    .cd-composer{background:#f9fdf5;border:1px solid #d4edab;border-radius:10px;padding:20px 24px;margin-bottom:20px;opacity:.6}
    .cd-composer h3{margin:0 0 14px;font-size:14px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#5b8e0d}
    .cd-composer textarea{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;min-height:70px;resize:vertical;background:#fff}
    .cd-composer .btn-primary{margin-top:10px;padding:9px 22px;background:var(--green);color:#111;border:none;border-radius:6px;font-weight:800;font-size:13px}
    .cd-filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}
    .cd-filter-pill{padding:5px 14px;border-radius:20px;border:1px solid var(--border);background:#fff;font-size:12px;font-weight:600;color:var(--muted)}
    .cd-filter-pill.active{background:var(--green);border-color:var(--green);color:#111;font-weight:800}
    .empty-state{text-align:center;padding:50px 20px;color:var(--faint);font-size:14px}
    .empty-state .es-icon{font-size:28px;margin-bottom:10px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('coach_discussions', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div class="bo-eyebrow">Coaching</div>
        <div class="content-title">Discussions</div>
        <div class="content-sub">Coaching threads and conversations — coming soon.</div>
      </div>
    </header>
    <main class="wrap" style="max-width:1100px">

      <div class="cd-composer">
        <h3>Start a Discussion</h3>
        <textarea disabled placeholder="Discussion composer — not yet wired to a data source."></textarea>
        <button type="button" class="btn-primary" disabled>Post</button>
      </div>

      <div class="cd-filters">
        <span class="cd-filter-pill active">All</span>
        <span class="cd-filter-pill">Open</span>
        <span class="cd-filter-pill">Resolved</span>
      </div>

      <div class="card">
        <div class="empty-state">
          <div class="es-icon">💬</div>
          Discussions aren't built yet — this page is a placeholder shell.<br>
          The data model for threads, replies, and status will be defined in a follow-up pass.
        </div>
      </div>

    </main>
  </div>
</div>
</body>
</html>
