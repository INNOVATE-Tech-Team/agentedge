<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';

$agent = require_login();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>My Performance — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .lc-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:20px 24px;margin-bottom:20px}
    .lc-card h3{margin:0 0 14px;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
    .empty-state{color:var(--faint);font-size:13px}

    /* Next-steps nudge banner */
    .nudge-list{display:flex;flex-direction:column;gap:8px;margin-bottom:20px}
    .nudge{padding:12px 16px;border-radius:8px;font-size:13.5px;display:flex;align-items:center;gap:10px}
    .nudge-ok{background:#eef5e8;border:1px solid #c3dfa8;color:#3a6b1a}
    .nudge-warn{background:#fff8e6;border:1px solid #f0d792;color:#7a5c00}
    .nudge-icon{font-size:15px;flex-shrink:0}

    /* Stat tiles */
    .stat-row{display:flex;gap:16px;flex-wrap:wrap}
    .stat-tile{flex:1;min-width:120px;background:#fafbfa;border:1px solid var(--border);border-radius:8px;padding:14px 16px}
    .stat-num{font-size:26px;font-weight:800;color:var(--ink)}
    .stat-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);margin-top:2px}
    .streak-badge{display:inline-flex;align-items:center;gap:6px;background:#eef5e8;color:#3a6b1a;border-radius:999px;padding:4px 12px;font-size:12.5px;font-weight:700}

    /* Goal pacing */
    .goal-row{margin-bottom:14px}
    .goal-row:last-child{margin-bottom:0}
    .goal-head{display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px}
    .goal-metric{font-weight:700;color:var(--ink);text-transform:capitalize}
    .goal-nums{color:var(--muted)}
    .goal-track{height:7px;background:var(--border);border-radius:999px;overflow:hidden}
    .goal-fill{height:100%;border-radius:999px;background:var(--green)}
    .goal-fill.behind{background:#e0a030}
    .goal-note{font-size:11.5px;color:var(--faint);margin-top:3px}

    /* Leaderboard table */
    .lb-table{width:100%;border-collapse:collapse;font-size:13px}
    .lb-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);padding:6px 10px;text-align:left;border-bottom:1px solid var(--border)}
    .lb-table td{padding:7px 10px;border-top:1px solid var(--border)}
    .lb-table tr.me{background:#eef5e8}
    .lb-rank{color:var(--faint);width:24px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('my_performance', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)">Activity Pilot</div>
        <div class="content-title">My Performance</div>
      </div>
    </header>
    <main class="wrap">
      <div id="mp-root"><div class="lc-card"><div class="empty-state">Loading…</div></div></div>
    </main>
  </div>
</div>
<script>
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

const root = document.getElementById('mp-root');

Promise.all([
  fetch('api/performance_summary.php',   {credentials:'same-origin'}).then(r => r.json()),
  fetch('api/performance_goals.php',     {credentials:'same-origin'}).then(r => r.json()),
  fetch('api/performance_attention.php', {credentials:'same-origin'}).then(r => r.json()),
  fetch('api/performance_streaks.php',   {credentials:'same-origin'}).then(r => r.json()),
  fetch('api/performance_leaderboard.php',{credentials:'same-origin'}).then(r => r.json()),
]).then(([summary, goals, attention, streaks, leaderboard]) => {
  // If any call failed outright (not just "not in pilot"), show that first.
  const failed = [summary, goals, attention, streaks, leaderboard].find(d => !d.ok);
  if (failed) {
    root.innerHTML = `<div class="lc-card"><div class="empty-state">Couldn't load performance data right now (${esc(failed.error || 'unknown error')}). Try again shortly.</div></div>`;
    return;
  }
  // "Not part of the pilot" is not an error -- performance tracking
  // simply doesn't exist for this agent yet. No zeroed-out UI.
  if (!summary.inPilot) {
    root.innerHTML = `<div class="lc-card"><div class="empty-state">Performance tracking isn't turned on for you yet. Ask your team leader if you think this should be available.</div></div>`;
    return;
  }
  render(summary, goals, attention, streaks, leaderboard);
});

function render(summary, goals, attention, streaks, leaderboard) {
  const myId = leaderboard.requestingUserId || streaks.requestingUserId;
  const myStreak = (streaks.agents || []).find(a => a.userId === myId);
  const week = summary.week;

  // --- Next-steps nudges (V1, activity-based only -- no AI recommendations) ---
  const nudges = [];
  if (attention.lastActivityAt) {
    const lastDate = attention.lastActivityAt.slice(0, 10);
    const today = new Date().toISOString().slice(0, 10);
    if (lastDate !== today) {
      nudges.push({type: 'warn', text: 'You have not logged activity today.'});
    }
  } else {
    nudges.push({type: 'warn', text: 'No activity has been logged for you yet.'});
  }
  (goals.goals || []).forEach(g => {
    if (g.behindPace) {
      const remaining = Math.max(0, Math.round((g.expectedToDate - g.actualToDate) * 10) / 10);
      nudges.push({type: 'warn', text: `You are behind pace on your ${esc(g.period)}ly ${esc(g.metric)} goal — about ${remaining} ${esc(g.metric)} behind today's expected pace.`});
    }
  });
  if (myStreak && myStreak.currentStreak > 0) {
    nudges.push({type: 'ok', text: `Your current streak is ${myStreak.currentStreak} day${myStreak.currentStreak === 1 ? '' : 's'}.`});
  }
  if (!nudges.length) {
    nudges.push({type: 'ok', text: "You're on pace and logged activity today. Keep it up."});
  }

  let html = '<div class="nudge-list">' + nudges.map(n =>
    `<div class="nudge nudge-${n.type === 'ok' ? 'ok' : 'warn'}"><span class="nudge-icon">${n.type === 'ok' ? '✓' : '→'}</span>${n.text}</div>`
  ).join('') + '</div>';

  // --- This week's totals ---
  html += '<div class="lc-card"><h3>This Week</h3>';
  if (!week) {
    html += '<div class="empty-state">No activity logged yet this week.</div>';
  } else {
    html += `<div class="stat-row">
      <div class="stat-tile"><div class="stat-num">${week.dials}</div><div class="stat-label">Dials</div></div>
      <div class="stat-tile"><div class="stat-num">${week.connects}</div><div class="stat-label">Connects</div></div>
      <div class="stat-tile"><div class="stat-num">${myStreak ? myStreak.currentStreak : 0}</div><div class="stat-label">Day Streak</div></div>
    </div>`;
  }
  html += '</div>';

  // --- Goal pacing ---
  html += '<div class="lc-card"><h3>Goal Pacing</h3>';
  if (!goals.goals || !goals.goals.length) {
    html += '<div class="empty-state">No activity goals set for you yet.</div>';
  } else {
    goals.goals.forEach(g => {
      const pct = g.target > 0 ? Math.min(100, Math.round(100 * g.actualToDate / g.target)) : 0;
      html += `<div class="goal-row">
        <div class="goal-head"><span class="goal-metric">${esc(g.metric.replace('_', ' '))} (${esc(g.period)}ly)</span>
          <span class="goal-nums">${g.actualToDate} / ${g.target}</span></div>
        <div class="goal-track"><div class="goal-fill${g.behindPace ? ' behind' : ''}" style="width:${pct}%"></div></div>
        <div class="goal-note">${g.elapsedFractionPct}% of period elapsed — expected ${g.expectedToDate} by now${g.behindPace ? ' (behind pace)' : ''}</div>
      </div>`;
    });
  }
  html += '</div>';

  // --- Team leaderboard (activity only) ---
  html += '<div class="lc-card"><h3>Team Leaderboard — This Week</h3>';
  if (!leaderboard.agents || !leaderboard.agents.length) {
    html += '<div class="empty-state">No activity logged yet this week.</div>';
  } else {
    html += '<table class="lb-table"><thead><tr><th></th><th>Agent</th><th>Dials</th><th>Connects</th><th>Texts</th><th>Emails</th></tr></thead><tbody>';
    leaderboard.agents.forEach((a, i) => {
      const isMe = a.userId === myId;
      html += `<tr class="${isMe ? 'me' : ''}"><td class="lb-rank">${i + 1}</td><td>${esc(a.fullName)}${isMe ? ' (you)' : ''}</td>` +
              `<td>${a.dials}</td><td>${a.connects}</td><td>${a.textsSent}</td><td>${a.emailsSent}</td></tr>`;
    });
    html += '</tbody></table>';
  }
  html += '</div>';

  root.innerHTML = html;
}
</script>
</body>
</html>
