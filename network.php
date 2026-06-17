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
    /* ── Admin search ───────────────────────────────────────────────────── */
    .search-row{display:flex;gap:0;max-width:420px;margin-bottom:20px}
    .search-row input{flex:1;padding:9px 12px;font-size:13px;border:1px solid #ccc;border-radius:6px 0 0 6px;outline:none}
    .search-row input:focus{border-color:#82C112}
    .search-row button{padding:9px 18px;border:none;background:#82C112;color:#000;font-size:13px;font-weight:700;border-radius:0 6px 6px 0;cursor:pointer}

    /* ── Root card ──────────────────────────────────────────────────────── */
    .root-card{display:flex;align-items:center;gap:14px;padding:14px 18px;background:#f9fdf5;border:2px solid #82C112;border-radius:10px}
    .root-avatar{width:46px;height:46px;border-radius:50%;background:#82C112;color:#000;font-size:15px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .root-info{flex:1;min-width:0}
    .root-name{font-size:16px;font-weight:800;color:#111}
    .root-email{font-size:12px;color:#666;margin-top:1px}
    .root-chips{display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end}

    /* ── Tier-1 filter ──────────────────────────────────────────────────── */
    .tier1-filter-wrap{margin:16px 0 8px;position:relative}
    .tier1-filter-wrap input{width:100%;box-sizing:border-box;padding:7px 12px 7px 32px;font-size:12px;border:1px solid #ddd;border-radius:6px;outline:none}
    .tier1-filter-wrap input:focus{border-color:#82C112}
    .tier1-filter-wrap::before{content:'🔍';position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:12px;pointer-events:none}

    /* ── Tier-1 horizontal strip ────────────────────────────────────────── */
    .tier1-section{margin:16px 0 0;border-top:2px solid #e6e7e8;padding-top:14px}
    .tier1-label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#aaa;margin-bottom:8px}
    .tier1-strip{display:flex;gap:8px;overflow-x:auto;padding-bottom:8px;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch}
    .tier1-strip::-webkit-scrollbar{height:4px}
    .tier1-strip::-webkit-scrollbar-track{background:#f5f5f5;border-radius:2px}
    .tier1-strip::-webkit-scrollbar-thumb{background:#ccc;border-radius:2px}

    /* Individual tier-1 card */
    .t1-card{flex-shrink:0;scroll-snap-align:start;display:flex;flex-direction:column;align-items:center;gap:5px;padding:10px 14px;background:white;border:1.5px solid #e6e7e8;border-radius:8px;cursor:pointer;transition:border-color 100ms,box-shadow 100ms;min-width:90px;max-width:110px;text-align:center}
    .t1-card:hover{border-color:#c3dfa8;background:#fafff5}
    .t1-card.selected{border-color:#82C112;background:#f9fdf5;box-shadow:0 2px 8px rgba(130,193,18,.2)}
    .t1-card.hidden{display:none}
    .t1-avatar{width:36px;height:36px;border-radius:50%;background:#e8f5d0;color:#5b8e0d;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center}
    .t1-card.selected .t1-avatar{background:#82C112;color:#000}
    .t1-name{font-size:11px;font-weight:700;color:#222;line-height:1.2;word-break:break-word}
    .t1-count{font-size:10px;padding:1px 6px;border-radius:8px;background:#fff4e0;color:#a06000;font-weight:700}
    .t1-nodl{font-size:10px;color:#ccc}

    /* ── Sub-tree panel ─────────────────────────────────────────────────── */
    .subtree-panel{margin-top:16px;border-top:2px solid #e6e7e8;padding-top:16px;min-height:60px}
    .subtree-label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#aaa;margin-bottom:10px}
    .no-recruits{padding:20px;text-align:center;font-size:13px;color:#aaa;border:1px dashed #ddd;border-radius:8px}

    /* ── Downline connectors ────────────────────────────────────────────── */
    .downline{position:relative;padding-left:36px}
    .downline::before{content:'';position:absolute;left:14px;top:0;bottom:20px;width:2px;background:linear-gradient(to bottom,#82C112,#c3dfa8);border-radius:1px}
    .dl-node{position:relative;margin-top:8px}
    .dl-node::before{content:'';position:absolute;left:-22px;top:19px;width:22px;height:2px;background:#c3dfa8}
    .dl-node::after{content:'';position:absolute;left:-23px;top:15px;width:8px;height:8px;border-radius:50%;background:#82C112;border:2px solid white;box-shadow:0 0 0 1px #82C112}
    .dl-node .downline::before{background:linear-gradient(to bottom,#b8d98a,#daefc0)}
    .dl-node .dl-node::after{background:#b8d98a;box-shadow:0 0 0 1px #b8d98a}
    .dl-node .dl-node .downline::before{background:#daefc0}
    .dl-node .dl-node .dl-node::after{background:#d5eab5;box-shadow:0 0 0 1px #d5eab5}

    /* Agent card */
    .agent-card{display:flex;align-items:center;gap:10px;padding:9px 13px;background:white;border:1px solid #e6e7e8;border-radius:7px;cursor:pointer;transition:border-color 100ms;user-select:none}
    .agent-card:hover{border-color:#c3dfa8}
    .agent-card.has-children{border-left:3px solid #c3dfa8}
    .agent-card.open{border-color:#82C112;border-left-color:#82C112}
    .agent-avatar{width:32px;height:32px;border-radius:50%;background:#e8f5d0;color:#5b8e0d;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .agent-info{flex:1;min-width:0}
    .agent-name{font-size:13px;font-weight:700;color:#111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .agent-email{font-size:11px;color:#888;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .agent-stats{display:flex;gap:4px;flex-shrink:0;align-items:center}
    .chip{font-size:10px;padding:2px 6px;border-radius:8px;font-weight:700;white-space:nowrap}
    .chip-vol{background:#f0f5e8;color:#5b8e0d}
    .chip-deals{background:#eef0ff;color:#4444cc}
    .chip-rec{background:#fff4e0;color:#a06000}
    .caret{font-size:10px;color:#aaa;margin-left:4px;transition:transform 150ms;flex-shrink:0}
    .open .caret{transform:rotate(90deg)}

    .loading-msg{padding:24px;text-align:center;color:#888;font-size:13px}
    .error-msg{padding:14px 18px;background:#fff0f0;border:1px solid #f5c6c6;border-radius:6px;color:#c00;font-size:13px;margin-top:12px}
    .net-summary{font-size:12px;color:#888;margin-top:10px}
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

        <div id="tree-wrap"></div>

      </div>
    </main>
  </div>
</div>
<script>
const MY_EMAIL  = <?= json_encode($agent['email']) ?>;
const IS_LEADER = <?= json_encode(is_leader()) ?>;

function esc(s) {
  return String(s||'').replace(/[&<>"]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
}
function initials(name) {
  const p = name.trim().split(/\s+/);
  return p.length >= 2 ? (p[0][0]+p[p.length-1][0]).toUpperCase() : p[0].slice(0,2).toUpperCase();
}
function fmtMoney(n) {
  if (!n) return null;
  if (n >= 1000000) return '$'+(n/1000000).toFixed(1).replace(/\.0$/,'')+' M';
  if (n >= 1000) return '$'+Math.round(n/1000)+'k';
  return '$'+Math.round(n);
}
function chips(node) {
  const vol = fmtMoney(node.volume);
  return [
    vol ? `<span class="chip chip-vol">${esc(vol)}</span>` : '',
    node.deals > 0 ? `<span class="chip chip-deals">${node.deals} deal${node.deals===1?'':'s'}</span>` : '',
    node.children?.length ? `<span class="chip chip-rec">${node.children.length} recruit${node.children.length===1?'':'s'}</span>` : '',
  ].join('');
}

// Build a subtree (level 2+) with connectors
function buildDownline(children) {
  const dl = document.createElement('div');
  dl.className = 'downline';
  children.forEach(child => {
    const node = document.createElement('div');
    node.className = 'dl-node';
    const hasKids = child.children?.length > 0;
    const card = document.createElement('div');
    card.className = 'agent-card' + (hasKids ? ' has-children' : '');
    card.innerHTML = `
      <div class="agent-avatar">${esc(initials(child.name))}</div>
      <div class="agent-info">
        <div class="agent-name">${esc(child.name)}</div>
        <div class="agent-email">${esc(child.email||'')}</div>
      </div>
      <div class="agent-stats">${chips(child)}${hasKids?'<span class="caret">▶</span>':''}</div>`;
    node.appendChild(card);
    if (hasKids) {
      const sub = buildDownline(child.children);
      sub.style.display = 'none';
      node.appendChild(sub);
      card.addEventListener('click', () => {
        const open = sub.style.display !== 'none';
        sub.style.display = open ? 'none' : '';
        card.classList.toggle('open', !open);
      });
    }
    dl.appendChild(node);
  });
  return dl;
}

// Select a tier-1 card and show their downline
let currentT1 = -1;
function selectTier1(idx, children, strip, panel) {
  currentT1 = idx;
  strip.querySelectorAll('.t1-card').forEach((c,i) => c.classList.toggle('selected', i === idx));

  panel.innerHTML = '';
  const child = children[idx];
  const label = document.createElement('div');
  label.className = 'subtree-label';
  label.textContent = child.name + "'s downline";
  panel.appendChild(label);

  if (child.children?.length) {
    panel.appendChild(buildDownline(child.children));
  } else {
    panel.innerHTML += '<div class="no-recruits">No recruits in ' + esc(child.name) + ''s downline yet.</div>';
  }
}

function renderTree(tree, totalCount) {
  const wrap = document.getElementById('tree-wrap');
  wrap.innerHTML = '';

  if (!tree) {
    wrap.innerHTML = '<div class="error-msg">No network data found for this agent.</div>';
    return;
  }

  const children = tree.children || [];

  // Root card
  const rootEl = document.createElement('div');
  rootEl.className = 'root-card';
  rootEl.innerHTML = `
    <div class="root-avatar">${esc(initials(tree.name))}</div>
    <div class="root-info">
      <div class="root-name">${esc(tree.name)}</div>
      <div class="root-email">${esc(tree.email||'')}</div>
    </div>
    <div class="root-chips">${chips(tree)}</div>`;
  wrap.appendChild(rootEl);

  if (!children.length) {
    wrap.innerHTML += '<div class="no-recruits" style="margin-top:16px">No recruits yet.</div>';
    return;
  }

  // Summary
  const summary = document.createElement('div');
  summary.className = 'net-summary';
  summary.textContent = `${children.length} direct recruit${children.length===1?'':'s'} · ${totalCount} total in downline`;
  wrap.appendChild(summary);

  // Tier-1 section
  const section = document.createElement('div');
  section.className = 'tier1-section';

  const topLabel = document.createElement('div');
  topLabel.className = 'tier1-label';
  topLabel.textContent = 'Direct Recruits';
  section.appendChild(topLabel);

  // Filter input (only if 6+ direct recruits)
  if (children.length >= 6) {
    const filterWrap = document.createElement('div');
    filterWrap.className = 'tier1-filter-wrap';
    const filterInput = document.createElement('input');
    filterInput.type = 'text';
    filterInput.placeholder = 'Filter recruits…';
    filterInput.addEventListener('input', () => {
      const q = filterInput.value.trim().toLowerCase();
      strip.querySelectorAll('.t1-card').forEach(card => {
        const name = card.dataset.name || '';
        card.classList.toggle('hidden', q !== '' && !name.includes(q));
      });
    });
    filterWrap.appendChild(filterInput);
    section.appendChild(filterWrap);
  }

  // Tier-1 strip
  const strip = document.createElement('div');
  strip.className = 'tier1-strip';

  children.forEach((child, i) => {
    const card = document.createElement('div');
    card.className = 't1-card';
    card.dataset.name = child.name.toLowerCase();
    const hasKids = child.children?.length > 0;
    card.innerHTML = `
      <div class="t1-avatar">${esc(initials(child.name))}</div>
      <div class="t1-name">${esc(child.name)}</div>
      ${hasKids ? `<div class="t1-count">${child.children.length} recruit${child.children.length===1?'':'s'}</div>` : '<div class="t1-nodl">—</div>'}`;
    card.addEventListener('click', () => selectTier1(i, children, strip, panel));
    strip.appendChild(card);
  });
  section.appendChild(strip);

  // Sub-tree panel
  const panel = document.createElement('div');
  panel.className = 'subtree-panel';
  panel.id = 'subtree-panel';
  section.appendChild(panel);

  wrap.appendChild(section);

  // Auto-select first recruit that has children, else just the first
  const firstWithKids = children.findIndex(c => c.children?.length > 0);
  selectTier1(firstWithKids >= 0 ? firstWithKids : 0, children, strip, panel);
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
      if (d.error) { wrap.innerHTML = '<div class="error-msg">'+esc(d.error)+'</div>'; return; }
      renderTree(d.tree, d.totalCount || 0);
    })
    .catch(() => {
      wrap.innerHTML = '<div class="error-msg">Could not load network data. Check bridge configuration.</div>';
    });
}

loadTree();
document.getElementById('email-input')?.addEventListener('keydown', e => { if (e.key==='Enter') loadTree(); });
</script>
</body>
</html>
