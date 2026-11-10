<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/lib/mc_event_access.php';

$agent = require_login();
if (!can_manage_mc_events()) { header('Location: index.php'); exit; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }

$isAdmin   = is_admin();
$isSuperAdmin = is_super_admin();   // only a Super Admin can change an existing event's Market Centers (enforced in api/mc_events_action.php)
$db        = local_db();
// Own MCs plus any a Super Admin approved via the access request below.
$myMcSlugs = mc_access_effective_slugs($db, strtolower(trim($agent['email'] ?? '')), my_event_mc_slugs());
$canRequestAccess = !$isAdmin;
$requestable = $canRequestAccess ? mc_access_requestable($db, $myMcSlugs) : [];
$hasCompanyGrant = $canRequestAccess && mc_access_has_company_grant($db, strtolower(trim($agent['email'] ?? '')));

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
    /* Per-checkbox labels inside #mce-mc-list are real field options, not
       field captions -- override the uppercase/spaced/gray caption style
       .mce-field label sets above (this rule wins on specificity). */
    .mce-field label.mce-mc-label{display:flex;text-transform:none;letter-spacing:normal;font-weight:400;color:#333;margin-bottom:0}
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
        <div class="mce-field" style="max-width:460px;margin-bottom:18px">
          <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px">
            <label>Market Center(s) <span style="font-weight:400;color:var(--faint);text-transform:none">— check all that this applies to</span></label>
            <a href="#" id="mce-mc-toggle-all" onclick="toggleAllMcs(event)" style="font-size:12px;white-space:nowrap">Deselect all</a>
          </div>
          <div id="mce-mc-list" style="display:grid;grid-template-columns:1fr 1fr;gap:2px 16px;max-height:180px;overflow-y:auto;border:1px solid #ccc;border-radius:6px;padding:10px 12px;margin-top:4px">
            <?php foreach ($mcOptions as $mc): ?>
              <label class="mce-mc-label" style="display:flex;align-items:center;gap:7px;font-size:13px;font-weight:400;text-transform:none;letter-spacing:normal;color:#333;margin:0;padding:4px 0;cursor:pointer">
                <input type="checkbox" class="mce-mc-check" value="<?= h($mc['slug']) ?>" checked onchange="syncMcToggle();loadMcEvents()" style="margin:0;flex-shrink:0;width:15px;height:15px">
                <span><?= h($mc['name']) ?><?= $mc['state_code'] ? ' (' . h($mc['state_code']) . ')' : '' ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if ($canRequestAccess && ($requestable || !$hasCompanyGrant)): ?>
        <div class="mce-field" style="max-width:460px;margin:-6px 0 18px">
          <button type="button" class="btn-sm" id="mce-req-toggle" onclick="document.getElementById('mce-req-panel').style.display = document.getElementById('mce-req-panel').style.display === 'none' ? '' : 'none'">Request more market centers</button>
          <div id="mce-req-panel" style="display:none;margin-top:8px;border:1px solid #ddd;border-radius:6px;padding:12px">
            <?php if ($requestable): ?>
            <label>Market Centers in your state</label>
            <select id="mce-req-mcs" multiple size="6">
              <?php foreach ($requestable as $mc): ?>
                <option value="<?= h($mc['slug']) ?>"><?= h($mc['name']) ?> (<?= h($mc['state_code']) ?>)</option>
              <?php endforeach; ?>
            </select>
            <div style="font-size:11px;color:var(--faint);margin:3px 0 8px">Ctrl/Cmd-click to pick more than one.</div>
            <button type="button" class="btn-sm" onclick="sendAccessRequest('mcs')">Send request</button>
            <?php endif; ?>
            <?php if (!$hasCompanyGrant): ?>
            <div style="margin-top:<?= $requestable ? '12' : '0' ?>px"><button type="button" class="btn-sm" onclick="sendAccessRequest('company')">Request entire company</button></div>
            <?php endif; ?>
            <div style="font-size:11px;color:var(--faint);margin-top:8px">Super Admins are emailed to approve. You'll get an email with their decision.</div>
            <span id="mce-req-msg" style="font-size:12px;margin-top:6px;display:block"></span>
          </div>
        </div>
        <?php endif; ?>

        <div class="mce-form">
          <div id="mce-mc-lock-note" style="display:none;font-size:12px;color:#a06000;background:#fff4e0;border:1px solid #f0d9a8;border-radius:6px;padding:7px 10px;margin-bottom:12px">Only a Super Admin can change an existing event's Market Centers, so the Market Center boxes above are locked while you edit.</div>
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
          <div class="mce-field" style="margin-bottom:12px">
            <label>Image <span style="font-weight:400;color:var(--faint);text-transform:none">— optional (JPG, PNG, GIF or WebP, max 8MB). Agents can click it to enlarge.</span></label>
            <input type="file" id="mce-image" accept="image/jpeg,image/png,image/gif,image/webp" onchange="onMceImagePick()">
            <div id="mce-image-preview" style="display:none;margin-top:8px">
              <img id="mce-image-thumb" alt="" style="max-height:110px;max-width:100%;border-radius:6px;border:1px solid #ddd;display:block">
              <button type="button" class="btn-sm danger" style="margin-top:6px" onclick="clearMceImage()">Remove image</button>
            </div>
          </div>
          <div class="mce-field" style="margin-bottom:12px">
            <label class="mce-mc-label" style="display:flex;align-items:center;gap:7px;text-transform:none;letter-spacing:normal;font-size:13px;font-weight:600;color:#333;cursor:pointer">
              <input type="checkbox" id="mce-rsvp" style="width:15px;height:15px;margin:0;flex-shrink:0">
              Allow agents to RSVP to this event
            </label>
            <div style="font-size:11.5px;color:var(--faint);margin-top:3px">Off by default — leave unchecked for a plain notice. When on, agents in the selected Market Centers see an RSVP button and you can see who registered.</div>
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
<script src="assets/event_attendee_panel.js"></script>
<script>
const MCE_IS_SUPER_ADMIN = <?= $isSuperAdmin ? 'true' : 'false' ?>;
let MCE_IMAGE_URL = '';   // image_url to send on save ('' = none / remove)
let MCE_EDITING = null;   // the event object being edited (for the RSVP-off confirmation)
function esc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function fmtDate(s) { if (!s) return ''; const d = new Date(s + 'T00:00:00'); return isNaN(d) ? s : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }); }

