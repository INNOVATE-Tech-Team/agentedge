<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
if (!can_post_announcements()) { header('Location: index.php'); exit; }
$isAdmin = is_admin();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Industry Events — Back Office — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .evt-form { background:#f9fdf5; border:1px solid #d4edab; border-radius:10px; padding:20px 24px; margin-bottom:24px; }
    .evt-form h3 { margin:0 0 14px; font-size:14px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:#5b8e0d; }
    .field-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:12px; }
    .field-row-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; margin-bottom:12px; }
    .field-full { margin-bottom:12px; }
    .field label { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#888; margin-bottom:4px; }
    .field input, .field select, .field textarea { width:100%; padding:8px 10px; border:1px solid #ccc; border-radius:6px; font-size:13px; box-sizing:border-box; }
    .field textarea { min-height:72px; resize:vertical; }
    .field input:focus, .field select:focus, .field textarea:focus { outline:2px solid #82C112; border-color:#82C112; }
    .btn-primary { padding:9px 20px; background:#82C112; color:#000; border:none; border-radius:6px; font-weight:800; font-size:13px; cursor:pointer; }
    .btn-primary:hover { background:#5b8e0d; color:#fff; }
    .btn-sm { padding:4px 10px; font-size:11px; font-weight:700; border-radius:4px; border:none; cursor:pointer; }
    .btn-delete { background:#fee2e2; color:#c00; }
    .btn-edit { background:#f0f0f0; color:#333; }
    .evt-table { width:100%; border-collapse:collapse; font-size:13px; }
    .evt-table th { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#aaa; border-bottom:1px solid #eee; padding:8px 10px; text-align:left; }
    .evt-table td { padding:9px 10px; border-bottom:1px solid #f5f5f5; vertical-align:top; }
    .evt-table tr:hover td { background:#fafff5; }
    .cat-badge { display:inline-block; padding:2px 8px; border-radius:3px; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; }
    .cat-brokerage  { background:#ecfeff; color:#0e7490; }
    .cat-leadership { background:#fff7ed; color:#9a3412; }
    .cat-nar        { background:#eef5e8; color:#5b8e0d; }
    .cat-inman      { background:#eff6ff; color:#1d4ed8; }
    .cat-training   { background:#fffbeb; color:#92400e; }
    .cat-technology { background:#f5f3ff; color:#5b21b6; }
    .cat-industry   { background:#f3f4f6; color:#374151; }
    .cat-finance    { background:#fefce8; color:#713f12; }
    .empty-note { color:#bbb; font-size:13px; padding:24px; text-align:center; }
    .feat-star { color:#f59e0b; }
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('bo_industry_events', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Industry Events</div>
      <a href="industry_events.php" style="font-size:12px;color:#82C112;font-weight:700;text-decoration:none">View page ↗</a>
    </header>
    <main class="wrap">
      <div class="card" style="padding:20px 24px">

        <div class="evt-form">
          <h3 id="form-heading">Add Custom Event</h3>
          <input type="hidden" id="edit-id" value="">

          <div class="field-full field">
            <label>Event Name *</label>
            <input type="text" id="ev-name" placeholder="e.g. INNOVATE Leadership Retreat 2026">
          </div>

          <div class="field-row">
            <div class="field">
              <label>Organizer</label>
              <input type="text" id="ev-organizer" placeholder="e.g. INNOVATE">
            </div>
            <div class="field">
              <label>Category *</label>
              <select id="ev-category">
                <option value="brokerage">Brokerage</option>
                <option value="leadership">Leadership</option>
                <option value="nar">NAR</option>
                <option value="inman">Inman</option>
                <option value="training">Training</option>
                <option value="technology">Technology</option>
                <option value="industry">Industry</option>
                <option value="finance">Finance</option>
              </select>
            </div>
          </div>

          <div class="field-row-3">
            <div class="field">
              <label>Start Date *</label>
              <input type="date" id="ev-start">
            </div>
            <div class="field">
              <label>End Date</label>
              <input type="date" id="ev-end">
            </div>
            <div class="field">
              <label>Location</label>
              <input type="text" id="ev-location" placeholder="e.g. Myrtle Beach, SC">
            </div>
          </div>

          <div class="field-full field">
            <label>Registration URL</label>
            <input type="url" id="ev-url" placeholder="https://…">
          </div>

          <div class="field-full field">
            <label>Description</label>
            <textarea id="ev-desc" placeholder="Brief description shown on the events page…"></textarea>
          </div>

          <div style="display:flex; align-items:center; gap:16px">
            <?php if ($isAdmin): ?>
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
              <input type="checkbox" id="ev-featured"> <span>★ Featured</span>
            </label>
            <?php else: ?>
            <input type="hidden" id="ev-featured" value="0">
            <?php endif; ?>
            <button class="btn-primary" onclick="saveEvent()">Save Event</button>
            <button class="btn-sm btn-edit" id="cancel-edit" style="display:none" onclick="cancelEdit()">Cancel</button>
          </div>
        </div>

        <table class="evt-table" id="evt-table">
          <thead>
            <tr>
              <th>Event</th>
              <th>Category</th>
              <th>Date</th>
              <th>Location</th>
              <th style="text-align:right">Actions</th>
            </tr>
          </thead>
          <tbody id="evt-tbody">
            <tr><td colspan="5" class="empty-note">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </main>
  </div>
</div>

<script>
var IS_ADMIN  = <?= $isAdmin ? 'true' : 'false' ?>;
var MY_EMAIL  = <?= json_encode(strtolower($agent['email'] ?? '')) ?>;
var items = [];
var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
var CAT_LABELS = {
  brokerage:'Brokerage', leadership:'Leadership', nar:'NAR', inman:'Inman',
  training:'Training', technology:'Technology', industry:'Industry', finance:'Finance'
};

function esc(s) {
  return String(s||'').replace(/[&<>"]/g, function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]);});
}

function fmtDate(s) {
  if (!s) return '—';
  var p = s.split('-');
  return MONTHS[+p[1]-1] + ' ' + (+p[2]) + ', ' + p[0];
}

function load() {
  fetch('api/custom_events_action.php', {credentials:'same-origin'})
    .then(function(r){return r.json();})
    .then(function(d){items = d.events||[]; render();});
}

function render() {
  var tb = document.getElementById('evt-tbody');
  if (!items.length) {
    tb.innerHTML = '<tr><td colspan="5" class="empty-note">No custom events yet. Add one above.</td></tr>';
    return;
  }
  tb.innerHTML = items.map(function(e) {
    var cat = e.category || 'industry';
    var label = CAT_LABELS[cat] || cat;
    var dateStr = fmtDate(e.start_date) + (e.end_date && e.end_date !== e.start_date ? ' – ' + fmtDate(e.end_date) : '');
    return '<tr>'
      + '<td><strong>' + esc(e.name) + '</strong>' + (e.featured ? ' <span class="feat-star">★</span>' : '')
      + (e.organizer ? '<br><span style="font-size:11px;color:#888">' + esc(e.organizer) + '</span>' : '') + '</td>'
      + '<td><span class="cat-badge cat-' + esc(cat) + '">' + esc(label) + '</span></td>'
      + '<td style="white-space:nowrap">' + esc(dateStr) + '</td>'
      + '<td>' + esc(e.location||'—') + '</td>'
      + '<td style="text-align:right;white-space:nowrap">'
      + (IS_ADMIN || (e.created_by||'').toLowerCase()===MY_EMAIL ? '<button class="btn-sm btn-edit" style="margin-right:6px" onclick="editEvent(' + e.id + ')">Edit</button>' : '')
      + (IS_ADMIN || (e.created_by||'').toLowerCase()===MY_EMAIL ? '<button class="btn-sm btn-delete" onclick="deleteEvent(' + e.id + ')">Delete</button>' : '')
      + '</td>'
      + '</tr>';
  }).join('');
}

function getVal(id) { return document.getElementById(id).value; }

function saveEvent() {
  var id = getVal('edit-id');
  var payload = {
    action:      id ? 'update' : 'create',
    id:          id ? +id : undefined,
    name:        getVal('ev-name').trim(),
    organizer:   getVal('ev-organizer').trim(),
    category:    getVal('ev-category'),
    start_date:  getVal('ev-start'),
    end_date:    getVal('ev-end'),
    location:    getVal('ev-location').trim(),
    url:         getVal('ev-url').trim(),
    description: getVal('ev-desc').trim(),
    featured:    document.getElementById('ev-featured').checked ? 1 : 0,
  };
  if (!payload.name)       { alert('Event name is required.');  return; }
  if (!payload.start_date) { alert('Start date is required.');  return; }

  fetch('api/custom_events_action.php', {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify(payload),
  }).then(function(r){return r.json();}).then(function(d){
    if (d.ok) { clearForm(); load(); }
    else alert(d.error || 'Error saving event.');
  });
}

function editEvent(id) {
  var e = items.find(function(x){return x.id===id;});
  if (!e) return;
  document.getElementById('edit-id').value     = e.id;
  document.getElementById('ev-name').value      = e.name;
  document.getElementById('ev-organizer').value = e.organizer || '';
  document.getElementById('ev-category').value  = e.category  || 'industry';
  document.getElementById('ev-start').value     = e.start_date;
  document.getElementById('ev-end').value       = e.end_date   || '';
  document.getElementById('ev-location').value  = e.location   || '';
  document.getElementById('ev-url').value       = e.url        || '';
  document.getElementById('ev-desc').value      = e.description|| '';
  document.getElementById('ev-featured').checked= !!e.featured;
  document.getElementById('form-heading').textContent = 'Edit Event';
  document.getElementById('cancel-edit').style.display = '';
  window.scrollTo({top: 0, behavior: 'smooth'});
}

function cancelEdit() { clearForm(); }

function clearForm() {
  ['edit-id','ev-name','ev-organizer','ev-start','ev-end','ev-location','ev-url','ev-desc']
    .forEach(function(id){ document.getElementById(id).value=''; });
  document.getElementById('ev-category').value  = 'brokerage';
  document.getElementById('ev-featured').checked = false;
  document.getElementById('form-heading').textContent = 'Add Custom Event';
  document.getElementById('cancel-edit').style.display = 'none';
}

function deleteEvent(id) {
  var e = items.find(function(x){return x.id===id;});
  if (!confirm('Delete "' + (e ? e.name : 'this event') + '"?')) return;
  fetch('api/custom_events_action.php', {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'delete', id:id}),
  }).then(function(){load();});
}

load();
</script>
</body>
</html>
