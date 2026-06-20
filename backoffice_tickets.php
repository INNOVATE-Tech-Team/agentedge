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
  <title>Tickets — AgentEdge</title>
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
    .reply-row{display:flex;gap:8px}
    .reply-row textarea{flex:1;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;resize:vertical;min-height:60px}
    .btn-primary{padding:9px 20px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer}
    .btn-primary:hover{background:#5b8e0d;color:#fff}
    .btn-sm{padding:4px 10px;font-size:11px;font-weight:700;border-radius:4px;border:none;cursor:pointer}
    .actions-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
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
function esc(s){return String(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
function fmtDate(s){if(!s)return'—';return new Date(s).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'})}

let tickets=[], curFilter='all', openId=null;

function setFilter(f,el){
  curFilter=f;
  document.querySelectorAll('.tkt-filter').forEach(b=>b.classList.remove('active'));
  el.classList.add('active');
  render();
}

function load(){
  fetch('api/tickets_list.php',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    tickets=d.tickets||[];render();
    if(openId) openTicket(openId);
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
      <td><span class="status-badge st-${esc(t.status)}">${esc(t.status.replace('_',' '))}</span></td>
      <td>${fmtDate(t.updated_at)}</td>
    </tr>`).join('');
}

function openTicket(id){
  openId=id;
  fetch('api/tickets_detail.php?id='+id,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    if(!d.ok){return;}
    const t=d.ticket, msgs=d.messages||[];
    const detail=document.getElementById('tkt-detail');
    detail.className='tkt-detail open';
    detail.innerHTML=`
      <div class="tkt-detail-head">
        <div class="tkt-detail-title">#${t.id}: ${esc(t.title)}</div>
        <span class="status-badge st-${esc(t.status)}">${esc(t.status.replace('_',' '))}</span>
      </div>
      <div class="actions-row">
        <strong style="font-size:11px;color:#888">Status:</strong>
        ${['open','in_progress','closed'].map(s=>`<button class="btn-sm" style="background:${t.status===s?'#82C112':'#f0f0f0'};color:${t.status===s?'#000':'#555'}" onclick="setStatus(${t.id},'${s}')">${s.replace('_',' ')}</button>`).join('')}
        <button class="btn-sm" style="background:#f0f0f0;color:#555;margin-left:auto" onclick="closeDetail()">✕ Close</button>
      </div>
      <div class="msg-thread">
        ${msgs.map(m=>`
          <div class="msg${m.is_staff?' staff':''}">
            <div class="msg-meta"><strong>${esc(m.author)}</strong>${m.is_staff?' (staff)':''} · ${fmtDate(m.created_at)}</div>
            <div>${esc(m.body)}</div>
          </div>`).join('')}
      </div>
      <div class="reply-row">
        <textarea id="reply-body" placeholder="Write a reply…"></textarea>
        <button class="btn-primary" onclick="sendReply(${t.id})">Reply</button>
      </div>`;
    detail.scrollIntoView({behavior:'smooth',block:'start'});
  });
}

function setStatus(id,status){
  fetch('api/ticket_action.php',{
    method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'status',id,status}),
  }).then(()=>load());
}

function sendReply(id){
  const body=document.getElementById('reply-body').value.trim();
  if(!body)return;
  fetch('api/ticket_action.php',{
    method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'reply',id,body}),
  }).then(r=>r.json()).then(d=>{if(d.ok){document.getElementById('reply-body').value='';load();}});
}

function closeDetail(){
  document.getElementById('tkt-detail').className='tkt-detail';
  openId=null;
}

load();
</script>
</body>
</html>
