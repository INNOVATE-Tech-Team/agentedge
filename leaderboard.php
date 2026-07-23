<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';

$agent = require_login();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>LAUNCH Leaderboard — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .lb-toolbar{display:flex;gap:12px;align-items:flex-end;margin-bottom:20px;flex-wrap:wrap}
    .field-group{display:flex;flex-direction:column;gap:4px;min-width:200px}
    .field-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint)}
    .field-select{padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;width:100%;box-sizing:border-box;background:#fff}

    .lb-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px}
    @media (max-width: 900px){.lb-grid{grid-template-columns:1fr}}
    .lb-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:20px 24px}
    .lb-card h3{margin:0 0 14px;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}

    .cohort-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-top:1px solid var(--border)}
    .cohort-row:first-child{border-top:none}
    .cohort-rank{font-size:16px;font-weight:800;color:var(--faint);width:28px}
    .cohort-name{font-weight:700;font-size:13px}
    .cohort-stats{font-size:12px;color:var(--faint)}
    .cohort-pct{font-size:15px;font-weight:800;color:#5b8e0d}

    .agent-table{width:100%;border-collapse:collapse;font-size:13px}
    .agent-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);padding:6px 10px;text-align:left;border-bottom:1px solid var(--border)}
    .agent-table td{padding:7px 10px;border-top:1px solid var(--border)}
    .rank-cell{font-weight:800;color:var(--faint);width:24px}
    .kpi-hit{color:#5b8e0d;font-weight:700}
    .kpi-miss{color:#c00}
    .streak-badge{font-size:11px;font-weight:700;background:#fff4e0;color:#a07221;padding:2px 7px;border-radius:10px;white-space:nowrap}

    .wins-list{display:flex;flex-direction:column;gap:8px}
    .win-item{font-size:13px;padding:8px 12px;background:#eef5e8;border-radius:6px;color:#3a6b1a}
    .empty-state{color:var(--faint);font-size:13px;text-align:center;padding:20px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('leaderboard', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)">LAUNCH</div>
        <div class="content-title">Leaderboard</div>
      </div>
    </header>
    <main class="wrap">
      <div class="lb-toolbar">
        <div class="field-group">
          <div class="field-label">KPI</div>
          <select id="lb-kpi" class="field-select" onchange="load()"></select>
        </div>
      </div>

      <div class="lb-grid">
        <div class="lb-card">
          <h3>Cohort vs. Cohort</h3>
          <div id="cohort-board"><div class="empty-state">Loading…</div></div>
        </div>
        <div class="lb-card">
          <h3>Recent Wins</h3>
          <div class="wins-list" id="wins-list"><div class="empty-state">Loading…</div></div>
        </div>
      </div>

      <div class="lb-card">
        <h3>Agent Leaderboard <span id="lb-week-label" style="text-transform:none;font-weight:400;color:var(--faint)"></span></h3>
        <div id="agent-board"><div class="empty-state">Loading…</div></div>
      </div>
    </main>
  </div>
</div>
<script>
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

let KPI_LOADED = false;

function load() {
  const kpiEl = document.getElementById('lb-kpi');
  const kpiKey = kpiEl.value;
  const url = 'api/leaderboard.php' + (kpiKey ? ('?kpi_key=' + encodeURIComponent(kpiKey)) : '');
  fetch(url, {credentials:'same-origin'})
    .then(r => r.json())
    .then(d => {
      if (!d.ok) {
        document.getElementById('agent-board').innerHTML = `<div class="empty-state">${esc(d.error||'Failed to load')}</div>`;
        return;
      }
      if (!KPI_LOADED) {
        kpiEl.innerHTML = d.kpis.map(k => `<option value="${esc(k.kpi_key)}">${esc(k.label)}</option>`).join('');
        kpiEl.value = d.kpi_key;
        KPI_LOADED = true;
      }
      document.getElementById('lb-week-label').textContent = '— week of ' + d.week_start;
      renderCohortBoard(d.cohort_leaderboard);
      renderAgentBoard(d.agent_leaderboard);
      renderWins(d.recent_milestones);
    });
}

function renderCohortBoard(cohorts) {
  const el = document.getElementById('cohort-board');
  if (!cohorts.length) { el.innerHTML = '<div class="empty-state">No active LAUNCH cohorts yet.</div>'; return; }
  el.innerHTML = cohorts.map((c, i) => `
    <div class="cohort-row">
      <div style="display:flex;align-items:center;gap:10px">
        <div class="cohort-rank">${i+1}</div>
        <div>
          <div class="cohort-name">${esc(c.cohort_name)}</div>
          <div class="cohort-stats">${c.count} agent${c.count===1?'':'s'} &middot; avg ${c.avg_value}</div>
        </div>
      </div>
      <div class="cohort-pct">${c.pct_hit_target}%</div>
    </div>`).join('');
}

function renderAgentBoard(agents) {
  const el = document.getElementById('agent-board');
  if (!agents.length) { el.innerHTML = '<div class="empty-state">No active LAUNCH agents yet.</div>'; return; }
  let html = '<table class="agent-table"><thead><tr><th></th><th>Agent</th><th>Cohort</th><th>This Week</th><th>Streak</th></tr></thead><tbody>';
  agents.forEach((a, i) => {
    const cls = a.value >= a.target ? 'kpi-hit' : 'kpi-miss';
    html += `<tr><td class="rank-cell">${i+1}</td><td>${esc(a.agent_email)}</td><td style="color:var(--faint);font-size:12px">${esc(a.cohort_name)}</td>`;
    html += `<td class="${cls}">${a.value}/${a.target}</td>`;
    html += `<td>${a.streak > 0 ? `<span class="streak-badge">${a.streak}-week streak</span>` : ''}</td></tr>`;
  });
  html += '</tbody></table>';
  el.innerHTML = html;
}

function renderWins(milestones) {
  const el = document.getElementById('wins-list');
  if (!milestones.length) { el.innerHTML = '<div class="empty-state">No wins logged yet.</div>'; return; }
  el.innerHTML = milestones.map(m => `<div class="win-item">${esc(m.agent_email)} — ${esc(m.label)}${m.cohort_name ? ' (' + esc(m.cohort_name) + ')' : ''}</div>`).join('');
}

load();
</script>
</body>
</html>
