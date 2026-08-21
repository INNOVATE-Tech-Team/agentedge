// Shared "Languages Spoken" picker -- used by intake.php (onboarding),
// agent_profile.php and backoffice_agents.php (admin edit modals). Renders a
// click-to-add combobox: pick from the dropdown (or type to filter, or type
// something not on the list to add it as a custom chip) and each pick
// becomes a removable chip. Keeps a hidden <input> in sync with a
// comma-joined string so every page's existing generic field get/set code
// (which just reads/writes `.value` on the id passed in) keeps working
// unchanged -- the picker is purely a view onto that hidden input.
const LANGUAGE_OPTIONS = [
  'Afrikaans', 'Albanian', 'American Sign Language (ASL)', 'Amharic', 'Arabic',
  'Armenian', 'Bengali', 'Bosnian', 'Bulgarian', 'Burmese', 'Cantonese',
  'Cebuano', 'Croatian', 'Czech', 'Danish', 'Dutch', 'English', 'Estonian',
  'Finnish', 'French', 'German', 'Greek', 'Gujarati', 'Haitian Creole',
  'Hebrew', 'Hindi', 'Hmong', 'Hungarian', 'Icelandic', 'Igbo', 'Indonesian',
  'Italian', 'Japanese', 'Khmer', 'Korean', 'Kurdish', 'Lao', 'Latvian',
  'Lithuanian', 'Malay', 'Malayalam', 'Mandarin', 'Marathi', 'Mongolian',
  'Nepali', 'Norwegian', 'Persian (Farsi)', 'Polish', 'Portuguese', 'Punjabi',
  'Romanian', 'Russian', 'Serbian', 'Sinhala', 'Slovak', 'Slovenian',
  'Somali', 'Spanish', 'Swahili', 'Swedish', 'Tagalog', 'Tamil', 'Telugu',
  'Thai', 'Turkish', 'Ukrainian', 'Urdu', 'Vietnamese', 'Yoruba', 'Zulu',
].sort((a, b) => a.localeCompare(b));

function lpEsc(s) { return String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }

