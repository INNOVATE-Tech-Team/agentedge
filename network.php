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
    /* ── Search bar ─────────────────────────────────────────────────────── */
    .search-row{display:flex;gap:0;max-width:420px;margin-bottom:20px}
    .search-row input{flex:1;padding:9px 12px;font-size:13px;border:1px solid #ccc;border-radius:6px 0 0 6px;outline:none}
    .search-row input:focus{border-color:#82C112}
    .search-row button{padding:9px 18px;border:none;background:#82C112;color:#000;font-size:13px;font-weight:700;border-radius:0 6px 6px 0;cursor:pointer}

    /* ── Controls ───────────────────────────────────────────────────────── */
    .tree-controls{display:flex;align-items:center;gap:8px;margin-bottom:14px}
    .ctrl-btn{padding:5px 12px;border:1px solid #ddd;background:white;font-size:12px;border-radius:4px;cursor:pointer;color:#555}
    .ctrl-btn:hover{background:#f5f5f5}
    .net-count{font-size:12px;color:#888;margin-left:auto}

    /* ── Root node ──────────────────────────────────────────────────────── */
    .root-card{display:flex;align-items:center;gap:14px;padding:14px 18px;background:#f9fdf5;border:2px solid #82C112;border-radius:10px;margin-bottom:0}
    .root-avatar{width:46px;height:46px;border-radius:50%;background:#82C112;color:#000;font-size:16px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .root-info{flex:1;min-width:0}
    .root-name{font-size:16px;font-weight:800;color:#111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .root-email{font-size:12px;color:#666;margin-top:1px}
    .root-stats{display:flex;gap:6px;flex-shrink:0}

    /* ── Downline tree ──────────────────────────────────────────────────── */
    .downline{position:relative;padding-left:38px;margin-top:0}

    /* Vertical connector line running down the left */
    .downline::before{content:'';position:absolute;left:16px;top:0;bottom:20px;width:2px;background:linear-gradient(to bottom,#82C112 0%,#c3dfa8 100%);border-radius:1px}

    /* Each child node */
    .dl-node{position:relative;margin-top:8px}

    /* Horizontal connector from vertical line to card */
    .dl-node::before{content:'';position:absolute;left:-22px;top:19px;width:22px;height:2px;background:#c3dfa8}

    /* Dot at the junction */
    .dl-node::after{content:'';position:absolute;left:-23px;top:15px;width:8px;height:8px;border-radius:50%;background:#82C112;border:2px solid white;box-shadow:0 0 0 1px #82C112}

    /* Nested downlines get lighter connectors */
    .dl-node .downline::before{background:linear-gradient(to bottom,#b8d98a 0%,#daefc0 100%)}
    .dl-node .dl-node::after{background:#b8d98a;box-shadow:0 0 0 1px #b8d98a}
    .dl-node .dl-node .downline::before{background:#daefc0}
    .dl-node .dl-node .dl-node::after{background:#d5eab5;box-shadow:0 0 0 1px #d5eab5}

    /* Agent card */
    .agent-card{display:flex;align-items:center;gap:10px;padding:9px 13px;background:white;border:1px solid #e6e7e8;border-radius:7px;cursor:pointer;transition:border-color 100ms,box-shadow 100ms;user-select:none}
    .agent-card:hover{border-color:#c3dfa8;box-shadow:0 1px 4px rgba(130,193,18,.12)}
    .agent-card.has-children{border-left:3px solid #c3dfa8}
    .agent-card.open{border-color:#82C112;border-left-color:#82C112}

    /* Avatar */
    .agent-avatar{width:34px;height:34px;border-radius:50%;background:#e8f5d0;color:#5b8e0d;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}

    /* Info */
    .agent-info{flex:1;min-width:0}
    .agent-name{font-size:13px;font-weight:700;color:#111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .agent-email{font-size:11px;color:#888;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

    /* Stats chips */
    .agent-stats{display:flex;gap:5px;flex-shrink:0;align-items:center}
    .chip{font-size:10px;padding:2px 7px;border-radius:10px;font-weight:700;white-space:nowrap}
    .chip-vol{background:#f0f5e8;color:#5b8e0d}
    .chip-deals{background:#eef0ff;color:#4444cc}
    .chip-rec{background:#fff4e0;color:#a06000}

    /* Expand caret */
    .caret{font-size:10px;color:#aaa;margin-left:4px;transition:transform 150ms;flex-shrink:0}
    .open .caret{transform:rotate(90deg)}

    .loading-msg{padding:24px;text-align:center;color:#888;font-size:13px}
    .error-msg{padding:14px 18px;background:#fff0f0;border:1px solid #f5c6c6;border-radius:6px;color:#c00;font-size:13px;margin-top:12px}
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

        <div id="tree-controls" class="tree-controls" hidden>
          <button class="ctrl-btn" onclick="expandAll()">Expand all</button>
          <button class="ctrl-btn" onclick="collapseAll()">Collapse all</button>
          <span class="net-count" id="net-count"></span>
        </div>

        <div id="tree-wrap"></div>

      </div>
    </main>
  </div>
</div>
<script>
const MY_EMAIL  = <?= json_encode($agent['email']) ?>;
const IS_LEADER = <?= json_encode(is_leader()) ?>;

function esc(s) {
  return String(s || '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
}
function initials(name) {
  const p = name.trim().split(/\s+/);
  return p.length >= 2 ? (p[0][0] + p[p.length-1][0]).toUpperCase() : p[0].slice(0,2).toUpperCase();
}
function fmtMoney(n) {
  if (!n) return null;
  if (n >= 1000000) return '$' + (n/1000000).toFixed(1).replace(/\.0$/,'') + 'M';
  if (n >= 1000)    return '$' + Math.round(n/1000) + 'k';
  return '$' + Math.round(n);
}

function buildNodeEl(node, isRoot) {
  const hasKids  = node.children && node.children.length > 0;
  const vol      = fmtMoney(node.volume);
  const statsHtml = [
    vol                ? `<span class="chip chip-vol">${esc(vol)}</span>` : '',
    node.deals > 0     ? `<span class="chip chip-deals">${node.deals} deal${node.deals===1?'':'s'}</span>` : '',
    hasKids            ? `<span class="chip chip-rec">${node.children.length} recruit${node.children.length===1?'':'s'}</span>` : '',
  ].join('');

  if (isRoot) {
    const wrap = document.createElement('div');
    wrap.innerHTML = `
      <div class="root-card">
        <div class="root-avatar">${esc(initials(node.name))}</div>
        <div class="root-info">
          <div class="root-name">${esc(node.name)}</div>
          <div class="root-email">${esc(node.email || '')}</div>
        </div>
        <div class="root-stats">${statsHtml}</div>
      </div>`;

    if (hasKids) {
      const dl = buildDownline(node.children);
      wrap.appendChild(dl);
    }
    return wrap;
  }

  // Non-root node
  const el = document.createElement('div');
  el.className = 'dl-node';

  const card = document.createElement('div');
  card.className = 'agent-card' + (hasKids ? ' has-children' : '');
  card.innerHTML = `
    <div class="agent-avatar">${esc(initials(node.name))}</div>
    <div class="agent-info">
      <div class="agent-name">${esc(node.name)}</div>
      <div class="agent-email">${esc(node.email || '')}</div>
    </div>
    <div class="agent-stats">${statsHtml}${hasKids ? '<span class="caret">▶</span>' : ''}</div>`;
  el.appendChild(card);

  if (hasKids) {
    const dl = buildDownline(node.children);
    dl.style.display = 'none'; // collapsed by default
    el.appendChild(dl);

    card.addEventListener('click', () => {
      const open = dl.style.display !== 'none';
      dl.style.display = open ? 'none' : '';
      card.classList.toggle('open', !open);
    });
  }

  return el;
}

function buildDownline(children) {
  const dl = document.createElement('div');
  dl.className = 'downline';
  children.forEach(child => dl.appendChild(buildNodeEl(child, false)));
  return dl;
}

function renderTree(tree, totalCount) {
  const wrap = document.getElementById('tree-wrap');
  const ctrl = document.getElementById('tree-controls');
  const cnt  = document.getElementById('net-count');
  wrap.innerHTML = '';

  if (!tree) {
    wrap.innerHTML = '<div class="error-msg">No network data found for this agent.</div>';
    ctrl.hidden = true;
    return;
  }

  wrap.appendChild(buildNodeEl(tree, true));
  cnt.textContent = totalCount + ' agent' + (totalCount === 1 ? '' : 's') + ' in downline';
  ctrl.hidden = false;
}

function loadTree() {
  const email = IS_LEADER
    ? (document.getElementById('email-input')?.value.trim() || MY_EMAIL)
    : MY_EMAIL;

  const wrap = document.getElementById('tree-wrap');
  const ctrl = document.getElementById('tree-controls');
  wrap.innerHTML = '<div class="loading-msg">Loading network…</div>';
  ctrl.hidden = true;

  fetch('api/network_tree.php?email=' + encodeURIComponent(email), {credentials:'same-origin'})
    .then(r => r.ok ? r.json() : Promise.reject(r.status))
    .then(d => {
      if (d.error) { wrap.innerHTML = '<div class="error-msg">' + esc(d.error) + '</div>'; return; }
      renderTree(d.tree, d.totalCount || 0);
    })
    .catch(() => {
      wrap.innerHTML = '<div class="error-msg">Could not load network data. Check bridge configuration.</div>';
    });
}

function expandAll() {
  document.querySelectorAll('#tree-wrap .downline').forEach(d => d.style.display = '');
  document.querySelectorAll('#tree-wrap .agent-card.has-children').forEach(c => c.classList.add('open'));
}
function collapseAll() {
  document.querySelectorAll('#tree-wrap .dl-node .downline').forEach(d => d.style.display = 'none');
  document.querySelectorAll('#tree-wrap .agent-card').forEach(c => c.classList.remove('open'));
}

loadTree();
document.getElementById('email-input')?.addEventListener('keydown', e => { if (e.key === 'Enter') loadTree(); });
</script>
</body>
</html>
