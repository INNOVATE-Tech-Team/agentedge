<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/local_db.php';
$agent = require_login();

$me = $agent['email'];
$db = local_db();

// Company Regrid key — powers the bulk farm sync (owner/mailing address, sale
// history, assessed value). Per-agent BatchData key stays for manual skip trace.
$cfg          = cfg();
$regridKey    = trim($cfg['regrid_api_key'] ?? '');
$costPerRec   = (float)($cfg['listing_intel_cost_per_rec'] ?? 0.10);
$usingCompany = $regridKey !== '';

// Monthly usage (tracked for Regrid company pulls)
$monthlyPulled = 0;
$period        = date('Y-m');
try {
    $r = $db->prepare("SELECT COALESCE(SUM(records_pulled),0) FROM listing_intel_usage WHERE agent_email=? AND period=?");
    $r->execute([$me, $period]);
    $monthlyPulled = (int)$r->fetchColumn();
} catch (\Throwable $e) {}

// Summary counts for overview tiles
$sc = $db->prepare("SELECT COUNT(*) FROM listing_farms WHERE agent_email=?"); $sc->execute([$me]); $farmCount = (int)$sc->fetchColumn();
$sc = $db->prepare("SELECT COUNT(*) FROM listing_prospects WHERE agent_email=? AND status != 'dead'"); $sc->execute([$me]); $activeProspects = (int)$sc->fetchColumn();
$sc = $db->prepare("SELECT COUNT(*) FROM listing_outreach WHERE agent_email=? AND logged_at >= date('now','-7 days')"); $sc->execute([$me]); $weeklyTouches = (int)$sc->fetchColumn();
$sc = $db->prepare("SELECT COUNT(*) FROM listing_prospects WHERE agent_email=? AND skip_traced=0 AND status!='dead'"); $sc->execute([$me]); $needsTrace = (int)$sc->fetchColumn();
$sc = $db->prepare("SELECT MAX(updated_at) FROM listing_prospects WHERE agent_email=? AND source='auto'"); $sc->execute([$me]); $lastSync = $sc->fetchColumn() ?: null;

// Farms for this agent
$sf = $db->prepare("SELECT * FROM listing_farms WHERE agent_email=? ORDER BY name"); $sf->execute([$me]); $farms = $sf->fetchAll(PDO::FETCH_ASSOC);

