<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';

// Super Admin or Launch Coach. A Launch Coach only ever sees agents assigned
// to them (agent_admin.coached_by) below -- never the full brokerage roster,
// never an "All Agents" view. Enforced again server-side in
// api/coach_production_summary.php, which the JS on this page calls --
// this page's own scoping is for correct rendering, not the security
// boundary itself.
$agent = require_login();
$isSuperAdmin = is_super_admin();
if (!$isSuperAdmin && !is_launch_coach()) { header('Location: index.php'); exit; }
$coachScoped = !$isSuperAdmin;
$viewerEmail = strtolower(trim($agent['email'] ?? ''));

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES); }

// Real, existing roster data — no coaching schema involved. Powers both the
// "Agent" filter dropdown and the Agent List below from one query.
$roster = $coachScoped
    ? coach_assigned_agents(local_db(), $viewerEmail)
    : local_db()->query(
        "SELECT agent_name, email, phone, market_center, state_code, license_exp
         FROM innovate_roster WHERE active=1 ORDER BY agent_name"
    )->fetchAll(PDO::FETCH_ASSOC);
$coachHasNoAgents = $coachScoped && !$roster;

// Period: ltm | ytd | year | custom. Old bookmarked links may still carry a
// bare 4-digit year (e.g. "period=2025") -- api/coach_production_summary.php
// already accepts that as a compatibility shim, and we honor it here too so
// an old link still lands on the right control, but every link THIS page
// generates from here on uses period=year&year=YYYY instead (see
// coachSetYear() below) -- the old literal-year form is read-only compat,
// never written.
$periodRaw = trim((string)($_GET['period'] ?? 'ltm'));
$yearRaw   = $_GET['year'] ?? '';
if (preg_match('/^\d{4}$/', $periodRaw)) {
    $period = 'year';
    $yearRaw = $periodRaw;
} elseif (in_array($periodRaw, ['ltm', 'ytd', 'year', 'custom'], true)) {
    $period = $periodRaw;
} else {
    $period = 'ltm';
}
$selectedYear = preg_match('/^\d{4}$/', (string)$yearRaw) ? (int)$yearRaw : (int)date('Y');
$customFrom = $_GET['from'] ?? '';
$customTo   = $_GET['to']   ?? '';
$selectedAgent = strtolower(trim($_GET['agent'] ?? ''));
if ($coachScoped) {
    // Never "All Agents" for a coach, and never someone else's agent even if
    // ?agent= is edited by hand -- fall back to (one of) their own assigned
    // agents. With exactly one assigned agent this also doubles as the
    // "auto-select the one agent" behavior.
    $assignedEmails = array_map(fn($r) => strtolower(trim($r['email'])), $roster);
    if (!in_array($selectedAgent, $assignedEmails, true)) {
        $selectedAgent = $assignedEmails[0] ?? '';
    }
}
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
    .bo-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
    .content-sub{font-size:13px;color:var(--faint);margin-top:2px}

    /* Filter / reporting controls */
    .coach-filters{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:18px}
    .coach-filter-field{display:flex;flex-direction:column;gap:4px}
    .coach-filter-field label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
    .coach-agent-select{padding:8px 30px 8px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;background:#fff;min-width:220px}
    .coach-period{display:flex;gap:0;border:1px solid var(--border);border-radius:20px;overflow:hidden}
    .coach-period-btn{padding:7px 16px;border:none;background:#fff;font-size:12px;font-weight:700;color:var(--muted);cursor:pointer;border-right:1px solid var(--border)}
    .coach-period-btn:last-child{border-right:none}
    .coach-period-btn:hover{background:var(--bg)}
    .coach-period-btn.active{background:var(--green);color:#111}
    .coach-custom-range{display:flex;align-items:center;gap:8px}
    .coach-custom-range input{padding:6px 9px;border:1px solid var(--border);border-radius:6px;font-size:12px}
    .coach-custom-apply{padding:6px 14px;border:0;border-radius:6px;background:var(--ink);color:var(--green);font-weight:700;font-size:12px;cursor:pointer}

    /* Performance summary */
    .coach-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;margin-bottom:18px}
    .coach-summary-card h3{margin:0 0 12px;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--faint)}
    .coach-stat-row{display:grid;grid-template-columns:1fr 1fr;gap:10px 14px}
    .coach-stat{display:flex;flex-direction:column;gap:2px}
    .coach-stat-val{font-size:20px;font-weight:800;color:var(--ink)}
    .coach-stat-val.empty{color:var(--faint);font-weight:600}
    .coach-stat-lbl{font-size:11px;color:var(--faint);font-weight:600}
    .coach-stat-sub{font-size:10px;color:var(--faint);margin-top:1px}

    /* Chart */
    .coach-chart-card{margin-bottom:18px}
    .coach-chart-head{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:6px}
    .coach-chart-head h2{margin:0;font-size:15px}
    .coach-chart-legend{display:flex;gap:14px;font-size:11px;color:var(--faint)}
    .coach-chart-legend span::before{content:'';display:inline-block;width:9px;height:9px;border-radius:2px;margin-right:5px;vertical-align:middle}
    .coach-chart-legend .leg-current::before{background:var(--green)}
    .coach-chart-legend .leg-prior::before{background:var(--border)}
    .coach-chart-shell{min-height:220px;display:flex;align-items:center;justify-content:center;text-align:center;color:var(--faint);font-size:13px;background:var(--bg);border-radius:10px;padding:20px}

    /* Agent list */
    .coach-agents-head{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:14px}
    .coach-agents-head h2{margin:0;font-size:15px}
    .coach-agents-toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
    .coach-agent-count{font-size:12px;color:var(--faint);font-weight:600}
    .coach-agent-row{display:flex;align-items:center;gap:16px;background:#fff;border:1px solid var(--border);border-radius:10px;padding:12px 16px;margin-bottom:8px;cursor:pointer;transition:border-color .15s}
    .coach-agent-row:hover{border-color:#c3dfa8;background:#fafff5}
    .coach-row-identity{display:flex;align-items:center;gap:10px;min-width:200px;flex:1.2}
    .coach-row-avatar-fallback{width:36px;height:36px;border-radius:50%;background:#e8f5d0;color:#5b8e0d;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .coach-row-name{font-size:13px;font-weight:700;color:#111}
    .coach-row-meta{font-size:11px;color:var(--faint)}
    .coach-row-stats{display:flex;gap:20px;flex:1.4;justify-content:center}
    .coach-row-stat{text-align:center;min-width:56px}
    .coach-row-stat-val{font-size:13px;font-weight:700;color:var(--faint)}
    .coach-row-stat-lbl{font-size:9px;color:var(--faint);text-transform:uppercase;letter-spacing:.04em;margin-top:1px}
    .coach-row-contract{flex:1.4;display:flex;flex-direction:column;gap:3px;min-width:150px}
    .coach-contract-stage{font-size:11px;font-weight:700;color:var(--faint)}
    .coach-progress-track{height:5px;background:var(--border);border-radius:999px;overflow:hidden}
    .coach-progress-fill{height:100%;background:var(--green);border-radius:999px}
    .coach-contract-meta{font-size:10px;color:var(--faint)}
    .coach-row-contact{display:flex;gap:6px;flex-shrink:0}
    .coach-pill-btn{padding:5px 12px;border:1px solid var(--green-d);border-radius:20px;background:#fff;color:var(--green-d);font-size:11px;font-weight:800;text-decoration:none;white-space:nowrap}
    .coach-pill-btn:hover{background:var(--green);color:#111}
    .coach-pill-btn.disabled{border-color:var(--border);color:var(--faint);pointer-events:none}
    .empty-state{text-align:center;padding:40px;color:var(--faint);font-size:14px}

    /* Production wiring (Step 6) */
    .coach-status-banner{display:flex;align-items:center;gap:8px;padding:9px 14px;border-radius:8px;margin-bottom:14px;font-size:13px}
    .coach-status-banner.warn{background:#fdf6e8;color:var(--amber)}
    .coach-status-banner.err{background:#fdecea;color:var(--red)}
    .coach-status-banner.info{background:var(--bg);color:var(--muted)}
    .coach-summary-grid.coach-loading .coach-stat-val,
    .coach-summary-grid.coach-loading .coach-stat-sub{opacity:.35}
    .coach-stat-val.coach-unavailable{color:var(--faint);font-weight:600}
    .coach-stat-val.coach-disabled{color:var(--faint);font-weight:600;font-style:italic;font-size:13px}
    .coach-mtd-delta{display:block;font-size:10px;margin-top:1px;font-weight:700}
    .coach-trend-up{color:var(--green-d)}
    .coach-trend-down{color:var(--red)}
    .coach-trend-neutral{color:var(--faint)}
    .coach-active-note{font-size:10px;color:var(--faint);cursor:help;border-bottom:1px dotted var(--faint)}
    .coach-chart-canvas-wrap{min-height:220px}
    .coach-row-stat-val.coach-trend-up::before{content:'▲ '}
    .coach-row-stat-val.coach-trend-down::before{content:'▼ '}

    @media(max-width:900px){
      .coach-agent-row{flex-wrap:wrap}
      .coach-row-stats{justify-content:flex-start;order:3;flex-basis:100%}
      .coach-row-contract{order:4;flex-basis:100%}
      .coach-row-contact{order:2;margin-left:auto}
    }
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('coach_dashboard', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div class="bo-eyebrow">Coaching</div>
        <div class="content-title">Coach Dashboard</div>
        <div class="content-sub">Monitor coaching performance, production, activity and assigned agents.</div>
      </div>
    </header>
    <main class="wrap" style="max-width:1360px">

      <!-- A. Filter / reporting controls -->
      <div class="coach-filters">
        <div class="coach-filter-field">
          <label>Agent</label>
          <select class="coach-agent-select" id="coach-agent-select" onchange="coachFilterAgent(this.value)"<?= $coachHasNoAgents ? ' disabled' : '' ?>>
            <?php if (!$coachScoped): ?>
              <option value="">All Agents</option>
            <?php elseif ($coachHasNoAgents): ?>
              <option value="">No agents assigned</option>
            <?php endif; ?>
            <?php foreach ($roster as $r): $em = strtolower(trim($r['email'])); ?>
              <option value="<?= h($em) ?>"<?= $em === $selectedAgent ? ' selected' : '' ?>><?= h($r['agent_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="coach-filter-field">
          <label>Period</label>
          <div class="coach-period">
            <?php foreach (['ltm' => 'LTM', 'ytd' => 'YTD', 'year' => 'Year', 'custom' => 'Custom'] as $pk => $pl): ?>
              <button type="button" class="coach-period-btn<?= $period === $pk ? ' active' : '' ?>" onclick="coachSetPeriod('<?= $pk ?>')"><?= h($pl) ?></button>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="coach-filter-field" id="coach-year-wrap" style="<?= $period === 'year' ? '' : 'display:none' ?>">
          <label>Year</label>
          <select class="coach-agent-select" id="coach-year-select" style="min-width:100px" onchange="coachSetYear(this.value)">
            <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 5; $y--): ?>
              <option value="<?= $y ?>"<?= $y === $selectedYear ? ' selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="coach-filter-field" id="coach-custom-range-wrap" style="<?= $period === 'custom' ? '' : 'display:none' ?>">
          <label>Date range</label>
          <div class="coach-custom-range">
            <input type="date" id="coach-custom-from" value="<?= h($customFrom) ?>">
            <span style="color:var(--faint);font-size:12px">to</span>
            <input type="date" id="coach-custom-to" value="<?= h($customTo) ?>">
            <button type="button" class="coach-custom-apply" onclick="coachApplyCustomRange()">Apply</button>
          </div>
        </div>
      </div>

      <div id="coach-status-banner" class="coach-status-banner" hidden></div>

      <?php if ($coachHasNoAgents): ?>
      <div class="card"><div class="empty-state">No agents are currently assigned to you.</div></div>
      <?php else: ?>

      <!-- B. Performance summary -->
      <div class="coach-summary-grid" id="coach-summary-grid">
        <div class="card coach-summary-card">
          <h3>Pending</h3>
          <div class="coach-stat-row">
            <div class="coach-stat"><span class="coach-stat-val coach-disabled">Not connected yet</span><span class="coach-stat-lbl">Transactions</span></div>
            <div class="coach-stat"><span class="coach-stat-val coach-disabled">Not connected yet</span><span class="coach-stat-lbl">Total Volume</span></div>
            <div class="coach-stat"><span class="coach-stat-val coach-disabled">—</span><span class="coach-stat-lbl">Avg Sale Price</span></div>
            <div class="coach-stat"><span class="coach-stat-val coach-disabled">—</span><span class="coach-stat-lbl">GCI</span></div>
          </div>
        </div>
        <div class="card coach-summary-card">
          <h3>Closed</h3>
          <div class="coach-stat-row">
            <div class="coach-stat"><span class="coach-stat-val" id="coach-stat-transactions">—</span><span class="coach-stat-lbl">Transactions</span><span class="coach-stat-sub" id="coach-stat-transactions-mtd">MTD —</span></div>
            <div class="coach-stat"><span class="coach-stat-val" id="coach-stat-volume">—</span><span class="coach-stat-lbl">Total Volume</span><span class="coach-stat-sub" id="coach-stat-volume-mtd">MTD —</span></div>
            <div class="coach-stat"><span class="coach-stat-val" id="coach-stat-avgprice">—</span><span class="coach-stat-lbl">Avg Sale Price</span><span class="coach-stat-sub">&nbsp;</span></div>
            <div class="coach-stat"><span class="coach-stat-val coach-disabled">Not connected</span><span class="coach-stat-lbl">GCI</span><span class="coach-stat-sub">&nbsp;</span></div>
          </div>
        </div>
        <div class="card coach-summary-card">
          <h3>Active Listings</h3>
          <div class="coach-stat-row">
            <div class="coach-stat"><span class="coach-stat-val" id="coach-stat-active-volume">—</span><span class="coach-stat-lbl">Total Volume</span></div>
            <div class="coach-stat"><span class="coach-stat-val" id="coach-stat-active-count">—</span><span class="coach-stat-lbl" id="coach-active-listings-lbl">Listings</span></div>
          </div>
        </div>
      </div>

      <!-- C. Performance chart -->
      <div class="card coach-chart-card">
        <div class="coach-chart-head">
          <h2>Closed Volume by Month</h2>
          <div class="coach-chart-legend"><span class="leg-current">Current 12 months</span><span class="leg-prior">Prior 12 months</span></div>
        </div>
        <!-- Chart buckets are whole calendar months (see coachRenderChart()) while
             the Closed card's LTM figure is Coastline's rolling/calendar-date
             aggregate -- sum(current_12mo) will NOT equal the LTM volume above.
             Expected, not a bug: see lib/coach_production.php's chart header comment. -->
        <div class="coach-chart-canvas-wrap"><canvas id="coach-chart-canvas" height="80"></canvas></div>
      </div>

      <!-- D. Agent list -->
      <div class="card">
        <div class="coach-agents-head">
          <h2>Agents</h2>
          <div class="coach-agents-toolbar">
            <input type="text" class="search" id="coach-agent-search" placeholder="Search agents…" oninput="coachFilterRows()">
            <select class="coach-agent-select" id="coach-agent-sort" onchange="coachSortRows()">
              <option value="name">Sort: Name (A–Z)</option>
              <option value="market">Sort: Market Center</option>
            </select>
            <span class="coach-agent-count" id="coach-agent-count"><?= count($roster) ?> agents</span>
          </div>
        </div>
        <div id="coach-agent-list">
          <?php if (!$roster): ?>
            <div class="empty-state">No active agents on the roster yet.</div>
          <?php else: foreach ($roster as $r):
            $name   = $r['agent_name'] ?: $r['email'];
            $market = trim($r['market_center'] ?: '');
            $state  = trim($r['state_code'] ?: '');
            $marketLbl = $market !== '' ? ($market . ($state !== '' ? " ($state)" : '')) : '—';
            $initials = '';
            foreach (preg_split('/\s+/', trim($name ?: '?')) as $part) { if ($part !== '') $initials .= mb_strtoupper(mb_substr($part, 0, 1)); }
            $initials = mb_substr($initials ?: '?', 0, 2);
            $email = strtolower(trim($r['email']));
            $phone = preg_replace('/[^0-9+]/', '', $r['phone'] ?? '');
          ?>
          <div class="coach-agent-row" data-name="<?= h(mb_strtolower($name)) ?>" data-market="<?= h(mb_strtolower($market)) ?>" data-email="<?= h($email) ?>"
               onclick="location.href='coach_agent_detail.php?email=<?= urlencode($email) ?>'">
            <div class="coach-row-identity">
              <div class="coach-row-avatar-fallback"><?= h($initials) ?></div>
              <div>
                <div class="coach-row-name"><?= h($name) ?></div>
                <div class="coach-row-meta"><?= h($marketLbl) ?> &middot; Coach: <span style="font-style:italic">—</span></div>
              </div>
            </div>
            <div class="coach-row-stats">
              <div class="coach-row-stat"><div class="coach-row-stat-val" data-stat="ltm_volume">—</div><div class="coach-row-stat-lbl">LTM Volume</div></div>
              <div class="coach-row-stat"><div class="coach-row-stat-val" data-stat="trend_pct">—</div><div class="coach-row-stat-lbl">Trend</div></div>
              <div class="coach-row-stat"><div class="coach-row-stat-val" data-stat="ltm_sides">—</div><div class="coach-row-stat-lbl">Sides</div></div>
            </div>
            <div class="coach-row-contract">
              <div class="coach-contract-stage">No coaching contract on file</div>
              <div class="coach-progress-track"><div class="coach-progress-fill" style="width:0%"></div></div>
              <div class="coach-contract-meta">Last coaching touch: —</div>
            </div>
            <div class="coach-row-contact" onclick="event.stopPropagation()">
              <?php if ($phone !== ''): ?><a class="coach-pill-btn" href="tel:<?= h($phone) ?>">Call</a><?php else: ?><span class="coach-pill-btn disabled">Call</span><?php endif; ?>
              <?php if ($email !== ''): ?><a class="coach-pill-btn" href="mailto:<?= h($email) ?>">Email</a><?php else: ?><span class="coach-pill-btn disabled">Email</span><?php endif; ?>
            </div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <?php endif; ?>

    </main>
  </div>
</div>
<script src="assets/vendor/chartjs/chart.umd.min.js"></script>
<script>
// ── State + URL handling ─────────────────────────────────────────────────────
// Mirrors the PHP-computed initial state so the very first render (before any
// fetch completes) and every subsequent client-side period/agent change stay
// in sync with what's in the address bar.
const COACH = {
  agent:  <?= json_encode($selectedAgent) ?>,
  period: <?= json_encode($period) ?>,
  year:   <?= json_encode((string)$selectedYear) ?>,
  from:   <?= json_encode($customFrom) ?>,
  to:     <?= json_encode($customTo) ?>,
};
// Launch Coach with zero assigned agents: the server already rendered the
// "No agents are currently assigned to you" state and skipped the
// production markup entirely -- there's nothing to fetch, and COACH.agent
// is empty here, which would otherwise resolve to a company-wide request
// the API correctly rejects for a coach. Just don't call it.
const COACH_HAS_NO_AGENTS = <?= json_encode($coachHasNoAgents) ?>;
let coachChart = null;
let coachRequestSeq = 0; // guards against an older, slower response landing after a newer one

function coachSyncUrl() {
  const url = new URL(location.href);
  const p = url.searchParams;
  if (COACH.agent) p.set('agent', COACH.agent); else p.delete('agent');
  p.set('period', COACH.period);
  p.delete('year'); p.delete('from'); p.delete('to');
  if (COACH.period === 'year') p.set('year', COACH.year);
  if (COACH.period === 'custom') { p.set('from', COACH.from); p.set('to', COACH.to); }
  history.replaceState(null, '', url.toString());
}

function coachApiUrl() {
  const p = new URLSearchParams();
  if (COACH.agent) p.set('agent', COACH.agent);
  p.set('period', COACH.period);
  if (COACH.period === 'year') p.set('year', COACH.year);
  if (COACH.period === 'custom') { p.set('from', COACH.from); p.set('to', COACH.to); }
  return 'api/coach_production_summary.php?' + p.toString();
}

function coachSetPeriod(p) {
  COACH.period = p;
  if (p === 'year' && !COACH.year) COACH.year = String(new Date().getFullYear());
  document.querySelectorAll('.coach-period-btn').forEach(function (btn) {
    btn.classList.toggle('active', btn.getAttribute('data-period') === p);
  });
  document.getElementById('coach-year-wrap').style.display = p === 'year' ? '' : 'none';
  document.getElementById('coach-custom-range-wrap').style.display = p === 'custom' ? '' : 'none';
  coachSyncUrl();
  if (p !== 'custom') coachLoad();
}
function coachSetYear(y) {
  COACH.year = String(y);
  coachSyncUrl();
  coachLoad();
}
function coachApplyCustomRange() {
  const from = document.getElementById('coach-custom-from').value;
  const to   = document.getElementById('coach-custom-to').value;
  if (!from || !to) { coachShowBanner('warn', 'Pick both a start and end date.'); return; }
  if (from > to) { coachShowBanner('warn', 'Start date must be before end date.'); return; }
  COACH.period = 'custom'; COACH.from = from; COACH.to = to;
  coachSyncUrl();
  coachLoad();
}
function coachFilterAgent(email) {
  COACH.agent = email || '';
  coachSyncUrl();
  coachApplyAgentRowVisibility();
  coachLoad();
}

// Show only the selected agent's row (mirrors the "All Agents" empty-string
// convention the page already used server-side) -- reused on initial load
// and every time the agent filter changes, instead of a full page reload.
function coachApplyAgentRowVisibility() {
  const rows = document.querySelectorAll('#coach-agent-list .coach-agent-row');
  let shown = 0;
  rows.forEach(function (row) {
    const hit = !COACH.agent || row.dataset.email === COACH.agent;
    row.style.display = hit ? '' : 'none';
    if (hit) shown++;
  });
  const countEl = document.getElementById('coach-agent-count');
  if (countEl) countEl.textContent = shown + ' of ' + rows.length + ' agents';
}

function coachFilterRows() {
  const q = document.getElementById('coach-agent-search').value.trim().toLowerCase();
  const rows = document.querySelectorAll('#coach-agent-list .coach-agent-row');
  let shown = 0;
  rows.forEach(row => {
    const agentHit = !COACH.agent || row.dataset.email === COACH.agent;
    const hit = agentHit && (!q || row.dataset.name.includes(q) || row.dataset.market.includes(q) || row.dataset.email.includes(q));
    row.style.display = hit ? '' : 'none';
    if (hit) shown++;
  });
  document.getElementById('coach-agent-count').textContent = shown + ' of ' + rows.length + ' agents';
}
function coachSortRows() {
  const key = document.getElementById('coach-agent-sort').value;
  const list = document.getElementById('coach-agent-list');
  const rows = Array.from(list.querySelectorAll('.coach-agent-row'));
  rows.sort((a, b) => (a.dataset[key] || '').localeCompare(b.dataset[key] || ''));
  rows.forEach(r => list.appendChild(r));
}

// ── Formatting helpers ───────────────────────────────────────────────────────
function coachFmtMoney(v) {
  if (v === null || v === undefined) return '—';
  const sign = v < 0 ? '-' : '';
  const abs = Math.abs(v);
  let out;
  if (abs >= 1000000) out = '$' + (abs / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
  else if (abs >= 1000) out = '$' + Math.round(abs / 1000) + 'K';
  else out = '$' + Math.round(abs).toLocaleString();
  return sign + out;
}
function coachExactMoney(v) { return (v === null || v === undefined) ? '' : '$' + Math.round(v).toLocaleString(); }
function coachFmtInt(v) { return (v === null || v === undefined) ? '—' : Math.round(v).toLocaleString(); }
function coachFmtPct(v) {
  if (v === null || v === undefined) return '—';
  return (v > 0 ? '+' : '') + v.toFixed(1) + '%';
}
function coachTrendClass(v) {
  if (v === null || v === undefined) return 'coach-trend-neutral';
  if (v > 0) return 'coach-trend-up';
  if (v < 0) return 'coach-trend-down';
  return 'coach-trend-neutral';
}
// prior=0 is a real, common case (e.g. an agent's first year) -- never divide
// by zero into Infinity/NaN; fall back to a plain-language state instead.
function coachMtdDelta(current, prior) {
  if (current === null || prior === null || current === undefined || prior === undefined) {
    return { text: '—', cls: 'coach-trend-neutral' };
  }
  if (prior === 0) {
    if (current === 0) return { text: 'No activity yet', cls: 'coach-trend-neutral' };
    return { text: 'New vs. no prior-year activity', cls: 'coach-trend-up' };
  }
  const pct = ((current - prior) / prior) * 100;
  const arrow = pct >= 0 ? '↑' : '↓';
  return { text: arrow + ' ' + Math.abs(pct).toFixed(0) + '% vs same period last year', cls: pct >= 0 ? 'coach-trend-up' : 'coach-trend-down' };
}

function coachShowBanner(kind, text) {
  const el = document.getElementById('coach-status-banner');
  el.className = 'coach-status-banner' + (kind ? ' ' + kind : '');
  el.textContent = text;
  el.hidden = !text;
}

// ── Rendering ────────────────────────────────────────────────────────────────
function coachSetLoading(on) {
  document.getElementById('coach-summary-grid').classList.toggle('coach-loading', on);
}

function coachRenderUnavailable(message) {
  ['coach-stat-transactions', 'coach-stat-volume', 'coach-stat-avgprice', 'coach-stat-active-volume', 'coach-stat-active-count'].forEach(function (id) {
    const el = document.getElementById(id);
    el.textContent = '—';
    el.className = 'coach-stat-val coach-unavailable';
  });
  document.getElementById('coach-stat-transactions-mtd').textContent = '';
  document.getElementById('coach-stat-volume-mtd').textContent = '';
  coachShowBanner('err', message || 'Production data unavailable.');
  if (coachChart) { coachChart.data.datasets[0].data = []; coachChart.data.datasets[1].data = []; coachChart.data.labels = []; coachChart.update(); }
}

function coachRenderChart(chart) {
  const canvas = document.getElementById('coach-chart-canvas');
  if (!canvas || typeof Chart === 'undefined' || !chart) return;
  const labels = (chart.current_12mo || []).map(b => b.month);
  const current = (chart.current_12mo || []).map(b => b.volume);
  const prior = (chart.prior_12mo || []).map(b => b.volume);
  if (!coachChart) {
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = '#666';
    coachChart = new Chart(canvas, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          { label: 'Current 12 Months', data: current, backgroundColor: '#82C112', borderRadius: 4 },
          { label: 'Prior 12 Months', data: prior, backgroundColor: '#e6e7e8', borderRadius: 4 },
        ],
      },
      options: {
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, ticks: { callback: v => coachFmtMoney(v) }, grid: { color: '#f0f0f0' } },
          x: { grid: { display: false } },
        },
      },
    });
  } else {
    coachChart.data.labels = labels;
    coachChart.data.datasets[0].data = current;
    coachChart.data.datasets[1].data = prior;
    coachChart.update();
  }
}

function coachRenderRoster(agents) {
  (agents || []).forEach(function (a) {
    const row = document.querySelector('#coach-agent-list .coach-agent-row[data-email="' + (window.CSS && CSS.escape ? CSS.escape(a.email) : a.email) + '"]');
    if (!row) return;
    const volEl = row.querySelector('[data-stat="ltm_volume"]');
    const trendEl = row.querySelector('[data-stat="trend_pct"]');
    const sidesEl = row.querySelector('[data-stat="ltm_sides"]');
    const unavailable = a.production_status === 'unmatched' || a.production_status === 'mirror_unavailable';
    if (unavailable) {
      volEl.textContent = '—'; volEl.title = '';
      sidesEl.textContent = '—';
      trendEl.textContent = '—'; trendEl.className = 'coach-row-stat-val';
      return;
    }
    volEl.textContent = coachFmtMoney(a.ltm_volume);
    volEl.title = coachExactMoney(a.ltm_volume);
    sidesEl.textContent = coachFmtInt(a.ltm_sides);
    if (a.trend_pct === null || a.trend_pct === undefined) {
      trendEl.textContent = '—'; trendEl.className = 'coach-row-stat-val coach-trend-neutral';
    } else {
      trendEl.textContent = coachFmtPct(a.trend_pct);
      trendEl.className = 'coach-row-stat-val ' + coachTrendClass(a.trend_pct);
    }
  });
}

function coachRenderClosedCard(closed, mtd, unavailableReason) {
  const txEl = document.getElementById('coach-stat-transactions');
  const volEl = document.getElementById('coach-stat-volume');
  const avgEl = document.getElementById('coach-stat-avgprice');
  const txMtdEl = document.getElementById('coach-stat-transactions-mtd');
  const volMtdEl = document.getElementById('coach-stat-volume-mtd');

  if (unavailableReason) {
    txEl.textContent = '—'; txEl.className = 'coach-stat-val coach-unavailable'; txEl.title = unavailableReason;
    volEl.textContent = '—'; volEl.className = 'coach-stat-val coach-unavailable';
    avgEl.textContent = '—'; avgEl.className = 'coach-stat-val coach-unavailable';
    txMtdEl.textContent = unavailableReason;
    volMtdEl.textContent = '';
    return;
  }

  txEl.className = 'coach-stat-val'; txEl.title = '';
  volEl.className = 'coach-stat-val';
  avgEl.className = 'coach-stat-val';
  txEl.textContent = coachFmtInt(closed.transactions);
  volEl.textContent = coachFmtMoney(closed.volume);
  volEl.title = coachExactMoney(closed.volume);
  avgEl.textContent = coachFmtMoney(closed.average_sale_price);
  avgEl.title = coachExactMoney(closed.average_sale_price);

  if (mtd) {
    const txDelta = coachMtdDelta(mtd.current.transactions, mtd.prior_year.transactions);
    const volDelta = coachMtdDelta(mtd.current.volume, mtd.prior_year.volume);
    txMtdEl.innerHTML = 'MTD ' + coachFmtInt(mtd.current.transactions) + '<span class="coach-mtd-delta ' + txDelta.cls + '">' + txDelta.text + '</span>';
    volMtdEl.innerHTML = 'MTD ' + coachFmtMoney(mtd.current.volume) + '<span class="coach-mtd-delta ' + volDelta.cls + '">' + volDelta.text + '</span>';
  } else {
    txMtdEl.textContent = 'MTD —';
    volMtdEl.textContent = 'MTD —';
  }
}

function coachRenderActiveListings(active, isCompany) {
  const volEl = document.getElementById('coach-stat-active-volume');
  const countEl = document.getElementById('coach-stat-active-count');
  const lblEl = document.getElementById('coach-active-listings-lbl');
  if (isCompany) {
    volEl.textContent = '—'; volEl.className = 'coach-stat-val coach-unavailable';
    countEl.textContent = '—'; countEl.className = 'coach-stat-val coach-unavailable';
    countEl.title = active && active.reason ? active.reason : '';
    lblEl.innerHTML = 'Listings <span class="coach-active-note" title="Company-wide deduped listing total not available yet">(?)</span>';
    return;
  }
  lblEl.textContent = 'Listings';
  if (!active) {
    volEl.textContent = '—'; volEl.className = 'coach-stat-val coach-unavailable';
    countEl.textContent = '—'; countEl.className = 'coach-stat-val coach-unavailable';
    return;
  }
  volEl.className = 'coach-stat-val'; countEl.className = 'coach-stat-val';
  volEl.textContent = coachFmtMoney(active.volume);
  volEl.title = coachExactMoney(active.volume);
  countEl.textContent = coachFmtInt(active.count);
}

function coachRender(data) {
  const mirrorOk = !!(data.mirror && data.mirror.healthy);

  if (!mirrorOk) {
    coachRenderUnavailable('Coastline production mirror is currently unavailable.');
    coachRenderRoster(data.agents);
    return;
  }

  if (data.coverage && data.coverage.complete === false) {
    coachShowBanner('warn', 'Partial data — the selected period extends beyond what has been synced from Coastline (' + data.coverage.available_start + ' to ' + data.coverage.available_end + '). Figures shown are real but incomplete.');
  } else {
    coachShowBanner(null, '');
  }

  if (data.scope === 'all') {
    const s = data.company_summary;
    coachRenderClosedCard(s.closed, s.mtd, null);
    coachRenderActiveListings(s.active_listings, true);
    coachRenderChart(s.chart);
  } else {
    const a = data.selected_agent;
    if (a.production_status === 'unmatched') {
      coachRenderClosedCard(null, null, 'Production unavailable — agent identity not matched');
      coachRenderActiveListings(null, false);
      coachRenderChart(a.chart);
    } else if (a.production_status === 'mirror_unavailable') {
      coachRenderClosedCard(null, null, 'Production mirror unavailable');
      coachRenderActiveListings(null, false);
      coachRenderChart(a.chart);
    } else {
      coachRenderClosedCard(a.closed, a.mtd, null);
      coachRenderActiveListings(a.active_listings, false);
      coachRenderChart(a.chart);
    }
  }

  coachRenderRoster(data.agents);
}

function coachLoad() {
  const seq = ++coachRequestSeq;
  coachSetLoading(true);
  fetch(coachApiUrl(), { credentials: 'same-origin' })
    .then(function (r) { return r.json().then(function (data) { return { status: r.status, data: data }; }); })
    .then(function (res) {
      if (seq !== coachRequestSeq) return; // a newer request already landed
      coachSetLoading(false);
      if (!res.data || !res.data.ok) {
        coachRenderUnavailable((res.data && res.data.error) || 'Could not load production data.');
        return;
      }
      coachRender(res.data);
    })
    .catch(function () {
      if (seq !== coachRequestSeq) return;
      coachSetLoading(false);
      coachRenderUnavailable('Could not reach the production service.');
    });
}

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.coach-period-btn').forEach(function (btn) {
    const label = btn.textContent.trim().toLowerCase();
    const key = label === 'ltm' ? 'ltm' : label === 'ytd' ? 'ytd' : label === 'year' ? 'year' : 'custom';
    btn.setAttribute('data-period', key);
  });
  if (!COACH_HAS_NO_AGENTS) {
    coachApplyAgentRowVisibility();
    coachLoad();
  }
});
</script>
</body>
</html>
