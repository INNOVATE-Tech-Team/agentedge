<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/nav.php';
$agent = require_login();
if (!is_admin()) { header('Location: index.php'); exit; }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Announcements — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .ann-form{background:#f9fdf5;border:1px solid #d4edab;border-radius:10px;padding:20px 24px;margin-bottom:24px}
    .ann-form h3{margin:0 0 14px;font-size:14px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#5b8e0d}
    .field-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:12px}
    .field-full{margin-bottom:12px}
    .field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px}
    .field input,.field select,.field textarea{width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;box-sizing:border-box}
    .field textarea{min-height:80px;resize:vertical}
    .field input:focus,.field select:focus,.field textarea:focus{outline:2px solid #82C112;border-color:#82C112}
    .btn-primary{padding:9px 20px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer}
    .btn-primary:hover{background:#5b8e0d;color:#fff}
    .btn-sm{padding:4px 10px;font-size:11px;font-weight:700;border-radius:4px;border:none;cursor:pointer}
    .btn-delete{background:#fee2e2;color:#c00}
    .btn-pin{background:#fff4e0;color:#a06000}
    .ann-table{width:100%;border-collapse:collapse;font-size:13px}
    .ann-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#aaa;border-bottom:1px solid #eee;padding:8px 10px;text-align:left}
    .ann-table td{padding:9px 10px;border-bottom:1px solid #f5f5f5;vertical-align:top}
    .ann-table tr:hover td{background:#fafff5}
    .pin-badge{display:inline-block;padding:1px 6px;background:#fff4e0;color:#a06000;font-size:10px;font-weight:700;border-radius:8px;margin-left:6px}
    .aud-badge{display:inline-block;padding:1px 6px;font-size:10px;font-weight:700;border-radius:8px}
    .aud-all{background:#e8f5e9;color:#2e7d32}
    .aud-admin{background:#e8f0ff;color:#2255cc}
    .ann-body-preview{color:#555;font-size:12px;margin-top:3px;white-space:pre-wrap;max-height:54px;overflow:hidden}
    .empty-note{color:#bbb;font-size:13px;padding:24px;text-align:center}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('bo_announcements', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Announcements</div>
    </header>
    <main class="wrap">
      <div class="card" style="padding:20px 24px">
        <div class="ann-form">
          <h3 id="form-heading">New Announcement</h3>
          <input type="hidden" id="edit-id" value="">
          <div class="field-full field">
            <label>Title</label>
            <input type="text" id="ann-title" placeholder="e.g. Office closed Friday">
          </div>
          <div class="field-full field">
            <label>Message</label>
            <textarea id="ann-body" placeholder="Write your announcement here…"></textarea>
          </div>
          <div class="field-row">
            <div class="field">
              <label>Audience</label>
              <select id="ann-audience">
                <option value="all">Everyone</option>
                <option value="admin">Admin / Staff only</option>
              </select>
            </div>
            <div class="field">
              <label>Expires (optional)</label>
              <input type="date" id="ann-expires">
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:14px">
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
              <input type="checkbox" id="ann-pinned"> Pin to top
            </label>
            <button class="btn-primary" onclick="saveAnn()">Save</button>
            <button class="btn-sm" id="cancel-edit" style="display:none;background:#f0f0f0;color:#333" onclick="cancelEdit()">Cancel</button>
          </div>
        </div>

        <table class="ann-table" id="ann-table">
          <thead><tr><th>Title</th><th>Audience</th><th>Created</th><th style="text-align:right">Actions</th></tr></thead>
          <tbody id="ann-tbody"><tr><td colspan="4" class="empty-note">Loading…</td></tr></tbody>
        </table>
      </div>
    </main>
  </div>
</div>
<script>
function esc(s){return String(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}

function fmtDate(s){if(!s)return'—';const d=new Date(s);return d.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}

let items=[];

function load(){
  fetch('api/announcements.php',{credentials:'same-origin'})
    .then(r=>r.json()).then(d=>{items=d.items||[];render()});
}

function render(){
  const tb=document.getElementById('ann-tbody');
  if(!items.length){tb.innerHTML='<tr><td colspan="4" class="empty-note">No announcements yet.</td></tr>';return;}
  tb.innerHTML=items.map(a=>`
    <tr>
      <td>
        <strong>${esc(a.title)}</strong>${a.pinned?'<span class="pin-badge">Pinned</span>':''}
        <div class="ann-body-preview">${esc(a.body)}</div>
      </td>
      <td><span class="aud-badge aud-${esc(a.audience)}">${a.audience==='all'?'Everyone':'Admin only'}</span></td>
      <td>${fmtDate(a.created_at)}${a.expires_at?'<br><span style="font-size:11px;color:#888">Expires '+fmtDate(a.expires_at)+'</span>':''}</td>
      <td style="text-align:right;white-space:nowrap;display:flex;gap:6px;justify-content:flex-end">
        <button class="btn-sm btn-pin" onclick="togglePin(${a.id},${a.pinned?0:1})">${a.pinned?'Unpin':'Pin'}</button>
        <button class="btn-sm" style="background:#f0f0f0;color:#333" onclick="editAnn(${a.id})">Edit</button>
        <button class="btn-sm btn-delete" onclick="delAnn(${a.id})">Delete</button>
      </td>
    </tr>`).join('');
}

function saveAnn(){
  const id=document.getElementById('edit-id').value;
  const payload={
    action: id?'update':'create',
    id: id?Number(id):undefined,
    title: document.getElementById('ann-title').value.trim(),
    body:  document.getElementById('ann-body').value.trim(),
    audience: document.getElementById('ann-audience').value,
    pinned: document.getElementById('ann-pinned').checked?1:0,
    expires_at: document.getElementById('ann-expires').value||null,
  };
  if(!payload.title||!payload.body){alert('Title and message are required.');return;}
  fetch('api/announcements.php',{
    method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify(payload),
  }).then(r=>r.json()).then(d=>{
    if(d.ok){clearForm();load();}else alert(d.error||'Error');
  });
}

function editAnn(id){
  const a=items.find(x=>x.id===id);if(!a)return;
  document.getElementById('edit-id').value=a.id;
  document.getElementById('ann-title').value=a.title;
  document.getElementById('ann-body').value=a.body;
  document.getElementById('ann-audience').value=a.audience;
  document.getElementById('ann-pinned').checked=!!a.pinned;
  document.getElementById('ann-expires').value=(a.expires_at||'').slice(0,10);
  document.getElementById('form-heading').textContent='Edit Announcement';
  document.getElementById('cancel-edit').style.display='';
  window.scrollTo({top:0,behavior:'smooth'});
}

function cancelEdit(){clearForm();}

function clearForm(){
  ['ann-title','ann-body','ann-expires'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('ann-audience').value='all';
  document.getElementById('ann-pinned').checked=false;
  document.getElementById('edit-id').value='';
  document.getElementById('form-heading').textContent='New Announcement';
  document.getElementById('cancel-edit').style.display='none';
}

function togglePin(id,val){
  fetch('api/announcements.php',{
    method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'pin',id,pinned:val}),
  }).then(()=>load());
}

function delAnn(id){
  const a=items.find(x=>x.id===id);
  if(!confirm('Delete "'+a.title+'"?'))return;
  fetch('api/announcements.php',{
    method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'delete',id}),
  }).then(()=>load());
}

load();
</script>
</body>
</html>
