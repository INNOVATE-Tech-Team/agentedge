<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/launch_roster.php';

$agent = require_login();
if (!can_manage_launch_roster()) { header('Location: index.php'); exit; }

$db = local_db();
launch_roster_recalc_graduation($db);
$roster    = launch_roster_fetch_all($db, "start_date = '' , start_date, agent_name");
$directory = launch_roster_build_directory($db);

$groups = [];   // start_date (or '' for unscheduled) => [rows]
foreach ($roster as $r) {
    if ($r['status'] === 'dropped') continue;
    $resolved = launch_roster_resolve_agent($directory, $r['agent_name'], $r['state']);
    $r['market_center'] = $resolved['matched'] ? $resolved['market_center'] : $r['office'];
    $r['phone']          = $resolved['phone'];
    $r['verified']       = $resolved['matched'];
    $groups[$r['start_date']][] = $r;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
function fmt_date(string $d): string {
    if ($d === '') return 'Not Yet Scheduled';
    $ts = strtotime($d);
    return $ts ? date('F j, Y', $ts) : $d;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Launch Schedule — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .lc-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:20px 24px;margin-bottom:20px}
    .lc-card h3{margin:0 0 14px;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
    .lc-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px}
    .field-group{display:flex;flex-direction:column;gap:4px}
    .field-group.grow{flex:1;min-width:160px}
    .field-group.sm{min-width:90px;width:110px}
    .field-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint)}
    .field-input{padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;width:100%;box-sizing:border-box;background:#fff}
    .btn-add{padding:9px 20px;background:var(--green);color:#111;font-weight:800;font-size:13px;border:0;border-radius:6px;cursor:pointer;white-space:nowrap}
    .btn-add:hover{background:var(--green-d,#5b8e0d);color:#fff}
    .btn-sm{padding:5px 10px;font-size:11px;border-radius:5px;border:1px solid var(--border);background:#fff;cursor:pointer;white-space:nowrap}
    .btn-sm:hover{border-color:#82C112;color:#5b8e0d}

    .group-list{display:flex;flex-direction:column;gap:14px}
    .group-item{border:1px solid var(--border);border-radius:10px;padding:16px 20px;background:#fff}
    .group-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
    .group-title{font-size:15px;font-weight:800}
    .group-meta{font-size:12px;color:var(--faint)}
    .roster-table{width:100%;border-collapse:collapse;font-size:12.5px;margin-bottom:10px}
    .roster-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);padding:6px 8px;text-align:left;border-bottom:1px solid var(--border)}
    .roster-table td{padding:6px 8px;border-top:1px solid var(--border)}
    .status-chip{font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;text-transform:uppercase;letter-spacing:.04em}
    .status-active{background:#eef5e8;color:#5b8e0d}
    .status-graduated{background:#e8f0fb;color:#2b5f9e}
    .verify-chip{font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
    .verify-yes{background:#e8f0fb;color:#2b5f9e}
    .verify-no{background:#fdf3e3;color:#a06a1c}
    .move-row{display:flex;gap:6px;align-items:center}
    .move-row input{padding:4px 6px;border:1px solid var(--border);border-radius:5px;font-size:11.5px}
    .quick-add{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;border-top:1px solid #f0f0f0;padding-top:10px}
    .quick-add input{padding:6px 8px;border:1px solid var(--border);border-radius:5px;font-size:12px}
    .flash-ok{padding:10px 14px;background:#eef5e8;border:1px solid #c3dfa8;border-radius:6px;color:#3a6b1a;font-size:13px;margin-bottom:16px}
    .flash-err{padding:10px 14px;background:#fff0f0;border:1px solid #f5c6c6;border-radius:6px;color:#c00;font-size:13px;margin-bottom:16px}
    .lc-intro{color:var(--faint);font-size:13px;margin-bottom:20px;max-width:80ch}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('launch_schedule', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)">Agent Development</div>
        <div class="content-title">Launch Schedule</div>
      </div>
    </header>
    <main class="wrap">
      <div class="lc-intro">Who's starting LAUNCH and when, grouped by start date. Coach assignments, notes, and deal counts live on the <a href="launch_coaching.php">Launch Coaching</a> tab — this view is for scheduling.</div>
      <div id="flash-area"></div>

      <div class="lc-card">
        <h3>Schedule a New Start Date</h3>
        <div class="lc-row">
          <div class="field-group sm">
            <div class="field-label">Start Date</div>
            <input type="date" id="new-group-date" class="field-input">
          </div>
          <div class="field-group grow">
            <div class="field-label">Agent Name</div>
            <input type="text" id="new-group-name" class="field-input" placeholder="Full name" autocomplete="off">
          </div>
          <div class="field-group sm">
            <div class="field-label">State</div>
            <input type="text" id="new-group-state" class="field-input" placeholder="SC" maxlength="2" style="text-transform:uppercase" autocomplete="off">
          </div>
          <div class="field-group grow">
            <div class="field-label">Market Center</div>
            <input type="text" id="new-group-office" class="field-input" placeholder="e.g. Pro Drive (used only if not found on the roster)" autocomplete="off">
          </div>
          <button class="btn-add" onclick="createGroupEntry()">Add</button>
        </div>
      </div>

      <div class="group-list">
        <?php foreach ($groups as $startDate => $rows): ?>
        <div class="group-item">
          <div class="group-header">
            <div>
              <div class="group-title"><?= h(fmt_date($startDate)) ?></div>
              <div class="group-meta"><?= count($rows) ?> agent<?= count($rows) === 1 ? '' : 's' ?></div>
            </div>
          </div>
          <table class="roster-table">
            <thead><tr><th>Agent</th><th>State</th><th>Market Center</th><th>Phone</th><th>Roster Match</th><th>Status</th><th>Move / Remove</th></tr></thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= h($r['agent_name']) ?></td>
                <td><?= h($r['state']) ?></td>
                <td><?= h($r['market_center'] ?: '—') ?></td>
                <td><?= h($r['phone'] ?: '—') ?></td>
                <td><span class="verify-chip <?= $r['verified'] ? 'verify-yes' : 'verify-no' ?>"><?= $r['verified'] ? 'Verified' : 'Unverified' ?></span></td>
                <td><span class="status-chip status-<?= h($r['status']) ?>"><?= h($r['status']) ?></span></td>
                <td>
                  <div class="move-row">
                    <input type="date" id="move-date-<?= (int)$r['id'] ?>" value="<?= h($startDate) ?>">
                    <button class="btn-sm" onclick="moveAgent(<?= (int)$r['id'] ?>)">Move</button>
                    <button class="btn-sm" onclick="clearDate(<?= (int)$r['id'] ?>)">Unschedule</button>
                    <?php if ($r['status'] === 'active'): ?><button class="btn-sm" onclick="graduateAgent(<?= (int)$r['id'] ?>, '<?= h(addslashes($r['agent_name'])) ?>')">Graduate</button><?php endif; ?>
                    <button class="btn-sm" onclick="deleteAgent(<?= (int)$r['id'] ?>, '<?= h(addslashes($r['agent_name'])) ?>')">Delete</button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div class="quick-add">
            <input type="text" class="add-name" placeholder="Add agent to this date..." autocomplete="off">
            <input type="text" class="add-state" placeholder="State" style="width:60px;text-transform:uppercase" autocomplete="off">
            <input type="text" class="add-office" placeholder="Market Center" autocomplete="off">
            <button class="btn-sm" onclick="quickAdd('<?= h($startDate) ?>', this)">Add to group</button>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$groups): ?>
        <div class="lc-card" style="text-align:center;color:var(--faint)">No one on the schedule yet. Add an agent above.</div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</div>
<script>
function flash(msg, type='ok') {
  const el = document.getElementById('flash-area');
  el.innerHTML = `<div class="flash-${type}">${msg}</div>`;
  setTimeout(() => el.innerHTML = '', 4000);
}
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function post(data) {
  return fetch('api/launch_roster_action.php', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify(data) }).then(r => r.json());
}

function createGroupEntry() {
  const name = document.getElementById('new-group-name').value.trim();
  const date = document.getElementById('new-group-date').value;
  if (!name) { flash('Agent name is required.', 'err'); return; }
  if (!date) { flash('Start date is required.', 'err'); return; }
  post({
    action: 'create',
    agent_name: name,
    start_date: date,
    state: document.getElementById('new-group-state').value.trim().toUpperCase(),
    office: document.getElementById('new-group-office').value.trim(),
  }).then(d => {
    if (!d.ok) { flash(d.error || 'Add failed', 'err'); return; }
    flash(`Added <strong>${esc(name)}</strong> to ${esc(date)}.`);
    setTimeout(() => location.reload(), 800);
  });
}

function quickAdd(startDate, btn) {
  const wrap = btn.closest('.quick-add');
  const name = wrap.querySelector('.add-name').value.trim();
  if (!name) { flash('Agent name is required.', 'err'); return; }
  post({
    action: 'create',
    agent_name: name,
    start_date: startDate,
    state: wrap.querySelector('.add-state').value.trim().toUpperCase(),
    office: wrap.querySelector('.add-office').value.trim(),
  }).then(d => {
    if (!d.ok) { flash(d.error || 'Add failed', 'err'); return; }
    flash(`Added <strong>${esc(name)}</strong>.`);
    setTimeout(() => location.reload(), 800);
  });
}

function moveAgent(id) {
  const date = document.getElementById(`move-date-${id}`).value;
  if (!date) { flash('Pick a date to move to.', 'err'); return; }
  post({ action:'update_fields', id, start_date: date }).then(d => {
    if (!d.ok) { flash(d.error || 'Move failed', 'err'); return; }
    setTimeout(() => location.reload(), 500);
  });
}

function clearDate(id) {
  post({ action:'update_fields', id, start_date: '' }).then(d => {
    if (!d.ok) { flash(d.error || 'Failed', 'err'); return; }
    setTimeout(() => location.reload(), 500);
  });
}

function graduateAgent(id, name) {
  if (!confirm(`Mark ${name} as graduated from LAUNCH?`)) return;
  post({ action:'set_status', id, status: 'graduated' }).then(d => {
    if (!d.ok) { flash(d.error || 'Failed', 'err'); return; }
    flash(`<strong>${esc(name)}</strong> graduated.`);
    setTimeout(() => location.reload(), 600);
  });
}

function deleteAgent(id, name) {
  if (!confirm(`Remove ${name} from the schedule entirely? This deletes their roster record (and any logged deals), not just this date. This can't be undone.`)) return;
  post({ action:'delete', id }).then(d => {
    if (!d.ok) { flash(d.error || 'Delete failed', 'err'); return; }
    flash(`Removed <strong>${esc(name)}</strong>.`);
    setTimeout(() => location.reload(), 600);
  });
}
</script>
</body>
</html>
