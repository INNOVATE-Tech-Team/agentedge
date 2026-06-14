<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
$agent = require_login();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
  <header class="topbar">
    <div class="topbar-brand"><span class="brand">INNOVATE</span> <span class="brand-edge">AgentEdge</span></div>
    <div class="topbar-right">
      <span class="who"><?= htmlspecialchars($agent['name'] ?: $agent['email']) ?></span>
      <a class="logout" href="logout.php">Sign out</a>
    </div>
  </header>

  <main class="wrap">
    <div id="sample-banner" class="banner" hidden>Showing sample numbers — live transaction + Darwin data wires in next.</div>

    <!-- KPI tiles -->
    <section class="tiles">
      <div class="tile tile-blue"><div class="tile-val" id="t-volume">—</div><div class="tile-lbl">Sales Volume</div></div>
      <div class="tile tile-green"><div class="tile-val" id="t-closed">—</div><div class="tile-lbl">Closed Deals</div></div>
      <div class="tile tile-amber"><div class="tile-val" id="t-residual">—</div><div class="tile-lbl">Residual Income</div></div>
      <div class="tile tile-red"><div class="tile-val" id="t-recruits">—</div><div class="tile-lbl">Recruits</div></div>
    </section>

    <div class="grid2">
      <!-- Cap wheel -->
      <section class="card">
        <h2>Cap Progress</h2>
        <div class="cap-wrap">
          <canvas id="capWheel" width="220" height="220"></canvas>
          <div class="cap-center"><span id="cap-pct">0%</span></div>
        </div>
        <dl class="cap-legend">
          <div><dt>Cap</dt><dd id="cap-amount">—</dd></div>
          <div><dt>Paid</dt><dd id="cap-paid">—</dd></div>
          <div><dt>Remaining</dt><dd id="cap-remaining">—</dd></div>
        </dl>
        <p class="src-note" id="cap-note"></p>
      </section>

      <!-- Network / residual -->
      <section class="card">
        <h2>Your Network &amp; Residual Income</h2>
        <div class="residual-head">
          <span class="residual-amt" id="residual-amt">—</span>
          <span class="residual-lbl">residual income earned</span>
        </div>
        <table class="tx" id="network-table" hidden>
          <thead><tr><th>Recruit</th><th class="num">Volume</th><th class="num">Deals</th></tr></thead>
          <tbody id="network-body"></tbody>
        </table>
        <div id="network-empty" class="network-empty">No recruits in your network yet.</div>
      </section>
    </div>
  </main>

  <script src="assets/app.js"></script>
</body>
</html>
