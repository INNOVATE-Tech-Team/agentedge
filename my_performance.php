<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';

$agent = require_login();
$firstName = trim(strtok(trim($agent['name'] ?? ''), ' ')) ?: 'there';
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
    :root{
      --mp-lime:#C9F24E; --mp-lime-hover:#D9FF6B; --mp-lime-border:#A8D82F;
      --mp-lime-wash:#F7FBEE; --mp-lime-wash-2:#F0F7E4;
      --mp-panel:#0F110D;
      --mp-page:#F4F4F2; --mp-card-border:#ECECE7; --mp-row-border:#F0F0EC; --mp-thead-border:#EDEDE8;
      --mp-text:#0B0C0A; --mp-text-2:#4A4A44; --mp-muted:#77776F; --mp-faint:#8A8A84; --mp-disabled:#9B9B94; --mp-dash:#CFCFC9;
      --mp-green-text:#63A80A; --mp-green-text-2:#4C7A00; --mp-green-delta:#7A9E3A;
    }
    .mp-wrap{max-width:1240px}
    body{background:var(--mp-page)}

    .mp-hero{display:flex;align-items:flex-end;justify-content:space-between;gap:24px;flex-wrap:wrap;margin-bottom:24px}
    .mp-eyebrow{font-size:11.5px;letter-spacing:.16em;text-transform:uppercase;color:var(--mp-green-text);font-weight:700;margin-bottom:8px}
    .mp-greeting{margin:0;font-size:32px;line-height:1.1;letter-spacing:-.025em;font-weight:700;color:var(--mp-text)}
    .mp-sub{margin:6px 0 0;font-size:14.5px;color:var(--mp-muted)}
    .mp-hero-right{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
    .mp-streak-pill{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid var(--mp-card-border);border-radius:999px;padding:8px 15px}
    .mp-streak-dot{width:8px;height:8px;border-radius:50%;background:var(--mp-dash)}
    .mp-streak-pill.active .mp-streak-dot{background:var(--mp-lime-border)}
    .mp-streak-val{font-size:14px;font-weight:700;color:var(--mp-text)}
    .mp-streak-label{font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:var(--mp-muted);font-weight:600}
    .mp-toggle{display:flex;background:#fff;border:1px solid var(--mp-card-border);border-radius:999px;padding:4px}
    .mp-toggle button{padding:8px 18px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;border:none;border-radius:9px;background:transparent;color:var(--mp-muted)}
    .mp-toggle button.active{background:var(--mp-lime);color:var(--mp-text)}

    .mp-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:20px}
    .mp-kpi{background:#fff;border:1px solid var(--mp-card-border);border-radius:16px;padding:20px 20px;display:flex;flex-direction:column;gap:10px}
    .mp-kpi-head{display:flex;align-items:center;gap:10px}
    .mp-kpi-icon{width:32px;height:32px;border-radius:9px;background:var(--mp-page);display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .mp-kpi-label{font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:var(--mp-muted);font-weight:700}
    .mp-kpi-val{font-size:38px;font-weight:700;letter-spacing:-.02em;line-height:1;color:var(--mp-text);font-variant-numeric:tabular-nums}
    .mp-kpi-val.dash{color:var(--mp-dash)}
    .mp-kpi-note{font-size:12px;color:var(--mp-disabled)}

    .mp-main-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:20px;align-items:start;margin-bottom:20px}
    .mp-next{border-radius:16px;background:var(--mp-panel);color:#fff;padding:28px 30px;display:flex;flex-direction:column;gap:16px}
    .mp-next-head{display:flex;align-items:center;gap:10px}
    .mp-next-badge{width:26px;height:26px;border-radius:50%;background:var(--mp-lime);display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .mp-next-label{font-size:15px;font-weight:700;letter-spacing:-.01em}
    .mp-next-headline{font-size:26px;font-weight:700;letter-spacing:-.02em;line-height:1.15;margin:0}
    .mp-next-body{margin:0;font-size:14.5px;line-height:1.55;color:rgba(255,255,255,.62);max-width:56ch}
    .mp-next-actions{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
    .mp-next-cta-note{font-size:12.5px;color:rgba(255,255,255,.45)}

    .mp-rail{display:flex;flex-direction:column;gap:20px}
    .mp-card{background:#fff;border:1px solid var(--mp-card-border);border-radius:16px;padding:18px 20px}
    .mp-card-head{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:10px}
    .mp-card-title{font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:var(--mp-text);font-weight:700}
    .mp-empty{font-size:13.5px;color:var(--mp-disabled)}

    .mp-goal-row{margin-bottom:14px}
    .mp-goal-row:last-child{margin-bottom:0}
    .mp-goal-head{display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px}
    .mp-goal-metric{font-weight:700;color:var(--mp-text);text-transform:capitalize}
    .mp-goal-nums{color:var(--mp-text-2)}
    .mp-goal-track{height:6px;background:var(--mp-card-border);border-radius:999px;overflow:hidden}
    .mp-goal-fill{height:100%;border-radius:999px;background:var(--mp-lime-border)}
    .mp-goal-fill.behind{background:#e0a030}
    .mp-goal-note{font-size:11.5px;color:var(--mp-faint);margin-top:3px}

    .mp-lb{background:#fff;border:1px solid var(--mp-card-border);border-radius:16px;overflow:hidden}
    .mp-lb-head{display:flex;align-items:center;justify-content:space-between;padding:20px 22px 16px;gap:16px;flex-wrap:wrap}
    .mp-lb-title{font-size:17px;font-weight:700;letter-spacing:-.01em;color:var(--mp-text)}
    .mp-lb-meta{font-size:12.5px;color:var(--mp-faint)}
    .mp-lb-cols{display:grid;grid-template-columns:56px minmax(0,1fr) 90px 90px 90px;padding:9px 22px;background:#FAFAF8;border-top:1px solid var(--mp-thead-border);border-bottom:1px solid var(--mp-thead-border);font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:var(--mp-faint);font-weight:700}
    .mp-lb-cols.no-emails{grid-template-columns:56px minmax(0,1fr) 90px 90px}
    .mp-lb-row{display:grid;grid-template-columns:56px minmax(0,1fr) 90px 90px 90px;align-items:center;padding:13px 22px;border-bottom:1px solid var(--mp-row-border)}
    .mp-lb-row.no-emails{grid-template-columns:56px minmax(0,1fr) 90px 90px}
    .mp-lb-row:last-child{border-bottom:none}
    .mp-lb-row.top{padding:15px 22px}
    .mp-lb-row.me{background:var(--mp-lime-wash);border-left:3px solid var(--mp-lime-border);padding-left:19px}
    .mp-lb-rank{font-size:13.5px;font-weight:600;color:var(--mp-faint);font-variant-numeric:tabular-nums}
    .mp-lb-row.top .mp-lb-rank{width:26px;height:26px;border-radius:50%;background:#EFEFEA;color:var(--mp-text);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700}
    .mp-lb-name{display:flex;align-items:center;gap:9px;font-size:14.5px;font-weight:600;color:var(--mp-text)}
    .mp-lb-row.top .mp-lb-name{font-size:15.5px;font-weight:700}
    .mp-lb-row.me .mp-lb-name{font-weight:700}
    .mp-chip{font-size:9.5px;letter-spacing:.1em;text-transform:uppercase;font-weight:700;border-radius:999px;padding:3px 9px}
    .mp-chip-leader{color:var(--mp-green-text-2);background:var(--mp-lime-wash-2)}
    .mp-chip-you{color:var(--mp-lime);background:var(--mp-text)}
    .mp-lb-metric{text-align:right;font-size:14.5px;color:var(--mp-text-2);font-variant-numeric:tabular-nums}
    .mp-lb-row.top .mp-lb-metric.primary{font-size:17px;font-weight:700;color:var(--mp-text)}
    .mp-lb-row.me .mp-lb-metric{color:var(--mp-text);font-weight:600}
    .mp-lb-foot{padding:12px 22px 16px;font-size:12px;color:var(--mp-disabled)}

    @media(max-width:1100px){
      .mp-main-grid{grid-template-columns:1fr}
    }
    @media(max-width:900px){
      .mp-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}
    }
    @media(max-width:760px){
      .mp-kpis{grid-template-columns:1fr}
      .mp-lb-cols,.mp-lb-row{grid-template-columns:44px minmax(0,1fr) 64px}
      .mp-lb-cols.no-emails,.mp-lb-row.no-emails{grid-template-columns:44px minmax(0,1fr) 64px}
      .mp-lb-col-hide{display:none}
    }
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
    <main class="wrap mp-wrap">
      <div id="mp-root"><div class="mp-card"><div class="mp-empty">Loading…</div></div></div>
    </main>
  </div>
</div>
<script>
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function timeGreeting() {
  const h = new Date().getHours();
  if (h < 12) return 'Good morning';
  if (h < 18) return 'Good afternoon';
  return 'Good evening';
}

const root = document.getElementById('mp-root');
const FIRST_NAME = <?php echo json_encode($firstName); ?>;

const state = { period: 'week', summary: null, goals: null, attention: null, streaks: null, leaderboardByPeriod: {} };

function icon(name) {
  const icons = {
    dials: '<path d="M6.5 3.5h3l1.5 4-2 1.5a12 12 0 0 0 6 6l1.5-2 4 1.5v3a2 2 0 0 1-2.2 2A17 17 0 0 1 4.5 5.7 2 2 0 0 1 6.5 3.5z"></path>',
    connects: '<path d="M20 12a7.5 7.5 0 0 1-7.5 7.5H5L3.8 21V12A7.5 7.5 0 0 1 11.3 4.5h1.2A7.5 7.5 0 0 1 20 12z"></path>',
    emails: '<rect x="3" y="5" width="18" height="14" rx="2.5"></rect><path d="M3.8 6.5 12 13l8.2-6.5"></path>',
    streak: '<path d="M12 3c.6 3.2 3.2 4.4 4.5 6.6A6.8 6.8 0 0 1 12 21a6.8 6.8 0 0 1-4.5-11.4C9 7.2 11.4 6.2 12 3z"></path>',
    arrow: '<path d="M5 12h13"></path><path d="m12.5 6 6 6-6 6"></path>',
    trophy: '<path d="M7 4h10v5a5 5 0 0 1-10 0V4z"></path><path d="M17 5.5h3v1.5a3.5 3.5 0 0 1-3.5 3.5M7 5.5H4V7a3.5 3.5 0 0 0 3.5 3.5"></path><path d="M9.5 20h5M12 14v6"></path>',
  };
  return `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">${icons[name] || ''}</svg>`;
}

function fetchJson(url) { return fetch(url, {credentials:'same-origin'}).then(r => r.json()); }

Promise.all([
  fetchJson('api/performance_summary.php'),
  fetchJson('api/performance_goals.php'),
  fetchJson('api/performance_attention.php'),
  fetchJson('api/performance_streaks.php'),
  fetchJson('api/performance_leaderboard.php?period=week'),
]).then(([summary, goals, attention, streaks, leaderboard]) => {
  const failed = [summary, goals, attention, streaks, leaderboard].find(d => !d.ok);
  if (failed) {
    root.innerHTML = `<div class="mp-card"><div class="mp-empty">Couldn't load performance data right now (${esc(failed.error || 'unknown error')}). Try again shortly.</div></div>`;
    return;
  }
  if (!summary.inPilot) {
    root.innerHTML = `<div class="mp-card"><div class="mp-empty">Performance tracking isn't turned on for you yet. Ask your team leader if you think this should be available.</div></div>`;
    return;
  }
  state.summary = summary;
  state.goals = goals;
  state.attention = attention;
  state.streaks = streaks;
  state.leaderboardByPeriod.week = leaderboard;
  render();
});

function setPeriod(p) {
  if (state.period === p) return;
  state.period = p;
  if (state.leaderboardByPeriod[p]) { render(); return; }
  const lbCard = document.getElementById('mp-lb-body');
  if (lbCard) lbCard.innerHTML = '<div class="mp-empty" style="padding:20px 22px">Loading…</div>';
  fetchJson(`api/performance_leaderboard.php?period=${p}`).then(lb => {
    if (!lb.ok) { if (lbCard) lbCard.innerHTML = `<div class="mp-empty" style="padding:20px 22px">Couldn't load the leaderboard (${esc(lb.error || 'unknown error')}).</div>`; return; }
    state.leaderboardByPeriod[p] = lb;
    render();
  });
}

function computeNextStep(myStreak) {
  const goals = state.goals.goals || [];
  const behind = goals.filter(g => g.behindPace)
    .map(g => ({ g, gap: Math.max(0, Math.round((g.expectedToDate - g.actualToDate) * 10) / 10) }))
    .sort((a, b) => b.gap - a.gap)[0];
  if (behind) {
    const metricLabel = behind.g.metric.replace('_', ' ');
    return {
      headline: `You're ${behind.gap} ${esc(metricLabel)} behind today's goal pace.`,
      body: `Your ${esc(behind.g.period)}ly ${esc(metricLabel)} goal is ${behind.g.actualToDate} of ${behind.g.target} so far — ${behind.g.expectedToDate} expected by now.`,
    };
  }

  const last = state.attention.lastActivityAt;
  const today = new Date().toISOString().slice(0, 10);
  if (!last) {
    return { headline: 'Make your first dial today.', body: "A little activity today keeps your week moving. You have not logged activity today." };
  }
  if (last.slice(0, 10) !== today) {
    return { headline: "You haven't logged activity today.", body: "A little activity today keeps your week moving and your streak alive." };
  }

  if (myStreak && myStreak.currentStreak > 0) {
    return { headline: `Your streak is at ${myStreak.currentStreak} day${myStreak.currentStreak === 1 ? '' : 's'}.`, body: "You've logged activity today — keep it going tomorrow." };
  }
  if (goals.length) {
    return { headline: "Keep going — you're ahead of your weekly goal.", body: "You've logged activity today and you're on pace. Nice work." };
  }
  return { headline: "You're all caught up for today.", body: "Nice work — you've logged activity today." };
}

function render() {
  const { summary, goals, attention, streaks } = state;
  const leaderboard = state.leaderboardByPeriod[state.period];
  const myId = leaderboard.requestingUserId || streaks.requestingUserId;
  const myStreak = (streaks.agents || []).find(a => a.userId === myId);
  const periodData = state.period === 'week' ? summary.week : summary.month;
  const periodLabel = state.period === 'week' ? 'week' : 'month';

  let html = '';

  // --- Hero ---
  html += `<div class="mp-hero">
    <div>
      <div class="mp-eyebrow">My Performance</div>
      <h1 class="mp-greeting">${timeGreeting()}, ${esc(FIRST_NAME)}.</h1>
      <p class="mp-sub">Here's where your activity stands this ${periodLabel}.</p>
    </div>
    <div class="mp-hero-right">
      <div class="mp-streak-pill${myStreak && myStreak.currentStreak > 0 ? ' active' : ''}">
        <span class="mp-streak-dot"></span>
        <span class="mp-streak-val">${myStreak ? myStreak.currentStreak : 0}</span>
        <span class="mp-streak-label">Day Streak</span>
      </div>
      <div class="mp-toggle">
        <button class="${state.period === 'week' ? 'active' : ''}" data-period="week">Weekly</button>
        <button class="${state.period === 'month' ? 'active' : ''}" data-period="month">Monthly</button>
      </div>
    </div>
  </div>`;

  // --- KPI cards ---
  // No prior-period totals are returned by Advantage today, so delta
  // lines ("+1 vs last week") are intentionally omitted rather than
  // fabricated -- see the deployment report's API-gap note.
  const kpis = [
    { key: 'dials', label: 'Dials', icon: 'dials', val: periodData ? periodData.dials : null },
    { key: 'connects', label: 'Connects', icon: 'connects', val: periodData ? periodData.connects : null },
    { key: 'emails', label: 'Emails', icon: 'emails', val: periodData ? periodData.emailsSent : null },
    { key: 'streak', label: 'Day Streak', icon: 'streak', val: myStreak ? myStreak.currentStreak : 0, alwaysNumeric: true },
  ];
  html += '<div class="mp-kpis">';
  kpis.forEach(k => {
    const missing = !k.alwaysNumeric && !periodData;
    html += `<div class="mp-kpi">
      <div class="mp-kpi-head"><div class="mp-kpi-icon">${icon(k.icon)}</div><div class="mp-kpi-label">${esc(k.label)}</div></div>
      <div class="mp-kpi-val${missing ? ' dash' : ''}">${missing ? '—' : k.val}</div>
      ${missing ? `<div class="mp-kpi-note">No activity logged yet this ${periodLabel}</div>` : ''}
    </div>`;
  });
  html += '</div>';

  // --- Next step + right rail ---
  const next = computeNextStep(myStreak);
  html += '<div class="mp-main-grid">';
  html += `<section class="mp-next">
    <div class="mp-next-head">
      <div class="mp-next-badge">${icon('arrow')}</div>
      <span class="mp-next-label">My Next Step</span>
    </div>
    <div>
      <div class="mp-next-headline">${next.headline}</div>
      <p class="mp-next-body">${next.body}</p>
    </div>
    <div class="mp-next-actions">
      <span class="mp-next-cta-note">Logging activity happens in Follow Up Boss for now — an in-app shortcut is planned (see deployment report).</span>
    </div>
  </section>`;

  html += '<div class="mp-rail">';
  html += '<section class="mp-card"><div class="mp-card-head"><span class="mp-card-title">Weekly Goals</span></div>';
  if (!goals.goals || !goals.goals.length) {
    html += '<div class="mp-empty">No goals assigned yet.</div>';
  } else {
    goals.goals.forEach(g => {
      const pct = g.target > 0 ? Math.min(100, Math.round(100 * g.actualToDate / g.target)) : 0;
      html += `<div class="mp-goal-row">
        <div class="mp-goal-head"><span class="mp-goal-metric">${esc(g.metric.replace('_', ' '))} (${esc(g.period)}ly)</span>
          <span class="mp-goal-nums">${g.actualToDate} / ${g.target}</span></div>
        <div class="mp-goal-track"><div class="mp-goal-fill${g.behindPace ? ' behind' : ''}" style="width:${pct}%"></div></div>
        <div class="mp-goal-note">${g.elapsedFractionPct}% of period elapsed — expected ${g.expectedToDate} by now${g.behindPace ? ' (behind pace)' : ''}</div>
      </div>`;
    });
  }
  html += '</section>';
  html += '</div>'; // .mp-rail
  html += '</div>'; // .mp-main-grid

  // --- Leaderboard ---
  html += renderLeaderboardCard(leaderboard, myId, periodLabel);

  root.innerHTML = html;

  root.querySelectorAll('.mp-toggle button').forEach(btn => {
    btn.addEventListener('click', () => setPeriod(btn.dataset.period));
  });
}

function renderLeaderboardCard(leaderboard, myId, periodLabel) {
  const agents = leaderboard.agents || [];
  const showEmails = agents.some(a => (a.emailsSent || 0) > 0);
  const colsClass = showEmails ? '' : ' no-emails';
  let inner;
  if (!agents.length) {
    inner = `<div class="mp-empty" style="padding:20px 22px">No activity logged yet this ${periodLabel}.</div>`;
  } else {
    const maxDials = agents[0].dials;
    inner = agents.map((a, i) => {
      const rank = i + 1;
      const isMe = a.userId === myId;
      const isTop = rank <= 3;
      const cls = ['mp-lb-row', isTop ? 'top' : '', isMe ? 'me' : '', showEmails ? '' : 'no-emails'].filter(Boolean).join(' ');
      // A real recorded 0 (this agent has a row this period, it's just 0)
      // always renders as 0, never a dash -- the dash means "no row at
      // all" (see the KPI cards' periodData===null handling), not "zero".
      // showEmails already decides whether the whole column is worth
      // showing at all when every visible agent is really at 0.
      const emailsCell = showEmails
        ? `<div class="mp-lb-metric mp-lb-col-hide">${a.emailsSent}</div>`
        : '';
      const leaderChip = (rank === 1 && a.dials === maxDials && maxDials > 0) ? ' <span class="mp-chip mp-chip-leader">Leader</span>' : '';
      const youChip = isMe ? ' <span class="mp-chip mp-chip-you">You</span>' : '';
      return `<div class="${cls}">
        <div class="mp-lb-rank">${rank}</div>
        <div class="mp-lb-name">${esc(a.fullName)}${leaderChip}${youChip}</div>
        <div class="mp-lb-metric primary">${a.dials}</div>
        <div class="mp-lb-metric mp-lb-col-hide">${a.connects}</div>
        ${emailsCell}
      </div>`;
    }).join('');
  }
  const colHeaders = showEmails
    ? '<div>Rank</div><div>Agent</div><div style="text-align:right">Dials</div><div style="text-align:right" class="mp-lb-col-hide">Connects</div><div style="text-align:right" class="mp-lb-col-hide">Emails</div>'
    : '<div>Rank</div><div>Agent</div><div style="text-align:right">Dials</div><div style="text-align:right" class="mp-lb-col-hide">Connects</div>';
  return `<section class="mp-lb">
    <div class="mp-lb-head">
      <div style="display:flex;align-items:center;gap:10px">
        <div style="width:28px;height:28px;border-radius:8px;background:var(--mp-page);display:flex;align-items:center;justify-content:center;color:var(--mp-faint)">${icon('trophy')}</div>
        <div class="mp-lb-title">Team Leaderboard</div>
      </div>
      <div class="mp-lb-meta">ranked by dials · this ${periodLabel}</div>
    </div>
    <div class="mp-lb-cols${colsClass}">${colHeaders}</div>
    <div id="mp-lb-body">${inner}</div>
    <div class="mp-lb-foot">Transaction metrics are not shown while the underlying data is being validated.</div>
  </section>`;
}
</script>
</body>
</html>
