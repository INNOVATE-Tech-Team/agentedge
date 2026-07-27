<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';

$agent = require_login();
if (!is_super_admin()) { header('Location: index.php'); exit; }

$db    = local_db();
$teams = $db->query("SELECT * FROM teams ORDER BY sort_ord, name")->fetchAll(PDO::FETCH_ASSOC);

$membersByTeam = [];
foreach ($db->query("SELECT team_id, agent_email FROM team_members ORDER BY agent_email")->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $membersByTeam[(int)$m['team_id']][] = $m['agent_email'];
}

// Active roster agents — source for both the leader picker and the member
// picker/name-resolution. Only agents with a resolvable email are eligible,
// since team_members joins purely on email. Sent to the client as a small
// JSON array so the name-search typeahead (same pattern as network.php's
// agent search) can filter client-side without a round trip per keystroke.
$rosterAgents = $db->query(
    "SELECT agent_name, email FROM innovate_roster WHERE active=1 AND email != '' GROUP BY email ORDER BY agent_name"
)->fetchAll(PDO::FETCH_ASSOC);
$nameByEmail = [];
foreach ($rosterAgents as $r) { $nameByEmail[strtolower(trim($r['email']))] = $r['agent_name']; }
function team_name_for_email(array $nameByEmail, string $email): string {
    if (!$email) return '';
    return $nameByEmail[strtolower($email)] ?? $email;
}
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Teams — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .add-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:20px 24px;margin-bottom:24px}
    .add-card h3{margin:0 0 14px;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
    .add-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px}
    .field-group{display:flex;flex-direction:column;gap:4px}
    .field-group.grow{flex:1;min-width:180px}
    .field-group.sm{min-width:80px;width:80px}
    .field-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint)}
    .field-input{padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;width:100%;box-sizing:border-box}
    .field-input:focus{outline:2px solid var(--green);border-color:var(--green)}
    .btn-add{padding:9px 20px;background:var(--green);color:#111;font-weight:800;font-size:13px;border:0;border-radius:6px;cursor:pointer;white-space:nowrap;align-self:flex-end}
    .btn-add:hover{background:var(--green-d,#5b8e0d);color:#fff}

    .team-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;
              border:1px solid var(--border);border-radius:10px;overflow:hidden}
    .team-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
                 color:var(--faint);padding:10px 14px;text-align:left;border-bottom:1px solid var(--border)}
    .team-table td{padding:9px 14px;border-top:1px solid var(--border);vertical-align:middle}
    .team-table tr.edit-row td{padding:0;background:#f9fdf5;border-top:2px solid var(--green)}
    .team-table tr:last-child td{border-bottom:none}
    .team-table tr.data-row:hover td{background:#fafafa}
    .team-table tr.data-row.disabled td{opacity:.5}

    .slug-chip{font-size:11px;font-family:monospace;background:#f0f0f0;color:#555;padding:2px 7px;border-radius:4px}
    .leader-chip{font-size:11px;font-weight:700;background:#eef5e8;color:#5b8e0d;padding:2px 7px;border-radius:4px}
    .toggle-btn{padding:4px 10px;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer;border:1px solid;white-space:nowrap}
    .toggle-btn.enabled{background:#eef5e8;color:#5b8e0d;border-color:#c3dfa8}
    .toggle-btn.disabled{background:#f5f5f5;color:#999;border-color:#ddd}
    .btn-edit-row{padding:4px 10px;border:1px solid var(--border);background:#fff;border-radius:4px;font-size:12px;cursor:pointer}
    .btn-delete{padding:4px 10px;border:1px solid #fcc;background:#fff;color:#c00;border-radius:4px;font-size:12px;cursor:pointer}
    .btn-delete:hover{background:#fff0f0}

    .edit-panel{padding:16px 20px}
    .edit-row-1{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px}
    .btn-save-row{padding:8px 16px;background:var(--green);color:#111;font-weight:800;font-size:12px;border:0;border-radius:5px;cursor:pointer;white-space:nowrap}
    .btn-cancel-row{padding:8px 12px;border:1px solid var(--border);background:#fff;color:#555;font-size:12px;border-radius:5px;cursor:pointer;white-space:nowrap}

    .members-section{border-top:1px solid var(--border);padding-top:12px;margin-top:4px}
    .members-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);margin-bottom:8px}
    .members-table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:6px}
    .members-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);padding:6px 8px;text-align:left;border-bottom:1px solid var(--border)}
    .members-table td{padding:7px 8px;border-top:1px solid var(--border)}
    .members-table tr:hover td{background:#fafafa}
    .member-email{color:var(--faint);font-size:12px}
    .btn-rm-member{background:none;border:none;color:var(--faint);cursor:pointer;font-size:14px;line-height:1;padding:2px 6px;border-radius:4px}
    .btn-rm-member:hover{color:var(--red,#c0392b);background:#fdecea}
    .no-members{font-size:12px;color:var(--faint);font-style:italic}
    .member-add-row{display:flex;gap:8px;align-items:center;margin-top:8px}
    .member-add-row .agent-search-wrap{flex:1;max-width:320px}
    .btn-add-member{padding:6px 14px;background:var(--green);color:#111;font-weight:700;font-size:12px;border:0;border-radius:5px;cursor:pointer;white-space:nowrap}

    /* Name-search typeahead — same look/behavior as network.php's agent search */
    .agent-search-wrap{position:relative}
    .agent-search-dropdown{position:fixed;background:#fff;border:1px solid #ccc;border-radius:6px;
                            box-shadow:0 4px 12px rgba(0,0,0,.12);z-index:2000;max-height:280px;overflow-y:auto}
    .sd-item{display:flex;flex-direction:column;padding:8px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0}
    .sd-item:last-child{border-bottom:none}
    .sd-item:hover,.sd-item.active{background:#f9fdf5}
    .sd-name{font-size:13px;font-weight:700;color:#222}
    .sd-email{font-size:11px;color:#888}

    .empty-state{text-align:center;padding:40px;color:var(--faint);font-size:14px}
    .flash-ok{padding:10px 14px;background:#eef5e8;border:1px solid #c3dfa8;border-radius:6px;color:#3a6b1a;font-size:13px;margin-bottom:16px}
    .flash-err{padding:10px 14px;background:#fff0f0;border:1px solid #f5c6c6;border-radius:6px;color:#c00;font-size:13px;margin-bottom:16px}
    .count-strip{font-size:12px;color:var(--faint);margin-bottom:10px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('teams', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)">Back Office</div>
        <div class="content-title">Teams</div>
      </div>
    </header>
    <main class="wrap">

      <div id="flash-area"></div>

      <!-- Add form -->
      <div class="add-card">
        <h3>Add Team</h3>
        <div class="add-row">
          <div class="field-group grow">
            <div class="field-label">Team Name</div>
            <input type="text" id="add-name" class="field-input" placeholder="e.g. The Woodard Group" autocomplete="off">
          </div>
          <div class="field-group grow">
            <div class="field-label">Team Leader</div>
            <div class="agent-search-wrap">
              <input type="text" id="add-leader" class="field-input agent-search-input" placeholder="Type a name…" autocomplete="off" data-email="">
            </div>
          </div>
          <div class="field-group sm">
            <div class="field-label">Sort</div>
            <input type="number" id="add-sort" class="field-input" value="0" min="0">
          </div>
          <button class="btn-add" onclick="addTeam()">Add</button>
        </div>
      </div>

      <div class="count-strip"><?= count($teams) ?> team<?= count($teams)!==1?'s':'' ?></div>

      <?php if (!$teams): ?>
        <div class="team-table" style="border-radius:10px">
          <div class="empty-state">No teams yet. Add one above — a team can include agents from any Market Center.</div>
        </div>
      <?php else: ?>
      <table class="team-table" id="team-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Slug</th>
            <th>Leader</th>
            <th>Members</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($teams as $t): $rowId = 'edit-' . $t['id']; $members = $membersByTeam[(int)$t['id']] ?? []; ?>
          <tr class="data-row<?= $t['enabled'] ? '' : ' disabled' ?>" id="row-<?= $t['id'] ?>">
            <td><strong><?= h($t['name']) ?></strong></td>
            <td><span class="slug-chip"><?= h($t['slug']) ?></span></td>
            <td><?= $t['leader_email'] ? '<span class="leader-chip">' . h(team_name_for_email($nameByEmail, $t['leader_email'])) . '</span>' : '<span style="color:var(--faint)">— none —</span>' ?></td>
            <td><?= count($members) ?> agent<?= count($members)!==1?'s':'' ?></td>
            <td>
              <button class="toggle-btn <?= $t['enabled'] ? 'enabled' : 'disabled' ?>"
                      onclick="toggleTeam(<?= $t['id'] ?>, this)">
                <?= $t['enabled'] ? 'Enabled' : 'Disabled' ?>
              </button>
            </td>
            <td style="text-align:right;white-space:nowrap;display:flex;gap:6px;justify-content:flex-end">
              <button class="btn-edit-row" onclick="openEditRow('<?= h($rowId) ?>', this)">Edit</button>
              <button class="btn-delete" onclick="deleteTeam(<?= $t['id'] ?>, '<?= h(addslashes($t['name'])) ?>')">Delete</button>
            </td>
          </tr>
          <tr id="<?= h($rowId) ?>" class="edit-row" style="display:none">
            <td colspan="6">
              <div class="edit-panel">
                <div class="edit-row-1">
                  <div class="field-group grow">
                    <div class="field-label">Name</div>
                    <input type="text" class="field-input edit-name" value="<?= h($t['name']) ?>" autocomplete="off">
                  </div>
                  <div class="field-group grow">
                    <div class="field-label">Team Leader</div>
                    <div class="agent-search-wrap">
                      <input type="text" class="field-input agent-search-input edit-leader"
                             placeholder="Type a name…" autocomplete="off"
                             value="<?= h(team_name_for_email($nameByEmail, $t['leader_email'])) ?>"
                             data-email="<?= h($t['leader_email']) ?>">
                    </div>
                  </div>
                  <div class="field-group sm">
                    <div class="field-label">Sort</div>
                    <input type="number" class="field-input edit-sort" value="<?= (int)$t['sort_ord'] ?>" min="0">
                  </div>
                  <button class="btn-save-row" onclick="saveEdit(<?= $t['id'] ?>, '<?= h($rowId) ?>')">Save</button>
                  <button class="btn-cancel-row" onclick="closeEditRow('<?= h($rowId) ?>')">Cancel</button>
                </div>
                <div class="members-section">
                  <div class="members-label">Members</div>
                  <table class="members-table">
                    <thead><tr><th>Name</th><th>Email</th><th></th></tr></thead>
                    <tbody>
                      <?php if (!$members): ?>
                      <tr class="no-members-row"><td colspan="3" class="no-members">No members yet — add one below.</td></tr>
                      <?php else: foreach ($members as $me): ?>
                      <tr data-email="<?= h($me) ?>">
                        <td><?= h(team_name_for_email($nameByEmail, $me)) ?></td>
                        <td class="member-email"><?= h($me) ?></td>
                        <td style="text-align:right"><button class="btn-rm-member" title="Remove from team" onclick="removeMember(this, <?= (int)$t['id'] ?>)">✕</button></td>
                      </tr>
                      <?php endforeach; endif; ?>
                    </tbody>
                  </table>
                  <div class="member-add-row">
                    <div class="agent-search-wrap">
                      <input type="text" class="field-input agent-search-input member-add-input" placeholder="Type a name…" autocomplete="off" data-email="">
                    </div>
                    <button class="btn-add-member" onclick="addMember(<?= $t['id'] ?>, this)">+ Add Member</button>
                  </div>
                </div>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>

    </main>
  </div>
</div>
<script>
// Full active roster (name + email) sent once, filtered client-side as the
// user types — same UX as the agent search on network.php, but self-contained
// here since this is a fixed, already-loaded list rather than a live company
// directory search.
const ROSTER_AGENTS = <?= json_encode(array_map(fn($r) => ['name' => $r['agent_name'], 'email' => $r['email']], $rosterAgents)) ?>;

function flash(msg, type='ok') {
  const el = document.getElementById('flash-area');
  el.innerHTML = `<div class="flash-${type}">${msg}</div>`;
  setTimeout(() => el.innerHTML='', 4000);
}

function post(data) {
  return fetch('api/team_action.php', {
    method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(data)
  }).then(r => r.json());
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Name-search typeahead (shared by the leader + add-member fields) ────────
// One dropdown, portaled to <body> with position:fixed, reused by every
// .agent-search-input on the page — avoids the team table's own
// overflow:hidden (used for its rounded corners) clipping the results list.
const agentDropdown = document.createElement('div');
agentDropdown.className = 'agent-search-dropdown';
agentDropdown.hidden = true;
document.body.appendChild(agentDropdown);
let activeSearchInput = null;
let dropActiveIdx = -1;

function positionAgentDropdown(input) {
  const r = input.getBoundingClientRect();
  agentDropdown.style.left  = r.left + 'px';
  agentDropdown.style.top   = (r.bottom + 2) + 'px';
  agentDropdown.style.width = r.width + 'px';
}

function filterAgents(q) {
  q = q.toLowerCase().trim();
  if (!q) return [];
  return ROSTER_AGENTS.filter(a => a.name.toLowerCase().includes(q) || a.email.toLowerCase().includes(q)).slice(0, 20);
}

function renderAgentDropdown(agents) {
  if (!agents.length) { agentDropdown.hidden = true; return; }
  agentDropdown.innerHTML = agents.map((a, i) =>
    `<div class="sd-item" data-email="${esc(a.email)}" data-name="${esc(a.name)}" data-idx="${i}">` +
    `<div class="sd-name">${esc(a.name)}</div><div class="sd-email">${esc(a.email)}</div></div>`
  ).join('');
  agentDropdown.hidden = false;
  dropActiveIdx = -1;
  agentDropdown.querySelectorAll('.sd-item').forEach(el => {
    el.addEventListener('mousedown', e => {
      e.preventDefault();
      if (!activeSearchInput) return;
      activeSearchInput.value = el.dataset.name;
      activeSearchInput.dataset.email = el.dataset.email;
      agentDropdown.hidden = true;
    });
  });
}

let agentSearchTimer;
document.querySelectorAll('.agent-search-input').forEach(inp => {
  inp.addEventListener('focus', () => { activeSearchInput = inp; });
  inp.addEventListener('input', () => {
    activeSearchInput = inp;
    inp.dataset.email = ''; // typing invalidates whatever was previously picked
    clearTimeout(agentSearchTimer);
    agentSearchTimer = setTimeout(() => {
      const q = inp.value.trim();
      if (!q) { agentDropdown.hidden = true; return; }
      positionAgentDropdown(inp);
      renderAgentDropdown(filterAgents(q));
    }, 120);
  });
  inp.addEventListener('keydown', e => {
    if (agentDropdown.hidden) return;
    const items = [...agentDropdown.querySelectorAll('.sd-item')];
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      dropActiveIdx = Math.min(dropActiveIdx + 1, items.length - 1);
      items.forEach((el, i) => el.classList.toggle('active', i === dropActiveIdx));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      dropActiveIdx = Math.max(dropActiveIdx - 1, -1);
      items.forEach((el, i) => el.classList.toggle('active', i === dropActiveIdx));
    } else if (e.key === 'Enter') {
      if (dropActiveIdx >= 0 && items[dropActiveIdx]) {
        e.preventDefault();
        items[dropActiveIdx].dispatchEvent(new MouseEvent('mousedown'));
      }
    } else if (e.key === 'Escape') {
      agentDropdown.hidden = true;
    }
  });
  inp.addEventListener('blur', () => setTimeout(() => { agentDropdown.hidden = true; }, 150));
});
window.addEventListener('scroll', () => { if (!agentDropdown.hidden && activeSearchInput) positionAgentDropdown(activeSearchInput); }, true);
window.addEventListener('resize', () => { if (!agentDropdown.hidden && activeSearchInput) positionAgentDropdown(activeSearchInput); });

// ── Teams CRUD ────────────────────────────────────────────────────────────────
function addTeam() {
  const name   = document.getElementById('add-name').value.trim();
  const leader = document.getElementById('add-leader').dataset.email || '';
  const sort   = parseInt(document.getElementById('add-sort').value) || 0;
  if (!name) { flash('Team name is required.', 'err'); return; }
  post({action:'save', name, leader_email:leader, sort_ord:sort})
    .then(d => {
      if (!d.ok) { flash(d.error || 'Save failed', 'err'); return; }
      flash(`Team <strong>${esc(name)}</strong> added.`);
      setTimeout(() => location.reload(), 700);
    });
}

function openEditRow(rowId, btn) {
  document.querySelectorAll('.edit-row').forEach(r => r.style.display='none');
  document.querySelectorAll('.btn-edit-row').forEach(b => { b.textContent='Edit'; b.onclick = function(){ openEditRow(rowId, this); }; });
  document.getElementById(rowId).style.display='';
  btn.textContent = 'Cancel';
  btn.onclick = () => closeEditRow(rowId);
}
function closeEditRow(rowId) {
  document.getElementById(rowId).style.display='none';
  document.querySelectorAll('.btn-edit-row').forEach(b => {
    b.textContent='Edit';
    b.onclick = function(){ openEditRow(rowId, this); };
  });
}

function saveEdit(id, rowId) {
  const row    = document.getElementById(rowId);
  const name   = row.querySelector('.edit-name').value.trim();
  const leader = row.querySelector('.edit-leader').dataset.email || '';
  const sort   = parseInt(row.querySelector('.edit-sort').value) || 0;
  if (!name) { flash('Team name is required.', 'err'); return; }
  post({action:'save', id, name, leader_email:leader, sort_ord:sort})
    .then(d => {
      if (!d.ok) { flash(d.error || 'Save failed', 'err'); return; }
      flash('Saved.');
      setTimeout(() => location.reload(), 500);
    });
}

function toggleTeam(id, btn) {
  post({action:'toggle', id}).then(d => {
    if (!d.ok) { flash(d.error||'Toggle failed','err'); return; }
    const enabled = d.enabled === 1;
    btn.textContent = enabled ? 'Enabled' : 'Disabled';
    btn.className = 'toggle-btn ' + (enabled ? 'enabled' : 'disabled');
    const dataRow = document.getElementById('row-' + id);
    if (dataRow) dataRow.classList.toggle('disabled', !enabled);
  });
}

function deleteTeam(id, name) {
  if (!confirm(`Delete "${name}"?\n\nThis removes the team and its member list. Any prospects claimed by this team stay in the system but become unowned.`)) return;
  post({action:'delete', id}).then(d => {
    if (!d.ok) { flash(d.error||'Delete failed','err'); return; }
    const row = document.getElementById('row-' + id);
    if (row) row.remove();
    flash(`Deleted <strong>${esc(name)}</strong>.`);
  });
}

// ── Members — added/removed in place, no page reload, so you can add several
// members back to back without losing your spot in the edit panel. ─────────
function updateMemberCount(teamId, tbody) {
  const countCell = document.querySelector('#row-' + teamId + ' td:nth-child(4)');
  if (!countCell) return;
  const n = tbody.querySelectorAll('tr[data-email]').length;
  countCell.textContent = n + ' agent' + (n!==1?'s':'');
}

function addMember(teamId, btn) {
  const wrap  = btn.closest('.member-add-row');
  const input = wrap.querySelector('.member-add-input');
  const email = input.dataset.email || '';
  const name  = input.value.trim();
  if (!email) { flash('Pick an agent from the list.', 'err'); return; }
  post({action:'add_member', team_id: teamId, agent_email: email})
    .then(d => {
      if (!d.ok) { flash(d.error || 'Add failed', 'err'); return; }
      const section = wrap.closest('.members-section');
      const tbody   = section.querySelector('.members-table tbody');
      const noRow   = tbody.querySelector('.no-members-row');
      if (noRow) noRow.remove();
      // If this agent was already on another team, their old row (if visible
      // here — same team) just gets replaced rather than duplicated.
      const existing = tbody.querySelector(`tr[data-email="${CSS.escape(email)}"]`);
      if (existing) existing.remove();
      const tr = document.createElement('tr');
      tr.dataset.email = email;
      tr.innerHTML = `<td>${esc(name)}</td><td class="member-email">${esc(email)}</td>` +
        `<td style="text-align:right"><button class="btn-rm-member" title="Remove from team" onclick="removeMember(this, ${teamId})">✕</button></td>`;
      tbody.appendChild(tr);
      updateMemberCount(teamId, tbody);
      input.value = '';
      input.dataset.email = '';
      flash('Member added.');
    });
}

function removeMember(btn, teamId) {
  const tr    = btn.closest('tr');
  const email = tr.dataset.email;
  if (!confirm(`Remove ${email} from this team?`)) return;
  post({action:'remove_member', agent_email: email})
    .then(d => {
      if (!d.ok) { flash(d.error||'Remove failed','err'); return; }
      const tbody = tr.closest('tbody');
      tr.remove();
      if (!tbody.querySelector('tr[data-email]')) {
        tbody.innerHTML = '<tr class="no-members-row"><td colspan="3" class="no-members">No members yet — add one below.</td></tr>';
      }
      updateMemberCount(teamId, tbody);
      flash('Member removed.');
    });
}
</script>
</body>
</html>
