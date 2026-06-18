// ── Get Support modal ─────────────────────────────────────────────────────────

let supportOverlay = null;

function openSupportModal() {
  if (!supportOverlay) {
    supportOverlay = document.createElement('div');
    supportOverlay.className = 'support-overlay';
    supportOverlay.innerHTML = `
      <div class="support-modal" role="dialog" aria-modal="true" aria-labelledby="support-title">
        <button class="support-close" onclick="closeSupportModal()" aria-label="Close">&times;</button>
        <h3 id="support-title">Get Support</h3>
        <p class="support-sub">Submit a request and the right team will respond in the ticket thread.</p>
        <div id="support-form-wrap">
          <div class="support-field">
            <label for="sup-title">Title</label>
            <input id="sup-title" type="text" placeholder="e.g., MLS access not working" maxlength="200">
          </div>
          <div class="support-field">
            <label for="sup-dept">Route to department</label>
            <select id="sup-dept"><option value="">— pick a department —</option></select>
          </div>
          <div class="support-field">
            <label for="sup-body">Describe the issue</label>
            <textarea id="sup-body" placeholder="What's happening? When did it start?" maxlength="4000"></textarea>
          </div>
          <div class="support-actions">
            <button class="support-submit" id="sup-submit" onclick="submitSupportTicket()">Submit ticket</button>
            <button class="support-cancel" onclick="closeSupportModal()">Cancel</button>
            <span class="support-msg" id="sup-msg"></span>
          </div>
        </div>
      </div>`;
    supportOverlay.addEventListener('click', e => { if (e.target === supportOverlay) closeSupportModal(); });
    document.body.appendChild(supportOverlay);
  }

  supportOverlay.classList.add('open');
  document.getElementById('sup-msg').textContent = '';
  document.getElementById('sup-title').value = '';
  document.getElementById('sup-body').value = '';
  document.getElementById('sup-dept').value = '';
  document.getElementById('sup-submit').disabled = false;

  // Load department list
  const sel = document.getElementById('sup-dept');
  if (sel.options.length <= 1) {
    fetch('api/support_departments.php', { credentials: 'same-origin' })
      .then(r => r.json())
      .then(d => {
        (d.departments || []).forEach(dept => {
          const opt = document.createElement('option');
          opt.value = dept.slug; opt.textContent = dept.name;
          sel.appendChild(opt);
        });
        if (sel.options.length <= 1) {
          sel.innerHTML = '<option value="">No departments configured yet</option>';
        }
      })
      .catch(() => {
        sel.innerHTML = '<option value="">Could not load departments</option>';
      });
  }
}

function closeSupportModal() {
  if (supportOverlay) supportOverlay.classList.remove('open');
}

function submitSupportTicket() {
  const title    = (document.getElementById('sup-title').value || '').trim();
  const deptSlug = document.getElementById('sup-dept').value;
  const body     = (document.getElementById('sup-body').value || '').trim();
  const msg      = document.getElementById('sup-msg');
  const btn      = document.getElementById('sup-submit');

  if (!title)    { msg.textContent = 'Please enter a title.';           msg.className = 'support-msg err'; return; }
  if (!deptSlug) { msg.textContent = 'Please select a department.';     msg.className = 'support-msg err'; return; }
  if (!body)     { msg.textContent = 'Please describe the issue.';      msg.className = 'support-msg err'; return; }

  btn.disabled = true;
  msg.textContent = 'Submitting…'; msg.className = 'support-msg ok';

  fetch('api/support_ticket.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ title, departmentSlug: deptSlug, body }),
  })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        msg.textContent = 'Ticket submitted! The team will follow up in the support portal.';
        msg.className = 'support-msg ok';
        setTimeout(closeSupportModal, 2500);
      } else {
        msg.textContent = d.error || 'Submit failed — please try again.';
        msg.className = 'support-msg err';
        btn.disabled = false;
      }
    })
    .catch(() => {
      msg.textContent = 'Network error — please try again.';
      msg.className = 'support-msg err';
      btn.disabled = false;
    });
}

// ── Masquerade stop ───────────────────────────────────────────────────────────

function stopMasquerade() {
  fetch('api/masquerade.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'stop' }),
  })
    .then(r => r.json())
    .then(d => { if (d.redirect) location.href = d.redirect; else location.reload(); })
    .catch(() => location.reload());
}

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
