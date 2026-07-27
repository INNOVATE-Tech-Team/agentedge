<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
if (in_array(my_role(), ['agent', 'launch_agent'], true)) {
    header('Location: index.php'); exit;
}
$myName = htmlspecialchars($agent['name'] ?? $agent['email'], ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Email Signature — AgentEdge</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="stylesheet" href="assets/app.css">
<style>
.page-header{margin-bottom:24px}
.page-header h1{margin:0 0 4px;font-size:22px;font-weight:800}
.page-header p{margin:0;color:var(--faint);font-size:13px}

.sig-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:24px;max-width:660px}

/* Signature image upload */
.sig-img-section{margin-bottom:20px;padding-bottom:20px;border-bottom:1px solid var(--border)}
.sig-img-section h3{margin:0 0 4px;font-size:13px;font-weight:700}
.sig-img-section p{margin:0 0 10px;font-size:12px;color:var(--faint)}
.sig-img-drop{border:2px dashed var(--border);border-radius:8px;background:#fafafa;
              min-height:80px;display:flex;align-items:center;justify-content:center;
              cursor:pointer;transition:border-color .15s;overflow:hidden;position:relative}
.sig-img-drop:hover,.sig-img-drop.drag-over{border-color:var(--green);background:#f4fbea}
.sig-img-drop img{max-width:100%;max-height:240px;display:block;object-fit:contain}
.sig-img-placeholder{text-align:center;padding:22px;pointer-events:none}
.sig-img-placeholder span{display:block;font-size:28px;margin-bottom:6px}
.sig-img-placeholder em{font-size:12px;color:#bbb;font-style:normal}
.sig-img-actions{display:flex;align-items:center;gap:8px;margin-top:8px}
.btn-sig{padding:6px 14px;border:1px solid var(--border);border-radius:6px;
         background:#fff;font-size:12px;font-weight:700;cursor:pointer;color:var(--ink)}
.btn-sig:hover{border-color:var(--green);color:var(--green)}
.sig-uploading{font-size:12px;color:var(--faint);display:none}
.sig-img-note{font-size:11px;color:var(--green-d);font-weight:700;margin-top:6px;display:none}
.fields-dimmed{opacity:.4;pointer-events:none}

/* Fields */
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
.field{margin-bottom:14px}
.field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;
             letter-spacing:.06em;color:var(--faint);margin-bottom:5px}
.field input{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:7px;
             font-size:13px;box-sizing:border-box;font-family:inherit}
.field input:focus{outline:2px solid var(--green);border-color:var(--green)}

.custom-toggle{display:flex;align-items:center;gap:8px;margin:4px 0 16px;
               font-size:13px;color:var(--ink);cursor:pointer;user-select:none}
.custom-toggle input{accent-color:var(--green);width:16px;height:16px;cursor:pointer}

/* Rich text editor */
.rte-wrap{border:1px solid var(--border);border-radius:8px;background:#fff}
.rte-wrap:focus-within{outline:2px solid var(--green);border-color:var(--green)}
.rte-toolbar{display:flex;align-items:center;gap:2px;padding:7px 10px;background:#f7f7f7;
             border-bottom:1px solid #e0e0e0;flex-wrap:wrap;row-gap:6px;border-radius:7px 7px 0 0}
.rte-group{display:flex;align-items:center;gap:2px}
.rte-group+.rte-group{margin-left:6px;padding-left:8px;border-left:1px solid #dcdcdc}
.rte-btn{display:inline-flex;align-items:center;justify-content:center;padding:0;border:1px solid transparent;
         background:none;border-radius:5px;cursor:pointer;font-size:13px;font-weight:600;
         color:#333;line-height:1;width:30px;height:30px;flex-shrink:0}
.rte-btn:hover{background:#fff;border-color:#ddd}
.rte-btn:active{background:#eef5e8;border-color:#c7e2a3;color:var(--green-d)}
.rte-btn svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round}
.cdd{position:relative;flex-shrink:0}
.cdd-toggle{display:flex;align-items:center;gap:5px;height:30px;padding:0 9px;border:1px solid #ccc;
            border-radius:5px;background:#fff;font-size:12px;font-family:inherit;color:#333;cursor:pointer;white-space:nowrap}
.cdd-toggle:hover{border-color:#aaa}
.cdd.open .cdd-toggle{border-color:var(--green)}
.cdd-arrow{font-size:8px;color:#888;line-height:1}
.cdd-menu{display:none;position:absolute;top:calc(100% + 4px);left:0;background:#fff;border:1px solid #ccc;
          border-radius:6px;box-shadow:0 4px 14px rgba(0,0,0,.10);z-index:200;min-width:160px;padding:8px}
.cdd.open .cdd-menu{display:block}
.cdd-swatch-menu{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:8px}
.cdd-swatch{width:20px;height:20px;border:1px solid rgba(0,0,0,.15);border-radius:3px;cursor:pointer;padding:0}
.cdd-swatch:hover{transform:scale(1.2)}
.cdd-swatch-custom-row{display:flex;align-items:center;gap:6px;font-size:12px;color:#555}
.rte-body{min-height:90px;padding:12px;font-size:14px;line-height:1.6;outline:none;border-radius:0 0 7px 7px}
.rte-body:empty::before{content:attr(data-placeholder);color:#bbb;pointer-events:none}
.rte-insert-photo{font-size:11px;font-weight:700;padding:4px 10px;height:30px;border:1px solid #ccc;
                  border-radius:5px;background:#fff;cursor:pointer;white-space:nowrap}
.rte-insert-photo:hover{border-color:var(--green);color:var(--green)}

/* Preview */
.preview-card{background:#f9fdf5;border:1px solid #d4edab;border-radius:8px;padding:16px 18px;margin-top:20px;max-width:660px}
.preview-card h4{margin:0 0 10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--green-d)}

.hint{font-size:11px;color:var(--faint);margin:6px 0 0}
.save-row{display:flex;align-items:center;gap:10px;margin-top:20px}
.btn-save{padding:9px 22px;background:var(--green);color:#111;border:none;border-radius:8px;
          font-weight:800;font-size:14px;cursor:pointer}
.btn-save:hover{background:var(--green-d);color:#fff}
.save-status{font-size:12px;font-weight:700;display:none}
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('settings_signature', $agent); ?>
<main class="main-content">
<div class="page-header">
  <h1>My Email Signature</h1>
  <p>Appended to emails you send from AgentEdge — onboarding completions, notifications, and Company Email.</p>
</div>

<div class="sig-card">

  <!-- Signature image upload -->
  <div class="sig-img-section">
    <h3>Signature Image</h3>
    <p>Upload an image to use as your full email signature. When set, it replaces all fields below.</p>
    <div class="sig-img-drop" id="sig-img-drop"
         onclick="document.getElementById('photo-input').click()"
         ondragover="event.preventDefault();this.classList.add('drag-over')"
         ondragleave="this.classList.remove('drag-over')"
         ondrop="event.preventDefault();this.classList.remove('drag-over');handleDrop(event)">
      <div class="sig-img-placeholder" id="sig-img-placeholder">
        <span>&#128444;</span>
        <em>Click or drag an image here</em>
      </div>
    </div>
    <div class="sig-img-actions">
      <button type="button" class="btn-sig" onclick="document.getElementById('photo-input').click()">Upload Image</button>
      <button type="button" class="btn-sig" id="btn-remove-photo" onclick="deletePhoto()" style="display:none;color:#c00;border-color:#c00">Remove</button>
      <span class="sig-uploading" id="photo-uploading">Uploading…</span>
    </div>
    <div class="sig-img-note" id="sig-img-note">Image is set — fields below are overridden in outgoing emails.</div>
    <input type="file" id="photo-input" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none" onchange="uploadPhoto(this)">
  </div>

  <!-- Structured fields (dimmed when sig image is set) -->
  <div id="simple-mode">
    <div class="field-row">
      <div class="field"><label>Title / Role</label><input type="text" id="sig-title" placeholder="e.g. Director of Recruiting"></div>
      <div class="field"><label>Phone</label><input type="text" id="sig-phone" placeholder="e.g. 843-267-4627"></div>
    </div>
    <div class="field-row">
      <div class="field"><label>Calendar Link</label><input type="text" id="sig-cal" placeholder="https://calendly.com/..."></div>
      <div class="field"><label>Website Link</label><input type="text" id="sig-web" placeholder="https://innovateonline.com"></div>
    </div>
    <p class="hint">Your name is pulled in automatically. Leave all fields blank to sign with just your name.</p>
  </div>

  <label class="custom-toggle">
    <input type="checkbox" id="sig-use-custom" onchange="toggleMode()">
    Write a fully custom signature instead
  </label>

  <div id="custom-mode" style="display:none">
    <div class="rte-wrap">
      <div class="rte-toolbar">
        <div class="rte-group">
          <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteCmd('bold')" title="Bold" style="font-size:15px"><b>B</b></button>
          <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteCmd('italic')" title="Italic" style="font-size:15px"><i>I</i></button>
          <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteCmd('underline')" title="Underline" style="font-size:15px"><u>U</u></button>
        </div>
        <div class="rte-group">
          <div class="cdd" id="cdd-sigcolor">
            <button type="button" class="cdd-toggle" onmousedown="event.preventDefault();toggleCdd('sigcolor')" title="Text color">
              <span>A</span><span class="cdd-arrow">&#9662;</span>
            </button>
            <div class="cdd-menu">
              <div class="cdd-swatch-menu">
                <button type="button" class="cdd-swatch" style="background:#000000" title="Black"          onmousedown="event.preventDefault();focusRte();rteCmd('foreColor','#000000');closeCdds()"></button>
                <button type="button" class="cdd-swatch" style="background:#434343" title="Dark gray"      onmousedown="event.preventDefault();focusRte();rteCmd('foreColor','#434343');closeCdds()"></button>
                <button type="button" class="cdd-swatch" style="background:#999999" title="Gray"           onmousedown="event.preventDefault();focusRte();rteCmd('foreColor','#999999');closeCdds()"></button>
                <button type="button" class="cdd-swatch" style="background:#cc0000" title="Red"            onmousedown="event.preventDefault();focusRte();rteCmd('foreColor','#cc0000');closeCdds()"></button>
                <button type="button" class="cdd-swatch" style="background:#e69138" title="Orange"         onmousedown="event.preventDefault();focusRte();rteCmd('foreColor','#e69138');closeCdds()"></button>
                <button type="button" class="cdd-swatch" style="background:#38761d" title="Green"          onmousedown="event.preventDefault();focusRte();rteCmd('foreColor','#38761d');closeCdds()"></button>
                <button type="button" class="cdd-swatch" style="background:#82C112" title="INNOVATE green" onmousedown="event.preventDefault();focusRte();rteCmd('foreColor','#82C112');closeCdds()"></button>
                <button type="button" class="cdd-swatch" style="background:#0b5394" title="Blue"           onmousedown="event.preventDefault();focusRte();rteCmd('foreColor','#0b5394');closeCdds()"></button>
                <button type="button" class="cdd-swatch" style="background:#674ea7" title="Purple"         onmousedown="event.preventDefault();focusRte();rteCmd('foreColor','#674ea7');closeCdds()"></button>
              </div>
              <div class="cdd-swatch-custom-row">
                <label for="sig-color-custom">Custom</label>
                <input type="color" id="sig-color-custom" value="#000000"
                  onmousedown="saveColorSel()" onchange="restoreColorSel();focusRte();rteCmd('foreColor',this.value);closeCdds()">
              </div>
            </div>
          </div>
        </div>
        <div class="rte-group">
          <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteLink()" title="Insert link">
            <svg viewBox="0 0 20 20"><path d="M8.7 12.3l2.6-2.6"/><path d="M9.3 6.3H8a3 3 0 0 0 0 6h1.3"/><path d="M10.7 13.7H12a3 3 0 0 0 0-6h-1.3"/></svg>
          </button>
          <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteCmd('removeFormat')" title="Clear formatting" style="color:#999">
            <svg viewBox="0 0 20 20"><path d="M5 4h7l3 12H8z"/><line x1="7" y1="4" x2="10" y2="16"/><line x1="4" y1="17" x2="16" y2="17" stroke-width="2"/></svg>
          </button>
        </div>
        <div class="rte-group">
          <button type="button" class="rte-insert-photo" onmousedown="event.preventDefault();insertPhoto()" title="Insert your photo">&#128247; Insert photo</button>
        </div>
      </div>
      <div id="sig-custom-body" class="rte-body" contenteditable="true" data-placeholder="Write your signature…"></div>
    </div>
    <p class="hint">This replaces the auto-built signature. Use inline styles; CSS classes don't work in most email clients.</p>
  </div>

  <div class="save-row">
    <button class="btn-save" onclick="saveSig()">Save Signature</button>
    <span class="save-status" id="save-status"></span>
  </div>
</div>

<!-- Live preview (simple mode only) -->
<div class="preview-card" id="preview-card">
  <h4>Preview</h4>
  <div id="preview-body"></div>
</div>

</main>
</div>

<script>
let savedColorSel = null;
let currentPhotoUrl = '';
const MY_NAME = <?php echo json_encode($agent['name'] ?? $agent['email']); ?>;

function rteCmd(cmd, val) { document.execCommand(cmd, false, val || null); }
function focusRte() { document.getElementById('sig-custom-body').focus(); }

function rteLink() {
  const url = prompt('URL:');
  if (!url) return;
  focusRte();
  rteCmd('createLink', url);
}

function insertPhoto() {
  if (!currentPhotoUrl) {
    // No photo yet — open the picker; after upload, insert automatically
    const input = document.getElementById('photo-input');
    input.dataset.insertAfter = '1';
    input.click();
    return;
  }
  doInsertPhoto();
}

function doInsertPhoto() {
  focusRte();
  document.execCommand('insertHTML', false,
    '<img src="' + currentPhotoUrl + '" width="80" height="80" style="border-radius:50%;object-fit:cover;vertical-align:middle;margin-right:12px" alt="' + esc(MY_NAME) + '">');
}

function toggleCdd(id) {
  document.querySelectorAll('.cdd').forEach(el =>
    el.classList.toggle('open', el.id === 'cdd-' + id && !el.classList.contains('open')));
}
function closeCdds() { document.querySelectorAll('.cdd').forEach(el => el.classList.remove('open')); }
function saveColorSel() { try { savedColorSel = document.getSelection().getRangeAt(0); } catch(e){} }
function restoreColorSel() {
  if (savedColorSel) { try { const s = document.getSelection(); s.removeAllRanges(); s.addRange(savedColorSel); } catch(e){} }
}
document.addEventListener('click', e => { if (!e.target.closest('.cdd')) closeCdds(); });

function setPhoto(url) {
  currentPhotoUrl = url || '';
  const drop      = document.getElementById('sig-img-drop');
  const ph        = document.getElementById('sig-img-placeholder');
  const removeBtn = document.getElementById('btn-remove-photo');
  const note      = document.getElementById('sig-img-note');
  const simpleWrap = document.getElementById('simple-mode');
  const customToggleWrap = document.querySelector('.custom-toggle');
  const customMode = document.getElementById('custom-mode');

  if (url) {
    let img = drop.querySelector('img');
    if (!img) { img = document.createElement('img'); drop.appendChild(img); }
    img.src = url + (url.includes('?') ? '&' : '?') + '_v=' + Date.now();
    img.alt = 'Signature';
    if (ph) ph.style.display = 'none';
    if (removeBtn) removeBtn.style.display = '';
    if (note) note.style.display = '';
    simpleWrap.classList.add('fields-dimmed');
    if (customToggleWrap) customToggleWrap.style.opacity = '.4';
    if (customToggleWrap) customToggleWrap.style.pointerEvents = 'none';
    if (customMode) { customMode.style.display = 'none'; }
  } else {
    let img = drop.querySelector('img');
    if (img) img.remove();
    if (ph) ph.style.display = '';
    if (removeBtn) removeBtn.style.display = 'none';
    if (note) note.style.display = 'none';
    simpleWrap.classList.remove('fields-dimmed');
    if (customToggleWrap) { customToggleWrap.style.opacity = ''; customToggleWrap.style.pointerEvents = ''; }
    toggleMode();
  }
  updatePreview();
}

function handleDrop(event) {
  const file = event.dataTransfer.files[0];
  if (!file) return;
  const fakeInput = { files: [file], dataset: {}, value: '' };
  uploadPhoto(fakeInput);
}

async function deletePhoto() {
  if (!currentPhotoUrl) return;
  try {
    const r = await fetch('api/signature_action.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ action: 'delete_photo' }),
    });
    const d = await r.json();
    if (d.ok) {
      setPhoto('');
    } else {
      alert('Could not remove photo: ' + (d.error || 'unknown'));
    }
  } catch(e) { alert('Could not remove photo.'); }
}

async function uploadPhoto(input) {
  const file = input.files[0];
  if (!file) return;
  const insertAfter = input.dataset.insertAfter === '1';
  delete input.dataset.insertAfter;
  input.value = '';
  document.getElementById('photo-uploading').style.display = 'block';

  const fd = new FormData();
  fd.append('action', 'upload_photo');
  fd.append('photo', file);
  try {
    const r = await fetch('api/signature_action.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) {
      setPhoto(d.photo_url);
      if (insertAfter) doInsertPhoto();
    } else {
      alert('Upload failed: ' + (d.error || 'unknown'));
    }
  } catch(e) { alert('Upload failed.'); }
  document.getElementById('photo-uploading').style.display = 'none';
}

function toggleMode() {
  if (currentPhotoUrl) return; // sig image controls all state
  const custom = document.getElementById('sig-use-custom').checked;
  const simpleWrap = document.getElementById('simple-mode');
  simpleWrap.classList.remove('fields-dimmed');
  simpleWrap.style.opacity       = custom ? '.4' : '';
  simpleWrap.style.pointerEvents = custom ? 'none' : '';
  document.getElementById('custom-mode').style.display  = custom ? '' : 'none';
  document.getElementById('preview-card').style.display = custom ? 'none' : '';
}

function updatePreview() {
  const previewCard = document.getElementById('preview-card');

  if (currentPhotoUrl) {
    previewCard.style.display = '';
    document.getElementById('preview-body').innerHTML =
      '<div style="border-top:1px solid #ddd;padding-top:12px;margin-top:8px">'
      + '<img src="' + esc(currentPhotoUrl) + '" style="max-width:100%;display:block" alt="Signature">'
      + '</div>';
    return;
  }

  const custom = document.getElementById('sig-use-custom').checked;
  if (custom) { previewCard.style.display = 'none'; return; }
  previewCard.style.display = '';

  const title = document.getElementById('sig-title').value.trim();
  const phone = document.getElementById('sig-phone').value.trim();
  const cal   = document.getElementById('sig-cal').value.trim();
  const web   = document.getElementById('sig-web').value.trim();

  let info = '<div style="font-weight:700;color:#111;font-size:14px">' + esc(MY_NAME) + '</div>';
  if (title) info += '<div style="font-size:12px;color:#666;margin-top:2px">' + esc(title) + '</div>';
  if (phone) info += '<div style="font-size:12px;color:#666;margin-top:2px">' + esc(phone) + '</div>';
  const links = [];
  if (cal) links.push('<a href="' + esc(cal) + '" style="color:#5b8e0d;text-decoration:underline">Schedule a meeting</a>');
  if (web) links.push('<a href="' + esc(web) + '" style="color:#5b8e0d;text-decoration:underline">' + esc(web.replace(/^https?:\/\//,'')) + '</a>');
  if (links.length) info += '<div style="font-size:12px;margin-top:4px">' + links.join(' &nbsp;|&nbsp; ') + '</div>';
  info += '<div style="font-size:12px;color:#aaa;margin-top:3px">INNOVATE Real Estate</div>';

  document.getElementById('preview-body').innerHTML =
    '<div style="border-top:1px solid #ddd;padding-top:12px;margin-top:8px">' + info + '</div>';
}

['sig-title','sig-phone','sig-cal','sig-web'].forEach(id =>
  document.getElementById(id).addEventListener('input', updatePreview));

async function loadSig() {
  try {
    const r = await fetch('api/signature_action.php?action=get');
    const d = await r.json();
    if (!d.ok) return;
    document.getElementById('sig-title').value           = d.title        || '';
    document.getElementById('sig-phone').value           = d.phone        || '';
    document.getElementById('sig-cal').value             = d.calendar_url || '';
    document.getElementById('sig-web').value             = d.website_url  || '';
    document.getElementById('sig-use-custom').checked    = !!d.use_custom;
    document.getElementById('sig-custom-body').innerHTML = d.custom_html  || '';
    toggleMode();      // set structured vs custom state first
    if (d.photo_url) setPhoto(d.photo_url); // then apply sig image on top (overrides everything)
    else updatePreview();
  } catch(e) {}
}

async function saveSig() {
  const useCustom = document.getElementById('sig-use-custom').checked;
  const payload = {
    action:       'save',
    title:        document.getElementById('sig-title').value.trim(),
    phone:        document.getElementById('sig-phone').value.trim(),
    calendar_url: document.getElementById('sig-cal').value.trim(),
    website_url:  document.getElementById('sig-web').value.trim(),
    use_custom:   useCustom ? 1 : 0,
    custom_html:  useCustom ? document.getElementById('sig-custom-body').innerHTML.trim() : '',
  };
  try {
    const r = await fetch('api/signature_action.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload),
    });
    const d = await r.json();
    const st = document.getElementById('save-status');
    st.textContent = d.ok ? 'Saved.' : ('Error: ' + (d.error || 'unknown'));
    st.style.color = d.ok ? 'var(--green-d)' : '#c00';
    st.style.display = 'inline';
    setTimeout(() => { st.style.display = 'none'; }, 3000);
  } catch(e) {}
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

loadSig();
</script>
</body>
</html>