let MCE_EVENTS = [];
const MCE_MC_NAMES = <?= json_encode(array_column($mcOptions, 'name', 'slug')) ?>;

function currentMcSlugs() {
  return Array.from(document.querySelectorAll('.mce-mc-check:checked')).map(cb => cb.value);
}

function loadMcEvents() {
  const slugs = currentMcSlugs();
  if (!slugs.length) { MCE_EVENTS = []; renderMcEvents(); return; }
  fetch('api/mc_events_action.php?mc=' + encodeURIComponent(slugs.join(',')), { credentials: 'same-origin' })
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
    const mcLabel = (e.mc_slugs || []).map(s => MCE_MC_NAMES[s] || s).join(', ');
    return `<tr>
      <td><strong>${esc(e.name)}</strong><div style="font-size:11px;color:#5b8e0d;font-weight:700;margin-top:2px">${esc(mcLabel)}</div>${e.description ? `<div style="font-size:11.5px;color:var(--faint);margin-top:2px">${esc(e.description)}</div>` : ''}${e.image_url ? `<img src="${esc(e.image_url)}" alt="" style="max-height:56px;max-width:140px;margin-top:6px;border-radius:4px;border:1px solid #ddd;display:block">` : ''}${rsvpCell(e)}</td>
      <td style="white-space:nowrap">${esc(dateLabel)}</td>
      <td>${e.url ? `<a href="${esc(e.url)}" target="_blank" rel="noopener">${esc(e.location || 'Link')}</a>` : esc(e.location || '—')}</td>
      <td style="white-space:nowrap">${e.vendorsgiving
        ? `<a class="btn-sm" href="${esc(e.vendorsgiving_url)}" title="Vendorsgiving events are managed from the Vendorsgiving page">Manage in Vendorsgiving</a>`
        : `<button class="btn-sm" onclick='editMcEvent(${JSON.stringify(e).replace(/'/g, "&#39;")})'>Edit</button>
        <button class="btn-sm danger" onclick="deleteMcEvent(${e.id})">Delete</button>`}
      </td>
    </tr>${e.rsvp_enabled && e.can_manage_rsvps ? '<tr class="cal-att-row"><td colspan="4"><div class="cal-att-panel" hidden></div></td></tr>' : ''}`;
  }).join('');
}

// RSVP bits for a row. RSVP-off events show nothing — unless registrations were
// preserved from an earlier "on", in which case a muted note says so.
function rsvpCell(e) {
  if (e.rsvp_enabled && e.can_manage_rsvps) {
    return `<div style="margin-top:6px"><button type="button" class="cal-reg-toggle" aria-expanded="false" data-scope="mc" data-event-id="${esc(e.id)}" data-cap="">${e.rsvp_count || 0} Registered &#9662;</button></div>`;
  }
  if (!e.rsvp_enabled && e.rsvp_count > 0) {
    return `<div style="font-size:11px;color:var(--faint);margin-top:4px">RSVP is off &middot; ${e.rsvp_count} registration${e.rsvp_count === 1 ? '' : 's'} preserved</div>`;
  }
  return '';
}