// Prospects — ordered by seller_score desc, then updated_at
$sp = $db->prepare("
    SELECT p.*, f.name AS farm_name,
        (SELECT COUNT(*) FROM listing_outreach o WHERE o.prospect_id=p.id) AS touch_count,
        (SELECT logged_at FROM listing_outreach o WHERE o.prospect_id=p.id ORDER BY logged_at DESC LIMIT 1) AS last_touch
    FROM listing_prospects p
    LEFT JOIN listing_farms f ON p.farm_id = f.id
    WHERE p.agent_email=?
    ORDER BY p.seller_score DESC, p.updated_at DESC
");
$sp->execute([$me]);
$prospects = $sp->fetchAll(PDO::FETCH_ASSOC);

// Overview chart data — computed from data already loaded above, no extra queries.
$scoreBuckets = ['0–24' => 0, '25–44' => 0, '45–74' => 0, '75–100' => 0];
foreach ($prospects as $p) {
    $s = (int)$p['seller_score'];
    if ($s >= 75) $scoreBuckets['75–100']++;
    elseif ($s >= 45) $scoreBuckets['45–74']++;
    elseif ($s >= 25) $scoreBuckets['25–44']++;
    else $scoreBuckets['0–24']++;
}
$farmStats = [];
foreach ($farms as $farm) {
    $farmProspects = array_filter($prospects, fn($p) => (int)$p['farm_id'] === (int)$farm['id'] && $p['status'] !== 'dead');
    $count = count($farmProspects);
    $avgScore = $count ? round(array_sum(array_column($farmProspects, 'seller_score')) / $count) : 0;
    $farmStats[] = ['name' => $farm['name'], 'count' => $count, 'avg_score' => $avgScore];
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
function money(int $n): string { return $n ? '$'.number_format($n) : '—'; }
function chip(string $label, string $bg, string $fg, string $dot): string {
    return '<span class="chip" style="background:'.$bg.';color:'.$fg.'"><span class="chip-dot" style="background:'.$dot.'"></span>'.h($label).'</span>';
}
function statusChip(string $status): string {
    $map = [
        'new'       => ['New',       '#E8F4FF', '#2255CC', '#2255CC'],
        'contacted' => ['Contacted', '#F5EBD3', '#7A5618', '#A07221'],
        'active'    => ['Active',    '#EEF7DC', '#3D6B0A', '#82C112'],
        'dead'      => ['Dead',      '#F0F0F0', '#888888', '#999999'],
    ];
    [$label, $bg, $fg, $dot] = $map[$status] ?? [$status, '#F0F0F0', '#888', '#999'];
    return chip($label, $bg, $fg, $dot);
}
function scoreTier(int $score): array {
    if ($score >= 75) return ['hot',  '#A40000'];
    if ($score >= 45) return ['warm', '#A07221'];
    return ['cool', '#82C112'];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Listing Intel</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">
  <style>
    :root{
      --green:#82C112; --green-d:#5b8e0d; --ink:#111; --faint:#8a8a8a; --text-2:#666; --text-3:#999;
      --bg:#F8F9FA; --surface:#fff; --surface-2:#F8F9FA; --border:#E6E7E8; --border-2:#D5D6D7;
      --red:#A40000; --red-bg:#FDE2E2; --gold:#A07221; --gold-bg:#F5EBD3; --up-bg:#EEF7DC;
      --sidebar-bg:#000; --sidebar-text:#fff;
    }
    *{box-sizing:border-box}
    body{font-family:'Inter',-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink);margin:0}
    .tnum{font-family:'JetBrains Mono',monospace;font-variant-numeric:tabular-nums}
    a{color:inherit}

    /* ── App shell ────────────────────────────────────────────────────────── */
    .li-app{display:flex;min-height:100vh}
    .li-sidebar{width:228px;flex-shrink:0;background:var(--sidebar-bg);color:var(--sidebar-text);
      display:flex;flex-direction:column;transition:width .18s ease;position:sticky;top:0;height:100vh;overflow:hidden;z-index:10}
    .li-sidebar.collapsed{width:60px}
    .li-brand{display:flex;align-items:center;gap:10px;padding:22px 18px 18px}
    .li-logo{flex-shrink:0}
    .li-brand-text{overflow:hidden;white-space:nowrap}
    .li-brand-name{font-weight:900;font-size:15px;letter-spacing:.4px}
    .li-brand-sub{font-weight:700;color:var(--green);font-size:10px;text-transform:uppercase;letter-spacing:.12em;margin-top:1px}
    .li-sidebar.collapsed .li-brand-text{display:none}
    .li-collapse-btn{background:none;border:none;color:#777;cursor:pointer;padding:2px 18px 14px;text-align:left;font-size:11px;font-weight:600}
    .li-collapse-btn:hover{color:#fff}
    .li-nav{flex:1;padding:6px 0;overflow-y:auto}
    .li-nav-item{display:flex;align-items:center;gap:12px;padding:11px 18px;color:#AEB0AC;font-size:13px;
      font-weight:600;cursor:pointer;white-space:nowrap;border-left:3px solid transparent;transition:background .12s ease,color .12s ease}
    .li-nav-item:hover{color:#fff;background:rgba(255,255,255,.06)}
    .li-nav-item.active{color:var(--green);border-left-color:var(--green);background:rgba(130,193,18,.1)}
    .li-nav-icon{width:18px;text-align:center;flex-shrink:0;font-size:14px}
    .li-sidebar.collapsed .li-nav-label{display:none}
    .li-sidebar-footer{padding:14px 18px;border-top:1px solid rgba(255,255,255,.1);font-size:11px;color:#777;white-space:nowrap;overflow:hidden}
    .li-sidebar-footer a{text-decoration:none;color:#999}
    .li-sidebar-footer a:hover{color:#fff}
    .li-sidebar.collapsed .li-sidebar-footer{padding:14px 0;text-align:center}
    .li-sidebar.collapsed .li-sidebar-footer .li-footer-text{display:none}
    .li-main{flex:1;min-width:0;padding:24px 28px 60px}

    .li-topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px;flex-wrap:wrap}
    .li-title{font-size:20px;font-weight:800;margin:0}

    /* ── Setup banner ────────────────────────────────────────────────────── */
    .li-setup{background:#fff8e1;border:1px solid #ffe082;border-radius:10px;padding:18px 20px;margin-bottom:20px;display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap}
    .li-setup-icon{font-size:26px;flex-shrink:0;padding-top:2px}
    .li-setup-body{flex:1;min-width:0}
    .li-setup-body strong{display:block;font-size:14px;font-weight:800;color:#5a3e00;margin-bottom:3px}
    .li-setup-body p{margin:0 0 10px;font-size:12px;color:#7a5c00;line-height:1.6}

    /* ── Panes ────────────────────────────────────────────────────────────── */
    .li-pane{display:none}.li-pane.active{display:block}

    /* ── Stats tiles ──────────────────────────────────────────────────────── */
    .li-tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
    @media(max-width:760px){.li-tiles{grid-template-columns:repeat(2,1fr)}}
    .li-tile{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
    .li-tile-val{font-size:28px;font-weight:700}
    .li-tile-lbl{font-size:11px;color:var(--text-3);margin-top:3px;text-transform:uppercase;letter-spacing:.06em;font-weight:600}
    .li-tile.hot .li-tile-val{color:var(--red)}
    .li-tile.green .li-tile-val{color:var(--green-d)}

    /* ── Cards / panels ───────────────────────────────────────────────────── */
    .card{background:var(--surface);border:1px solid var(--border);border-radius:10px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
    .li-chart-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
    @media(max-width:900px){.li-chart-grid{grid-template-columns:1fr}}
    .li-chart-card{padding:18px}
    .li-chart-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-3);margin-bottom:14px}

    /* ── Table ────────────────────────────────────────────────────────────── */
    .li-tbl{width:100%;border-collapse:collapse;font-size:13px}
    .li-tbl th{text-align:left;padding:9px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-3);border-bottom:1px solid var(--border);white-space:nowrap}
    .li-tbl th.sortable{cursor:pointer;user-select:none}
    .li-tbl th.sortable:hover{color:var(--ink)}
    .li-tbl th.sortable .arrow{opacity:.3;font-size:9px;margin-left:2px}
    .li-tbl th.sortable.sorted .arrow{opacity:1;color:var(--green-d)}
    .li-tbl td{padding:10px 10px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
    .li-tbl tr:last-child td{border-bottom:none}
    .li-tbl tr:hover td{background:var(--surface-2)}
    .li-tbl .num{text-align:right}

    /* ── Chips (dot + label, matches advantage.innovateonline.com) ──────────── */
    .chip{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:600;white-space:nowrap}
    .chip-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}

    /* ── Score bar ────────────────────────────────────────────────────────── */
    .score-wrap{display:flex;align-items:center;gap:6px}
    .score-bar{flex:1;height:5px;background:#eee;border-radius:3px;overflow:hidden;min-width:40px}
    .score-fill{height:100%;border-radius:3px;background:var(--green)}
    .score-fill.warm{background:var(--gold)}
    .score-fill.hot{background:var(--red)}
    .score-num{font-size:11px;font-weight:700;color:var(--text-2);width:22px;text-align:right}

    /* ── Buttons ──────────────────────────────────────────────────────────── */
    .btn{display:inline-flex;align-items:center;gap:5px;padding:8px 14px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none}
    .btn-primary{background:var(--green);color:#fff}
    .btn-primary:hover{background:var(--green-d)}
    .btn-ghost{background:var(--surface-2);color:var(--text-2);border:1px solid var(--border)}
    .btn-ghost:hover{background:#eee}
    .btn-danger{background:var(--red-bg);color:var(--red)}
    .btn-danger:hover{opacity:.85}
    .btn-sm{padding:5px 10px;font-size:12px}
    .btn-row{display:flex;gap:8px;flex-wrap:wrap}
    .btn:disabled{opacity:.6;cursor:default}

    /* ── Bulk toolbar ─────────────────────────────────────────────────────── */
    .li-bulk-bar{display:none;align-items:center;gap:10px;background:#111;color:#fff;border-radius:8px;padding:10px 14px;margin-bottom:10px;font-size:13px}
    .li-bulk-bar.show{display:flex}
    .li-bulk-bar select{padding:6px 8px;border-radius:5px;border:none;font-size:12px}

    /* ── Empty state ──────────────────────────────────────────────────────── */
    .li-empty{text-align:center;padding:48px 20px;color:var(--faint)}
    .li-empty-icon{font-size:40px;margin-bottom:10px}
    .li-empty-msg{font-size:14px;margin-bottom:16px}

    /* ── Modals ───────────────────────────────────────────────────────────── */
    .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:1000}
    .modal-overlay.open{display:flex}
    .modal{background:#fff;border-radius:12px;width:min(520px,95vw);max-height:90vh;overflow-y:auto;padding:24px;position:relative;box-shadow:0 20px 60px rgba(0,0,0,.25)}
    .modal h3{margin:0 0 4px;font-size:17px;font-weight:800}
    .modal .sub{font-size:12px;color:var(--faint);margin-bottom:18px}
    .modal-close{position:absolute;top:16px;right:16px;background:none;border:none;font-size:22px;cursor:pointer;color:#888;line-height:1}
    .form-row{margin-bottom:14px}
    .form-row label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-3);margin-bottom:5px}
    .form-input{width:100%;padding:9px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--surface-2)}
    .form-input:focus{outline:none;border-color:var(--green);background:#fff}
    .form-grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .form-select{width:100%;padding:9px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--surface-2)}
    .form-textarea{width:100%;padding:9px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--surface-2);min-height:72px;resize:vertical}

    /* ── Farm cards ───────────────────────────────────────────────────────── */
    .farm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin-bottom:20px}
    .farm-card{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
    .farm-card-name{font-size:15px;font-weight:800;margin-bottom:4px}
    .farm-card-meta{font-size:12px;color:var(--faint);margin-bottom:10px}
    .farm-zip{display:inline-block;background:var(--up-bg);color:var(--green-d);border-radius:4px;padding:2px 8px;font-size:11px;font-weight:700;margin:2px 2px 2px 0}

    /* ── Expired pipeline / toolbars ─────────────────────────────────────── */
    .exp-toolbar{display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap}
    .exp-search{padding:8px 12px;border:1px solid var(--border);border-radius:6px;font-size:13px;width:220px;background:var(--surface-2)}
    .exp-note{font-size:12px;color:var(--faint);margin-left:auto}

    /* ── Outreach log drawer ──────────────────────────────────────────────── */
    .log-list{display:flex;flex-direction:column;gap:8px;margin-bottom:16px}
    .log-item{background:var(--surface-2);border-radius:6px;padding:10px 12px;font-size:12px}
    .log-item-top{display:flex;align-items:center;gap:8px;margin-bottom:3px}
    .log-method{font-weight:700;font-size:11px;text-transform:uppercase;color:var(--green-d)}
    .log-outcome{color:var(--faint)}
    .log-date{margin-left:auto;color:var(--faint);font-size:11px}
    .log-notes{color:#444}
  </style>
</head>
<body>
<div class="li-app">
  <aside class="li-sidebar" id="li-sidebar">
    <div class="li-brand">
      <svg class="li-logo" width="26" height="26" viewBox="0 0 26 26" fill="none">
        <rect x="0" y="14" width="8" height="12" fill="#82C112"/>
        <rect x="9" y="7" width="8" height="19" fill="#82C112"/>
        <rect x="18" y="0" width="8" height="26" fill="#82C112"/>
      </svg>
      <div class="li-brand-text">
        <div class="li-brand-name">INNOVATE</div>
        <div class="li-brand-sub">Listing Intel</div>
      </div>
    </div>
    <button class="li-collapse-btn" onclick="toggleSidebar()" id="li-collapse-btn">&laquo; Collapse</button>
    <nav class="li-nav">
      <div class="li-nav-item active" data-tab="overview" onclick="switchTab('overview', this)">
        <span class="li-nav-icon">&#128202;</span><span class="li-nav-label">Overview</span>
      </div>
      <div class="li-nav-item" data-tab="prospects" onclick="switchTab('prospects', this)">
        <span class="li-nav-icon">&#127968;</span><span class="li-nav-label">Prospects</span>
      </div>
      <div class="li-nav-item" data-tab="expireds" onclick="switchTab('expireds', this)">
        <span class="li-nav-icon">&#8987;</span><span class="li-nav-label">Expired Pipeline</span>
      </div>
      <div class="li-nav-item" data-tab="farms" onclick="switchTab('farms', this)">
        <span class="li-nav-icon">&#128506;&#65039;</span><span class="li-nav-label">My Farms</span>
      </div>
      <div class="li-nav-item" data-tab="map" onclick="switchTab('map', this)">
        <span class="li-nav-icon">&#128205;</span><span class="li-nav-label">Map</span>
      </div>
    </nav>
    <div class="li-sidebar-footer">
      <span class="li-footer-text"><?= h($agent['name'] ?? $me) ?><br><a href="logout.php">Sign out</a> &middot; <a href="https://agentedge.innovateonline.com/">AgentEdge &#8599;</a></span>
    </div>
  </aside>

  <div class="li-main">
    <header class="li-topbar">
      <h1 class="li-title" id="li-page-title">Overview</h1>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <?php if ($lastSync): ?>
        <span style="font-size:11px;color:var(--faint)">Last sync: <?= date('M j g:ia', strtotime($lastSync)) ?></span>
        <?php endif; ?>
        <button class="btn btn-primary btn-sm" id="sync-btn" onclick="syncProspects()" <?= !$usingCompany ? 'title="Regrid API key not configured yet"' : '' ?>>&#8635; Sync Prospects</button>
        <a class="btn btn-ghost btn-sm" href="api/listing_intel.php?action=export_csv" download>&#8595; Export CSV</a>
        <button class="btn btn-ghost btn-sm" onclick="openProspectModal()">+ Add Manually</button>
      </div>
    </header>

    <!-- Provider banner -->
    <?php if ($usingCompany): ?>
    <div class="li-setup" style="background:var(--up-bg);border-color:#c5e29a">
      <div class="li-setup-icon">&#9989;</div>
      <div class="li-setup-body" style="display:flex;align-items:center;gap:32px;flex-wrap:wrap">
        <div>
          <strong style="color:#1b5e20">INNOVATE Property Data — Active</strong>
          <p style="color:#2e7d32;margin:0">Tax record sync is ready. Click <strong>Sync Prospects</strong> to pull seller candidates for your farm areas.</p>
        </div>
        <?php if ($monthlyPulled > 0): ?>
        <div style="border-left:1px solid #a5d6a7;padding-left:24px;flex-shrink:0">
          <div class="tnum" style="font-size:22px;font-weight:700;color:#1b5e20"><?= number_format($monthlyPulled) ?></div>
          <div style="font-size:11px;color:#388e3c;text-transform:uppercase;letter-spacing:.04em">Records this month</div>
          <div style="font-size:12px;color:#388e3c;margin-top:2px">Est. $<?= number_format($monthlyPulled * $costPerRec, 2) ?> charge</div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php else: ?>
    <div class="li-setup">
      <div class="li-setup-icon">&#9203;</div>
      <div class="li-setup-body">
        <strong>Property data sync coming soon</strong>
        <p>INNOVATE is finalizing the parcel/tax data integration. Once active, you'll be able to sync seller candidates directly from tax records — owner info, mailing address, years owned, and assessed value — for any zip code in your farm areas. No setup required on your end.</p>
      </div>
    </div>
    <?php endif; ?>

    <!-- Overview tiles (shown on every tab) -->
    <div class="li-tiles">
      <div class="li-tile">
        <div class="li-tile-val tnum"><?= $farmCount ?></div>
        <div class="li-tile-lbl">Farm Areas</div>
      </div>
      <div class="li-tile">
        <div class="li-tile-val tnum"><?= $activeProspects ?></div>
        <div class="li-tile-lbl">Candidates</div>
      </div>
      <div class="li-tile hot">
        <div class="li-tile-val tnum"><?= $needsTrace ?></div>
        <div class="li-tile-lbl">Need Skip Trace</div>
      </div>
      <div class="li-tile green">
        <div class="li-tile-val tnum"><?= $weeklyTouches ?></div>
        <div class="li-tile-lbl">Touches This Week</div>
      </div>
    </div>

    <!-- ── OVERVIEW ───────────────────────────────────────────────────── -->
    <div class="li-pane active" id="pane-overview">
      <?php if (empty($prospects)): ?>
      <div class="li-empty">
        <div class="li-empty-icon">&#128202;</div>
        <div class="li-empty-msg">No prospect data yet — sync a farm or load sample data from the Map tab to see charts here.</div>
      </div>
      <?php else: ?>
      <div class="li-chart-grid">
        <div class="card li-chart-card">
          <div class="li-chart-title">Score Distribution</div>
          <canvas id="chart-score" height="180"></canvas>
        </div>
        <div class="card li-chart-card">
          <div class="li-chart-title">Farm Performance</div>
          <canvas id="chart-farms" height="180"></canvas>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── PROSPECTS ──────────────────────────────────────────────────── -->
    <div class="li-pane" id="pane-prospects">
      <?php if (empty($farms)): ?>
      <div class="li-empty">
        <div class="li-empty-icon">&#128506;&#65039;</div>
        <div class="li-empty-msg">Set up a farm area first, then sync to auto-generate your prospect list.</div>
        <button class="btn btn-primary" onclick="switchTabByKey('farms'); openFarmModal()">+ Add Farm</button>
      </div>
      <?php elseif (empty($prospects)): ?>
      <div class="li-empty">
        <div class="li-empty-icon">&#127968;</div>
        <div class="li-empty-msg">No prospects yet. Sync your farms to pull candidates from MLS data.</div>
        <button class="btn btn-primary" onclick="syncProspects()">&#8635; Sync Farm Prospects</button>
      </div>
      <?php else: ?>
      <div style="margin-bottom:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="search" id="prospect-search" class="exp-search" placeholder="Search address, city…" oninput="filterProspects()">
        <select id="prospect-status-filter" class="form-select" style="width:150px" onchange="filterProspects()">
          <option value="">All statuses</option>
          <option value="new">New</option>
          <option value="contacted">Contacted</option>
          <option value="active">Active</option>
          <option value="dead">Dead</option>
        </select>
        <select id="prospect-trace-filter" class="form-select" style="width:170px" onchange="filterProspects()">
          <option value="">All</option>
          <option value="0">Needs Skip Trace</option>
          <option value="1">Skip Traced</option>
        </select>
        <span style="font-size:12px;color:var(--faint);margin-left:auto"><?= $activeProspects ?> candidate<?= $activeProspects != 1 ? 's' : '' ?></span>
      </div>

      <div class="li-bulk-bar" id="bulk-bar">
        <span id="bulk-count">0 selected</span>
        <select id="bulk-status-select">
          <option value="new">Mark New</option>
          <option value="contacted">Mark Contacted</option>
          <option value="active">Mark Active</option>
          <option value="dead">Mark Dead</option>
        </select>
        <button class="btn btn-primary btn-sm" onclick="applyBulkStatus()">Apply</button>
        <button class="btn btn-ghost btn-sm" onclick="exportSelected()">Export Selected</button>
        <button class="btn btn-ghost btn-sm" onclick="clearSelection()" style="margin-left:auto">Clear</button>
      </div>

      <div class="card" style="padding:0;overflow:hidden">
        <table class="li-tbl" id="prospect-table">
          <thead><tr>
            <th style="width:30px"><input type="checkbox" id="select-all" onchange="toggleSelectAll(this)"></th>
            <th class="sortable" data-sort="address" onclick="sortTable('address')">Address <span class="arrow">&#9660;</span></th>
            <th class="sortable" data-sort="owner" onclick="sortTable('owner')">Owner <span class="arrow">&#9660;</span></th>
            <th class="sortable" data-sort="years" onclick="sortTable('years')">Yrs Owned <span class="arrow">&#9660;</span></th>
            <th class="sortable sorted" data-sort="score" onclick="sortTable('score')">Score <span class="arrow">&#9660;</span></th>
            <th class="num sortable" data-sort="value" onclick="sortTable('value')">Est. Value <span class="arrow">&#9660;</span></th>
            <th>Skip Trace</th>
            <th class="sortable" data-sort="status" onclick="sortTable('status')">Status <span class="arrow">&#9660;</span></th>
            <th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($prospects as $p):
            [$scoreClass, ] = scoreTier((int)$p['seller_score']);
            $traced = !empty($p['skip_traced']);
          ?>
          <tr data-id="<?= $p['id'] ?>" data-status="<?= h($p['status']) ?>" data-traced="<?= $traced ? '1' : '0' ?>"
              data-search="<?= h(strtolower($p['address'].' '.$p['city'].' '.($p['owner_name'] ?? ''))) ?>"
              data-sort-address="<?= h(strtolower($p['address'])) ?>"
              data-sort-owner="<?= h(strtolower($p['owner_name'] ?? '')) ?>"
              data-sort-years="<?= (int)$p['years_owned'] ?>"
              data-sort-score="<?= (int)$p['seller_score'] ?>"
              data-sort-value="<?= (int)$p['est_value'] ?>"
              data-sort-status="<?= h($p['status']) ?>">
            <td><input type="checkbox" class="row-check" value="<?= $p['id'] ?>" onchange="updateBulkBar()"></td>
            <td>
              <div style="font-weight:600"><?= h($p['address']) ?><?php if (!empty($p['absentee_owner'])): ?> <?= chip('Absentee', '#F5EEFF', '#7C3AED', '#7C3AED') ?><?php endif; ?></div>
              <div style="font-size:11px;color:var(--faint)"><?= h($p['city']) ?><?= $p['zip'] ? ' '.$p['zip'] : '' ?></div>
            </td>
            <td>
              <?php if ($traced && $p['owner_name']): ?>
                <div style="font-weight:700"><?= h($p['owner_name']) ?></div>
                <?php if ($p['phone']): ?><div style="font-size:11px;color:var(--faint)"><?= h($p['phone']) ?></div><?php endif; ?>
              <?php else: ?>
                <span style="font-size:11px;color:#bbb;font-style:italic">Not traced</span>
              <?php endif; ?>
            </td>
            <td class="tnum" style="font-size:13px;font-weight:700;color:<?= ($p['years_owned'] >= 5 && $p['years_owned'] <= 7) ? 'var(--gold)' : 'var(--ink)' ?>">
              <?= $p['years_owned'] ? $p['years_owned'].'y' : '—' ?>
            </td>
            <td>
              <div class="score-wrap">
                <div class="score-bar"><div class="score-fill <?= $scoreClass ?>" style="width:<?= $p['seller_score'] ?>%"></div></div>
                <div class="score-num tnum"><?= $p['seller_score'] ?: '—' ?></div>
              </div>
            </td>
            <td class="num tnum" style="font-weight:700"><?= money((int)$p['est_value']) ?></td>
            <td>
              <?php if ($traced): ?>
                <?= chip('Traced', '#EEF7DC', '#3D6B0A', '#82C112') ?>
              <?php else: ?>
                <button class="btn btn-ghost btn-sm" onclick='openSkipTraceModal(<?= $p['id'] ?>, <?= json_encode($p['address']) ?>)'>Skip Trace</button>
              <?php endif; ?>
            </td>
            <td><?= statusChip($p['status']) ?></td>
            <td>
              <div style="display:flex;gap:4px">
                <?php if ($traced): ?>
                <button class="btn btn-ghost btn-sm" onclick='openOutreachModal(<?= $p['id'] ?>, <?= json_encode($p['owner_name'] ?: $p['address']) ?>)'>Log</button>
                <?php endif; ?>
                <button class="btn btn-ghost btn-sm" onclick='editProspect(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)'>Edit</button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── EXPIRED PIPELINE ───────────────────────────────────────────── -->
    <div class="li-pane" id="pane-expireds">
      <div class="exp-toolbar">
        <input type="search" id="exp-search" class="exp-search" placeholder="Search expireds…" oninput="filterExpireds()">
        <select id="exp-zip" class="form-select" style="width:160px" onchange="loadExpireds()">
          <option value="">All farm zips</option>
          <?php foreach ($farms as $farm):
            $zips = json_decode($farm['zip_codes'], true) ?: [];
            foreach ($zips as $z): ?>
            <option value="<?= h($z) ?>"><?= h($z) ?> (<?= h($farm['name']) ?>)</option>
          <?php endforeach; endforeach; ?>
        </select>
        <select id="exp-days" class="form-select" style="width:140px" onchange="loadExpireds()">
          <option value="30">Last 30 days</option>
          <option value="60">Last 60 days</option>
          <option value="90" selected>Last 90 days</option>
          <option value="180">Last 180 days</option>
        </select>
        <button class="btn btn-ghost btn-sm" onclick="loadExpireds()">Refresh</button>
        <span class="exp-note" id="exp-count"></span>
      </div>

      <?php if (empty($farms)): ?>
      <div class="li-empty">
        <div class="li-empty-icon">&#128506;&#65039;</div>
        <div class="li-empty-msg">Set up a farm area first so we know which zip codes to pull expireds from.</div>
        <button class="btn btn-primary" onclick="switchTabByKey('farms'); openFarmModal()">+ Add Farm</button>
      </div>
      <?php else: ?>
      <div class="card" style="padding:0;overflow:hidden" id="exp-card">
        <div id="exp-loading" style="padding:40px;text-align:center;color:var(--faint)">Loading expired listings…</div>
        <table class="li-tbl" id="exp-table" hidden>
          <thead><tr>
            <th>Address</th>
            <th>City</th>
            <th>MLS #</th>
            <th class="num">List Price</th>
            <th>DOM</th>
            <th>Expired</th>
            <th>Listing Agent</th>
            <th></th>
          </tr></thead>
          <tbody id="exp-body"></tbody>
        </table>
        <div id="exp-empty" class="li-empty" hidden>
          <div class="li-empty-icon">&#9989;</div>
          <div class="li-empty-msg">No expired listings found in this date range.</div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── FARMS ──────────────────────────────────────────────────────── -->
    <div class="li-pane" id="pane-farms">
      <div style="margin-bottom:16px">
        <button class="btn btn-primary" onclick="openFarmModal()">+ New Farm Area</button>
      </div>
      <?php if (empty($farms)): ?>
      <div class="li-empty">
        <div class="li-empty-icon">&#128506;&#65039;</div>
        <div class="li-empty-msg">No farm areas defined yet. A farm is a neighborhood or set of zip codes you focus on.</div>
        <button class="btn btn-primary" onclick="openFarmModal()">+ Add Farm</button>
      </div>
      <?php else: ?>
      <div class="farm-grid">
        <?php foreach ($farms as $farm):
          $zips = json_decode($farm['zip_codes'], true) ?: [];
          $hoods = json_decode($farm['neighborhoods'], true) ?: [];
          $sc2 = $db->prepare("SELECT COUNT(*) FROM listing_prospects WHERE agent_email=? AND farm_id=? AND status!='dead'");
          $sc2->execute([$me, $farm['id']]);
          $farmProspects = (int)$sc2->fetchColumn();
        ?>
        <div class="farm-card">
          <div class="farm-card-name"><?= h($farm['name']) ?><?php if ($farm['state']): ?> <span style="font-weight:400;color:var(--faint);font-size:12px"><?= h($farm['state']) ?></span><?php endif; ?><?php if (!empty($farm['is_demo'])): ?> <?= chip('Demo', '#F0F0F0', '#888', '#999') ?><?php endif; ?></div>
          <div class="farm-card-meta"><?= $farmProspects ?> active prospect<?= $farmProspects != 1 ? 's' : '' ?></div>
          <?php if ($zips): ?>
          <div style="margin-bottom:8px">
            <?php foreach ($zips as $z): ?><span class="farm-zip"><?= h($z) ?></span><?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php if ($hoods): ?>
          <div style="font-size:12px;color:var(--faint);margin-bottom:8px"><?= h(implode(', ', $hoods)) ?></div>
          <?php endif; ?>
          <?php if ($farm['notes']): ?>
          <div style="font-size:12px;color:#666;margin-bottom:10px"><?= h($farm['notes']) ?></div>
          <?php endif; ?>
          <div class="btn-row">
            <button class="btn btn-ghost btn-sm" onclick='editFarm(<?= htmlspecialchars(json_encode($farm), ENT_QUOTES) ?>)'>Edit</button>
            <button class="btn btn-danger btn-sm" onclick="deleteFarm(<?= $farm['id'] ?>, '<?= h(addslashes($farm['name'])) ?>')">Delete</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── MAP ────────────────────────────────────────────────────────── -->
    <?php
      $hasDemoFarm = false;
      foreach ($farms as $f) { if (!empty($f['is_demo'])) { $hasDemoFarm = true; break; } }
    ?>
    <div class="li-pane" id="pane-map">
      <?php if (empty($farms)): ?>
      <div class="li-empty">
        <div class="li-empty-icon">&#128506;&#65039;</div>
        <div class="li-empty-msg">Set up a farm area first so the map has somewhere to center on.</div>
        <button class="btn btn-primary" onclick="switchTabByKey('farms'); openFarmModal()">+ Add Farm</button>
      </div>
      <?php else: ?>
      <div class="exp-toolbar">
        <?php if ($hasDemoFarm): ?>
        <button class="btn btn-ghost btn-sm" onclick="clearDemoData()">Clear Sample Data</button>
        <?php else: ?>
        <button class="btn btn-primary btn-sm" onclick="seedDemoData()">+ Load Sample Data</button>
        <?php endif; ?>
        <a class="btn btn-ghost btn-sm" href="listing_map.php">&#10021; Full Screen</a>
        <span class="exp-note" id="map-count"></span>
      </div>
      <div class="card" style="padding:0;overflow:hidden">
        <div id="li-map" style="height:560px"></div>
      </div>
      <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:12px;font-size:12px;color:var(--faint)">
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--red);margin-right:5px"></span>Hot lead (75+)</span>
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--gold);margin-right:5px"></span>Warm lead (45–74)</span>
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--green);margin-right:5px"></span>Cooler lead (&lt;45)</span>
        <span>&#127968; Active MLS listing</span>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<!-- ── FARM MODAL ──────────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="farm-overlay">
  <div class="modal">
    <button class="modal-close" onclick="closeFarmModal()">×</button>
    <h3 id="farm-modal-title">New Farm Area</h3>
    <div class="sub">Define a geographic area you want to farm for listing leads.</div>
    <form id="farm-form">
      <input type="hidden" id="farm-id" value="">
      <div class="form-row">
        <label>Farm Name</label>
        <input type="text" id="farm-name" class="form-input" placeholder="e.g. Pawleys Plantation" required>
      </div>
      <div class="form-row">
        <label>State</label>
        <select id="farm-state" class="form-select">
          <option value="">— select —</option>
          <?php foreach (['DE','FL','GA','MA','MD','NC','NH','NJ','OH','PA','RI','SC','TN','VA'] as $st): ?>
          <option value="<?= $st ?>"><?= $st ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <label>Zip Codes <span style="font-weight:400;text-transform:none">(comma-separated)</span></label>
        <input type="text" id="farm-zips" class="form-input" placeholder="e.g. 29585, 29576">
      </div>
      <div class="form-row">
        <label>Neighborhoods <span style="font-weight:400;text-transform:none">(comma-separated)</span></label>
        <input type="text" id="farm-hoods" class="form-input" placeholder="e.g. Pawleys Plantation, Litchfield">
      </div>
      <div class="form-row">
        <label>Notes</label>
        <textarea id="farm-notes" class="form-textarea" placeholder="Any notes about this farm area…"></textarea>
      </div>
      <div class="btn-row">
        <button type="submit" class="btn btn-primary" id="farm-submit">Save Farm</button>
        <button type="button" class="btn btn-ghost" onclick="closeFarmModal()">Cancel</button>
      </div>
      <div id="farm-error" style="color:var(--red);font-size:12px;margin-top:10px;display:none"></div>
    </form>
  </div>
</div>

<!-- ── PROSPECT MODAL ─────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="prospect-overlay">
  <div class="modal">
    <button class="modal-close" onclick="closeProspectModal()">×</button>
    <h3 id="prospect-modal-title">Add Prospect</h3>
    <div class="sub">Track a potential seller lead.</div>
    <form id="prospect-form">
      <input type="hidden" id="prospect-id" value="">
      <div class="form-grid2">
        <div class="form-row">
          <label>Owner Name</label>
          <input type="text" id="p-owner" class="form-input" placeholder="John Smith" required>
        </div>
        <div class="form-row">
          <label>Farm Area</label>
          <select id="p-farm" class="form-select">
            <option value="">— none —</option>
            <?php foreach ($farms as $f): ?>
            <option value="<?= $f['id'] ?>"><?= h($f['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <label>Property Address</label>
        <input type="text" id="p-address" class="form-input" placeholder="123 Beach Rd" required>
      </div>
      <div class="form-grid2">
        <div class="form-row">
          <label>City</label>
          <input type="text" id="p-city" class="form-input" placeholder="Pawleys Island">
        </div>
        <div class="form-row">
          <label>Zip</label>
          <input type="text" id="p-zip" class="form-input" placeholder="29585">
        </div>
      </div>
      <div class="form-grid2">
        <div class="form-row">
          <label>Phone</label>
          <input type="text" id="p-phone" class="form-input" placeholder="(843) 555-0100">
        </div>
        <div class="form-row">
          <label>Email</label>
          <input type="email" id="p-email" class="form-input" placeholder="owner@email.com">
        </div>
      </div>
      <div class="form-grid2">
        <div class="form-row">
          <label>Source</label>
          <select id="p-source" class="form-select">
            <option value="manual">Manual</option>
            <option value="expired">Expired Listing</option>
            <option value="fsbo">FSBO</option>
            <option value="equity">Equity / Farm</option>
          </select>
        </div>
        <div class="form-row">
          <label>Status</label>
          <select id="p-status" class="form-select">
            <option value="new">New</option>
            <option value="contacted">Contacted</option>
            <option value="active">Active</option>
            <option value="dead">Dead</option>
          </select>
        </div>
      </div>
      <div class="form-grid2">
        <div class="form-row">
          <label>Seller Score (0–100)</label>
          <input type="number" id="p-score" class="form-input" min="0" max="100" placeholder="0">
        </div>
        <div class="form-row">
          <label>Est. Property Value</label>
          <input type="number" id="p-value" class="form-input" placeholder="450000">
        </div>
      </div>
      <div class="form-row">
        <label>MLS # (if expired)</label>
        <input type="text" id="p-mls" class="form-input" placeholder="">
      </div>
      <div class="form-row">
        <label>Notes</label>
        <textarea id="p-notes" class="form-textarea" placeholder="Any notes about this prospect…"></textarea>
      </div>
      <div class="btn-row">
        <button type="submit" class="btn btn-primary">Save Prospect</button>
        <button type="button" class="btn btn-ghost" onclick="closeProspectModal()">Cancel</button>
      </div>
      <div id="prospect-error" style="color:var(--red);font-size:12px;margin-top:10px;display:none"></div>
    </form>
  </div>
</div>

<!-- ── SKIP TRACE MODAL ───────────────────────────────────────────────────── -->
<div class="modal-overlay" id="skiptrace-overlay">
  <div class="modal">
    <button class="modal-close" onclick="closeSkipTraceModal()">×</button>
    <h3>Mark Skip Traced</h3>
    <div class="sub" id="skiptrace-modal-sub">Enter the contact info you found for this property owner.</div>
    <form id="skiptrace-form">
      <input type="hidden" id="st-prospect-id" value="">
      <div class="form-row">
        <label>Owner Name</label>
        <input type="text" id="st-owner" class="form-input" placeholder="John Smith">
      </div>
      <div class="form-row">
        <label>Phone</label>
        <input type="text" id="st-phone" class="form-input" placeholder="(843) 555-0100">
      </div>
      <div class="form-row">
        <label>Email</label>
        <input type="email" id="st-email" class="form-input" placeholder="owner@email.com">
      </div>
      <div class="btn-row">
        <button type="submit" class="btn btn-primary">Save &amp; Mark Traced</button>
        <button type="button" class="btn btn-ghost" onclick="closeSkipTraceModal()">Cancel</button>
      </div>
      <div id="skiptrace-error" style="color:var(--red);font-size:12px;margin-top:10px;display:none"></div>
    </form>
  </div>
</div>

<!-- ── OUTREACH LOG MODAL ─────────────────────────────────────────────────── -->
<div class="modal-overlay" id="outreach-overlay">
  <div class="modal">
    <button class="modal-close" onclick="closeOutreachModal()">×</button>
    <h3 id="outreach-modal-title">Log Outreach</h3>
    <div class="sub" id="outreach-modal-sub"></div>
    <div id="outreach-log-list" class="log-list"></div>
    <hr style="border:none;border-top:1px solid var(--border);margin:14px 0">
    <form id="outreach-form">
      <input type="hidden" id="outreach-prospect-id" value="">
      <div class="form-grid2">
        <div class="form-row">
          <label>Method</label>
          <select id="o-method" class="form-select">
            <option value="call">Phone Call</option>
            <option value="text">Text Message</option>
            <option value="email">Email</option>
            <option value="mail">Direct Mail</option>
            <option value="door">Door Knock</option>
          </select>
        </div>
        <div class="form-row">
          <label>Outcome</label>
          <select id="o-outcome" class="form-select">
            <option value="no_answer">No Answer</option>
            <option value="left_vm">Left Voicemail</option>
            <option value="spoke">Spoke with Owner</option>
            <option value="interested">Interested in Listing</option>
            <option value="not_interested">Not Interested</option>
            <option value="other">Other</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <label>Notes</label>
        <textarea id="o-notes" class="form-textarea" placeholder="What happened?…"></textarea>
      </div>
      <div class="btn-row">
        <button type="submit" class="btn btn-primary">Log Touch</button>
        <button type="button" class="btn btn-ghost" onclick="closeOutreachModal()">Cancel</button>
      </div>
      <div id="outreach-error" style="color:var(--red);font-size:12px;margin-top:10px;display:none"></div>
    </form>
  </div>
</div>

<script src="assets/vendor/leaflet/leaflet.js"></script>
<script src="assets/vendor/chartjs/chart.umd.min.js"></script>
<script>
const FARMS = <?= json_encode(array_map(fn($f) => ['id'=>$f['id'],'name'=>$f['name']], $farms)) ?>;
const MAP_PROSPECTS = <?= json_encode(array_map(fn($p) => [
    'id' => $p['id'], 'address' => $p['address'], 'city' => $p['city'], 'zip' => $p['zip'],
    'lat' => (float)$p['lat'], 'lon' => (float)$p['lon'], 'seller_score' => (int)$p['seller_score'],
    'owner_name' => $p['owner_name'], 'phone' => $p['phone'], 'email' => $p['email'],
    'skip_traced' => (int)$p['skip_traced'], 'absentee_owner' => (int)($p['absentee_owner'] ?? 0),
    'tax_delinquent' => (int)($p['tax_delinquent'] ?? 0), 'in_foreclosure' => (int)($p['in_foreclosure'] ?? 0),
    'is_vacant' => (int)($p['is_vacant'] ?? 0), 'est_value' => (int)$p['est_value'],
], array_filter($prospects, fn($p) => $p['lat'] && $p['lon']))) ?>;
const MAP_ZIPS = <?= json_encode(array_values(array_unique(array_merge(...array_map(fn($f) => json_decode($f['zip_codes'], true) ?: [], $farms))))) ?>;
const SCORE_BUCKETS = <?= json_encode($scoreBuckets) ?>;
const FARM_STATS = <?= json_encode($farmStats) ?>;

// ── Toast notifications (replaces alert() dialogs) ───────────────────────────
(function(){
  const el = document.createElement('div');
  el.id = 'li-toast';
  el.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9000;display:flex;flex-direction:column;gap:8px;pointer-events:none';
  document.body.appendChild(el);
})();
function liToast(msg, type='info', duration=4000) {
  const t = document.createElement('div');
  const bg = {success:'#2d7a0e',error:'#c0392b',info:'#2255cc',warn:'#a07221'}[type] || '#333';
  t.style.cssText = `background:${bg};color:#fff;padding:12px 18px;border-radius:8px;font-size:13px;font-weight:600;max-width:340px;box-shadow:0 4px 14px rgba(0,0,0,.2);opacity:0;transition:opacity .2s;pointer-events:auto`;
  t.textContent = msg;
  document.getElementById('li-toast').appendChild(t);
  requestAnimationFrame(() => { t.style.opacity = '1'; });
  setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 220); }, duration);
}

// ── Sidebar ──────────────────────────────────────────────────────────────────
function toggleSidebar() {
    const sb = document.getElementById('li-sidebar');
    sb.classList.toggle('collapsed');
    document.getElementById('li-collapse-btn').textContent = sb.classList.contains('collapsed') ? '»' : '« Collapse';
    setTimeout(() => { if (liMap) liMap.invalidateSize(); }, 200);
}

// ── Tabs (sidebar-driven) ──────────────────────────────────────────────────
const TAB_TITLES = {overview:'Overview', prospects:'Prospects', expireds:'Expired Pipeline', farms:'My Farms', map:'Map'};
function switchTab(key, el) {
    document.querySelectorAll('.li-nav-item').forEach(n => n.classList.remove('active'));
    document.querySelectorAll('.li-pane').forEach(p => p.classList.remove('active'));
    if (el) el.classList.add('active');
    else document.querySelector(`.li-nav-item[data-tab="${key}"]`)?.classList.add('active');
    const pane = document.getElementById('pane-' + key);
    if (pane) {
        pane.classList.add('active');
        document.getElementById('li-page-title').textContent = TAB_TITLES[key] || key;
        if (key === 'expireds') loadExpireds();
        if (key === 'map') initLiMap();
        if (key === 'overview') initCharts();
    }
}
function switchTabByKey(key) { switchTab(key, null); }

// ── Overview charts ──────────────────────────────────────────────────────────
let chartsInited = false;
function initCharts() {
    if (chartsInited) return;
    chartsInited = true;
    const scoreCanvas = document.getElementById('chart-score');
    const farmCanvas = document.getElementById('chart-farms');
    if (!scoreCanvas || !farmCanvas || typeof Chart === 'undefined') return;

    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = '#666';

    new Chart(scoreCanvas, {
        type: 'bar',
        data: {
            labels: Object.keys(SCORE_BUCKETS),
            datasets: [{
                data: Object.values(SCORE_BUCKETS),
                backgroundColor: ['#82C112','#82C112','#A07221','#A40000'],
                borderRadius: 4, barThickness: 36,
            }],
        },
        options: {
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f0f0f0' } }, x: { grid: { display: false } } },
        },
    });

    new Chart(farmCanvas, {
        type: 'bar',
        data: {
            labels: FARM_STATS.map(f => f.name),
            datasets: [
                { label: 'Candidates', data: FARM_STATS.map(f => f.count), backgroundColor: '#111', borderRadius: 4 },
                { label: 'Avg Score', data: FARM_STATS.map(f => f.avg_score), backgroundColor: '#82C112', borderRadius: 4 },
            ],
        },
        options: {
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f0f0f0' } }, x: { grid: { display: false } } },
        },
    });
}

// ── Interactive Map ───────────────────────────────────────────────────────────
let liMap = null;
let liMapInited = false;
function scoreColor(score) {
    return score >= 75 ? '#A40000' : (score >= 45 ? '#A07221' : '#5b8e0d');
}
function badgeRow(p) {
    const badges = [];
    if (p.tax_delinquent)  badges.push('<span class="chip" style="background:#FDE2E2;color:#A40000"><span class="chip-dot" style="background:#A40000"></span>Tax Delinquent</span>');
    if (p.in_foreclosure)  badges.push('<span class="chip" style="background:#FDE2E2;color:#A40000"><span class="chip-dot" style="background:#A40000"></span>Foreclosure</span>');
    if (p.is_vacant)       badges.push('<span class="chip" style="background:#F5EBD3;color:#7A5618"><span class="chip-dot" style="background:#A07221"></span>Vacant</span>');
    if (p.absentee_owner)  badges.push('<span class="chip" style="background:#F5EEFF;color:#7C3AED"><span class="chip-dot" style="background:#7C3AED"></span>Absentee</span>');
    return badges.length ? `<div style="margin:6px 0;display:flex;gap:4px;flex-wrap:wrap">${badges.join('')}</div>` : '';
}
function buildProspectPopup(p) {
    const contact = p.skip_traced
        ? `<div style="font-size:12px;margin-top:6px"><strong>${esc(p.owner_name || 'Unknown owner')}</strong>${p.phone ? '<br>'+esc(p.phone) : ''}${p.email ? '<br>'+esc(p.email) : ''}</div>`
        : `<button class="btn btn-ghost btn-sm" style="margin-top:6px" onclick='openSkipTraceModal(${p.id}, ${JSON.stringify(p.address)})'>Skip Trace</button>`;
    return `
      <div style="min-width:200px">
        <div style="font-weight:700">${esc(p.address)}</div>
        <div style="font-size:11px;color:var(--faint);margin-bottom:4px">${esc(p.city)} ${esc(p.zip)}</div>
        <div class="score-wrap" style="margin:6px 0">
          <div class="score-bar"><div class="score-fill" style="width:${p.seller_score}%;background:${scoreColor(p.seller_score)}"></div></div>
          <div class="score-num tnum">${p.seller_score}</div>
        </div>
        ${badgeRow(p)}
        ${contact}
      </div>`;
}
function buildListingPopup(l) {
    return `
      <div style="min-width:200px">
        <div style="font-weight:700">${esc(l.address)}</div>
        <div style="font-size:11px;color:var(--faint);margin-bottom:4px">${esc(l.city)} ${esc(l.zip)}</div>
        <div class="tnum" style="font-weight:700;margin:4px 0">${l.list_price ? '$'+Number(l.list_price).toLocaleString() : '—'}</div>
        ${l.listing_agent_name ? `<div style="font-size:12px;color:var(--faint)">Agent: ${esc(l.listing_agent_name)}</div>` : ''}
        <div style="font-size:11px;color:var(--faint);margin-top:4px">MLS #${esc(l.mls_number)}</div>
      </div>`;
}
function initLiMap() {
    if (liMapInited) { liMap.invalidateSize(); return; }
    liMapInited = true;
    const el = document.getElementById('li-map');
    if (!el) return;
    let lat = 33.460, lon = -79.130;
    if (MAP_PROSPECTS.length) { lat = MAP_PROSPECTS[0].lat; lon = MAP_PROSPECTS[0].lon; }
    liMap = L.map('li-map').setView([lat, lon], 12);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
        attribution: '&copy; OpenStreetMap contributors &copy; CARTO', maxZoom: 19,
    }).addTo(liMap);

    MAP_PROSPECTS.forEach(p => {
        L.circleMarker([p.lat, p.lon], {
            radius: 9, weight: 2, color: '#fff', fillColor: scoreColor(p.seller_score), fillOpacity: 0.9,
        }).addTo(liMap).bindPopup(buildProspectPopup(p));
    });

    document.getElementById('map-count').textContent = MAP_PROSPECTS.length + ' prospect' + (MAP_PROSPECTS.length !== 1 ? 's' : '') + ' on map';
    loadActiveListingsOnMap();
}
async function loadActiveListingsOnMap() {
    if (!liMap || !MAP_ZIPS.length) return;
    let total = 0;
    for (const zip of MAP_ZIPS) {
        try {
            const r = await fetch('api/listing_intel.php?action=get_active_listings&zip=' + encodeURIComponent(zip), {credentials:'same-origin'});
            const d = await r.json();
            if (!d.ok || !d.listings) continue;
            d.listings.forEach(l => {
                const icon = L.icon({
                    iconUrl: 'assets/vendor/leaflet/images/marker-icon.png',
                    iconRetinaUrl: 'assets/vendor/leaflet/images/marker-icon-2x.png',
                    shadowUrl: 'assets/vendor/leaflet/images/marker-shadow.png',
                    iconSize: [25,41], iconAnchor: [12,41], popupAnchor: [1,-34], shadowSize: [41,41],
                });
                L.marker([l.lat, l.lon], {icon}).addTo(liMap).bindPopup(buildListingPopup(l));
                total++;
            });
        } catch(e) { /* MLS overlay is best-effort; prospect pins already rendered */ }
    }
    if (total) document.getElementById('map-count').textContent += ` · ${total} active MLS listing${total !== 1 ? 's' : ''}`;
}
async function seedDemoData() {
    try {
        const r = await fetch('api/listing_intel.php', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'seed_demo_data'})});
        const d = await r.json();
        if (d.ok) { liToast('Sample data loaded — 40 demo prospects added.', 'success'); setTimeout(() => location.reload(), 1000); }
        else throw new Error(d.error || 'Failed');
    } catch(e) { liToast('Error: ' + e.message, 'error'); }
}
async function clearDemoData() {
    if (!confirm('Remove all sample/demo data?')) return;
    try {
        const r = await fetch('api/listing_intel.php', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'clear_demo_data'})});
        const d = await r.json();
        if (d.ok) { liToast('Sample data cleared.', 'success'); setTimeout(() => location.reload(), 800); }
        else throw new Error(d.error || 'Failed');
    } catch(e) { liToast('Error: ' + e.message, 'error'); }
}

