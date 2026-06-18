// Loaded on every AgentEdge page via nav.php.
// Contains functions that must be available globally (support modal, masquerade, sidebar).

// ── Sidebar Links submenu ─────────────────────────────────────────────────────

function toggleSbLinks(btn) {
  const sub = btn.nextElementSibling;
  const open = btn.getAttribute('aria-expanded') === 'true';
  btn.setAttribute('aria-expanded', String(!open));
  sub.hidden = open;
  try { localStorage.setItem('ae_links_' + (btn.dataset.group || ''), String(!open)); } catch(e) {}
}

(function() {
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.sb-links-toggle').forEach(function(btn) {
      const sub = btn.nextElementSibling;
      if (!sub) return;
      try {
        if (localStorage.getItem('ae_links_' + (btn.dataset.group || '')) === 'false') {
          btn.setAttribute('aria-expanded', 'false');
          sub.hidden = true;
        }
      } catch(e) {}
    });
  });
})();

// ── Masquerade stop ───────────────────────────────────────────────────────────

function stopMasquerade() {
  fetch('api/masquerade.php', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'stop' }),
  })
    .then(r => r.json())
    .then(d => { location.href = d.redirect || 'roster.php'; })
    .catch(() => location.reload());
}

// ── Get Support modal ─────────────────────────────────────────────────────────

let _supportOverlay = null;

function openSupportModal() {
  if (!_supportOverlay) {
    _supportOverlay = document.createElement('div');
    _supportOverlay.className = 'support-overlay';
    _supportOverlay.innerHTML = `
      <div class="support-modal" role="dialog" aria-modal="true">
        <button class="support-close" onclick="closeSupportModal()" aria-label="Close">&times;</button>
        <h3>Get Support</h3>
        <p class="support-sub">Submit a request — the right team will respond in the ticket thread at everythinginnovate.com.</p>
        <div class="support-field">
          <label for="sup-title">Title</label>
          <input id="sup-title" type="text" placeholder="e.g., MLS access not working" maxlength="200">
        </div>
        <div class="support-field">
          <label for="sup-dept">Route to department</label>
          <select id="sup-dept"><option value="">— loading… —</option></select>
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
      </div>`;
    _supportOverlay.addEventListener('click', e => { if (e.target === _supportOverlay) closeSupportModal(); });
    document.body.appendChild(_supportOverlay);

    // Load departments once
    fetch('api/support_departments.php', { credentials: 'same-origin' })
      .then(r => r.json())
      .then(d => {
        const sel = document.getElementById('sup-dept');
        sel.innerHTML = '<option value="">— pick a department —</option>';
        (d.departments || []).forEach(dept => {
          const opt = document.createElement('option');
          opt.value = dept.slug; opt.textContent = dept.name;
          sel.appendChild(opt);
        });
        if (sel.options.length <= 1) sel.innerHTML = '<option value="">No departments configured yet</option>';
      })
      .catch(() => {
        const sel = document.getElementById('sup-dept');
        if (sel) sel.innerHTML = '<option value="">Could not load departments</option>';
      });
  }

  _supportOverlay.classList.add('open');
  const msg = document.getElementById('sup-msg');
  if (msg) msg.textContent = '';
  const ti = document.getElementById('sup-title'); if (ti) ti.value = '';
  const bo = document.getElementById('sup-body');  if (bo) bo.value = '';
  const sb = document.getElementById('sup-submit'); if (sb) sb.disabled = false;
}

function closeSupportModal() {
  if (_supportOverlay) _supportOverlay.classList.remove('open');
}

function submitSupportTicket() {
  const title    = (document.getElementById('sup-title')?.value || '').trim();
  const deptSlug = document.getElementById('sup-dept')?.value || '';
  const body     = (document.getElementById('sup-body')?.value  || '').trim();
  const msg      = document.getElementById('sup-msg');
  const btn      = document.getElementById('sup-submit');

  if (!title)    { msg.textContent = 'Please enter a title.';       msg.className = 'support-msg err'; return; }
  if (!deptSlug) { msg.textContent = 'Please select a department.'; msg.className = 'support-msg err'; return; }
  if (!body)     { msg.textContent = 'Please describe the issue.';  msg.className = 'support-msg err'; return; }

  btn.disabled = true;
  msg.textContent = 'Submitting…'; msg.className = 'support-msg ok';

  fetch('api/support_ticket.php', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ title, departmentSlug: deptSlug, body }),
  })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        msg.textContent = 'Ticket submitted! The team will follow up at everythinginnovate.com/tickets.';
        msg.className = 'support-msg ok';
        setTimeout(closeSupportModal, 3000);
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
