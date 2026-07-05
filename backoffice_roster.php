<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
$perms = current_perms();
if (empty($perms['isAdmin'])) {
    header('Location: index.php'); exit;
}

$today  = date('Y-m-d');
$warn60 = date('Y-m-d', strtotime('+60 days'));

// Pull active roster rows only, group by state then market_center.
$rows = local_db()
    ->query("SELECT * FROM innovate_roster WHERE active=1 ORDER BY state_code, market_center, agent_name")
    ->fetchAll(PDO::FETCH_ASSOC);

define('MC_UNASSIGNED', '__unassigned__');

$byState = [];
foreach ($rows as $r) {
    $st = $r['state_code'];
    $mc = $r['market_center'] !== '' ? $r['market_center'] : MC_UNASSIGNED;
    $byState[$st][$mc][] = $r;
}

// Unique agent count (agents in multiple states count once).
$uniqueTotal = (int)local_db()
    ->query("SELECT COUNT(DISTINCT agent_name) FROM innovate_roster WHERE active=1")
    ->fetchColumn();

// Summary counts per state (unique within each state). MC count excludes the unassigned bucket.
$stateMeta = [];
foreach ($byState as $st => $mcs) {
    $total = 0; $exp = 0; $warn = 0; $seen = [];
    foreach ($mcs as $mc => $agents) {
        foreach ($agents as $a) {
            $key = strtolower($a['agent_name']);
            if (isset($seen[$key])) continue;
            $seen[$key] = true; $total++;
            if ($a['license_exp'] && $a['license_exp'] <= $today) $exp++;
            elseif ($a['license_exp'] && $a['license_exp'] <= $warn60) $warn++;
        }
    }
    $namedMcCount = count(array_filter(array_keys($mcs), fn($k) => $k !== MC_UNASSIGNED));
    $stateMeta[$st] = ['total'=>$total,'mc_count'=>$namedMcCount,'expired'=>$exp,'expiring'=>$warn];
}

$stateNames = [
    'FL'=>'Florida','GA'=>'Georgia','SC'=>'South Carolina','NC'=>'North Carolina',
    'TN'=>'Tennessee','VA'=>'Virginia','MD'=>'Maryland','DE'=>'Delaware',
    'NJ'=>'New Jersey','PA'=>'Pennsylvania','OH'=>'Ohio','MA'=>'Massachusetts',
    'RI'=>'Rhode Island','NH'=>'New Hampshire',
];
$stateTiers = ['FL'=>1,'VA'=>1,'DE'=>1,'RI'=>1,'NH'=>1,'OH'=>1,'NC'=>2,'GA'=>2,'PA'=>2,'SC'=>3,'MD'=>3,'TN'=>3,'NJ'=>3,'MA'=>3];
$tierLabel  = [1=>'Auto',2=>'Purchase',3=>'FOIA'];
$tierClass  = [1=>'tier1',2=>'tier2',3=>'tier3'];

$activeState = $_GET['state'] ?? (array_key_first($byState) ?: 'SC');
if (!isset($byState[$activeState])) $activeState = array_key_first($byState) ?: 'SC';

// MC list for the active state (for the add-agent datalist) — excludes the unassigned bucket
$mcList = [];
if (isset($byState[$activeState])) {
    $mcList = array_filter(array_keys($byState[$activeState]), fn($k) => $k !== MC_UNASSIGNED);
    $mcList = array_values($mcList);
}

