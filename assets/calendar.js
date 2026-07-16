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
  personal:       { bg: '#e91e8c', text: '#fff' },
  training:       { bg: '#82C112', text: '#111' },
  events:         { bg: '#7c3aed', text: '#fff' },
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

  const [companyRes, trainingRes, eventsRes, personalRes] = await Promise.allSettled([
    fetch('api/events.php?' + params, { credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : { events: [] })
      .catch(() => ({ events: [] })),
    fetch('api/training_cal.php?month=' + encodeURIComponent(key), { credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : { events: [] })
      .catch(() => ({ events: [] })),
    fetch('api/events_cal.php?month=' + encodeURIComponent(key), { credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : { events: [] })
      .catch(() => ({ events: [] })),
    fetch('api/personal_cal.php?month=' + encodeURIComponent(key), { credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : { events: [], has_url: false })
      .catch(() => ({ events: [], has_url: false })),
  ]);

  const company  = companyRes.status  === 'fulfilled' ? (companyRes.value.events  ?? []) : [];
  const training = trainingRes.status === 'fulfilled' ? (trainingRes.value.events ?? []) : [];
  const events   = eventsRes.status   === 'fulfilled' ? (eventsRes.value.events   ?? []) : [];
  const personal = personalRes.status === 'fulfilled' ? (personalRes.value.events ?? []) : [];
  const hasPersonalUrl = personalRes.status === 'fulfilled' ? (personalRes.value.has_url ?? false) : false;

  // Update personal bar status text
  const personalStatus = document.getElementById('cal-personal-status');
  if (personalStatus) {
    if (!hasPersonalUrl) {
      personalStatus.textContent = 'No calendar synced yet.';
    } else if (personal.length) {
      personalStatus.textContent = personal.length + ' event' + (personal.length !== 1 ? 's' : '') + ' this month from your personal calendar.';
    } else {
      personalStatus.textContent = 'Calendar synced — no events this month.';
    }
  }

  const merged = [...company, ...training, ...events, ...personal].sort((a, b) => a.date.localeCompare(b.date));
  merged.forEach((ev, i) => { ev._uid = i; });
  evCache[key] = merged;
  return evCache[key];
}

function filtered(evs) {
  if (calFilter === 'all')      return evs;
  if (calFilter === 'mc')       return evs.filter(e => e.scope === 'market-center');
  if (calFilter === 'training') return evs.filter(e => e.scope === 'training');
  if (calFilter === 'events')   return evs.filter(e => e.scope === 'events');
  if (calFilter === 'mycal')    return evs.filter(e => e.scope === 'personal');
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
        `<div class="cal-chip" data-uid="${ev._uid}" style="background:${sc(ev).bg};color:${sc(ev).text}"
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
  if (scope === 'personal')      return 'Personal';
  if (scope === 'training')      return 'Training';
  if (scope === 'events')        return 'Events';
  return 'Company';
}

function calRsvpLabel(ev) {
  if (ev.rsvped) return 'Registered &#10003;';
  if (ev.waitlisted) return 'Waitlisted';
  if (ev.capacity != null && ev.registered_count >= ev.capacity) return 'Join Waitlist';
  return 'Register';
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
    <div class="cal-list-ev" data-uid="${ev._uid}">
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
          ${(ev.scope === 'training' || ev.scope === 'events') ? `
            ${ev.capacity != null ? `<span class="cal-reg-badge" style="font-size:11px;color:#888">${ev.registered_count}/${ev.capacity} registered</span>` : ''}
            <button class="cal-rsvp-btn${ev.rsvped ? ' cal-rsvp-active' : ''}${ev.waitlisted ? ' cal-rsvp-waitlisted' : ''}"
              data-scope="${ev.scope}"
              data-event-id="${calEsc(ev.gcal_id || '')}"
              data-event-title="${calEsc(ev.title)}"
              data-event-date="${calEsc(ev.date)}"
              data-rsvped="${ev.rsvped ? '1' : '0'}"
              data-waitlisted="${ev.waitlisted ? '1' : '0'}">${calRsvpLabel(ev)}</button>
            ${(typeof CAL_IS_ADMIN !== 'undefined' && CAL_IS_ADMIN)
              ? `<button class="cal-edit-btn" data-scope="${ev.scope}" data-event-id="${calEsc(ev.gcal_id || '')}">Edit</button>`
              : ''}` : ''}
        </div>
      </div>
    </div>`).join('');
}

function updateTabCounts(evs) {
  const counts = { all: evs.length, company: 0, mc: 0, training: 0, events: 0, mycal: 0 };
  evs.forEach(e => {
    if      (e.scope === 'company')        counts.company++;
    else if (e.scope === 'market-center')  counts.mc++;
    else if (e.scope === 'training')       counts.training++;
    else if (e.scope === 'events')         counts.events++;
    else if (e.scope === 'personal')       counts.mycal++;
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
  updateTrainingBar();
  updateEventsBar();
  updatePersonalBar();
  const evs = await loadEvents(calKey());
  renderGrid(evs);
  renderList(evs);
  updateTabCounts(evs);
}

function gcalAddUrl(ev) {
  let dates;
  if (ev.is_all_day) {
    const s = (ev.start_dt || ev.date).replace(/-/g, '');
    const endBase = ev.end_dt || ev.date;
    const endObj  = new Date(endBase + 'T00:00:00');
    endObj.setDate(endObj.getDate() + 1);
    const e = endObj.toISOString().slice(0, 10).replace(/-/g, '');
    dates = s + '/' + e;
  } else {
    const s = new Date(ev.start_dt).toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '');
    const e = new Date(ev.end_dt  ).toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '');
    dates = s + '/' + e;
  }
  return 'https://calendar.google.com/calendar/render?action=TEMPLATE'
    + '&text='     + encodeURIComponent(ev.title       || '')
    + '&dates='    + dates
    + '&details='  + encodeURIComponent(ev.description || '')
    + '&location=' + encodeURIComponent(ev.location    || '');
}

function updateTrainingBar() {
  const bar = document.getElementById('cal-training-bar');
  if (bar) bar.style.display = calFilter === 'training' ? 'flex' : 'none';
}

function updateEventsBar() {
  const bar = document.getElementById('cal-events-bar');
  if (bar) bar.style.display = calFilter === 'events' ? 'flex' : 'none';
}

function updateMyCalBar() {
  const bar = document.getElementById('cal-mycal-bar');
  if (bar) bar.style.display = calFilter === 'mycal' ? 'flex' : 'none';
}

function updatePersonalBar() {
  const bar = document.getElementById('cal-personal-bar');
  if (bar) bar.style.display = calFilter === 'personal' ? 'flex' : 'none';
}

document.querySelectorAll('.cal-tab').forEach(t => {
  t.addEventListener('click', () => {
    document.querySelectorAll('.cal-tab').forEach(x => x.classList.remove('cal-tab-active'));
    t.classList.add('cal-tab-active');
    calFilter = t.dataset.filter;
    updateTrainingBar();
    updateEventsBar();
    updatePersonalBar();
    updateMyCalBar();
    if (calFilter === 'mycal') loadCalFeedUrl();
    loadEvents(calKey()).then(evs => { renderGrid(evs); renderList(evs); });
  });
});

document.getElementById('cal-grid').addEventListener('click', e => {
  const chip = e.target.closest('.cal-chip');
  if (!chip) return;
  const item = document.querySelector(`.cal-list-ev[data-uid="${chip.dataset.uid}"]`);
  if (!item) return;
  item.scrollIntoView({ behavior: 'smooth', block: 'center' });
  item.classList.remove('cal-list-ev-flash');
  void item.offsetWidth; // restart animation if clicked again
  item.classList.add('cal-list-ev-flash');
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

// ── Admin: training event modal ───────────────────────────────────────────────
if (typeof CAL_IS_ADMIN !== 'undefined' && CAL_IS_ADMIN) {
  const overlay    = document.getElementById('cal-modal-overlay');
  const modalTitle = document.getElementById('cal-modal-title');
  const evId       = document.getElementById('cal-ev-id');
  const evTitle    = document.getElementById('cal-ev-title');
  const evDate     = document.getElementById('cal-ev-date');
  const evEndDate  = document.getElementById('cal-ev-end-date');
  const evStart    = document.getElementById('cal-ev-start-time');
  const evEnd      = document.getElementById('cal-ev-end-time');
  const evLoc      = document.getElementById('cal-ev-location');
  const evDesc     = document.getElementById('cal-ev-description');
  const evCapacity = document.getElementById('cal-ev-capacity');
  const attendeesBox = document.getElementById('cal-ev-attendees');
  const errBox     = document.getElementById('cal-modal-err');
  const deleteBtn  = document.getElementById('cal-ev-delete');

  async function loadAttendees(eventId) {
    attendeesBox.innerHTML = '<div style="font-size:12px;color:#888">Loading attendees…</div>';
    try {
      const r = await fetch('api/training_rsvp_list.php?event_id=' + encodeURIComponent(eventId), {credentials:'same-origin'});
      const d = await r.json();
      const rows = (d.attendees || []);
      if (!rows.length) { attendeesBox.innerHTML = '<div style="font-size:12px;color:#888">No RSVPs yet.</div>'; return; }
      attendeesBox.innerHTML = rows.map(a => {
        const tag = a.status === 'waitlisted'
          ? '<span style="font-size:10px;font-weight:700;color:#a06000;background:#fff4e0;padding:1px 6px;border-radius:8px;margin-left:6px">Waitlisted</span>'
          : '';
        return `<div style="font-size:12px;padding:3px 0;border-bottom:1px solid #f0f0f0">${calEsc(a.agent_name || a.agent_email)}${tag}`
          + `<span style="color:#999;margin-left:6px">${calEsc(a.agent_email)}</span></div>`;
      }).join('');
    } catch (e) {
      attendeesBox.innerHTML = '<div style="font-size:12px;color:#c00">Could not load attendees.</div>';
    }
  }

  function openModal(ev) {
    evId.value          = ev ? (ev.gcal_id || '') : '';
    evTitle.value       = ev ? ev.title       : '';
    evDate.value        = ev ? ev.date        : '';
    evEndDate.value     = ev ? (ev.end_dt !== ev.date ? (ev.end_dt || '') : '') : '';
    evLoc.value         = ev ? (ev.location    || '') : '';
    evDesc.value        = ev ? (ev.description || '') : '';
    evCapacity.value    = (ev && ev.capacity != null) ? ev.capacity : '';
    evStart.value       = '';
    evEnd.value         = '';
    if (!ev?.is_all_day && ev?.start_dt?.includes('T')) {
      evStart.value = ev.start_dt.slice(11, 16);
      evEnd.value   = (ev.end_dt || '').slice(11, 16) || '';
    }
    modalTitle.textContent = ev ? 'Edit Training Event' : 'Add Training Event';
    deleteBtn.style.display = ev ? 'block' : 'none';
    errBox.style.display    = 'none';
    overlay.style.display   = 'flex';
    if (ev && ev.gcal_id) {
      attendeesBox.parentElement.style.display = '';
      loadAttendees(ev.gcal_id);
    } else {
      attendeesBox.parentElement.style.display = 'none';
    }
    evTitle.focus();
  }

  function closeModal() { overlay.style.display = 'none'; }

  document.getElementById('cal-add-event-btn')?.addEventListener('click', () => openModal(null));
  document.getElementById('cal-modal-close')   .addEventListener('click', closeModal);
  document.getElementById('cal-modal-cancel')  .addEventListener('click', closeModal);
  overlay.addEventListener('click', e => { if (e.target === overlay) closeModal(); });

  // Edit button — delegated
  document.getElementById('cal-event-list-body').addEventListener('click', e => {
    const btn = e.target.closest('.cal-edit-btn');
    if (!btn || btn.dataset.scope !== 'training') return;
    const cached = evCache[calKey()] ?? [];
    const ev = cached.find(x => x.gcal_id === btn.dataset.eventId);
    if (ev) openModal(ev);
  });

  // Save
  document.getElementById('cal-ev-save').addEventListener('click', async () => {
    const id    = evId.value.trim();
    const title = evTitle.value.trim();
    const date  = evDate.value;
    if (!title || !date) { errBox.textContent = 'Title and start date are required.'; errBox.style.display = 'block'; return; }

    errBox.style.display = 'none';
    const saveBtn = document.getElementById('cal-ev-save');
    saveBtn.disabled = true; saveBtn.textContent = 'Saving…';

    const payload = {
      action: id ? 'update' : 'create',
      event_id:    id,
      title, date,
      end_date:    evEndDate.value,
      start_time:  evStart.value,
      end_time:    evEnd.value,
      location:    evLoc.value,
      description: evDesc.value,
      capacity:    evCapacity.value.trim(),
    };

    try {
      const r = await fetch('api/training_event_action.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const d = await r.json();
      if (!d.ok) throw new Error(d.error || 'Unknown error');
      closeModal();
      delete evCache[calKey()];
      calDraw();
    } catch (err) {
      errBox.textContent = err.message;
      errBox.style.display = 'block';
    } finally {
      saveBtn.disabled = false; saveBtn.textContent = 'Save Event';
    }
  });

  // Delete
  deleteBtn.addEventListener('click', async () => {
    const id = evId.value.trim();
    if (!id || !confirm('Delete this training event? This cannot be undone.')) return;
    deleteBtn.disabled = true;
    try {
      const r = await fetch('api/training_event_action.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', event_id: id }),
      });
      const d = await r.json();
      if (!d.ok) throw new Error('Delete failed');
      closeModal();
      delete evCache[calKey()];
      calDraw();
    } catch (err) {
      errBox.textContent = err.message;
      errBox.style.display = 'block';
      deleteBtn.disabled = false;
    }
  });
}

// ── Admin: company event modal ─────────────────────────────────────────────────
// Mirrors the training modal block above exactly, but targets event_action.php /
// event_rsvp_list.php — kept as a fully separate block rather than parameterizing
// the training block, so this addition can't regress the already-working Training flow.
if (typeof CAL_IS_ADMIN !== 'undefined' && CAL_IS_ADMIN) {
  const overlay2    = document.getElementById('cal-events-modal-overlay');
  const modalTitle2 = document.getElementById('cal-ev2-modal-title');
  const evId2       = document.getElementById('cal-ev2-id');
  const evTitle2    = document.getElementById('cal-ev2-title');
  const evDate2     = document.getElementById('cal-ev2-date');
  const evEndDate2  = document.getElementById('cal-ev2-end-date');
  const evStart2    = document.getElementById('cal-ev2-start-time');
  const evEnd2      = document.getElementById('cal-ev2-end-time');
  const evLoc2      = document.getElementById('cal-ev2-location');
  const evDesc2     = document.getElementById('cal-ev2-description');
  const evCapacity2 = document.getElementById('cal-ev2-capacity');
  const attendeesBox2 = document.getElementById('cal-ev2-attendees');
  const errBox2     = document.getElementById('cal-ev2-modal-err');
  const deleteBtn2  = document.getElementById('cal-ev2-delete');

  async function loadAttendees2(eventId) {
    attendeesBox2.innerHTML = '<div style="font-size:12px;color:#888">Loading attendees…</div>';
    try {
      const r = await fetch('api/event_rsvp_list.php?event_id=' + encodeURIComponent(eventId), {credentials:'same-origin'});
      const d = await r.json();
      const rows = (d.attendees || []);
      if (!rows.length) { attendeesBox2.innerHTML = '<div style="font-size:12px;color:#888">No RSVPs yet.</div>'; return; }
      attendeesBox2.innerHTML = rows.map(a => {
        const tag = a.status === 'waitlisted'
          ? '<span style="font-size:10px;font-weight:700;color:#a06000;background:#fff4e0;padding:1px 6px;border-radius:8px;margin-left:6px">Waitlisted</span>'
          : '';
        return `<div style="font-size:12px;padding:3px 0;border-bottom:1px solid #f0f0f0">${calEsc(a.agent_name || a.agent_email)}${tag}`
          + `<span style="color:#999;margin-left:6px">${calEsc(a.agent_email)}</span></div>`;
      }).join('');
    } catch (e) {
      attendeesBox2.innerHTML = '<div style="font-size:12px;color:#c00">Could not load attendees.</div>';
    }
  }

  function openModal2(ev) {
    evId2.value          = ev ? (ev.gcal_id || '') : '';
    evTitle2.value       = ev ? ev.title       : '';
    evDate2.value        = ev ? ev.date        : '';
    evEndDate2.value     = ev ? (ev.end_dt !== ev.date ? (ev.end_dt || '') : '') : '';
    evLoc2.value         = ev ? (ev.location    || '') : '';
    evDesc2.value        = ev ? (ev.description || '') : '';
    evCapacity2.value    = (ev && ev.capacity != null) ? ev.capacity : '';
    evStart2.value       = '';
    evEnd2.value         = '';
    if (!ev?.is_all_day && ev?.start_dt?.includes('T')) {
      evStart2.value = ev.start_dt.slice(11, 16);
      evEnd2.value   = (ev.end_dt || '').slice(11, 16) || '';
    }
    modalTitle2.textContent = ev ? 'Edit Event' : 'Add Event';
    deleteBtn2.style.display = ev ? 'block' : 'none';
    errBox2.style.display    = 'none';
    overlay2.style.display   = 'flex';
    if (ev && ev.gcal_id) {
      attendeesBox2.parentElement.style.display = '';
      loadAttendees2(ev.gcal_id);
    } else {
      attendeesBox2.parentElement.style.display = 'none';
    }
    evTitle2.focus();
  }

  function closeModal2() { overlay2.style.display = 'none'; }

  document.getElementById('cal-add-events-btn')?.addEventListener('click', () => openModal2(null));
  document.getElementById('cal-ev2-modal-close')  .addEventListener('click', closeModal2);
  document.getElementById('cal-ev2-modal-cancel') .addEventListener('click', closeModal2);
  overlay2.addEventListener('click', e => { if (e.target === overlay2) closeModal2(); });

  // Edit button — delegated
  document.getElementById('cal-event-list-body').addEventListener('click', e => {
    const btn = e.target.closest('.cal-edit-btn');
    if (!btn || btn.dataset.scope !== 'events') return;
    const cached = evCache[calKey()] ?? [];
    const ev = cached.find(x => x.gcal_id === btn.dataset.eventId);
    if (ev) openModal2(ev);
  });

  // Save
  document.getElementById('cal-ev2-save').addEventListener('click', async () => {
    const id    = evId2.value.trim();
    const title = evTitle2.value.trim();
    const date  = evDate2.value;
    if (!title || !date) { errBox2.textContent = 'Title and start date are required.'; errBox2.style.display = 'block'; return; }

    errBox2.style.display = 'none';
    const saveBtn = document.getElementById('cal-ev2-save');
    saveBtn.disabled = true; saveBtn.textContent = 'Saving…';

    const payload = {
      action: id ? 'update' : 'create',
      event_id:    id,
      title, date,
      end_date:    evEndDate2.value,
      start_time:  evStart2.value,
      end_time:    evEnd2.value,
      location:    evLoc2.value,
      description: evDesc2.value,
      capacity:    evCapacity2.value.trim(),
    };

    try {
      const r = await fetch('api/event_action.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const d = await r.json();
      if (!d.ok) throw new Error(d.error || 'Unknown error');
      closeModal2();
      delete evCache[calKey()];
      calDraw();
    } catch (err) {
      errBox2.textContent = err.message;
      errBox2.style.display = 'block';
    } finally {
      saveBtn.disabled = false; saveBtn.textContent = 'Save Event';
    }
  });

  // Delete
  deleteBtn2.addEventListener('click', async () => {
    const id = evId2.value.trim();
    if (!id || !confirm('Delete this event? This cannot be undone.')) return;
    deleteBtn2.disabled = true;
    try {
      const r = await fetch('api/event_action.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', event_id: id }),
      });
      const d = await r.json();
      if (!d.ok) throw new Error('Delete failed');
      closeModal2();
      delete evCache[calKey()];
      calDraw();
    } catch (err) {
      errBox2.textContent = err.message;
      errBox2.style.display = 'block';
      deleteBtn2.disabled = false;
    }
  });
}

