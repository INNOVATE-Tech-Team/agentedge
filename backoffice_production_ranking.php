<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
$agent = require_login();
require_admin_page();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }

// Company-wide, admin-only — see backoffice_production_ranking's nav entry
// (no 'leaderVisible' flag) and project_mc_leader_bic_operations_access memory
// for why mc_leader/bic don't get this: it's explicitly a cross-MC list.
$rows = local_db()->query(
    "SELECT sv.agent_name, sv.ytd_sales_volume, sv.ytd_transaction_count, cp.agent_email
       FROM darwin_sales_volume sv
       JOIN darwin_cap_progress cp ON cp.agent_person_id = sv.agent_person_id
      WHERE cp.is_active_agent = 1"
)->fetchAll(PDO::FETCH_ASSOC);

// Email -> Market Center, same join pattern used elsewhere for Darwin data
// (email is the only field darwin_sales_volume/darwin_cap_progress share with
// AgentEdge's own roster).
$mcByEmail = [];
foreach (local_db()->query("SELECT email, market_center FROM innovate_roster WHERE active=1 AND email != ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $email = strtolower(trim($r['email']));
    if ($r['market_center'] && !isset($mcByEmail[$email])) $mcByEmail[$email] = $r['market_center'];
}

$agents = [];
$totalVolume = 0.0;
$totalDeals  = 0.0;
foreach ($rows as $r) {
    $email = strtolower(trim($r['agent_email'] ?? ''));
    $volume = (float)$r['ytd_sales_volume'];
    $deals  = (float)$r['ytd_transaction_count'];
    $totalVolume += $volume;
    $totalDeals  += $deals;
    $agents[] = [
        'name'   => $r['agent_name'],
        'mc'     => $mcByEmail[$email] ?? '—',
        'volume' => $volume,
        'deals'  => $deals,
    ];
}
usort($agents, fn($a, $b) => $b['volume'] <=> $a['volume']);
foreach ($agents as $i => &$a) { $a['rank'] = $i + 1; }
unset($a);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Production Ranking — AgentEdge</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="stylesheet" href="assets/app.css">
<style>
.bo-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
.pr-note{font-size:12px;color:var(--faint);background:#fafbfa;border:1px solid var(--border);
         border-radius:8px;padding:10px 14px;margin-bottom:18px;line-height:1.5}
.roster-summary{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}
.rs-tile{background:#fff;border:1px solid var(--border);border-radius:10px;padding:14px 18px;min-width:120px}
.rs-tile .rs-num{font-size:26px;font-weight:800;line-height:1.1}
.rs-tile .rs-lbl{font-size:11px;color:var(--faint);font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-top:2px}
.rs-tile.green .rs-num{color:var(--green-d,#5b8e0d)}
.pr-toolbar{display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap}
.pr-search{flex:1;max-width:320px;padding:8px 12px;border:1px solid var(--border);border-radius:8px;
           font-size:13px;background:#fafafa;box-sizing:border-box}
.pr-search:focus{outline:2px solid var(--green);border-color:var(--green)}
.pr-count{font-size:12px;color:var(--faint)}
.detail-panel{background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden}
.agent-table{width:100%;border-collapse:collapse;font-size:13px}
.agent-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;
                color:var(--faint);padding:8px 18px;text-align:left;white-space:nowrap;cursor:pointer;
                user-select:none}
.agent-table th:hover{color:var(--ink)}
.agent-table th.sorted::after{content:' \25BC';font-size:8px}
.agent-table td{padding:8px 18px;border-top:1px solid var(--border);vertical-align:middle}
.agent-table tr:hover td{background:#f8faf5}
.pr-rank{font-weight:800;color:var(--faint);width:36px}
.pr-rank.top3{color:#c87800}
.pr-vol{font-weight:700;color:#111;white-space:nowrap}
.pr-mc{font-size:12px;color:var(--muted)}
.no-results{padding:32px;text-align:center;color:var(--faint);font-size:13px}
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('backoffice_production_ranking', $agent); ?>
<div class="content">
  <div class="content-top">
    <div>
      <div class="bo-eyebrow">Back Office</div>
      <div class="content-title">Production Ranking</div>
    </div>
  </div>
  <div class="wrap">

    <div class="pr-note">
      Ranked by <strong>YTD sales volume</strong> from Darwin (AccountTECH) — INNOVATE's Darwin data starts
      2026-02-27, so this isn't a full calendar year yet and can't be a true trailing-12-month figure. Once a full
      year of Darwin history has accumulated (roughly early-to-mid 2027), this ranking can move to a real
      trailing-12-month basis — daily snapshots are already being logged toward that.
    </div>

    <div class="roster-summary">
      <div class="rs-tile">
        <div class="rs-num"><?= count($agents) ?></div>
        <div class="rs-lbl">Ranked Agents</div>
      </div>
      <div class="rs-tile green">
        <div class="rs-num" id="pr-total-vol"></div>
        <div class="rs-lbl">Company YTD Volume</div>
      </div>
      <div class="rs-tile">
        <div class="rs-num"><?= number_format($totalDeals, $totalDeals == (int)$totalDeals ? 0 : 2) ?></div>
        <div class="rs-lbl">Company YTD Deals</div>
      </div>
    </div>

    <div class="detail-panel">
      <div class="pr-toolbar" style="padding:14px 18px 0">
        <input type="text" id="pr-search" class="pr-search" placeholder="Search agents or Market Center…" autocomplete="off">
        <span class="pr-count" id="pr-count"></span>
      </div>
      <div style="padding:0 18px 14px;overflow-x:auto">
        <table class="agent-table" id="pr-table">
          <thead>
            <tr>
              <th style="width:36px">#</th>
              <th data-sort="name">Agent</th>
              <th data-sort="mc">Market Center</th>
              <th data-sort="volume" class="sorted">YTD Volume</th>
              <th data-sort="deals">YTD Deals</th>
            </tr>
          </thead>
          <tbody id="pr-body">
            <?php foreach ($agents as $a): ?>
            <tr data-search="<?= h(strtolower($a['name'] . ' ' . $a['mc'])) ?>"
                data-name="<?= h($a['name']) ?>" data-mc="<?= h($a['mc']) ?>"
                data-volume="<?= $a['volume'] ?>" data-deals="<?= $a['deals'] ?>" data-rank="<?= $a['rank'] ?>">
              <td class="pr-rank<?= $a['rank']<=3?' top3':'' ?>"><?= $a['rank'] ?></td>
              <td><?= h($a['name']) ?></td>
              <td class="pr-mc"><?= h($a['mc']) ?></td>
              <td class="pr-vol" data-vol-cell><?= number_format($a['volume']) ?></td>
              <td><?= number_format($a['deals'], $a['deals'] == (int)$a['deals'] ? 0 : 2) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="no-results" id="pr-no-results" style="display:none">No agents match your search.</div>
      </div>
    </div>

  </div>
</div>
</div>

<script>
function fmtMoney(v) {
    return '$' + Math.round(v).toLocaleString();
}
document.getElementById('pr-total-vol').textContent = fmtMoney(<?= json_encode($totalVolume) ?>);
document.querySelectorAll('#pr-body [data-vol-cell]').forEach(td => {
    td.textContent = fmtMoney(parseFloat(td.closest('tr').dataset.volume));
});

// ── Search ───────────────────────────────────────────────────────────────────
const searchEl = document.getElementById('pr-search');
const countEl  = document.getElementById('pr-count');
function applySearch() {
    const q = searchEl.value.toLowerCase().trim();
    const rows = document.querySelectorAll('#pr-body tr');
    let visible = 0;
    rows.forEach(row => {
        const show = !q || row.dataset.search.indexOf(q) !== -1;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('pr-no-results').style.display = visible === 0 ? '' : 'none';
    countEl.textContent = q ? visible + ' of ' + rows.length + ' agents' : rows.length + ' agents';
}
searchEl.addEventListener('input', applySearch);
applySearch();

// ── Sortable columns ─────────────────────────────────────────────────────────
// The '#' column always shows each agent's true production rank (fixed, by
// YTD volume) regardless of how the table is currently sorted — sorting by
// Agent/MC/Deals re-orders rows but never renumbers that column.
let sortKey = 'volume', sortDir = -1;
function renderSort() {
    const tbody = document.getElementById('pr-body');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    rows.sort((a, b) => {
        let av = a.dataset[sortKey], bv = b.dataset[sortKey];
        if (sortKey === 'volume' || sortKey === 'deals') { av = parseFloat(av); bv = parseFloat(bv); }
        else { av = av.toLowerCase(); bv = bv.toLowerCase(); }
        if (av < bv) return -1 * sortDir;
        if (av > bv) return 1 * sortDir;
        return 0;
    });
    rows.forEach(row => tbody.appendChild(row));
    document.querySelectorAll('#pr-table th[data-sort]').forEach(th => {
        th.classList.toggle('sorted', th.dataset.sort === sortKey);
    });
}
document.querySelectorAll('#pr-table th[data-sort]').forEach(th => {
    th.addEventListener('click', () => {
        const key = th.dataset.sort;
        if (key === sortKey) { sortDir *= -1; }
        else { sortKey = key; sortDir = (key === 'name' || key === 'mc') ? 1 : -1; }
        renderSort();
    });
});
</script>
</body>
</html>