// Build ordered group for the active state:
//   1. Named MCs in market_centers table order (so the master list order is respected)
//   2. Any MC names in the roster that aren't in the master list (orphaned text values)
//   3. Unassigned agents last
$activeGroups = [];
if (isset($byState[$activeState])) {
    $statePool = $byState[$activeState];
    // 1. MCs in master-list order
    $masterNames = array_column(
        local_db()->query("SELECT name FROM market_centers WHERE enabled=1 ORDER BY state_code, sort_ord, name")->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    foreach ($masterNames as $n) {
        if (isset($statePool[$n])) { $activeGroups[$n] = $statePool[$n]; }
    }
    // 2. Orphaned MC names (in roster but not in master list)
    foreach ($statePool as $k => $v) {
        if ($k !== MC_UNASSIGNED && !isset($activeGroups[$k])) { $activeGroups[$k] = $v; }
    }
    // 3. Unassigned last
    if (isset($statePool[MC_UNASSIGNED])) { $activeGroups[MC_UNASSIGNED] = $statePool[MC_UNASSIGNED]; }
}

// MC options for bulk-assign dropdown (from market_centers table)
$mcOptsAssign = local_db()
    ->query("SELECT slug, name, state_code FROM market_centers WHERE enabled=1 ORDER BY state_code, sort_ord, name")
    ->fetchAll(PDO::FETCH_ASSOC);


// BIC options for bulk-assign dropdown
$bicOpts = local_db()
    ->query("SELECT email FROM agent_roles WHERE role='bic' ORDER BY email")
    ->fetchAll(PDO::FETCH_COLUMN);

// MC Leader options for add-MC modal
$mcLeaderOpts = local_db()
    ->query("SELECT email FROM agent_roles WHERE role='mc_leader' ORDER BY email")
    ->fetchAll(PDO::FETCH_COLUMN);

// MC metadata map: display name → {slug, bic_email, mc_leader_email} for heading chips
$mcMeta = [];
foreach (local_db()->query("SELECT slug, name, bic_email, mc_leader_email FROM market_centers")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $mcMeta[$row['name']] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Agent Roster — AgentEdge</title>
<link rel="stylesheet" href="assets/app.css">
<style>
.bo-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
.roster-summary{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}
.rs-tile{background:#fff;border:1px solid var(--border);border-radius:10px;padding:14px 18px;min-width:120px}
.rs-tile .rs-num{font-size:26px;font-weight:800;line-height:1.1}
.rs-tile .rs-lbl{font-size:11px;color:var(--faint);font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-top:2px}
.rs-tile.red   .rs-num{color:var(--red,#c0392b)}
.rs-tile.amber .rs-num{color:#c87800}
.rs-tile.green .rs-num{color:var(--green-d,#5b8e0d)}
#prod-loading{font-size:11px;color:var(--faint);font-style:italic;align-self:center}
.state-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:24px}
.state-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:14px 16px;
            cursor:pointer;transition:border-color .15s,box-shadow .15s;text-decoration:none;color:inherit;display:block}
.state-card:hover{border-color:var(--green);box-shadow:0 2px 8px rgba(0,0,0,.06)}
.state-card.active{border-color:var(--green);box-shadow:0 0 0 3px rgba(130,193,18,.18)}
.sc-code{font-size:28px;font-weight:900;letter-spacing:-.01em;line-height:1;margin-bottom:2px}
.sc-name{font-size:11px;color:var(--faint);font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px}
.sc-stats{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.sc-stat{font-size:12px;color:var(--muted)}
.sc-stat strong{color:var(--ink);font-weight:700}
.tier-badge{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;padding:2px 7px;border-radius:4px;margin-left:auto}
.tier1{background:#e8f5e9;color:#2e7d32}
.tier2{background:#fff3e0;color:#e65100}
.tier3{background:#fce4ec;color:#c62828}
.exp-dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:3px;vertical-align:middle}
.exp-dot.red{background:#c0392b}
.exp-dot.amber{background:#c87800}
.detail-panel{background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden}
.detail-header{padding:14px 18px 12px;border-bottom:1px solid var(--border);
               display:flex;align-items:baseline;gap:12px;flex-wrap:wrap}
.detail-title{font-size:18px;font-weight:800}
.detail-sub{font-size:12px;color:var(--faint)}
.mc-heading{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;
            color:var(--faint);padding:10px 18px 8px;border-top:1px solid var(--border);
            background:#fafbfa;display:flex;align-items:center;gap:10px;flex-wrap:wrap;
            cursor:pointer;user-select:none}
.mc-heading:hover{background:#f2f6ee}
.mc-heading span{flex:1}
.mc-heading-unassigned{background:#fafafa;border-top:2px dashed #ddd;color:#bbb}
.mc-heading-unassigned:hover{background:#f5f5f5}
.mc-chevron{flex-shrink:0;width:14px;height:14px;display:inline-flex;align-items:center;
            justify-content:center;color:var(--faint);font-size:9px;transition:transform .18s;
            font-style:normal}
.mc-heading.mc-open .mc-chevron{transform:rotate(90deg)}
.mc-group-content{display:none}
.mc-group-content.mc-open{display:block}
.mc-role-chip{font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px;
              white-space:nowrap;text-transform:none;letter-spacing:0}
.mc-bic-chip{background:#fff4e0;color:#a07221}
.mc-leader-chip{background:#eef5e8;color:#5b8e0d}
.agent-table{width:100%;border-collapse:collapse;font-size:13px}
.agent-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;
                color:var(--faint);padding:8px 18px;text-align:left;white-space:nowrap}
.agent-table td{padding:8px 18px;border-top:1px solid var(--border);vertical-align:middle}
.agent-table tr:hover td{background:#f8faf5}
.exp-badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;
           padding:2px 8px;border-radius:4px;white-space:nowrap}
.exp-badge.expired {background:#fdecea;color:#c0392b}
.exp-badge.expiring{background:#fff3e0;color:#c87800}
.exp-badge.ok      {background:#e8f5e9;color:#2e7d32}
.exp-badge.none    {background:#f0f0f0;color:#999;font-weight:400}
.prod-vol  {font-weight:700;color:#111;white-space:nowrap}
.prod-deals{font-size:11px;color:var(--muted);white-space:nowrap}
.prod-none {color:var(--faint);font-size:11px}


/* Remove button */
.btn-remove{background:none;border:none;color:var(--faint);font-size:14px;cursor:pointer;
            padding:2px 6px;border-radius:4px;line-height:1;opacity:.5;transition:opacity .15s}
.btn-remove:hover{opacity:1;color:var(--red,#c0392b);background:#fdecea}

/* Move-MC per-row */
.btn-move-mc{background:none;border:none;color:var(--faint);font-size:11px;cursor:pointer;
             padding:2px 6px;border-radius:4px;opacity:.45;transition:opacity .15s;white-space:nowrap}
.btn-move-mc:hover{opacity:1;background:#f0f5e8;color:#5b8e0d}
.move-mc-inline{display:none;align-items:center;gap:5px}
.move-mc-inline.open{display:flex}
.move-mc-select{font-size:11px;padding:3px 6px;border:1px solid var(--green);border-radius:4px;
                background:#fff;max-width:160px}
.btn-move-save{padding:3px 9px;background:var(--green);color:#111;border:0;border-radius:4px;
               font-size:11px;font-weight:700;cursor:pointer}
.btn-move-cancel{padding:3px 7px;border:1px solid #ccc;background:#fff;color:#555;
                 border-radius:4px;font-size:11px;cursor:pointer}

/* Retention badge + inline editor */
.retain-badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;
              padding:2px 8px;border-radius:4px;white-space:nowrap;border:0;cursor:pointer;font-family:inherit}
.retain-badge.secure {background:#e8f5e9;color:#2e7d32}
.retain-badge.watch  {background:#fff3e0;color:#c87800}
.retain-badge.at_risk{background:#fdecea;color:#c0392b}
.retain-edit-inline{display:none;align-items:center;gap:5px;margin-top:5px}
.retain-edit-inline.open{display:flex;flex-wrap:wrap}
.retain-edit-select{font-size:11px;padding:3px 6px;border:1px solid var(--green);border-radius:4px;background:#fff}
.retain-edit-notes{font-size:11px;padding:3px 6px;border:1px solid #ccc;border-radius:4px;min-width:140px}
.btn-retain-save{padding:3px 9px;background:var(--green);color:#111;border:0;border-radius:4px;
                 font-size:11px;font-weight:700;cursor:pointer}
.btn-retain-cancel{padding:3px 7px;border:1px solid #ccc;background:#fff;color:#555;
                   border-radius:4px;font-size:11px;cursor:pointer}
.mc-retain-chip.secure {background:#eef5e8;color:#5b8e0d}
.mc-retain-chip.watch  {background:#fff4e0;color:#a07221}
.mc-retain-chip.at_risk{background:#fdecea;color:#c0392b}

/* Add-agent button */
.btn-add-agent{font-size:11px;font-weight:700;padding:4px 12px;background:var(--green);color:#111;
               border:0;border-radius:6px;cursor:pointer;white-space:nowrap;text-decoration:none}
.btn-add-agent:hover{background:var(--green-d,#5b8e0d);color:#fff}

/* Checkboxes */
.agent-cb,.mc-sel-all{accent-color:#82C112;width:14px;height:14px;cursor:pointer;flex-shrink:0}
.cb-cell{width:32px;padding-left:14px!important;padding-right:4px!important}

/* MC Edit & Delete */
.btn-edit-mc{padding:3px 9px;border:1px solid var(--border);background:#fff;color:#444;
             border-radius:4px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap}
.btn-edit-mc:hover{border-color:var(--green);color:#5b8e0d;background:#f0f8e8}
.btn-delete-mc{padding:3px 9px;border:1px solid #fcc;background:#fff;color:#c00;
               border-radius:4px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap}
.btn-delete-mc:hover{background:#fff0f0}
.mc-edit-panel{background:#f4fbec;border-top:2px solid var(--green);padding:14px 18px;display:none}
.mc-edit-panel.open{display:block}
.mc-edit-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px}
.mc-edit-field{display:flex;flex-direction:column;gap:3px}
.mc-edit-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint)}
.mc-edit-input{padding:6px 9px;border:1px solid var(--border);border-radius:5px;font-size:12px;background:#fff;min-width:200px}
.mc-edit-input:focus{outline:2px solid var(--green);border-color:var(--green)}
.mc-edit-select{padding:6px 9px;border:1px solid var(--border);border-radius:5px;font-size:12px;background:#fff;min-width:200px}
.mc-edit-select:focus{outline:2px solid var(--green);border-color:var(--green)}
.mc-edit-actions{display:flex;gap:7px}
.mc-edit-save{padding:6px 16px;background:var(--green);color:#111;border:0;border-radius:5px;
              font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap}
.mc-edit-save:hover{background:var(--green-d,#5b8e0d);color:#fff}
.mc-edit-cancel{padding:6px 12px;border:1px solid #ccc;background:#fff;color:#555;
                border-radius:5px;font-size:12px;cursor:pointer}

/* Bulk action bar */
#bulk-bar{position:fixed;bottom:0;left:0;right:0;background:#1a1a1a;color:#fff;
          padding:12px 20px;display:none;align-items:center;gap:12px;flex-wrap:wrap;
          z-index:500;box-shadow:0 -2px 12px rgba(0,0,0,.25)}
#bulk-bar.open{display:flex}
#bulk-count{font-size:13px;font-weight:700;white-space:nowrap;min-width:80px}
.bulk-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
            color:#aaa;white-space:nowrap}
.bulk-select{padding:6px 10px;border-radius:5px;border:1px solid #444;background:#2a2a2a;
             color:#fff;font-size:12px;min-width:160px}
.btn-bulk-assign{padding:8px 18px;background:#82C112;color:#111;border:0;border-radius:5px;
                 font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap}
.btn-bulk-assign:hover{background:#6da00f}
.btn-bulk-clear{padding:8px 12px;background:none;border:1px solid #555;color:#ccc;
                border-radius:5px;font-size:12px;cursor:pointer}
.btn-bulk-clear:hover{border-color:#888;color:#fff}

/* Modal */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;
               align-items:center;justify-content:center;z-index:999}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:12px;width:min(440px,95vw);padding:24px;
       max-height:90vh;overflow-y:auto;position:relative}
.modal h3{margin:0 0 4px;font-size:16px;font-weight:800}
.modal .modal-sub{font-size:12px;color:var(--faint);margin-bottom:18px}
.modal-close{position:absolute;top:14px;right:16px;background:none;border:none;
             font-size:20px;cursor:pointer;color:#888;line-height:1}
.mf-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
          color:var(--faint);display:block;margin-bottom:4px;margin-top:14px}
.mf-label:first-of-type{margin-top:0}
.mf-input{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:7px;
          font-size:13px;background:#fafafa;box-sizing:border-box}
.mf-input:focus{outline:2px solid var(--green);border-color:var(--green)}
.mf-btns{display:flex;gap:8px;margin-top:20px}
.mf-save{flex:1;padding:10px;background:var(--green);color:#111;border:0;border-radius:7px;
         font-weight:800;font-size:13px;cursor:pointer}
.mf-save:hover{background:var(--green-d,#5b8e0d);color:#fff}
.mf-cancel{padding:10px 16px;border:1px solid var(--border);background:#fff;color:#555;
           font-size:13px;border-radius:7px;cursor:pointer}
.mf-err{font-size:12px;color:var(--red,#c0392b);margin-top:8px;display:none}
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('backoffice_roster', $agent); ?>
<div class="content">
  <div class="content-top">
    <div>
      <div class="bo-eyebrow">Back Office</div>
      <div class="content-title">Agent Roster</div>
    </div>
    <div class="content-hello" style="display:flex;align-items:center;gap:12px">
      <?= $uniqueTotal ?> unique agents across <?= count($byState) ?> states
      <a href="backoffice_roster_changes.php" class="btn-add-agent" style="background:#f0f0f0;color:#555">
        Weekly Changes →
      </a>
    </div>
  </div>
  <div class="wrap">

    <!-- Summary bar -->
    <div class="roster-summary">
      <div class="rs-tile">
        <div class="rs-num"><?= $uniqueTotal ?></div>
        <div class="rs-lbl">Unique Agents</div>
      </div>
      <div class="rs-tile">
        <div class="rs-num"><?= count($byState) ?></div>
        <div class="rs-lbl">States</div>
      </div>
      <?php $totalExp = array_sum(array_column($stateMeta,'expired')); $totalWarn = array_sum(array_column($stateMeta,'expiring')); ?>
      <?php if ($totalExp): ?>
      <div class="rs-tile red">
        <div class="rs-num"><?= $totalExp ?></div>
        <div class="rs-lbl">Expired Licenses</div>
      </div>
      <?php endif; ?>
      <?php if ($totalWarn): ?>
      <div class="rs-tile amber">
        <div class="rs-num"><?= $totalWarn ?></div>
        <div class="rs-lbl">Expiring &lt;60 Days</div>
      </div>
      <?php endif; ?>
      <span id="prod-loading">Loading production…</span>
      <div class="rs-tile green" id="prod-vol-tile" style="display:none">
        <div class="rs-num" id="prod-vol-num">—</div>
        <div class="rs-lbl">LTM Volume</div>
      </div>
      <div class="rs-tile" id="prod-deals-tile" style="display:none">
        <div class="rs-num" id="prod-deals-num">—</div>
        <div class="rs-lbl">LTM Deals</div>
      </div>
    </div>

    <!-- State cards -->
    <div class="state-grid">
      <?php foreach ($byState as $st => $mcs): ?>
      <?php $m = $stateMeta[$st]; $tier = $stateTiers[$st] ?? 0; ?>
      <a class="state-card<?= $st===$activeState?' active':'' ?>" href="?state=<?= urlencode($st) ?>">
        <div style="display:flex;align-items:flex-start;justify-content:space-between">
          <div class="sc-code"><?= htmlspecialchars($st) ?></div>
          <?php if ($tier): ?>
          <span class="tier-badge <?= $tierClass[$tier] ?>"><?= $tierLabel[$tier] ?></span>
          <?php endif; ?>
        </div>
        <div class="sc-name"><?= htmlspecialchars($stateNames[$st] ?? $st) ?></div>
        <div class="sc-stats">
          <span class="sc-stat"><strong><?= $m['total'] ?></strong> agents</span>
          <?php if ($m['mc_count']>1): ?><span class="sc-stat"><strong><?= $m['mc_count'] ?></strong> MCs</span><?php endif; ?>
          <?php if ($m['expired']): ?>
          <span class="sc-stat" style="color:#c0392b"><span class="exp-dot red"></span><?= $m['expired'] ?> expired</span>
          <?php endif; ?>
          <?php if ($m['expiring']): ?>
          <span class="sc-stat" style="color:#c87800"><span class="exp-dot amber"></span><?= $m['expiring'] ?> expiring</span>
          <?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Detail panel -->
    <?php if ($activeGroups): ?>
    <?php $m = $stateMeta[$activeState]; $tier = $stateTiers[$activeState] ?? 0; ?>
    <div class="detail-panel">
      <div class="detail-header">
        <div class="detail-title"><?= htmlspecialchars($stateNames[$activeState] ?? $activeState) ?> (<?= htmlspecialchars($activeState) ?>)</div>
        <?php if ($tier): ?>
        <span class="tier-badge <?= $tierClass[$tier] ?>"><?= $tierLabel[$tier] ?> Commission Data</span>
        <?php endif; ?>
        <div style="margin-left:auto;display:flex;align-items:center;gap:10px">
          <div class="detail-sub">
            <?= $m['total'] ?> agents &nbsp;·&nbsp; <?= $m['mc_count'] ?> MC<?= $m['mc_count']!==1?'s':'' ?>
            <?php if ($m['expired']): ?>&nbsp;·&nbsp;<span style="color:#c0392b;font-weight:700"><?= $m['expired'] ?> expired</span><?php endif; ?>
            <?php if ($m['expiring']): ?>&nbsp;·&nbsp;<span style="color:#c87800;font-weight:700"><?= $m['expiring'] ?> expiring</span><?php endif; ?>
          </div>
          <?php if (is_super_admin()): ?>
          <button class="btn-add-agent" style="background:#f0f0f0;color:#555;border:1px solid #ddd"
                  id="btn-import-mc" onclick="importMCsFromRoster()">↓ Import MCs from CRM</button>
          <button class="btn-add-agent" style="background:#f0f5e8;color:#5b8e0d;border:1px solid #c3dfa8" onclick="openAddMCModal()">+ Add MC</button>
          <?php endif; ?>
          <button class="btn-add-agent" onclick="openAddModal()">+ Add Agent</button>
        </div>
      </div>

      <?php foreach ($activeGroups as $mc => $agents):
        $isUnassigned = ($mc === MC_UNASSIGNED);
        $mcLabel  = $isUnassigned ? 'Unassigned Agents' : $mc;
        $mcJson   = htmlspecialchars(json_encode($mc), ENT_QUOTES);
        $mcSlug   = $isUnassigned ? '' : ($mcMeta[$mc]['slug'] ?? '');
        $mcSlugJ  = htmlspecialchars(json_encode($mcSlug), ENT_QUOTES);
        $mcBic    = $isUnassigned ? '' : ($mcMeta[$mc]['bic_email']      ?? '');
        $mcLeader = $isUnassigned ? '' : ($mcMeta[$mc]['mc_leader_email'] ?? '');
        $mcEditId = 'mc-edit-' . md5($mc);

        // Retention rate for this group: % of agents not flagged at_risk.
        $mcRiskCount = 0;
        foreach ($agents as $a) { if (($a['retention_status'] ?? 'secure') === 'at_risk') $mcRiskCount++; }
        $mcRetainPct = count($agents) ? (int)round((count($agents) - $mcRiskCount) / count($agents) * 100) : 100;
        $mcRetainTier = $mcRetainPct >= 90 ? 'secure' : ($mcRetainPct >= 75 ? 'watch' : 'at_risk');
      ?>
      <div>
        <div class="mc-heading<?= $isUnassigned ? ' mc-heading-unassigned' : '' ?>"
             data-content-id="mc-content-<?= $mcEditId ?>">
          <input type="checkbox" class="mc-sel-all" title="Select all in this group"
                 onchange="toggleMcAll(this, <?= $mcJson ?>)">
          <i class="mc-chevron">&#9654;</i>
          <span class="mc-name-label"><?= htmlspecialchars($mcLabel) ?> &mdash; <?= count($agents) ?> agent<?= count($agents)!==1?'s':'' ?></span>
          <?php if (!$isUnassigned && $mcBic): ?>
          <span class="mc-role-chip mc-bic-chip">BIC: <?= htmlspecialchars($mcBic) ?></span>
          <?php endif; ?>
          <?php if (!$isUnassigned && $mcLeader): ?>
          <span class="mc-role-chip mc-leader-chip">Leader: <?= htmlspecialchars($mcLeader) ?></span>
          <?php endif; ?>
          <?php if (!$isUnassigned): ?>
          <span class="mc-role-chip mc-retain-chip <?= $mcRetainTier ?>">Retention: <?= $mcRetainPct ?>%</span>
          <?php endif; ?>
          <?php if (!$isUnassigned && is_super_admin()): ?>
          <div style="margin-left:auto;display:flex;gap:5px;flex-shrink:0">
            <button class="btn-edit-mc" onclick="toggleEditMC('<?= $mcEditId ?>')">Edit</button>
            <button class="btn-delete-mc"
                    onclick="deleteMC(<?= $mcSlugJ ?>, <?= $mcJson ?>, <?= count($agents) ?>)">Delete</button>
          </div>
          <?php endif; ?>
        </div>
        <?php if (!$isUnassigned && is_super_admin()): ?>
        <div class="mc-edit-panel" id="<?= $mcEditId ?>">
          <div class="mc-edit-row">
            <div class="mc-edit-field">
              <label class="mc-edit-label">Name</label>
              <input class="mc-edit-input mc-edit-name" type="text"
                     value="<?= htmlspecialchars($mc) ?>" maxlength="80" autocomplete="off">
            </div>
            <div class="mc-edit-field">
              <label class="mc-edit-label">BIC (Broker in Charge)</label>
              <select class="mc-edit-select mc-edit-bic">
                <option value="">— none —</option>
                <?php foreach ($bicOpts as $be): ?>
                <option value="<?= htmlspecialchars($be) ?>"<?= $be===$mcBic?' selected':'' ?>><?= htmlspecialchars($be) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mc-edit-field">
              <label class="mc-edit-label">MC Leader</label>
              <select class="mc-edit-select mc-edit-leader">
                <option value="">— none —</option>
                <?php foreach ($mcLeaderOpts as $le): ?>
                <option value="<?= htmlspecialchars($le) ?>"<?= $le===$mcLeader?' selected':'' ?>><?= htmlspecialchars($le) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mc-edit-actions">
            <button class="mc-edit-save"
                    onclick="saveEditMC('<?= $mcEditId ?>', <?= $mcJson ?>, <?= $mcSlugJ ?>)">Save Changes</button>
            <button class="mc-edit-cancel" onclick="toggleEditMC('<?= $mcEditId ?>')">Cancel</button>
          </div>
        </div>
        <?php endif; ?>
        <div class="mc-group-content" id="mc-content-<?= $mcEditId ?>">
        <table class="agent-table">
          <thead>
            <tr>
              <th class="cb-cell"></th>
              <th>Name</th>
              <th>Volume</th>
              <th>Deals</th>
              <?php if ($activeState==='SC'): ?><th>License Expires</th><?php endif; ?>
              <th>Retention</th>
              <th style="width:40px"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($agents as $a): ?>
            <tr data-agent="<?= htmlspecialchars($a['agent_name']) ?>" data-mc="<?= htmlspecialchars($mc) ?>" data-roster-id="<?= $a['id'] ?>">
              <td class="cb-cell">
                <input type="checkbox" class="agent-cb" data-name="<?= htmlspecialchars($a['agent_name']) ?>" data-mc="<?= htmlspecialchars($mc) ?>">
              </td>
              <td>
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                  <span><?= htmlspecialchars($a['agent_name']) ?></span>
                  <button class="btn-move-mc" title="Move to a different Market Center"
                          onclick="openMoveMC(this, <?= $a['id'] ?>, <?= json_encode($mc) ?>)">↪ Move</button>
                  <div class="move-mc-inline" id="move-mc-<?= $a['id'] ?>">
                    <select class="move-mc-select">
                      <option value="">— pick MC —</option>
                      <?php foreach ($mcOptsAssign as $opt): ?>
                      <?php $optLabel = ($opt['state_code'] ? $opt['state_code'] . ' - ' : '') . $opt['name']; ?>
                      <option value="<?= htmlspecialchars($opt['name']) ?>"
                        <?= $opt['name']===$mc?' selected':'' ?>><?= htmlspecialchars($optLabel) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn-move-save" onclick="saveMoveMC(<?= $a['id'] ?>)">Save</button>
                    <button class="btn-move-cancel" onclick="closeMoveMC(<?= $a['id'] ?>)">✕</button>
                  </div>
                </div>
              </td>
              <td class="prod-cell-vol"><span class="prod-none">—</span></td>
              <td class="prod-cell-deals"><span class="prod-none">—</span></td>
              <?php if ($activeState==='SC'): ?>
              <td>
                <?php
                $exp = $a['license_exp'];
                if (!$exp)           echo '<span class="exp-badge none">—</span>';
                elseif ($exp<=$today) echo '<span class="exp-badge expired">Expired ' . htmlspecialchars($exp) . '</span>';
                elseif ($exp<=$warn60) echo '<span class="exp-badge expiring">Expiring ' . htmlspecialchars($exp) . '</span>';
                else                  echo '<span class="exp-badge ok">' . htmlspecialchars($exp) . '</span>';
                ?>
              </td>
              <?php endif; ?>
              <td>
                <?php
                $rStatus = $a['retention_status'] ?? 'secure';
                $rNotes  = $a['retention_notes']   ?? '';
                $rLabel  = ['secure'=>'Secure','watch'=>'Watch','at_risk'=>'At Risk'][$rStatus] ?? 'Secure';
                ?>
                <button class="retain-badge <?= htmlspecialchars($rStatus) ?>" title="<?= htmlspecialchars($rNotes) ?>"
                        onclick="openRetentionEdit(<?= $a['id'] ?>)"><?= htmlspecialchars($rLabel) ?></button>
                <div class="retain-edit-inline" id="retain-edit-<?= $a['id'] ?>">
                  <select class="retain-edit-select">
                    <option value="secure"  <?= $rStatus==='secure' ?'selected':'' ?>>Secure</option>
                    <option value="watch"   <?= $rStatus==='watch'  ?'selected':'' ?>>Watch</option>
                    <option value="at_risk" <?= $rStatus==='at_risk'?'selected':'' ?>>At Risk</option>
                  </select>
                  <input type="text" class="retain-edit-notes" placeholder="Notes (optional)" maxlength="200"
                         value="<?= htmlspecialchars($rNotes) ?>">
                  <button class="btn-retain-save" onclick="saveRetention(<?= $a['id'] ?>)">Save</button>
                  <button class="btn-retain-cancel" onclick="closeRetentionEdit(<?= $a['id'] ?>)">✕</button>
                </div>
              </td>
              <td>
                <button class="btn-remove" title="Remove from roster"
                        onclick="removeAgent(<?= $a['id'] ?>, <?= json_encode($a['agent_name']) ?>)">✕</button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div><!-- /mc-group-content -->
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>


  </div><!-- /wrap -->
</div><!-- /content -->
</div><!-- /layout -->

<!-- Bulk Assign Bar -->
<div id="bulk-bar">
  <span id="bulk-count">0 selected</span>
  <span class="bulk-label">Assign to MC</span>
  <select id="bulk-mc" class="bulk-select">
    <option value="">— pick a Market Center —</option>
    <?php foreach ($mcOptsAssign as $mc): ?>
    <?php $optLabel = ($mc['state_code'] ? $mc['state_code'] . ' - ' : '') . $mc['name']; ?>
    <option value="<?= htmlspecialchars($mc['slug']) ?>"><?= htmlspecialchars($optLabel) ?></option>
    <?php endforeach; ?>
  </select>
  <span class="bulk-label">BIC</span>
  <select id="bulk-bic" class="bulk-select">
    <option value="">— none / keep existing —</option>
    <?php foreach ($bicOpts as $be): ?>
    <option value="<?= htmlspecialchars($be) ?>"><?= htmlspecialchars($be) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn-bulk-assign" onclick="doBulkAssign()">Assign</button>
  <button class="btn-bulk-clear" onclick="clearSelection()">Clear</button>
</div>

<!-- Add Agent Modal -->
<div class="modal-overlay" id="addModalOverlay">
  <div class="modal">
    <button class="modal-close" onclick="closeAddModal()">×</button>
    <h3>Add Agent to Roster</h3>
    <div class="modal-sub">Agent will appear in the <?= htmlspecialchars($stateNames[$activeState] ?? $activeState) ?> roster and be logged for onboarding.</div>
    <label class="mf-label">Agent Name</label>
    <input id="mf-name" class="mf-input" type="text" placeholder="Full name" maxlength="120" autocomplete="off">
    <label class="mf-label">State</label>
    <select id="mf-state" class="mf-input">
      <?php foreach ($stateNames as $code => $name): ?>
      <option value="<?= $code ?>"<?= $code===$activeState?' selected':'' ?>><?= htmlspecialchars($name) ?> (<?= $code ?>)</option>
      <?php endforeach; ?>
    </select>
    <label class="mf-label">Market Center</label>
    <input id="mf-mc" class="mf-input" type="text" placeholder="e.g. Myrtle Beach" list="mc-datalist" maxlength="80" autocomplete="off">
    <datalist id="mc-datalist">
      <?php foreach ($mcList as $mc): ?>
      <option value="<?= htmlspecialchars($mc) ?>">
      <?php endforeach; ?>
    </datalist>
    <label class="mf-label">License Expiry <span style="font-weight:400;text-transform:none;letter-spacing:0">(optional)</span></label>
    <input id="mf-exp" class="mf-input" type="date">
    <div class="mf-err" id="mf-err"></div>
    <div class="mf-btns">
      <button class="mf-save" id="mf-save" onclick="saveNewAgent()">Add to Roster</button>
      <button class="mf-cancel" onclick="closeAddModal()">Cancel</button>
    </div>
  </div>
</div>

<!-- Add MC Modal -->
<div class="modal-overlay" id="addMCModalOverlay">
  <div class="modal">
    <button class="modal-close" onclick="closeAddMCModal()">×</button>
    <h3>Add Market Center</h3>
    <div class="modal-sub">Create a new Market Center. Agents can then be assigned to it.</div>
    <label class="mf-label">Market Center Name</label>
    <input id="mc-name" class="mf-input" type="text" placeholder="e.g. SC - Myrtle Beach" maxlength="80" autocomplete="off">
    <label class="mf-label">State</label>
    <select id="mc-state" class="mf-input">
      <?php foreach ($stateNames as $code => $name): ?>
      <option value="<?= $code ?>"<?= $code===$activeState?' selected':'' ?>><?= htmlspecialchars($name) ?> (<?= $code ?>)</option>
      <?php endforeach; ?>
    </select>
    <label class="mf-label">BIC (Broker in Charge) <span style="font-weight:400;text-transform:none;letter-spacing:0">(optional)</span></label>
    <select id="mc-bic" class="mf-input">
      <option value="">— none —</option>
      <?php foreach ($bicOpts as $be): ?>
      <option value="<?= htmlspecialchars($be) ?>"><?= htmlspecialchars($be) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="mf-label">MC Leader <span style="font-weight:400;text-transform:none;letter-spacing:0">(optional)</span></label>
    <select id="mc-leader" class="mf-input">
      <option value="">— none —</option>
      <?php foreach ($mcLeaderOpts as $le): ?>
      <option value="<?= htmlspecialchars($le) ?>"><?= htmlspecialchars($le) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="mf-err" id="mc-err"></div>
    <div class="mf-btns">
      <button class="mf-save" id="mc-save" onclick="saveNewMC()">Add Market Center</button>
      <button class="mf-cancel" onclick="closeAddMCModal()">Cancel</button>
    </div>
  </div>
</div>

<script>
const ACTIVE_STATE = <?= json_encode($activeState) ?>;
const STATE_URL    = 'backoffice_roster.php?state=' + encodeURIComponent(ACTIVE_STATE);
const MC_OPTS      = <?= json_encode(array_column($mcOptsAssign, 'name', 'slug')) ?>;

// ── Add agent modal ──────────────────────────────────────────────────────────
function openAddModal() {
    document.getElementById('addModalOverlay').classList.add('open');
    document.getElementById('mf-name').focus();
}
function closeAddModal() {
    document.getElementById('addModalOverlay').classList.remove('open');
    document.getElementById('mf-err').style.display = 'none';
}
document.getElementById('addModalOverlay').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeAddModal();
});

// Update MC datalist when state changes
document.getElementById('mf-state').addEventListener('change', function() {
    fetch('api/roster_agent.php', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'mcs_for_state', state_code: this.value})
    }).then(r=>r.json()).then(d=>{
        const dl = document.getElementById('mc-datalist');
        dl.innerHTML = (d.mcs||[]).map(m=>`<option value="${esc(m)}">`).join('');
    });
});

function saveNewAgent() {
    const name  = document.getElementById('mf-name').value.trim();
    const state = document.getElementById('mf-state').value;
    const mc    = document.getElementById('mf-mc').value.trim();
    const exp   = document.getElementById('mf-exp').value;
    const err   = document.getElementById('mf-err');
    err.style.display = 'none';
    if (!name) { err.textContent = 'Agent name is required.'; err.style.display = ''; return; }

    const btn = document.getElementById('mf-save');
    btn.disabled = true; btn.textContent = 'Saving…';

    fetch('api/roster_agent.php', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'add', agent_name:name, state_code:state, market_center:mc, license_exp:exp})
    })
    .then(r=>r.json())
    .then(d=>{
        btn.disabled = false; btn.textContent = 'Add to Roster';
        if (!d.ok) { err.textContent = d.error || 'Error saving.'; err.style.display = ''; return; }
        closeAddModal();
        // Reload the page to show the new agent in the correct state/MC
        window.location.href = 'backoffice_roster.php?state=' + encodeURIComponent(state);
    })
    .catch(()=>{ btn.disabled=false; btn.textContent='Add to Roster'; err.textContent='Network error.'; err.style.display=''; });
}

// ── Add MC modal ──────────────────────────────────────────────────────────────
function openAddMCModal() {
    document.getElementById('addMCModalOverlay').classList.add('open');
    document.getElementById('mc-name').focus();
}
function closeAddMCModal() {
    document.getElementById('addMCModalOverlay').classList.remove('open');
    document.getElementById('mc-err').style.display = 'none';
}
document.getElementById('addMCModalOverlay').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeAddMCModal();
});

function saveNewMC() {
    const name   = document.getElementById('mc-name').value.trim();
    const state  = document.getElementById('mc-state').value;
    const bic    = document.getElementById('mc-bic').value;
    const leader = document.getElementById('mc-leader').value;
    const err    = document.getElementById('mc-err');
    err.style.display = 'none';
    if (!name) { err.textContent = 'Market Center name is required.'; err.style.display = ''; return; }

    const btn = document.getElementById('mc-save');
    btn.disabled = true; btn.textContent = 'Saving…';

    fetch('api/mc_action.php', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action:'save', name, state_code:state, sort_ord:0, bic_email:bic, mc_leader_email:leader})
    })
    .then(r => r.json())
    .then(d => {
        btn.disabled = false; btn.textContent = 'Add Market Center';
        if (!d.ok) { err.textContent = d.error || 'Error saving.'; err.style.display = ''; return; }
        closeAddMCModal();
        window.location.href = 'backoffice_roster.php?state=' + encodeURIComponent(state);
    })
    .catch(() => {
        btn.disabled = false; btn.textContent = 'Add Market Center';
        err.textContent = 'Network error.'; err.style.display = '';
    });
}

// ── Remove agent ─────────────────────────────────────────────────────────────
function removeAgent(id, name) {
    if (!confirm('Remove ' + name + ' from the roster?\n\nThis is logged and can be reviewed in Weekly Changes. The agent can be restored from the changes report.')) return;
    fetch('api/roster_agent.php', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'remove', id})
    })
    .then(r=>r.json())
    .then(d=>{
        if (d.ok) {
            const row = document.querySelector('tr[data-roster-id="'+id+'"]');
            if (row) {
                row.style.transition='opacity .3s';
                row.style.opacity='0';
                setTimeout(()=>row.remove(), 320);
            }
        }
    });
}

// ── Bulk selection ───────────────────────────────────────────────────────────
function updateBulkBar() {
    const checked = document.querySelectorAll('.agent-cb:checked');
    const bar = document.getElementById('bulk-bar');
    const count = document.getElementById('bulk-count');
    count.textContent = checked.length + ' selected';
    bar.classList.toggle('open', checked.length > 0);
}

function toggleMcAll(masterCb, mcName) {
    document.querySelectorAll(`.agent-cb[data-mc="${CSS.escape(mcName)}"]`)
        .forEach(cb => { cb.checked = masterCb.checked; });
    updateBulkBar();
}

function clearSelection() {
    document.querySelectorAll('.agent-cb,.mc-sel-all').forEach(cb => cb.checked = false);
    updateBulkBar();
}

document.addEventListener('change', e => {
    if (e.target.classList.contains('agent-cb')) updateBulkBar();
});

function doBulkAssign() {
    const names = Array.from(document.querySelectorAll('.agent-cb:checked'))
                       .map(cb => cb.dataset.name);
    if (!names.length) return;
    const mcSlug   = document.getElementById('bulk-mc').value;
    const bicEmail = document.getElementById('bulk-bic').value;
    if (!mcSlug) { alert('Please choose a Market Center.'); return; }

    const btn = document.querySelector('.btn-bulk-assign');
    btn.disabled = true; btn.textContent = 'Assigning…';

    fetch('api/roster_agent.php', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'bulk_assign', agent_names:names, mc_slug:mcSlug,
                              bic_email:bicEmail, state_code:ACTIVE_STATE})
    })
    .then(r=>r.json())
    .then(d=>{
        btn.disabled = false; btn.textContent = 'Assign';
        if (!d.ok) { alert('Error: ' + (d.error||'Unknown')); return; }
        // Reload so agents appear under their new MC group
        window.location.reload();
    })
    .catch(()=>{ btn.disabled=false; btn.textContent='Assign'; alert('Network error.'); });
}

// ── Move single agent to a different MC ──────────────────────────────────────
function openMoveMC(btn, rosterId, currentMC) {
    // Close any other open move panels
    document.querySelectorAll('.move-mc-inline.open').forEach(el => {
        if (el.id !== 'move-mc-' + rosterId) el.classList.remove('open');
    });
    document.querySelectorAll('.btn-move-mc').forEach(b => b.style.display = '');
    const wrap = document.getElementById('move-mc-' + rosterId);
    const isOpen = wrap.classList.contains('open');
    if (isOpen) { wrap.classList.remove('open'); return; }
    btn.style.display = 'none';
    wrap.classList.add('open');
    wrap.querySelector('.move-mc-select').focus();
}

function closeMoveMC(rosterId) {
    const wrap = document.getElementById('move-mc-' + rosterId);
    wrap.classList.remove('open');
    const row = wrap.closest('tr');
    if (row) {
        const btn = row.querySelector('.btn-move-mc');
        if (btn) btn.style.display = '';
    }
}

function saveMoveMC(rosterId) {
    const wrap   = document.getElementById('move-mc-' + rosterId);
    const mcName = wrap.querySelector('.move-mc-select').value;
    if (!mcName) { alert('Please pick a Market Center.'); return; }

    const saveBtn = wrap.querySelector('.btn-move-save');
    saveBtn.disabled = true; saveBtn.textContent = '…';

    fetch('api/roster_agent.php', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'move_mc', id:rosterId, mc_name:mcName})
    })
    .then(r => r.json())
    .then(d => {
        saveBtn.disabled = false; saveBtn.textContent = 'Save';
        if (!d.ok) { alert('Error: ' + (d.error||'Unknown')); return; }
        window.location.reload();
    })
    .catch(() => { saveBtn.disabled = false; saveBtn.textContent = 'Save'; alert('Network error.'); });
}

// ── Retention status per agent ────────────────────────────────────────────────
function openRetentionEdit(rosterId) {
    document.querySelectorAll('.retain-edit-inline.open').forEach(el => {
        if (el.id !== 'retain-edit-' + rosterId) el.classList.remove('open');
    });
    const wrap = document.getElementById('retain-edit-' + rosterId);
    const isOpen = wrap.classList.contains('open');
    if (isOpen) { wrap.classList.remove('open'); return; }
    wrap.classList.add('open');
    wrap.querySelector('.retain-edit-select').focus();
}

function closeRetentionEdit(rosterId) {
    document.getElementById('retain-edit-' + rosterId).classList.remove('open');
}

function saveRetention(rosterId) {
    const wrap   = document.getElementById('retain-edit-' + rosterId);
    const status = wrap.querySelector('.retain-edit-select').value;
    const notes  = wrap.querySelector('.retain-edit-notes').value;

    const saveBtn = wrap.querySelector('.btn-retain-save');
    saveBtn.disabled = true; saveBtn.textContent = '…';

    fetch('api/retention_action.php', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'update', id:rosterId, retention_status:status, retention_notes:notes})
    })
    .then(r => r.json())
    .then(d => {
        saveBtn.disabled = false; saveBtn.textContent = 'Save';
        if (!d.ok) { alert('Error: ' + (d.error||'Unknown')); return; }
        window.location.reload();
    })
    .catch(() => { saveBtn.disabled = false; saveBtn.textContent = 'Save'; alert('Network error.'); });
}

// ── Edit MC (name + BIC + leader) ────────────────────────────────────────────

function saveEditMC(panelId, oldName, slug) {
    const panel   = document.getElementById(panelId);
    const newName = panel.querySelector('.mc-edit-name').value.trim();
    const bic     = panel.querySelector('.mc-edit-bic').value;
    const leader  = panel.querySelector('.mc-edit-leader').value;
    if (!newName) { alert('Name is required.'); return; }

    const btn = panel.querySelector('.mc-edit-save');
    btn.disabled = true; btn.textContent = 'Saving…';

    // If we have a slug, use mc_action for full save (propagates BIC to agent_roles)
    // Always also run rename_mc to update innovate_roster rows
    const saves = [];

    if (slug) {
        saves.push(fetch('api/mc_action.php', {
            method:'POST', credentials:'same-origin',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify({action:'save', edit_slug:slug, name:newName,
                                  state_code:ACTIVE_STATE, sort_ord:0,
                                  bic_email:bic, mc_leader_email:leader})
        }).then(r=>r.json()));
    }

    if (newName !== oldName) {
        saves.push(fetch('api/roster_agent.php', {
            method:'POST', credentials:'same-origin',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify({action:'rename_mc', old_name:oldName, new_name:newName, state_code:ACTIVE_STATE})
        }).then(r=>r.json()));
    }

    Promise.all(saves).then(results => {
        btn.disabled = false; btn.textContent = 'Save Changes';
        const failed = results.find(r => !r.ok);
        if (failed) { alert('Save failed: ' + (failed.error||'Unknown')); return; }
        window.location.reload();
    }).catch(err => {
        btn.disabled = false; btn.textContent = 'Save Changes';
        alert('Error: ' + err.message);
    });
}

function deleteMC(slug, mcName, agentCount) {
    const msg = agentCount > 0
        ? `Delete "${mcName}"?\n\n${agentCount} agent${agentCount!==1?'s':''} will remain in the roster but won't be assigned to any Market Center. You can reassign them afterwards.`
        : `Delete "${mcName}"?`;
    if (!confirm(msg)) return;

    fetch('api/mc_action.php', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'delete', slug})
    })
    .then(r=>r.json())
    .then(d => {
        if (!d.ok) { alert('Delete failed: ' + (d.error||'Unknown')); return; }
        window.location.reload();
    })
    .catch(err => alert('Error: ' + err.message));
}

function importMCsFromRoster() {
    const btn = document.getElementById('btn-import-mc');
    btn.disabled = true; btn.textContent = 'Importing…';
    fetch('api/mc_action.php', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'import'})
    })
    .then(r=>r.json())
    .then(d => {
        btn.disabled = false; btn.textContent = '↓ Import MCs from CRM';
        if (!d.ok) { alert('Import failed: ' + (d.error||'Unknown')); return; }
        if (d.added === 0) { alert('All CRM Market Centers are already in the list.'); return; }
        alert(`Imported ${d.added} new Market Center${d.added!==1?'s':''} from the CRM.`);
        window.location.reload();
    })
    .catch(err => { btn.disabled=false; btn.textContent='↓ Import MCs from CRM'; alert('Error: '+err.message); });
}

// ── Collapsible MC groups ────────────────────────────────────────────────────
function toggleMCGroup(heading) {
    const contentId = heading.dataset.contentId;
    const content = document.getElementById(contentId);
    if (!content) return;
    const isOpen = heading.classList.contains('mc-open');
    heading.classList.toggle('mc-open', !isOpen);
    content.classList.toggle('mc-open', !isOpen);
}
document.querySelectorAll('.mc-heading').forEach(function(h) {
    h.addEventListener('click', function(e) {
        if (e.target.closest('button,input,select,a,label')) return;
        toggleMCGroup(h);
    });
});

function toggleEditMC(panelId) {
    const panel = document.getElementById(panelId);
    const isOpen = panel.classList.contains('open');
    document.querySelectorAll('.mc-edit-panel.open').forEach(p => p.classList.remove('open'));
    if (!isOpen) { panel.classList.add('open'); panel.querySelector('.mc-edit-name').focus(); }
}

// ── Production enrichment ────────────────────────────────────────────────────
function normName(n) {
    return (n||'').toLowerCase().replace(/[^a-z ]/g,' ').replace(/\s+/g,' ').trim();
}
function lookupProd(name, map) {
    const n = normName(name);
    if (map[n]) return map[n];
    const parts = n.split(' ').filter(p=>p.length>1);
    if (parts.length>1 && map[parts.join(' ')]) return map[parts.join(' ')];
    const words = n.split(' ').filter(p=>p.length>0);
    if (words.length>2 && map[words[0]+' '+words[words.length-1]]) return map[words[0]+' '+words[words.length-1]];
    return null;
}
function fmtVol(v) {
    if (!v||v<1000) return '—';
    if (v>=1e9) return '$'+(v/1e9).toFixed(1)+'B';
    if (v>=1e6) return '$'+(v/1e6).toFixed(1)+'M';
    if (v>=1e3) return '$'+(v/1e3).toFixed(0)+'K';
    return '$'+Math.round(v).toLocaleString();
}
function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }

fetch('api/backoffice_production.php',{credentials:'same-origin'})
    .then(r=>r.json())
    .then(d=>{
        const loading=document.getElementById('prod-loading');
        if (loading) loading.style.display='none';
        if (!d.ok) return;
        if (d.total_volume>0){ document.getElementById('prod-vol-num').textContent=fmtVol(d.total_volume); document.getElementById('prod-vol-tile').style.display=''; }
        if (d.total_deals>0) { document.getElementById('prod-deals-num').textContent=d.total_deals.toLocaleString(); document.getElementById('prod-deals-tile').style.display=''; }
        const map=d.agents||{};
        document.querySelectorAll('tr[data-agent]').forEach(row=>{
            const prod=lookupProd(row.dataset.agent, map);
            const vt=row.querySelector('.prod-cell-vol'), dt=row.querySelector('.prod-cell-deals');
            if (!vt||!dt) return;
            if (prod&&(prod.volume>0||prod.deals>0)){
                vt.innerHTML='<span class="prod-vol">'+fmtVol(prod.volume)+'</span>';
                dt.innerHTML='<span class="prod-deals">'+(prod.deals||0)+'</span>';
            }
        });
    })
    .catch(()=>{ const l=document.getElementById('prod-loading'); if(l)l.style.display='none'; });
</script>
</body>
</html>
