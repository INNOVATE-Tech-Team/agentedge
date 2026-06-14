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

function render(rows) {
  const table = document.getElementById('roster-table');
  const empty = document.getElementById('roster-empty');
  const body = document.getElementById('roster-body');
  const count = document.getElementById('roster-count');
  count.textContent = `${rows.length} agent${rows.length === 1 ? '' : 's'}`;
  if (rows.length === 0) { table.hidden = true; empty.hidden = false; return; }
  empty.hidden = true; table.hidden = false;
  body.innerHTML = rows.map(a => `<tr>
    <td>${esc(a.name)}</td>
    <td>${esc(a.marketCenter) || '—'}</td>
    <td>${contactCell(a)}</td>
    <td class="soc-cell">${socialIcons(a.social)}</td>
  </tr>`).join('');
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
