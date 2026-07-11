<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
$agent = require_login();

if (!current_agent() || (!can_post_announcements() && !is_recruiter())) {
    header('Location: index.php'); exit;
}

$isAdmin = is_admin();
$myEmail = strtolower($agent['email'] ?? '');

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Suggestions — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .sug-submit {
      background: #f9fdf5; border: 1px solid #d4edab; border-radius: 10px;
      padding: 20px 24px; margin-bottom: 28px;
    }
    .sug-submit h3 {
      margin: 0 0 14px; font-size: 14px; font-weight: 800;
      text-transform: uppercase; letter-spacing: .06em; color: #5b8e0d;
    }
    .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 12px; }
    .field-full { margin-bottom: 12px; }
    .field label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #888; margin-bottom: 4px; }
    .field input, .field select, .field textarea {
      width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px;
      font-size: 13px; box-sizing: border-box; font-family: inherit;
    }
    .field textarea { min-height: 80px; resize: vertical; }
    .field input:focus, .field select:focus, .field textarea:focus { outline: 2px solid #82C112; border-color: #82C112; }

    .btn-primary { padding: 9px 22px; background: #82C112; color: #000; border: none; border-radius: 6px; font-weight: 800; font-size: 13px; cursor: pointer; }
    .btn-primary:hover { background: #5b8e0d; color: #fff; }
    .btn-sm { padding: 4px 10px; font-size: 11px; font-weight: 700; border-radius: 4px; border: none; cursor: pointer; }

    /* Filter bar */
    .sug-filters {
      display: flex; align-items: center; gap: 10px; margin-bottom: 18px; flex-wrap: wrap;
    }
    .sug-filters label { font-size: 11px; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: .05em; }
    .filter-btn {
      padding: 5px 14px; border-radius: 20px; border: 1px solid #ddd; background: #fff;
      font-size: 12px; font-weight: 600; cursor: pointer; transition: all .15s;
    }
    .filter-btn.active { background: #82C112; border-color: #82C112; color: #000; font-weight: 800; }
    .filter-btn:hover:not(.active) { border-color: #82C112; color: #3a6b1a; }
    .sort-sel { padding: 5px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; }

    /* Suggestion cards */
    .sug-list { display: flex; flex-direction: column; gap: 12px; }
    .sug-card {
      background: #fff; border: 1px solid #e8e8e8; border-radius: 10px;
      padding: 16px 20px; display: grid; grid-template-columns: 56px 1fr; gap: 16px;
      align-items: start; transition: border-color .15s;
    }
    .sug-card:hover { border-color: #c0e080; }

    /* Vote column */
    .sug-vote { display: flex; flex-direction: column; align-items: center; gap: 2px; }
    .vote-btn {
      width: 44px; height: 40px; border-radius: 8px; border: 1px solid #ddd;
      background: #f8f8f8; cursor: pointer; font-size: 18px; display: flex;
      align-items: center; justify-content: center; transition: all .15s;
    }
    .vote-btn:hover { border-color: #82C112; background: #f0f9e0; }
    .vote-btn.voted { border-color: #82C112; background: #eef5e8; }
    .vote-count { font-size: 14px; font-weight: 800; color: #333; }
    .vote-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #aaa; }

    /* Content column */
    .sug-meta { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; flex-wrap: wrap; }
    .sug-title { font-size: 14px; font-weight: 700; color: #111; line-height: 1.3; }
    .sug-body-text { font-size: 13px; color: #555; margin-top: 6px; line-height: 1.5; }
    .sug-footer { display: flex; align-items: center; gap: 10px; margin-top: 8px; flex-wrap: wrap; }
    .sug-by { font-size: 11px; color: #aaa; }
    .sug-admin-note { margin-top: 8px; padding: 8px 12px; background: #fff8e8; border-left: 3px solid #f0b429; border-radius: 0 6px 6px 0; font-size: 12px; color: #7a5c0e; }

    /* Status badges */
    .status-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; }
    .status-pending      { background: #f3f4f6; color: #6b7280; }
    .status-under_review { background: #eff6ff; color: #1d4ed8; }
    .status-implemented  { background: #eef5e8; color: #5b8e0d; }
    .status-declined     { background: #fee2e2; color: #b91c1c; }

    /* Category badges */
    .cat-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; background: #f3f4f6; color: #555; }

    /* Admin status modal */
    #status-modal-overlay { position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;z-index:1000; }
    #status-modal-overlay.open { display:flex; }
    #status-modal { background:#fff;border-radius:10px;width:min(420px,95vw);padding:24px;position:relative; }
    #status-modal h3 { margin:0 0 14px;font-size:15px;font-weight:800; }
    .sm-label { font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:5px; }
    .sm-sel { padding:8px 10px;font-size:13px;border:1px solid #ccc;border-radius:4px;width:100%;margin-bottom:12px; }
    .sm-ta { padding:8px 10px;font-size:13px;border:1px solid #ccc;border-radius:4px;width:100%;min-height:80px;resize:vertical;font-family:inherit;margin-bottom:14px;box-sizing:border-box; }
    .sm-btns { display:flex;gap:8px; }
    .sm-save { padding:9px 20px;border:none;background:#82C112;color:#000;font-size:13px;font-weight:800;border-radius:4px;cursor:pointer; }
    .sm-cancel { padding:9px 14px;border:1px solid #ccc;background:#fff;color:#555;font-size:13px;border-radius:4px;cursor:pointer; }
    .sm-close { position:absolute;top:14px;right:14px;background:none;border:none;font-size:20px;cursor:pointer;color:#888; }

    .empty-note { color: #bbb; font-size: 13px; text-align: center; padding: 40px 20px; }

    /* Attachments */
    .file-chip-row { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
    .file-chip { display: inline-flex; align-items: center; gap: 4px; background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 14px; padding: 3px 10px; font-size: 11px; color: #374151; }
    .file-chip a { color: #1d4ed8; text-decoration: none; font-weight: 600; }
    .file-chip a:hover { text-decoration: underline; }
    .file-size { color: #999; }
    .file-x { background: none; border: none; color: #b91c1c; font-size: 14px; line-height: 1; cursor: pointer; padding: 0 0 0 2px; }
    .sug-files { margin-top: 8px; }

    /* Edit modal */
    #edit-modal-overlay { position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;z-index:1000; }
    #edit-modal-overlay.open { display:flex; }
    #edit-modal { background:#fff;border-radius:10px;width:min(460px,95vw);padding:24px;position:relative;max-height:85vh;overflow-y:auto; }
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('suggestions', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Suggestions</div>
      <div style="font-size:12px;color:#888">Share ideas to improve INNOVATE</div>
    </header>
    <main class="wrap">

      <!-- Submit form -->
      <div class="card" style="padding:20px 24px;margin-bottom:0">
        <div class="sug-submit">
          <h3>Submit a Suggestion</h3>
          <div class="field-row">
            <div class="field">
              <label>Title *</label>
              <input type="text" id="sug-title" placeholder="Brief summary of your idea…" maxlength="160">
            </div>
            <div class="field">
              <label>Category</label>
              <select id="sug-category">
                <option value="general">General</option>
                <option value="technology">Technology</option>
                <option value="training">Training</option>
                <option value="culture">Culture</option>
                <option value="recruiting">Recruiting</option>
                <option value="operations">Operations</option>
                <option value="marketing">Marketing</option>
              </select>
            </div>
          </div>
          <div class="field field-full">
            <label>Details <span style="font-weight:400;text-transform:none">(optional)</span></label>
            <textarea id="sug-body" placeholder="Explain your suggestion in more detail…"></textarea>
          </div>
          <div class="field field-full">
            <label>Attachments <span style="font-weight:400;text-transform:none">(optional — screenshots, documents)</span></label>
            <input type="file" id="sug-files" multiple onchange="onPickFiles(this)">
            <div id="sug-files-preview" class="file-chip-row"></div>
          </div>
          <button class="btn-primary" onclick="submitSuggestion()">Submit Suggestion</button>
        </div>

        <!-- Filter / Sort bar -->
        <div class="sug-filters">
          <label>Filter:</label>
          <button class="filter-btn active" data-status="all"   onclick="setFilter('all',this)">All</button>
          <button class="filter-btn" data-status="pending"      onclick="setFilter('pending',this)">Pending</button>
          <button class="filter-btn" data-status="under_review" onclick="setFilter('under_review',this)">Under Review</button>
          <button class="filter-btn" data-status="implemented"  onclick="setFilter('implemented',this)">Implemented</button>
          <button class="filter-btn" data-status="declined"     onclick="setFilter('declined',this)">Declined</button>
          <div style="margin-left:auto;display:flex;align-items:center;gap:6px">
            <label style="margin:0">Sort:</label>
            <select class="sort-sel" id="sort-sel" onchange="render()">
              <option value="votes">Most Votes</option>
              <option value="newest">Newest</option>
            </select>
          </div>
        </div>

        <div id="sug-list" class="sug-list">
          <div class="empty-note">Loading…</div>
        </div>
      </div>

    </main>
  </div>
</div>

<!-- Admin status modal -->
<div id="status-modal-overlay">
  <div id="status-modal">
    <button class="sm-close" onclick="closeStatusModal()">×</button>
    <h3>Update Status</h3>
    <input type="hidden" id="sm-id">
    <div class="sm-label">Status</div>
    <select id="sm-status" class="sm-sel">
      <option value="pending">Pending</option>
      <option value="under_review">Under Review</option>
      <option value="implemented">Implemented</option>
      <option value="declined">Declined</option>
    </select>
    <div class="sm-label">Admin Note <span style="font-weight:400;text-transform:none">(optional)</span></div>
    <textarea id="sm-note" class="sm-ta" placeholder="Leave a note explaining the status change…"></textarea>
    <div class="sm-btns">
      <button class="sm-save" onclick="saveStatus()">Save</button>
      <button class="sm-cancel" onclick="closeStatusModal()">Cancel</button>
    </div>
  </div>
</div>

<!-- Edit suggestion modal -->
<div id="edit-modal-overlay">
  <div id="edit-modal">
    <button class="sm-close" onclick="closeEditModal()">×</button>
    <h3>Edit Suggestion</h3>
    <input type="hidden" id="em-id">
    <div class="sm-label">Title</div>
    <input type="text" id="em-title" class="sm-sel" maxlength="160" style="margin-bottom:12px;box-sizing:border-box">
    <div class="sm-label">Category</div>
    <select id="em-category" class="sm-sel">
      <option value="general">General</option>
      <option value="technology">Technology</option>
      <option value="training">Training</option>
      <option value="culture">Culture</option>
      <option value="recruiting">Recruiting</option>
      <option value="operations">Operations</option>
      <option value="marketing">Marketing</option>
    </select>
    <div class="sm-label">Details</div>
    <textarea id="em-body" class="sm-ta"></textarea>
    <div class="sm-label">Attachments</div>
    <div id="em-files" class="file-chip-row" style="margin-bottom:8px"></div>
    <input type="file" id="em-new-files" multiple onchange="uploadEditFiles(this)" style="margin-bottom:14px">
    <div class="sm-btns">
      <button class="sm-save" onclick="saveEdit()">Save</button>
      <button class="sm-cancel" onclick="closeEditModal()">Cancel</button>
    </div>
  </div>
</div>

<script>
var IS_ADMIN  = <?= $isAdmin ? 'true' : 'false' ?>;
var MY_EMAIL  = <?= json_encode($myEmail) ?>;
var CSRF      = <?= json_encode($csrf) ?>;
var items     = [];
var curFilter = 'all';
var pendingFiles = [];

var STATUS_LABELS = {
  pending: 'Pending', under_review: 'Under Review',
  implemented: 'Implemented', declined: 'Declined'
};
var CAT_LABELS = {
  general:'General', technology:'Technology', training:'Training',
  culture:'Culture', recruiting:'Recruiting', operations:'Operations', marketing:'Marketing'
};

function esc(s) {
  return String(s||'').replace(/[&<>"]/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];
  });
}

function fmtDate(s) {
  if (!s) return '';
  var d = new Date(s.replace(' ','T'));
  if (isNaN(d)) return s;
  return d.toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'});
}

function fmtSize(n) {
  n = +n || 0;
  if (n < 1024) return n + ' B';
  if (n < 1024*1024) return Math.round(n/1024) + ' KB';
  return (n/1024/1024).toFixed(1) + ' MB';
}

function load() {
  return fetch('api/suggestions_action.php', {credentials:'same-origin'})
    .then(function(r){return r.json();})
    .then(function(d){ items = d.suggestions || []; render(); });
}

function onPickFiles(input) {
  pendingFiles = pendingFiles.concat(Array.from(input.files));
  input.value = '';
  renderPendingFiles();
}

function removePendingFile(idx) {
  pendingFiles.splice(idx, 1);
  renderPendingFiles();
}

function renderPendingFiles() {
  var el = document.getElementById('sug-files-preview');
  el.innerHTML = pendingFiles.map(function(f, i) {
    return '<span class="file-chip">' + esc(f.name)
      + ' <button type="button" class="file-x" onclick="removePendingFile(' + i + ')" title="Remove">&times;</button></span>';
  }).join('');
}

function uploadFile(suggestionId, file) {
  var fd = new FormData();
  fd.append('file', file);
  fd.append('suggestion_id', suggestionId);
  fd.append('csrf', CSRF);
  return fetch('api/suggestion_file_action.php', {method:'POST', credentials:'same-origin', body: fd})
    .then(function(r){return r.json();})
    .then(function(d){ if (!d.ok) alert(file.name + ': ' + (d.error || 'Upload failed.')); });
}

function uploadFilesSequential(suggestionId, files) {
  return files.reduce(function(p, file) {
    return p.then(function(){ return uploadFile(suggestionId, file); });
  }, Promise.resolve());
}

function setFilter(status, btn) {
  curFilter = status;
  document.querySelectorAll('.filter-btn').forEach(function(b){ b.classList.remove('active'); });
  btn.classList.add('active');
  render();
}

function render() {
  var sortBy = document.getElementById('sort-sel').value;
  var filtered = items.filter(function(s){ return curFilter === 'all' || s.status === curFilter; });
  filtered.sort(function(a,b){
    if (sortBy === 'votes') return (b.upvotes - a.upvotes) || (b.id - a.id);
    return b.id - a.id;
  });

  var el = document.getElementById('sug-list');
  if (!filtered.length) {
    el.innerHTML = '<div class="empty-note">No suggestions' + (curFilter !== 'all' ? ' in this category' : ' yet') + '.</div>';
    return;
  }
  el.innerHTML = filtered.map(function(s) {
    var voted    = !!s.my_vote;
    var isOwn    = (s.submitted_by||'').toLowerCase() === MY_EMAIL;
    var catLabel = CAT_LABELS[s.category] || s.category;
    var statusCls= 'status-' + esc(s.status);
    var statusLbl= STATUS_LABELS[s.status] || s.status;

    return '<div class="sug-card" id="sug-' + s.id + '">'
      + '<div class="sug-vote">'
      +   '<button class="vote-btn' + (voted ? ' voted' : '') + '" onclick="vote(' + s.id + ')" title="' + (voted ? 'Remove vote' : 'Upvote') + '">'
      +   (voted ? '▲' : '△') + '</button>'
      +   '<div class="vote-count">' + s.upvotes + '</div>'
      +   '<div class="vote-label">votes</div>'
      + '</div>'
      + '<div>'
      +   '<div class="sug-meta">'
      +     '<span class="cat-badge">' + esc(catLabel) + '</span>'
      +     '<span class="status-badge ' + statusCls + '">' + esc(statusLbl) + '</span>'
      +   '</div>'
      +   '<div class="sug-title">' + esc(s.title) + '</div>'
      +   (s.body ? '<div class="sug-body-text">' + esc(s.body) + '</div>' : '')
      +   (s.admin_note ? '<div class="sug-admin-note"><strong>Note:</strong> ' + esc(s.admin_note) + '</div>' : '')
      +   ((s.files && s.files.length) ? '<div class="sug-files">' + s.files.map(function(f) {
              return '<span class="file-chip">'
                + '<a href="api/suggestion_file_download.php?id=' + f.id + '" target="_blank" rel="noopener">📎 ' + esc(f.orig_name) + '</a>'
                + ' <span class="file-size">(' + fmtSize(f.size_bytes) + ')</span>'
                + ((IS_ADMIN || isOwn) ? ' <button type="button" class="file-x" onclick="deleteFile(' + f.id + ')" title="Remove">&times;</button>' : '')
                + '</span>';
            }).join('') + '</div>' : '')
      +   '<div class="sug-footer">'
      +     '<span class="sug-by">Submitted by ' + esc(s.submitter_name || s.submitted_by) + ' · ' + fmtDate(s.created_at) + '</span>'
      +     (IS_ADMIN ? '<button class="btn-sm" style="background:#eff6ff;color:#1d4ed8" onclick="openStatusModal(' + s.id + ')">Update Status</button>' : '')
      +     ((IS_ADMIN || isOwn) ? '<button class="btn-sm" style="background:#f3f4f6;color:#374151;margin-left:4px" onclick="openEditModal(' + s.id + ')">Edit</button>' : '')
      +     (IS_ADMIN || isOwn ? '<button class="btn-sm" style="background:#fee2e2;color:#b91c1c;margin-left:4px" onclick="deleteSug(' + s.id + ')">Delete</button>' : '')
      +   '</div>'
      + '</div>'
      + '</div>';
  }).join('');
}

function submitSuggestion() {
  var title = document.getElementById('sug-title').value.trim();
  var cat   = document.getElementById('sug-category').value;
  var body  = document.getElementById('sug-body').value.trim();
  if (!title) { alert('Please enter a title for your suggestion.'); return; }

  fetch('api/suggestions_action.php', {
    method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action:'create', csrf:CSRF, title:title, category:cat, body:body}),
  }).then(function(r){return r.json();}).then(function(d){
    if (!d.ok) { alert(d.error || 'Could not submit suggestion.'); return; }
    var files = pendingFiles.slice();
    pendingFiles = [];
    renderPendingFiles();
    uploadFilesSequential(d.id, files).then(function() {
      document.getElementById('sug-title').value = '';
      document.getElementById('sug-body').value  = '';
      document.getElementById('sug-category').value = 'general';
      load();
    });
  });
}

function vote(id) {
  fetch('api/suggestions_action.php', {
    method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action:'vote', csrf:CSRF, id:id}),
  }).then(function(r){return r.json();}).then(function(d){
    if (d.ok) {
      var s = items.find(function(x){return x.id===id;});
      if (s) { s.upvotes = d.upvotes; s.my_vote = d.voted; }
      render();
    }
  });
}

function openStatusModal(id) {
  var s = items.find(function(x){return x.id===id;});
  if (!s) return;
  document.getElementById('sm-id').value     = id;
  document.getElementById('sm-status').value = s.status || 'pending';
  document.getElementById('sm-note').value   = s.admin_note || '';
  document.getElementById('status-modal-overlay').classList.add('open');
}

function closeStatusModal() {
  document.getElementById('status-modal-overlay').classList.remove('open');
}

function saveStatus() {
  var id     = +document.getElementById('sm-id').value;
  var status = document.getElementById('sm-status').value;
  var note   = document.getElementById('sm-note').value.trim();

  fetch('api/suggestions_action.php', {
    method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action:'update_status', csrf:CSRF, id:id, status:status, admin_note:note}),
  }).then(function(r){return r.json();}).then(function(d){
    if (d.ok) { closeStatusModal(); load(); }
    else alert(d.error || 'Could not update status.');
  });
}

function deleteSug(id) {
  var s = items.find(function(x){return x.id===id;});
  if (!confirm('Delete "' + (s ? s.title : 'this suggestion') + '"?')) return;
  fetch('api/suggestions_action.php', {
    method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action:'delete', csrf:CSRF, id:id}),
  }).then(function(r){return r.json();}).then(function(d){
    if (d.ok) load();
    else alert(d.error || 'Could not delete.');
  });
}

document.getElementById('status-modal-overlay').addEventListener('click', function(e){
  if (e.target === this) closeStatusModal();
});

function deleteFile(id) {
  if (!confirm('Remove this attachment?')) return;
  var editOpen = document.getElementById('edit-modal-overlay').classList.contains('open');
  fetch('api/suggestion_file_action.php', {
    method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action:'delete_file', csrf:CSRF, id:id}),
  }).then(function(r){return r.json();}).then(function(d){
    if (!d.ok) { alert(d.error || 'Could not remove file.'); return; }
    load().then(function(){
      if (editOpen) {
        var sid = +document.getElementById('em-id').value;
        var s = items.find(function(x){return x.id===sid;});
        renderEditFiles(s ? (s.files||[]) : []);
      }
    });
  });
}

function openEditModal(id) {
  var s = items.find(function(x){return x.id===id;});
  if (!s) return;
  document.getElementById('em-id').value       = id;
  document.getElementById('em-title').value    = s.title || '';
  document.getElementById('em-category').value = s.category || 'general';
  document.getElementById('em-body').value     = s.body || '';
  renderEditFiles(s.files || []);
  document.getElementById('edit-modal-overlay').classList.add('open');
}

function closeEditModal() {
  document.getElementById('edit-modal-overlay').classList.remove('open');
}

function renderEditFiles(files) {
  var el = document.getElementById('em-files');
  if (!files.length) { el.innerHTML = '<span style="color:#bbb;font-size:12px">No attachments yet.</span>'; return; }
  el.innerHTML = files.map(function(f) {
    return '<span class="file-chip">'
      + '<a href="api/suggestion_file_download.php?id=' + f.id + '" target="_blank" rel="noopener">📎 ' + esc(f.orig_name) + '</a>'
      + ' <span class="file-size">(' + fmtSize(f.size_bytes) + ')</span>'
      + ' <button type="button" class="file-x" onclick="deleteFile(' + f.id + ')" title="Remove">&times;</button>'
      + '</span>';
  }).join('');
}

function uploadEditFiles(input) {
  var id    = +document.getElementById('em-id').value;
  var files = Array.from(input.files);
  input.value = '';
  if (!files.length) return;
  uploadFilesSequential(id, files).then(load).then(function(){
    var s = items.find(function(x){return x.id===id;});
    renderEditFiles(s ? (s.files||[]) : []);
  });
}

function saveEdit() {
  var id    = +document.getElementById('em-id').value;
  var title = document.getElementById('em-title').value.trim();
  var cat   = document.getElementById('em-category').value;
  var body  = document.getElementById('em-body').value.trim();
  if (!title) { alert('Please enter a title.'); return; }

  fetch('api/suggestions_action.php', {
    method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action:'edit', csrf:CSRF, id:id, title:title, category:cat, body:body}),
  }).then(function(r){return r.json();}).then(function(d){
    if (d.ok) { closeEditModal(); load(); }
    else alert(d.error || 'Could not save changes.');
  });
}

document.getElementById('edit-modal-overlay').addEventListener('click', function(e){
  if (e.target === this) closeEditModal();
});

load();
</script>
</body>
</html>