// -- Outbound ICS feed --------------------------------------------------------

let calFeedLoaded = false;

async function loadCalFeedUrl(force) {
  if (calFeedLoaded && !force) return;
  const input = document.getElementById('cal-feed-url');
  if (!input) return;
  input.value = 'Loading...';
  try {
    const r = await fetch('api/cal_token.php', { credentials: 'same-origin' });
    const d = await r.json();
    if (d.ok && d.feed_url) { input.value = d.feed_url; calFeedLoaded = true; }
    else { input.value = ''; }
  } catch (e) { input.value = ''; }
}

document.getElementById('cal-feed-copy-btn')?.addEventListener('click', () => {
  const input = document.getElementById('cal-feed-url');
  const msg   = document.getElementById('cal-feed-msg');
  if (!input || !input.value || input.value === 'Loading...') return;
  if (navigator.clipboard) {
    navigator.clipboard.writeText(input.value).then(() => {
      if (msg) { msg.textContent = 'Copied!'; setTimeout(() => { if (msg) msg.textContent = ''; }, 2000); }
    });
  } else { input.select(); document.execCommand('copy'); }
});

document.getElementById('cal-feed-regen-btn')?.addEventListener('click', async () => {
  if (!confirm('Regenerate your calendar feed URL? The old URL will stop working.')) return;
  const msg = document.getElementById('cal-feed-msg');
  const btn = document.getElementById('cal-feed-regen-btn');
  if (btn) btn.disabled = true;
  if (msg) { msg.textContent = 'Regenerating...'; msg.style.color = '#888'; }
  try {
    const r = await fetch('api/cal_token.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'regenerate' }),
    });
    const d = await r.json();
    if (d.ok && d.feed_url) {
      const input = document.getElementById('cal-feed-url');
      if (input) input.value = d.feed_url;
      if (msg) { msg.textContent = 'URL regenerated. Re-subscribe in your calendar app.'; msg.style.color = '#c00'; }
    }
  } catch (e) {
    if (msg) { msg.textContent = 'Error regenerating.'; msg.style.color = '#c00'; }
  } finally { if (btn) btn.disabled = false; }
});

