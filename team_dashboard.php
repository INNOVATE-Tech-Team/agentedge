<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';

$agent   = require_login();
$isAdmin = is_admin();
if (!$isAdmin && !is_team_leader()) { header('Location: index.php'); exit; }

$db = local_db();

// Admins can browse any team via ?team_id=; a team leader always sees their own.
$allTeams = $isAdmin ? $db->query("SELECT id, name FROM teams WHERE enabled=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) : [];
$teamId   = $isAdmin ? (int)($_GET['team_id'] ?? 0) : my_team_id();
if ($isAdmin && !$teamId && $allTeams) $teamId = (int)$allTeams[0]['id'];

$team = null;
if ($teamId) {
    $stmt = $db->prepare("SELECT * FROM teams WHERE id=?");
    $stmt->execute([$teamId]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$today  = date('Y-m-d');
$warn60 = date('Y-m-d', strtotime('+60 days'));

$members = [];
if ($teamId) {
    $emailStmt = $db->prepare("SELECT agent_email FROM team_members WHERE team_id=?");
    $emailStmt->execute([$teamId]);
    $emailList = $emailStmt->fetchAll(PDO::FETCH_COLUMN);
    if ($emailList) {
        $placeholders = implode(',', array_fill(0, count($emailList), '?'));
        $stmt = $db->prepare("SELECT * FROM innovate_roster WHERE active=1 AND lower(email) IN ($placeholders)");
        $stmt->execute(array_map('strtolower', $emailList));
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        usort($members, fn($a, $b) => strcmp($a['agent_name'] ?? '', $b['agent_name'] ?? ''));
    }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Team Dashboard — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .bo-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
    .roster-summary{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}
    .rs-tile{background:#fff;border:1px solid var(--border);border-radius:10px;padding:14px 18px;min-width:120px}
    .rs-tile .rs-num{font-size:26px;font-weight:800;line-height:1.1}
    .rs-tile .rs-lbl{font-size:11px;color:var(--faint);font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-top:2px}
    .rs-tile.green .rs-num{color:var(--green-d,#5b8e0d)}
    #prod-loading{font-size:11px;color:var(--faint);font-style:italic;align-self:center}
    .detail-panel{background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden}
    .agent-table{width:100%;border-collapse:collapse;font-size:13px}
    .agent-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;
                    color:var(--faint);padding:10px 18px;text-align:left;white-space:nowrap}
    .agent-table td{padding:9px 18px;border-top:1px solid var(--border);vertical-align:middle}
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
    .mc-chip{font-size:11px;color:var(--faint)}
    .empty-state{text-align:center;padding:40px;color:var(--faint);font-size:14px}
    .team-switcher{padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:12px}
  </style>
</head>
<body>
<div class="layout">
<?php render_sidebar('team_dashboard', $agent); ?>
<div class="content">
  <div class="content-top">
    <div>
      <div class="bo-eyebrow">My Team</div>
      <div class="content-title"><?= $team ? h($team['name']) : 'Team Dashboard' ?></div>
    </div>
    <div class="content-hello" style="display:flex;align-items:center;gap:12px">
      <?php if ($isAdmin && $allTeams): ?>
      <select class="team-switcher" onchange="location.href='team_dashboard.php?team_id='+this.value">
        <?php foreach ($allTeams as $t): ?>
        <option value="<?= $t['id'] ?>"<?= $t['id']==$teamId?' selected':'' ?>><?= h($t['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <?= count($members) ?> agent<?= count($members)!==1?'s':'' ?>
    </div>
  </div>
  <div class="wrap">

    <?php if (!$team): ?>
      <div class="detail-panel"><div class="empty-state">No team found. <?= $isAdmin ? 'Create one on the <a href="teams.php">Teams</a> page.' : 'Contact a super admin to set up your team.' ?></div></div>
    <?php else: ?>

    <div class="roster-summary">
      <div class="rs-tile">
        <div class="rs-num"><?= count($members) ?></div>
        <div class="rs-lbl">Team Members</div>
      </div>
      <span id="prod-loading">Loading production…</span>
      <div class="rs-tile green" id="prod-vol-tile" style="display:none">
        <div class="rs-num" id="prod-vol-num">—</div>
        <div class="rs-lbl">YTD Volume</div>
      </div>
      <div class="rs-tile" id="prod-deals-tile" style="display:none">
        <div class="rs-num" id="prod-deals-num">—</div>
        <div class="rs-lbl">YTD Deals</div>
      </div>
    </div>

    <div class="detail-panel">
      <?php if (!$members): ?>
        <div class="empty-state">No members on this team yet. <?= $isAdmin ? 'Add some on the <a href="teams.php">Teams</a> page.' : 'Ask a super admin to add agents to your team.' ?></div>
      <?php else: ?>
      <table class="agent-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Market Center</th>
            <th>Volume</th>
            <th>Deals</th>
            <th>License Expires</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($members as $m): ?>
          <tr data-agent="<?= h($m['agent_name']) ?>" data-email="<?= h(strtolower(trim($m['email'] ?? ''))) ?>">
            <td>
              <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                <span><?= h($m['agent_name']) ?></span>
                <?php if (!empty($m['email'])): ?>
                <span style="font-size:11px;color:var(--faint)"><?= h($m['email']) ?></span>
                <?php endif; ?>
              </div>
            </td>
            <td class="mc-chip"><?= h($m['market_center'] ?: '—') ?><?= $m['state_code'] ? ' (' . h($m['state_code']) . ')' : '' ?></td>
            <td class="prod-cell-vol"><span class="prod-none">—</span></td>
            <td class="prod-cell-deals"><span class="prod-none">—</span></td>
            <td>
              <?php
              $exp = $m['license_exp'] ?? '';
              if (!$exp)             echo '<span class="exp-badge none">—</span>';
              elseif ($exp<=$today)  echo '<span class="exp-badge expired">Expired ' . h($exp) . '</span>';
              elseif ($exp<=$warn60) echo '<span class="exp-badge expiring">Expiring ' . h($exp) . '</span>';
              else                   echo '<span class="exp-badge ok">' . h($exp) . '</span>';
              ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <?php endif; ?>

  </div>
</div>
</div>
<script>
function normName(n) { return (n||'').toLowerCase().replace(/[^a-z ]/g,' ').replace(/\s+/g,' ').trim(); }
// Same fallback backoffice_roster.php's production lookup uses — the roster
// name (e.g. "Paul F Mayer") and Darwin's name (e.g. "Paul Mayer") don't
// always agree on a middle name/initial, so an exact match first, then
// first+last word, catches the common case a direct lookup misses.
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
<?php if ($team): ?>
fetch('api/team_production.php?team_id=<?= (int)$teamId ?>', {credentials:'same-origin'})
  .then(r=>r.json())
  .then(d=>{
    const loading=document.getElementById('prod-loading');
    if (loading) loading.style.display='none';
    if (!d.ok) return;
    if (d.total_volume>0){ document.getElementById('prod-vol-num').textContent=fmtVol(d.total_volume); document.getElementById('prod-vol-tile').style.display=''; }
    if (d.total_deals>0) { document.getElementById('prod-deals-num').textContent=d.total_deals.toLocaleString(); document.getElementById('prod-deals-tile').style.display=''; }
    const map=d.agents||{};
    const byEmail=d.agentsByEmail||{};
    document.querySelectorAll('tr[data-agent]').forEach(row=>{
      const email=(row.dataset.email||'').trim();
      const prod=(email && byEmail[email]) || lookupProd(row.dataset.agent, map);
      const vt=row.querySelector('.prod-cell-vol'), dt=row.querySelector('.prod-cell-deals');
      if (!vt||!dt||!prod) return;
      if (prod.volume>0||prod.deals>0){
        vt.innerHTML='<span class="prod-vol">'+fmtVol(prod.volume)+'</span>';
        dt.innerHTML='<span class="prod-deals">'+(prod.deals||0)+'</span>';
      }
    });
  })
  .catch(()=>{ const l=document.getElementById('prod-loading'); if(l)l.style.display='none'; });
<?php endif; ?>
</script>
</body>
</html>
