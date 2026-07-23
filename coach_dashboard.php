<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';

$agent = require_login();
if (!is_launch_coach() && !is_admin()) { header('Location: index.php'); exit; }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Coach Dashboard — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .roster-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden}
    .roster-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);padding:10px 14px;text-align:left;border-bottom:1px solid var(--border)}
    .roster-table td{padding:9px 14px;border-top:1px solid var(--border);vertical-align:middle}
    .roster-table tr.is-flagged{background:#fff8f6}
    .cohort-badge{font-size:11px;color:var(--faint)}
    .kpi-cell{font-variant-numeric:tabular-nums}
    .kpi-miss{color:#c00;font-weight:700}
    .kpi-hit{color:#5b8e0d;font-weight:700}
    .flag-chip{font-size:10px;font-weight:700;background:#fdeceb;color:#c00;padding:2px 7px;border-radius:10px;margin-left:6px}
    .empty-state{text-align:center;padding:40px;color:var(--faint);font-size:14px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('coach_dashboard', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)">LAUNCH</div>
        <div class="content-title">Coach Dashboard</div>
      </div>
    </header>
    <main class="wrap">
      <div id="roster-wrap"><div class="empty-state">Loading…</div></div>
    </main>
  </div>
</div>
<script>
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

fetch('api/coach_roster_activity.php', {credentials:'same-origin'})
  .then(r => r.json())
  .then(d => {
    const wrap = document.getElementById('roster-wrap');
    if (!d.ok) { wrap.innerHTML = `<div class="empty-state">${esc(d.error||'Failed to load roster')}</div>`; return; }
    if (!d.members.length) { wrap.innerHTML = '<div class="empty-state">No agents assigned to you yet.</div>'; return; }

    // Flagged agents first.
    const members = d.members.slice().sort((a,b) => (b.flagged?1:0) - (a.flagged?1:0));
    const kpiKeys = Object.keys(members[0].this_week);

    let html = `<table class="roster-table"><thead><tr><th>Agent</th><th>Cohort</th>`;
    kpiKeys.forEach(k => html += `<th>${esc(members[0].this_week[k].label)}</th>`);
    html += `</tr></thead><tbody>`;
    members.forEach(m => {
      html += `<tr class="${m.flagged ? 'is-flagged' : ''}"><td>${esc(m.agent_email)}${m.flagged ? '<span class="flag-chip">Needs check-in</span>' : ''}</td>`;
      html += `<td class="cohort-badge">${esc(m.cohort_name)}</td>`;
      kpiKeys.forEach(k => {
        const v = m.this_week[k];
        const cls = v.value >= v.target ? 'kpi-hit' : 'kpi-miss';
        html += `<td class="kpi-cell ${cls}">${v.value}/${v.target}</td>`;
      });
      html += `</tr>`;
    });
    html += '</tbody></table>';
    wrap.innerHTML = html;
  });
</script>
</body>
</html>
