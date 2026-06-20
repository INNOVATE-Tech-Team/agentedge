<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/roles.php';
require __DIR__ . '/nav.php';

$agent = require_login();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>My Network — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    /* Admin search */
    .search-row{display:flex;gap:0;max-width:420px;margin-bottom:20px}
    .search-row input{flex:1;padding:9px 12px;font-size:13px;border:1px solid #ccc;border-radius:6px 0 0 6px;outline:none}
    .search-row input:focus{border-color:#82C112}
    .search-row button{padding:9px 18px;border:none;background:#82C112;color:#000;font-size:13px;font-weight:700;border-radius:0 6px 6px 0;cursor:pointer}

    /* Root card */
    .root-card{display:flex;align-items:center;gap:14px;padding:14px 18px;background:#f9fdf5;border:2px solid #82C112;border-radius:10px;margin-bottom:20px}
    .root-avatar{width:44px;height:44px;border-radius:50%;background:#82C112;color:#000;font-size:14px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .root-info{flex:1;min-width:0}
    .root-name{font-size:15px;font-weight:800;color:#111}
    .root-email{font-size:12px;color:#666}
    .root-chips{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;flex-shrink:0}

    /* Line summary bar */
    .line-summary{display:flex;gap:0;border:1px solid #e6e7e8;border-radius:8px;overflow:hidden;margin-bottom:20px}
    .line-pill{flex:1;text-align:center;padding:9px 6px;font-size:12px;cursor:pointer;border-right:1px solid #e6e7e8;background:white;transition:background 100ms}
    .line-pill:last-child{border-right:none}
    .line-pill:hover{background:#f9fdf5}
    .line-pill.active{background:#f9fdf5;border-bottom:2px solid #82C112}
    .line-pill.zero{color:#ccc;cursor:default}
    .line-pill .lp-count{font-size:16px;font-weight:800;color:#111;display:block}
    .line-pill.zero .lp-count{color:#ddd}
    .line-pill .lp-label{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#888;display:block;margin-top:1px}

    /* Each line level section */
    .level-section{margin-bottom:16px}
    .level-header{display:flex;align-items:baseline;gap:8px;margin-bottom:8px}
    .level-badge{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;padding:3px 8px;border-radius:10px;color:#fff}
    .badge-1{background:#82C112}
    .badge-2{background:#5b8e0d}
    .badge-3{background:#4a7a0a}
    .badge-4{background:#3a6307}
    .badge-5{background:#2b4d04}
    .level-title{font-size:13px;font-weight:700;color:#333}
    .level-sub{font-size:11px;color:#aaa;margin-left:auto}

    /* Horizontal agent strip */
    .agent-strip{display:flex;gap:8px;overflow-x:auto;padding-bottom:8px;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch}
    .agent-strip::-webkit-scrollbar{height:4px}
    .agent-strip::-webkit-scrollbar-thumb{background:#ddd;border-radius:2px}

    /* Agent card in strip */
    .ag-card{flex-shrink:0;scroll-snap-align:start;display:flex;flex-direction:column;align-items:center;gap:3px;padding:10px 10px 8px;background:white;border:1.5px solid #e6e7e8;border-radius:8px;cursor:pointer;min-width:108px;max-width:130px;text-align:center;transition:border-color 100ms,box-shadow 100ms;position:relative}
    .ag-card:hover{border-color:#c3dfa8;background:#fafff5}
    .ag-card.selected{border-color:#82C112;background:#f9fdf5;box-shadow:0 2px 8px rgba(130,193,18,.2)}
    .ag-card.no-kids{opacity:.7;cursor:default}
    .ag-avatar{width:34px;height:34px;border-radius:50%;background:#e8f5d0;color:#5b8e0d;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .ag-card.selected .ag-avatar{background:#82C112;color:#000}
    .ag-name{font-size:11px;font-weight:700;color:#222;line-height:1.2;word-break:break-word}
    /* Production stats */
    .ag-vol{font-size:13px;font-weight:800;color:#2d7a00;margin-top:2px}
    .ag-vol.zero{color:#ccc;font-weight:500}
    .ag-deals{font-size:10px;color:#888}
    .ag-deals span{color:#555;font-weight:700}
    .ag-divider{width:70%;height:1px;background:#f0f0f0;margin:3px 0}
    .ag-count{font-size:10px;padding:1px 6px;border-radius:8px;background:#fff4e0;color:#a06000;font-weight:700}
    .ag-no-rec{font-size:10px;color:#ccc}
    /* Arrow indicator */
    .ag-card.selected::after{content:'▼';position:absolute;bottom:-12px;left:50%;transform:translateX(-50%);font-size:8px;color:#82C112}

    /* Divider between levels */
    .level-divider{height:2px;background:linear-gradient(to right,#82C112 0%,#e6e7e8 60%);border-radius:1px;margin:4px 0 14px}

    /* Empty / loading */
    .empty-prompt{padding:28px;text-align:center;color:#bbb;font-size:13px;border:1px dashed #e0e0e0;border-radius:8px}
    .loading-msg{padding:28px;text-align:center;color:#888;font-size:13px}
    .error-msg{padding:14px 18px;background:#fff0f0;border:1px solid #f5c6c6;border-radius:6px;color:#c00;font-size:13px;margin-top:8px}

    /* Chips */
    .chip{font-size:10px;padding:2px 6px;border-radius:8px;font-weight:700;white-space:nowrap}
    .chip-vol{background:#f0f5e8;color:#5b8e0d}
    .chip-rec{background:#fff4e0;color:#a06000}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('network', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">My Network</div>
    </header>
    <main class="wrap">
      <div class="card" style="padding:20px 24px">

        <?php if (is_leader()): ?>
        <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:6px">View agent network</div>
        <div class="search-row">
          <input type="email" id="email-input" placeholder="agent@email.com" value="<?= htmlspecialchars($agent['email']) ?>">
          <button onclick="loadTree()">Load</button>
        </div>
        <?php endif; ?>

        <div id="tree-wrap">
          <div class="empty-prompt">Enter an agent&rsquo;s email above and click <strong>Load</strong> to view their network.</div>
        </div>

      </div>
    </main>
  </div>
</div>
<script>
const MY_EMAIL  = <?= json_encode($agent['email']) ?>;
const IS_LEADER = <?= json_encode(is_leader()) ?>;
const LINE_NAMES = ['','1st Line','2nd Line','3rd Line','4th Line','5th Line'];

function esc(s){ return String(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
function initials(n){ const p=n.trim().split(/\s+/); return p.length>=2?(p[0][0]+p[p.length-1][0]).toUpperCase():p[0].slice(0,2).toUpperCase(); }
function fmtMoney(n){ if(!n)return null; if(n>=1000000)return '$'+(n/1000000).toFixed(1).replace(/\.0$/,'')+' M'; if(n>=1000)return '$'+Math.round(n/1000)+'k'; return '$'+Math.round(n); }

// Count agents at each depth level (1-5) from a root node
function countByLevel(node, depth) {
  const counts = [0,0,0,0,0]; // index 0=1st line ... 4=5th line
  function walk(n, d) {
    if (!n.children) return;
    n.children.forEach(c => {
      if (d >= 1 && d <= 5) counts[d-1]++;
      if (d < 5) walk(c, d+1);
    });
  }
  walk(node, depth);
  return counts;
}

// Flatten all nodes at a given depth
function nodesAtDepth(root, target) {
  const out = [];
  function walk(n, d) {
    if (d === target) { out.push(n); return; }
    (n.children||[]).forEach(c => walk(c, d+1));
  }
  walk(root, 0);
  return out;
}

// State: selected agent at each line level
let ROOT    = null;
let path    = [null, null, null, null, null]; // path[0]=selected 1st-line agent, etc.

function selectAgent(level, agent) {
  // level: 1-5 (which line we clicked in)
  path[level-1] = agent;
  // Clear selections deeper than this level
  for (let i = level; i < 5; i++) path[i] = null;
  renderLevels();
}

function renderLevels() {
  const wrap = document.getElementById('levels-wrap');
  if (!wrap) return;
  wrap.innerHTML = '';

  // Build each level section
  for (let lvl = 1; lvl <= 5; lvl++) {
    // The parent at this level: for lvl=1 it's root, for lvl=2 it's path[0], etc.
    const parent = lvl === 1 ? ROOT : path[lvl-2];
    if (!parent) break; // no selection at previous level → stop

    const kids = parent.children || [];
    if (kids.length === 0 && lvl > 1) {
      // Show no-recruits message for selected agent
      const msg = document.createElement('div');
      msg.className = 'empty-prompt';
      msg.style.marginTop = '8px';
      msg.textContent = (parent.name||'This agent') + ' has no recruits yet.';
      wrap.appendChild(msg);
      break;
    }
    if (kids.length === 0) break;

    const section = document.createElement('div');
    section.className = 'level-section';

    // Divider (not for first level)
    if (lvl > 1) {
      const div = document.createElement('div');
      div.className = 'level-divider';
      section.appendChild(div);
    }

    // Header
    const hdr = document.createElement('div');
    hdr.className = 'level-header';
    hdr.innerHTML = `
      <span class="level-badge badge-${lvl}">${LINE_NAMES[lvl]}</span>
      <span class="level-title">${esc(kids.length)} recruit${kids.length===1?'':'s'}${lvl>1?' of '+esc(parent.name):''}</span>`;
    section.appendChild(hdr);

    // Strip
    const strip = document.createElement('div');
    strip.className = 'agent-strip';
    const selectedAtThisLevel = path[lvl-1];

    kids.forEach(kid => {
      const hasKids = (kid.children||[]).length > 0;
      const isSelected = selectedAtThisLevel && selectedAtThisLevel === kid;
      const vol = fmtMoney(kid.volume);

      const deals = kid.deals || 0;
      const card = document.createElement('div');
      card.className = 'ag-card' + (isSelected ? ' selected' : '') + (!hasKids ? ' no-kids' : '');
      card.innerHTML = `
        <div class="ag-avatar">${esc(initials(kid.name))}</div>
        <div class="ag-name">${esc(kid.name)}</div>
        <div class="ag-vol${vol ? '' : ' zero'}">${vol || '—'}</div>
        <div class="ag-deals"><span>${deals}</span> deal${deals===1?'':'s'}</div>
        <div class="ag-divider"></div>
        ${hasKids ? `<div class="ag-count">${kid.children.length} recruit${kid.children.length===1?'':'s'}</div>` : '<div class="ag-no-rec">no recruits</div>'}`;

      if (hasKids && lvl < 5) {
        card.addEventListener('click', () => selectAgent(lvl, kid));
      }
      strip.appendChild(card);

      // Scroll selected card into view
      if (isSelected) requestAnimationFrame(() => card.scrollIntoView({block:'nearest',inline:'center',behavior:'smooth'}));
    });

    section.appendChild(strip);
    wrap.appendChild(section);

    // Stop rendering further levels if nothing selected at this level
    if (!path[lvl-1]) break;
  }
}

function renderTree(tree, totalCount) {
  const wrap = document.getElementById('tree-wrap');
  wrap.innerHTML = '';

  if (!tree) {
    wrap.innerHTML = IS_LEADER
      ? '<div class="empty-prompt">No network data found.<br><span style="font-size:12px">Try a different agent email.</span></div>'
      : '<div class="empty-prompt">No network data on file yet.</div>';
    return;
  }

  ROOT = tree;
  path = [null, null, null, null, null];

  // Root agent card
  const vol       = fmtMoney(tree.volume);
  const rootDeals = tree.deals || 0;
  const root = document.createElement('div');
  root.className = 'root-card';
  root.innerHTML = `
    <div class="root-avatar">${esc(initials(tree.name))}</div>
    <div class="root-info">
      <div class="root-name">${esc(tree.name)}</div>
      <div class="root-email">${esc(tree.email||'')}</div>
      <div style="margin-top:4px;display:flex;align-items:baseline;gap:10px">
        <span style="font-size:16px;font-weight:800;color:${vol?'#2d7a00':'#ccc'}">${vol||'—'}</span>
        <span style="font-size:11px;color:#888"><b style="color:#555">${rootDeals}</b> deal${rootDeals===1?'':'s'}</span>
      </div>
    </div>
    <div class="root-chips">
      <span class="chip chip-rec">${totalCount} in network</span>
    </div>`;
  wrap.appendChild(root);

  // Line-count summary bar (1st–5th)
  const lineCounts = countByLevel(tree, 1);
  const bar = document.createElement('div');
  bar.className = 'line-summary';
  LINE_NAMES.slice(1).forEach((name, i) => {
    const n   = lineCounts[i];
    const pill = document.createElement('div');
    pill.className = 'line-pill' + (n === 0 ? ' zero' : '');
    pill.innerHTML = `<span class="lp-count">${n}</span><span class="lp-label">${name}</span>`;
    if (n > 0) {
      pill.title = `Jump to ${name}`;
      pill.addEventListener('click', () => {
        // Scroll to that level section in the levels-wrap
        const sections = document.querySelectorAll('.level-section');
        if (sections[i]) sections[i].scrollIntoView({behavior:'smooth',block:'start'});
      });
    }
    bar.appendChild(pill);
  });
  wrap.appendChild(bar);

  // Levels container
  const lvlWrap = document.createElement('div');
  lvlWrap.id = 'levels-wrap';
  wrap.appendChild(lvlWrap);

  renderLevels();
}

function loadTree() {
  const email = IS_LEADER
    ? (document.getElementById('email-input')?.value.trim() || MY_EMAIL)
    : MY_EMAIL;
  const wrap = document.getElementById('tree-wrap');
  wrap.innerHTML = '<div class="loading-msg">Loading network…</div>';

  fetch('api/network_tree.php?email=' + encodeURIComponent(email), {credentials:'same-origin'})
    .then(r => r.ok ? r.json() : Promise.reject(r.status))
    .then(d => {
      if (d.error) { wrap.innerHTML = '<div class="error-msg">' + esc(d.error) + '</div>'; return; }
      renderTree(d.tree, d.totalCount||0);
    })
    .catch(() => {
      wrap.innerHTML = '<div class="error-msg">Could not load network data. Check bridge configuration.</div>';
    });
}

// Always auto-load own tree; leaders can then type another email to look up others
loadTree();
document.getElementById('email-input')?.addEventListener('keydown', e => { if (e.key==='Enter') loadTree(); });
</script>
</body>
</html>
