<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
if (!is_leader()) { header('Location: index.php'); exit; }
$canEdit = is_admin();
$folderId = isset($_GET['folder']) ? (int)$_GET['folder'] : null;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Documents — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .doc-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px}
    .btn-primary{padding:8px 16px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer}
    .btn-primary:hover{background:#5b8e0d;color:#fff}
    .btn-sm{padding:4px 10px;font-size:11px;font-weight:700;border-radius:4px;border:1px solid #ddd;background:white;cursor:pointer;color:#333}
    .btn-danger{background:#fee2e2;color:#c00;border-color:#f5c6c6}
    .breadcrumb{display:flex;gap:4px;align-items:center;font-size:12px;color:#888;margin-bottom:12px;flex-wrap:wrap}
    .breadcrumb a{color:#5b8e0d;text-decoration:none;font-weight:700}
    .breadcrumb a:hover{text-decoration:underline}
    .breadcrumb span{color:#ccc}
    .doc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
    .doc-item{border:1px solid #eee;border-radius:8px;padding:12px 14px;background:white;cursor:pointer;transition:border-color 100ms,box-shadow 100ms}
    .doc-item:hover{border-color:#c3dfa8;box-shadow:0 2px 6px rgba(0,0,0,.06)}
    .doc-item.folder{background:#f9fdf5;border-color:#d4edab}
    .doc-icon{font-size:28px;margin-bottom:6px}
    .doc-name{font-size:12px;font-weight:700;color:#222;word-break:break-word}
    .doc-meta{font-size:10px;color:#aaa;margin-top:3px}
    .doc-actions{display:flex;gap:4px;margin-top:8px}
    .vis-badge{display:inline-block;padding:1px 5px;font-size:9px;font-weight:700;border-radius:4px;margin-top:4px}
    .vis-all{background:#e8f5e9;color:#2e7d32}
    .vis-admin{background:#e8f0ff;color:#2255cc}
    .vis-leaders{background:#fdf0e3;color:#a8720f}
    /* Modal */
    .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:300;align-items:center;justify-content:center}
    .modal-overlay.open{display:flex}
    .modal{background:white;border-radius:12px;padding:24px;width:400px;max-width:96vw;box-shadow:0 8px 40px rgba(0,0,0,.18)}
    .modal h3{margin:0 0 16px;font-size:15px;font-weight:800}
    .field{margin-bottom:12px}
    .field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px}
    .field input,.field select{width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;box-sizing:border-box}
    .field input:focus,.field select:focus{outline:2px solid #82C112}
    .modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:16px}
    .btn-cancel{padding:8px 14px;border:1px solid #ccc;background:white;color:#555;border-radius:6px;cursor:pointer;font-size:13px}
    .upload-area{border:2px dashed #ccc;border-radius:8px;padding:20px;text-align:center;cursor:pointer;transition:border-color 100ms}
    .upload-area:hover,.upload-area.drag{border-color:#82C112;background:#f9fdf5}
    .upload-area p{margin:4px 0;font-size:13px;color:#888}
    .empty-note{color:#bbb;font-size:13px;padding:32px;text-align:center;border:1px dashed #eee;border-radius:8px;grid-column:1/-1}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('bo_docs', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Document Library</div>
    </header>
    <main class="wrap">
      <div class="card" style="padding:20px 24px">
        <div id="breadcrumb" class="breadcrumb"></div>
        <?php if ($canEdit): ?>
        <div class="doc-toolbar">
          <button class="btn-primary" onclick="openFolderModal()">+ New Folder</button>
          <button class="btn-primary" style="background:#e8f0ff;color:#2255cc" onclick="openUploadModal()">↑ Upload File</button>
        </div>
        <?php endif; ?>
        <div class="doc-grid" id="doc-grid"><div class="empty-note">Loading…</div></div>
      </div>
    </main>
  </div>
</div>

<!-- New Folder Modal -->
<div class="modal-overlay" id="folder-modal">
  <div class="modal">
    <h3>New Folder</h3>
    <div class="field"><label>Folder Name</label><input type="text" id="folder-name" placeholder="e.g. Training Materials"></div>
    <div class="field"><label>Visibility</label>
      <select id="folder-vis"><option value="all">Everyone</option><option value="leaders">Leaders &amp; Recruiters</option><option value="admin">Admin only</option></select>
    </div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeModal('folder-modal')">Cancel</button>
      <button class="btn-primary" onclick="createFolder()">Create</button>
    </div>
  </div>
</div>

<!-- Upload Modal -->
<div class="modal-overlay" id="upload-modal">
  <div class="modal">
    <h3>Upload File</h3>
    <div class="field"><label>Display Name (optional)</label><input type="text" id="upload-name" placeholder="Leave blank to use filename"></div>
    <div class="upload-area" id="upload-area" onclick="document.getElementById('file-input').click()"
         ondragover="event.preventDefault();this.classList.add('drag')"
         ondragleave="this.classList.remove('drag')"
         ondrop="handleDrop(event)">
      <div style="font-size:24px">📄</div>
      <p><strong>Click to browse</strong> or drag a file here</p>
      <p id="upload-filename" style="color:#5b8e0d;font-weight:700"></p>
    </div>
    <input type="file" id="file-input" style="display:none" onchange="handleFileSelect(this)">
    <div id="upload-progress" style="font-size:12px;color:#888;margin-top:8px"></div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeModal('upload-modal')">Cancel</button>
      <button class="btn-primary" onclick="uploadFile()">Upload</button>
    </div>
  </div>
</div>

<script>
function esc(s){return String(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
function fmtSize(n){if(!n)return'';if(n>1048576)return(n/1048576).toFixed(1)+' MB';if(n>1024)return Math.round(n/1024)+' KB';return n+' B'}

const canEdit = <?= json_encode($canEdit) ?>;
let currentFolder = <?= json_encode($folderId) ?>;
let currentData = {folders:[],files:[],breadcrumb:[]};
let pendingFile = null;

function load(){
  const url = currentFolder!==null ? 'api/doc_action.php?folder='+currentFolder : 'api/doc_action.php';
  fetch(url,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    currentData=d; renderBreadcrumb(d.breadcrumb||[]); renderGrid(d.folders||[],d.files||[]);
  });
}

function renderBreadcrumb(bc){
  const el=document.getElementById('breadcrumb');
  let html='<a href="backoffice_docs.php">Documents</a>';
  bc.forEach((b,i)=>{
    html+='<span>/</span><a href="backoffice_docs.php?folder='+b.id+'">'+esc(b.name)+'</a>';
  });
  el.innerHTML=html;
}

function folderIcon(vis){return vis==='admin'?'🔒':vis==='leaders'?'🧭':'📁';}
function visLabel(vis){return vis==='admin'?'Admin':vis==='leaders'?'Leaders':'Public';}
function fileIcon(mime){
  if(!mime)return'📄';
  if(mime.startsWith('image/'))return'🖼️';
  if(mime.includes('pdf'))return'📕';
  if(mime.includes('word')||mime.includes('document'))return'📝';
  if(mime.includes('sheet')||mime.includes('excel'))return'📊';
  if(mime.includes('presentation')||mime.includes('powerpoint'))return'📋';
  if(mime.includes('zip')||mime.includes('compressed'))return'🗜️';
  return'📄';
}

function renderGrid(folders,files){
  const grid=document.getElementById('doc-grid');
  if(!folders.length&&!files.length){grid.innerHTML='<div class="empty-note">This folder is empty.<br>Upload a file or create a sub-folder.</div>';return;}
  let html='';
  folders.forEach(f=>{
    html+=`<div class="doc-item folder" onclick="navigate(${f.id})">
      <div class="doc-icon">${folderIcon(f.visibility)}</div>
      <div class="doc-name">${esc(f.name)}</div>
      <div><span class="vis-badge vis-${esc(f.visibility)}">${visLabel(f.visibility)}</span></div>
      <div class="doc-actions">
        ${canEdit?`<button class="btn-sm btn-danger" onclick="event.stopPropagation();deleteFolder(${f.id},'${esc(f.name).replace("'","\\'")}')">Delete</button>`:''}
      </div>
    </div>`;
  });
  files.forEach(f=>{
    html+=`<div class="doc-item">
      <div class="doc-icon">${fileIcon(f.mime_type)}</div>
      <div class="doc-name">${esc(f.name)}</div>
      <div class="doc-meta">${fmtSize(f.size_bytes)}</div>
      <div class="doc-actions">
        <a class="btn-sm" href="api/doc_download.php?id=${f.id}" target="_blank" style="text-decoration:none;color:#5b8e0d;border-color:#c3dfa8">Download</a>
        ${canEdit?`<button class="btn-sm btn-danger" onclick="deleteFile(${f.id},'${esc(f.name).replace("'","\\'")}')">Delete</button>`:''}
      </div>
    </div>`;
  });
  grid.innerHTML=html;
}

function navigate(id){currentFolder=id;history.pushState({},'','backoffice_docs.php?folder='+id);load();}

function openFolderModal(){document.getElementById('folder-name').value='';document.getElementById('folder-modal').classList.add('open');}
function openUploadModal(){document.getElementById('upload-name').value='';document.getElementById('upload-filename').textContent='';pendingFile=null;document.getElementById('upload-modal').classList.add('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');}

function createFolder(){
  const name=document.getElementById('folder-name').value.trim();
  if(!name){alert('Name required');return;}
  fetch('api/doc_action.php',{
    method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'create_folder',name,parent_id:currentFolder,visibility:document.getElementById('folder-vis').value}),
  }).then(r=>r.json()).then(d=>{if(d.ok){closeModal('folder-modal');load();}else alert(d.error)});
}

function handleFileSelect(input){if(input.files[0]){pendingFile=input.files[0];document.getElementById('upload-filename').textContent=pendingFile.name;}}
function handleDrop(e){e.preventDefault();document.getElementById('upload-area').classList.remove('drag');if(e.dataTransfer.files[0]){pendingFile=e.dataTransfer.files[0];document.getElementById('upload-filename').textContent=pendingFile.name;}}

function uploadFile(){
  if(!pendingFile){alert('Please select a file first.');return;}
  const fd=new FormData();
  fd.append('file',pendingFile);
  if(currentFolder!==null) fd.append('folder_id',currentFolder);
  const dn=document.getElementById('upload-name').value.trim();
  if(dn) fd.append('display_name',dn);
  document.getElementById('upload-progress').textContent='Uploading…';
  fetch('api/doc_action.php',{method:'POST',credentials:'same-origin',body:fd})
    .then(r=>r.json()).then(d=>{
      if(d.ok){closeModal('upload-modal');load();}
      else{document.getElementById('upload-progress').textContent='Error: '+(d.error||'upload failed');}
    }).catch(()=>{document.getElementById('upload-progress').textContent='Upload failed.';});
}

function deleteFolder(id,name){
  if(!confirm('Delete folder "'+name+'" and ALL its contents? This cannot be undone.'))return;
  fetch('api/doc_action.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete_folder',id})}).then(()=>load());
}

function deleteFile(id,name){
  if(!confirm('Delete "'+name+'"?'))return;
  fetch('api/doc_action.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete_file',id})}).then(()=>load());
}

window.onpopstate=()=>{const p=new URLSearchParams(location.search);currentFolder=p.get('folder')?(parseInt(p.get('folder'))):null;load();};
load();
</script>
</body>
</html>
