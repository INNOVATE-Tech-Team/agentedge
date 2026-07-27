// AgentEdge dashboard — pulls the signed-in agent's numbers from
// api/summary.php (Perfex RE module, overridden by Darwin sync numbers when
// available) and paints the tiles, production card, and recruiting network.

const usdShort = (n) => {
  n = Number(n) || 0;
  if (n >= 1e6) return '$' + (n / 1e6).toFixed(1) + 'M';
  if (n >= 1e3) return '$' + Math.round(n / 1e3) + 'K';
  return '$' + Math.round(n);
};

// Small helper so a missing element (stale cache mid-deploy, or a card
// that's conditionally absent for this agent) never throws and trips the
// "Could not load your data" catch handler below for unrelated reasons.
function setText(id, text) {
  const el = document.getElementById(id);
  if (el) el.textContent = text;
}

function renderProduction(production) {
  // production is null until this agent is matched in Darwin — show a note
  // instead of made-up numbers (same "not connected yet" treatment the cap
  // wheel gives before Darwin linked up).
  const volume = production ? Number(production.volume) || 0 : 0;
  const deals  = production ? Number(production.deals)  || 0 : 0;
  const avg    = deals > 0 ? volume / deals : 0;
  setText('prod-volume', production ? usdShort(volume) : '—');
  setText('prod-deals', production ? deals : '—');
  setText('prod-avg', production && avg > 0 ? usdShort(avg) : '—');
  if (!production) {
    setText('prod-rank', 'Production data connects with Darwin (AccountTECH).');
  } else if (production.rank && production.totalAgents) {
    setText('prod-rank', `#${production.rank} of ${production.totalAgents} agents company-wide by YTD volume`);
  } else {
    setText('prod-rank', '');
  }
}

let capChart = null;
function renderCap(cap) {
  // Card is absent entirely for team members (index.php renders it
  // conditionally) — nothing to do.
  const canvas = document.getElementById('capWheel');
  if (!canvas) return;
  // cap is null until Darwin is connected — show an empty wheel + note.
  const amount = cap ? Number(cap.amount) || 0 : 0;
  const paid = cap ? Number(cap.paid) || 0 : 0;
  const remaining = Math.max(0, amount - paid);
  const pct = amount > 0 ? Math.round((paid / amount) * 100) : 0;
  setText('cap-pct', pct + '%');
  setText('cap-amount', cap ? usdShort(amount) : '—');
  setText('cap-paid', cap ? usdShort(paid) : '—');
  setText('cap-remaining', cap ? usdShort(remaining) : '—');
  setText('cap-note', cap ? '' : 'Cap data connects with Darwin (AccountTECH).');
  if (typeof Chart === 'undefined') return;
  if (capChart) capChart.destroy();
  capChart = new Chart(canvas, {
    type: 'doughnut',
    data: { datasets: [{ data: cap ? [paid, remaining] : [0, 1], backgroundColor: ['#82C112', '#e6e7e8'], borderWidth: 0 }] },
    options: { cutout: '74%', plugins: { legend: { display: false }, tooltip: { enabled: false } }, animation: { duration: 600 } },
  });
}

function renderNetwork(list) {
  const table = document.getElementById('network-table');
  const empty = document.getElementById('network-empty');
  const body = document.getElementById('network-body');
  if (!list || list.length === 0) { table.hidden = true; empty.hidden = false; return; }
  empty.hidden = true; table.hidden = false;
  body.innerHTML = list.map(r => `<tr>
    <td>${r.name}</td>
    <td class="num">${usdShort(r.volume)}</td>
    <td class="num">${r.deals || 0}</td></tr>`).join('');
}

fetch('api/summary.php', { credentials: 'same-origin' })
  .then(r => r.ok ? r.json() : Promise.reject(r.status))
  .then(d => {
    const banner = document.getElementById('sample-banner');
    if (!d.hasData && banner) { banner.textContent = "We couldn't find your agent record yet — totals will show once it's linked."; banner.hidden = false; }
    setText('t-volume', usdShort(d.tiles.volume));
    setText('t-closed', d.tiles.closedDeals ?? 0);
    setText('t-residual', usdShort(d.tiles.residual));
    setText('t-recruits', d.tiles.recruits ?? 0);
    setText('residual-amt', usdShort(d.tiles.residual));
    renderCap(d.cap);
    renderProduction(d.production);
    renderNetwork(d.network);
  })
  .catch(() => {
    const banner = document.getElementById('sample-banner');
    if (banner) { banner.textContent = 'Could not load your data — please try again.'; banner.hidden = false; }
  });
