<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
$agent = require_login();

$me = $agent['email'];
$db = local_db();

// Summary counts for overview tiles
$farmCount   = (int)$db->prepare("SELECT COUNT(*) FROM listing_farms WHERE agent_email=?")->execute([$me]) ? 0 : 0;
$sc = $db->prepare("SELECT COUNT(*) FROM listing_farms WHERE agent_email=?"); $sc->execute([$me]); $farmCount = (int)$sc->fetchColumn();
$sc = $db->prepare("SELECT COUNT(*) FROM listing_prospects WHERE agent_email=? AND status != 'dead'"); $sc->execute([$me]); $activeProspects = (int)$sc->fetchColumn();
$sc = $db->prepare("SELECT COUNT(*) FROM listing_outreach WHERE agent_email=? AND logged_at >= date('now','-7 days')"); $sc->execute([$me]); $weeklyTouches = (int)$sc->fetchColumn();
$sc = $db->prepare("SELECT COUNT(*) FROM listing_prospects WHERE agent_email=? AND status='active'"); $sc->execute([$me]); $hotCount = (int)$sc->fetchColumn();

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

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
function money(int $n): string { return $n ? '$'.number_format($n) : '—'; }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Listing Intel — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    /* ── Tabs ─────────────────────────────────────────────────────────────── */
    .li-tabs{display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:20px}
    .li-tab{padding:10px 20px;font-size:13px;font-weight:700;color:var(--faint);background:none;border:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;letter-spacing:.02em}
    .li-tab:hover{color:var(--ink)}
    .li-tab.active{color:var(--green-d);border-bottom-color:var(--green)}
    .li-pane{display:none}.li-pane.active{display:block}

    /* ── Stats tiles ──────────────────────────────────────────────────────── */
    .li-tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
    @media(max-width:760px){.li-tiles{grid-template-columns:repeat(2,1fr)}}
    .li-tile{background:#fff;border:1px solid var(--border);border-radius:12px;padding:16px}
    .li-tile-val{font-size:28px;font-weight:800;color:var(--ink)}
    .li-tile-lbl{font-size:12px;color:var(--faint);margin-top:2px}
    .li-tile.hot .li-tile-val{color:#e05d00}
    .li-tile.green .li-tile-val{color:var(--green-d)}

    /* ── Table ────────────────────────────────────────────────────────────── */
    .li-tbl{width:100%;border-collapse:collapse;font-size:13px}
    .li-tbl th{text-align:left;padding:8px 10px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);border-bottom:2px solid var(--border)}
    .li-tbl td{padding:10px 10px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
    .li-tbl tr:last-child td{border-bottom:none}
    .li-tbl tr:hover td{background:#fafafa}
    .li-tbl .num{text-align:right}

    /* ── Status badges ────────────────────────────────────────────────────── */
    .badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
    .badge-new{background:#e8f4ff;color:#2255cc}
    .badge-contacted{background:#fff4e0;color:#a07221}
    .badge-active{background:#eef5e8;color:#5b8e0d}
    .badge-dead{background:#f5f5f5;color:#aaa}
    .badge-expired{background:#fdecea;color:#c0392b}
    .badge-manual{background:#f0f0f0;color:#666}
    .badge-fsbo{background:#e8f0ff;color:#2255cc}
    .badge-equity{background:#f5eeff;color:#7c3aed}

    /* ── Score bar ────────────────────────────────────────────────────────── */
    .score-wrap{display:flex;align-items:center;gap:6px}
    .score-bar{flex:1;height:5px;background:#eee;border-radius:3px;overflow:hidden}
    .score-fill{height:100%;border-radius:3px;background:var(--green)}
    .score-fill.warm{background:#e08c00}
    .score-fill.hot{background:#c0392b}
    .score-num{font-size:11px;font-weight:700;color:var(--faint);width:22px;text-align:right}

    /* ── Buttons ──────────────────────────────────────────────────────────── */
    .btn{display:inline-flex;align-items:center;gap:5px;padding:8px 14px;border:none;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none}
    .btn-primary{background:var(--green);color:#000}
    .btn-primary:hover{background:var(--green-d);color:#fff}
    .btn-ghost{background:#f0f0f0;color:#333}
    .btn-ghost:hover{background:#e4e4e4}
    .btn-danger{background:#fdecea;color:#c0392b;border:1px solid #f5c6c0}
    .btn-danger:hover{background:#f5c6c0}
    .btn-sm{padding:5px 10px;font-size:12px}
    .btn-row{display:flex;gap:8px;flex-wrap:wrap}

    /* ── Empty state ──────────────────────────────────────────────────────── */
    .li-empty{text-align:center;padding:48px 20px;color:var(--faint)}
    .li-empty-icon{font-size:40px;margin-bottom:10px}
    .li-empty-msg{font-size:14px;margin-bottom:16px}

    /* ── Modals ───────────────────────────────────────────────────────────── */
    .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:1000}
    .modal-overlay.open{display:flex}
    .modal{background:#fff;border-radius:12px;width:min(520px,95vw);max-height:90vh;overflow-y:auto;padding:24px;position:relative}
    .modal h3{margin:0 0 4px;font-size:17px;font-weight:800}
    .modal .sub{font-size:12px;color:var(--faint);margin-bottom:18px}
    .modal-close{position:absolute;top:16px;right:16px;background:none;border:none;font-size:22px;cursor:pointer;color:#888;line-height:1}
    .form-row{margin-bottom:14px}
    .form-row label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint);margin-bottom:5px}
    .form-input{width:100%;padding:9px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:#fff}
    .form-input:focus{outline:none;border-color:var(--green)}
    .form-grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .form-select{width:100%;padding:9px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:#fff}
    .form-textarea{width:100%;padding:9px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:#fff;min-height:72px;resize:vertical}

    /* ── Farm cards ───────────────────────────────────────────────────────── */
    .farm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin-bottom:20px}
    .farm-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:16px}
    .farm-card-name{font-size:15px;font-weight:800;margin-bottom:4px}
    .farm-card-meta{font-size:12px;color:var(--faint);margin-bottom:10px}
    .farm-zip{display:inline-block;background:#eef5e8;color:#5b8e0d;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:700;margin:2px 2px 2px 0}

    /* ── Expired pipeline ─────────────────────────────────────────────────── */
    .exp-toolbar{display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap}
    .exp-search{padding:8px 12px;border:1px solid var(--border);border-radius:6px;font-size:13px;width:220px}
    .exp-note{font-size:12px;color:var(--faint);margin-left:auto}

    /* ── Outreach log drawer ──────────────────────────────────────────────── */
    .log-list{display:flex;flex-direction:column;gap:8px;margin-bottom:16px}
    .log-item{background:#f9f9f9;border-radius:6px;padding:10px 12px;font-size:12px}
    .log-item-top{display:flex;align-items:center;gap:8px;margin-bottom:3px}
    .log-method{font-weight:700;font-size:11px;text-transform:uppercase;color:var(--green-d)}
    .log-outcome{color:var(--faint)}
    .log-date{margin-left:auto;color:var(--faint);font-size:11px}
    .log-notes{color:#444}

    /* ── Masquerade ───────────────────────────────────────────────────────── */
    .masq-bar{background:#f59e0b;color:#000;text-align:center;padding:8px;font-size:13px;font-weight:700}
    .masq-back{background:#000;color:#f59e0b;border:none;border-radius:4px;padding:4px 10px;font-size:12px;font-weight:700;cursor:pointer;margin-left:10px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('listing_intel', $agent); ?>

  <div class="content">
    <header class="content-top">
      <div class="content-title">Listing Intel</div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-primary btn-sm" onclick="openProspectModal()">+ Add Prospect</button>
      </div>
    </header>

    <main class="wrap">

      <!-- Overview tiles -->
      <div class="li-tiles">
        <div class="li-tile">
          <div class="li-tile-val"><?= $farmCount ?></div>
          <div class="li-tile-lbl">Farm Areas</div>
        </div>
        <div class="li-tile">
          <div class="li-tile-val"><?= $activeProspects ?></div>
          <div class="li-tile-lbl">Active Prospects</div>
        </div>
        <div class="li-tile hot">
          <div class="li-tile-val"><?= $hotCount ?></div>
          <div class="li-tile-lbl">Hot Leads</div>
        </div>
        <div class="li-tile green">
          <div class="li-tile-val"><?= $weeklyTouches ?></div>
          <div class="li-tile-lbl">Touches This Week</div>
        </div>
      </div>

      <!-- Tabs -->
      <div class="li-tabs">
        <button class="li-tab active" onclick="switchTab('prospects', this)">Prospects</button>
        <button class="li-tab" onclick="switchTab('expireds', this)">Expired Pipeline</button>
        <button class="li-tab" onclick="switchTab('farms', this)">My Farms</button>
      </div>

      <!-- ── PROSPECTS ──────────────────────────────────────────────────── -->
      <div class="li-pane active" id="pane-prospects">
        <?php if (empty($prospects)): ?>
        <div class="li-empty">
          <div class="li-empty-icon">🏡</div>
          <div class="li-empty-msg">No prospects yet. Add your first one or pull from the Expired Pipeline.</div>
          <button class="btn btn-primary" onclick="openProspectModal()">+ Add Prospect</button>
        </div>
        <?php else: ?>
        <div style="margin-bottom:10px;display:flex;gap:8px;align-items:center">
          <input type="search" id="prospect-search" class="exp-search" placeholder="Search prospects…" oninput="filterProspects()">
          <select id="prospect-status-filter" class="form-select" style="width:160px" onchange="filterProspects()">
            <option value="">All statuses</option>
            <option value="new">New</option>
            <option value="contacted">Contacted</option>
            <option value="active">Active</option>
            <option value="dead">Dead</option>
          </select>
          <select id="prospect-source-filter" class="form-select" style="width:160px" onchange="filterProspects()">
            <option value="">All sources</option>
            <option value="manual">Manual</option>
            <option value="expired">Expired</option>
            <option value="fsbo">FSBO</option>
            <option value="equity">Equity</option>
          </select>
        </div>
        <div class="card" style="padding:0;overflow:hidden">
          <table class="li-tbl" id="prospect-table">
            <thead><tr>
              <th>Owner / Address</th>
              <th>Farm</th>
              <th>Source</th>
              <th>Status</th>
              <th>Score</th>
              <th class="num">Est. Value</th>
              <th>Last Touch</th>
              <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($prospects as $p):
              $scoreClass = $p['seller_score'] >= 75 ? 'hot' : ($p['seller_score'] >= 45 ? 'warm' : '');
              $lastTouch = $p['last_touch'] ? date('M j', strtotime($p['last_touch'])) : 'Never';
            ?>
            <tr data-status="<?= h($p['status']) ?>" data-source="<?= h($p['source']) ?>"
                data-search="<?= h(strtolower($p['owner_name'].' '.$p['address'].' '.$p['city'])) ?>">
              <td>
                <div style="font-weight:700"><?= h($p['owner_name']) ?></div>
                <div style="font-size:11px;color:var(--faint)"><?= h($p['address']) ?><?= $p['city'] ? ', '.h($p['city']) : '' ?></div>
              </td>
              <td style="font-size:12px;color:var(--faint)"><?= h($p['farm_name'] ?? '—') ?></td>
              <td><span class="badge badge-<?= h($p['source']) ?>"><?= h($p['source']) ?></span></td>
              <td><span class="badge badge-<?= h($p['status']) ?>"><?= h($p['status']) ?></span></td>
              <td>
                <div class="score-wrap">
                  <div class="score-bar"><div class="score-fill <?= $scoreClass ?>" style="width:<?= $p['seller_score'] ?>%"></div></div>
                  <div class="score-num"><?= $p['seller_score'] ?: '—' ?></div>
                </div>
              </td>
              <td class="num" style="font-weight:700"><?= money((int)$p['est_value']) ?></td>
              <td style="font-size:12px;color:var(--faint)">
                <?= h($lastTouch) ?>
                <?php if ($p['touch_count'] > 0): ?>
                <span style="font-size:10px;color:#bbb"> (<?= $p['touch_count'] ?>x)</span>
                <?php endif; ?>
              </td>
              <td>
                <div style="display:flex;gap:4px">
                  <button class="btn btn-ghost btn-sm" onclick='openOutreachModal(<?= $p['id'] ?>, <?= json_encode($p['owner_name']) ?>)'>Log</button>
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
          <div class="li-empty-icon">🗺️</div>
          <div class="li-empty-msg">Set up a farm area first so we know which zip codes to pull expireds from.</div>
          <button class="btn btn-primary" onclick="switchTab('farms'); openFarmModal()">+ Add Farm</button>
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
            <div class="li-empty-icon">✅</div>
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
          <div class="li-empty-icon">🗺️</div>
          <div class="li-empty-msg">No farm areas defined yet. A farm is a neighborhood or set of zip codes you focus on.</div>
          <button class="btn btn-primary" onclick="openFarmModal()">+ Add Farm</button>
        </div>
        <?php else: ?>
        <div class="farm-grid">
          <?php foreach ($farms as $farm):
            $zips = json_decode($farm['zip_codes'], true) ?: [];
            $hoods = json_decode($farm['neighborhoods'], true) ?: [];
            // Count prospects in this farm
            $sc2 = $db->prepare("SELECT COUNT(*) FROM listing_prospects WHERE agent_email=? AND farm_id=? AND status!='dead'");
            $sc2->execute([$me, $farm['id']]);
            $farmProspects = (int)$sc2->fetchColumn();
          ?>
          <div class="farm-card">
            <div class="farm-card-name"><?= h($farm['name']) ?></div>
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

    </main>
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

<script src="assets/app.js"></script>
<script>
const FARMS = <?= json_encode(array_map(fn($f) => ['id'=>$f['id'],'name'=>$f['name']], $farms)) ?>;
const CRM_BASE = <?= json_encode(rtrim(cfg()['crm_base'] ?? 'https://bold360.vip/api', '/')) ?>;
const CRM_TOKEN = <?= json_encode(cfg()['crm_token'] ?? '') ?>;

// ── Tabs ────────────────────────────────────────────────────────────────────
function switchTab(key, btn) {
    document.querySelectorAll('.li-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.li-pane').forEach(p => p.classList.remove('active'));
    if (btn) btn.classList.add('active');
    else document.querySelector(`.li-tab[onclick*="${key}"]`)?.classList.add('active');
    const pane = document.getElementById('pane-' + key);
    if (pane) {
        pane.classList.add('active');
        if (key === 'expireds') loadExpireds();
    }
}

// ── Farm modal ───────────────────────────────────────────────────────────────
function openFarmModal(id) {
    document.getElementById('farm-id').value = '';
    document.getElementById('farm-name').value = '';
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

    // Load existing log
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

// ── Prospect filters ─────────────────────────────────────────────────────────
function filterProspects() {
    const q = document.getElementById('prospect-search').value.toLowerCase();
    const st = document.getElementById('prospect-status-filter').value;
    const src = document.getElementById('prospect-source-filter').value;
    document.querySelectorAll('#prospect-table tbody tr').forEach(row => {
        const match = (!q || row.dataset.search.includes(q))
            && (!st || row.dataset.status === st)
            && (!src || row.dataset.source === src);
        row.style.display = match ? '' : 'none';
    });
}

// ── Expired pipeline ─────────────────────────────────────────────────────────
let expData = [];
async function loadExpireds() {
    const zip = document.getElementById('exp-zip')?.value || '';
    const days = document.getElementById('exp-days')?.value || '90';
    const loading = document.getElementById('exp-loading');
    const tbl = document.getElementById('exp-table');
    const empty = document.getElementById('exp-empty');
    if (!loading) return;

    loading.style.display = 'block';
    if (tbl) tbl.hidden = true;
    if (empty) empty.hidden = true;

    try {
        // Build zip list from farms if no specific zip selected
        let zips = zip ? [zip] : [];
        if (!zips.length) {
            <?php foreach ($farms as $farm):
                $fzips = json_decode($farm['zip_codes'], true) ?: []; ?>
            <?php foreach ($fzips as $fz): ?> zips.push(<?= json_encode($fz) ?>); <?php endforeach; ?>
            <?php endforeach; ?>
        }
        if (!zips.length) {
            loading.textContent = 'Add zip codes to your farm areas to load expired listings.';
            return;
        }
        const url = `${CRM_BASE}/public/listing-intel/expireds?token=${encodeURIComponent(CRM_TOKEN)}&zips=${encodeURIComponent(zips.join(','))}&days=${days}`;
        const r = await fetch(url);
        if (!r.ok) throw new Error('CRM error ' + r.status);
        const d = await r.json();
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
      <td class="num" style="font-weight:700">${l.list_price ? '$'+Number(l.list_price).toLocaleString() : '—'}</td>
      <td style="font-size:12px">${l.days_on_market ?? '—'}</td>
      <td style="font-size:12px;color:var(--faint)">${esc(l.expiration_date?.slice(0,10) || '')}</td>
      <td style="font-size:12px">${esc(l.listing_agent_name || '')}</td>
      <td>
        <button class="btn btn-ghost btn-sm" onclick='addExpiredAsProspect(${JSON.stringify(l).replace(/'/g,"&#39;")})'>+ Prospect</button>
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
</script>
</body>
</html>
