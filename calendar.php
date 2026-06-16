<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/nav.php';
$agent = require_login();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Calendar — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
  <div class="layout">
    <?php render_sidebar('calendar', $agent); ?>
    <div class="content">
      <header class="content-top">
        <div class="content-title">Company Calendar</div>
      </header>
      <main class="wrap">
        <section class="card">
          <div class="cal-header">
            <button class="btn-cal-nav" id="cal-prev">&#8592; Prev</button>
            <h2 class="cal-month-label" id="cal-month-label"></h2>
            <button class="btn-cal-nav" id="cal-next">Next &#8594;</button>
          </div>
          <div class="cal-grid" id="cal-grid"></div>
        </section>
        <section class="card" style="margin-top:16px">
          <div class="cal-list-header">
            <span class="cal-list-title" id="cal-list-title">Events</span>
          </div>
          <div id="cal-event-list-body"></div>
        </section>
      </main>
    </div>
  </div>
  <script src="assets/calendar.js"></script>
</body>
</html>
