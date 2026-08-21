// Shared "Languages Spoken" picker -- used by intake.php (onboarding),
// agent_profile.php and backoffice_agents.php (admin edit modals). Renders a
// checkbox grid of common languages + an "Other" free-text fallback, and
// keeps a hidden <input> in sync with a comma-joined string so every page's
// existing generic field get/set code (which just reads/writes `.value` on
// the id passed in) keeps working unchanged -- the checklist is purely a
// view onto that hidden input.
const LANGUAGE_OPTIONS = [
  'English', 'Spanish', 'French', 'German', 'Italian', 'Portuguese',
  'Mandarin', 'Cantonese', 'Vietnamese', 'Korean', 'Japanese', 'Tagalog',
  'Russian', 'Arabic', 'Hindi', 'Polish', 'Haitian Creole',
  'American Sign Language (ASL)',
];

function initLanguageChecklist(containerId, hiddenInputId) {
  const hidden = document.getElementById(hiddenInputId);
  const container = document.getElementById(containerId);
  if (!hidden || !container) return;

  const otherCbId = hiddenInputId + '-other-cb';
  const otherTxtId = hiddenInputId + '-other-txt';

  container.innerHTML = LANGUAGE_OPTIONS.map(l => `
    <label style="display:inline-flex;align-items:center;gap:4px;font-size:12px;margin:2px 12px 2px 0;cursor:pointer">
      <input type="checkbox" value="${l}" style="accent-color:#82C112"> ${l}
    </label>`).join('') + `
    <label style="display:inline-flex;align-items:center;gap:4px;font-size:12px;margin:2px 12px 2px 0;cursor:pointer">
      <input type="checkbox" id="${otherCbId}" style="accent-color:#82C112"> Other
    </label>
    <input type="text" id="${otherTxtId}" placeholder="Other language(s), comma-separated"
           style="display:none;margin-top:6px;padding:6px 9px;border:1px solid #ccc;border-radius:6px;font-size:12px;width:100%;box-sizing:border-box">`;

  const boxes = () => container.querySelectorAll('input[type=checkbox]:not(#' + otherCbId + ')');
  const otherCb = container.querySelector('#' + otherCbId);
  const otherTxt = container.querySelector('#' + otherTxtId);

  function sync() {
    const picked = [...boxes()].filter(cb => cb.checked).map(cb => cb.value);
    if (otherCb.checked && otherTxt.value.trim()) {
      otherTxt.value.split(',').forEach(v => { v = v.trim(); if (v) picked.push(v); });
    }
    hidden.value = picked.join(', ');
  }

  boxes().forEach(cb => cb.addEventListener('change', sync));
  otherCb.addEventListener('change', () => { otherTxt.style.display = otherCb.checked ? 'block' : 'none'; sync(); });
  otherTxt.addEventListener('input', sync);

  applyLanguageChecklist(containerId, hiddenInputId);
}

// Re-checks the boxes (and fills "Other") to match the hidden input's
// current comma-separated value -- call after setting hidden.value from
// freshly-loaded agent data (e.g. inside an edit modal's load callback).
function applyLanguageChecklist(containerId, hiddenInputId) {
  const hidden = document.getElementById(hiddenInputId);
  const container = document.getElementById(containerId);
  if (!hidden || !container) return;

  const otherCbId = hiddenInputId + '-other-cb';
  const otherTxtId = hiddenInputId + '-other-txt';
  const otherCb = container.querySelector('#' + otherCbId);
  const otherTxt = container.querySelector('#' + otherTxtId);
  if (!otherCb || !otherTxt) return;

  const current = (hidden.value || '').split(',').map(s => s.trim()).filter(Boolean);
  const leftovers = [];
  container.querySelectorAll('input[type=checkbox]:not(#' + otherCbId + ')').forEach(cb => {
    cb.checked = current.includes(cb.value);
  });
  current.forEach(v => { if (!LANGUAGE_OPTIONS.includes(v)) leftovers.push(v); });

  otherCb.checked = leftovers.length > 0;
  otherTxt.style.display = leftovers.length ? 'block' : 'none';
  otherTxt.value = leftovers.join(', ');
}
