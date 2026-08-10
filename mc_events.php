<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';

$agent = require_login();
if (!can_post_announcements()) { header('Location: index.php'); exit; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }

$isAdmin   = is_admin();
$myMcSlugs = my_mc_slugs();
$db        = local_db();

// Admins can manage any Market Center; MC Leaders/BICs are locked to their own.
if ($isAdmin) {
    $mcOptions = $db->query("SELECT slug, name, state_code FROM market_centers WHERE enabled=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} else {
    if (!$myMcSlugs) {
        $mcOptions = [];
    } else {
        $ph = implode(',', array_fill(0, count($myMcSlugs), '?'));
        $st = $db->prepare("SELECT slug, name, state_code FROM market_centers WHERE slug IN ($ph) ORDER BY name");
        $st->execute($myMcSlugs);
        $mcOptions = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Market Center Events — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .mce-form{background:#f9fdf5;border:1px solid #d4edab;border-radius:10px;padding:20px 24px;margin-bottom:20px}
    .mce-form h3{margin:0 0 14px;font-size:14px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#5b8e0d}
    .mce-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:12px}
    .mce-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:12px}
    .mce-field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px}
    .mce-field input,.mce-field select,.mce-field textarea{width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;box-sizing:border-box;font-family:inherit}
    .mce-field textarea{min-height:64px;resize:vertical}
    .mce-table{width:100%;border-collapse:collapse;font-size:13px}
    .mce-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#aaa;border-bottom:1px solid #eee;padding:8px 10px;text-align:left}
    .mce-table td{padding:9px 10px;border-bottom:1px solid #f5f5f5;vertical-align:top}
    .btn-sm{padding:4px 10px;font-size:11px;font-weight:700;border-radius:4px;border:1px solid #ccc;background:#fff;cursor:pointer}
    .btn-sm.danger{color:#c00;border-color:#fcc}
    .empty-note{color:#bbb;font-size:13px;padding:24px;text-align:center}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('mc_events', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Market Center Events</div>
    </header>
    <main class="wrap">
      <?php if (!$mcOptions): ?>
        <div class="card" style="padding:30px;text-align:center;color:var(--faint)">You're not assigned to lead a Market Center.</div>
      <?php else: ?>

      <div class="card" style="padding:20px 24px">
        <div class="mce-field" style="max-width:320px;margin-bottom:18px">
          <label>Market Center</label>
          <select id="mce-mc-select" onchange="loadMcEvents()">
            <?php foreach ($mcOptions as $mc): ?>
              <option value="<?= h($mc['slug']) ?>"><?= h($mc['name']) ?><?= $mc['state_code'] ? ' (' . h($mc['state_code']) . ')' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mce-form">
          <h3 id="mce-form-heading">Add Event</h3>
          <input type="hidden" id="mce-edit-id" value="">
          <div class="mce-field" style="margin-bottom:12px">
            <label>Event Name *</label>
            <input type="text" id="mce-name" placeholder="e.g. Office Client Appreciation Party">
          </div>
          <div class="mce-row-3">
            <div class="mce-field"><label>Start Date *</label><input type="date" id="mce-start-date"></div>
            <div class="mce-field"><label>End Date</label><input type="date" id="mce-end-date"></div>
            <div class="mce-field"><label>Start Time</label><input type="time" id="mce-start-time"></div>
          </div>
          <div class="mce-row">
            <div class="mce-field"><label>Location</label><input type="text" id="mce-location" placeholder="e.g. Office conference room"></div>
            <div class="mce-field"><label>Link</label><input type="url" id="mce-url" placeholder="https://…"></div>
          </div>
          <div class="mce-field" style="margin-bottom:12px">
            <label>Description</label>
            <textarea id="mce-description" placeholder="Brief description shown to agents…"></textarea>
          </div>
          <button class="btn-add" style="padding:9px 20px;background:#82C112;color:#111;font-weight:800;font-size:13px;border:0;border-radius:6px;cursor:pointer" onclick="saveMcEvent()">Save Event</button>
          <button class="btn-sm" id="mce-cancel-edit" style="display:none" onclick="cancelMcEdit()">Cancel</button>
          <span id="mce-msg" style="font-size:12px;color:var(--faint);margin-left:8px"></span>
        </div>

        <table class="mce-table">
          <thead><tr><th>Event</th><th>Date</th><th>Location</th><th></th></tr></thead>
          <tbody id="mce-list"></tbody>
        </table>
      </div>
      <?php endif; ?>
    </main>
  </div>
</div>
<script>
function esc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function fmtDate(s) { if (!s) return ''; const d = new Date(s + 'T00:00:00'); return isNaN(d) ? s : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }); }

let MCE_EVENTS = [];

function currentMc() {
  const sel = document.getElementById('mce-mc-select');
  return sel ? sel.value : '';
}

function loadMcEvents() {
  const mc = currentMc();
  if (!mc) return;
  fetch('api/mc_events_action.php?mc=' + encodeURIComponent(mc), { credentials: 'same-origin' })
    .then(r => r.json())
    .then(d => {
      if (!d.ok) { document.getElementById('mce-list').innerHTML = `<tr><td colspan="4" class="empty-note">${esc(d.error || 'Failed to load')}</td></tr>`; return; }
      MCE_EVENTS = d.events;
      renderMcEvents();
    })
    .catch(() => { document.getElementById('mce-list').innerHTML = '<tr><td colspan="4" class="empty-note">Network error.</td></tr>'; });
}

function renderMcEvents() {
  const tbody = document.getElementById('mce-list');
  if (!MCE_EVENTS.length) { tbody.innerHTML = '<tr><td colspan="4" class="empty-note">No events yet.</td></tr>'; return; }
  tbody.innerHTML = MCE_EVENTS.map(e => {
    const dateLabel = fmtDate(e.start_date) + (e.end_date && e.end_date !== e.start_date ? ' – ' + fmtDate(e.end_date) : '') + (e.start_time ? ' · ' + e.start_time : '');
    return `<tr>
      <td><strong>${esc(e.name)}</strong>${e.description ? `<div style="font-size:11.5px;color:var(--faint);margin-top:2px">${esc(e.description)}</div>` : ''}</td>
      <td style="white-space:nowrap">${esc(dateLabel)}</td>
      <td>${e.url ? `<a href="${esc(e.url)}" target="_blank" rel="noopener">${esc(e.location || 'Link')}</a>` : esc(e.location || '—')}</td>
      <td style="white-space:nowrap">
        <button class="btn-sm" onclick='editMcEvent(${JSON.stringify(e).replace(/'/g, "&#39;")})'>Edit</button>
        <button class="btn-sm danger" onclick="deleteMcEvent(${e.id})">Delete</button>
      </td>
    </tr>`;
  }).join('');
}

function editMcEvent(e) {
  document.getElementById('mce-form-heading').textContent = 'Edit Event';
  document.getElementById('mce-edit-id').value = e.id;
  document.getElementById('mce-name').value = e.name;
  document.getElementById('mce-start-date').value = e.start_date;
  document.getElementById('mce-end-date').value = e.end_date;
  document.getElementById('mce-start-time').value = e.start_time;
  document.getElementById('mce-location').value = e.location;
  document.getElementById('mce-url').value = e.url;
  document.getElementById('mce-description').value = e.description;
  document.getElementById('mce-cancel-edit').style.display = '';
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function cancelMcEdit() {
  document.getElementById('mce-form-heading').textContent = 'Add Event';
  document.getElementById('mce-edit-id').value = '';
  ['name','start-date','end-date','start-time','location','url','description'].forEach(f => document.getElementById('mce-' + f).value = '');
  document.getElementById('mce-cancel-edit').style.display = 'none';
}

function saveMcEvent() {
  const msg = document.getElementById('mce-msg');
  const name = document.getElementById('mce-name').value.trim();
  const startDate = document.getElementById('mce-start-date').value;
  if (!name || !startDate) { msg.textContent = 'Event name and start date are required.'; return; }

  const body = {
    action: 'save',
    id: document.getElementById('mce-edit-id').value || 0,
    mc_slug: currentMc(),
    name,
    start_date: startDate,
    end_date: document.getElementById('mce-end-date').value,
    start_time: document.getElementById('mce-start-time').value,
    location: document.getElementById('mce-location').value.trim(),
    url: document.getElementById('mce-url').value.trim(),
    description: document.getElementById('mce-description').value.trim(),
  };
  msg.textContent = 'Saving…';
  fetch('api/mc_events_action.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
    .then(r => r.json())
    .then(d => {
      if (!d.ok) { msg.textContent = d.error || 'Save failed.'; return; }
      msg.textContent = '';
      cancelMcEdit();
      loadMcEvents();
    })
    .catch(() => { msg.textContent = 'Network error.'; });
}

function deleteMcEvent(id) {
  if (!confirm('Delete this event?')) return;
  fetch('api/mc_events_action.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', id }) })
    .then(r => r.json())
    .then(d => { if (d.ok) loadMcEvents(); else alert(d.error || 'Delete failed.'); })
    .catch(() => alert('Network error.'));
}

loadMcEvents();
</script>
</body>
</html>
