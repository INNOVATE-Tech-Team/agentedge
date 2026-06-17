// Agent Roster — loads the retention roster, filters live, and sorts on header click.
let ALL = [];
let VIEW = [];
let sortKey = 'name';
let sortDir = 1; // 1 asc, -1 desc

function esc(s) { return (s == null ? '' : String(s)).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }

// key -> [label, background color]
const SOCIALS = {
  facebook:  ['f',  '#1877F2'], instagram: ['IG', '#E1306C'],
  linkedin:  ['in', '#0A66C2'], twitter:   ['X',  '#111111'],
  youtube:   ['▶',  '#FF0000'], tiktok:    ['TT', '#111111'],
  website:   ['🌐', '#5b5b5b'], blog:      ['✎',  '#5b5b5b'],
};

function socialIcons(social) {
  if (!social) return '';
  return Object.keys(SOCIALS).filter(k => social[k]).map(k => {
    const [label, bg] = SOCIALS[k];
    return `<a class="soc" style="background:${bg}" target="_blank" rel="noopener"
      title="${k}" href="${esc(social[k])}">${label}</a>`;
  }).join('') || '<span class="muted">—</span>';
}

function contactCell(a) {
  const bits = [];
  if (a.email) bits.push(`<a href="mailto:${esc(a.email)}">${esc(a.email)}</a>`);
  if (a.phone) bits.push(`<a class="ph" href="tel:${esc(a.phone)}">${esc(a.phone)}</a>`);
  return bits.length ? bits.join('<br>') : '<span class="muted">—</span>';
}

function roleBadge(email) {
  const cur = (typeof ROLE_BY_EMAIL !== 'undefined') ? ROLE_BY_EMAIL[email.toLowerCase()] : null;
  if (!cur || cur.role === 'agent') return '';
  return `<span class="role-badge-sm role-${esc(cur.role)}">${esc((typeof ROLE_LABELS !== 'undefined' ? ROLE_LABELS[cur.role] : null) || cur.role)}</span> `;
}

function render(rows) {
  const table = document.getElementById('roster-table');
  const empty = document.getElementById('roster-empty');
  const body  = document.getElementById('roster-body');
  const count = document.getElementById('roster-count');
  count.textContent = `${rows.length} agent${rows.length === 1 ? '' : 's'}`;
  if (rows.length === 0) { table.hidden = true; empty.hidden = false; return; }
  empty.hidden = true; table.hidden = false;
  body.innerHTML = rows.map(a => `<tr>
    <td>${esc(a.name)}</td>
    <td>${esc(a.marketCenter) || '—'}</td>
    <td>${contactCell(a)}</td>
    <td class="soc-cell">${socialIcons(a.social)}</td>
    ${IS_ADMIN ? `<td style="white-space:nowrap">
      ${roleBadge(a.email)}
      <button class="btn-stats" data-email="${esc(a.email)}" data-name="${esc(a.name)}">Stats</button>
      ${(typeof IS_SUPER_ADMIN !== 'undefined' && IS_SUPER_ADMIN) ? `<button class="btn-assign" data-email="${esc(a.email)}" data-name="${esc(a.name)}" data-mc="${esc(a.marketCenter || '')}" style="margin-left:4px">Assign Role</button>` : ''}
    </td>` : ''}
  </tr>`).join('');

  if (IS_ADMIN) {
    body.querySelectorAll('.btn-stats').forEach(btn => {
      btn.addEventListener('click', () => showStats(btn.dataset.email, btn.dataset.name));
    });
    if (typeof IS_SUPER_ADMIN !== 'undefined' && IS_SUPER_ADMIN) {
      body.querySelectorAll('.btn-assign').forEach(btn => {
        btn.addEventListener('click', () => openRoleModal(btn.dataset.email, btn.dataset.name, btn.dataset.mc));
      });
    }
  }
}

function sortRows(rows) {
  return rows.slice().sort((a, b) => {
    const x = (a[sortKey] || '').toString().toLowerCase();
    const y = (b[sortKey] || '').toString().toLowerCase();
    return x < y ? -1 * sortDir : x > y ? 1 * sortDir : 0;
  });
}

