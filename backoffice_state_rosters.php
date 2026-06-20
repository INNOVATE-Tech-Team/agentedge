<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/nav.php';
$agent = require_login();
$perms = current_perms();
if (empty($perms['isAdmin'])) {
    header('Location: index.php'); exit;
}

$statuses = state_roster_statuses_all();

// State data — all 14 states organized by tier.
$tiers = [
    1 => ['label' => 'Tier 1 — Automate Now',        'desc' => 'Free bulk downloads. No purchase, no approval — schedule a cron and pull.', 'cls' => 'tier-green'],
    2 => ['label' => 'Tier 2 — Purchase Data',        'desc' => 'Available in bulk but behind a paywall. One purchase gets a full snapshot.', 'cls' => 'tier-amber'],
    3 => ['label' => 'Tier 3 — File Records Request', 'desc' => 'No public bulk download. A written FOIA/OPRA/PIA request is the standard path.', 'cls' => 'tier-red'],
];

$states = [
    'FL' => ['name'=>'Florida',       'tier'=>1, 'commission'=>'FREC / DBPR',
             'method'=>'Free statewide CSV — no login required.',
             'format'=>'CSV', 'freq'=>'Weekly', 'cost'=>'Free', 'warn'=>null,
             'action'=>'myfloridalicense.com/real-estate-commission/public-records/'],
    'VA' => ['name'=>'Virginia',      'tier'=>1, 'commission'=>'Virginia Real Estate Board / DPOR',
             'method'=>'Free flat files by license type. Includes email addresses.',
             'format'=>'TXT / Tab-delimited', 'freq'=>'Every 5 biz days', 'cost'=>'Free', 'warn'=>null,
             'action'=>'dpor.virginia.gov/RegulantLists'],
    'DE' => ['name'=>'Delaware',      'tier'=>1, 'commission'=>'Delaware Real Estate Commission / DPR',
             'method'=>'Socrata REST API + bulk CSV. ~30K real estate records.',
             'format'=>'Socrata API / CSV', 'freq'=>'Check for updates', 'cost'=>'Free', 'warn'=>'Check freshness',
             'action'=>'data.delaware.gov/resource/pjnv-eaih.json'],
    'RI' => ['name'=>'Rhode Island',  'tier'=>1, 'commission'=>'Rhode Island DBR',
             'method'=>'"Generate a Roster" — no login. Select board, download.',
             'format'=>'Excel / CSV', 'freq'=>'On-demand', 'cost'=>'Free', 'warn'=>null,
             'action'=>'elicensing.ri.gov/Lookup/GenerateRoster.aspx'],
    'NH' => ['name'=>'New Hampshire', 'tier'=>1, 'commission'=>'NH Real Estate Commission / OPLC',
             'method'=>'Downloadable roster file — no login. Updated monthly.',
             'format'=>'Excel (XLSX)', 'freq'=>'~Monthly', 'cost'=>'Free', 'warn'=>null,
             'action'=>'oplc.nh.gov/list-oplc-licensees-and-their-license-types'],
    'OH' => ['name'=>'Ohio',          'tier'=>1, 'commission'=>'Ohio Division of Real Estate / ODRE',
             'method'=>'Free daily file at 9 AM ET. Platform migration in progress — old URL is down.',
             'format'=>'CSV', 'freq'=>'Daily · 9 AM ET', 'cost'=>'Free', 'warn'=>'Platform migration',
             'action'=>'WebReal@com.ohio.gov (email for active download link)'],
    'NC' => ['name'=>'North Carolina','tier'=>2, 'commission'=>'NC Real Estate Commission / NCREC',
             'method'=>'Annual SFTP subscription — daily CSVs including firm affiliation file. Best paid option.',
             'format'=>'CSV via SFTP', 'freq'=>'Daily', 'cost'=>'$250/yr or $15/list', 'warn'=>null,
             'action'=>'ncrec.gov/orderform · datasubscriptions@ncrec.gov'],
    'GA' => ['name'=>'Georgia',       'tier'=>2, 'commission'=>'Georgia Real Estate Commission / GREC',
             'method'=>'Paid CSV purchase. Includes company name, qualifying broker, mailing address.',
             'format'=>'CSV', 'freq'=>'~Monthly snapshot', 'cost'=>'Paid — price on request', 'warn'=>null,
             'action'=>'charge.grec.state.ga.us · grecmail@grec.state.ga.us'],
    'PA' => ['name'=>'Pennsylvania',  'tier'=>2, 'commission'=>'PA State Real Estate Commission / BPOA',
             'method'=>'List Sales Program via PALS. ~50K licensees. Note: no phone or email in data.',
             'format'=>'Excel', 'freq'=>'One-time snapshot', 'cost'=>'$71 setup + $0.005/name', 'warn'=>null,
             'action'=>'ra-listrequest@pa.gov'],
    'SC' => ['name'=>'South Carolina','tier'=>3, 'commission'=>'SC Real Estate Commission / LLR',
             'method'=>'Individual lookup only. No roster export — bulk verification requires known license numbers.',
             'format'=>'FOIA Request', 'freq'=>'—', 'cost'=>'FOIA', 'warn'=>null,
             'action'=>'Contact.REC@llr.sc.gov · (803) 896-4400'],
    'MD' => ['name'=>'Maryland',      'tier'=>3, 'commission'=>'Maryland Real Estate Commission / MREC',
             'method'=>'Legacy CGI search — no export, no wildcard-all query. ~40,000 active licensees.',
             'format'=>'PIA Request', 'freq'=>'—', 'cost'=>'PIA', 'warn'=>null,
             'action'=>'labor.maryland.gov/license/mrec/'],
    'TN' => ['name'=>'Tennessee',     'tier'=>3, 'commission'=>'Tennessee Real Estate Commission / TREC',
             'method'=>'JavaScript SPA portal — no open data, no URL-queryable interface.',
             'format'=>'Public Records Req.', 'freq'=>'—', 'cost'=>'PRR', 'warn'=>null,
             'action'=>'trec.info@tn.gov · (615) 741-2241'],
    'NJ' => ['name'=>'New Jersey',    'tier'=>3, 'commission'=>'NJ Real Estate Commission / NJDOBI',
             'method'=>'Agents at DOBI — no bulk export. ~84,145 licensees. 7-business-day OPRA response.',
             'format'=>'OPRA Request', 'freq'=>'7-day response', 'cost'=>'OPRA', 'warn'=>null,
             'action'=>'relic@dobi.nj.gov'],
    'MA' => ['name'=>'Massachusetts', 'tier'=>3, 'commission'=>'Board of Registration of RE Brokers / MA DOL',
             'method'=>'eLIPSE (Salesforce SPA). Check bulk list page first — real estate may be offered there.',
             'format'=>'Check bulk list first', 'freq'=>'—', 'cost'=>'May be free', 'warn'=>null,
             'action'=>'mass.gov/lists/download-a-list-of-approved-licensees'],
];

