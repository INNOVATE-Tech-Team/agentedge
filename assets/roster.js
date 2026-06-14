// Agent Roster — loads the directory and filters it live as you type.
let ALL = [];

function esc(s) { return (s == null ? '' : String(s)).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }

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
    <td>${a.email ? `<a href="mailto:${esc(a.email)}">${esc(a.email)}</a>` : '—'}</td>
    <td>${esc(a.role) || '—'}</td>
    <td>${esc(a.marketCenter) || '—'}</td>
  </tr>`).join('');
}

function applyFilter() {
  const q = document.getElementById('roster-search').value.trim().toLowerCase();
  if (!q) return render(ALL);
  render(ALL.filter(a => `${a.name} ${a.email} ${a.role} ${a.marketCenter}`.toLowerCase().includes(q)));
}

document.getElementById('roster-search').addEventListener('input', applyFilter);

fetch('api/roster.php', { credentials: 'same-origin' })
  .then(r => r.ok ? r.json() : Promise.reject(r.status))
  .then(d => { ALL = d.agents || []; render(ALL); })
  .catch(() => { document.getElementById('roster-count').textContent = 'Could not load the roster.'; });
