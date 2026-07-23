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
  <title>My Weekly Activity — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .lc-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:20px 24px;margin-bottom:20px}
    .lc-card h3{margin:0 0 14px;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
    .kpi-row{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px}
    .field-group{display:flex;flex-direction:column;gap:4px;min-width:140px}
    .field-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint)}
    .field-input{padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:14px;width:100%;box-sizing:border-box}
    .target-hint{font-size:11px;color:var(--faint);margin-top:2px}
    .btn-add{padding:9px 20px;background:var(--green);color:#111;font-weight:800;font-size:13px;border:0;border-radius:6px;cursor:pointer}
    .btn-add:hover{background:var(--green-d,#5b8e0d);color:#fff}
    .milestone-list{display:flex;flex-direction:column;gap:8px}
    .milestone-item{font-size:13px;padding:8px 12px;background:#eef5e8;border-radius:6px;color:#3a6b1a}
    .history-table{width:100%;border-collapse:collapse;font-size:12px}
    .history-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);padding:6px 10px;text-align:left;border-bottom:1px solid var(--border)}
    .history-table td{padding:6px 10px;border-top:1px solid var(--border)}
    .flash-ok{padding:10px 14px;background:#eef5e8;border:1px solid #c3dfa8;border-radius:6px;color:#3a6b1a;font-size:13px;margin-bottom:16px}
    .flash-err{padding:10px 14px;background:#fff0f0;border:1px solid #f5c6c6;border-radius:6px;color:#c00;font-size:13px;margin-bottom:16px}
    .empty-state{color:var(--faint);font-size:13px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('my_activity', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)">LAUNCH</div>
        <div class="content-title">My Weekly Activity</div>
      </div>
    </header>
    <main class="wrap">
      <div id="flash-area"></div>
      <div id="form-wrap"><div class="empty-state">Loading…</div></div>

      <div class="lc-card">
        <h3>Milestones</h3>
        <div class="milestone-list" id="milestone-list"><div class="empty-state">Loading…</div></div>
      </div>

      <div class="lc-card">
        <h3>History</h3>
        <div id="history-wrap"><div class="empty-state">Loading…</div></div>
      </div>
    </main>
  </div>
</div>
<script>
function flash(msg, type='ok') {
  const el = document.getElementById('flash-area');
  el.innerHTML = `<div class="flash-${type}">${msg}</div>`;
  setTimeout(() => el.innerHTML='', 4000);
}
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

let currentData = null;

function load() {
  fetch('api/weekly_activity.php', {credentials:'same-origin'})
    .then(r => r.json())
    .then(d => {
      currentData = d;
      renderForm(d);
      renderMilestones(d);
      renderHistory(d);
    });
}

function renderForm(d) {
  const wrap = document.getElementById('form-wrap');
  if (!d.ok) { wrap.innerHTML = `<div class="flash-err">${esc(d.error||'Failed to load')}</div>`; return; }
  if (!d.in_cohort) {
    wrap.innerHTML = '<div class="lc-card"><div class="empty-state">You\'re not currently an active member of a LAUNCH cohort, so there\'s nothing to self-report yet.</div></div>';
    return;
  }
  const thisWeek = {};
  d.history.filter(h => h.week_start === d.week_start).forEach(h => thisWeek[h.kpi_key] = h.value);

  let html = `<div class="lc-card"><h3>This Week (starting ${esc(d.week_start)})</h3><div class="kpi-row">`;
  d.kpis.forEach(k => {
    const val = thisWeek[k.kpi_key] ?? '';
    html += `<div class="field-group">
      <div class="field-label">${esc(k.label)}</div>
      <input type="number" min="0" class="field-input kpi-input" data-kpi="${esc(k.kpi_key)}" value="${val}">
      <div class="target-hint">Target: ${k.weekly_target}</div>
    </div>`;
  });
  html += `</div><button class="btn-add" onclick="submitWeek()">Save This Week</button></div>`;
  wrap.innerHTML = html;
}

function submitWeek() {
  const values = {};
  document.querySelectorAll('.kpi-input').forEach(el => { values[el.dataset.kpi] = parseInt(el.value) || 0; });
  fetch('api/weekly_activity.php', {
    method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({week_start: currentData.week_start, values})
  }).then(r => r.json()).then(d => {
    if (!d.ok) { flash(d.error||'Save failed','err'); return; }
    flash('Saved this week\'s numbers.');
    load();
  });
}

function renderMilestones(d) {
  const el = document.getElementById('milestone-list');
  if (!d.ok || !d.milestones.length) { el.innerHTML = '<div class="empty-state">No milestones yet.</div>'; return; }
  el.innerHTML = d.milestones.map(m => `<div class="milestone-item">${esc(m.label)} — ${esc(m.achieved_at)}</div>`).join('');
}

function renderHistory(d) {
  const el = document.getElementById('history-wrap');
  if (!d.ok || !d.history.length) { el.innerHTML = '<div class="empty-state">No activity logged yet.</div>'; return; }
  const weeks = [...new Set(d.history.map(h => h.week_start))].sort().reverse();
  let html = '<table class="history-table"><thead><tr><th>Week</th>';
  d.kpis.forEach(k => html += `<th>${esc(k.label)}</th>`);
  html += '</tr></thead><tbody>';
  weeks.forEach(w => {
    html += `<tr><td>${esc(w)}</td>`;
    d.kpis.forEach(k => {
      const row = d.history.find(h => h.week_start === w && h.kpi_key === k.kpi_key);
      html += `<td>${row ? row.value : '—'}</td>`;
    });
    html += '</tr>';
  });
  html += '</tbody></table>';
  el.innerHTML = html;
}

load();
</script>
</body>
</html>
