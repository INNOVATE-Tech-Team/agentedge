// AgentEdge dashboard — pulls the signed-in agent's numbers from
// api/summary.php (Perfex RE module) and paints the tiles, cap wheel,
// and recruiting network.

const usdShort = (n) => {
  n = Number(n) || 0;
  if (n >= 1e6) return '$' + (n / 1e6).toFixed(1) + 'M';
  if (n >= 1e3) return '$' + Math.round(n / 1e3) + 'K';
  return '$' + Math.round(n);
};

let capChart = null;
function renderCap(cap) {
  // cap is null until Darwin is connected — show an empty wheel + note.
  const amount = cap ? Number(cap.amount) || 0 : 0;
  const paid = cap ? Number(cap.paid) || 0 : 0;
  const remaining = Math.max(0, amount - paid);
  const pct = amount > 0 ? Math.round((paid / amount) * 100) : 0;
  document.getElementById('cap-pct').textContent = pct + '%';
  document.getElementById('cap-amount').textContent = cap ? usdShort(amount) : '—';
  document.getElementById('cap-paid').textContent = cap ? usdShort(paid) : '—';
  document.getElementById('cap-remaining').textContent = cap ? usdShort(remaining) : '—';
  document.getElementById('cap-note').textContent = cap ? '' : 'Cap data connects with Darwin (AccountTECH).';
  const ctx = document.getElementById('capWheel');
  if (capChart) capChart.destroy();
  capChart = new Chart(ctx, {
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
    if (!d.hasData) { banner.textContent = "We couldn't find your agent record yet — totals will show once it's linked."; banner.hidden = false; }
    document.getElementById('t-volume').textContent = usdShort(d.tiles.volume);
    document.getElementById('t-closed').textContent = d.tiles.closedDeals ?? 0;
    document.getElementById('t-residual').textContent = usdShort(d.tiles.residual);
    document.getElementById('t-recruits').textContent = d.tiles.recruits ?? 0;
    document.getElementById('residual-amt').textContent = usdShort(d.tiles.residual);
    renderCap(d.cap);
    renderNetwork(d.network);
  })
  .catch(() => {
    const banner = document.getElementById('sample-banner');
    banner.textContent = 'Could not load your data — please try again.';
    banner.hidden = false;
  });
