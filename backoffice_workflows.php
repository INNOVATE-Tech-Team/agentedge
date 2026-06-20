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
  <title>Workflows — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    /* Board list */
    .boards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-bottom:24px}
    .board-card{border:1px solid #eee;border-radius:10px;padding:16px;cursor:pointer;transition:border-color 100ms,box-shadow 100ms;background:white}
    .board-card:hover{border-color:#82C112;box-shadow:0 2px 8px rgba(130,193,18,.12)}
    .board-name{font-size:14px;font-weight:800;color:#111;margin-bottom:4px}
    .board-meta{font-size:11px;color:#aaa}
    .board-actions{display:flex;gap:6px;margin-top:10px}
    /* Kanban */
    .kanban{display:flex;gap:12px;overflow-x:auto;padding-bottom:16px;scroll-snap-type:x mandatory;min-height:400px}
    .kanban::-webkit-scrollbar{height:6px}
    .kanban::-webkit-scrollbar-thumb{background:#ddd;border-radius:3px}
    .kb-col{flex-shrink:0;scroll-snap-align:start;width:240px;background:#f7f7f7;border-radius:10px;padding:12px;display:flex;flex-direction:column;gap:8px}
    .kb-col-header{display:flex;align-items:center;gap:6px;padding:4px 0 8px;border-bottom:2px solid;margin-bottom:4px}
    .kb-col-name{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;flex:1}
    .kb-col-count{font-size:10px;font-weight:700;background:rgba(0,0,0,.08);border-radius:8px;padding:1px 6px}
    .kb-card{background:white;border:1px solid #e8e8e8;border-radius:7px;padding:10px 12px;cursor:grab;transition:box-shadow 100ms}
    .kb-card:hover{box-shadow:0 2px 8px rgba(0,0,0,.1);border-color:#ccc}
    .kb-card.dragging{opacity:.5;box-shadow:0 4px 16px rgba(0,0,0,.2)}
    .kb-card-title{font-size:13px;font-weight:700;color:#111;line-height:1.3;margin-bottom:4px}
    .kb-card-meta{font-size:10px;color:#aaa;display:flex;gap:8px;flex-wrap:wrap}
    .kb-add-btn{background:none;border:1px dashed #ccc;border-radius:6px;padding:6px;width:100%;font-size:12px;color:#aaa;cursor:pointer;text-align:center}
    .kb-add-btn:hover{border-color:#82C112;color:#5b8e0d;background:#f9fdf5}
    .kb-drop-zone{min-height:40px;border-radius:6px;transition:background 100ms}
    .kb-drop-zone.over{background:#e8f5e9}
    /* Board header */
    .board-header{display:flex;align-items:center;gap:12px;margin-bottom:16px}
    .back-btn{background:none;border:1px solid #ccc;border-radius:6px;padding:5px 12px;font-size:12px;cursor:pointer;color:#555}
    .back-btn:hover{background:#f0f0f0}
    /* Modal */
    .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:300;align-items:center;justify-content:center}
    .modal-overlay.open{display:flex}
    .modal{background:white;border-radius:12px;padding:24px;width:420px;max-width:96vw;box-shadow:0 8px 40px rgba(0,0,0,.18)}
    .modal h3{margin:0 0 16px;font-size:15px;font-weight:800}
    .field{margin-bottom:12px}
    .field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px}
    .field input,.field select,.field textarea{width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;box-sizing:border-box}
    .field textarea{min-height:70px;resize:vertical}
    .field input:focus,.field select:focus,.field textarea:focus{outline:2px solid #82C112}
    .modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:16px}
    .btn-primary{padding:8px 18px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer}
    .btn-primary:hover{background:#5b8e0d;color:#fff}
    .btn-cancel{padding:8px 14px;border:1px solid #ccc;background:white;color:#555;border-radius:6px;cursor:pointer;font-size:13px}
    .btn-sm{padding:4px 10px;font-size:11px;font-weight:700;border-radius:4px;border:1px solid #ddd;background:white;cursor:pointer;color:#333}
    .btn-danger{background:#fee2e2;color:#c00;border-color:#f5c6c6}
    .empty-note{color:#bbb;font-size:13px;padding:24px;text-align:center}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('bo_workflows', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Workflows</div>
    </header>
    <main class="wrap">
      <div class="card" style="padding:20px 24px" id="main-card">
        <!-- Board list view -->
        <div id="boards-view">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <div style="font-size:13px;color:#888">Kanban boards for tracking internal tasks</div>
            <button class="btn-primary" onclick="openCreateBoard()">+ New Board</button>
          </div>
          <div class="boards-grid" id="boards-grid"><div class="empty-note">Loading…</div></div>
        </div>
        <!-- Kanban view -->
        <div id="kanban-view" style="display:none">
          <div class="board-header">
            <button class="back-btn" onclick="showBoards()">← All Boards</button>
            <div style="font-size:16px;font-weight:800;flex:1" id="board-title"></div>
            <button class="btn-sm btn-danger" onclick="deleteCurrentBoard()">Delete Board</button>
          </div>
          <div class="kanban" id="kanban"></div>
        </div>
      </div>
    </main>
  </div>
</div>

<!-- Create Board Modal -->
<div class="modal-overlay" id="create-board-modal">
  <div class="modal">
    <h3>New Board</h3>
    <div class="field"><label>Board Name</label><input type="text" id="board-name" placeholder="e.g. Onboarding Tasks"></div>
    <div class="field"><label>Description (optional)</label><input type="text" id="board-desc"></div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeModal('create-board-modal')">Cancel</button>
      <button class="btn-primary" onclick="createBoard()">Create</button>
    </div>
  </div>
</div>

<!-- Add/Edit Card Modal -->
<div class="modal-overlay" id="card-modal">
  <div class="modal">
    <h3 id="card-modal-title">New Card</h3>
    <input type="hidden" id="card-id">
    <input type="hidden" id="card-stage-id">
    <input type="hidden" id="card-board-id">
    <div class="field"><label>Title</label><input type="text" id="card-title" placeholder="What needs to be done?"></div>
    <div class="field"><label>Description</label><textarea id="card-desc" placeholder="Optional details…"></textarea></div>
    <div class="field"><label>Assigned to</label><input type="text" id="card-assignee" placeholder="email or name"></div>
    <div class="field"><label>Due date</label><input type="date" id="card-due"></div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeModal('card-modal')">Cancel</button>
      <button class="btn-sm btn-danger" id="card-delete-btn" style="display:none;margin-right:auto" onclick="deleteCard()">Delete</button>
      <button class="btn-primary" onclick="saveCard()">Save</button>
    </div>
  </div>
</div>

<script>
function esc(s){return String(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
function api(body){return fetch('api/wf_action.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}).then(r=>r.json())}

let boards=[], currentBoard=null, dragItem=null;

// ── Board list ────────────────────────────────────────────────────────────────
function loadBoards(){
  api({action:'list_boards'}).then(d=>{boards=d.boards||[];renderBoards();});
}
function renderBoards(){
  const g=document.getElementById('boards-grid');
  if(!boards.length){g.innerHTML='<div class="empty-note">No boards yet. Create one to get started.</div>';return;}
  g.innerHTML=boards.map(b=>`
    <div class="board-card">
      <div class="board-name">${esc(b.name)}</div>
      <div class="board-meta">${esc(b.description||'')}</div>
      <div class="board-actions">
        <button class="btn-primary" style="font-size:12px;padding:5px 12px" onclick="openBoard(${b.id})">Open</button>
        <button class="btn-sm btn-danger" onclick="deleteBoard(${b.id})">Delete</button>
      </div>
    </div>`).join('');
}
function openCreateBoard(){document.getElementById('board-name').value='';document.getElementById('board-desc').value='';document.getElementById('create-board-modal').classList.add('open');}
function createBoard(){
  const name=document.getElementById('board-name').value.trim();
  if(!name){alert('Name required');return;}
  api({action:'create_board',name,description:document.getElementById('board-desc').value.trim()||null})
    .then(d=>{if(d.ok){closeModal('create-board-modal');openBoard(d.id);}else alert(d.error)});
}
function deleteBoard(id){
  if(!confirm('Delete this board and all its cards?'))return;
  api({action:'delete_board',id}).then(()=>loadBoards());
}
function deleteCurrentBoard(){
  if(!currentBoard)return;
  if(!confirm('Delete "'+currentBoard.board.name+'" and all its cards?'))return;
  api({action:'delete_board',id:currentBoard.board.id}).then(()=>{showBoards();loadBoards();});
}

// ── Kanban ────────────────────────────────────────────────────────────────────
const STAGE_COLORS=['#82C112','#f59e0b','#3b82f6','#10b981','#8b5cf6','#ef4444'];
function openBoard(id){
  api({action:'get_board',id}).then(d=>{
    if(!d.ok)return;
    currentBoard=d;
    document.getElementById('board-title').textContent=d.board.name;
    document.getElementById('boards-view').style.display='none';
    document.getElementById('kanban-view').style.display='';
    renderKanban(d.stages);
  });
}
function showBoards(){
  document.getElementById('boards-view').style.display='';
  document.getElementById('kanban-view').style.display='none';
  currentBoard=null;
}

function renderKanban(stages){
  const kb=document.getElementById('kanban');
  kb.innerHTML='';
  stages.forEach((st,i)=>{
    const col=document.createElement('div');
    col.className='kb-col';
    col.dataset.stageId=st.id;
    const color=STAGE_COLORS[i%STAGE_COLORS.length];
    col.innerHTML=`
      <div class="kb-col-header" style="border-color:${color}">
        <span class="kb-col-name" style="color:${color}">${esc(st.name)}</span>
        <span class="kb-col-count">${st.items.length}</span>
      </div>
      <div class="kb-drop-zone" data-stage-id="${st.id}"
           ondragover="onDragOver(event,this)" ondragleave="onDragLeave(this)" ondrop="onDrop(event,this)">
        ${st.items.map(item=>cardHtml(item)).join('')}
      </div>
      <button class="kb-add-btn" onclick="openNewCard(${st.id},${currentBoard.board.id})">+ Add card</button>`;
    kb.appendChild(col);
    // Wire drag listeners
    col.querySelectorAll('.kb-card').forEach(el=>wireDrag(el,st.items.find(x=>x.id==el.dataset.id)));
  });
}

function cardHtml(item){
  const due = item.due_date ? '<span>📅 '+item.due_date.slice(0,10)+'</span>' : '';
  const ass = item.assigned_to ? '<span>👤 '+esc(item.assigned_to)+'</span>' : '';
  return `<div class="kb-card" draggable="true" data-id="${item.id}" onclick="openEditCard(${item.id})">
    <div class="kb-card-title">${esc(item.title)}</div>
    ${(item.description?`<div style="font-size:11px;color:#888;margin-bottom:4px;white-space:pre-wrap">${esc(item.description.slice(0,80))}${item.description.length>80?'…':''}</div>`:'') }
    <div class="kb-card-meta">${due}${ass}</div>
  </div>`;
}

function wireDrag(el,item){
  el.addEventListener('dragstart',e=>{
    dragItem={el,item};el.classList.add('dragging');
    e.dataTransfer.effectAllowed='move';
  });
  el.addEventListener('dragend',()=>{el.classList.remove('dragging');dragItem=null;});
}

function onDragOver(e,zone){e.preventDefault();zone.classList.add('over');}
function onDragLeave(zone){zone.classList.remove('over');}
function onDrop(e,zone){
  e.preventDefault();zone.classList.remove('over');
  if(!dragItem)return;
  const newStage=parseInt(zone.dataset.stageId);
  if(dragItem.item.stage_id===newStage)return;
  api({action:'move_item',id:dragItem.item.id,stage_id:newStage})
    .then(()=>openBoard(currentBoard.board.id));
}

// ── Card modal ────────────────────────────────────────────────────────────────
function openNewCard(stageId,boardId){
  document.getElementById('card-id').value='';
  document.getElementById('card-stage-id').value=stageId;
  document.getElementById('card-board-id').value=boardId;
  ['card-title','card-desc','card-assignee','card-due'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('card-modal-title').textContent='New Card';
  document.getElementById('card-delete-btn').style.display='none';
  document.getElementById('card-modal').classList.add('open');
}
function openEditCard(id){
  const stage=currentBoard.stages.find(s=>s.items.find(i=>i.id===id));
  const item=stage&&stage.items.find(i=>i.id===id);
  if(!item)return;
  document.getElementById('card-id').value=id;
  document.getElementById('card-title').value=item.title;
  document.getElementById('card-desc').value=item.description||'';
  document.getElementById('card-assignee').value=item.assigned_to||'';
  document.getElementById('card-due').value=(item.due_date||'').slice(0,10);
  document.getElementById('card-modal-title').textContent='Edit Card';
  document.getElementById('card-delete-btn').style.display='';
  document.getElementById('card-modal').classList.add('open');
}
function saveCard(){
  const id=document.getElementById('card-id').value;
  const payload={
    title:document.getElementById('card-title').value.trim(),
    description:document.getElementById('card-desc').value.trim()||null,
    assigned_to:document.getElementById('card-assignee').value.trim()||null,
    due_date:document.getElementById('card-due').value||null,
  };
  if(!payload.title){alert('Title required');return;}
  if(id){
    api({action:'update_item',id:parseInt(id),...payload}).then(d=>{
      if(d.ok){closeModal('card-modal');openBoard(currentBoard.board.id);}
    });
  }else{
    payload.action='create_item';
    payload.stage_id=parseInt(document.getElementById('card-stage-id').value);
    payload.board_id=parseInt(document.getElementById('card-board-id').value);
    api(payload).then(d=>{
      if(d.ok){closeModal('card-modal');openBoard(currentBoard.board.id);}
    });
  }
}
function deleteCard(){
  const id=document.getElementById('card-id').value;
  if(!id||!confirm('Delete this card?'))return;
  api({action:'delete_item',id:parseInt(id)}).then(()=>{closeModal('card-modal');openBoard(currentBoard.board.id);});
}

function closeModal(id){document.getElementById(id).classList.remove('open');}

loadBoards();
</script>
</body>
</html>