function showMceImage(url) {
  MCE_IMAGE_URL = url || '';
  document.getElementById('mce-image-preview').style.display = MCE_IMAGE_URL ? '' : 'none';
  if (MCE_IMAGE_URL) document.getElementById('mce-image-thumb').src = MCE_IMAGE_URL;
  document.getElementById('mce-image').value = '';
}
function clearMceImage() { showMceImage(''); }
function onMceImagePick() {
  const f = document.getElementById('mce-image').files[0];
  if (!f) return;
  const msg = document.getElementById('mce-msg');
  if (f.size > 8 * 1024 * 1024) { msg.textContent = 'Image must be 8MB or smaller.'; document.getElementById('mce-image').value = ''; return; }
  const fd = new FormData();
  fd.append('action', 'upload_image');
  fd.append('image', f);
  msg.textContent = 'Uploading image…';
  fetch('api/mc_events_action.php', { method: 'POST', credentials: 'same-origin', body: fd })
    .then(r => r.json())
    .then(d => { if (!d.ok) { msg.textContent = d.error || 'Image upload failed.'; document.getElementById('mce-image').value = ''; return; } msg.textContent = ''; showMceImage(d.image_url); })
    .catch(() => { msg.textContent = 'Network error uploading image.'; });
}

function sendAccessRequest(scope) {
  const msg = document.getElementById('mce-req-msg');
  const body = { action: 'request', scope };
  if (scope === 'mcs') {
    body.mc_slugs = Array.from(document.getElementById('mce-req-mcs').selectedOptions).map(o => o.value);
    if (!body.mc_slugs.length) { msg.style.color = '#c00'; msg.textContent = 'Choose at least one Market Center.'; return; }
  } else if (!confirm('Request access to every Market Center in the company?')) return;
  fetch('api/mc_event_requests.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
    .then(r => r.json())
    .then(d => { msg.style.color = d.ok ? '#15803d' : '#c00'; msg.textContent = d.ok ? 'Request sent to the Super Admins.' : (d.error || 'Failed to send.'); })
    .catch(() => { msg.style.color = '#c00'; msg.textContent = 'Network error.'; });
}

function syncMcToggle() {
  const boxes = Array.from(document.querySelectorAll('.mce-mc-check:not(:disabled)'));
  const a = document.getElementById('mce-mc-toggle-all');
  if (a) a.textContent = (boxes.length && boxes.every(cb => cb.checked)) ? 'Deselect all' : 'Select all';
}

function toggleAllMcs(ev) {
  ev.preventDefault();
  const boxes = Array.from(document.querySelectorAll('.mce-mc-check:not(:disabled)'));
  if (!boxes.length) return;
  const check = !boxes.every(cb => cb.checked);
  boxes.forEach(cb => { cb.checked = check; });
  syncMcToggle();
  loadMcEvents();
}

// Existing events: only a Super Admin may change which Market Centers they belong to.
function lockMcBoxes(lock) {
  document.querySelectorAll('.mce-mc-check').forEach(cb => { cb.disabled = lock; });
  syncMcToggle();
  document.getElementById('mce-mc-lock-note').style.display = lock ? '' : 'none';
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
  document.getElementById('mce-rsvp').checked = !!Number(e.rsvp_enabled);
  showMceImage(e.image_url || '');
  MCE_EDITING = e;
  document.getElementById('mce-cancel-edit').style.display = '';
  const evSlugs = new Set(e.mc_slugs || []);
  document.querySelectorAll('.mce-mc-check').forEach(cb => { cb.checked = evSlugs.has(cb.value); });
  syncMcToggle();
  lockMcBoxes(!MCE_IS_SUPER_ADMIN);
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function cancelMcEdit() {
  document.getElementById('mce-form-heading').textContent = 'Add Event';
  document.getElementById('mce-edit-id').value = '';
  ['name','start-date','end-date','start-time','location','url','description'].forEach(f => document.getElementById('mce-' + f).value = '');
  document.getElementById('mce-cancel-edit').style.display = 'none';
  document.getElementById('mce-rsvp').checked = false;
  showMceImage('');
  MCE_EDITING = null;
  lockMcBoxes(false);
  document.querySelectorAll('.mce-mc-check').forEach(cb => { cb.checked = true; });
  syncMcToggle();
  loadMcEvents();
}

function saveMcEvent() {
  const msg = document.getElementById('mce-msg');
  const name = document.getElementById('mce-name').value.trim();
  const startDate = document.getElementById('mce-start-date').value;
  const mcSlugs = currentMcSlugs();
  if (!mcSlugs.length) { msg.textContent = 'Select at least one Market Center.'; return; }
  if (!name || !startDate) { msg.textContent = 'Event name and start date are required.'; return; }

  const rsvpOn = document.getElementById('mce-rsvp').checked;
  if (MCE_EDITING && Number(MCE_EDITING.rsvp_enabled) && !rsvpOn && MCE_EDITING.rsvp_count > 0) {
    const n = MCE_EDITING.rsvp_count;
    if (!confirm(`This event already has ${n} registered attendee${n === 1 ? '' : 's'}. Turning RSVP off will hide registration and attendee management, but the existing registrations will be preserved.`)) return;
  }

  const body = {
    action: 'save',
    rsvp_enabled: rsvpOn ? 1 : 0,
    image_url: MCE_IMAGE_URL,
    id: document.getElementById('mce-edit-id').value || 0,
    mc_slugs: mcSlugs,
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
