// Onboarding — populate market centers + sponsor picker, then create the agent.
let SPONSORS = {}; // name -> id

function note(text) {
  const n = document.getElementById('onb-note');
  if (!text) { n.hidden = true; return; }
  n.hidden = false; n.textContent = text;
}
function msg(text, ok) {
  const m = document.getElementById('onb-msg');
  m.textContent = text; m.className = 'form-msg ' + (ok ? 'ok' : 'err');
}

fetch('api/onboard.php', { credentials: 'same-origin' })
  .then(r => r.json())
  .then(d => {
    const mc = document.getElementById('o-market_center_id');
    (d.marketCenters || []).forEach(m => {
      const o = document.createElement('option');
      o.value = m.id; o.textContent = m.name; mc.appendChild(o);
    });
    const dl = document.getElementById('sponsors');
    (d.agents || []).forEach(a => {
      if (!a.name) return;
      SPONSORS[a.name.toLowerCase()] = a.id;
      const o = document.createElement('option'); o.value = a.name; dl.appendChild(o);
    });
  })
  .catch(() => note('Could not load market centers / sponsors.'));

document.getElementById('onb-form').addEventListener('submit', e => {
  e.preventDefault();
  const btn = document.getElementById('onb-btn');
  const sponsorName = document.getElementById('o-sponsor').value.trim().toLowerCase();
  const body = {
    full_name:        document.getElementById('o-full_name').value.trim(),
    email:            document.getElementById('o-email').value.trim(),
    phone:            document.getElementById('o-phone').value.trim(),
    market_center_id: document.getElementById('o-market_center_id').value,
    role:             document.getElementById('o-role').value,
    sponsor_id:       SPONSORS[sponsorName] || null,
    start_date:       document.getElementById('o-start_date').value,
    notes:            document.getElementById('o-notes').value.trim(),
  };
  if (!body.full_name) { msg('Full name is required.', false); return; }
  btn.disabled = true; msg('Creating…', true);
  fetch('api/onboard.php', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
    .then(r => r.json())
    .then(d => {
      if (d.ok) { msg(`Created ${d.name || 'agent'} ✓`, true); document.getElementById('onb-form').reset(); }
      else { msg(d.error || 'Could not create the agent.', false); }
      btn.disabled = false;
    })
    .catch(() => { msg('Request failed — please try again.', false); btn.disabled = false; });
});