// ── Farm modal ───────────────────────────────────────────────────────────────
function openFarmModal(id) {
    document.getElementById('farm-id').value = '';
    document.getElementById('farm-name').value = '';
    document.getElementById('farm-state').value = '';
    document.getElementById('farm-zips').value = '';
    document.getElementById('farm-hoods').value = '';
    document.getElementById('farm-notes').value = '';
    document.getElementById('farm-modal-title').textContent = 'New Farm Area';
    document.getElementById('farm-submit').textContent = 'Save Farm';
    document.getElementById('farm-error').style.display = 'none';
    document.getElementById('farm-overlay').classList.add('open');
}
function editFarm(f) {
    document.getElementById('farm-id').value = f.id;
    document.getElementById('farm-name').value = f.name;
    document.getElementById('farm-state').value = f.state || '';
    document.getElementById('farm-zips').value = (JSON.parse(f.zip_codes||'[]')).join(', ');
    document.getElementById('farm-hoods').value = (JSON.parse(f.neighborhoods||'[]')).join(', ');
    document.getElementById('farm-notes').value = f.notes || '';
    document.getElementById('farm-modal-title').textContent = 'Edit Farm';
    document.getElementById('farm-submit').textContent = 'Update Farm';
    document.getElementById('farm-error').style.display = 'none';
    document.getElementById('farm-overlay').classList.add('open');
}
function closeFarmModal() { document.getElementById('farm-overlay').classList.remove('open'); }
document.getElementById('farm-overlay').addEventListener('click', e => { if (e.target.id === 'farm-overlay') closeFarmModal(); });
document.getElementById('farm-form').addEventListener('submit', async e => {
    e.preventDefault();
    const btn = document.getElementById('farm-submit');
    btn.disabled = true; btn.textContent = 'Saving…';
    const body = {
        action: 'save_farm',
        id: document.getElementById('farm-id').value,
        name: document.getElementById('farm-name').value.trim(),
        state: document.getElementById('farm-state').value,
        zip_codes: document.getElementById('farm-zips').value.split(',').map(s=>s.trim()).filter(Boolean),
        neighborhoods: document.getElementById('farm-hoods').value.split(',').map(s=>s.trim()).filter(Boolean),
        notes: document.getElementById('farm-notes').value.trim(),
    };
    try {
        const r = await fetch('api/listing_intel.php', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
        const d = await r.json();
        if (d.ok) { location.reload(); } else { throw new Error(d.error || 'Save failed'); }
    } catch(err) {
        document.getElementById('farm-error').textContent = err.message;
        document.getElementById('farm-error').style.display = 'block';
        btn.disabled = false; btn.textContent = 'Save Farm';
    }
});
async function deleteFarm(id, name) {
    if (!confirm(`Delete farm "${name}"? Prospects linked to it will become unfiled.`)) return;
    const r = await fetch('api/listing_intel.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete_farm',id})});
    const d = await r.json();
    if (d.ok) location.reload();
    else alert(d.error || 'Delete failed');
}

