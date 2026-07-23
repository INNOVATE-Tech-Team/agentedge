<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
if (!is_admin()) { header('Location: index.php'); exit; }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Tickets — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .tkt-filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
    .tkt-filter{padding:5px 12px;border:1px solid #ccc;border-radius:20px;font-size:12px;font-weight:700;cursor:pointer;background:white;color:#555}
    .tkt-filter.active{background:#82C112;color:#000;border-color:#82C112}
    .tkt-table{width:100%;border-collapse:collapse;font-size:13px}
    .tkt-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#aaa;border-bottom:1px solid #eee;padding:8px 10px;text-align:left}
    .tkt-table td{padding:9px 10px;border-bottom:1px solid #f5f5f5;vertical-align:middle}
    .tkt-table tr{cursor:pointer}
    .tkt-table tr:hover td{background:#fafff5}
    .status-badge{display:inline-block;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;text-transform:uppercase}
    .st-open{background:#fee2e2;color:#c00}
    .st-in_progress{background:#fff4e0;color:#a06000}
    .st-answered{background:#e0edff;color:#1d4ed8}
    .st-on_hold{background:#f1e6fb;color:#7c3aed}
    .st-closed{background:#e8f5e9;color:#2e7d32}
    /* Detail panel */
    .tkt-detail{display:none;border-top:2px solid #82C112;margin-top:16px;padding-top:16px}
    .tkt-detail.open{display:block}
    .tkt-detail-head{display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap}
    .tkt-detail-title{font-size:16px;font-weight:800;flex:1}
    .msg-thread{border:1px solid #eee;border-radius:8px;margin-bottom:14px;overflow:hidden}
    .msg{padding:10px 14px;font-size:13px;border-bottom:1px solid #f5f5f5}
    .msg:last-child{border-bottom:none}
    .msg.staff{background:#f9fdf5}
    .msg-meta{font-size:11px;color:#888;margin-bottom:3px}
    .msg-meta strong{color:#333}
    .evt{padding:6px 14px;font-size:11px;color:#999;font-style:italic;text-align:center;border-bottom:1px solid #f5f5f5;background:#fbfbfb}
    .evt:last-child{border-bottom:none}
    .reply-toolbar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px}
    .reply-toolbar select{padding:6px 10px;border:1px solid #ccc;border-radius:6px;font-size:12px;max-width:220px}
    .reply-row{display:flex;flex-direction:column;gap:6px}
    .reply-row textarea{padding:10px 12px;border:1px solid #ccc;border-radius:6px;font-size:13px;resize:vertical;min-height:140px;font-family:inherit}
    .reply-foot{display:flex;align-items:center;gap:10px}
    .char-count{font-size:11px;color:#aaa;margin-left:auto}
    .btn-primary{padding:9px 20px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer}
    .btn-primary:hover{background:#5b8e0d;color:#fff}
    .btn-sm{padding:4px 10px;font-size:11px;font-weight:700;border-radius:4px;border:none;cursor:pointer}
    .actions-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
    .cc-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px;padding:8px 10px;background:#fafafa;border:1px solid #eee;border-radius:6px}
    .cc-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#888}
    .cc-chip{display:inline-flex;align-items:center;gap:5px;background:#eef7e0;color:#3a6b00;border-radius:12px;padding:3px 10px;font-size:11px;font-weight:600}
    .cc-chip button{border:none;background:none;color:#3a6b00;cursor:pointer;font-weight:800;line-height:1;padding:0}
    .cc-add{display:flex;gap:6px;margin-left:auto}
    .cc-add input{padding:5px 8px;border:1px solid #ccc;border-radius:5px;font-size:12px;width:200px}
    .cc-add button{padding:5px 10px;font-size:11px;font-weight:700;border-radius:5px;border:1px solid #82C112;background:#82C112;color:#000;cursor:pointer}
    .empty-note{color:#bbb;font-size:13px;padding:24px;text-align:center}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('bo_tickets', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Support Tickets</div>
    </header>
    <main class="wrap">
      <div class="card" style="padding:20px 24px">
        <div class="tkt-filters">
          <span class="tkt-filter active" onclick="setFilter('all',this)">All</span>
          <span class="tkt-filter" onclick="setFilter('open',this)">Open</span>
          <span class="tkt-filter" onclick="setFilter('in_progress',this)">In Progress</span>
          <span class="tkt-filter" onclick="setFilter('answered',this)">Answered</span>
          <span class="tkt-filter" onclick="setFilter('on_hold',this)">On Hold</span>
          <span class="tkt-filter" onclick="setFilter('closed',this)">Closed</span>
        </div>
        <table class="tkt-table">
          <thead><tr><th>#</th><th>Title</th><th>Agent</th><th>Dept</th><th>Status</th><th>Updated</th></tr></thead>
          <tbody id="tkt-tbody"><tr><td colspan="6" class="empty-note">Loading…</td></tr></tbody>
        </table>
        <div class="tkt-detail" id="tkt-detail"></div>
      </div>
    </main>
  </div>
</div>
<script>
const STATUSES = ['open','in_progress','answered','on_hold','closed'];
function esc(s){return String(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
function fmtDate(s){if(!s)return'—';return new Date(s.replace(' ','T')+'Z').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'})}
function statusLabel(s){return String(s||'').replace('_',' ').replace(/\b\w/g,c=>c.toUpperCase())}

let tickets=[], curFilter='all', openId=null, cannedReplies=[], kbLinks=[], replyPendingFiles=[];

function fileChipsHtml(files){
  if(!files||!files.length)return'';
  return '<div class="file-chip-row">'+files.map(f=>
    `<span class="file-chip"><a href="api/ticket_file_download.php?id=${f.id}" target="_blank">${esc(f.orig_name)}</a></span>`
  ).join('')+'</div>';
}

function onPickReplyFiles(input){
  replyPendingFiles=replyPendingFiles.concat(Array.from(input.files));
  input.value='';
  renderReplyFiles();
}
function removeReplyFile(idx){ replyPendingFiles.splice(idx,1); renderReplyFiles(); }
function renderReplyFiles(){
  const el=document.getElementById('reply-files-preview');
  if(!el)return;
  el.innerHTML=replyPendingFiles.map((f,i)=>
    `<span class="file-chip">${esc(f.name)} <button type="button" class="file-x" onclick="removeReplyFile(${i})" title="Remove">&times;</button></span>`
  ).join('');
}

function uploadTicketFile(messageId,file){
  const fd=new FormData();
  fd.append('file',file);
  fd.append('message_id',messageId);
  fd.append('csrf',window.AE_CSRF||'');
  return fetch('api/ticket_file_action.php',{method:'POST',credentials:'same-origin',body:fd}).then(r=>r.json());
}
function uploadTicketFilesSequential(messageId,files){
  return files.reduce((p,file)=>p.then(()=>uploadTicketFile(messageId,file)),Promise.resolve());
}

fetch('api/support_meta.php',{credentials:'same-origin'})
  .then(r=>r.json())
  .then(d=>{ if(d.ok){ cannedReplies=d.cannedReplies||[]; kbLinks=d.kbLinks||[]; } })
  .catch(()=>{});

function setFilter(f,el){
  curFilter=f;
  document.querySelectorAll('.tkt-filter').forEach(b=>b.classList.remove('active'));
  el.classList.add('active');
  render();
}

function load(){
  fetch('api/tickets_list.php',{credentials:'same-origin'})
    .then(r=>r.json())
    .then(d=>{
      tickets=d.tickets||[];render();
      if(openId) openTicket(openId);
    })
    .catch(()=>{
      document.getElementById('tkt-tbody').innerHTML='<tr><td colspan="6" class="empty-note">Could not load tickets — check your connection and reload.</td></tr>';
    });
}

function render(){
  const rows=tickets.filter(t=>curFilter==='all'||t.status===curFilter);
  const tb=document.getElementById('tkt-tbody');
  if(!rows.length){tb.innerHTML='<tr><td colspan="6" class="empty-note">No tickets found.</td></tr>';return;}
  tb.innerHTML=rows.map(t=>`
    <tr onclick="openTicket(${t.id})">
      <td>#${t.id}</td>
      <td><strong>${esc(t.title)}</strong></td>
      <td>${esc(t.agent_name||t.agent_email)}</td>
      <td>${esc(t.dept_slug||'—')}</td>
      <td><span class="status-badge st-${esc(t.status)}">${esc(statusLabel(t.status))}</span></td>
      <td>${fmtDate(t.updated_at)}</td>
    </tr>`).join('');
}

function openTicket(id){
  openId=id;
  replyPendingFiles=[];
  fetch('api/tickets_detail.php?id='+id,{credentials:'same-origin'})
    .then(r=>r.json())
    .then(d=>{
      if(!d.ok){ alert(d.error||'Could not load this ticket.'); return; }
      renderDetail(d);
    })
    .catch(()=>alert('Network error loading this ticket — please try again.'));
}

function renderDetail(d){
  const t=d.ticket, msgs=d.messages||[], cc=d.cc||[], events=d.events||[];
  const detail=document.getElementById('tkt-detail');
  detail.className='tkt-detail open';

  const timeline=[
    ...msgs.map(m=>({kind:'msg',at:m.created_at,m})),
    ...events.filter(e=>e.event_type!=='created').map(e=>({kind:'evt',at:e.created_at,e})),
  ].sort((a,b)=>a.at<b.at?-1:1);

  const threadHtml=timeline.map(item=>{
    if(item.kind==='msg'){
      const m=item.m;
      return `<div class="msg${m.is_staff?' staff':''}">
        <div class="msg-meta"><strong>${esc(m.author)}</strong>${m.is_staff?' (staff)':''} · ${fmtDate(m.created_at)}</div>
        <div>${esc(m.body).replace(/\n/g,'<br>')}</div>
        ${fileChipsHtml(m.files)}
      </div>`;
    }
    const e=item.e;
    let label='';
    if(e.event_type==='status_change') label=`Status changed ${esc(e.detail)} by ${esc(e.actor_email)}`;
    else if(e.event_type==='cc_added') label=`${esc(e.actor_email)} CC'd ${esc(e.detail)}`;
    else if(e.event_type==='cc_removed') label=`${esc(e.actor_email)} removed CC ${esc(e.detail)}`;
    else if(e.event_type==='assigned') label=`Assigned to ${esc(e.detail)} by ${esc(e.actor_email)}`;
    else label=esc(e.detail);
    return `<div class="evt">${label} · ${fmtDate(e.created_at)}</div>`;
  }).join('');

  const ccHtml=cc.map(c=>`<span class="cc-chip">${esc(c.email)}<button onclick="removeCc(${t.id},'${esc(c.email)}')" title="Remove">&times;</button></span>`).join('');

  const replySelect=cannedReplies.length
    ? `<select onchange="insertCanned(this)"><option value="">Insert predefined reply…</option>${cannedReplies.map(r=>`<option value="${r.id}">${esc(r.title)}</option>`).join('')}</select>`
    : '';
  const kbSelect=kbLinks.length
    ? `<select onchange="insertKb(this)"><option value="">Insert KB link…</option>${kbLinks.map(k=>`<option value="${k.id}">${esc(k.title)}</option>`).join('')}</select>`
    : '';

  detail.innerHTML=`
    <div class="tkt-detail-head">
      <div class="tkt-detail-title">#${t.id}: ${esc(t.title)}</div>
      <span class="status-badge st-${esc(t.status)}">${esc(statusLabel(t.status))}</span>
    </div>
    <div class="actions-row">
      <strong style="font-size:11px;color:#888">Status:</strong>
      ${STATUSES.map(s=>`<button class="btn-sm" style="background:${t.status===s?'#82C112':'#f0f0f0'};color:${t.status===s?'#000':'#555'}" onclick="setStatus(${t.id},'${s}')">${statusLabel(s)}</button>`).join('')}
      <button class="btn-sm" style="background:#f0f0f0;color:#555;margin-left:auto" onclick="closeDetail()">✕ Close</button>
    </div>
    <div class="cc-row">
      <span class="cc-label">CC</span>
      ${ccHtml || '<span style="font-size:12px;color:#aaa">No one CC\'d</span>'}
      <div class="cc-add">
        <input type="email" id="cc-input" placeholder="staff@innovateonline.com">
        <button onclick="addCc(${t.id})">Add</button>
      </div>
    </div>
    <div class="msg-thread" id="msg-thread">${threadHtml}</div>
    ${t.status!=='closed' ? `
    <div class="reply-row">
      <div class="reply-toolbar">${replySelect}${kbSelect}</div>
      <textarea id="reply-body" placeholder="Write a reply…" oninput="updateCharCount()"></textarea>
      <div class="field">
        <input type="file" id="reply-file" multiple onchange="onPickReplyFiles(this)">
        <div class="support-files" id="reply-files-preview"></div>
      </div>
      <div class="reply-foot">
        <button class="btn-primary" onclick="sendReply(${t.id})">Reply</button>
        <button class="btn-sm" style="background:#f0f0f0;color:#555" onclick="closeTicket(${t.id})">Close Ticket</button>
        <span class="char-count" id="char-count">0 characters</span>
      </div>
    </div>` : '<p style="color:#888;font-size:12px">This ticket is closed.</p>'}`;

  const thread=document.getElementById('msg-thread');
  if (thread) thread.scrollTop = thread.scrollHeight;
  detail.scrollIntoView({behavior:'smooth',block:'start'});
}

function updateCharCount(){
  const ta=document.getElementById('reply-body');
  const cc=document.getElementById('char-count');
  if(ta && cc) cc.textContent = ta.value.length + ' characters';
}

function insertCanned(sel){
  const reply=cannedReplies.find(r=>String(r.id)===sel.value);
  sel.value='';
  if(!reply) return;
  const ta=document.getElementById('reply-body');
  ta.value = ta.value ? (ta.value.replace(/\s*$/,'') + '\n\n' + reply.body) : reply.body;
  updateCharCount();
  ta.focus();
}

function insertKb(sel){
  const link=kbLinks.find(k=>String(k.id)===sel.value);
  sel.value='';
  if(!link) return;
  const ta=document.getElementById('reply-body');
  const snippet = `${link.title}: ${link.url}`;
  ta.value = ta.value ? (ta.value.replace(/\s*$/,'') + '\n\n' + snippet) : snippet;
  updateCharCount();
  ta.focus();
}

function setStatus(id,status){
  fetch('api/ticket_action.php',{
    method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'status',id,status}),
  }).then(r=>r.json()).then(d=>{ if(!d.ok){alert(d.error||'Could not update status.');return;} load(); })
    .catch(()=>alert('Network error — status not updated.'));
}

function closeTicket(id){
  if(!confirm('Close this ticket?')) return;
  setStatus(id,'closed');
}

function sendReply(id){
  const ta=document.getElementById('reply-body');
  const body=ta.value.trim();
  if(!body)return;
  const files=replyPendingFiles.slice();
  fetch('api/ticket_action.php',{
    method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'reply',id,body}),
  }).then(r=>r.json()).then(d=>{
    if(d.ok){
      replyPendingFiles=[];
      (files.length?uploadTicketFilesSequential(d.messageId,files):Promise.resolve()).then(load);
    } else { alert(d.error||'Reply failed — please try again.'); }
  }).catch(()=>alert('Network error — reply was not sent.'));
}

function addCc(id){
  const input=document.getElementById('cc-input');
  const email=input.value.trim();
  if(!email)return;
  fetch('api/ticket_action.php',{
    method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'cc_add',id,email}),
  }).then(r=>r.json()).then(d=>{
    if(d.ok){ openTicket(id); } else { alert(d.error||'Could not add CC.'); }
  }).catch(()=>alert('Network error — CC not added.'));
}

function removeCc(id,email){
  fetch('api/ticket_action.php',{
    method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'cc_remove',id,email}),
  }).then(r=>r.json()).then(()=>openTicket(id))
    .catch(()=>alert('Network error — CC not removed.'));
}

function closeDetail(){
  document.getElementById('tkt-detail').className='tkt-detail';
  openId=null;
}

// Deep-link support: backoffice_tickets.php?id=123 opens that ticket directly
// (used by the "notify department staff" email links).
const urlId = new URLSearchParams(location.search).get('id');
if (urlId) openId = parseInt(urlId, 10) || null;

load();
</script>
</body>
</html>
