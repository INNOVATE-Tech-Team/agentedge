<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/roles.php';
require __DIR__ . '/nav.php';

$agent = require_login();
$perms = current_perms();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>My Network — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    /* Search bar for admins */
    .agent-search-wrap{display:flex;gap:0;margin-bottom:6px;max-width:420px}
    .agent-search-wrap input{flex:1;padding:9px 12px;font-size:13px;border:1px solid #ccc;border-radius:6px 0 0 6px;outline:none}
    .agent-search-wrap input:focus{border-color:#82C112}
    .agent-search-wrap button{padding:9px 16px;border:none;background:#82C112;color:#000;font-size:13px;font-weight:700;border-radius:0 6px 6px 0;cursor:pointer}

    /* Tree chrome */
    .tree-root{font-size:13px;line-height:1.5}
    .tree-node{margin:0;padding:0}
    .tree-node-row{display:flex;align-items:flex-start;gap:0;padding:3px 0}
    .tree-toggle{width:20px;flex-shrink:0;text-align:center;cursor:pointer;color:#888;font-size:11px;line-height:22px;user-select:none}
    .tree-toggle:hover{color:#333}
    .tree-toggle.leaf{cursor:default;color:transparent}
    .tree-card{display:flex;align-items:center;gap:8px;padding:7px 12px;background:white;border:1px solid #e6e7e8;border-radius:6px;flex:1;min-width:0}
    .tree-card:hover{border-color:#c3dfa8;background:#f9fdf5}
    .tree-name{font-weight:700;font-size:13px;color:#111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px}
    .tree-email{font-size:11px;color:#888;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px}
    .tree-stats{display:flex;gap:6px;margin-left:auto;flex-shrink:0}
    .stat-chip{font-size:11px;padding:3px 7px;border-radius:3px;font-weight:700;white-space:nowrap}
    .stat-vol{background:#f0f5e8;color:#5b8e0d}
    .stat-deals{background:#f0f0ff;color:#4444cc}
    .stat-net{background:#fff5e8;color:#a06000}
    .tree-children{margin-left:20px;padding-left:12px;border-left:2px solid #e6e7e8}

    /* Root node style */
    .tree-node.root > .tree-node-row > .tree-card{border-color:#82C112;background:#f9fdf5;border-width:2px}
    .tree-node.root > .tree-node-row > .tree-card .tree-name{font-size:15px}

    /* Depth tints on left border */
    .depth-1 > .tree-children{border-color:#82C11250}
    .depth-2 > .tree-children{border-color:#82C11230}

    .net-count{font-size:12px;color:#666;margin-bottom:16px}
    .loading-msg{padding:24px;text-align:center;color:#888;font-size:13px}
    .error-msg{padding:16px;background:#fff0f0;border:1px solid #f5c6c6;border-radius:6px;color:#c00;font-size:13px}

    /* Expand/collapse all */
    .tree-controls{display:flex;gap:8px;margin-bottom:12px}
    .tree-ctrl-btn{padding:5px 12px;border:1px solid #ccc;background:white;font-size:12px;border-radius:4px;cursor:pointer;color:#555}
    .tree-ctrl-btn:hover{background:#f5f5f5}
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
        <div style="margin-bottom:20px">
          <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:6px">View agent network</div>
          <div class="agent-search-wrap">
            <input type="email" id="email-input" placeholder="agent@innovateonline.com"
              value="<?= htmlspecialchars($agent['email']) ?>">
            <button onclick="loadTree()">Load</button>
          </div>
        </div>
        <?php endif; ?>

        <div id="tree-controls" class="tree-controls" hidden>
          <button class="tree-ctrl-btn" onclick="expandAll()">Expand all</button>
          <button class="tree-ctrl-btn" onclick="collapseAll()">Collapse all</button>
        </div>

        <div id="net-count" class="net-count" hidden></div>
        <div id="tree-wrap" class="tree-root"></div>

      </div>
    </main>
  </div>
</div>
<script>
const MY_EMAIL   = <?= json_encode($agent['email']) ?>;
const IS_LEADER  = <?= json_encode(is_leader()) ?>;

function fmtMoney(n) {
  if (!n) return '$0';
  if (n >= 1000000) return '$' + (n / 1000000).toFixed(1) + 'M';
  if (n >= 1000)    return '$' + Math.round(n / 1000) + 'k';
  return '$' + Math.round(n);
}

function buildNodeEl(node, depth, isRoot) {
  const hasChildren = node.children && node.children.length > 0;

  const wrapper = document.createElement('div');
  wrapper.className = 'tree-node' + (isRoot ? ' root' : '') + ' depth-' + depth;

  const row = document.createElement('div');
  row.className = 'tree-node-row';

  // Toggle button
  const tog = document.createElement('span');
  tog.className = 'tree-toggle' + (hasChildren ? '' : ' leaf');
  tog.textContent = hasChildren ? '▼' : '·';
  row.appendChild(tog);

  // Card
  const card = document.createElement('div');
  card.className = 'tree-card';

  const nameWrap = document.createElement('div');
  nameWrap.style.minWidth = 0;
  nameWrap.innerHTML = `<div class="tree-name" title="${esc(node.name)}">${esc(node.name)}</div>
    <div class="tree-email">${esc(node.email || '')}</div>`;
  card.appendChild(nameWrap);

  const stats = document.createElement('div');
  stats.className = 'tree-stats';
  if (node.volume > 0) stats.innerHTML += `<span class="stat-chip stat-vol">${fmtMoney(node.volume)}</span>`;
  if (node.deals  > 0) stats.innerHTML += `<span class="stat-chip stat-deals">${node.deals} deal${node.deals===1?'':'s'}</span>`;
  if (hasChildren)     stats.innerHTML += `<span class="stat-chip stat-net">${node.children.length} recruit${node.children.length===1?'':'s'}</span>`;
  card.appendChild(stats);

  row.appendChild(card);
  wrapper.appendChild(row);

  // Children subtree
  if (hasChildren) {
    const childWrap = document.createElement('div');
    childWrap.className = 'tree-children';
    node.children.forEach(child => {
      childWrap.appendChild(buildNodeEl(child, depth + 1, false));
    });
    wrapper.appendChild(childWrap);

    tog.addEventListener('click', () => {
      const open = childWrap.style.display !== 'none';
      childWrap.style.display = open ? 'none' : '';
      tog.textContent = open ? '▶' : '▼';
    });
  }

  return wrapper;
}

function renderTree(tree, totalCount) {
  const wrap = document.getElementById('tree-wrap');
  const ctrl = document.getElementById('tree-controls');
  const cnt  = document.getElementById('net-count');
  wrap.innerHTML = '';
  if (!tree) {
    wrap.innerHTML = '<div class="error-msg">No network data found for this agent.</div>';
    ctrl.hidden = true; cnt.hidden = true;
    return;
  }
  wrap.appendChild(buildNodeEl(tree, 0, true));
  ctrl.hidden = false;
  cnt.hidden  = false;
  cnt.textContent = totalCount + ' agent' + (totalCount===1?'':'s') + ' in network';
}

function loadTree() {
  const email = IS_LEADER
    ? (document.getElementById('email-input')?.value.trim() || MY_EMAIL)
    : MY_EMAIL;

  const wrap = document.getElementById('tree-wrap');
  const ctrl = document.getElementById('tree-controls');
  const cnt  = document.getElementById('net-count');
  wrap.innerHTML = '<div class="loading-msg">Loading network…</div>';
  ctrl.hidden = true; cnt.hidden = true;

  fetch('api/network_tree.php?email=' + encodeURIComponent(email), {credentials:'same-origin'})
    .then(r => r.ok ? r.json() : Promise.reject(r.status))
    .then(d => {
      if (d.error) { wrap.innerHTML = '<div class="error-msg">' + esc(d.error) + '</div>'; return; }
      renderTree(d.tree, d.totalCount || 0);
    })
    .catch(() => { wrap.innerHTML = '<div class="error-msg">Could not load network data. Check that the bridge is configured.</div>'; });
}

function expandAll() {
  document.querySelectorAll('.tree-children').forEach(c => c.style.display = '');
  document.querySelectorAll('.tree-toggle:not(.leaf)').forEach(t => t.textContent = '▼');
}
function collapseAll() {
  document.querySelectorAll('#tree-wrap .tree-node:not(.root) .tree-children').forEach(c => c.style.display = 'none');
  document.querySelectorAll('#tree-wrap .tree-node:not(.root) .tree-toggle:not(.leaf)').forEach(t => t.textContent = '▶');
}

function esc(s) { return String(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

// Auto-load on page open
loadTree();

// Enter key in search
document.getElementById('email-input')?.addEventListener('keydown', e => { if (e.key==='Enter') loadTree(); });
</script>
</body>
</html>
