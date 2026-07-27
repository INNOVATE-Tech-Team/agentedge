<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
if (!is_super_admin()) { header('Location: index.php'); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Email Signatures — AgentEdge</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="stylesheet" href="assets/app.css">
<style>
.page-header{margin-bottom:24px}
.page-header h1{margin:0 0 4px;font-size:22px;font-weight:800}
.page-header p{margin:0;color:var(--faint);font-size:13px}

/* Role tabs */
.role-tabs{display:flex;gap:6px;margin-bottom:24px;flex-wrap:wrap}
.role-tab{padding:7px 16px;border:1px solid var(--border);border-radius:20px;font-size:13px;
          font-weight:700;cursor:pointer;background:#fff;color:var(--muted);transition:all .15s}
.role-tab:hover{border-color:var(--green);color:var(--green)}
.role-tab.active{background:var(--green);border-color:var(--green);color:#111}

/* Signature editor card */
.sig-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:22px 24px}
.sig-card h3{margin:0 0 18px;font-size:14px;font-weight:800;text-transform:uppercase;
             letter-spacing:.05em;color:var(--green-d)}

.field-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
.field{margin-bottom:14px}
.field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;
             letter-spacing:.06em;color:var(--faint);margin-bottom:5px}
.field input{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:7px;
             font-size:13px;box-sizing:border-box;font-family:inherit}
.field input:focus{outline:2px solid var(--green);border-color:var(--green)}

/* Custom-mode toggle */
.custom-toggle{display:flex;align-items:center;gap:8px;margin:16px 0;
               font-size:13px;color:var(--ink);cursor:pointer;user-select:none}
.custom-toggle input{accent-color:var(--green);width:16px;height:16px;cursor:pointer}

/* Rich text editor (matches backoffice_email.php pattern) */
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
.rte-btn:active,.rte-btn.rte-active{background:#eef5e8;border-color:#c7e2a3;color:var(--green-d)}
.rte-btn svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:1.6;
             stroke-linecap:round;stroke-linejoin:round}
.rte-body{min-height:100px;padding:12px;font-size:14px;line-height:1.6;outline:none;
          border-radius:0 0 7px 7px}
.rte-body:empty::before{content:attr(data-placeholder);color:#bbb;pointer-events:none}
.rte-hint{font-size:11px;color:var(--faint);margin-top:6px}

/* Preview */
.preview-box{margin-top:18px;border:1px dashed var(--border);border-radius:8px;
             padding:16px;background:#fafdf5}
.preview-box h4{margin:0 0 10px;font-size:11px;font-weight:700;text-transform:uppercase;
                letter-spacing:.06em;color:var(--faint)}
.preview-inner{font-size:14px}

/* Save row */
.save-row{display:flex;align-items:center;gap:10px;margin-top:20px}
.btn-save{padding:9px 22px;background:var(--green);color:#111;border:none;border-radius:8px;
          font-weight:800;font-size:14px;cursor:pointer}
.btn-save:hover{background:var(--green-d);color:#fff}
.save-status{font-size:12px;font-weight:700;color:var(--green-d);display:none}
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('admin_role_signatures', $agent); ?>
<main class="main-content">
<div class="page-header">
  <h1>Email Signatures</h1>
  <p>Set the signature appended to transactional emails (onboarding complete, coach assignments, etc.) per sender role.</p>
</div>

<div class="role-tabs">
  <?php
  $roles = [
    'default'   => 'Default',
    'admin'     => 'Admin',
    'staff'     => 'Staff',
    'recruiter' => 'Recruiter',
    'mc_leader' => 'MC Leader',
  ];
  foreach ($roles as $key => $label) {
    $active = $key === 'default' ? 'active' : '';
    echo '<button class="role-tab ' . $active . '" data-role="' . $key . '" onclick="switchRole(\'' . $key . '\')">' . $label . '</button>';
  }
  ?>
</div>

<div class="sig-card">
  <h3 id="tab-heading">Default Signature</h3>

  <div class="field-row">
    <div class="field">
      <label>Display Name</label>
      <input type="text" id="f-display-name" placeholder="e.g. INNOVATE Recruiting Team">
    </div>
    <div class="field">
      <label>Title / Department</label>
      <input type="text" id="f-title" placeholder="e.g. Director of Recruiting">
    </div>
  </div>
  <div class="field-row">
    <div class="field">
      <label>Phone</label>
      <input type="text" id="f-phone" placeholder="e.g. 843-267-4627">
    </div>
    <div class="field">
      <label>Website URL</label>
      <input type="url" id="f-website" placeholder="https://innovateonline.com">
    </div>
  </div>

  <label class="custom-toggle">
    <input type="checkbox" id="f-use-custom" onchange="toggleCustomMode()">
    Write a fully custom HTML signature instead
  </label>

  <div id="simple-preview" class="preview-box">
    <h4>Preview</h4>
    <div class="preview-inner" id="simple-preview-inner"></div>
  </div>

  <div id="custom-mode" style="display:none">
    <div class="rte-wrap">
      <div class="rte-toolbar">
        <div class="rte-group">
          <button class="rte-btn" onmousedown="rteFmt('bold')" title="Bold"><svg viewBox="0 0 24 24"><path d="M6 4h8a4 4 0 0 1 0 8H6z"/><path d="M6 12h9a4 4 0 0 1 0 8H6z"/></svg></button>
          <button class="rte-btn" onmousedown="rteFmt('italic')" title="Italic"><svg viewBox="0 0 24 24"><line x1="19" y1="4" x2="10" y2="4"/><line x1="14" y1="20" x2="5" y2="20"/><line x1="15" y1="4" x2="9" y2="20"/></svg></button>
          <button class="rte-btn" onmousedown="rteFmt('underline')" title="Underline"><svg viewBox="0 0 24 24"><path d="M6 3v7a6 6 0 0 0 12 0V3"/><line x1="4" y1="21" x2="20" y2="21"/></svg></button>
        </div>
        <div class="rte-group">
          <select id="rte-color" onchange="rteFmt('foreColor',this.value)" style="height:30px;border:1px solid #ccc;border-radius:5px;padding:0 6px;font-size:12px;cursor:pointer">
            <option value="#1a1a1a">Black</option>
            <option value="#444444">Dark Gray</option>
            <option value="#82C112">INNOVATE Green</option>
            <option value="#5b8e0d">Dark Green</option>
            <option value="#0b5394">Blue</option>
            <option value="#cc0000">Red</option>
            <option value="#888888">Gray</option>
          </select>
        </div>
        <div class="rte-group">
          <button class="rte-btn" onmousedown="rteLink()" title="Insert link">
            <svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
          </button>
        </div>
      </div>
      <div id="rte-body" class="rte-body" contenteditable="true" data-placeholder="Write your signature HTML…"></div>
    </div>
    <p class="rte-hint">This replaces the auto-built signature entirely. Use inline styles — CSS classes won't apply in most email clients.</p>
  </div>

  <div class="save-row">
    <button class="btn-save" onclick="saveSig()">Save Signature</button>
    <span class="save-status" id="save-status">Saved.</span>
  </div>
</div>
</main>
</div>

<script>
const ROLE_LABELS = {
  default:   'Default Signature',
  admin:     'Admin Signature',
  staff:     'Staff Signature',
  recruiter: 'Recruiter Signature',
  mc_leader: 'MC Leader Signature',
};

let currentRole = 'default';

function switchRole(role) {
  currentRole = role;
  document.querySelectorAll('.role-tab').forEach(t => t.classList.toggle('active', t.dataset.role === role));
  document.getElementById('tab-heading').textContent = ROLE_LABELS[role] || role;
  loadSig(role);
}

async function loadSig(role) {
  try {
    const r   = await fetch('api/admin_role_signatures.php?action=get&role=' + role);
    const d   = await r.json();
    const sig = d.sig || {};
    document.getElementById('f-display-name').value = sig.display_name || '';
    document.getElementById('f-title').value         = sig.title        || '';
    document.getElementById('f-phone').value         = sig.phone        || '';
    document.getElementById('f-website').value       = sig.website_url  || '';
    document.getElementById('f-use-custom').checked  = !!sig.use_custom;
    document.getElementById('rte-body').innerHTML    = sig.custom_html  || '';
    toggleCustomMode();
    updateSimplePreview();
  } catch(e) {}
}

function toggleCustomMode() {
  const custom = document.getElementById('f-use-custom').checked;
  document.getElementById('simple-preview').style.display = custom ? 'none' : '';
  document.getElementById('custom-mode').style.display    = custom ? '' : 'none';
}

function updateSimplePreview() {
  const dn      = document.getElementById('f-display-name').value.trim() || 'INNOVATE Real Estate';
  const title   = document.getElementById('f-title').value.trim();
  const phone   = document.getElementById('f-phone').value.trim();
  const website = document.getElementById('f-website').value.trim();

  let html = '<div style="font-weight:700;color:#111;font-size:14px">' + esc(dn) + '</div>';
  if (title)   html += '<div style="font-size:12px;color:#666;margin-top:3px">' + esc(title) + '</div>';
  if (phone)   html += '<div style="font-size:12px;color:#666;margin-top:3px">' + esc(phone) + '</div>';
  if (website) {
    const label = website.replace(/^https?:\/\//, '');
    html += '<div style="font-size:12px;margin-top:5px"><a href="' + esc(website) + '" style="color:#5b8e0d;text-decoration:underline">' + esc(label) + '</a></div>';
  }
  document.getElementById('simple-preview-inner').innerHTML =
    '<div style="margin-top:16px;border-top:1px solid #ddd;padding-top:14px">' + html + '</div>';
}

['f-display-name','f-title','f-phone','f-website'].forEach(id => {
  document.getElementById(id).addEventListener('input', updateSimplePreview);
});

async function saveSig() {
  const useCustom = document.getElementById('f-use-custom').checked;
  const payload = {
    action:       'save',
    role:         currentRole,
    display_name: document.getElementById('f-display-name').value.trim(),
    title:        document.getElementById('f-title').value.trim(),
    phone:        document.getElementById('f-phone').value.trim(),
    website_url:  document.getElementById('f-website').value.trim(),
    use_custom:   useCustom ? 1 : 0,
    custom_html:  useCustom ? document.getElementById('rte-body').innerHTML.trim() : '',
  };
  try {
    const r = await fetch('api/admin_role_signatures.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload),
    });
    const d = await r.json();
    const st = document.getElementById('save-status');
    st.textContent = d.ok ? 'Saved.' : ('Error: ' + (d.error || 'unknown'));
    st.style.display = 'inline';
    st.style.color = d.ok ? 'var(--green-d)' : '#c00';
    setTimeout(() => { st.style.display = 'none'; }, 3000);
  } catch(e) {}
}

// ── Rich text editor helpers ──────────────────────────────────────────────────
function rteFmt(cmd, val) {
  event.preventDefault();
  document.getElementById('rte-body').focus();
  document.execCommand(cmd, false, val || null);
}

function rteLink() {
  event.preventDefault();
  const url = prompt('URL:');
  if (!url) return;
  document.getElementById('rte-body').focus();
  document.execCommand('createLink', false, url);
}

function esc(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Load default on page open
loadSig('default');
updateSimplePreview();
</script>
</body>
</html>
