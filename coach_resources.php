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
  <link rel="stylesheet" href="assets/coach-dashboard.css">
</head>
<body>
<div class="layout coachdash">
  <?php render_sidebar('coach_resources', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div class="cd-eyebrow">Coach Dashboard</div>
        <div class="content-title">Resources</div>
        <div class="content-sub">Coaching resources and reference materials — coming soon.</div>
      </div>
    </header>
    <main class="cd-page">
      <div class="cd-card cd-empty">
        <div class="cd-empty-icon">&#128218;</div>
        Resources aren't built yet — this page is a placeholder.
      </div>
    </main>
  </div>
</div>
</body>
</html>
