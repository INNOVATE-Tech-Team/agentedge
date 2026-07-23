<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';

$agent = require_login();
if (!can_manage_cohorts()) { header('Location: index.php'); exit; }

$db      = local_db();
$cohorts = $db->query("SELECT * FROM cohorts WHERE program='launch' ORDER BY start_date DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
$coaches = $db->query("SELECT email FROM agent_roles WHERE role IN ('launch_coach','director_of_coaching') ORDER BY email")->fetchAll(PDO::FETCH_COLUMN);

$memberCountSt = $db->prepare("SELECT COUNT(*) FROM cohort_members WHERE cohort_id=? AND status='active'");
foreach ($cohorts as &$c) {
    $memberCountSt->execute([$c['id']]);
    $c['active_member_count'] = (int)$memberCountSt->fetchColumn();
}
unset($c);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>LAUNCH Cohorts — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .lc-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:20px 24px;margin-bottom:20px}
    .lc-card h3{margin:0 0 14px;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
    .lc-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px}
    .field-group{display:flex;flex-direction:column;gap:4px}
    .field-group.grow{flex:1;min-width:180px}
    .field-group.sm{min-width:90px;width:110px}
    .field-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint)}
    .field-input,.field-select{padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;width:100%;box-sizing:border-box;background:#fff}
    .btn-add{padding:9px 20px;background:var(--green);color:#111;font-weight:800;font-size:13px;border:0;border-radius:6px;cursor:pointer;white-space:nowrap}
    .btn-add:hover{background:var(--green-d,#5b8e0d);color:#fff}

    .cohort-list{display:flex;flex-direction:column;gap:14px}
    .cohort-item{border:1px solid var(--border);border-radius:10px;padding:16px 20px;background:#fff}
    .cohort-item h4{margin:0;font-size:15px}
    .cohort-meta{font-size:12px;color:var(--faint);margin-top:2px}
    .status-chip{font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;text-transform:uppercase;letter-spacing:.04em}
    .status-active{background:#eef5e8;color:#5b8e0d}
    .status-graduated{background:#e8f0fb;color:#2b5f9e}
    .status-archived{background:#f0f0f0;color:#888}

    .members-table{width:100%;border-collapse:collapse;font-size:12px;margin-top:12px}
    .members-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);padding:6px 10px;text-align:left;border-bottom:1px solid var(--border)}
    .members-table td{padding:6px 10px;border-top:1px solid var(--border)}
    .kpi-cell{font-variant-numeric:tabular-nums}
    .kpi-miss{color:#c00;font-weight:700}
    .kpi-hit{color:#5b8e0d;font-weight:700}
    .add-member-row{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap}
    .add-member-row input,.add-member-row select{padding:6px 8px;border:1px solid var(--border);border-radius:5px;font-size:12px}
    .btn-sm{padding:5px 10px;font-size:11px;border-radius:5px;border:1px solid var(--border);background:#fff;cursor:pointer}
    .flash-ok{padding:10px 14px;background:#eef5e8;border:1px solid #c3dfa8;border-radius:6px;color:#3a6b1a;font-size:13px;margin-bottom:16px}
    .flash-err{padding:10px 14px;background:#fff0f0;border:1px solid #f5c6c6;border-radius:6px;color:#c00;font-size:13px;margin-bottom:16px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('launch_cohorts', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)">Agent Development</div>
        <div class="content-title">LAUNCH Cohorts</div>
      </div>
    </header>
    <main class="wrap">
      <div id="flash-area"></div>

      <div class="lc-card">
        <h3>Create Cohort</h3>
        <div class="lc-row">
          <div class="field-group grow">
            <div class="field-label">Name</div>
            <input type="text" id="new-name" class="field-input" placeholder="e.g. LAUNCH Class of Aug 2026" autocomplete="off">
          </div>
          <div class="field-group sm">
            <div class="field-label">Start Date</div>
            <input type="date" id="new-start" class="field-input">
          </div>
          <div class="field-group sm">
            <div class="field-label">Cadence (weeks)</div>
            <input type="number" id="new-cadence" class="field-input" value="1" min="1">
          </div>
          <button class="btn-add" onclick="createCohort()">Create</button>
        </div>
      </div>

      <div class="cohort-list" id="cohort-list">
        <?php foreach ($cohorts as $c): ?>
        <div class="cohort-item" data-cohort-id="<?= (int)$c['id'] ?>">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <div>
              <h4><?= h($c['name']) ?></h4>
              <div class="cohort-meta">Started <?= h($c['start_date'] ?: '—') ?> &middot; <?= (int)$c['active_member_count'] ?> active members</div>
            </div>
            <span class="status-chip status-<?= h($c['status']) ?>"><?= h($c['status']) ?></span>
          </div>

          <div class="add-member-row">
            <input type="email" class="add-agent-email" placeholder="agent@example.com" autocomplete="off">
            <?php if ($coaches): ?>
            <select class="add-coach-email">
              <option value="">— assign coach —</option>
              <?php foreach ($coaches as $ce): ?><option value="<?= h($ce) ?>"><?= h($ce) ?></option><?php endforeach; ?>
            </select>
            <?php else: ?>
            <input type="email" class="add-coach-email" placeholder="coach@example.com" autocomplete="off">
            <?php endif; ?>
            <button class="btn-sm" onclick="addMember(<?= (int)$c['id'] ?>, this)">Add Member</button>
            <button class="btn-sm" onclick="loadMembers(<?= (int)$c['id'] ?>, this)">Refresh Roster</button>
          </div>

          <div class="members-wrap" id="members-<?= (int)$c['id'] ?>"></div>
        </div>
        <?php endforeach; ?>
        <?php if (!$cohorts): ?>
          <div class="lc-card" style="text-align:center;color:var(--faint)">No cohorts yet. Create one above.</div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</div>
<script>
function flash(msg, type='ok') {
  const el = document.getElementById('flash-area');
  el.innerHTML = `<div class="flash-${type}">${msg}</div>`;
  setTimeout(() => el.innerHTML='', 4000);
}
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function post(url, data) {
  return fetch(url, { method: 'POST', credentials: 'same-origin', headers: {'Content-Type':'application/json'}, body: JSON.stringify(data) }).then(r => r.json());
}

function createCohort() {
  const name     = document.getElementById('new-name').value.trim();
  const start    = document.getElementById('new-start').value;
  const cadence  = parseInt(document.getElementById('new-cadence').value) || 1;
  if (!name) { flash('Name is required.', 'err'); return; }
  post('api/cohorts_action.php', {action:'create', program:'launch', name, start_date:start, cadence_weeks:cadence})
    .then(d => {
      if (!d.ok) { flash(d.error || 'Save failed', 'err'); return; }
      flash(`Cohort <strong>${esc(name)}</strong> created.`);
      setTimeout(() => location.reload(), 900);
    });
}

function addMember(cohortId, btn) {
  const row = btn.closest('.add-member-row');
  const email = row.querySelector('.add-agent-email').value.trim().toLowerCase();
  const coachEl = row.querySelector('.add-coach-email');
  const coach = coachEl.value.trim().toLowerCase();
  if (!email) { flash('Agent email is required.', 'err'); return; }
  post('api/cohort_members_action.php', {action:'add', cohort_id:cohortId, agent_email:email, coach_email:coach})
    .then(d => {
      if (!d.ok) { flash(d.error || 'Add failed', 'err'); return; }
      flash(`Added <strong>${esc(email)}</strong> to the cohort.`);
      row.querySelector('.add-agent-email').value = '';
      if (coachEl.tagName === 'SELECT') coachEl.selectedIndex = 0; else coachEl.value = '';
      loadMembers(cohortId, btn);
    });
}

function loadMembers(cohortId, btnOrNull) {
  fetch(`api/cohort_members_action.php?cohort_id=${cohortId}`, {credentials:'same-origin'})
    .then(r => r.json())
    .then(d => {
      const wrap = document.getElementById('members-' + cohortId);
      if (!d.ok) { wrap.innerHTML = `<div class="flash-err">${esc(d.error||'Failed to load roster')}</div>`; return; }
      if (!d.members.length) { wrap.innerHTML = '<div style="color:var(--faint);font-size:12px;margin-top:10px">No members yet.</div>'; return; }
      let html = '<table class="members-table"><thead><tr><th>Agent</th><th>Coach</th><th>Status</th>';
      d.kpis.forEach(k => html += `<th>${esc(k.label)}</th>`);
      html += '<th></th></tr></thead><tbody>';
      d.members.forEach(m => {
        html += `<tr><td>${esc(m.agent_email)}</td><td>${esc(m.coach_email||'—')}</td><td>${esc(m.status)}</td>`;
        d.kpis.forEach(k => {
          const v = m.this_week[k.kpi_key];
          const cls = v.value >= v.target ? 'kpi-hit' : 'kpi-miss';
          html += `<td class="kpi-cell ${cls}">${v.value}/${v.target}</td>`;
        });
        html += `<td>`;
        if (m.status === 'active') {
          html += `<button class="btn-sm" onclick="setStatus(${m.id},'graduated',${cohortId})">Graduate</button> `;
          html += `<button class="btn-sm" onclick="setStatus(${m.id},'dropped',${cohortId})">Drop</button>`;
        }
        html += `</td></tr>`;
      });
      html += '</tbody></table>';
      wrap.innerHTML = html;
    });
}

function setStatus(memberId, status, cohortId) {
  post('api/cohort_members_action.php', {action:'set_status', id:memberId, status})
    .then(d => {
      if (!d.ok) { flash(d.error||'Update failed','err'); return; }
      flash('Updated.');
      loadMembers(cohortId, null);
    });
}

document.querySelectorAll('.cohort-item').forEach(el => loadMembers(parseInt(el.dataset.cohortId)));
</script>
</body>
</html>
