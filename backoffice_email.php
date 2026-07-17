<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
if (!can_send_company_email()) { header('Location: index.php'); exit; }

$myMcSlugs = my_mc_slugs();
$isMcOnly  = is_mc_leader() && !is_bic() && !is_admin();
$isBicOnly = is_bic() && !is_admin();

// Admin can target any enabled Market Center; mc_leader/bic only their own.
$mcOptsAll = local_db()
    ->query("SELECT slug, name, state_code FROM market_centers WHERE enabled=1 ORDER BY state_code, sort_ord, name")
    ->fetchAll(PDO::FETCH_ASSOC);

// Full slug → name map (including disabled MCs) so history rows for old sends still label correctly.
$mcNameMap = [];
foreach (local_db()->query("SELECT slug, name FROM market_centers")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $mcNameMap[$r['slug']] = $r['name'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Company Email — AgentEdge</title>
<link rel="stylesheet" href="assets/app.css">
<style>
.bo-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
.email-form{background:#f9fdf5;border:1px solid #d4edab;border-radius:10px;padding:20px 24px;margin-bottom:24px}
.email-form h3{margin:0 0 16px;font-size:14px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#5b8e0d}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:12px}
.field-full{margin-bottom:12px}
.field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px}
.field input,.field select,.field textarea{width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:6px;
      font-size:13px;box-sizing:border-box;font-family:inherit}
.field input:focus,.field select:focus,.field textarea:focus{outline:2px solid #82C112;border-color:#82C112}

/* Rich text editor */
.rte-wrap{border:1px solid #ccc;border-radius:8px;overflow:hidden;background:#fff}
.rte-wrap:focus-within{outline:2px solid #82C112;border-color:#82C112}
.rte-toolbar{display:flex;align-items:center;gap:1px;padding:5px 8px;background:#f7f7f7;border-bottom:1px solid #e0e0e0;flex-wrap:wrap}
.rte-group{display:flex;align-items:center;gap:1px}
.rte-group+.rte-group{margin-left:4px;padding-left:5px;border-left:1px solid #ddd}
.rte-btn{display:inline-flex;align-items:center;justify-content:center;padding:4px 7px;border:none;background:none;border-radius:4px;cursor:pointer;font-size:12px;font-weight:600;color:#333;line-height:1;min-width:26px;height:26px;gap:3px;flex-shrink:0;white-space:nowrap}
.rte-btn:hover{background:#e8e8e8}
.rte-btn-text{padding:4px 9px;font-weight:600}
.rte-select{padding:3px 4px;border:1px solid #ccc;border-radius:4px;font-size:12px;background:#fff;cursor:pointer;height:26px;outline:none;color:#333;flex-shrink:0}
.rte-select:focus{border-color:#82C112}
.rte-select.rte-select-style{min-width:88px}
.rte-select.rte-select-font{min-width:104px}
.rte-select.rte-select-size{min-width:68px}
.rte-select.rte-select-align{min-width:76px}
.rte-select.rte-select-var{min-width:110px}
.rte-color{width:26px;height:26px;padding:2px;border:1px solid #ccc;border-radius:4px;cursor:pointer;background:#fff;flex-shrink:0}
.rte-color-lbl{font-size:12px;font-weight:800;color:#555;padding:0 2px;flex-shrink:0}
.rte-body{min-height:160px;padding:10px 12px;font-size:13px;line-height:1.6;outline:none;background:#fff;cursor:text}
.rte-body:empty:before{content:attr(data-placeholder);color:#aaa;pointer-events:none;display:block}
.rte-body h2{font-size:18px;font-weight:800;color:#111;margin:0 0 6px;line-height:1.3}
.rte-body h3{font-size:15px;font-weight:700;color:#333;margin:0 0 4px;line-height:1.3}
.rte-body p{margin:0 0 6px}
.rte-body ul,.rte-body ol{margin:0 0 6px;padding-left:20px}
.rte-body a{color:#5b8e0d;text-decoration:underline}
.rte-body blockquote{margin:0 0 6px;padding:6px 12px;border-left:3px solid #82C112;background:#f9fdf5;font-style:italic;color:#555}
.rte-body table{border-collapse:collapse}
.reach-note{font-size:12px;color:var(--faint);margin:-4px 0 14px}
.form-actions{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.btn-primary{padding:9px 20px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer}
.btn-primary:hover{background:#5b8e0d;color:#fff}
.btn-primary:disabled{opacity:.5;cursor:default}
.send-status{font-size:12px;font-weight:700}
.send-status.ok{color:#2e7d32}
.send-status.err{color:#c0392b}

/* Signature settings */
.sig-panel{margin-bottom:16px}
.sig-toggle{background:none;border:1px solid #d4edab;border-radius:6px;padding:6px 12px;font-size:12px;font-weight:700;
            color:#5b8e0d;cursor:pointer}
.sig-toggle:hover{background:#f0f5e8}
.sig-fields{margin-top:10px;padding:14px;background:#fff;border:1px solid #e0e0e0;border-radius:8px}
.btn-sm-save{padding:6px 14px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:700;font-size:12px;cursor:pointer}
.btn-sm-save:hover{background:#5b8e0d;color:#fff}

.email-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:20px}
.email-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--faint);
      padding:8px 16px;text-align:left;white-space:nowrap;border-bottom:1px solid var(--border)}
.email-table td{padding:9px 16px;border-top:1px solid var(--border);vertical-align:middle}
.email-table tr:first-child td{border-top:none}
.aud-chip{font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px;white-space:nowrap}
.aud-chip.all{background:#eef5e8;color:#5b8e0d}
.aud-chip.admin{background:#fff4e0;color:#a07221}
.aud-chip.mc{background:#e8f0fe;color:#1a56c4}
.aud-chip.person{background:#f3e8fe;color:#7a1ac4}
.aud-chip.leaders{background:#fce8f0;color:#c41a6a}
.empty-note{color:var(--faint);font-style:italic;text-align:center;padding:20px}
.btn-cancel-sched{padding:4px 10px;background:#fee2e2;color:#c00;border:none;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer}
.btn-cancel-sched:hover{background:#fecaca}
.section-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint);margin:0 0 8px}
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('bo_company_email', $agent); ?>
<div class="content">
  <div class="content-top">
    <div>
      <div class="bo-eyebrow">Back Office</div>
      <div class="content-title">Company Email</div>
    </div>
  </div>
  <div class="wrap">

    <div class="email-form">
      <h3>Compose</h3>

      <div class="field-row">
        <div class="field">
          <label>Audience</label>
          <select id="em-audience" onchange="onAudienceChange()">
            <?php if (is_admin()): ?>
            <option value="all">Entire Company</option>
            <option value="admin">Admin &amp; Staff Only</option>
            <option value="leaders">Market Center Leaders &amp; BICs</option>
            <option value="mc">Specific Market Center</option>
            <?php else: ?>
            <option value="mc"><?= $isBicOnly ? "Market Center(s) I'm BIC Over" : "Market Center(s) I Lead" ?></option>
            <?php endif; ?>
            <option value="person">Specific Person</option>
          </select>
        </div>
        <div class="field" id="mc-target-row" style="display:none">
          <label>Market Center</label>
          <?php if (is_admin()): ?>
            <select id="em-mc-slug">
              <?php foreach ($mcOptsAll as $opt): ?>
              <option value="<?= htmlspecialchars($opt['slug']) ?>">
                <?= htmlspecialchars(($opt['state_code'] ? $opt['state_code'] . ' - ' : '') . $opt['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          <?php elseif (count($myMcSlugs) > 1): ?>
            <select id="em-mc-slug">
              <?php foreach ($myMcSlugs as $slug): ?>
              <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($mcNameMap[$slug] ?? $slug) ?></option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <input type="text" value="<?= htmlspecialchars($mcNameMap[$myMcSlugs[0] ?? ''] ?? 'My Market Center') ?>" disabled style="background:#f5f5f5;color:#888">
            <input type="hidden" id="em-mc-slug" value="<?= htmlspecialchars($myMcSlugs[0] ?? '') ?>">
          <?php endif; ?>
        </div>
        <div class="field" id="person-target-row" style="display:none">
          <label>Recipient</label>
          <input type="text" id="em-person" list="em-person-list" placeholder="Type a name or email…" autocomplete="off">
          <datalist id="em-person-list"></datalist>
        </div>
      </div>

      <p class="reach-note" id="reach-note">
        <?= $isBicOnly ? 'Sends to every agent in the Market Center(s) you\'re BIC over.'
           : ($isMcOnly ? 'Sends to every agent in the Market Center(s) you lead.'
           : 'Sends to every agent in the company, pulled from the live agent roster.') ?>
      </p>

      <div class="field-full field">
        <label>Subject</label>
        <input type="text" id="em-subject" maxlength="150" placeholder="Subject line">
      </div>

      <div class="field-full field">
        <label>Message</label>
        <div class="rte-wrap">
          <div class="rte-toolbar">
            <div class="rte-group">
              <button type="button" class="rte-btn rte-btn-text" onmousedown="event.preventDefault();rteCmd('undo')" title="Undo">Undo</button>
              <button type="button" class="rte-btn rte-btn-text" onmousedown="event.preventDefault();rteCmd('redo')" title="Redo">Redo</button>
            </div>
            <div class="rte-group">
              <select class="rte-select rte-select-style" title="Paragraph style"
                onchange="rteFormat(this.value);this.selectedIndex=0;focusBody()">
                <option value="">Style</option>
                <option value="p">Paragraph</option>
                <option value="h2">Heading 1</option>
                <option value="h3">Heading 2</option>
                <option value="blockquote">Quote</option>
              </select>
            </div>
            <div class="rte-group">
              <select class="rte-select rte-select-font" title="Font family" onchange="if(this.value){rteCmd('fontName', this.value);}this.selectedIndex=0;focusBody()">
                <option value="">Font</option>
                <option value="Arial">Arial</option>
                <option value="Georgia">Georgia</option>
                <option value="Verdana">Verdana</option>
                <option value="'Courier New'">Courier New</option>
                <option value="'Times New Roman'">Times New Roman</option>
              </select>
              <select class="rte-select rte-select-size" title="Font size" onchange="rteFontSize(this.value);this.selectedIndex=0;focusBody()">
                <option value="">Size</option>
                <option value="12">12px</option>
                <option value="14">14px</option>
                <option value="16">16px</option>
                <option value="18">18px</option>
                <option value="24">24px</option>
                <option value="32">32px</option>
              </select>
            </div>
            <div class="rte-group">
              <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteCmd('bold')" title="Bold"><b>B</b></button>
              <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteCmd('italic')" title="Italic"><i>I</i></button>
              <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteCmd('underline')" title="Underline"><u>U</u></button>
            </div>
            <div class="rte-group">
              <select class="rte-select rte-select-align" title="Text alignment" onchange="rteCmd(this.value);this.selectedIndex=0;focusBody()">
                <option value="">Align</option>
                <option value="justifyLeft">Left</option>
                <option value="justifyCenter">Center</option>
                <option value="justifyRight">Right</option>
                <option value="justifyFull">Justify</option>
              </select>
            </div>
            <div class="rte-group">
              <button type="button" class="rte-btn rte-btn-text" onmousedown="event.preventDefault();rteCmd('outdent')" title="Decrease indent">Outdent</button>
              <button type="button" class="rte-btn rte-btn-text" onmousedown="event.preventDefault();rteCmd('indent')" title="Increase indent">Indent</button>
            </div>
            <div class="rte-group">
              <button type="button" class="rte-btn rte-btn-text" onmousedown="event.preventDefault();rteCmd('insertUnorderedList')" title="Bullet list">&bull; List</button>
              <button type="button" class="rte-btn rte-btn-text" onmousedown="event.preventDefault();rteCmd('insertOrderedList')" title="Numbered list">1. List</button>
            </div>
            <div class="rte-group">
              <span class="rte-color-lbl">A</span>
              <input type="color" class="rte-color" title="Text color" value="#000000" onchange="rteCmd('foreColor', this.value);focusBody()">
              <span class="rte-color-lbl">H</span>
              <input type="color" class="rte-color" title="Highlight color" value="#ffff00" onchange="rteHighlight(this.value);focusBody()">
            </div>
            <div class="rte-group">
              <button type="button" class="rte-btn rte-btn-text" onmousedown="event.preventDefault()" onclick="document.getElementById('em-img-file').click()" title="Insert image">Image</button>
              <input type="file" id="em-img-file" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none" onchange="uploadEmailImage(this.files[0])">
              <button type="button" class="rte-btn rte-btn-text" onmousedown="event.preventDefault();insertTable()" title="Insert table">Table</button>
              <button type="button" class="rte-btn rte-btn-text" onmousedown="event.preventDefault();rteInsertLink()" title="Insert link">Link</button>
            </div>
            <div class="rte-group">
              <select class="rte-select rte-select-var" title="Insert merge variable" onchange="insertVariable(this.value);this.selectedIndex=0">
                <option value="">Insert variable</option>
                <option value="{{first_name}}">First Name</option>
                <option value="{{full_name}}">Full Name</option>
              </select>
            </div>
            <div class="rte-group">
              <button type="button" class="rte-btn rte-btn-text" onmousedown="event.preventDefault();rteCmd('removeFormat');rteFormat('p')" title="Clear formatting" style="color:#888">Clear</button>
            </div>
          </div>
          <div id="em-body" class="rte-body" contenteditable="true" data-placeholder="Write your message…"></div>
        </div>
      </div>

      <div class="form-actions">
        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;font-weight:600;color:#555">
          <input type="checkbox" id="em-schedule-toggle" onchange="toggleSchedule()"> Schedule for later
        </label>
        <input type="datetime-local" id="em-send-at" style="display:none;padding:7px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px">
        <button class="btn-primary" id="btn-send" onclick="sendEmail()">Send</button>
        <span class="send-status" id="send-status"></span>
      </div>

      <div class="sig-panel">
        <button type="button" class="sig-toggle" onclick="toggleSigPanel()">&#9998; My Signature <span id="sig-arrow">&#9660;</span></button>
        <div class="sig-fields" id="sig-fields" style="display:none">
          <div class="field-row">
            <div class="field"><label>Title</label><input type="text" id="sig-title" placeholder="e.g. Co-Founder"></div>
            <div class="field"><label>Phone</label><input type="text" id="sig-phone" placeholder="e.g. 843-267-4627"></div>
          </div>
          <div class="field-row">
            <div class="field"><label>Calendar Link</label><input type="text" id="sig-cal" placeholder="https://calendly.com/..."></div>
            <div class="field"><label>Website Link</label><input type="text" id="sig-web" placeholder="https://..."></div>
          </div>
          <button type="button" class="btn-sm-save" onclick="saveSignature()">Save Signature</button>
          <span id="sig-status" style="font-size:12px;margin-left:8px;font-weight:700"></span>
          <p style="font-size:11px;color:var(--faint);margin:8px 0 0">
            Your headshot (if uploaded via the Intake Form) and phone (if not set here) are pulled in automatically.
            Leave everything blank to just sign off with your name.
          </p>
        </div>
      </div>
    </div>

    <div class="section-label">Scheduled</div>
    <table class="email-table" id="scheduled-table">
      <thead>
        <tr>
          <th>Send At</th>
          <th>Subject</th>
          <th>Audience</th>
          <th>Recipients</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="scheduled-tbody"><tr><td colspan="5" class="empty-note">Loading…</td></tr></tbody>
    </table>

    <div class="section-label">Sent</div>
    <table class="email-table">
      <thead>
        <tr>
          <th>Sent</th>
          <th>Subject</th>
          <th>Audience</th>
          <th>Recipients</th>
          <th>Sent By</th>
        </tr>
      </thead>
      <tbody id="email-tbody"><tr><td colspan="5" class="empty-note">Loading…</td></tr></tbody>
    </table>

  </div>
</div>
</div>
<script>
const IS_ADMIN     = <?= is_admin() ? 'true' : 'false' ?>;
const MC_NAME_MAP  = <?= json_encode($mcNameMap) ?>;
let PERSON_LIST_LOADED = false;

function focusBody(){ document.getElementById('em-body').focus(); }

function onAudienceChange() {
  const val = document.getElementById('em-audience').value;
  document.getElementById('mc-target-row').style.display = (val === 'mc') ? '' : 'none';
  document.getElementById('person-target-row').style.display = (val === 'person') ? '' : 'none';
  if (val === 'person' && !PERSON_LIST_LOADED) loadPersonList();
  const note = document.getElementById('reach-note');
  note.textContent = val === 'all'     ? 'Sends to every agent in the company, pulled from the live agent roster.'
                    : val === 'admin'   ? 'Sends only to Super Admin and Staff accounts.'
                    : val === 'leaders' ? 'Sends to every Market Center Leader and BIC assigned across all Market Centers.'
                    : val === 'person'  ? 'Sends to just the one person you pick.'
                    : 'Sends to every agent in the selected Market Center.';
}

function loadPersonList() {
  fetch('api/company_email_action.php', {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'roster_list'})
  })
  .then(r => r.json())
  .then(d => {
    PERSON_LIST_LOADED = true;
    if (!d.ok) return;
    document.getElementById('em-person-list').innerHTML =
      d.agents.map(a => `<option value="${escapeHtml(a.name)} (${escapeHtml(a.email)})">`).join('');
  });
}

function audLabel(audience, mcSlug) {
  if (audience === 'all')    return '<span class="aud-chip all">Entire Company</span>';
  if (audience === 'admin')  return '<span class="aud-chip admin">Admin &amp; Staff</span>';
  if (audience === 'leaders') return '<span class="aud-chip leaders">Leaders &amp; BICs</span>';
  if (audience === 'person') return '<span class="aud-chip person">1 Person</span>';
  const name = MC_NAME_MAP[mcSlug] || mcSlug || '—';
  return '<span class="aud-chip mc">' + escapeHtml(name) + '</span>';
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
}

// ── Rich text editor ──────────────────────────────────────────────────────────
function rteCmd(cmd, val){ document.execCommand(cmd, false, val ?? null); }

function rteFormat(val){
  if(!val) return;
  document.execCommand('formatBlock',false,'<'+(val||'p')+'>');
}

function rteFontSize(px){
  if(!px) return;
  document.execCommand('fontSize', false, '7');
  document.querySelectorAll('#em-body font[size="7"]').forEach(el => {
    el.removeAttribute('size');
    el.style.fontSize = px + 'px';
  });
}

function rteHighlight(color){
  if(!document.execCommand('hiliteColor', false, color)){
    document.execCommand('backColor', false, color);
  }
}

function rteInsertLink(){
  const sel=window.getSelection();
  const hasText=sel&&!sel.isCollapsed;
  const url=prompt('Link URL:');
  if(!url||!url.trim()) return;
  const safeUrl=url.trim();
  if(!hasText){
    const text=prompt('Link text:','Click here');
    if(!text) return;
    const range=sel.getRangeAt(0);
    const a=document.createElement('a');
    a.href=safeUrl; a.target='_blank'; a.textContent=text;
    range.insertNode(a);
    sel.collapseToEnd();
  } else {
    document.execCommand('createLink',false,safeUrl);
    document.getElementById('em-body').querySelectorAll('a[href="'+safeUrl+'"]')
      .forEach(el=>{el.target='_blank';el.rel='noopener noreferrer';});
  }
  focusBody();
}

function insertTable(){
  const rows = parseInt(prompt('Rows:', '3'), 10);
  const cols = parseInt(prompt('Columns:', '3'), 10);
  if (!rows || !cols || rows < 1 || cols < 1 || rows > 20 || cols > 20) return;
  let html = '<table style="border-collapse:collapse;width:100%;margin:8px 0">';
  for (let r = 0; r < rows; r++) {
    html += '<tr>';
    for (let c = 0; c < cols; c++) html += '<td style="border:1px solid #ccc;padding:6px 8px;min-width:40px">&nbsp;</td>';
    html += '</tr>';
  }
  html += '</table><p><br></p>';
  document.execCommand('insertHTML', false, html);
  focusBody();
}

function uploadEmailImage(file){
  if (!file) return;
  const fd = new FormData();
  fd.append('image', file);
  fetch('api/email_image.php', { method:'POST', credentials:'same-origin', body: fd })
    .then(r => r.json())
    .then(d => {
      if (!d.ok) { alert('Image upload failed: ' + (d.error || 'Unknown')); return; }
      document.execCommand('insertHTML', false, '<img src="'+d.url+'" style="max-width:100%;display:block;margin:8px 0">');
      focusBody();
    })
    .catch(() => alert('Network error uploading image.'));
  document.getElementById('em-img-file').value = '';
}

function insertVariable(v){
  if (!v) return;
  focusBody();
  document.execCommand('insertText', false, v);
}

// ── Signature settings ────────────────────────────────────────────────────────
function toggleSigPanel(){
  const el = document.getElementById('sig-fields');
  const open = el.style.display !== 'none';
  el.style.display = open ? 'none' : '';
  document.getElementById('sig-arrow').innerHTML = open ? '&#9660;' : '&#9650;';
}

function loadSignature(){
  fetch('api/company_email_action.php', {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'get_signature'})
  })
  .then(r => r.json())
  .then(d => {
    if (!d.ok) return;
    document.getElementById('sig-title').value = d.title || '';
    document.getElementById('sig-phone').value = d.phone || '';
    document.getElementById('sig-cal').value   = d.calendar_url || '';
    document.getElementById('sig-web').value   = d.website_url || '';
  });
}

function saveSignature(){
  const status = document.getElementById('sig-status');
  status.textContent = 'Saving…'; status.style.color = '#888';
  fetch('api/company_email_action.php', {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      action:'save_signature',
      title: document.getElementById('sig-title').value.trim(),
      phone: document.getElementById('sig-phone').value.trim(),
      calendar_url: document.getElementById('sig-cal').value.trim(),
      website_url: document.getElementById('sig-web').value.trim(),
    })
  })
  .then(r => r.json())
  .then(d => {
    status.textContent = d.ok ? 'Saved.' : ('Error: ' + (d.error || 'Unknown'));
    status.style.color = d.ok ? '#2e7d32' : '#c0392b';
  })
  .catch(() => { status.textContent = 'Network error.'; status.style.color = '#c0392b'; });
}

// ── Schedule ───────────────────────────────────────────────────────────────────
function toggleSchedule(){
  const on = document.getElementById('em-schedule-toggle').checked;
  document.getElementById('em-send-at').style.display = on ? '' : 'none';
  document.getElementById('btn-send').textContent = on ? 'Schedule' : 'Send';
}

function fmtDt(dt) {
  const ts = Date.parse(dt.replace(' ', 'T') + 'Z');
  if (!ts) return dt;
  return new Date(ts).toLocaleString('en-US', {month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'2-digit'});
}

function loadScheduled(){
  fetch('api/company_email_action.php', {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'scheduled_list'})
  })
  .then(r => r.json())
  .then(d => {
    const tbody = document.getElementById('scheduled-tbody');
    if (!d.ok || !d.rows.length) { tbody.innerHTML = '<tr><td colspan="5" class="empty-note">No scheduled emails.</td></tr>'; return; }
    tbody.innerHTML = d.rows.map(r => `
      <tr>
        <td>${fmtDt(r.send_at)}</td>
        <td>${escapeHtml(r.subject)}</td>
        <td>${audLabel(r.audience, r.target_mc_slug)}</td>
        <td>${r.recipient_count}</td>
        <td><button class="btn-cancel-sched" onclick="cancelScheduled(${r.id})">Cancel</button></td>
      </tr>`).join('');
  })
  .catch(() => { document.getElementById('scheduled-tbody').innerHTML = '<tr><td colspan="5" class="empty-note">Failed to load.</td></tr>'; });
}

function cancelScheduled(id){
  if (!confirm('Cancel this scheduled email?')) return;
  fetch('api/company_email_action.php', {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'cancel_scheduled', id})
  })
  .then(r => r.json())
  .then(() => loadScheduled());
}

function loadHistory() {
  fetch('api/company_email_action.php', {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'history'})
  })
  .then(r => r.json())
  .then(d => {
    const tbody = document.getElementById('email-tbody');
    if (!d.ok || !d.rows.length) { tbody.innerHTML = '<tr><td colspan="5" class="empty-note">No emails sent yet.</td></tr>'; return; }
    tbody.innerHTML = d.rows.map(r => `
      <tr>
        <td>${fmtDt(r.sent_at)}</td>
        <td>${escapeHtml(r.subject)}</td>
        <td>${audLabel(r.audience, r.target_mc_slug)}</td>
        <td>${r.recipient_count}</td>
        <td>${escapeHtml(r.sender_email)}</td>
      </tr>`).join('');
  })
  .catch(() => { document.getElementById('email-tbody').innerHTML = '<tr><td colspan="5" class="empty-note">Failed to load.</td></tr>'; });
}

function sendEmail() {
  const audience = document.getElementById('em-audience').value;
  const mcSlugEl = document.getElementById('em-mc-slug');
  const mcSlug   = (audience === 'mc' && mcSlugEl) ? mcSlugEl.value : '';

  let targetEmail = '';
  if (audience === 'person') {
    const raw = document.getElementById('em-person').value.trim();
    const m   = raw.match(/\(([^()]+)\)\s*$/);
    targetEmail = (m ? m[1] : raw).trim().toLowerCase();
  }

  const subject  = document.getElementById('em-subject').value.trim();
  const bodyEl   = document.getElementById('em-body');
  const bodyHtml = bodyEl.innerHTML.trim();
  const hasText  = bodyEl.textContent.trim() !== '';
  const status   = document.getElementById('send-status');
  const btn      = document.getElementById('btn-send');
  const isSchedule = document.getElementById('em-schedule-toggle').checked;

  if (!subject || !hasText) { status.textContent = 'Subject and message are required.'; status.className = 'send-status err'; return; }
  if (audience === 'mc' && !mcSlug) { status.textContent = 'Pick a Market Center.'; status.className = 'send-status err'; return; }
  if (audience === 'person' && !targetEmail) { status.textContent = 'Pick a recipient.'; status.className = 'send-status err'; return; }

  let sendAtIso = '';
  if (isSchedule) {
    const raw = document.getElementById('em-send-at').value;
    const d   = raw ? new Date(raw) : null;
    if (!d || isNaN(d.getTime()) || d.getTime() <= Date.now()) {
      status.textContent = 'Pick a future date/time to schedule for.'; status.className = 'send-status err'; return;
    }
    sendAtIso = d.toISOString();
  }

  if (!confirm(isSchedule ? 'Schedule this email?' : 'Send this email now? This cannot be undone.')) return;

  btn.disabled = true; btn.textContent = isSchedule ? 'Scheduling…' : 'Sending…';
  status.textContent = ''; status.className = 'send-status';

  fetch('api/company_email_action.php', {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      action: isSchedule ? 'schedule' : 'send',
      audience, target_mc_slug: mcSlug, target_email: targetEmail,
      subject, body: bodyHtml, send_at: sendAtIso,
    })
  })
  .then(r => r.json())
  .then(d => {
    btn.disabled = false; btn.textContent = isSchedule ? 'Schedule' : 'Send';
    if (!d.ok) { status.textContent = 'Error: ' + (d.error || 'Unknown'); status.className = 'send-status err'; return; }
    status.textContent = d.scheduled
      ? `Scheduled for ${d.recipients} recipient${d.recipients !== 1 ? 's' : ''}.`
      : `Sent to ${d.recipients} recipient${d.recipients !== 1 ? 's' : ''}.`;
    status.className = 'send-status ok';
    document.getElementById('em-subject').value = '';
    document.getElementById('em-person').value = '';
    bodyEl.innerHTML = '';
    if (isSchedule) {
      document.getElementById('em-schedule-toggle').checked = false;
      toggleSchedule();
      loadScheduled();
    }
    loadHistory();
  })
  .catch(() => { btn.disabled = false; btn.textContent = isSchedule ? 'Schedule' : 'Send'; status.textContent = 'Network error.'; status.className = 'send-status err'; });
}

if (document.getElementById('em-audience')) onAudienceChange();
loadSignature();
loadScheduled();
loadHistory();
</script>
</body>
</html>
