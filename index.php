<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/roles.php';
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
  <style>
    .ann-panel{margin-bottom:20px}
    .ann-panel h2{margin:0 0 10px;font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px}
    .ann-panel h2 a{font-size:11px;font-weight:700;color:#5b8e0d;text-decoration:none;margin-left:auto}
    .ann-panel h2 a:hover{text-decoration:underline}
    .ann-item{padding:10px 14px;border-left:3px solid #82C112;background:#f9fdf5;border-radius:0 6px 6px 0;margin-bottom:8px}
    .ann-item.pinned{border-color:#f59e0b;background:#fffbeb}
    .ann-item-title{font-size:13px;font-weight:700;color:#111;margin-bottom:2px}
    .ann-item-body{font-size:12px;color:#555;white-space:pre-wrap;max-height:48px;overflow:hidden}
    .ann-item-meta{font-size:10px;color:#aaa;margin-top:4px}
  </style>
</head>
<body>
  <div class="layout">
    <?php render_sidebar('dashboard', $agent); ?>

    <!-- Main content -->
    <div class="content">
      <header class="content-top">
        <div class="content-title">Dashboard</div>
        <div class="content-hello">Welcome back, <?= htmlspecialchars(explode(' ', $agent['name'] ?: 'Agent')[0]) ?></div>
      </header>

      <main class="wrap">
        <div id="sample-banner" class="banner" hidden></div>

        <section class="tiles">
          <div class="tile tile-blue"><div class="tile-val" id="t-volume">—</div><div class="tile-lbl">Sales Volume</div></div>
          <div class="tile tile-green"><div class="tile-val" id="t-closed">—</div><div class="tile-lbl">Closed Deals</div></div>
          <div class="tile tile-amber"><div class="tile-val" id="t-residual">—</div><div class="tile-lbl">Residual Income</div></div>
          <div class="tile tile-red"><div class="tile-val" id="t-recruits">—</div><div class="tile-lbl">Recruits</div></div>
        </section>

        <div id="ann-panel" class="card ann-panel" style="display:none">
          <h2>Announcements <a href="backoffice_announcements.php" id="ann-manage-link" style="display:none">Manage →</a></h2>
          <div id="ann-list"></div>
        </div>

        <div class="grid2">
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
    </div>
  </div>

  <script src="assets/app.js"></script>
  <script>
  (function(){
    fetch('api/announcements.php',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      const items=d.items||[];
      if(!items.length)return;
      const panel=document.getElementById('ann-panel');
      const list=document.getElementById('ann-list');
      panel.style.display='';
      list.innerHTML=items.slice(0,5).map(a=>`
        <div class="ann-item${a.pinned?' pinned':''}">
          <div class="ann-item-title">${a.title.replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}</div>
          <div class="ann-item-body">${a.body.replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}</div>
          <div class="ann-item-meta">${new Date(a.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}</div>
        </div>`).join('');
    }).catch(()=>{});
    <?php if (can_post_announcements()): ?>
    document.getElementById('ann-manage-link').style.display='';
    <?php endif; ?>
  })();
  </script>
</body>
</html>
