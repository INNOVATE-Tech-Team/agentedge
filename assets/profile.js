// My Profile — load the agent's record, let them edit contact + social links.
const SOCIAL_KEYS = ['facebook', 'instagram', 'linkedin', 'twitter', 'youtube', 'tiktok', 'website', 'blog'];
const FIELDS = ['fullName', 'email', 'phone', 'marketCenter', 'brokerage', ...SOCIAL_KEYS];

function set(id, v) { const el = document.getElementById('f-' + id); if (el) el.value = v || ''; }
function get(id) { const el = document.getElementById('f-' + id); return el ? el.value.trim() : ''; }

function note(text) {
  const n = document.getElementById('profile-note');
  if (!text) { n.hidden = true; return; }
  n.hidden = false; n.textContent = text;
}

function msg(text, ok) {
  const m = document.getElementById('form-msg');
  m.textContent = text; m.className = 'form-msg ' + (ok ? 'ok' : 'err');
}

function fill(p) {
  set('fullName', p.fullName); set('email', p.email); set('phone', p.phone);
  set('marketCenter', p.marketCenter); set('brokerage', p.brokerage);
  const s = p.social || {};
  SOCIAL_KEYS.forEach(k => set(k, s[k]));
}

fetch('api/profile.php', { credentials: 'same-origin' })
  .then(r => r.json())
  .then(d => {
    if (d.profile) fill(d.profile);
    const btn = document.getElementById('save-btn');
    if (d.editable) {
      btn.disabled = false;
      if (d.matched) note('');
    } else {
      // Read-only: lock every input and explain why.
      FIELDS.forEach(k => { const el = document.getElementById('f-' + k); if (el) el.disabled = true; });
      btn.disabled = true;
      note(d.reason || (d.demo ? 'Preview mode — you can see the form, but changes aren\'t saved until this runs on the production server.' : 'Editing is unavailable right now.'));
    }
  })
  .catch(() => note('Could not load your profile.'));

document.getElementById('profile-form').addEventListener('submit', e => {
  e.preventDefault();
  const btn = document.getElementById('save-btn');
  btn.disabled = true; msg('Saving…', true);
  const body = {};
  FIELDS.forEach(k => { body[k] = get(k); });
  fetch('api/profile.php', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
    .then(r => r.json())
    .then(d => {
      if (d.ok) { msg('Saved ✓', true); }
      else { msg(d.error || 'Save failed.', false); }
      btn.disabled = false;
    })
    .catch(() => { msg('Save failed — please try again.', false); btn.disabled = false; });
});