// RSVP toggle — delegated so it works after every renderList call
document.getElementById('cal-event-list-body').addEventListener('click', e => {
  const btn = e.target.closest('.cal-rsvp-btn');
  if (!btn) return;
  btn.disabled = true;
  const rsvpEndpoint = btn.dataset.scope === 'events' ? 'api/event_rsvp.php' : 'api/training_rsvp.php';
  fetch(rsvpEndpoint, {
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
    const wasRegistered = btn.dataset.rsvped === '1';

    // Update the cached event so tab switches and the count badge stay in sync.
    const cached = evCache[calKey()] ?? [];
    const ev = cached.find(x => x.gcal_id === btn.dataset.eventId);
    if (ev) {
      if (d.rsvped && !wasRegistered) ev.registered_count = (ev.registered_count || 0) + 1;
      if (!d.rsvped && wasRegistered) ev.registered_count = Math.max(0, (ev.registered_count || 0) - 1);
      ev.rsvped     = d.rsvped;
      ev.waitlisted = d.waitlisted;
    }

    btn.dataset.rsvped     = d.rsvped ? '1' : '0';
    btn.dataset.waitlisted = d.waitlisted ? '1' : '0';
    btn.innerHTML = ev ? calRsvpLabel(ev) : (d.rsvped ? 'Registered &#10003;' : 'Register');
    btn.classList.toggle('cal-rsvp-active', d.rsvped);
    btn.classList.toggle('cal-rsvp-waitlisted', d.waitlisted);

    const badge = btn.parentElement?.querySelector('.cal-reg-badge');
    if (badge && ev) badge.textContent = `${ev.registered_count}/${ev.capacity} registered`;

    // On confirmed registration (not waitlisted), open Google Calendar to add the event
    if (d.rsvped && ev) window.open(gcalAddUrl(ev), '_blank', 'noopener');
  })
  .catch(() => {})
  .finally(() => { btn.disabled = false; });
});
