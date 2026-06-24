// Company Calendar — month grid with company / market-center / BIC scope filtering.

const CAL_MONTHS = ['January','February','March','April','May','June',
                    'July','August','September','October','November','December'];
const CAL_DAYS   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

let calYear   = new Date().getFullYear();
let calMonth  = new Date().getMonth();
let calFilter = 'all';
let agentMC   = '';
let agentMCSlug = '';
const evCache = {};

const SCOPES = {
  company:        { bg: '#82C112', text: '#111' },
  'market-center':{ bg: '#2C9CC9', text: '#fff' },
  bic:            { bg: '#A07221', text: '#fff' },
  dotloop:        { bg: '#7c3aed', text: '#fff' },
  personal:       { bg: '#e91e8c', text: '#fff' },
  training:       { bg: '#E87722', text: '#fff' },
};

function calEsc(s) {
  return (s == null ? '' : String(s)).replace(/[&<>"]/g,
    c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;' }[c]));
}

function slugify(s) {
  return s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
}

function calKey() {
  return `${calYear}-${String(calMonth + 1).padStart(2, '0')}`;
}

async function loadProfile() {
  try {
    const r = await fetch('api/profile.php', { credentials: 'same-origin' });
    const d = r.ok ? await r.json() : null;
    if (d?.profile?.marketCenter) {
      agentMC     = d.profile.marketCenter;
      agentMCSlug = slugify(agentMC);
      const badge = document.getElementById('cal-mc-badge');
      const tab   = document.getElementById('cal-tab-mc');
      if (badge) badge.textContent = agentMC;
      if (tab) tab.childNodes[0].textContent = agentMC + ' ';
    }
  } catch (e) { /* profile unavailable */ }
}

async function loadEvents(key) {
  if (evCache[key]) return evCache[key];
  const params = new URLSearchParams({ month: key });
  if (agentMCSlug) params.set('dept', agentMCSlug);

  const [companyRes, txRes, bicRes, trainingRes] = await Promise.allSettled([
    fetch('api/events.php?' + params, { credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : { events: [] })
      .catch(() => ({ events: [] })),
    fetch('api/dotloop_cal.php?month=' + encodeURIComponent(key), { credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : { events: [] })
      .catch(() => ({ events: [] })),
    fetch('api/bic_cal.php?month=' + encodeURIComponent(key), { credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : { events: [] })
      .catch(() => ({ events: [] })),
    fetch('api/training_cal.php?month=' + encodeURIComponent(key), { credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : { events: [] })
      .catch(() => ({ events: [] })),
  ]);

  const company  = companyRes.status  === 'fulfilled' ? (companyRes.value.events  ?? []) : [];
  const tx       = txRes.status       === 'fulfilled' ? (txRes.value.events       ?? []) : [];
  const bic      = bicRes.status      === 'fulfilled' ? (bicRes.value.events      ?? []) : [];
  const training = trainingRes.status === 'fulfilled' ? (trainingRes.value.events ?? []) : [];

  evCache[key] = [...company, ...tx, ...bic, ...training].sort((a, b) => a.date.localeCompare(b.date));
  return evCache[key];
}

function filtered(evs) {
  if (calFilter === 'all') return evs;
  if (calFilter === 'mc')  return evs.filter(e => e.scope === 'market-center');
  // 'dotloop' tab shows both dotloop events and personal (birthday, license renewal)
  if (calFilter === 'dotloop')  return evs.filter(e => e.scope === 'dotloop' || e.scope === 'personal');
  if (calFilter === 'training') return evs.filter(e => e.scope === 'training');
  return evs.filter(e => e.scope === calFilter);
}

function renderGrid(evs) {
  document.getElementById('cal-month-label').textContent =
    `${CAL_MONTHS[calMonth]} ${calYear}`;

  const vis = filtered(evs);
  const byDay = {};
  vis.forEach(ev => {
    const d = parseInt(ev.date.split('-')[2], 10);
    (byDay[d] = byDay[d] || []).push(ev);
  });

  const today      = new Date();
  const isCurMonth = today.getFullYear() === calYear && today.getMonth() === calMonth;
  const firstDay   = new Date(calYear, calMonth, 1).getDay();
  const daysInMo   = new Date(calYear, calMonth + 1, 0).getDate();

  let html = '<div class="cal-day-names">' +
    CAL_DAYS.map(d => `<div class="cal-day-name">${d}</div>`).join('') +
    '</div><div class="cal-cells">';

  for (let i = 0; i < firstDay; i++) html += '<div class="cal-cell cal-cell-blank"></div>';

  for (let d = 1; d <= daysInMo; d++) {
    const isToday = isCurMonth && today.getDate() === d;
    const dayEvs  = byDay[d] || [];
    const sc      = e => SCOPES[e.scope] || SCOPES.company;
    html += `<div class="cal-cell${isToday ? ' cal-today' : ''}">
      <div class="cal-cell-num">${d}</div>
      ${dayEvs.slice(0, 2).map(ev =>
        `<div class="cal-chip" style="background:${sc(ev).bg};color:${sc(ev).text}"
          title="${calEsc(ev.title)}">${calEsc(ev.title)}</div>`
      ).join('')}
      ${dayEvs.length > 2
        ? `<div class="cal-chip-more">+${dayEvs.length - 2} more</div>` : ''}
    </div>`;
  }
  html += '</div>';
  document.getElementById('cal-grid').innerHTML = html;
}

function scopeLabel(scope) {
  if (scope === 'market-center') return agentMC || 'Market Center';
  if (scope === 'bic')           return 'BIC';
  if (scope === 'dotloop')       return 'Transaction';
  if (scope === 'personal')      return 'Personal';
  if (scope === 'training')      return 'Training';
  return 'Company';
}

function renderList(evs) {
  document.getElementById('cal-list-title').textContent =
    `${CAL_MONTHS[calMonth]} ${calYear} Events`;
  const vis  = filtered(evs);
  const body = document.getElementById('cal-event-list-body');
  if (!vis.length) {
    body.innerHTML = '<p class="muted" style="padding:.75rem 0">No events this month.</p>';
    return;
  }
  const sc = e => SCOPES[e.scope] || SCOPES.company;
  body.innerHTML = vis.map(ev => `
    <div class="cal-list-ev">
      <div class="cal-list-ev-inner">
        <div class="cal-scope-bar" style="background:${sc(ev).bg}"></div>
        <div class="cal-list-ev-body">
          <div class="cal-list-date">${calFmtDate(ev.date)}</div>
          <div class="cal-list-ev-title">${calEsc(ev.title)}</div>
          ${ev.location    ? `<div class="cal-list-meta">&#128205; ${calEsc(ev.location)}</div>` : ''}
          ${ev.description ? `<div class="cal-list-desc">${calEsc(ev.description)}</div>` : ''}
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex:none">
          <span class="cal-scope-badge" style="background:${sc(ev).bg};color:${sc(ev).text}">
            ${scopeLabel(ev.scope)}
          </span>
          ${ev.scope === 'training' ? `<button class="cal-rsvp-btn${ev.rsvped ? ' cal-rsvp-active' : ''}"
            data-event-id="${calEsc(ev.gcal_id || '')}"
            data-event-title="${calEsc(ev.title)}"
            data-event-date="${calEsc(ev.date)}"
            data-rsvped="${ev.rsvped ? '1' : '0'}">${ev.rsvped ? 'Registered &#10003;' : 'Register'}</button>` : ''}
        </div>
      </div>
    </div>`).join('');
}

function updateTabCounts(evs) {
  const counts = { all: evs.length, company: 0, mc: 0, bic: 0, dotloop: 0, training: 0 };
  evs.forEach(e => {
    if      (e.scope === 'company')       counts.company++;
    else if (e.scope === 'market-center') counts.mc++;
    else if (e.scope === 'bic')           counts.bic++;
    else if (e.scope === 'dotloop' || e.scope === 'personal') counts.dotloop++;
    else if (e.scope === 'training')      counts.training++;
  });
  document.querySelectorAll('.cal-tab').forEach(t => {
    const n = counts[t.dataset.filter] ?? 0;
    t.querySelector('.cal-tab-count').textContent = n > 0 ? String(n) : '';
  });
}

function calFmtDate(iso) {
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString(undefined,
    { weekday: 'long', month: 'long', day: 'numeric' });
}

async function calDraw() {
  document.getElementById('cal-grid').innerHTML =
    '<div style="padding:2.5rem;text-align:center;color:#999">Loading…</div>';
  document.getElementById('cal-event-list-body').innerHTML = '';
  const evs = await loadEvents(calKey());
  renderGrid(evs);
  renderList(evs);
  updateTabCounts(evs);
}

document.querySelectorAll('.cal-tab').forEach(t => {
  t.addEventListener('click', () => {
    document.querySelectorAll('.cal-tab').forEach(x => x.classList.remove('cal-tab-active'));
    t.classList.add('cal-tab-active');
    calFilter = t.dataset.filter;
    loadEvents(calKey()).then(evs => { renderGrid(evs); renderList(evs); });
  });
});

document.getElementById('cal-prev').addEventListener('click', () => {
  calMonth--; if (calMonth < 0)  { calMonth = 11; calYear--; }
  delete evCache[calKey()]; calDraw();
});
document.getElementById('cal-next').addEventListener('click', () => {
  calMonth++; if (calMonth > 11) { calMonth = 0;  calYear++; }
  delete evCache[calKey()]; calDraw();
});

loadProfile().then(() => calDraw());

// RSVP toggle — delegated so it works after every renderList call
document.getElementById('cal-event-list-body').addEventListener('click', e => {
  const btn = e.target.closest('.cal-rsvp-btn');
  if (!btn) return;
  btn.disabled = true;
  fetch('api/training_rsvp.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      event_id:    btn.dataset.eventId,
      event_title: btn.dataset.eventTitle,
      event_date:  btn.dataset.eventDate,
    }),
  })
  .then(r => r.json())
  .then(d => {
    if (!d.ok) return;
    btn.dataset.rsvped = d.rsvped ? '1' : '0';
    btn.innerHTML = d.rsvped ? 'Registered &#10003;' : 'Register';
    btn.classList.toggle('cal-rsvp-active', d.rsvped);
    // Update the cached event so tab switches stay in sync
    const cached = evCache[calKey()] ?? [];
    const ev = cached.find(x => x.gcal_id === btn.dataset.eventId);
    if (ev) ev.rsvped = d.rsvped;
  })
  .catch(() => {})
  .finally(() => { btn.disabled = false; });
});
