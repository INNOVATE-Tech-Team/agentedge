<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
$perms = current_perms();
if (empty($perms['isAdmin'])) {
    header('Location: index.php'); exit;
}

// Date range — default 7 days, accept ?days= or ?from= & ?to=
$today = date('Y-m-d');
$days  = max(1, min(365, (int)($_GET['days'] ?? 7)));
$from  = $_GET['from'] ?? date('Y-m-d', strtotime("-{$days} days"));
$to    = $_GET['to']   ?? $today;
// Sanitize
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime("-{$days} days"));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $today;
// from ≤ to
if ($from > $to) [$from, $to] = [$to, $from];

$db = local_db();

// Fetch changes in range
$rows = $db->prepare(
    "SELECT * FROM roster_changes
     WHERE date(changed_at) >= ? AND date(changed_at) <= ?
     ORDER BY changed_at DESC"
);
$rows->execute([$from, $to]);
$changes = $rows->fetchAll(PDO::FETCH_ASSOC);

$added    = array_values(array_filter($changes, fn($r) => $r['action'] === 'added'));
$removed  = array_values(array_filter($changes, fn($r) => $r['action'] === 'removed'));
$restored = array_values(array_filter($changes, fn($r) => $r['action'] === 'restored'));

function fmt_dt(string $dt): string {
    $ts = strtotime($dt . ' UTC');
    return $ts ? date('M j, Y g:ia', $ts) : $dt;
}

