<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
$folderId = isset($_GET['folder']) ? (int)$_GET['folder'] : null;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Resources — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .breadcrumb{display:flex;gap:4px;align-items:center;font-size:12px;color:#888;margin-bottom:14px;flex-wrap:wrap}
    .breadcrumb a{color:#5b8e0d;text-decoration:none;font-weight:700}
    .breadcrumb a:hover{text-decoration:underline}
    .breadcrumb span{color:#ccc}
    .doc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}
    .doc-item{border:1px solid #eee;border-radius:8px;padding:12px;background:white;cursor:pointer;transition:border-color 100ms,box-shadow 100ms;text-align:center}
    .doc-item:hover{border-color:#c3dfa8;box-shadow:0 2px 6px rgba(0,0,0,.06)}
    .doc-item.folder{background:#f9fdf5;border-color:#d4edab}
    .doc-icon{font-size:32px;margin-bottom:6px}
    .doc-name{font-size:12px;font-weight:700;color:#222;word-break:break-word;line-height:1.3}
    .doc-meta{font-size:10px;color:#aaa;margin-top:3px}
    .doc-dl{display:inline-block;margin-top:8px;padding:4px 10px;background:#82C112;color:#000;font-size:11px;font-weight:700;border-radius:4px;text-decoration:none}
    .doc-dl:hover{background:#5b8e0d;color:#fff}
    .empty-note{color:#bbb;font-size:13px;padding:32px;text-align:center;border:1px dashed #eee;border-radius:8px;grid-column:1/-1}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('docs', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Resources &amp; Documents</div>
    </header>
    <main class="wrap">
      <div class="card" style="padding:20px 24px">
        <div id="breadcrumb" class="breadcrumb"></div>
        <div class="doc-grid" id="doc-grid"><div class="empty-note">Loading…</div></div>
      </div>
    </main>
  </div>
</div>
<script>
function esc(s){return String(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
function fmtSize(n){if(!n)return'';if(n>1048576)return(n/1048576).toFixed(1)+' MB';if(n>1024)return Math.round(n/1024)+' KB';return n+' B'}
function fileIcon(mime){
  if(!mime)return'📄';
  if(mime.startsWith('image/'))return'🖼️';
  if(mime.includes('pdf'))return'📕';
  if(mime.includes('word')||mime.includes('document'))return'📝';
  if(mime.includes('sheet')||mime.includes('excel'))return'📊';
  if(mime.includes('presentation')||mime.includes('powerpoint'))return'📋';
  if(mime.includes('zip'))return'🗜️';
  return'📄';
}

let currentFolder = <?= json_encode($folderId) ?>;

function load(){
  const url = currentFolder!==null ? 'api/doc_action.php?folder='+currentFolder : 'api/doc_action.php';
  fetch(url,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    renderBreadcrumb(d.breadcrumb||[]);
    renderGrid(d.folders||[],d.files||[]);
  });
}

function renderBreadcrumb(bc){
  const el=document.getElementById('breadcrumb');
  let html='<a href="docs.php">Resources</a>';
  bc.forEach(b=>{
    html+='<span>/</span><a href="docs.php?folder='+b.id+'">'+esc(b.name)+'</a>';
  });
  el.innerHTML=html;
}

function renderGrid(folders,files){
  const grid=document.getElementById('doc-grid');
  if(!folders.length&&!files.length){grid.innerHTML='<div class="empty-note">No resources in this folder yet.</div>';return;}
  let html='';
  folders.forEach(f=>{
    html+=`<div class="doc-item folder" onclick="navigate(${f.id})">
      <div class="doc-icon">📁</div>
      <div class="doc-name">${esc(f.name)}</div>
    </div>`;
  });
  files.forEach(f=>{
    html+=`<div class="doc-item">
      <div class="doc-icon">${fileIcon(f.mime_type)}</div>
      <div class="doc-name">${esc(f.name)}</div>
      <div class="doc-meta">${fmtSize(f.size_bytes)}</div>
      <a class="doc-dl" href="api/doc_download.php?id=${f.id}">Download</a>
    </div>`;
  });
  grid.innerHTML=html;
}

function navigate(id){
  currentFolder=id;
  history.pushState({},'','docs.php?folder='+id);
  load();
}

window.onpopstate=()=>{const p=new URLSearchParams(location.search);currentFolder=p.get('folder')?parseInt(p.get('folder')):null;load();};
load();
</script>
</body>
</html>