// ── Prospect modal ───────────────────────────────────────────────────────────
function openProspectModal(prefill) {
    document.getElementById('prospect-id').value = '';
    document.getElementById('p-owner').value = prefill?.owner_name || '';
    document.getElementById('p-address').value = prefill?.address || '';
    document.getElementById('p-city').value = prefill?.city || '';
    document.getElementById('p-zip').value = prefill?.zip || '';
    document.getElementById('p-phone').value = prefill?.phone || '';
    document.getElementById('p-email').value = prefill?.email || '';
    document.getElementById('p-source').value = prefill?.source || 'manual';
    document.getElementById('p-status').value = prefill?.status || 'new';
    document.getElementById('p-score').value = prefill?.seller_score || '';
    document.getElementById('p-value').value = prefill?.est_value || '';
    document.getElementById('p-mls').value = prefill?.mls_number || '';
    document.getElementById('p-notes').value = prefill?.notes || '';
    document.getElementById('p-farm').value = prefill?.farm_id || '';
    document.getElementById('prospect-modal-title').textContent = 'Add Prospect';
    document.getElementById('prospect-error').style.display = 'none';
    document.getElementById('prospect-overlay').classList.add('open');
}
function editProspect(p) {
    openProspectModal(p);
    document.getElementById('prospect-id').value = p.id;
    document.getElementById('prospect-modal-title').textContent = 'Edit Prospect';
}
function closeProspectModal() { document.getElementById('prospect-overlay').classList.remove('open'); }
document.getElementById('prospect-overlay').addEventListener('click', e => { if (e.target.id === 'prospect-overlay') closeProspectModal(); });
document.getElementById('prospect-form').addEventListener('submit', async e => {
    e.preventDefault();
    const body = {
        action: 'save_prospect',
        id: document.getElementById('prospect-id').value,
        farm_id: document.getElementById('p-farm').value,
        owner_name: document.getElementById('p-owner').value.trim(),
        address: document.getElementById('p-address').value.trim(),
        city: document.getElementById('p-city').value.trim(),
        zip: document.getElementById('p-zip').value.trim(),
        phone: document.getElementById('p-phone').value.trim(),
        email: document.getElementById('p-email').value.trim(),
        source: document.getElementById('p-source').value,
        status: document.getElementById('p-status').value,
        seller_score: parseInt(document.getElementById('p-score').value) || 0,
        est_value: parseInt(document.getElementById('p-value').value) || 0,
        mls_number: document.getElementById('p-mls').value.trim(),
        notes: document.getElementById('p-notes').value.trim(),
    };
    try {
        const r = await fetch('api/listing_intel.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
        const d = await r.json();
        if (d.ok) { location.reload(); } else { throw new Error(d.error || 'Save failed'); }
    } catch(err) {
        document.getElementById('prospect-error').textContent = err.message;
        document.getElementById('prospect-error').style.display = 'block';
    }
});

// ── Outreach modal ───────────────────────────────────────────────────────────
async function openOutreachModal(prospectId, ownerName) {
    document.getElementById('outreach-prospect-id').value = prospectId;
    document.getElementById('outreach-modal-title').textContent = 'Log Outreach';
    document.getElementById('outreach-modal-sub').textContent = ownerName;
    document.getElementById('o-method').value = 'call';
    document.getElementById('o-outcome').value = 'no_answer';
    document.getElementById('o-notes').value = '';
    document.getElementById('outreach-error').style.display = 'none';
    document.getElementById('outreach-log-list').innerHTML = '<div style="color:var(--faint);font-size:12px">Loading…</div>';
    document.getElementById('outreach-overlay').classList.add('open');

    try {
        const r = await fetch('api/listing_intel.php?action=get_outreach&prospect_id='+prospectId, {credentials:'same-origin'});
        const d = await r.json();
        const list = document.getElementById('outreach-log-list');
        if (!d.items || !d.items.length) {
            list.innerHTML = '<div style="color:var(--faint);font-size:12px;font-style:italic">No touches logged yet.</div>';
        } else {
            list.innerHTML = d.items.map(o => `
            <div class="log-item">
              <div class="log-item-top">
                <span class="log-method">${esc(o.method)}</span>
                <span class="log-outcome">${esc(outcomeLabel(o.outcome))}</span>
                <span class="log-date">${esc(o.logged_at?.slice(0,10) || '')}</span>
              </div>
              ${o.notes ? `<div class="log-notes">${esc(o.notes)}</div>` : ''}
            </div>`).join('');
        }
    } catch(e) { document.getElementById('outreach-log-list').innerHTML = ''; }
}
function outcomeLabel(v) {
    return {no_answer:'No Answer',left_vm:'Left VM',spoke:'Spoke w/ Owner',interested:'Interested!',not_interested:'Not Interested',other:'Other'}[v] || v;
}
function esc(s) { return String(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
function closeOutreachModal() { document.getElementById('outreach-overlay').classList.remove('open'); }
document.getElementById('outreach-overlay').addEventListener('click', e => { if (e.target.id === 'outreach-overlay') closeOutreachModal(); });
document.getElementById('outreach-form').addEventListener('submit', async e => {
    e.preventDefault();
    const body = {
        action: 'log_outreach',
        prospect_id: document.getElementById('outreach-prospect-id').value,
        method: document.getElementById('o-method').value,
        outcome: document.getElementById('o-outcome').value,
        notes: document.getElementById('o-notes').value.trim(),
    };
    try {
        const r = await fetch('api/listing_intel.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
        const d = await r.json();
        if (d.ok) { closeOutreachModal(); location.reload(); } else { throw new Error(d.error||'Save failed'); }
    } catch(err) {
        document.getElementById('outreach-error').textContent = err.message;
        document.getElementById('outreach-error').style.display = 'block';
    }
});

// ── Sync farm prospects ───────────────────────────────────────────────────────
async function syncProspects() {
    const btn = document.getElementById('sync-btn');
    if (!btn) return;
    btn.disabled = true;
    btn.innerHTML = '&#8635; Syncing…';
    try {
        const r = await fetch('api/listing_intel.php', {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'sync_prospects'}),
        });
        const d = await r.json();
        if (d.ok) {
            liToast(`Synced: ${d.inserted} new, ${d.updated} updated (${d.total} MLS records scanned)`, 'success', 5000);
            setTimeout(() => location.reload(), 1200);
        } else {
            liToast('Sync failed: ' + (d.error || 'Unknown error'), 'error', 6000);
            btn.disabled = false; btn.innerHTML = '&#8635; Sync Farm Prospects';
        }
    } catch(e) {
        liToast('Sync error: ' + e.message, 'error', 6000);
        btn.disabled = false; btn.innerHTML = '&#8635; Sync Farm Prospects';
    }
}

// ── Skip trace modal ─────────────────────────────────────────────────────────
function openSkipTraceModal(prospectId, address) {
    document.getElementById('st-prospect-id').value = prospectId;
    document.getElementById('skiptrace-modal-sub').textContent = address;
    document.getElementById('st-owner').value = '';
    document.getElementById('st-phone').value = '';
    document.getElementById('st-email').value = '';
    document.getElementById('skiptrace-error').style.display = 'none';
    document.getElementById('skiptrace-overlay').classList.add('open');
}
function closeSkipTraceModal() { document.getElementById('skiptrace-overlay').classList.remove('open'); }
document.getElementById('skiptrace-overlay').addEventListener('click', e => { if (e.target.id === 'skiptrace-overlay') closeSkipTraceModal(); });
document.getElementById('skiptrace-form').addEventListener('submit', async e => {
    e.preventDefault();
    const body = {
        action: 'mark_skip_traced',
        prospect_id: document.getElementById('st-prospect-id').value,
        owner_name: document.getElementById('st-owner').value.trim(),
        phone: document.getElementById('st-phone').value.trim(),
        email: document.getElementById('st-email').value.trim(),
    };
    try {
        const r = await fetch('api/listing_intel.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
        const d = await r.json();
        if (d.ok) { closeSkipTraceModal(); location.reload(); }
        else throw new Error(d.error || 'Save failed');
    } catch(err) {
        document.getElementById('skiptrace-error').textContent = err.message;
        document.getElementById('skiptrace-error').style.display = 'block';
    }
});

// ── Prospect filters ─────────────────────────────────────────────────────────
function filterProspects() {
    const q  = document.getElementById('prospect-search').value.toLowerCase();
    const st = document.getElementById('prospect-status-filter').value;
    const tr = document.getElementById('prospect-trace-filter').value;
    document.querySelectorAll('#prospect-table tbody tr').forEach(row => {
        const match = (!q  || row.dataset.search.includes(q))
            && (!st || row.dataset.status === st)
            && (!tr || row.dataset.traced === tr);
        row.style.display = match ? '' : 'none';
    });
}

// ── Sortable columns ─────────────────────────────────────────────────────────
let sortState = { field: 'score', dir: 'desc' };
function sortTable(field) {
    const tbody = document.querySelector('#prospect-table tbody');
    if (!tbody) return;
    const dir = (sortState.field === field && sortState.dir === 'desc') ? 'asc' : 'desc';
    sortState = { field, dir };

    document.querySelectorAll('#prospect-table th.sortable').forEach(th => {
        th.classList.toggle('sorted', th.dataset.sort === field);
        const arrow = th.querySelector('.arrow');
        if (th.dataset.sort === field) arrow.textContent = dir === 'desc' ? '▼' : '▲';
        else arrow.textContent = '▼';
    });

    const rows = Array.from(tbody.querySelectorAll('tr'));
    const numeric = ['years','score','value'].includes(field);
    rows.sort((a, b) => {
        let av = a.dataset['sort' + field.charAt(0).toUpperCase() + field.slice(1)] ?? '';
        let bv = b.dataset['sort' + field.charAt(0).toUpperCase() + field.slice(1)] ?? '';
        if (numeric) { av = Number(av); bv = Number(bv); }
        if (av < bv) return dir === 'asc' ? -1 : 1;
        if (av > bv) return dir === 'asc' ? 1 : -1;
        return 0;
    });
    rows.forEach(r => tbody.appendChild(r));
}

// ── Bulk actions ─────────────────────────────────────────────────────────────
function getSelectedIds() {
    return Array.from(document.querySelectorAll('.row-check:checked')).map(cb => cb.value);
}
function toggleSelectAll(cb) {
    document.querySelectorAll('#prospect-table tbody tr').forEach(row => {
        if (row.style.display !== 'none') row.querySelector('.row-check').checked = cb.checked;
    });
    updateBulkBar();
}
function updateBulkBar() {
    const ids = getSelectedIds();
    const bar = document.getElementById('bulk-bar');
    document.getElementById('bulk-count').textContent = `${ids.length} selected`;
    bar.classList.toggle('show', ids.length > 0);
}
function clearSelection() {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = false);
    document.getElementById('select-all').checked = false;
    updateBulkBar();
}
async function applyBulkStatus() {
    const ids = getSelectedIds();
    if (!ids.length) return;
    const status = document.getElementById('bulk-status-select').value;
    try {
        const r = await fetch('api/listing_intel.php', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'bulk_update_status', ids, status})});
        const d = await r.json();
        if (d.ok) { liToast(`Updated ${d.updated} prospect${d.updated !== 1 ? 's' : ''}.`, 'success'); setTimeout(() => location.reload(), 800); }
        else throw new Error(d.error || 'Failed');
    } catch(e) { liToast('Error: ' + e.message, 'error'); }
}
function exportSelected() {
    const ids = new Set(getSelectedIds());
    if (!ids.size) return;
    const rows = [['Address','City','Zip','Owner','Phone','Email','Score','Est. Value','Status']];
    document.querySelectorAll('#prospect-table tbody tr').forEach(row => {
        if (!ids.has(row.dataset.id)) return;
        rows.push([
            row.dataset.sortAddress || '', '', row.dataset.status || '',
            row.dataset.sortOwner || '', '', '', row.dataset.sortScore || '', row.dataset.sortValue || '', row.dataset.status || '',
        ]);
    });
    const csv = rows.map(r => r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n');
    const blob = new Blob([csv], {type: 'text/csv'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'listing-intel-selected-' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}

// ── Expired pipeline (server-side Trestle proxy) ─────────────────────────────
let expData = [];
async function loadExpireds() {
    const zip     = document.getElementById('exp-zip')?.value  || '';
    const days    = document.getElementById('exp-days')?.value || '90';
    const loading = document.getElementById('exp-loading');
    const tbl     = document.getElementById('exp-table');
    const empty   = document.getElementById('exp-empty');
    if (!loading) return;

    loading.style.display = 'block';
    loading.textContent   = 'Loading expired listings from MLS…';
    if (tbl)   tbl.hidden   = true;
    if (empty) empty.hidden = true;

    try {
        const params = new URLSearchParams({action: 'get_expireds', days});
        if (zip) params.set('zip', zip);
        const r = await fetch('api/listing_intel.php?' + params, {credentials: 'same-origin'});
        const d = await r.json();
        if (!d.ok) throw new Error(d.error || 'Unknown error');
        expData = d.listings || [];
        renderExpireds();
        document.getElementById('exp-count').textContent = expData.length + ' expired listing' + (expData.length !== 1 ? 's' : '');
    } catch(err) {
        loading.textContent = 'Could not load expired listings: ' + err.message;
    }
}
function renderExpireds() {
    const tbl = document.getElementById('exp-table');
    const empty = document.getElementById('exp-empty');
    const loading = document.getElementById('exp-loading');
    loading.style.display = 'none';
    if (!expData.length) { empty.hidden = false; return; }
    const tbody = document.getElementById('exp-body');
    tbody.innerHTML = expData.map(l => `
    <tr>
      <td><div style="font-weight:600">${esc(l.address || '')}</div></td>
      <td>${esc(l.city || '')}</td>
      <td style="font-size:12px;color:var(--faint)">${esc(l.mls_number || '')}</td>
      <td class="num tnum" style="font-weight:700">${l.list_price ? '$'+Number(l.list_price).toLocaleString() : '—'}</td>
      <td style="font-size:12px">${l.days_on_market ?? '—'} days</td>
      <td style="font-size:12px;color:var(--faint)">${esc(l.expiration_date || '')}</td>
      <td style="font-size:12px">${esc(l.listing_agent_name || '')}</td>
      <td>
        <button class="btn btn-ghost btn-sm" onclick='addExpiredAsProspect(${JSON.stringify(l).replace(/'/g,"&#39;")})'>+ Add</button>
      </td>
    </tr>`).join('');
    tbl.hidden = false;
}
function filterExpireds() {
    const q = document.getElementById('exp-search').value.toLowerCase();
    document.querySelectorAll('#exp-body tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}
function addExpiredAsProspect(listing) {
    openProspectModal({
        owner_name: listing.owner_name || '',
        address: listing.address || '',
        city: listing.city || '',
        zip: listing.zip || '',
        source: 'expired',
        mls_number: listing.mls_number || '',
        est_value: listing.list_price || 0,
    });
}

// Charts render immediately since Overview is the default active tab.
initCharts();
</script>
</body>
</html>
