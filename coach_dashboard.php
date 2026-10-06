<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';

// V1: super-admin-only while the coaching data model is decided. Do not
// widen to is_launch_coach() without a deliberate follow-up pass — see
// nav.php's coach_dashboard group and api/coach_roster_activity.php.
$agent = require_login();
if (!is_super_admin()) { header('Location: index.php'); exit; }

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES); }

// Real, existing roster data — no coaching schema involved. Powers both the
// "Agent" filter dropdown and the Agent List below from one query.
$roster = local_db()->query(
    "SELECT agent_name, email, phone, market_center, state_code, license_exp
     FROM innovate_roster WHERE active=1 ORDER BY agent_name"
)->fetchAll(PDO::FETCH_ASSOC);

$period = $_GET['period'] ?? 'ltm';
if (!in_array($period, ['ltm', 'ytd', '2025', 'custom'], true)) $period = 'ltm';
$customFrom = $_GET['from'] ?? '';
$customTo   = $_GET['to']   ?? '';
$selectedAgent = strtolower(trim($_GET['agent'] ?? ''));
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
          <select class="coach-agent-select" id="coach-agent-select" onchange="coachFilterAgent(this.value)">
            <option value="">All Agents</option>
            <?php foreach ($roster as $r): $em = strtolower(trim($r['email'])); ?>
              <option value="<?= h($em) ?>"<?= $em === $selectedAgent ? ' selected' : '' ?>><?= h($r['agent_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="coach-filter-field">
          <label>Period</label>
          <div class="coach-period">
            <?php foreach (['ltm' => 'LTM', 'ytd' => 'YTD', '2025' => '2025', 'custom' => 'Custom'] as $pk => $pl): ?>
              <button type="button" class="coach-period-btn<?= $period === $pk ? ' active' : '' ?>" onclick="coachSetPeriod('<?= $pk ?>')"><?= h($pl) ?></button>
            <?php endforeach; ?>
          </div>
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

      <!-- B. Performance summary -->
      <div class="coach-summary-grid">
        <div class="card coach-summary-card">
          <h3>Pending</h3>
          <div class="coach-stat-row">
            <div class="coach-stat"><span class="coach-stat-val empty">—</span><span class="coach-stat-lbl">Transactions</span></div>
            <div class="coach-stat"><span class="coach-stat-val empty">—</span><span class="coach-stat-lbl">Total Volume</span></div>
            <div class="coach-stat"><span class="coach-stat-val empty">—</span><span class="coach-stat-lbl">Avg Sale Price</span></div>
            <div class="coach-stat"><span class="coach-stat-val empty">—</span><span class="coach-stat-lbl">GCI</span></div>
          </div>
        </div>
        <div class="card coach-summary-card">
          <h3>Closed</h3>
          <div class="coach-stat-row">
            <div class="coach-stat"><span class="coach-stat-val empty">—</span><span class="coach-stat-lbl">Transactions</span><span class="coach-stat-sub">MTD —</span></div>
            <div class="coach-stat"><span class="coach-stat-val empty">—</span><span class="coach-stat-lbl">Total Volume</span><span class="coach-stat-sub">MTD —</span></div>
            <div class="coach-stat"><span class="coach-stat-val empty">—</span><span class="coach-stat-lbl">Avg Sale Price</span><span class="coach-stat-sub">MTD —</span></div>
            <div class="coach-stat"><span class="coach-stat-val empty">—</span><span class="coach-stat-lbl">GCI</span><span class="coach-stat-sub">MTD —</span></div>
          </div>
        </div>
        <div class="card coach-summary-card">
          <h3>Active Listings</h3>
          <div class="coach-stat-row">
            <div class="coach-stat"><span class="coach-stat-val empty">—</span><span class="coach-stat-lbl">Total Volume</span></div>
            <div class="coach-stat"><span class="coach-stat-val empty">—</span><span class="coach-stat-lbl">Listings</span></div>
          </div>
        </div>
      </div>

      <!-- C. Performance chart -->
      <div class="card coach-chart-card">
        <div class="coach-chart-head">
          <h2>Closed Volume by Month</h2>
          <div class="coach-chart-legend"><span class="leg-current">Current 12 months</span><span class="leg-prior">Prior 12 months</span></div>
        </div>
        <div class="coach-chart-shell">Closed-volume chart will render here once a closed-transaction data source is connected.</div>
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
              <div class="coach-row-stat"><div class="coach-row-stat-val">—</div><div class="coach-row-stat-lbl">LTM Volume</div></div>
              <div class="coach-row-stat"><div class="coach-row-stat-val">—</div><div class="coach-row-stat-lbl">Trend</div></div>
              <div class="coach-row-stat"><div class="coach-row-stat-val">—</div><div class="coach-row-stat-lbl">Sides</div></div>
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

    </main>
  </div>
</div>
<script>
function coachSetPeriod(p) {
  const url = new URL(location.href);
  url.searchParams.set('period', p);
  if (p !== 'custom') { url.searchParams.delete('from'); url.searchParams.delete('to'); }
  location.href = url.toString();
}
function coachApplyCustomRange() {
  const url = new URL(location.href);
  url.searchParams.set('period', 'custom');
  url.searchParams.set('from', document.getElementById('coach-custom-from').value);
  url.searchParams.set('to', document.getElementById('coach-custom-to').value);
  location.href = url.toString();
}
function coachFilterAgent(email) {
  const url = new URL(location.href);
  if (email) url.searchParams.set('agent', email); else url.searchParams.delete('agent');
  location.href = url.toString();
}
function coachFilterRows() {
  const q = document.getElementById('coach-agent-search').value.trim().toLowerCase();
  const rows = document.querySelectorAll('#coach-agent-list .coach-agent-row');
  let shown = 0;
  rows.forEach(row => {
    const hit = !q || row.dataset.name.includes(q) || row.dataset.market.includes(q) || row.dataset.email.includes(q);
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
<?php if ($selectedAgent !== ''): ?>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('#coach-agent-list .coach-agent-row').forEach(row => {
    if (row.dataset.email !== <?= json_encode($selectedAgent) ?>) row.style.display = 'none';
  });
  const c = document.querySelectorAll('#coach-agent-list .coach-agent-row[style*="display: none"]');
  document.getElementById('coach-agent-count').textContent = (document.querySelectorAll('#coach-agent-list .coach-agent-row').length - c.length) + ' of ' + document.querySelectorAll('#coach-agent-list .coach-agent-row').length + ' agents';
});
<?php endif; ?>
</script>
</body>
</html>
