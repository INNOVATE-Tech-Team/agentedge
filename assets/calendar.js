// Company Calendar — fetches org-wide events from api/events.php and renders a month grid.

const CAL_DAYS   = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const CAL_MONTHS = ['January','February','March','April','May','June',
                    'July','August','September','October','November','December'];

let calYear  = new Date().getFullYear();
let calMonth = new Date().getMonth(); // 0-indexed
const evCache = {};

function calEsc(s) {
  return (s == null ? '' : String(s)).replace(/[&<>"]/g,
    c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;' }[c]));
}

function calMonthKey() {
  return `${calYear}-${String(calMonth + 1).padStart(2, '0')}`;
}

async function calLoadEvents(key) {
  if (evCache[key] !== undefined) return evCache[key];
  try {
    const r = await fetch('api/events.php?month=' + encodeURIComponent(key), { credentials: 'same-origin' });
    const d = r.ok ? await r.json() : { events: [] };
    evCache[key] = Array.isArray(d.events) ? d.events : [];
  } catch { evCache[key] = []; }
  return evCache[key];
}

function calFmtDate(iso) {
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString(undefined,
    { weekday: 'long', month: 'long', day: 'numeric' });
}

function renderCalGrid(evs) {
  document.getElementById('cal-month-label').textContent =
    `${CAL_MONTHS[calMonth]} ${calYear}`;

  const byDay = {};
  evs.forEach(ev => {
    const d = parseInt(ev.date.split('-')[2], 10);
    (byDay[d] = byDay[d] || []).push(ev);
  });

  const today = new Date();
  const isCurMonth = today.getFullYear() === calYear && today.getMonth() === calMonth;
  const firstDay   = new Date(calYear, calMonth, 1).getDay();
  const daysInMo   = new Date(calYear, calMonth + 1, 0).getDate();

  let html = '<div class="cal-day-names">' +
    CAL_DAYS.map(d => `<div class="cal-day-name">${d}</div>`).join('') +
    '</div><div class="cal-cells">';

  for (let i = 0; i < firstDay; i++) html += '<div class="cal-cell cal-cell-blank"></div>';

  for (let d = 1; d <= daysInMo; d++) {
    const isToday  = isCurMonth && today.getDate() === d;
    const dayEvs   = byDay[d] || [];
    const todayCls = isToday ? ' cal-today' : '';
    html += `<div class="cal-cell${todayCls}">
      <div class="cal-cell-num">${d}</div>
      ${dayEvs.slice(0, 2).map(ev =>
        `<div class="cal-chip" title="${calEsc(ev.title)}">${calEsc(ev.title)}</div>`
      ).join('')}
      ${dayEvs.length > 2 ? `<div class="cal-chip-more">+${dayEvs.length - 2}</div>` : ''}
    </div>`;
  }
  html += '</div>';
  document.getElementById('cal-grid').innerHTML = html;
}

function renderCalList(evs) {
  document.getElementById('cal-list-title').textContent =
    `${CAL_MONTHS[calMonth]} ${calYear} Events`;
  const body = document.getElementById('cal-event-list-body');
  if (!evs.length) {
    body.innerHTML = '<p class="muted" style="padding:.5rem 0">No events this month.</p>';
    return;
  }
  body.innerHTML = evs.map(ev => `
    <div class="cal-list-ev">
      <div class="cal-list-date">${calFmtDate(ev.date)}</div>
      <div class="cal-list-ev-title">${calEsc(ev.title)}</div>
      ${ev.location    ? `<div class="cal-list-meta">&#128205; ${calEsc(ev.location)}</div>` : ''}
      ${ev.description ? `<div class="cal-list-desc">${calEsc(ev.description)}</div>` : ''}
    </div>`).join('');
}

async function calDraw() {
  document.getElementById('cal-grid').innerHTML =
    '<p style="padding:1.5rem;color:#888;text-align:center">Loading…</p>';
  document.getElementById('cal-event-list-body').innerHTML = '';
  const evs = await calLoadEvents(calMonthKey());
  renderCalGrid(evs);
  renderCalList(evs);
}

document.getElementById('cal-prev').addEventListener('click', () => {
  calMonth--; if (calMonth < 0) { calMonth = 11; calYear--; } calDraw();
});
document.getElementById('cal-next').addEventListener('click', () => {
  calMonth++; if (calMonth > 11) { calMonth = 0; calYear++; } calDraw();
});

calDraw();
