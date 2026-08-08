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

// Every real scheduled date currently in use, for the "Move to..." dropdown
// so moving someone doesn't require re-typing a date that already has a
// group — Not Yet Scheduled (blank) is added as its own explicit option there.
$scheduledDates = array_values(array_filter(array_keys($groups), fn($d) => $d !== ''));
sort($scheduledDates);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
function fmt_date(string $d): string {
    if ($d === '') return 'Not Yet Scheduled';
    $ts = strtotime($d);
    return $ts ? date('F j, Y', $ts) : $d;
}

$rosterNames = [];
$rosterSeen  = [];
foreach ($directory['rosterByName'] as $rowsForName) {
    foreach ($rowsForName as $rr) {
        $nm = trim($rr['agent_name'] ?? '');
        if ($nm === '') continue;
        $st  = strtoupper(trim($rr['state_code'] ?? ''));
        $key = strtolower($nm) . '|' . $st;
        if (isset($rosterSeen[$key])) continue;
        $rosterSeen[$key] = true;
        $rosterNames[] = [
            'name'  => $nm,
            'state' => $st,
            'mc'    => lr_mc_display($directory['mcCanonical'], $rr['market_center'] ?? '', $st),
        ];
    }
}
usort($rosterNames, fn($a, $b) => strcasecmp($a['name'], $b['name']));
$rosterNamesJson = json_encode($rosterNames, JSON_HEX_TAG | JSON_HEX_AMP);
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
    .status-on_hold{background:#fdf3e3;color:#a06a1c}
    .status-confirmed{background:#f1ecfb;color:#6b3fa0}
    .verify-chip{font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
    .verify-yes{background:#e8f0fb;color:#2b5f9e}
    .verify-no{background:#fdf3e3;color:#a06a1c}
    .move-row{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
    .move-row input,.move-row select{padding:4px 6px;border:1px solid var(--border);border-radius:5px;font-size:11.5px}
    .quick-add{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;border-top:1px solid #f0f0f0;padding-top:10px}
    .quick-add input{padding:6px 8px;border:1px solid var(--border);border-radius:5px;font-size:12px}
    .flash-ok{padding:10px 14px;background:#eef5e8;border:1px solid #c3dfa8;border-radius:6px;color:#3a6b1a;font-size:13px;margin-bottom:16px}
    .flash-err{padding:10px 14px;background:#fff0f0;border:1px solid #f5c6c6;border-radius:6px;color:#c00;font-size:13px;margin-bottom:16px}
    .lc-intro{color:var(--faint);font-size:13px;margin-bottom:20px;max-width:80ch}
    .ac-dropdown{position:absolute;top:100%;left:0;right:0;z-index:50;background:#fff;border:1px solid var(--border);border-radius:6px;box-shadow:0 6px 16px rgba(0,0,0,.12);max-height:230px;overflow-y:auto;margin-top:2px}
    .ac-item{padding:6px 10px;font-size:13px;cursor:pointer}
    .ac-item:hover,.ac-item.active{background:#eef5e8}
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
                    <select id="move-target-<?= (int)$r['id'] ?>" onchange="onMoveTargetChange(<?= (int)$r['id'] ?>)">
                      <option value="" <?= $startDate === '' ? 'selected' : '' ?>>Not Yet Scheduled</option>
                      <?php foreach ($scheduledDates as $d): ?>
                      <option value="<?= h($d) ?>" <?= $d === $startDate ? 'selected' : '' ?>><?= h(fmt_date($d)) ?></option>
                      <?php endforeach; ?>
                      <option value="__custom__">Custom date…</option>
                    </select>
                    <input type="date" id="move-date-<?= (int)$r['id'] ?>" style="display:none">
                    <button class="btn-sm" onclick="moveAgent(<?= (int)$r['id'] ?>)">Move</button>
                    <?php if (in_array($r['status'], ['active', 'confirmed'], true)): ?>
                    <button class="btn-sm" onclick="graduateAgent(<?= (int)$r['id'] ?>, '<?= h(addslashes($r['agent_name'])) ?>')">Graduate</button>
                    <?php if ($r['status'] === 'active'): ?>
                    <button class="btn-sm" onclick="setLaunchStatus(<?= (int)$r['id'] ?>, 'confirmed', '<?= h(addslashes($r['agent_name'])) ?>')">Confirm</button>
                    <?php else: ?>
                    <button class="btn-sm" onclick="setLaunchStatus(<?= (int)$r['id'] ?>, 'active', '<?= h(addslashes($r['agent_name'])) ?>')">Unconfirm</button>
                    <?php endif; ?>
                    <button class="btn-sm" onclick="setLaunchStatus(<?= (int)$r['id'] ?>, 'on_hold', '<?= h(addslashes($r['agent_name'])) ?>')">Put On Hold</button>
                    <?php elseif ($r['status'] === 'on_hold'): ?>
                    <button class="btn-sm" onclick="setLaunchStatus(<?= (int)$r['id'] ?>, 'active', '<?= h(addslashes($r['agent_name'])) ?>')">Reactivate</button>
                    <?php endif; ?>
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

function onMoveTargetChange(id) {
  const target = document.getElementById(`move-target-${id}`).value;
  document.getElementById(`move-date-${id}`).style.display = target === '__custom__' ? 'inline-block' : 'none';
}

function moveAgent(id) {
  let target = document.getElementById(`move-target-${id}`).value;
  if (target === '__custom__') {
    target = document.getElementById(`move-date-${id}`).value;
    if (!target) { flash('Pick a custom date.', 'err'); return; }
  }
  post({ action:'update_fields', id, start_date: target }).then(d => {
    if (!d.ok) { flash(d.error || 'Move failed', 'err'); return; }
    flash(target === '' ? 'Moved to Not Yet Scheduled.' : 'Moved.');
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

const STATUS_MESSAGES = {
  on_hold:   'put on hold',
  confirmed: 'confirmed',
  active:    'moved back to active',
};
function setLaunchStatus(id, status, name) {
  post({ action:'set_status', id, status }).then(d => {
    if (!d.ok) { flash(d.error || 'Failed', 'err'); return; }
    flash(`<strong>${esc(name)}</strong> ${STATUS_MESSAGES[status] || 'updated'}.`);
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

const ROSTER_NAMES = <?= $rosterNamesJson ?: '[]' ?>;

function attachAgentAutocomplete(input, stateInput, officeInput) {
  if (!input) return;
  const parent = input.parentElement;
  if (getComputedStyle(parent).position === 'static') parent.style.position = 'relative';
  const box = document.createElement('div');
  box.className = 'ac-dropdown';
  box.style.display = 'none';
  parent.appendChild(box);
  let items = [];
  let activeIdx = -1;

  function render(matches) {
    items = matches;
    activeIdx = -1;
    if (!matches.length) { box.style.display = 'none'; box.innerHTML = ''; return; }
    box.innerHTML = matches.map((m, i) => {
      const loc = [m.mc, m.state].filter(Boolean).join(', ');
      return `<div class="ac-item" data-idx="${i}">${esc(m.name)}${loc ? ` <span style="color:var(--faint);font-size:11.5px">— ${esc(loc)}</span>` : ''}</div>`;
    }).join('');
    box.style.display = 'block';
  }

  function highlight() {
    [...box.children].forEach((c, i) => c.classList.toggle('active', i === activeIdx));
  }

  function select(m) {
    input.value = m.name;
    if (stateInput && !stateInput.value.trim()) stateInput.value = m.state || '';
    if (officeInput && !officeInput.value.trim()) officeInput.value = m.mc || '';
    box.style.display = 'none';
  }

  input.addEventListener('input', () => {
    const q = input.value.trim().toLowerCase();
    if (q.length < 2) { box.style.display = 'none'; return; }
    render(ROSTER_NAMES.filter(m => m.name.toLowerCase().includes(q)).slice(0, 8));
  });

  input.addEventListener('keydown', (e) => {
    if (box.style.display === 'none') return;
    if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = Math.min(activeIdx + 1, items.length - 1); highlight(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = Math.max(activeIdx - 1, 0); highlight(); }
    else if (e.key === 'Enter') { if (activeIdx >= 0) { e.preventDefault(); select(items[activeIdx]); } }
    else if (e.key === 'Escape') { box.style.display = 'none'; }
  });

  box.addEventListener('mousedown', (e) => {
    const item = e.target.closest('.ac-item');
    if (!item) return;
    e.preventDefault();
    select(items[+item.dataset.idx]);
  });

  document.addEventListener('click', (e) => {
    if (e.target !== input && !box.contains(e.target)) box.style.display = 'none';
  });
}

attachAgentAutocomplete(document.getElementById('new-group-name'), document.getElementById('new-group-state'), document.getElementById('new-group-office'));
document.querySelectorAll('.quick-add').forEach(wrap => {
  attachAgentAutocomplete(wrap.querySelector('.add-name'), wrap.querySelector('.add-state'), wrap.querySelector('.add-office'));
});
</script>
</body>
</html>
