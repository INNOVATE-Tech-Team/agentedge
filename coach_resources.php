<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';

// V1: super-admin-only, same gate as coach_dashboard.php. Placeholder only.
$agent = require_login();
if (!is_super_admin()) { header('Location: index.php'); exit; }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Coach Resources — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .bo-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
    .content-sub{font-size:13px;color:var(--faint);margin-top:2px}
    .empty-state{text-align:center;padding:50px 20px;color:var(--faint);font-size:14px}
    .empty-state .es-icon{font-size:28px;margin-bottom:10px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('coach_resources', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div class="bo-eyebrow">Coaching</div>
        <div class="content-title">Resources</div>
        <div class="content-sub">Coaching resources and reference materials — coming soon.</div>
      </div>
    </header>
    <main class="wrap" style="max-width:1100px">
      <div class="card">
        <div class="empty-state">
          <div class="es-icon">📚</div>
          Resources aren't built yet — this page is a placeholder.
        </div>
      </div>
    </main>
  </div>
</div>
</body>
</html>
