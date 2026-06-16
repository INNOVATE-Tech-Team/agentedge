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
        <div style="display:flex;align-items:center;gap:10px">
          <div class="content-title">Company Calendar</div>
          <span id="cal-mc-badge" class="cal-mc-badge"></span>
        </div>
      </header>
      <main class="wrap">
        <section class="card">
          <div class="cal-toolbar">
            <div class="cal-tabs">
              <button class="cal-tab cal-tab-active" data-filter="all">All <span class="cal-tab-count"></span></button>
              <button class="cal-tab" data-filter="company">Company <span class="cal-tab-count"></span></button>
              <button class="cal-tab" data-filter="mc" id="cal-tab-mc">Market Center <span class="cal-tab-count"></span></button>
              <button class="cal-tab" data-filter="bic">BIC <span class="cal-tab-count"></span></button>
            </div>
            <div class="cal-nav">
              <button class="btn-cal-nav" id="cal-prev">&#8592;</button>
              <strong class="cal-month-label" id="cal-month-label"></strong>
              <button class="btn-cal-nav" id="cal-next">&#8594;</button>
            </div>
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
