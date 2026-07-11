<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/roles.php';
$agent = require_login();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <style>
    .ann-panel{margin-bottom:20px}
    .ann-panel h2{margin:0 0 10px;font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px}
    .ann-panel h2 a{font-size:11px;font-weight:700;color:#5b8e0d;text-decoration:none;margin-left:auto}
    .ann-panel h2 a:hover{text-decoration:underline}
    .ann-card{background:#fff;border-radius:10px;box-shadow:0 1px 5px rgba(0,0,0,.08);margin-bottom:12px;overflow:hidden;border:1px solid #eee}
    .ann-card.pinned{box-shadow:0 1px 5px rgba(0,0,0,.08),inset 0 3px 0 #f59e0b}
    .ann-card-img-wrap{position:relative;overflow:hidden;border-radius:10px 10px 0 0}
    .ann-card-img{width:100%;display:block;object-fit:cover}
    .ann-card-overlay{position:absolute;bottom:0;left:0;right:0;padding:32px 15px 13px;background:linear-gradient(0deg,rgba(0,0,0,.68) 0%,transparent 100%)}
    .ann-card-overlay-pin{font-size:10px;font-weight:700;color:rgba(255,210,70,1);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px}
    .ann-card-overlay-title{font-size:15px;font-weight:800;color:#fff;line-height:1.3;text-shadow:0 1px 4px rgba(0,0,0,.35)}
    .ann-card-body{padding:11px 15px 12px}
    .ann-card-no-img{border-left:3px solid #82C112;border-radius:0 0 10px 10px}
    .ann-card-no-img.pinned{border-left-color:#f59e0b}
    .ann-card-pin{font-size:10px;font-weight:700;color:#a06000;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
    .ann-card-title{font-size:14px;font-weight:700;color:#111;margin-bottom:4px}
    .ann-card-text{font-size:12px;color:#555;line-height:1.5;margin-bottom:5px;display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}
    .ann-card-text h2{font-size:14px;font-weight:800;margin:0 0 3px}
    .ann-card-text h3{font-size:13px;font-weight:700;margin:0 0 2px}
    .ann-card-text p{margin:0 0 3px}
    .ann-card-text ul,.ann-card-text ol{margin:0 0 3px;padding-left:14px}
    .ann-card-text a{color:#5b8e0d}
    .ann-card-meta{font-size:10px;color:#bbb}
    .ann-card-side{display:flex;align-items:stretch}
    .ann-card-side-img{object-fit:cover;display:block;flex-shrink:0}
  </style>
</head>
<body>
  <div class="layout">
    <?php render_sidebar('dashboard', $agent); ?>

    <!-- Main content -->
    <div class="content">
      <header class="content-top">
        <div class="content-title">Dashboard</div>
        <div class="content-hello">Welcome back, <?= htmlspecialchars(explode(' ', $agent['name'] ?: 'Agent')[0]) ?></div>
      </header>

      <main class="wrap">
        <div id="sample-banner" class="banner" hidden></div>

        <section class="tiles">
          <div class="tile tile-blue"><div class="tile-val" id="t-volume">—</div><div class="tile-lbl">Sales Volume</div></div>
          <div class="tile tile-green"><div class="tile-val" id="t-closed">—</div><div class="tile-lbl">Closed Deals</div></div>
          <div class="tile tile-amber"><div class="tile-val" id="t-residual">—</div><div class="tile-lbl">Residual Income</div></div>
          <div class="tile tile-red"><div class="tile-val" id="t-recruits">—</div><div class="tile-lbl">Recruits</div></div>
        </section>

        <div id="ann-panel" class="card ann-panel" style="display:none">
          <h2>Announcements <a href="backoffice_announcements.php" id="ann-manage-link" style="display:none">Manage →</a></h2>
          <div id="ann-list"></div>
        </div>

        <div class="grid2">
          <section class="card">
            <h2>Cap Progress</h2>
            <div class="cap-wrap">
              <canvas id="capWheel" width="220" height="220"></canvas>
              <div class="cap-center"><span id="cap-pct">0%</span></div>
            </div>
            <dl class="cap-legend">
              <div><dt>Cap</dt><dd id="cap-amount">—</dd></div>
              <div><dt>Paid</dt><dd id="cap-paid">—</dd></div>
              <div><dt>Remaining</dt><dd id="cap-remaining">—</dd></div>
            </dl>
            <p class="src-note" id="cap-note"></p>
          </section>

          <section class="card">
            <h2>Your Network &amp; Residual Income</h2>
            <div class="residual-head">
              <span class="residual-amt" id="residual-amt">—</span>
              <span class="residual-lbl">residual income earned</span>
            </div>
            <table class="tx" id="network-table" hidden>
              <thead><tr><th>Recruit</th><th class="num">Volume</th><th class="num">Deals</th></tr></thead>
              <tbody id="network-body"></tbody>
            </table>
            <div id="network-empty" class="network-empty">No recruits in your network yet.</div>
          </section>
        </div>

        <!-- Closing Calendar -->
        <section class="card" id="cc-card" style="margin-top:16px">
          <h2 style="margin:0 0 14px;font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px">
            Closing Calendar
            <span id="cc-nav" style="display:flex;align-items:center;gap:6px;margin-left:auto">
              <button onclick="ccPrev()" style="all:unset;cursor:pointer;font-size:16px;padding:0 4px;color:#888">&#8592;</button>
              <span id="cc-month-lbl" style="font-size:12px;font-weight:700;color:#555"></span>
              <button onclick="ccNext()" style="all:unset;cursor:pointer;font-size:16px;padding:0 4px;color:#888">&#8594;</button>
            </span>
          </h2>
          <div id="cc-body"></div>
        </section>

      </main>
    </div>
  </div>

  <script src="assets/app.js"></script>
  <script>
  (function(){
    fetch('api/announcements.php',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      const items=d.items||[];
      if(!items.length)return;
      const panel=document.getElementById('ann-panel');
      const list=document.getElementById('ann-list');
      panel.style.display='';
      const esc=s=>String(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
      const sizeH={compact:'130px',standard:'220px',large:'370px'};
      const sideW={compact:'90px',standard:'130px',large:'170px'};
      list.innerHTML=items.slice(0,5).map(a=>{
        const hasImg=!!a.image_key;
        const dt=new Date(a.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
        const imgUrl=`api/announcements.php?action=image&key=${encodeURIComponent(a.image_key)}`;
        if(hasImg && (a.image_position==='left'||a.image_position==='right')){
          const w=sideW[a.image_size]||'130px';
          const rL=a.image_position==='left'?'10px 0 0 10px':'0 10px 10px 0';
          const imgEl=`<img class="ann-card-side-img" src="${imgUrl}" style="width:${w};border-radius:${rL}" alt="">`;
          const txtEl=`<div class="ann-card-body" style="flex:1;min-width:0">
              ${a.pinned?'<div class="ann-card-pin">Pinned</div>':''}
              <div class="ann-card-title">${esc(a.title)}</div>
              <div class="ann-card-text">${a.body}</div>
              <div class="ann-card-meta">${dt}</div>
            </div>`;
          return `<div class="ann-card ann-card-side${a.pinned?' pinned':''}">
            ${a.image_position==='left'?imgEl+txtEl:txtEl+imgEl}
          </div>`;
        }
        if(hasImg){
          const h=sizeH[a.image_size]||'220px';
          return `<div class="ann-card${a.pinned?' pinned':''}">
            <div class="ann-card-img-wrap">
              <img class="ann-card-img" src="${imgUrl}" style="height:${h}" alt="">
              <div class="ann-card-overlay">
                ${a.pinned?'<div class="ann-card-overlay-pin">Pinned</div>':''}
                <div class="ann-card-overlay-title">${esc(a.title)}</div>
              </div>
            </div>
            <div class="ann-card-body">
              <div class="ann-card-text">${a.body}</div>
              <div class="ann-card-meta">${dt}</div>
            </div>
          </div>`;
        }
        return `<div class="ann-card${a.pinned?' pinned':''}">
          <div class="ann-card-body ann-card-no-img${a.pinned?' pinned':''}">
            ${a.pinned?'<div class="ann-card-pin">Pinned</div>':''}
            <div class="ann-card-title">${esc(a.title)}</div>
            <div class="ann-card-text">${a.body}</div>
            <div class="ann-card-meta">${dt}</div>
          </div>
        </div>`;
      }).join('');
    }).catch(()=>{});
    <?php if (can_post_announcements()): ?>
    document.getElementById('ann-manage-link').style.display='';
    <?php endif; ?>
  })();
  </script>
  <style>
    .cc-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;margin-bottom:14px}
    .cc-day-name{text-align:center;font-size:10px;font-weight:700;color:#aaa;text-transform:uppercase;padding:3px 0}
    .cc-cell{min-height:36px;border-radius:5px;padding:3px 4px;position:relative;background:#fafafa}
    .cc-cell.cc-today{background:#eef5e8;font-weight:800}
    .cc-cell.cc-blank{background:transparent}
    .cc-cell-num{font-size:11px;font-weight:600;color:#555;line-height:1}
    .cc-dot{width:6px;height:6px;border-radius:50%;margin-top:2px}
    .cc-dot.closing{background:#82C112}
    .cc-dot.under_contract{background:#2C9CC9}
    .cc-dot.target{background:#f59e0b}
    .cc-list{border-top:1px solid #f0f0f0;padding-top:12px}
    .cc-ev{display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid #fafafa}
    .cc-ev:last-child{border-bottom:0}
    .cc-ev-dot{width:8px;height:8px;border-radius:50%;flex:none}
    .cc-ev-date{font-size:11px;font-weight:700;color:#888;min-width:80px;white-space:nowrap}
    .cc-ev-title{font-size:13px;color:#222;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .cc-ev-type{font-size:10px;font-weight:700;padding:1px 6px;border-radius:3px;flex:none}
  </style>
  <script>
  (function(){
    const MONTHS=['January','February','March','April','May','June','July','August','September','October','November','December'];
    const DAYS=['Su','Mo','Tu','We','Th','Fr','Sa'];
    let ccY=new Date().getFullYear(), ccM=new Date().getMonth(), ccCache={};

    function ccKey(){return ccY+'-'+String(ccM+1).padStart(2,'0');}

    function ccLoad(key){
      if(ccCache[key]) return Promise.resolve(ccCache[key]);
      return fetch('api/dotloop_cal.php?month='+encodeURIComponent(key),{credentials:'same-origin'})
        .then(r=>r.json()).then(d=>{ccCache[key]=d;return d;}).catch(()=>({events:[],connected:false}));
    }

    function ccDraw(){
      const key=ccKey();
      document.getElementById('cc-month-lbl').textContent=MONTHS[ccM]+' '+ccY;
      document.getElementById('cc-body').innerHTML='<div style="padding:20px;text-align:center;color:#bbb;font-size:12px">Loading…</div>';
      ccLoad(key).then(function(d){
        if(!d.connected){
          document.getElementById('cc-body').innerHTML=
            '<div style="padding:16px;text-align:center;color:#888;font-size:13px">'+
            'Connect DotLoop to see your closing calendar. '+
            '<a href="dotloop_connect.php" style="color:#5b8e0d;font-weight:700">Connect →</a></div>';
          return;
        }
        const evs=d.events||[];
        // Build a map: day → [events]
        const byDay={};
        evs.forEach(function(e){
          const day=parseInt(e.date.split('-')[2],10);
          (byDay[day]=byDay[day]||[]).push(e);
        });
        const today=new Date(), isNow=(today.getFullYear()===ccY&&today.getMonth()===ccM);
        const firstDay=new Date(ccY,ccM,1).getDay(), daysInMo=new Date(ccY,ccM+1,0).getDate();
        let html='<div class="cc-grid">';
        DAYS.forEach(function(d){html+='<div class="cc-day-name">'+d+'</div>';});
        for(let i=0;i<firstDay;i++) html+='<div class="cc-cell cc-blank"></div>';
        for(let d=1;d<=daysInMo;d++){
          const dayEvs=byDay[d]||[], isToday=isNow&&today.getDate()===d;
          html+='<div class="cc-cell'+(isToday?' cc-today':'')+'"><div class="cc-cell-num">'+d+'</div>';
          dayEvs.slice(0,2).forEach(function(e){
            html+='<div class="cc-dot '+(e.type||'closing')+'" title="'+e.title+'"></div>';
          });
          html+='</div>';
        }
        html+='</div>';
        // Upcoming list
        const typeLabel={closing:'Closing',under_contract:'Under Contract',target:'Target Date'};
        const typeColor={closing:'#82C112',under_contract:'#2C9CC9',target:'#f59e0b'};
        if(evs.length){
          html+='<div class="cc-list">';
          evs.forEach(function(e){
            const dt=new Date(e.date+'T12:00:00');
            const lbl=dt.toLocaleDateString('en-US',{month:'short',day:'numeric'});
            const type=e.type||'closing', col=typeColor[type]||'#82C112';
            html+='<div class="cc-ev">'+
              '<div class="cc-ev-dot" style="background:'+col+'"></div>'+
              '<div class="cc-ev-date">'+lbl+'</div>'+
              '<div class="cc-ev-title">'+String(e.title||'').replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];})+'</div>'+
              '<div class="cc-ev-type" style="background:'+col+'22;color:'+col+'">'+(typeLabel[type]||type)+'</div>'+
              '</div>';
          });
          html+='</div>';
        } else {
          html+='<p style="color:#bbb;font-size:12px;margin:6px 0 0;text-align:center">No closings or target dates this month.</p>';
        }
        document.getElementById('cc-body').innerHTML=html;
      });
    }

    window.ccPrev=function(){if(--ccM<0){ccM=11;ccY--;}ccDraw();};
    window.ccNext=function(){if(++ccM>11){ccM=0;ccY++;}ccDraw();};
    ccDraw();
  })();
  </script>
</body>
</html>