function initLanguageChecklist(containerId, hiddenInputId) {
  const hidden = document.getElementById(hiddenInputId);
  const container = document.getElementById(containerId);
  if (!hidden || !container) return;

  container.innerHTML = `
    <div class="lp-chips" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px"></div>
    <div style="position:relative">
      <input type="text" class="lp-input" placeholder="Click to add a language…" autocomplete="off"
             style="padding:7px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;width:100%;box-sizing:border-box">
      <div class="lp-dropdown" style="display:none;position:absolute;z-index:20;top:calc(100% + 2px);left:0;right:0;
           max-height:220px;overflow-y:auto;background:#fff;border:1px solid #ccc;border-radius:6px;
           box-shadow:0 4px 14px rgba(0,0,0,.14)"></div>
    </div>`;

  const chipsWrap = container.querySelector('.lp-chips');
  const input     = container.querySelector('.lp-input');
  const dropdown  = container.querySelector('.lp-dropdown');
  container._lpSelected = [];

  function sync() { hidden.value = container._lpSelected.join(', '); }

  function renderChips() {
    chipsWrap.innerHTML = container._lpSelected.map((lang, i) => `
      <span style="display:inline-flex;align-items:center;gap:5px;background:#eef5e8;color:#3a6b1a;
            font-size:12px;font-weight:600;padding:3px 6px 3px 10px;border-radius:14px">
        ${lpEsc(lang)}
        <button type="button" data-i="${i}" style="background:none;border:none;cursor:pointer;
                color:#5b8e0d;font-size:14px;line-height:1;padding:0 2px" aria-label="Remove ${lpEsc(lang)}">×</button>
      </span>`).join('');
    chipsWrap.querySelectorAll('button[data-i]').forEach(btn => {
      btn.addEventListener('click', () => {
        container._lpSelected.splice(Number(btn.dataset.i), 1);
        renderChips(); sync(); renderDropdown();
      });
    });
  }

  function addLanguage(lang) {
    lang = lang.trim();
    if (!lang) return;
    if (container._lpSelected.some(l => l.toLowerCase() === lang.toLowerCase())) { input.value = ''; return; }
    container._lpSelected.push(lang);
    renderChips(); sync();
    input.value = '';
    renderDropdown();
  }

  function renderDropdown() {
    const q = input.value.trim().toLowerCase();
    const taken = new Set(container._lpSelected.map(l => l.toLowerCase()));
    let opts = LANGUAGE_OPTIONS.filter(l => !taken.has(l.toLowerCase()));
    if (q) opts = opts.filter(l => l.toLowerCase().includes(q));
    const exactMatch = q && opts.some(l => l.toLowerCase() === q);

    let html = opts.map(l => `<div class="lp-opt" data-val="${lpEsc(l)}" style="padding:7px 10px;font-size:13px;cursor:pointer">${lpEsc(l)}</div>`).join('');
    if (q && !exactMatch) {
      html = `<div class="lp-opt" data-val="${lpEsc(input.value.trim())}"
                   style="padding:7px 10px;font-size:13px;cursor:pointer;color:#5b8e0d;font-weight:700;border-bottom:1px solid #eee">
                + Add "${lpEsc(input.value.trim())}"
              </div>` + html;
    }
    dropdown.innerHTML = html || '<div style="padding:7px 10px;font-size:12px;color:#999;font-style:italic">No matches</div>';
    dropdown.querySelectorAll('.lp-opt').forEach(opt => {
      opt.addEventListener('mouseenter', () => opt.style.background = '#f5f7f3');
      opt.addEventListener('mouseleave', () => opt.style.background = '');
      opt.addEventListener('mousedown', e => { e.preventDefault(); addLanguage(opt.dataset.val); input.focus(); });
    });
  }

  function openDropdown()  { renderDropdown(); dropdown.style.display = 'block'; }
  function closeDropdown() { dropdown.style.display = 'none'; }

  input.addEventListener('focus', openDropdown);
  input.addEventListener('input', openDropdown);
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
      e.preventDefault();
      const q = input.value.trim();
      if (!q) return;
      const taken = new Set(container._lpSelected.map(l => l.toLowerCase()));
      const canonicalMatch = LANGUAGE_OPTIONS.find(l => l.toLowerCase() === q.toLowerCase() && !taken.has(l.toLowerCase()));
      addLanguage(canonicalMatch || q);
    } else if (e.key === 'Backspace' && !input.value && container._lpSelected.length) {
      container._lpSelected.pop();
      renderChips(); sync(); renderDropdown();
    } else if (e.key === 'Escape') {
      closeDropdown();
    }
  });
  document.addEventListener('click', e => { if (!container.contains(e.target)) closeDropdown(); });

  // Exposed so applyLanguageChecklist() can re-render after swapping in a
  // different agent's data (e.g. backoffice_agents.php reusing this same
  // widget for whichever row's edit modal was just opened).
  container._lpRefresh = function () { renderChips(); sync(); renderDropdown(); };

  applyLanguageChecklist(containerId, hiddenInputId);
}

// Resets the picker's selected chips to match the hidden input's current
// comma-separated value -- call after setting hidden.value from freshly
// loaded agent data (e.g. inside an edit modal's load callback).
function applyLanguageChecklist(containerId, hiddenInputId) {
  const hidden = document.getElementById(hiddenInputId);
  const container = document.getElementById(containerId);
  if (!hidden || !container || !container._lpRefresh) return;

  const current = (hidden.value || '').split(',').map(s => s.trim()).filter(Boolean);
  const seen = new Set();
  container._lpSelected = current
    .map(v => LANGUAGE_OPTIONS.find(l => l.toLowerCase() === v.toLowerCase()) || v)
    .filter(v => {
      const k = v.toLowerCase();
      if (seen.has(k)) return false;
      seen.add(k); return true;
    });

  container._lpRefresh();
}