$stateNames = [
    'FL'=>'Florida','GA'=>'Georgia','SC'=>'South Carolina','NC'=>'North Carolina',
    'TN'=>'Tennessee','VA'=>'Virginia','MD'=>'Maryland','DE'=>'Delaware',
    'NJ'=>'New Jersey','PA'=>'Pennsylvania','OH'=>'Ohio','MA'=>'Massachusetts',
    'RI'=>'Rhode Island','NH'=>'New Hampshire',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Roster Changes — AgentEdge</title>
<link rel="stylesheet" href="assets/app.css">
<style>
.bo-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
.chg-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:20px;
             background:#fff;border:1px solid var(--border);border-radius:10px;padding:12px 16px}
.chg-toolbar label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint)}
.chg-toolbar input[type=date],.chg-toolbar select{
    padding:6px 10px;border:1px solid var(--border);border-radius:7px;font-size:13px;background:#fafafa}
.chg-toolbar input[type=date]:focus,.chg-toolbar select:focus{
    outline:2px solid var(--green);border-color:var(--green)}
.btn-filter{padding:7px 16px;background:var(--green);color:#111;font-weight:800;font-size:12px;
            border:0;border-radius:7px;cursor:pointer;white-space:nowrap}
.btn-filter:hover{background:var(--green-d,#5b8e0d);color:#fff}

.summary-strip{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px}
.ss-tile{background:#fff;border:1px solid var(--border);border-radius:10px;padding:12px 18px;min-width:100px;text-align:center}
.ss-num{font-size:28px;font-weight:900;line-height:1.1}
.ss-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);margin-top:2px}
.ss-tile.green .ss-num{color:var(--green-d,#5b8e0d)}
.ss-tile.red   .ss-num{color:#c0392b}
.ss-tile.blue  .ss-num{color:#1565c0}
.ss-empty{font-size:14px;color:var(--faint);padding:40px 0;text-align:center}

.section-head{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;
              color:#fff;padding:7px 14px;border-radius:8px 8px 0 0;display:flex;align-items:center;gap:8px}
.section-head.green{background:var(--green-d,#5b8e0d)}
.section-head.red  {background:#b71c1c}
.section-head.blue {background:#1565c0}
.section-head .badge{background:rgba(255,255,255,.25);font-size:10px;padding:1px 8px;border-radius:10px;font-weight:700}

.chg-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;
           border:1px solid var(--border);border-top:0;border-radius:0 0 10px 10px;
           margin-bottom:22px;overflow:hidden}
.chg-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;
              color:var(--faint);padding:9px 16px;text-align:left;border-bottom:1px solid var(--border)}
.chg-table td{padding:9px 16px;border-top:1px solid var(--border);vertical-align:middle}
.chg-table tr:last-child td{border-bottom:none}
.chg-table tr:hover td{background:#f8faf5}
.state-pill{display:inline-block;font-size:10px;font-weight:800;padding:2px 8px;border-radius:5px;
            background:#f0f0f0;color:#444;white-space:nowrap}
.changed-by{font-size:11px;color:var(--muted)}

/* Restore button */
.btn-restore{font-size:11px;font-weight:700;padding:3px 10px;background:none;
             border:1px solid var(--border);border-radius:5px;cursor:pointer;color:#1565c0;
             white-space:nowrap}
.btn-restore:hover{background:#e3f2fd;border-color:#1565c0}
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('backoffice_roster_changes', $agent); ?>
<div class="content">
  <div class="content-top">
    <div>
      <div class="bo-eyebrow">Back Office</div>
      <div class="content-title">Roster Changes</div>
    </div>
    <div class="content-hello">
      <a href="backoffice_roster.php" style="font-size:12px;color:var(--faint);text-decoration:none">
        ← Back to Agent Roster
      </a>
    </div>
  </div>
  <div class="wrap">

    <!-- Date filter -->
    <form method="GET" class="chg-toolbar">
      <label>From</label>
      <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
      <label>To</label>
      <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" max="<?= $today ?>">
      <button type="submit" class="btn-filter">Apply</button>
      <!-- Quick presets via JS -->
      <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap">
        <button type="button" class="btn-filter" style="background:#f0f0f0;color:#444" onclick="setRange(7)">Last 7 Days</button>
        <button type="button" class="btn-filter" style="background:#f0f0f0;color:#444" onclick="setRange(30)">Last 30 Days</button>
        <button type="button" class="btn-filter" style="background:#f0f0f0;color:#444" onclick="setRange(90)">Last 90 Days</button>
      </div>
    </form>

    <!-- Summary strip -->
    <div class="summary-strip">
      <div class="ss-tile green">
        <div class="ss-num"><?= count($added) ?></div>
        <div class="ss-lbl">Added</div>
      </div>
      <div class="ss-tile red">
        <div class="ss-num"><?= count($removed) ?></div>
        <div class="ss-lbl">Removed</div>
      </div>
      <?php if (count($restored)): ?>
      <div class="ss-tile blue">
        <div class="ss-num"><?= count($restored) ?></div>
        <div class="ss-lbl">Restored</div>
      </div>
      <?php endif; ?>
      <div class="ss-tile">
        <div class="ss-num"><?= count($changes) ?></div>
        <div class="ss-lbl">Total Changes</div>
      </div>
      <div style="align-self:center;font-size:12px;color:var(--faint);margin-left:8px">
        <?= htmlspecialchars(date('M j, Y', strtotime($from))) ?> — <?= htmlspecialchars(date('M j, Y', strtotime($to))) ?>
      </div>
    </div>

    <?php if (!$changes): ?>
    <div class="ss-empty">No roster changes in this date range.</div>
    <?php else: ?>

    <?php if ($added): ?>
    <div class="section-head green">
      <span>Agents Added</span>
      <span class="badge"><?= count($added) ?></span>
    </div>
    <table class="chg-table">
      <thead>
        <tr>
          <th>Agent Name</th>
          <th>State</th>
          <th>Market Center</th>
          <th>License Exp</th>
          <th>Added By</th>
          <th>Date &amp; Time</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($added as $r): ?>
        <tr>
          <td><strong><?= htmlspecialchars($r['agent_name']) ?></strong></td>
          <td>
            <span class="state-pill"><?= htmlspecialchars($r['state_code']) ?></span>
            <span style="font-size:11px;color:var(--faint);margin-left:5px"><?= htmlspecialchars($stateNames[$r['state_code']] ?? '') ?></span>
          </td>
          <td><?= htmlspecialchars($r['market_center'] ?: '—') ?></td>
          <td><?= $r['license_exp'] ? htmlspecialchars($r['license_exp']) : '<span style="color:var(--faint)">—</span>' ?></td>
          <td class="changed-by"><?= htmlspecialchars($r['changed_by'] ?: '—') ?></td>
          <td style="font-size:12px;white-space:nowrap;color:var(--muted)"><?= htmlspecialchars(fmt_dt($r['changed_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php if ($removed): ?>
    <div class="section-head red">
      <span>Agents Removed</span>
      <span class="badge"><?= count($removed) ?></span>
    </div>
    <table class="chg-table">
      <thead>
        <tr>
          <th>Agent Name</th>
          <th>State</th>
          <th>Market Center</th>
          <th>License Exp</th>
          <th>Removed By</th>
          <th>Date &amp; Time</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($removed as $r): ?>
        <tr id="chg-row-<?= (int)$r['id'] ?>">
          <td><strong><?= htmlspecialchars($r['agent_name']) ?></strong></td>
          <td>
            <span class="state-pill"><?= htmlspecialchars($r['state_code']) ?></span>
            <span style="font-size:11px;color:var(--faint);margin-left:5px"><?= htmlspecialchars($stateNames[$r['state_code']] ?? '') ?></span>
          </td>
          <td><?= htmlspecialchars($r['market_center'] ?: '—') ?></td>
          <td><?= $r['license_exp'] ? htmlspecialchars($r['license_exp']) : '<span style="color:var(--faint)">—</span>' ?></td>
          <td class="changed-by"><?= htmlspecialchars($r['changed_by'] ?: '—') ?></td>
          <td style="font-size:12px;white-space:nowrap;color:var(--muted)"><?= htmlspecialchars(fmt_dt($r['changed_at'])) ?></td>
          <td>
            <?php
            // Find the corresponding roster row (may still be active=0)
            $rosterRow = $db->prepare("SELECT id FROM innovate_roster WHERE agent_name=? AND state_code=? ORDER BY id DESC LIMIT 1");
            $rosterRow->execute([$r['agent_name'], $r['state_code']]);
            $rid = $rosterRow->fetchColumn();
            if ($rid):
            ?>
            <button class="btn-restore"
                    onclick="restoreAgent(<?= (int)$rid ?>, <?= json_encode($r['agent_name']) ?>, <?= (int)$r['id'] ?>)">
              Restore
            </button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php if ($restored): ?>
    <div class="section-head blue">
      <span>Agents Restored</span>
      <span class="badge"><?= count($restored) ?></span>
    </div>
    <table class="chg-table">
      <thead>
        <tr>
          <th>Agent Name</th>
          <th>State</th>
          <th>Market Center</th>
          <th>Restored By</th>
          <th>Date &amp; Time</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($restored as $r): ?>
        <tr>
          <td><strong><?= htmlspecialchars($r['agent_name']) ?></strong></td>
          <td>
            <span class="state-pill"><?= htmlspecialchars($r['state_code']) ?></span>
            <span style="font-size:11px;color:var(--faint);margin-left:5px"><?= htmlspecialchars($stateNames[$r['state_code']] ?? '') ?></span>
          </td>
          <td><?= htmlspecialchars($r['market_center'] ?: '—') ?></td>
          <td class="changed-by"><?= htmlspecialchars($r['changed_by'] ?: '—') ?></td>
          <td style="font-size:12px;white-space:nowrap;color:var(--muted)"><?= htmlspecialchars(fmt_dt($r['changed_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php endif; ?>
  </div><!-- /wrap -->
</div><!-- /content -->
</div><!-- /layout -->

<script>
function setRange(days) {
    const to   = new Date();
    const from = new Date(); from.setDate(from.getDate() - days);
    const fmt  = d => d.toISOString().slice(0,10);
    document.querySelector('input[name=from]').value = fmt(from);
    document.querySelector('input[name=to]').value   = fmt(to);
    document.querySelector('form').submit();
}

function restoreAgent(rosterId, name, changeRowId) {
    if (!confirm('Restore ' + name + ' to the active roster?')) return;
    fetch('api/roster_agent.php', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'restore', id: rosterId})
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) {
            const row = document.getElementById('chg-row-' + changeRowId);
            if (row) {
                row.style.transition = 'opacity .3s';
                row.style.opacity = '0';
                setTimeout(() => {
                    row.innerHTML = '<td colspan="7" style="text-align:center;color:var(--green-d,#5b8e0d);font-size:12px;padding:10px">✓ ' + name + ' restored to active roster</td>';
                    row.style.opacity = '1';
                }, 320);
            }
        }
    });
}
</script>
</body>
</html>