function refresh() {
  const q = document.getElementById('roster-search').value.trim().toLowerCase();
  let rows = ALL;
  if (q) rows = rows.filter(a => `${a.name} ${a.marketCenter} ${a.email}`.toLowerCase().includes(q));
  VIEW = sortRows(rows);
  render(VIEW);
  document.querySelectorAll('#roster-table th[data-sort]').forEach(th => {
    th.classList.toggle('sorted', th.dataset.sort === sortKey);
    th.dataset.dir = th.dataset.sort === sortKey ? (sortDir === 1 ? 'asc' : 'desc') : '';
  });
}

document.getElementById('roster-search').addEventListener('input', refresh);

document.querySelectorAll('#roster-table th[data-sort]').forEach(th => {
  th.addEventListener('click', () => {
    const k = th.dataset.sort;
    if (k === sortKey) sortDir *= -1; else { sortKey = k; sortDir = 1; }
    refresh();
  });
});

fetch('api/roster.php', { credentials: 'same-origin' })
  .then(r => r.ok ? r.json() : Promise.reject(r.status))
  .then(d => { ALL = d.agents || []; refresh(); })
  .catch(() => { document.getElementById('roster-count').textContent = 'Could not load the roster.'; });

// ---- Stats modal -----------------------------------------------------------
let statsModal = null;

function ensureModal() {
  if (statsModal) return statsModal;
  statsModal = document.createElement('div');
  statsModal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:1000';
  statsModal.innerHTML = `
    <div style="background:#fff;border-radius:10px;width:min(520px,95vw);max-height:85vh;overflow-y:auto;padding:1.5rem;position:relative">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <strong id="stats-agent-name" style="font-size:1.1rem"></strong>
        <button id="stats-close" style="background:none;border:none;font-size:1.4rem;cursor:pointer;line-height:1">×</button>
      </div>
      <div id="stats-body"></div>
    </div>`;
  statsModal.addEventListener('click', e => { if (e.target === statsModal) closeStatsModal(); });
  statsModal.querySelector('#stats-close').addEventListener('click', closeStatsModal);
  document.body.appendChild(statsModal);
  return statsModal;
}

function closeStatsModal() {
  if (statsModal) statsModal.style.display = 'none';
}

function fmtMoney(n) {
  return '$' + Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
}

function renderStatsBody(d) {
  if (!d.hasData) return '<p class="muted" style="text-align:center;padding:1rem">No transaction data on file for this agent.</p>';
  const t = d.tiles;
  const tiles = [
    { val: fmtMoney(t.volume),   label: 'Sales Volume' },
    { val: t.closedDeals,        label: 'Closed Deals' },
    { val: fmtMoney(t.residual), label: 'Residual Income' },
    { val: t.recruits,           label: 'Recruits' },
  ];
  let html = `<div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:1.25rem">
    ${tiles.map(tile => `
      <div style="background:#f7f8fa;border-radius:8px;padding:.85rem 1rem;text-align:center">
        <div style="font-size:1.25rem;font-weight:700;color:#1a1a2e">${esc(String(tile.val))}</div>
        <div style="font-size:.75rem;color:#666;margin-top:.2rem">${tile.label}</div>
      </div>`).join('')}
  </div>`;
  if (d.network && d.network.length) {
    html += `<h4 style="margin:0 0 .5rem;font-size:.85rem;text-transform:uppercase;letter-spacing:.05em;color:#555">Network</h4>
    <table class="tx" style="width:100%"><thead><tr>
      <th>Agent</th><th>Volume</th><th>Deals</th>
    </tr></thead><tbody>
    ${d.network.map(n => `<tr>
      <td>${esc(n.name)}</td>
      <td>${fmtMoney(n.volume)}</td>
      <td>${n.deals}</td>
    </tr>`).join('')}
    </tbody></table>`;
  }
  return html;
}

function showStats(email, name) {
  const m = ensureModal();
  document.getElementById('stats-agent-name').textContent = name;
  document.getElementById('stats-body').innerHTML = '<p style="text-align:center;padding:1rem;color:#888">Loading…</p>';
  m.style.display = 'flex';

  fetch('api/agent_stats.php?email=' + encodeURIComponent(email), { credentials: 'same-origin' })
    .then(r => r.ok ? r.json() : Promise.reject(r.status))
    .then(d => { document.getElementById('stats-body').innerHTML = renderStatsBody(d); })
    .catch(() => { document.getElementById('stats-body').innerHTML = '<p style="text-align:center;color:#c00;padding:1rem">Could not load stats.</p>'; });
}
