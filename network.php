<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';

$agent = require_login();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>My Network — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    /* Admin search */
    .search-wrap{position:relative;max-width:420px;margin-bottom:20px}
    .search-row{display:flex;gap:0}
    .search-row input{flex:1;padding:9px 12px;font-size:13px;border:1px solid #ccc;border-radius:6px 0 0 6px;outline:none}
    .search-row input:focus{border-color:#82C112}
    .search-row button{padding:9px 18px;border:none;background:#82C112;color:#000;font-size:13px;font-weight:700;border-radius:0 6px 6px 0;cursor:pointer;white-space:nowrap}
    .search-dropdown{position:absolute;top:100%;left:0;right:0;background:white;border:1px solid #ccc;border-top:none;border-radius:0 0 6px 6px;box-shadow:0 4px 12px rgba(0,0,0,.1);z-index:100;max-height:280px;overflow-y:auto}
    .sd-item{display:flex;flex-direction:column;padding:9px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0}
    .sd-item:last-child{border-bottom:none}
    .sd-item:hover,.sd-item.active{background:#f9fdf5}
    .sd-name{font-size:13px;font-weight:700;color:#222}
    .sd-email{font-size:11px;color:#888}

    /* Root card */
    .root-card{display:flex;align-items:center;gap:14px;padding:14px 18px;background:#f9fdf5;border:2px solid #82C112;border-radius:10px;margin-bottom:20px}
    .root-avatar{width:44px;height:44px;border-radius:50%;background:#82C112;color:#000;font-size:14px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .root-card.vacant{background:#f3f3f3;border-color:#ccc;border-style:dashed}
    .root-avatar.vacant{background:#e8e8e8;color:#999}
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

    /* Flat "everyone at this line" grid (wraps instead of scrolling) */
    .agent-grid{display:flex;flex-wrap:wrap;gap:8px}
    .ag-sponsor{font-size:9px;color:#aaa;margin-top:-1px}
    .level-back-link{margin-left:auto;font-size:11px;font-weight:700;color:#5b8e0d;text-decoration:none;white-space:nowrap}
    .level-back-link:hover{text-decoration:underline}

    /* Agent card in strip */
    .ag-card{flex-shrink:0;scroll-snap-align:start;display:flex;flex-direction:column;align-items:center;gap:3px;padding:10px 10px 8px;background:white;border:1.5px solid #e6e7e8;border-radius:8px;cursor:pointer;min-width:108px;max-width:130px;text-align:center;transition:border-color 100ms,box-shadow 100ms;position:relative}
    .ag-card:hover{border-color:#c3dfa8;background:#fafff5}
    .ag-card.selected{border-color:#82C112;background:#f9fdf5;box-shadow:0 2px 8px rgba(130,193,18,.2)}
    .ag-card.no-kids{opacity:.7;cursor:default}
    .ag-card.vacant{border-style:dashed;border-color:#ccc;background:#fafafa}
    .ag-card.vacant.selected{border-color:#999;background:#f3f3f3}
    .ag-avatar{width:34px;height:34px;border-radius:50%;background:#e8f5d0;color:#5b8e0d;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .ag-avatar.vacant{background:#e8e8e8;color:#999}
    .ag-card.selected .ag-avatar{background:#82C112;color:#000}
    .ag-card.vacant.selected .ag-avatar{background:#ccc;color:#666}
    .ag-name{font-size:11px;font-weight:700;color:#222;line-height:1.2;word-break:break-word}
    .ag-name.vacant-label{color:#999;font-style:italic;font-weight:600}
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

    /* Sponsor card (one level above root) */
    .sponsor-card{display:flex;align-items:center;gap:12px;padding:10px 16px;background:#f5f5f5;border:1px solid #e0e0e0;border-radius:8px;margin-bottom:8px}
    .sponsor-avatar{width:34px;height:34px;border-radius:50%;background:#ddd;color:#555;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .sponsor-card.vacant{background:#f3f3f3;border-style:dashed}
    .sponsor-avatar.vacant{background:#e8e8e8;color:#999}
    .sponsor-info{flex:1;min-width:0}
    .sponsor-label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#999;margin-bottom:1px}
    .sponsor-name{font-size:13px;font-weight:700;color:#333}
    .sponsor-email{font-size:11px;color:#999}
    .sponsor-link{font-size:11px;font-weight:700;color:#5b8e0d;text-decoration:none;white-space:nowrap;flex-shrink:0}
    .sponsor-link:hover{text-decoration:underline}
    .sponsor-connector{text-align:center;font-size:14px;color:#ccc;line-height:1;margin-bottom:4px;margin-top:-4px}

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

        <?php if (can_search_network()): ?>
        <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:6px">View agent network</div>
        <div class="search-wrap">
          <div class="search-row">
            <input type="text" id="search-input" placeholder="Search agents by name…" autocomplete="off">
            <button onclick="loadTree()">Load</button>
          </div>
          <div id="search-dropdown" class="search-dropdown" hidden></div>
        </div>
        <?php endif; ?>

        <div id="tree-wrap">
          <div class="loading-msg">Loading network…</div>
        </div>

      </div>
    </main>
  </div>
</div>
<script>
const MY_EMAIL   = <?= json_encode($agent['email']) ?>;
const CAN_SEARCH = <?= json_encode(can_search_network()) ?>;
const LINE_NAMES = ['','1st Line','2nd Line','3rd Line','4th Line','5th Line'];

let selectedEmail = null; // set when user picks from dropdown or sponsor link

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

// Flatten all nodes at a given depth, paired with their direct sponsor
function nodesAtDepthWithParent(root, target) {
  const out = [];
  function walk(n, d, parent) {
    if (d === target) { out.push({node: n, parent}); return; }
    (n.children||[]).forEach(c => walk(c, d+1, n));
  }
  walk(root, 0, null);
  return out;
}

// The chain of ancestors (1st line .. target's own line) leading to `target`
function ancestorChainTo(root, target) {
  let found = null;
  function walk(n, chain) {
    if (found || n === target) { if (!found && n === target) found = chain; return; }
    (n.children||[]).forEach(c => walk(c, chain.concat([c])));
  }
  walk(root, []);
  return found || [];
}

// State: selected agent at each line level
let ROOT      = null;
let path      = [null, null, null, null, null]; // path[0]=selected 1st-line agent, etc.
let flatLevel = null; // when set (1-5), show every agent at that line instead of drilling one branch

function selectAgent(level, agent) {
  path[level-1] = agent;
  for (let i = level; i < 5; i++) path[i] = null;
  renderLevels();
  requestAnimationFrame(() => {
    const sections = document.querySelectorAll('#levels-wrap .level-section');
    if (sections[level]) sections[level].scrollIntoView({behavior:'smooth', block:'nearest'});
  });
}

// The "Nth Line" summary pills show every agent on that line across the whole
// network (not just one drilled-down branch) — no need to hunt through each
// line for someone with recruits first. Click the active pill again to return
// to the normal drill-down view.
function jumpToLevel(level) {
  flatLevel = (flatLevel === level) ? null : level;
  path = [null, null, null, null, null];
  renderLevels();
  updatePillStates();
  requestAnimationFrame(() => {
    const wrap = document.getElementById('levels-wrap');
    if (wrap) wrap.scrollIntoView({behavior:'smooth', block:'start'});
  });
}

function updatePillStates() {
  document.querySelectorAll('.line-pill').forEach(p => {
    p.classList.toggle('active', flatLevel !== null && Number(p.dataset.level) === flatLevel);
  });
}

// Jump from a card in the flat "all agents on this line" view into the normal
// drill-down, rooted along that agent's real ancestor chain.
function drillFromFlat(level, node) {
  const chain = ancestorChainTo(ROOT, node);
  flatLevel = null;
  path = [null, null, null, null, null];
  chain.forEach((n, i) => { path[i] = n; });
  renderLevels();
  updatePillStates();
  requestAnimationFrame(() => {
    const sections = document.querySelectorAll('#levels-wrap .level-section');
    if (sections[level]) sections[level].scrollIntoView({behavior:'smooth', block:'nearest'});
  });
}

// Build one agent card (shared by the drill-down strips and the flat line view)
function buildAgentCard(kid, opts) {
  opts = opts || {};
  const hasKids  = (kid.children||[]).length > 0;
  const isVacant = !!kid.vacant;
  const vol      = fmtMoney(kid.volume);
  const deals    = kid.deals || 0;

  const card = document.createElement('div');
  card.className = 'ag-card' + (opts.selected ? ' selected' : '') + (!opts.clickable ? ' no-kids' : '') + (isVacant ? ' vacant' : '');
  const sponsorLine = opts.sponsorName ? `<div class="ag-sponsor">under ${esc(opts.sponsorName)}</div>` : '';
  card.innerHTML = isVacant ? `
    <div class="ag-avatar vacant">${esc(initials(kid.name))}</div>
    <div class="ag-name vacant-label">${esc(kid.name)}</div>
    <div class="ag-deals">departed — recruits below</div>
    ${sponsorLine}
    <div class="ag-divider"></div>
    <div class="ag-count">${kid.children.length} recruit${kid.children.length===1?'':'s'}</div>` : `
    <div class="ag-avatar">${esc(initials(kid.name))}</div>
    <div class="ag-name">${esc(kid.name)}</div>
    <div class="ag-vol${vol ? '' : ' zero'}">${vol || '—'}</div>
    <div class="ag-deals"><span>${deals}</span> deal${deals===1?'':'s'}</div>
    ${sponsorLine}
    <div class="ag-divider"></div>
    ${hasKids ? `<div class="ag-count">${kid.children.length} recruit${kid.children.length===1?'':'s'}</div>` : '<div class="ag-no-rec">no recruits</div>'}`;

  if (opts.clickable && opts.onClick) card.addEventListener('click', opts.onClick);
  return card;
}

function renderFlatLevel(level) {
  const wrap = document.getElementById('levels-wrap');
  const entries = nodesAtDepthWithParent(ROOT, level);

  const section = document.createElement('div');
  section.className = 'level-section';

  const hdr = document.createElement('div');
  hdr.className = 'level-header';
  hdr.innerHTML = `
    <span class="level-badge badge-${level}">${LINE_NAMES[level]}</span>
    <span class="level-title">${esc(entries.length)} agent${entries.length===1?'':'s'} across the network</span>`;
  const backLink = document.createElement('a');
  backLink.href = '#';
  backLink.className = 'level-back-link';
  backLink.textContent = '← Back to drill-down view';
  backLink.addEventListener('click', e => { e.preventDefault(); jumpToLevel(level); });
  hdr.appendChild(backLink);
  section.appendChild(hdr);

  const grid = document.createElement('div');
  grid.className = 'agent-grid';
  entries.forEach(({node, parent}) => {
    const hasKids = (node.children||[]).length > 0;
    const card = buildAgentCard(node, {
      clickable: hasKids,
      onClick: () => drillFromFlat(level, node),
      sponsorName: parent ? parent.name : null
    });
    grid.appendChild(card);
  });
  section.appendChild(grid);
  wrap.appendChild(section);
}

function renderLevels() {
  const wrap = document.getElementById('levels-wrap');
  if (!wrap) return;
  wrap.innerHTML = '';

  if (flatLevel) { renderFlatLevel(flatLevel); return; }

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
      const isClickable = hasKids && lvl < 5;
      const isSelected = selectedAtThisLevel && selectedAtThisLevel === kid;

      const card = buildAgentCard(kid, {
        selected: isSelected,
        clickable: isClickable,
        onClick: () => selectAgent(lvl, kid)
      });
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

function renderTree(tree, totalCount, sponsor) {
  const wrap = document.getElementById('tree-wrap');
  wrap.innerHTML = '';

  if (!tree) {
    wrap.innerHTML = CAN_SEARCH
      ? '<div class="empty-prompt">No network data found.<br><span style="font-size:12px">Try a different agent email.</span></div>'
      : '<div class="empty-prompt">No network data on file yet.</div>';
    return;
  }

  ROOT = tree;
  path = [null, null, null, null, null];
  flatLevel = null;

  // Sponsor card — the agent one level above the root
  if (sponsor) {
    const sponsorEl = document.createElement('div');
    const sVol = fmtMoney(sponsor.volume);
    const viewLink = (CAN_SEARCH && !sponsor.vacant)
      ? `<a class="sponsor-link" href="#" onclick="event.preventDefault();searchByEmail('${esc(sponsor.email)}')">View network →</a>`
      : '';
    sponsorEl.className = 'sponsor-card' + (sponsor.vacant ? ' vacant' : '');
    sponsorEl.innerHTML = sponsor.vacant ? `
      <div class="sponsor-avatar vacant">${esc(initials(sponsor.name))}</div>
      <div class="sponsor-info">
        <div class="sponsor-label">↑ Sponsored by</div>
        <div class="sponsor-name vacant-label">${esc(sponsor.name)} — departed</div>
      </div>` : `
      <div class="sponsor-avatar">${esc(initials(sponsor.name))}</div>
      <div class="sponsor-info">
        <div class="sponsor-label">↑ Sponsored by</div>
        <div class="sponsor-name">${esc(sponsor.name)}</div>
        <div class="sponsor-email">${esc(sponsor.email||'')}${sVol ? ' · ' + sVol : ''}</div>
      </div>
      ${viewLink}`;
    wrap.appendChild(sponsorEl);
    const connector = document.createElement('div');
    connector.className = 'sponsor-connector';
    connector.textContent = '│';
    wrap.appendChild(connector);
  }

  // Root agent card
  const vol       = fmtMoney(tree.volume);
  const rootDeals = tree.deals || 0;
  const root = document.createElement('div');
  root.className = 'root-card' + (tree.vacant ? ' vacant' : '');
  root.innerHTML = tree.vacant ? `
    <div class="root-avatar vacant">${esc(initials(tree.name))}</div>
    <div class="root-info">
      <div class="root-name vacant-label">${esc(tree.name)} — departed</div>
      <div class="root-email">${esc(tree.email||'')}</div>
    </div>
    <div class="root-chips">
      <span class="chip chip-rec">${totalCount} in network</span>
    </div>` : `
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
    pill.dataset.level = i + 1;
    pill.innerHTML = `<span class="lp-count">${n}</span><span class="lp-label">${name}</span>`;
    if (n > 0) {
      pill.title = `See everyone on ${name}`;
      pill.addEventListener('click', () => jumpToLevel(i + 1));
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

function searchByEmail(email) {
  selectedEmail = email;
  closeDropdown();
  loadTree();
}

function loadTree() {
  const email = CAN_SEARCH ? (selectedEmail || MY_EMAIL) : MY_EMAIL;
  const wrap = document.getElementById('tree-wrap');
  wrap.innerHTML = '<div class="loading-msg">Loading network…</div>';

  fetch('api/network_tree.php?email=' + encodeURIComponent(email), {credentials:'same-origin'})
    .then(r => r.ok ? r.json() : Promise.reject(r.status))
    .then(d => {
      if (d.error) { wrap.innerHTML = '<div class="error-msg">' + esc(d.error) + '</div>'; return; }
      renderTree(d.tree, d.totalCount||0, d.sponsor||null);
    })
    .catch(() => {
      wrap.innerHTML = '<div class="error-msg">Could not load network data. Check bridge configuration.</div>';
    });
}

// ── Name search / typeahead ────────────────────────────────────────────────────
let _searchTimer = null;
let _dropActive  = -1;

function closeDropdown() {
  const dd = document.getElementById('search-dropdown');
  if (dd) dd.hidden = true;
  _dropActive = -1;
}

function renderDropdown(agents) {
  const dd = document.getElementById('search-dropdown');
  if (!dd) return;
  if (!agents.length) { dd.hidden = true; return; }
  dd.innerHTML = agents.map((a, i) =>
    `<div class="sd-item" data-email="${esc(a.email)}" data-name="${esc(a.name)}" data-idx="${i}">` +
    `<div class="sd-name">${esc(a.name)}</div>` +
    `<div class="sd-email">${esc(a.email)}</div></div>`
  ).join('');
  dd.hidden = false;
  _dropActive = -1;
  dd.querySelectorAll('.sd-item').forEach(el => {
    el.addEventListener('mousedown', e => {
      e.preventDefault();
      const inp = document.getElementById('search-input');
      if (inp) inp.value = el.dataset.name;
      selectedEmail = el.dataset.email;
      closeDropdown();
      loadTree();
    });
  });
}

function doSearch(q) {
  if (q.length < 2) { closeDropdown(); return; }
  selectedEmail = null;
  fetch('api/agent_search.php?q=' + encodeURIComponent(q), {credentials:'same-origin'})
    .then(r => r.json())
    .then(d => renderDropdown(d.agents || []))
    .catch(() => closeDropdown());
}

(function() {
  const inp = document.getElementById('search-input');
  if (!inp) return;

  inp.addEventListener('input', () => {
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(() => doSearch(inp.value.trim()), 250);
  });

  inp.addEventListener('keydown', e => {
    const dd = document.getElementById('search-dropdown');
    const items = dd ? [...dd.querySelectorAll('.sd-item')] : [];
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      _dropActive = Math.min(_dropActive + 1, items.length - 1);
      items.forEach((el, i) => el.classList.toggle('active', i === _dropActive));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      _dropActive = Math.max(_dropActive - 1, -1);
      items.forEach((el, i) => el.classList.toggle('active', i === _dropActive));
    } else if (e.key === 'Enter') {
      if (_dropActive >= 0 && items[_dropActive]) {
        e.preventDefault();
        items[_dropActive].dispatchEvent(new MouseEvent('mousedown'));
      } else {
        closeDropdown();
        loadTree();
      }
    } else if (e.key === 'Escape') {
      closeDropdown();
    }
  });

  inp.addEventListener('blur', () => setTimeout(closeDropdown, 150));
})();

document.addEventListener('click', e => {
  if (!e.target.closest('.search-wrap')) closeDropdown();
});

// Agent strips are horizontally scrollable, but a plain vertical mouse wheel
// doesn't scroll them on most desktop browsers, and there's no drag handle —
// so add wheel-to-horizontal and click-drag support (delegated since strips
// are re-created on every renderLevels() call).
document.addEventListener('wheel', e => {
  const strip = e.target.closest('.agent-strip');
  if (!strip || strip.scrollWidth <= strip.clientWidth) return;
  if (Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return; // let native horizontal wheel/trackpad through
  e.preventDefault();
  strip.scrollLeft += e.deltaY;
}, {passive: false});

let _dragStrip = null, _dragStartX = 0, _dragStartScroll = 0, _dragMoved = false;
document.addEventListener('mousedown', e => {
  const strip = e.target.closest('.agent-strip');
  // Short strips (common on levels 2-5, which often hold just a card or two)
  // have nothing to scroll — don't arm drag-tracking or its click-swallowing
  // for them, otherwise ordinary hand jitter during a click gets misread as
  // a drag and eats the click that was meant to select the card.
  if (!strip || strip.scrollWidth <= strip.clientWidth) return;
  _dragStrip = strip;
  _dragStartX = e.clientX;
  _dragStartScroll = strip.scrollLeft;
  _dragMoved = false;
});
document.addEventListener('mousemove', e => {
  if (!_dragStrip) return;
  const dx = e.clientX - _dragStartX;
  if (Math.abs(dx) > 6) _dragMoved = true;
  _dragStrip.scrollLeft = _dragStartScroll - dx;
});
document.addEventListener('mouseup', () => {
  if (_dragStrip && _dragMoved) {
    // Swallow the click that would otherwise fire on the card under the cursor.
    const strip = _dragStrip;
    const swallow = ev => { ev.stopPropagation(); strip.removeEventListener('click', swallow, true); };
    strip.addEventListener('click', swallow, true);
  }
  _dragStrip = null;
});

// Auto-load own tree on page load
loadTree();
</script>
</body>
</html>
