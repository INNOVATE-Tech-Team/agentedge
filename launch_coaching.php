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
$autoLinked = launch_roster_auto_link_darwin($db);
$roster  = launch_roster_fetch_all($db, 'CASE status WHEN \'active\' THEN 0 ELSE 1 END, agent_name');
$coaches = $db->query("SELECT email FROM agent_roles WHERE role IN ('launch_coach','director_of_coaching') ORDER BY email")->fetchAll(PDO::FETCH_COLUMN);

$stats = ['total' => count($roster), 'active' => 0, 'confirmed' => 0, 'on_hold' => 0, 'graduated' => 0, 'dropped' => 0];
foreach ($roster as $r) { if (isset($stats[$r['status']])) $stats[$r['status']]++; }

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Launch Coaching — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .lc-intro{color:var(--faint);font-size:13px;margin-bottom:20px;max-width:80ch;line-height:1.6}
    .lc-sync-note{font-size:12px;color:#2b5f9e;background:#eef4fb;border:1px solid #d6e6f7;border-radius:8px;padding:8px 14px;margin-bottom:18px;display:inline-block}

    .stat-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:14px;margin-bottom:22px}
    .stat-tile{background:#fff;border:1px solid var(--border);border-radius:10px;padding:16px 18px;position:relative;overflow:hidden}
    .stat-tile::before{content:"";position:absolute;top:0;left:0;width:4px;height:100%}
    .stat-tile.tile-total::before{background:#9aa5b1}
    .stat-tile.tile-active::before{background:#5b8e0d}
    .stat-tile.tile-confirmed::before{background:#6b3fa0}
    .stat-tile.tile-on_hold::before{background:#a06a1c}
    .stat-tile.tile-graduated::before{background:#2b5f9e}
    .stat-tile.tile-dropped::before{background:#a8a8a8}
    .stat-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint);margin-bottom:6px}
    .stat-value{font-size:26px;font-weight:800;color:#141414;font-variant-numeric:tabular-nums;line-height:1}

    .lc-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:20px 24px;margin-bottom:20px}
    .lc-card h3{margin:0 0 14px;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
    .lc-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px}
    .field-group{display:flex;flex-direction:column;gap:4px}
    .field-group.grow{flex:1;min-width:160px}
    .field-group.sm{min-width:90px;width:110px}
    .field-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint)}
    .field-input,.field-select,.field-textarea{padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;width:100%;box-sizing:border-box;background:#fff;font-family:inherit}
    .btn-add{padding:9px 20px;background:var(--green);color:#111;font-weight:800;font-size:13px;border:0;border-radius:6px;cursor:pointer;white-space:nowrap;transition:background .12s}
    .btn-add:hover{background:var(--green-d,#5b8e0d);color:#fff}
    .btn-sm{padding:5px 11px;font-size:11px;border-radius:5px;border:1px solid var(--border);background:#fff;cursor:pointer;white-space:nowrap;transition:border-color .12s,color .12s}
    .btn-sm:hover{border-color:#82C112;color:#5b8e0d}
    .btn-sm.danger:hover{border-color:#c00;color:#c00}

    .filter-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;align-items:center}
    .filter-bar input,.filter-bar select{padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:12.5px}
    .roster-count{font-size:12px;color:var(--faint);margin-left:auto}

    .roster-table-wrap{overflow-x:auto;border-radius:8px}
    .roster-table{width:100%;border-collapse:collapse;font-size:12.5px}
    .roster-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);padding:9px 8px;text-align:left;border-bottom:2px solid var(--border);white-space:nowrap;background:#fafbf9}
    .roster-table td{padding:7px 8px;border-top:1px solid var(--border);vertical-align:top}
    .roster-table tbody tr:hover{background:#fafcf7}
    .cell-input{width:100%;min-width:90px;padding:5px 7px;border:1px solid transparent;border-radius:5px;font-size:12px;box-sizing:border-box;background:transparent;transition:border-color .12s,background .12s}
    .cell-input:hover,.cell-input:focus{border-color:var(--border);background:#fff}
    .cell-input.wide{min-width:160px}
    .cell-input.notes{min-width:200px}
    .status-chip{font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
    .status-active{background:#eef5e8;color:#5b8e0d}
    .status-confirmed{background:#f1ecfb;color:#6b3fa0}
    .status-on_hold{background:#fdf3e3;color:#a06a1c}
    .status-graduated{background:#e8f0fb;color:#2b5f9e}
    .status-dropped{background:#f0f0f0;color:#888}
    .deals-cell{white-space:nowrap}
    .deals-num{font-weight:800;font-variant-numeric:tabular-nums}
    .deals-tag{font-size:10px;color:var(--faint);margin:2px 0 4px}
    .darwin-search{position:relative}
    .darwin-results{position:absolute;z-index:5;background:#fff;border:1px solid var(--border);border-radius:6px;box-shadow:0 4px 14px rgba(0,0,0,.1);min-width:220px;max-height:220px;overflow-y:auto;margin-top:2px}
    .darwin-results div{padding:6px 10px;font-size:12px;cursor:pointer}
    .darwin-results div:hover{background:#f5f8f0}
    .row-actions{display:flex;gap:6px;flex-wrap:wrap}
    .flash-ok{padding:10px 14px;background:#eef5e8;border:1px solid #c3dfa8;border-radius:6px;color:#3a6b1a;font-size:13px;margin-bottom:16px}
    .flash-err{padding:10px 14px;background:#fff0f0;border:1px solid #f5c6c6;border-radius:6px;color:#c00;font-size:13px;margin-bottom:16px}

    /* Modal */
    .modal-overlay{position:fixed;inset:0;background:rgba(20,20,20,.45);display:none;align-items:center;justify-content:center;z-index:50}
    .modal-overlay.open{display:flex}
    .modal-box{background:#fff;border-radius:12px;padding:24px 26px;width:360px;max-width:92vw;box-shadow:0 12px 40px rgba(0,0,0,.2)}
    .modal-title{font-size:15px;font-weight:800;margin-bottom:14px;color:#141414}
    .modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('launch_coaching', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)">Agent Development</div>
        <div class="content-title">Launch Coaching</div>
      </div>
    </header>
    <main class="wrap">
      <div class="lc-intro">Coaching roster for everyone moving through LAUNCH: market center, assigned coach, notes, and deals completed toward the 3-transaction graduation mark. Darwin's synced number is a year-to-date figure that resets every January, so it only works within a single calendar year — use <strong>+ Log Deal</strong> to record each closed transaction by date instead; that log persists no matter how long graduation takes. A manual "Set #" always overrides both. Mark someone <strong>Confirmed</strong> once they're locked in for a class — two days before that class's start date, an automatic email goes to Michele Chalk listing every confirmed agent for that date, for invoicing.</div>
      <?php if ($autoLinked > 0): ?>
      <div class="lc-sync-note">Auto-synced <?= (int)$autoLinked ?> agent<?= $autoLinked === 1 ? '' : 's' ?> to Darwin by name just now.</div>
      <?php endif; ?>
      <div id="flash-area"></div>

      <datalist id="coach-list">
        <?php foreach ($coaches as $ce): ?><option value="<?= h($ce) ?>"><?php endforeach; ?>
      </datalist>

      <div class="stat-row">
        <div class="stat-tile tile-total"><div class="stat-label">Total</div><div class="stat-value"><?= (int)$stats['total'] ?></div></div>
        <div class="stat-tile tile-active"><div class="stat-label">Active</div><div class="stat-value"><?= (int)$stats['active'] ?></div></div>
        <div class="stat-tile tile-confirmed"><div class="stat-label">Confirmed</div><div class="stat-value"><?= (int)$stats['confirmed'] ?></div></div>
        <div class="stat-tile tile-on_hold"><div class="stat-label">On Hold</div><div class="stat-value"><?= (int)$stats['on_hold'] ?></div></div>
        <div class="stat-tile tile-graduated"><div class="stat-label">Graduated</div><div class="stat-value"><?= (int)$stats['graduated'] ?></div></div>
        <div class="stat-tile tile-dropped"><div class="stat-label">Dropped</div><div class="stat-value"><?= (int)$stats['dropped'] ?></div></div>
      </div>

      <div class="lc-card">
        <h3>Add Agent</h3>
        <div class="lc-row">
          <div class="field-group grow">
            <div class="field-label">Name</div>
            <input type="text" id="new-name" class="field-input" placeholder="Full name" autocomplete="off">
          </div>
          <div class="field-group sm">
            <div class="field-label">State</div>
            <input type="text" id="new-state" class="field-input" placeholder="SC" maxlength="2" style="text-transform:uppercase" autocomplete="off">
          </div>
          <div class="field-group grow">
            <div class="field-label">Office</div>
            <input type="text" id="new-office" class="field-input" placeholder="e.g. Pro Drive" autocomplete="off">
          </div>
          <div class="field-group grow">
            <div class="field-label">Coach</div>
            <input type="text" id="new-coach" class="field-input" placeholder="Coach name or email" list="coach-list" autocomplete="off">
          </div>
          <div class="field-group sm">
            <div class="field-label">Start Date</div>
            <input type="date" id="new-start" class="field-input">
          </div>
          <button class="btn-add" onclick="addAgent()">Add</button>
        </div>
      </div>

      <div class="lc-card">
        <div class="filter-bar">
          <input type="text" id="search-box" placeholder="Search name, office, coach..." oninput="applyFilter()">
          <select id="status-filter" onchange="applyFilter()">
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="confirmed">Confirmed</option>
            <option value="on_hold">On Hold</option>
            <option value="graduated">Graduated</option>
            <option value="dropped">Dropped</option>
          </select>
          <div class="roster-count" id="roster-count"></div>
        </div>
        <div class="roster-table-wrap">
        <table class="roster-table" id="roster-table">
          <thead>
            <tr>
              <th>Agent</th><th>State</th><th>Office</th><th>Coach</th><th>Start Date</th>
              <th>Deals</th><th>Status</th><th>Notes</th><th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($roster as $r): ?>
            <tr data-id="<?= (int)$r['id'] ?>" data-status="<?= h($r['status']) ?>"
                data-search="<?= h(strtolower($r['agent_name'] . ' ' . $r['office'] . ' ' . $r['coach'])) ?>">
              <td><input class="cell-input wide" data-field="agent_name" value="<?= h($r['agent_name']) ?>"></td>
              <td><input class="cell-input" style="width:56px" data-field="state" value="<?= h($r['state']) ?>"></td>
              <td><input class="cell-input" data-field="office" value="<?= h($r['office']) ?>"></td>
              <td><input class="cell-input" data-field="coach" value="<?= h($r['coach']) ?>" list="coach-list"></td>
              <td><input type="date" class="cell-input" data-field="start_date" value="<?= h($r['start_date']) ?>"></td>
              <td class="deals-cell">
                <?php if ($r['effective_deals'] !== null): ?>
                  <span class="deals-num"><?= (int)$r['effective_deals'] ?></span>/3
                  <div class="deals-tag">
                    <?php if ($r['deals_override'] !== null): ?>
                      manual &middot; <a href="#" onclick="openOverrideModal(<?= (int)$r['id'] ?>, <?= (int)$r['deals_override'] ?>);return false;">edit</a>
                    <?php elseif ((int)$r['logged_deals'] > 0): ?>
                      <?= (int)$r['logged_deals'] ?> logged &middot; <a href="#" onclick="viewDeals(<?= (int)$r['id'] ?>, this);return false;">history</a>
                    <?php elseif ($r['darwin_agent_person_id']): ?>
                      Darwin YTD &middot; <a href="#" onclick="unlinkDarwin(<?= (int)$r['id'] ?>);return false;">unlink</a>
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  <div style="color:var(--faint);margin-bottom:4px">—</div>
                <?php endif; ?>
                <div class="darwin-search">
                  <button class="btn-sm" onclick="openLogDealModal(<?= (int)$r['id'] ?>)">+ Log Deal</button>
                  <?php if (!$r['darwin_agent_person_id']): ?><button class="btn-sm" onclick="openDarwinSearch(<?= (int)$r['id'] ?>, this)">Link Darwin</button><?php endif; ?>
                  <button class="btn-sm" onclick="openOverrideModal(<?= (int)$r['id'] ?>, <?= $r['deals_override'] !== null ? (int)$r['deals_override'] : 'null' ?>)">Set #</button>
                </div>
              </td>
              <td>
                <span class="status-chip status-<?= h($r['status']) ?>"><?= h($r['status']) ?></span>
              </td>
              <td><input class="cell-input notes" data-field="notes" value="<?= h($r['notes']) ?>"></td>
              <td>
                <div class="row-actions">
                  <button class="btn-sm" onclick="saveRow(<?= (int)$r['id'] ?>, this)">Save</button>
                  <?php if ($r['status'] !== 'graduated'): ?><button class="btn-sm" onclick="setStatus(<?= (int)$r['id'] ?>,'graduated')">Graduate</button><?php endif; ?>
                  <?php if ($r['status'] !== 'confirmed'): ?><button class="btn-sm" onclick="setStatus(<?= (int)$r['id'] ?>,'confirmed')">Confirm</button><?php endif; ?>
                  <?php if ($r['status'] !== 'on_hold'): ?><button class="btn-sm" onclick="setStatus(<?= (int)$r['id'] ?>,'on_hold')">Put On Hold</button><?php endif; ?>
                  <?php if ($r['status'] !== 'dropped'): ?><button class="btn-sm" onclick="setStatus(<?= (int)$r['id'] ?>,'dropped')">Drop</button><?php endif; ?>
                  <?php if ($r['status'] !== 'active'): ?><button class="btn-sm" onclick="setStatus(<?= (int)$r['id'] ?>,'active')">Reactivate</button><?php endif; ?>
                  <button class="btn-sm danger" onclick="deleteRow(<?= (int)$r['id'] ?>)">Remove</button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$roster): ?>
            <tr><td colspan="9" style="text-align:center;color:var(--faint);padding:24px">No one on the coaching roster yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
        </div>
      </div>
    </main>
  </div>
</div>

<div class="modal-overlay" id="log-deal-modal">
  <div class="modal-box">
    <div class="modal-title">Log a Deal</div>
    <div class="field-group" style="margin-bottom:12px">
      <div class="field-label">Date Closed</div>
      <input type="date" id="ld-date" class="field-input">
    </div>
    <div class="field-group">
      <div class="field-label">Note (optional)</div>
      <input type="text" id="ld-notes" class="field-input" placeholder="Address, side, etc.">
    </div>
    <div class="modal-actions">
      <button class="btn-sm" onclick="closeModal('log-deal-modal')">Cancel</button>
      <button class="btn-add" onclick="submitLogDeal()">Log Deal</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="override-modal">
  <div class="modal-box">
    <div class="modal-title">Manual Deal Count</div>
    <div class="field-group">
      <div class="field-label">Count (blank to clear and use Darwin/logged deals instead)</div>
      <input type="number" min="0" id="ov-count" class="field-input">
    </div>
    <div class="modal-actions">
      <button class="btn-sm" onclick="closeModal('override-modal')">Cancel</button>
      <button class="btn-add" onclick="submitOverride()">Save</button>
    </div>
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
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function addAgent() {
  const name = document.getElementById('new-name').value.trim();
  if (!name) { flash('Name is required.', 'err'); return; }
  post({
    action: 'create',
    agent_name: name,
    state: document.getElementById('new-state').value.trim().toUpperCase(),
    office: document.getElementById('new-office').value.trim(),
    coach: document.getElementById('new-coach').value.trim(),
    start_date: document.getElementById('new-start').value,
  }).then(d => {
    if (!d.ok) { flash(d.error || 'Add failed', 'err'); return; }
    flash(`Added <strong>${esc(name)}</strong>.`);
    setTimeout(() => location.reload(), 800);
  });
}

function saveRow(id, btn) {
  const tr = btn.closest('tr');
  const body = { action: 'update_fields', id };
  tr.querySelectorAll('[data-field]').forEach(el => body[el.dataset.field] = el.value);
  post(body).then(d => {
    if (!d.ok) { flash(d.error || 'Save failed', 'err'); return; }
    flash('Saved.');
  });
}

function setStatus(id, status) {
  post({ action:'set_status', id, status }).then(d => {
    if (!d.ok) { flash(d.error || 'Update failed', 'err'); return; }
    flash('Updated.');
    setTimeout(() => location.reload(), 600);
  });
}

function deleteRow(id) {
  if (!confirm('Remove this agent from the coaching roster?')) return;
  post({ action:'delete', id }).then(d => {
    if (!d.ok) { flash(d.error || 'Remove failed', 'err'); return; }
    document.querySelector(`tr[data-id="${id}"]`)?.remove();
    flash('Removed.');
  });
}

function unlinkDarwin(id) {
  post({ action:'unlink_darwin', id }).then(d => {
    if (!d.ok) { flash(d.error || 'Failed', 'err'); return; }
    setTimeout(() => location.reload(), 400);
  });
}

let overrideRosterId = null;
function openOverrideModal(id, current) {
  overrideRosterId = id;
  document.getElementById('ov-count').value = (current === null || current === undefined) ? '' : current;
  openModal('override-modal');
}
function submitOverride() {
  const val = document.getElementById('ov-count').value;
  const num = val.trim() === '' ? null : parseInt(val, 10);
  post({ action:'set_deals_override', id: overrideRosterId, deals_override: num }).then(d => {
    if (!d.ok) { flash(d.error || 'Failed', 'err'); return; }
    closeModal('override-modal');
    setTimeout(() => location.reload(), 300);
  });
}

let logDealRosterId = null;
function openLogDealModal(id) {
  logDealRosterId = id;
  document.getElementById('ld-date').value = new Date().toISOString().slice(0, 10);
  document.getElementById('ld-notes').value = '';
  openModal('log-deal-modal');
}
function submitLogDeal() {
  const date = document.getElementById('ld-date').value;
  if (!date) { flash('Pick a date.', 'err'); return; }
  const notes = document.getElementById('ld-notes').value.trim();
  post({ action:'log_deal', id: logDealRosterId, deal_date: date, notes }).then(d => {
    if (!d.ok) { flash(d.error || 'Log failed', 'err'); return; }
    closeModal('log-deal-modal');
    flash('Deal logged.');
    setTimeout(() => location.reload(), 400);
  });
}

function viewDeals(id, link) {
  const cell = link.closest('.deals-cell');
  const old = cell.querySelector('.darwin-results');
  if (old) { old.remove(); return; }
  fetch(`api/launch_roster_action.php?deals_for=${id}`, { credentials:'same-origin' })
    .then(r => r.json())
    .then(d => {
      if (!d.ok) { flash(d.error || 'Failed to load history', 'err'); return; }
      const box = document.createElement('div');
      box.className = 'darwin-results';
      box.style.position = 'static';
      box.style.marginTop = '6px';
      if (!d.deals.length) {
        box.innerHTML = '<div style="color:var(--faint)">No deals logged</div>';
      } else {
        d.deals.forEach(deal => {
          const row = document.createElement('div');
          row.style.display = 'flex';
          row.style.justifyContent = 'space-between';
          row.style.gap = '8px';
          row.innerHTML = `<span>${esc(deal.deal_date)}${deal.notes ? ' — ' + esc(deal.notes) : ''}</span>`;
          const del = document.createElement('a');
          del.href = '#';
          del.textContent = 'remove';
          del.onclick = (e) => { e.preventDefault(); deleteDeal(deal.id); };
          row.appendChild(del);
          box.appendChild(row);
        });
      }
      cell.appendChild(box);
    });
}

function deleteDeal(dealId) {
  post({ action:'delete_deal', id: dealId }).then(d => {
    if (!d.ok) { flash(d.error || 'Remove failed', 'err'); return; }
    setTimeout(() => location.reload(), 400);
  });
}

function openDarwinSearch(id, btn) {
  const wrap = btn.closest('.darwin-search');
  wrap.innerHTML = `<input type="text" class="cell-input" placeholder="Search Darwin by name..." oninput="darwinType(${id}, this)" autofocus>`;
}

let darwinTimer = null;
function darwinType(id, input) {
  clearTimeout(darwinTimer);
  const q = input.value.trim();
  const old = input.parentElement.querySelector('.darwin-results');
  if (old) old.remove();
  if (q.length < 2) return;
  darwinTimer = setTimeout(() => {
    fetch(`api/launch_roster_action.php?darwin_search=${encodeURIComponent(q)}`, { credentials:'same-origin' })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) return;
        const box = document.createElement('div');
        box.className = 'darwin-results';
        if (!d.results.length) {
          box.innerHTML = '<div style="color:var(--faint)">No matches</div>';
        } else {
          d.results.forEach(r => {
            const row = document.createElement('div');
            row.textContent = `${r.agent_name} — ${r.office_name || 'no office'}`;
            row.onclick = () => linkDarwin(id, r.agent_person_id);
            box.appendChild(row);
          });
        }
        input.parentElement.appendChild(box);
      });
  }, 250);
}

function linkDarwin(id, darwinAgentPersonId) {
  post({ action:'link_darwin', id, darwin_agent_person_id: darwinAgentPersonId }).then(d => {
    if (!d.ok) { flash(d.error || 'Link failed', 'err'); return; }
    flash('Linked to Darwin.');
    setTimeout(() => location.reload(), 500);
  });
}

function applyFilter() {
  const q = document.getElementById('search-box').value.trim().toLowerCase();
  const status = document.getElementById('status-filter').value;
  let shown = 0;
  document.querySelectorAll('#roster-table tbody tr[data-id]').forEach(tr => {
    const matchesSearch = !q || tr.dataset.search.includes(q);
    const matchesStatus = !status || tr.dataset.status === status;
    const show = matchesSearch && matchesStatus;
    tr.style.display = show ? '' : 'none';
    if (show) shown++;
  });
  document.getElementById('roster-count').textContent = `${shown} shown`;
}
applyFilter();

document.querySelectorAll('.modal-overlay').forEach(ov => {
  ov.addEventListener('click', (e) => { if (e.target === ov) ov.classList.remove('open'); });
});
</script>
</body>
</html>
