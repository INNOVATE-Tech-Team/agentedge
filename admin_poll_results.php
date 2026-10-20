<?php
// Back Office → Technology → Polls → Results (super admin only).
// Per-person answers and names live ONLY behind this page and
// api/admin_polls_action.php. Live-updates by polling that API every 5s.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/polls.php';

$agent = require_login();
if (!is_super_admin()) { header('Location: index.php'); exit; }
$poll = polls_load_by_id(local_db(), (int)($_GET['id'] ?? 0));
if (!$poll) { header('Location: admin_polls.php'); exit; }
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Results — <?= h($poll['title']) ?> — AgentEdge Back Office</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .pr-stage{background:#0b0b0b;color:#f5f5f0;margin:-24px;padding:22px 24px 60px;min-height:calc(100vh - 62px);
      --card:#1b1b1b;--line:#82c012;--accent:#9be22b;--panel:rgba(255,255,255,.08);--bar:#82c012}
    .pr-head{display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;margin-bottom:18px}
    .pr-head h1{font-size:clamp(34px,5vw,60px);margin:0;font-weight:800;letter-spacing:-.02em}
    .pr-chip{font-weight:700;font-size:14px;padding:8px 14px;border-radius:10px;background:var(--panel)}
    .pr-sp{flex:1}
    .pr-btn{font:inherit;font-weight:800;font-size:13px;padding:8px 14px;border-radius:12px;border:2px solid #f5f5f0;background:transparent;color:#f5f5f0;cursor:pointer;text-decoration:none;display:inline-block}
    .pr-totals{background:var(--card);border:2px solid #f5f5f0;border-radius:18px;padding:20px 22px;margin-bottom:22px}
    .pr-eyebrow{font-weight:800;letter-spacing:.14em;font-size:13px;text-transform:uppercase;margin:0 0 10px}
    .pr-trow{display:grid;grid-template-columns:210px 1fr 150px;gap:14px;align-items:center;margin:10px 0;font-weight:700}
    .pr-tbar{height:24px;background:var(--panel);border-radius:8px;overflow:hidden}
    .pr-tbar b{display:block;height:100%;background:var(--bar);border-radius:8px;transition:width .4s}
    .pr-tval small{font-weight:600;opacity:.7}
    .pr-people{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}
    .pr-person{background:var(--card);border:2px dotted var(--line);border-radius:16px;padding:16px 18px}
    .pr-person h4{margin:0 0 2px;font-size:20px;font-weight:800}
    .pr-person .em{font-size:12px;opacity:.6;margin-bottom:8px}
    .pr-person ul{margin:0;padding:0;list-style:none;font-size:16px;line-height:1.6}
    .pr-person li b{color:var(--accent);font-weight:800}
    .pr-tags{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
    .pr-tags span{font-size:12px;font-weight:700;padding:4px 10px;border-radius:99px;background:#f5f5f0;color:#0b0b0b}
    .pr-tags span.staff{background:#ffe44d}
    .pr-empty{padding:30px;text-align:center;font-weight:600;background:var(--panel);border-radius:16px}
    .pr-nv{margin-top:26px} .pr-nv summary{cursor:pointer;font-weight:700}
    .pr-nv ul{columns:3 220px;margin:12px 0 0;padding-left:18px;font-size:14px;line-height:1.7}
    @media (max-width:899px){.pr-trow{grid-template-columns:1fr;gap:4px}}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('admin_polls', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)">Back Office · Technology · <a href="admin_polls.php">Polls</a></div>
        <div class="content-title"><?= h($poll['title']) ?></div>
      </div>
    </header>
    <main class="wrap">
      <div class="pr-stage" id="pr">Loading…</div>
    </main>
  </div>
</div>
<script>
(function () {
  var ID = <?= (int)$poll['id'] ?>, el = document.getElementById('pr'), last = null;
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
  function render(d) {
    var P = d.poll, T = d.totals, agents = T.voters, staff = d.staff_count;
    var h = '<div class="pr-head"><h1>Results</h1><div class="pr-chip">' + agents + ' agent' + (agents === 1 ? '' : 's') + (staff ? ' + ' + staff + ' staff' : '') + '</div>' +
      '<div class="pr-chip" style="text-transform:uppercase">' + esc(P.status) + '</div><div class="pr-sp"></div>' +
      '<button class="pr-btn" id="pr-copy">Copy as text</button><a class="pr-btn" href="api/admin_polls_action.php?action=csv&id=' + ID + '">Export CSV</a></div>';
    if (staff) h += '<p style="opacity:.7;font-size:13px;margin:-6px 0 14px">Totals below count agents only. Staff responses are tagged on their cards and included in Copy / CSV.</p>';
    if (!d.people.length) h += '<div class="pr-empty">No votes yet. Results appear here live as people submit.</div>';
    else {
      h += '<div class="pr-totals">';
      T.steps.forEach(function (st) {
        var alloc = st.type === 'allocate', vals = st.options.map(function (o) { return alloc ? o.dollars : o.people; });
        var max = Math.max(alloc ? 20 : Math.max(1, agents), Math.max.apply(null, vals));
        h += '<p class="pr-eyebrow" style="margin-top:' + (st === T.steps[0] ? 0 : 18) + 'px">' + (alloc ? 'Where the money went' : esc(st.title)) + '</p>';
        st.options.forEach(function (o, i) {
          h += '<div class="pr-trow"><div>' + esc(o.label) + '</div><div class="pr-tbar"><b style="width:' + (vals[i] / max * 100) + '%"></b></div><div class="pr-tval">' +
            (alloc ? '$' + o.dollars + ' <small>· ' + o.people + ' ' + (o.people === 1 ? 'person' : 'people') + '</small>' : o.people + ' <small>of ' + agents + '</small>') + '</div></div>';
        });
      });
      h += '</div><div class="pr-people">';
      d.people.forEach(function (p) {
        var li = '', tags = '';
        P.steps.forEach(function (st) {
          var a = p.answers[st.step_key] || {}, lab = {};
          st.options.forEach(function (o) { lab[o.opt_key] = o.label; });
          if (st.type === 'allocate') {
            Object.keys(a.alloc || {}).filter(function (k) { return a.alloc[k] > 0; }).sort(function (x, y) { return a.alloc[y] - a.alloc[x]; })
              .forEach(function (k) { li += '<li><b>$' + (a.alloc[k] * st.value) + '</b> on ' + esc(lab[k]) + '</li>'; });
          } else (a.picks || []).forEach(function (k) { tags += '<span>' + esc(lab[k]) + '</span>'; });
        });
        if (p.staff) tags += '<span class="staff">staff</span>';
        h += '<div class="pr-person"><h4>' + esc(p.name) + '</h4><div class="em">' + esc(p.email) + '</div><ul>' + li + '</ul>' + (tags ? '<div class="pr-tags">' + tags + '</div>' : '') + '</div>';
      });
      h += '</div>';
    }
    h += '<details class="pr-nv"><summary>Not yet voted (' + d.not_voted.length + ' active agents)</summary><ul>' +
      d.not_voted.map(function (n) { return '<li>' + esc(n.name) + ' <span style="opacity:.55">' + esc(n.email) + '</span></li>'; }).join('') + '</ul></details>';
    var open = el.querySelector('.pr-nv') && el.querySelector('.pr-nv').open;
    el.innerHTML = h;
    if (open) el.querySelector('.pr-nv').open = true;
    document.getElementById('pr-copy').onclick = function () {
      var b = this, done = function () { var o = b.textContent; b.textContent = 'Copied ✓'; setTimeout(function () { b.textContent = o; }, 1600); };
      if (navigator.clipboard) navigator.clipboard.writeText(d.text).then(done); 
    };
  }
  function load() {
    fetch('api/admin_polls_action.php?action=results&id=' + ID, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (!d.ok) { el.textContent = d.error || 'Failed to load'; return; } var j = JSON.stringify(d); if (j !== last) { last = j; render(d); } })
      .catch(function () {});
  }
  load(); setInterval(load, 5000);
})();
</script>
</body>
</html>
