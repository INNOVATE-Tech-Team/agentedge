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
  <title>Agent Roster — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
  <div class="layout">
    <?php render_sidebar('roster', $agent); ?>
    <div class="content">
      <header class="content-top">
        <div class="content-title">Agent Roster</div>
        <input id="roster-search" class="search" type="search" placeholder="Search by name, email, location…">
      </header>
      <main class="wrap">
        <section class="card">
          <div class="roster-count" id="roster-count">Loading agents…</div>
          <table class="tx" id="roster-table" hidden>
            <thead><tr>
              <th>Agent</th><th>Market Center</th><th>Brokerage</th>
            </tr></thead>
            <tbody id="roster-body"></tbody>
          </table>
          <div id="roster-empty" class="network-empty" hidden>No agents found.</div>
        </section>
      </main>
    </div>
  </div>
  <script src="assets/roster.js"></script>
</body>
</html>
