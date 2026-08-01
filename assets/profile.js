// My Profile — load the agent's record, let them edit contact + social links.
const SOCIAL_KEYS = ['facebook', 'instagram', 'linkedin', 'twitter', 'youtube', 'tiktok', 'website', 'blog'];
const EXTRA_KEYS  = ['birthday', 'hire_date', 'license_renewal'];
const FIELDS = ['fullName', 'email', 'phone', 'marketCenter', 'brokerage', ...SOCIAL_KEYS, 'googlePlaceId'];

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
  set('googlePlaceId', p.googlePlaceId);
  const optIn = document.getElementById('f-reviewRequestsOptIn');
  if (optIn) optIn.checked = !!p.reviewRequestsOptIn;
}

function showCandidate(c) {
  const banner = document.getElementById('gbp-candidate-banner');
  if (!c) { banner.hidden = true; return; }
  const details = document.getElementById('gbp-candidate-details');
  const reviewsTxt = c.reviews ? `${c.reviews} review${c.reviews === 1 ? '' : 's'}` : 'no reviews yet';
  const ratingTxt = c.rating ? `${c.rating}★` : '';
  details.textContent = `${c.name}${ratingTxt ? ' — ' + ratingTxt : ''} (${reviewsTxt}) at ${c.address}`;
  banner.hidden = false;
}

function candidateAction(action) {
  const msgEl = document.getElementById('gbp-candidate-msg');
  msgEl.textContent = 'Saving…';
  fetch('api/profile.php', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action }),
  }).then(r => r.json()).then(res => {
    if (res.ok) {
      document.getElementById('gbp-candidate-banner').hidden = true;
      if (action === 'confirm_candidate') location.reload(); // refresh Place ID + opt-in fields
    } else {
      msgEl.textContent = res.error || 'Something went wrong.';
    }
  }).catch(() => { msgEl.textContent = 'Network error — please try again.'; });
}

function confirmCandidate() { candidateAction('confirm_candidate'); }
function dismissCandidate() { candidateAction('dismiss_candidate'); }

// Load CRM profile + local extra fields in parallel
Promise.allSettled([
  fetch('api/profile.php', { credentials: 'same-origin' }).then(r => r.json()),
  fetch('api/agent_extra.php', { credentials: 'same-origin' }).then(r => r.json()),
]).then(([crmRes, extraRes]) => {
  const d = crmRes.status === 'fulfilled' ? crmRes.value : {};
  const x = extraRes.status === 'fulfilled' ? extraRes.value : {};

  if (d.profile) fill(d.profile);
  showCandidate(d.candidate);
  EXTRA_KEYS.forEach(k => { const el = document.getElementById('f-' + k); if (el) el.value = x[k] || ''; });

  const btn = document.getElementById('save-btn');
  if (d.editable) {
    btn.disabled = false;
    if (d.matched) note('');
  } else {
    FIELDS.forEach(k => { const el = document.getElementById('f-' + k); if (el) el.disabled = true; });
    btn.disabled = false; // extra fields are always editable locally
    note(d.reason || (d.demo ? 'Preview mode — CRM fields are read-only, but dates are saved locally.' : 'CRM editing is unavailable — dates are still saved locally.'));
  }
}).catch(() => note('Could not load your profile.'));

document.getElementById('profile-form').addEventListener('submit', e => {
  e.preventDefault();
  const btn = document.getElementById('save-btn');
  btn.disabled = true; msg('Saving…', true);

  const crmBody = {};
  FIELDS.forEach(k => { crmBody[k] = get(k); });
  crmBody.reviewRequestsOptIn = !!document.getElementById('f-reviewRequestsOptIn')?.checked;

  const extraBody = {};
  EXTRA_KEYS.forEach(k => { extraBody[k] = (document.getElementById('f-' + k)?.value || '').trim(); });

  Promise.allSettled([
    fetch('api/profile.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(crmBody),
    }).then(r => r.json()),
    fetch('api/agent_extra.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(extraBody),
    }).then(r => r.json()),
  ]).then(([crmRes, extraRes]) => {
    const crmOk   = crmRes.status   === 'fulfilled' && (crmRes.value.ok   || crmRes.value.demo);
    const extraOk = extraRes.status === 'fulfilled' && extraRes.value.ok;
    if (crmOk || extraOk) { msg('Saved ✓', true); }
    else {
      const err = (crmRes.status === 'fulfilled' ? crmRes.value.error : null)
               || (extraRes.status === 'fulfilled' ? extraRes.value.error : null)
               || 'Save failed.';
      msg(err, false);
    }
    btn.disabled = false;
  }).catch(() => { msg('Save failed — please try again.', false); btn.disabled = false; });
});