$tally = [1=>0, 2=>0, 3=>0];
foreach ($statuses as $s) {
    if ($s['status'] === 'active') {
        foreach ($states as $code => $st) {
            if ($code === $s['state_code']) { $tally[$st['tier']]++; break; }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>State Rosters — AgentEdge</title>
<link rel="stylesheet" href="assets/app.css">
<style>
/* ── Back Office shared ─────────────────────────────────── */
.bo-header { display:flex; align-items:baseline; gap:12px; flex-wrap:wrap; }
.bo-eyebrow { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--faint); }

/* ── Summary strip ──────────────────────────────────────── */
.roster-summary { display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap; }
.rs-tile { background:#fff; border:1px solid var(--border); border-radius:10px; padding:14px 18px;
           display:flex; flex-direction:column; gap:3px; min-width:120px; }
.rs-tile-num { font-size:22px; font-weight:800; line-height:1; }
.rs-tile-lbl { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--faint); }
.rs-tile.t1 .rs-tile-num { color:var(--green-d); }
.rs-tile.t2 .rs-tile-num { color:var(--amber); }
.rs-tile.t3 .rs-tile-num { color:var(--red); }

/* ── Tier sections ──────────────────────────────────────── */
.tier-block { margin-bottom:24px; }
.tier-head { display:flex; align-items:baseline; gap:10px; padding:10px 0 8px;
             border-bottom:2px solid var(--border); margin-bottom:0; flex-wrap:wrap; }
.tier-badge { font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.08em;
              padding:3px 8px; border-radius:4px; white-space:nowrap; }
.tier-green .tier-badge { background:#edf7dc; color:var(--green-d); }
.tier-amber .tier-badge { background:#fef3e0; color:var(--amber); }
.tier-red   .tier-badge { background:#fdecea; color:var(--red); }
.tier-head-desc { font-size:12px; color:var(--faint); }

/* ── State table ────────────────────────────────────────── */
.sr-table { width:100%; border-collapse:collapse; font-size:13px; }
.sr-table th { text-align:left; font-size:10px; font-weight:700; text-transform:uppercase;
               letter-spacing:.05em; color:var(--faint); border-bottom:1px solid var(--border);
               padding:8px 10px; white-space:nowrap; }
.sr-table td { padding:10px 10px; border-bottom:1px solid var(--border); vertical-align:top; }
.sr-table tr:last-child td { border-bottom:none; }
.sr-table tr:hover td { background:#fafbfa; }

/* State cell */
.sc-code { font-size:15px; font-weight:800; letter-spacing:.02em; display:block; }
.sc-name { font-size:11px; color:var(--faint); display:block; margin-top:1px; white-space:nowrap; }
.tier-green .sc-code { color:var(--green-d); }
.tier-amber .sc-code { color:var(--amber); }
.tier-red   .sc-code { color:var(--red); }

/* Commission + method cells */
.comm-name { font-size:11px; color:var(--muted); line-height:1.4; }
.method-text { line-height:1.45; color:var(--ink); }

/* Pills */
.pill-row { display:flex; flex-wrap:wrap; gap:4px; margin-top:4px; }
.pill { font-size:10px; font-weight:700; padding:2px 7px; border-radius:4px; white-space:nowrap; }
.pill-fmt  { background:#eef2f8; color:#2C6FA8; }
.pill-freq { background:#f4f5f1; color:var(--muted); }
.pill-warn { background:#fff3cd; color:#8a6200; }

/* Action cell */
.action-val { font-family:monospace; font-size:11px; color:var(--muted); word-break:break-all; line-height:1.5; }

/* Status select */
.status-sel { font-size:12px; font-weight:600; padding:5px 8px; border-radius:6px;
              border:1px solid var(--border); background:#fff; cursor:pointer;
              appearance:auto; min-width:110px; }
.status-sel[data-status="pending"]     { color:var(--faint); }
.status-sel[data-status="in_progress"] { color:var(--amber); border-color:var(--amber); }
.status-sel[data-status="active"]      { color:var(--green-d); border-color:var(--green-d); }
.status-saved { font-size:11px; color:var(--green-d); font-weight:700; display:none; }

/* Overflow wrapper for wide tables */
.table-scroll { overflow-x:auto; }
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('backoffice_state_rosters', $agent); ?>
<div class="content">
  <div class="content-top">
    <div>
      <div class="bo-eyebrow">Back Office</div>
      <div class="content-title">State Commission Rosters</div>
    </div>
    <div class="content-hello">Which states can be automated — and what to do for each</div>
  </div>
  <div class="wrap">

    <!-- Summary strip -->
    <div class="roster-summary">
      <div class="rs-tile t1">
        <span class="rs-tile-num"><?= $tally[1] ?>/6</span>
        <span class="rs-tile-lbl">Tier 1 Live</span>
      </div>
      <div class="rs-tile t2">
        <span class="rs-tile-num"><?= $tally[2] ?>/3</span>
        <span class="rs-tile-lbl">Tier 2 Live</span>
      </div>
      <div class="rs-tile t3">
        <span class="rs-tile-num"><?= $tally[3] ?>/5</span>
        <span class="rs-tile-lbl">Tier 3 Live</span>
      </div>
      <div class="rs-tile" style="border-left:3px solid var(--green)">
        <span class="rs-tile-num" style="color:var(--green-d)"><?= $tally[1]+$tally[2]+$tally[3] ?>/14</span>
        <span class="rs-tile-lbl">Total Live</span>
      </div>
    </div>

    <?php foreach ($tiers as $tierNum => $tier):
        $tierStates = array_filter($states, fn($s) => $s['tier'] === $tierNum);
    ?>
    <div class="tier-block card <?= $tier['cls'] ?>" style="padding:0;overflow:hidden;margin-bottom:20px">
      <div class="tier-head" style="padding:12px 16px">
        <span class="tier-badge"><?= htmlspecialchars($tier['label']) ?></span>
        <span class="tier-head-desc"><?= htmlspecialchars($tier['desc']) ?></span>
      </div>
      <div class="table-scroll">
      <table class="sr-table" aria-label="<?= htmlspecialchars($tier['label']) ?> states">
        <thead>
          <tr>
            <th style="width:70px">State</th>
            <th style="min-width:140px">Commission</th>
            <th>Access Method</th>
            <th style="min-width:110px">Format / Freq</th>
            <th style="min-width:120px">Cost</th>
            <th style="min-width:180px">Action / Contact</th>
            <th style="min-width:130px">Status</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($tierStates as $code => $st):
            $row = $statuses[$code] ?? ['status' => 'pending', 'notes' => ''];
            $statusVal = htmlspecialchars($row['status']);
        ?>
          <tr>
            <td>
              <span class="sc-code"><?= $code ?></span>
              <span class="sc-name"><?= htmlspecialchars($st['name']) ?></span>
            </td>
            <td><span class="comm-name"><?= htmlspecialchars($st['commission']) ?></span></td>
            <td>
              <span class="method-text"><?= htmlspecialchars($st['method']) ?></span>
              <?php if ($st['warn']): ?>
                <div class="pill-row"><span class="pill pill-warn">&#9888; <?= htmlspecialchars($st['warn']) ?></span></div>
              <?php endif; ?>
            </td>
            <td>
              <div class="pill-row">
                <span class="pill pill-fmt"><?= htmlspecialchars($st['format']) ?></span>
                <span class="pill pill-freq"><?= htmlspecialchars($st['freq']) ?></span>
              </div>
            </td>
            <td style="font-size:12px;font-weight:600"><?= htmlspecialchars($st['cost']) ?></td>
            <td><span class="action-val"><?= htmlspecialchars($st['action']) ?></span></td>
            <td>
              <select class="status-sel" data-code="<?= $code ?>" data-status="<?= $statusVal ?>"
                      onchange="saveStatus(this)" aria-label="Status for <?= $code ?>">
                <option value="pending"     <?= $row['status']==='pending'     ? 'selected':'' ?>>Not started</option>
                <option value="in_progress" <?= $row['status']==='in_progress' ? 'selected':'' ?>>In progress</option>
                <option value="active"      <?= $row['status']==='active'      ? 'selected':'' ?>>Live ✓</option>
              </select>
              <span class="status-saved" id="saved-<?= $code ?>">Saved</span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
    <?php endforeach; ?>

    <div class="banner" style="font-weight:400;font-size:12px">
      Researched June 2026. Commission portals and policies can change — verify URLs before automating.
      Status changes are saved immediately and visible to all admins.
    </div>

  </div><!-- /wrap -->
</div><!-- /content -->
</div><!-- /layout -->

<script>
function saveStatus(sel) {
    const code   = sel.dataset.code;
    const status = sel.value;
    sel.dataset.status = status;

    fetch('api/state_roster_status.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({state_code: code, status})
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) {
            const badge = document.getElementById('saved-' + code);
            badge.style.display = 'inline';
            setTimeout(() => badge.style.display = 'none', 1800);
        }
    })
    .catch(() => {});
}
</script>
</body>
</html>
