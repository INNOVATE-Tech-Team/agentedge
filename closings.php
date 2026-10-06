<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/closings.php';

// Same access pattern as team_dashboard.php: admins, team leaders, and plain
// team members. An agent with no team at all has no closings to see here.
$agent       = require_login();
$isAdmin     = is_admin();
$isLeader    = is_team_leader();
$myOwnTeamId = my_own_team_id();
if (!$isAdmin && !$isLeader && $myOwnTeamId === null) { header('Location: index.php'); exit; }

$db = local_db();

$allTeams = $isAdmin ? $db->query("SELECT id, name FROM teams WHERE enabled=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) : [];
$teamId   = $isAdmin ? (int)($_GET['team_id'] ?? 0) : ($isLeader ? my_team_id() : $myOwnTeamId);
if ($isAdmin && !$teamId && $allTeams) $teamId = (int)$allTeams[0]['id'];

$team = null;
if ($teamId) {
    $stmt = $db->prepare("SELECT * FROM teams WHERE id=?");
    $stmt->execute([$teamId]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$myEmail       = strtolower(trim($agent['email'] ?? ''));
$canManageMine = $team && can_edit_closing($teamId, $myEmail);
$canManageAll  = $team && can_manage_split_rules($teamId);

$members = [];
if ($teamId) {
    $emailStmt = $db->prepare("SELECT agent_email FROM team_members WHERE team_id=?");
    $emailStmt->execute([$teamId]);
    $emailList = $emailStmt->fetchAll(PDO::FETCH_COLUMN);
    if ($emailList) {
        $placeholders = implode(',', array_fill(0, count($emailList), '?'));
        $stmt = $db->prepare("SELECT agent_name, email FROM innovate_roster WHERE active=1 AND lower(email) IN ($placeholders) GROUP BY email");
        $stmt->execute(array_map('strtolower', $emailList));
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        usort($members, fn($a, $b) => strcmp($a['agent_name'] ?? '', $b['agent_name'] ?? ''));
    }
}
$nameByEmail = [];
foreach ($members as $m) { $nameByEmail[strtolower(trim($m['email']))] = $m['agent_name']; }

$closings = [];
if ($teamId) {
    $stmt = $db->prepare("SELECT * FROM closings WHERE team_id=? ORDER BY
        CASE status WHEN 'pending' THEN 0 WHEN 'closed' THEN 1 ELSE 2 END,
        COALESCE(NULLIF(closing_date_est,''), NULLIF(closing_date_actual,'')) DESC, id DESC");
    $stmt->execute([$teamId]);
    $closings = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Rollups (spec §7) — computed live from the raw rows, never hand-maintained.
$pendingCount = $pendingVolume = $closedCount = $closedVolume = 0;
$closedBySource = [];
foreach ($closings as $c) {
    if ($c['status'] === 'pending') {
        $pendingCount++;
        $pendingVolume += (float)$c['sale_price'];
    } elseif ($c['status'] === 'closed') {
        $closedCount++;
        $closedVolume += (float)$c['sale_price'];
        $src = $c['lead_source'] ?: 'unknown';
        $closedBySource[$src] = ($closedBySource[$src] ?? 0) + (float)$c['sale_price'];
    }
}
arsort($closedBySource);

$splitRule = $teamId ? get_split_rule($db, $teamId, '') : ['default_commission_rate' => 0, 'default_dwt_percent' => 0];

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
function money(float $n): string { return '$' . number_format($n, 0); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Closings — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .cl-tiles{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}
    .cl-tile{background:#fff;border:1px solid var(--border);border-radius:10px;padding:14px 18px;min-width:130px}
    .cl-tile .cl-num{font-size:24px;font-weight:800;line-height:1.1;color:var(--ink)}
    .cl-tile .cl-lbl{font-size:11px;color:var(--faint);font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-top:2px}
    .cl-tile.green .cl-num{color:var(--green-d)}
    .cl-tile.blue .cl-num{color:var(--blue)}

    .cl-panel{background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:22px}
    .cl-panel-head{padding:14px 18px;border-bottom:1px solid var(--border);font-size:14px;font-weight:800;display:flex;align-items:center;gap:10px}
    .cl-panel-head .btn{margin-left:auto}
    .btn{padding:8px 16px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;border:1px solid var(--border);background:#fff}
    .btn.primary{background:var(--green);border-color:var(--green);color:#111}
    .btn.primary:hover{background:var(--green-d);color:#fff}
    .btn.danger{color:#c0392b;border-color:#f3c6c1}
    .btn.danger:hover{background:#fff0f0}
    .btn.small{padding:4px 10px;font-size:12px}

    .cl-table{width:100%;border-collapse:collapse;font-size:13px}
    .cl-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--faint);padding:9px 14px;text-align:left;white-space:nowrap;border-bottom:1px solid var(--border)}
    .cl-table td{padding:9px 14px;border-top:1px solid var(--border);vertical-align:middle;white-space:nowrap}
    .cl-table tr:hover td{background:#fafcf7}
    .cl-addr{font-weight:600;color:var(--ink);white-space:normal}
    .cl-sub{font-size:11px;color:var(--faint)}
    .cl-badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700}
    .cl-b-pending{background:#fff3e0;color:#c87800}
    .cl-b-closed{background:#e8f5e9;color:#2e7d32}
    .cl-b-fell_through{background:#fdecea;color:#c0392b}
    .cl-source-chip{font-size:11px;color:var(--faint)}
    .cl-empty{padding:32px;text-align:center;color:var(--faint);font-size:13px;font-style:italic}
    .cl-actions{display:flex;gap:6px}
    .cl-source-row{display:flex;justify-content:space-between;padding:6px 0;font-size:13px;border-bottom:1px solid #f3f3f3}
    .cl-source-row:last-child{border-bottom:0}
    .team-switcher{padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:12px}

    .cl-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:100;align-items:center;justify-content:center;padding:20px}
    .cl-overlay.show{display:flex}
    .cl-modal{background:#fff;border-radius:12px;width:min(620px,100%);max-height:90vh;overflow:auto;padding:24px}
    .cl-modal h3{margin:0 0 16px;font-size:16px;font-weight:800}
    .cl-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    .cl-field{display:flex;flex-direction:column;gap:4px}
    .cl-field.full{grid-column:1/-1}
    .cl-field label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--faint)}
    .cl-field input,.cl-field select{padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px}
    .cl-preview{grid-column:1/-1;background:var(--bg);border-radius:8px;padding:12px 14px;font-size:12px;display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:4px}
    .cl-preview div span{display:block;font-weight:800;font-size:14px;color:var(--ink)}
    .cl-modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:20px}
    .cl-err{color:#c0392b;font-size:12px;margin-top:8px;display:none}
    .cl-split-form{display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;padding:16px 18px}
  </style>
</head>
<body>
<div class="layout">
<?php render_sidebar('closings', $agent); ?>
<div class="content">
  <div class="content-top">
    <div>
      <div class="bo-eyebrow" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)">Commissions</div>
      <div class="content-title"><?= $team ? h($team['name']) . ' — Closings' : 'Closings' ?></div>
    </div>
    <div class="content-hello" style="display:flex;align-items:center;gap:12px">
      <?php if ($isAdmin && $allTeams): ?>
      <select class="team-switcher" onchange="location.href='closings.php?team_id='+this.value">
        <?php foreach ($allTeams as $t): ?>
        <option value="<?= $t['id'] ?>"<?= $t['id']==$teamId?' selected':'' ?>><?= h($t['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <?php if ($canManageMine): ?>
      <button class="btn primary" onclick="openClosingModal()">+ Add Closing</button>
      <?php endif; ?>
    </div>
  </div>
  <div class="wrap">

  <?php if (!$team): ?>
    <div class="cl-panel"><div class="cl-empty"><?= $isAdmin ? 'No team found. Create one on the <a href="teams.php">Teams</a> page.' : 'You&rsquo;re not currently on a team.' ?></div></div>
  <?php else: ?>

    <div class="cl-tiles">
      <div class="cl-tile"><div class="cl-num"><?= $pendingCount ?></div><div class="cl-lbl">Pending</div></div>
      <div class="cl-tile"><div class="cl-num"><?= money($pendingVolume) ?></div><div class="cl-lbl">Pending Volume</div></div>
      <div class="cl-tile green"><div class="cl-num"><?= $closedCount ?></div><div class="cl-lbl">Closed</div></div>
      <div class="cl-tile green"><div class="cl-num"><?= money($closedVolume) ?></div><div class="cl-lbl">Closed Volume</div></div>
    </div>

    <div class="cl-panel">
      <div class="cl-panel-head">Closed Volume by Source</div>
      <div style="padding:6px 18px 14px">
        <?php if (!$closedBySource): ?>
          <div class="cl-empty">No closed deals yet.</div>
        <?php else: foreach ($closedBySource as $src => $vol): ?>
          <div class="cl-source-row"><span><?= h(LEAD_SOURCE_LABELS[$src] ?? ucfirst($src)) ?></span><strong><?= money($vol) ?></strong></div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <?php if ($canManageAll): ?>
    <div class="cl-panel">
      <div class="cl-panel-head">Team Default Split</div>
      <form class="cl-split-form" id="split-form">
        <input type="hidden" id="split_team_id" value="<?= (int)$teamId ?>">
        <div class="cl-field">
          <label>Default Commission %</label>
          <input type="number" step="0.01" id="split_commission" value="<?= h((string)round($splitRule['default_commission_rate']*100,4)) ?>">
        </div>
        <div class="cl-field">
          <label>Default DWT %</label>
          <input type="number" step="0.01" id="split_dwt" value="<?= h((string)round($splitRule['default_dwt_percent']*100,4)) ?>">
        </div>
        <button type="submit" class="btn primary">Save Defaults</button>
        <span id="split-saved" style="display:none;color:var(--green-d);font-size:12px;font-weight:700">Saved</span>
      </form>
    </div>
    <?php endif; ?>

    <div class="cl-panel">
      <div class="cl-panel-head">Closings<span style="margin-left:auto;font-size:11px;font-weight:600;color:var(--faint)"><?= count($closings) ?> total</span></div>
      <?php if (!$closings): ?>
        <div class="cl-empty">No closings logged yet.</div>
      <?php else: ?>
      <div style="overflow-x:auto">
      <table class="cl-table">
        <thead>
          <tr>
            <th>Address</th><th>Agent</th><th>Status</th><th>Price</th><th>Source</th>
            <th>GCI</th><th>Referral Out</th><th>Team Member</th><th>DWT</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($closings as $c):
              $calc = closing_calc($c);
              $canEditRow = can_edit_closing($teamId, $c['agent_email']);
          ?>
          <tr>
            <td><div class="cl-addr"><?= h($c['property_address'] ?: '—') ?></div><?php if ($c['client_names']): ?><div class="cl-sub"><?= h($c['client_names']) ?></div><?php endif; ?></td>
            <td><?= h($nameByEmail[strtolower($c['agent_email'])] ?? $c['agent_email']) ?></td>
            <td><span class="cl-badge cl-b-<?= h($c['status']) ?>"><?= h(closing_status_label($c['status'])) ?></span></td>
            <td><?= money((float)$c['sale_price']) ?></td>
            <td class="cl-source-chip"><?= h(LEAD_SOURCE_LABELS[$c['lead_source']] ?? 'Unknown') ?></td>
            <td><?= money($calc['gci']) ?></td>
            <td><?= $calc['outgoing_referral_amount'] > 0 ? money($calc['outgoing_referral_amount']) : '—' ?></td>
            <td><?= money($calc['team_member_payout']) ?></td>
            <td><?= money($calc['dwt_payout']) ?></td>
            <td class="cl-actions">
              <?php if ($canEditRow): ?>
              <button class="btn small" onclick='openClosingModal(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)'>Edit</button>
              <button class="btn small danger" onclick="deleteClosing(<?= (int)$c['id'] ?>)">Delete</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>

  <?php endif; ?>
  </div>
</div>
</div>

<div class="cl-overlay" id="cl-overlay">
  <div class="cl-modal">
    <h3 id="cl-modal-title">Add Closing</h3>
    <form id="cl-form">
      <input type="hidden" id="f_id" value="">
      <div class="cl-grid">
        <div class="cl-field full">
          <label>Property Address</label>
          <input type="text" id="f_property_address" required>
        </div>
        <?php if (count($members) > 1 || $isAdmin): ?>
        <div class="cl-field">
          <label>Agent</label>
          <select id="f_agent_email">
            <?php foreach ($members as $m): ?>
            <option value="<?= h(strtolower(trim($m['email']))) ?>"<?= strtolower(trim($m['email']))===$myEmail?' selected':'' ?>><?= h($m['agent_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php else: ?>
        <input type="hidden" id="f_agent_email" value="<?= h($myEmail) ?>">
        <?php endif; ?>
        <div class="cl-field">
          <label>Status</label>
          <select id="f_status">
            <?php foreach (CLOSING_STATUS_LABELS as $k=>$lbl): ?>
            <option value="<?= h($k) ?>"><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="cl-field">
          <label>Client Name(s)</label>
          <input type="text" id="f_client_names">
        </div>
        <div class="cl-field">
          <label>Client Email <span style="text-transform:none;font-weight:400">(for FUB source lookup)</span></label>
          <input type="email" id="f_client_email">
        </div>
        <div class="cl-field">
          <label>Sale Price ($)</label>
          <input type="number" step="0.01" id="f_sale_price" value="0">
        </div>
        <div class="cl-field">
          <label>Lead Source (override)</label>
          <select id="f_lead_source">
            <option value="">— auto from FUB / unset —</option>
            <?php foreach (LEAD_SOURCE_LABELS as $k=>$lbl): if ($k==='unknown') continue; ?>
            <option value="<?= h($k) ?>"><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="cl-field">
          <label>Contract Date</label>
          <input type="date" id="f_contract_date">
        </div>
        <div class="cl-field">
          <label>Est. Closing Date</label>
          <input type="date" id="f_closing_date_est">
        </div>
        <div class="cl-field">
          <label>Actual Closing Date</label>
          <input type="date" id="f_closing_date_actual">
        </div>
        <div class="cl-field">
          <label>Commission %</label>
          <input type="number" step="0.01" id="f_commission_rate" value="<?= h((string)round($splitRule['default_commission_rate']*100,4)) ?>">
        </div>
        <div class="cl-field">
          <label>Bonus ($)</label>
          <input type="number" step="0.01" id="f_bonus_amount" value="0">
        </div>
        <div class="cl-field">
          <label>DWT %</label>
          <input type="number" step="0.01" id="f_dwt_percent" value="<?= h((string)round($splitRule['default_dwt_percent']*100,4)) ?>">
        </div>
        <div class="cl-field">
          <label>Outgoing Referral %</label>
          <input type="number" step="0.01" id="f_outgoing_referral_percent" value="0">
        </div>

        <div class="cl-preview" id="cl-preview">
          <div>GCI<span id="pv_gci">$0</span></div>
          <div>Referral Out<span id="pv_ref">$0</span></div>
          <div>Team Member<span id="pv_team">$0</span></div>
          <div>DWT Payout<span id="pv_dwt">$0</span></div>
        </div>
      </div>
      <div class="cl-err" id="cl-err"></div>
      <div class="cl-modal-actions">
        <button type="button" class="btn" onclick="closeClosingModal()">Cancel</button>
        <button type="submit" class="btn primary">Save</button>
      </div>
    </form>
  </div>
</div>

<script src="assets/closings.js"></script>
</body>
</html>
