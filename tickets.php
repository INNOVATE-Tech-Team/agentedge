<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Support Tickets — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .tkt-tabs{display:flex;gap:8px;margin-bottom:16px}
    .tkt-tab{padding:6px 14px;border:1px solid #ccc;border-radius:20px;font-size:12px;font-weight:700;cursor:pointer;background:white;color:#555}
    .tkt-tab.active{background:#82C112;color:#000;border-color:#82C112}
    .status-badge{display:inline-block;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;text-transform:uppercase}
    .st-open{background:#fee2e2;color:#c00}
    .st-in_progress{background:#fff4e0;color:#a06000}
    .st-closed{background:#e8f5e9;color:#2e7d32}
    .tkt-list{display:flex;flex-direction:column;gap:8px}
    .tkt-row{border:1px solid #eee;border-radius:8px;padding:12px 16px;cursor:pointer;transition:border-color 100ms}
    .tkt-row:hover{border-color:#c3dfa8}
    .tkt-row-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
    .tkt-row-num{font-size:11px;color:#aaa;font-weight:700}
    .tkt-row-title{font-weight:700;font-size:14px;flex:1}
    .tkt-row-time{font-size:11px;color:#aaa}
    .tkt-detail-wrap{border-top:2px solid #82C112;margin-top:16px;padding-top:16px;display:none}
    .tkt-detail-wrap.open{display:block}
    .msg-thread{border:1px solid #eee;border-radius:8px;margin-bottom:14px;overflow:hidden}
    .msg{padding:10px 14px;font-size:13px;border-bottom:1px solid #f5f5f5}
    .msg:last-child{border-bottom:none}
    .msg.staff{background:#f9fdf5}
    .msg-meta{font-size:11px;color:#888;margin-bottom:3px}
    .reply-row{display:flex;gap:8px}
    .reply-row textarea{flex:1;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;resize:vertical;min-height:60px}
    .btn-primary{padding:9px 18px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer}
    .btn-primary:hover{background:#5b8e0d;color:#fff}
    .btn-new{padding:8px 16px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer;margin-bottom:16px}
    .new-ticket-form{background:#f9fdf5;border:1px solid #d4edab;border-radius:10px;padding:18px 20px;margin-bottom:18px;display:none}
    .new-ticket-form.open{display:block}
    .field{margin-bottom:10px}
    .field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px}
    .field input,.field select,.field textarea{width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;box-sizing:border-box}
    .field textarea{min-height:80px;resize:vertical}
    .field input:focus,.field select:focus,.field textarea:focus{outline:2px solid #82C112}
    .empty-note{color:#bbb;font-size:13px;padding:24px;text-align:center}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('tickets', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">My Tickets</div>
    </header>
    <main class="wrap">
      <div class="card" style="padding:20px 24px">
        <button class="btn-new" onclick="toggleNewForm()">+ New Ticket</button>
        <div class="new-ticket-form" id="new-ticket-form">
          <div class="field"><label>Title</label><input type="text" id="nt-title" placeholder="Brief description"></div>
          <div class="field"><label>Department</label><select id="nt-dept"><option value="">Loading…</option></select></div>
          <div class="field"><label>Details</label><textarea id="nt-body" placeholder="Describe your issue or request…"></textarea></div>
          <button class="btn-primary" onclick="submitTicket()">Submit</button>
        </div>
        <div class="tkt-tabs">
          <span class="tkt-tab active" onclick="setTab('all',this)">All</span>
          <span class="tkt-tab" onclick="setTab('open',this)">Open</span>
          <span class="tkt-tab" onclick="setTab('closed',this)">Closed</span>
        </div>
        <div class="tkt-list" id="tkt-list"></div>
        <div class="tkt-detail-wrap" id="detail-wrap"></div>
      </div>
    </main>
  </div>
</div>
<script>
function esc(s){return String(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
function fmtDate(s){if(!s)return'—';return new Date(s).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}

let tickets=[], curTab='all', openId=null;

// Load departments
fetch('api/support_departments.php',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
  const sel=document.getElementById('nt-dept');
  sel.innerHTML='<option value="">— Select dept —</option>';
  (d.departments||[]).forEach(dept=>{
    const o=document.createElement('option');o.value=dept.slug;o.textContent=dept.name;sel.appendChild(o);
  });
});

function load(){
  fetch('api/tickets_list.php',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    tickets=d.tickets||[];render();
    if(openId) loadDetail(openId);
  });
}

function setTab(t,el){curTab=t;document.querySelectorAll('.tkt-tab').forEach(b=>b.classList.remove('active'));el.classList.add('active');render();}

function render(){
  const rows=tickets.filter(t=>curTab==='all'||(curTab==='closed'?t.status==='closed':t.status!=='closed'));
  const list=document.getElementById('tkt-list');
  if(!rows.length){list.innerHTML='<div class="empty-note">No tickets yet.</div>';return;}
  list.innerHTML=rows.map(t=>`
    <div class="tkt-row" onclick="loadDetail(${t.id})">
      <div class="tkt-row-head">
        <span class="tkt-row-num">#${t.id}</span>
        <span class="tkt-row-title">${esc(t.title)}</span>
        <span class="status-badge st-${esc(t.status)}">${esc(t.status.replace('_',' '))}</span>
        <span class="tkt-row-time">${fmtDate(t.updated_at)}</span>
      </div>
    </div>`).join('');
}

function loadDetail(id){
  openId=id;
  fetch('api/tickets_detail.php?id='+id,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
    if(!d.ok)return;
    const t=d.ticket,msgs=d.messages||[];
    const wrap=document.getElementById('detail-wrap');
    wrap.className='tkt-detail-wrap open';
    wrap.innerHTML=`
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap">
        <div style="font-size:15px;font-weight:800;flex:1">#${t.id}: ${esc(t.title)}</div>
        <span class="status-badge st-${esc(t.status)}">${esc(t.status.replace('_',' '))}</span>
        <button style="background:none;border:1px solid #ccc;border-radius:6px;padding:4px 10px;font-size:12px;cursor:pointer" onclick="closeDetail()">✕</button>
      </div>
      <div class="msg-thread">
        ${msgs.map(m=>`
          <div class="msg${m.is_staff?' staff':''}">
            <div class="msg-meta"><strong>${esc(m.is_staff?'INNOVATE Staff':m.author)}</strong> · ${fmtDate(m.created_at)}</div>
            <div>${esc(m.body)}</div>
          </div>`).join('')}
      </div>
      ${t.status!=='closed'?`
      <div class="reply-row">
        <textarea id="reply-body" placeholder="Write a reply…"></textarea>
        <button class="btn-primary" onclick="sendReply(${t.id})">Reply</button>
      </div>`:'<p style="color:#888;font-size:12px">This ticket is closed.</p>'}`;
    wrap.scrollIntoView({behavior:'smooth',block:'start'});
  });
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

function closeDetail(){document.getElementById('detail-wrap').className='tkt-detail-wrap';openId=null;}

function toggleNewForm(){
  const f=document.getElementById('new-ticket-form');
  f.classList.toggle('open');
}

function submitTicket(){
  const title=document.getElementById('nt-title').value.trim();
  const body=document.getElementById('nt-body').value.trim();
  const dept=document.getElementById('nt-dept').value;
  if(!title||!body){alert('Title and details are required.');return;}
  fetch('api/support_ticket.php',{
    method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({title,body,departmentSlug:dept}),
  }).then(r=>r.json()).then(d=>{
    if(d.ok){
      document.getElementById('new-ticket-form').classList.remove('open');
      ['nt-title','nt-body'].forEach(i=>document.getElementById(i).value='');
      document.getElementById('nt-dept').value='';
      load();
    }else alert(d.error||'Error');
  });
}

load();
</script>
</body>
</html>
