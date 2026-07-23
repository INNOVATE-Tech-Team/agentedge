<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
if (!can_post_announcements()) { header('Location: index.php'); exit; }

$myMcSlugs = my_mc_slugs();
$isMcOnly  = is_mc_leader() && !is_bic() && !is_admin();
$isBicOnly = is_bic() && !is_admin();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Announcements — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    /* Layout */
    .ann-form{background:#f9fdf5;border:1px solid #d4edab;border-radius:10px;padding:20px 24px;margin-bottom:24px}
    .ann-form h3{margin:0 0 16px;font-size:14px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#5b8e0d}
    .ann-editor-layout{display:grid;grid-template-columns:1fr 300px;gap:24px;align-items:start}
    @media(max-width:900px){.ann-editor-layout{grid-template-columns:1fr}}
    .field-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:12px}
    .field-full{margin-bottom:12px}
    .field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px}
    .field input,.field select{width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;box-sizing:border-box}
    .field input:focus,.field select:focus{outline:2px solid #82C112;border-color:#82C112}
    .btn-primary{padding:9px 20px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer}
    .btn-primary:hover{background:#5b8e0d;color:#fff}
    .btn-sm{padding:4px 10px;font-size:11px;font-weight:700;border-radius:4px;border:none;cursor:pointer}
    .btn-delete{background:#fee2e2;color:#c00}
    .btn-pin{background:#fff4e0;color:#a06000}

    /* ── Advanced Rich Text Editor ─────────────────────────────────────── */
    .rte-wrap{border:1px solid #ccc;border-radius:8px;background:#fff}
    .rte-wrap:focus-within{outline:2px solid #82C112;border-color:#82C112}
    .rte-toolbar{display:flex;align-items:center;gap:2px;padding:7px 10px;background:#f7f7f7;border-bottom:1px solid #e0e0e0;flex-wrap:wrap;row-gap:6px;border-radius:7px 7px 0 0}
    .rte-group{display:flex;align-items:center;gap:2px}
    .rte-group+.rte-group{margin-left:6px;padding-left:8px;border-left:1px solid #dcdcdc}
    .rte-btn{display:inline-flex;align-items:center;justify-content:center;padding:0;border:1px solid transparent;background:none;border-radius:5px;cursor:pointer;font-size:13px;font-weight:600;color:#333;line-height:1;width:30px;height:30px;flex-shrink:0}
    .rte-btn:hover{background:#fff;border-color:#ddd}
    .rte-btn svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round}
    /* Custom dropdown — plain divs, not a native <select>, so the box always
       sizes to fit its own label text exactly. */
    .cdd{position:relative;flex-shrink:0}
    .cdd-toggle{display:flex;align-items:center;gap:5px;height:30px;padding:0 9px;border:1px solid #ccc;border-radius:5px;
      background:#fff;font-size:12px;font-family:inherit;color:#333;cursor:pointer;white-space:nowrap}
    .cdd-toggle:hover{border-color:#aaa}
    .cdd.open .cdd-toggle{border-color:#82C112}
    .cdd-arrow{font-size:8px;color:#888;line-height:1}
    .cdd-menu{display:none;position:absolute;top:calc(100% + 4px);left:0;background:#fff;border:1px solid #ccc;border-radius:6px;
      box-shadow:0 4px 14px rgba(0,0,0,.15);min-width:150px;z-index:50;padding:4px;white-space:nowrap}
    .cdd.open .cdd-menu{display:block}
    .cdd-item{padding:7px 10px;font-size:13px;color:#333;border-radius:4px;cursor:pointer}
    .cdd-item:hover{background:#eef5e8;color:#5b8e0d}
    .rte-body{min-height:110px;padding:10px 12px;font-size:13px;line-height:1.6;outline:none;background:#fff;cursor:text;border-radius:0 0 7px 7px}
    .rte-body:empty:before{content:attr(data-placeholder);color:#aaa;pointer-events:none;display:block}
    .rte-body h2{font-size:18px;font-weight:800;color:#111;margin:0 0 6px;line-height:1.3}
    .rte-body h3{font-size:15px;font-weight:700;color:#333;margin:0 0 4px;line-height:1.3}
    .rte-body p{margin:0 0 6px}
    .rte-body ul,.rte-body ol{margin:0 0 6px;padding-left:20px}
    .rte-body a{color:#5b8e0d;text-decoration:underline}
    .rte-body blockquote{margin:0 0 6px;padding:6px 12px;border-left:3px solid #82C112;background:#f9fdf5;font-style:italic;color:#555}

    /* Image upload */
    .img-upload-area{border:2px dashed #ccc;border-radius:8px;padding:14px;text-align:center;cursor:pointer;transition:border-color 100ms;margin-top:4px}
    .img-upload-area:hover,.img-upload-area.drag-over{border-color:#82C112;background:#f9fdf5}
    .img-upload-area input[type=file]{display:none}
    .img-upload-label{font-size:12px;color:#888}
    .img-preview-wrap{position:relative;display:inline-block;margin-top:8px}
    .img-preview-wrap img{max-height:160px;max-width:100%;border-radius:6px;border:1px solid #ddd;display:block}
    .img-remove-btn{position:absolute;top:4px;right:4px;background:rgba(0,0,0,.55);color:#fff;border:none;border-radius:50%;width:22px;height:22px;font-size:13px;cursor:pointer;display:flex;align-items:center;justify-content:center}
    .img-ctrl-row{margin-top:8px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
    .img-ctrl-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#999;width:32px;flex-shrink:0}
    .seg-btns{display:flex;border:1px solid #ccc;border-radius:6px;overflow:hidden}
    .seg-btn{padding:4px 12px;border:none;border-right:1px solid #ccc;background:#fff;font-size:12px;font-weight:600;cursor:pointer;transition:background 80ms;white-space:nowrap}
    .seg-btn:last-child{border-right:none}
    .seg-btn.active{background:#82C112;color:#000}

    /* Live preview panel */
    .ann-preview-panel{position:sticky;top:20px}
    .ann-preview-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:10px}
    .ann-preview-card{background:#fff;border-radius:10px;box-shadow:0 1px 5px rgba(0,0,0,.09);overflow:hidden;border:1px solid #eee}
    .ann-preview-img{width:100%;display:block;object-fit:cover}
    .ann-preview-body{padding:13px 15px 11px}
    .ann-preview-no-img{border-left:3px solid #82C112}
    .ann-preview-title{font-size:14px;font-weight:700;color:#111;margin-bottom:5px}
    .ann-preview-text{font-size:12px;color:#555;line-height:1.5;margin-bottom:5px}
    .ann-preview-text h2{font-size:15px;font-weight:800;margin:0 0 4px}
    .ann-preview-text h3{font-size:13px;font-weight:700;margin:0 0 3px}
    .ann-preview-text p{margin:0 0 4px}
    .ann-preview-text ul,.ann-preview-text ol{margin:0 0 4px;padding-left:16px}
    .ann-preview-text a{color:#5b8e0d;text-decoration:underline}
    .ann-preview-text blockquote{margin:0 0 4px;padding:4px 10px;border-left:3px solid #82C112;background:#f9fdf5;font-style:italic}
    .ann-preview-meta{font-size:10px;color:#bbb}
    .ann-preview-empty{padding:40px 16px;text-align:center;color:#ccc;font-size:12px;font-style:italic;border:1px dashed #ddd;border-radius:10px;background:#fafafa}

    /* Table */
    .ann-table{width:100%;border-collapse:collapse;font-size:13px}
    .ann-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#aaa;border-bottom:1px solid #eee;padding:8px 10px;text-align:left}
    .ann-table td{padding:9px 10px;border-bottom:1px solid #f5f5f5;vertical-align:top}
    .ann-table tr:hover td{background:#fafff5}
    .ann-thumb{width:52px;height:38px;object-fit:cover;border-radius:4px;border:1px solid #eee;vertical-align:middle;margin-right:6px}
    .pin-badge{display:inline-block;padding:1px 6px;background:#fff4e0;color:#a06000;font-size:10px;font-weight:700;border-radius:8px;margin-left:6px}
    .aud-badge{display:inline-block;padding:2px 8px;font-size:10px;font-weight:700;border-radius:8px}
    .aud-all{background:#e8f5e9;color:#2e7d32}
    .aud-admin{background:#e8f0ff;color:#2255cc}
    .aud-mc{background:#fff3e0;color:#e65100}
    .aud-bic{background:#fce4ec;color:#880e4f}
    .ann-body-preview{color:#555;font-size:12px;margin-top:3px;max-height:54px;overflow:hidden}
    .empty-note{color:#bbb;font-size:13px;padding:24px;text-align:center}
    #mc-target-row{display:none}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('bo_announcements', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Announcements</div>
    </header>
    <main class="wrap">
      <div class="card" style="padding:20px 24px">
        <div class="ann-form">
          <h3 id="form-heading">New Announcement</h3>
          <input type="hidden" id="edit-id" value="">

          <div class="ann-editor-layout">
            <!-- ── Left: form ── -->
            <div>
              <div class="field-full field">
                <label>Title</label>
                <input type="text" id="ann-title" placeholder="e.g. Welcome aboard!">
              </div>

              <div class="field-full field">
                <label>Message</label>
                <div class="rte-wrap">
                  <div class="rte-toolbar">
                    <!-- Paragraph style -->
                    <div class="rte-group">
                      <div class="cdd" id="cdd-style">
                        <button type="button" class="cdd-toggle" onmousedown="event.preventDefault();toggleDropdown('style')" title="Paragraph style">
                          <span>Style</span><span class="cdd-arrow">&#9662;</span>
                        </button>
                        <div class="cdd-menu">
                          <div class="cdd-item" onmousedown="event.preventDefault();rteFormat('p');closeDropdowns();document.getElementById('ann-body').focus()">Paragraph</div>
                          <div class="cdd-item" onmousedown="event.preventDefault();rteFormat('h2');closeDropdowns();document.getElementById('ann-body').focus()">Heading 1</div>
                          <div class="cdd-item" onmousedown="event.preventDefault();rteFormat('h3');closeDropdowns();document.getElementById('ann-body').focus()">Heading 2</div>
                          <div class="cdd-item" onmousedown="event.preventDefault();rteFormat('blockquote');closeDropdowns();document.getElementById('ann-body').focus()">Quote</div>
                        </div>
                      </div>
                    </div>
                    <!-- Text formatting -->
                    <div class="rte-group">
                      <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteCmd('bold')" title="Bold" style="font-size:15px"><b>B</b></button>
                      <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteCmd('italic')" title="Italic" style="font-size:15px"><i>I</i></button>
                      <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteCmd('underline')" title="Underline" style="font-size:15px"><u>U</u></button>
                    </div>
                    <!-- Lists -->
                    <div class="rte-group">
                      <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteCmd('insertUnorderedList')" title="Bullet list">
                        <svg viewBox="0 0 20 20"><circle cx="4" cy="5" r="1.3" fill="currentColor" stroke="none"/><line x1="8" y1="5" x2="17" y2="5"/><circle cx="4" cy="10" r="1.3" fill="currentColor" stroke="none"/><line x1="8" y1="10" x2="17" y2="10"/><circle cx="4" cy="15" r="1.3" fill="currentColor" stroke="none"/><line x1="8" y1="15" x2="17" y2="15"/></svg>
                      </button>
                      <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteCmd('insertOrderedList')" title="Numbered list">
                        <svg viewBox="0 0 20 20"><text x="1.5" y="6.5" font-size="6" font-weight="700" fill="currentColor" stroke="none">1</text><line x1="8" y1="5" x2="17" y2="5"/><text x="1.5" y="11.5" font-size="6" font-weight="700" fill="currentColor" stroke="none">2</text><line x1="8" y1="10" x2="17" y2="10"/><text x="1.5" y="16.5" font-size="6" font-weight="700" fill="currentColor" stroke="none">3</text><line x1="8" y1="15" x2="17" y2="15"/></svg>
                      </button>
                    </div>
                    <!-- Link & Clear -->
                    <div class="rte-group">
                      <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteInsertLink()" title="Insert link">
                        <svg viewBox="0 0 20 20"><path d="M8.7 12.3l2.6-2.6"/><path d="M9.3 6.3H8a3 3 0 0 0 0 6h1.3"/><path d="M10.7 13.7H12a3 3 0 0 0 0-6h-1.3"/></svg>
                      </button>
                      <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteCmd('removeFormat');rteFormat('p')" title="Clear formatting" style="color:#999">
                        <svg viewBox="0 0 20 20"><path d="M5 4h7l3 12H8z"/><line x1="7" y1="4" x2="10" y2="16"/><line x1="4" y1="17" x2="16" y2="17" stroke-width="2"/></svg>
                      </button>
                    </div>
                  </div>
                  <div id="ann-body" class="rte-body" contenteditable="true" data-placeholder="Write your announcement here…"></div>
                </div>
              </div>

              <div class="field-full field">
                <label>Photo (optional)</label>
                <div class="img-upload-area" id="img-drop-zone" onclick="document.getElementById('ann-image').click()"
                     ondragover="event.preventDefault();this.classList.add('drag-over')"
                     ondragleave="this.classList.remove('drag-over')"
                     ondrop="handleImgDrop(event)">
                  <input type="file" id="ann-image" accept="image/jpeg,image/png,image/gif,image/webp" onchange="previewNewImage(this.files[0])">
                  <div class="img-upload-label">Click or drag &amp; drop a photo (JPG, PNG, GIF, WebP · max 8 MB)</div>
                </div>
                <div id="new-img-wrap" class="img-preview-wrap" style="display:none">
                  <img id="new-img-thumb" src="" alt="preview">
                  <button type="button" class="img-remove-btn" onclick="clearNewImage()" title="Remove">✕</button>
                </div>
                <div id="cur-img-wrap" class="img-preview-wrap" style="display:none">
                  <img id="cur-img-thumb" src="" alt="current photo">
                  <button type="button" class="img-remove-btn" onclick="removeCurImage()" title="Remove">✕</button>
                </div>
                <!-- Image controls: size + crop position -->
                <div id="img-ctrl-section" style="display:none">
                  <div class="img-ctrl-row" style="margin-top:10px">
                    <span class="img-ctrl-label">Size</span>
                    <div class="seg-btns">
                      <button type="button" class="seg-btn" id="size-compact"  onclick="setImgSize('compact')">Compact</button>
                      <button type="button" class="seg-btn active" id="size-standard" onclick="setImgSize('standard')">Standard</button>
                      <button type="button" class="seg-btn" id="size-large"   onclick="setImgSize('large')">Large</button>
                    </div>
                  </div>
                  <div class="img-ctrl-row">
                    <span class="img-ctrl-label">Layout</span>
                    <div class="seg-btns">
                      <button type="button" class="seg-btn" id="pos-left"   onclick="setImgPos('left')">&#8592; Left</button>
                      <button type="button" class="seg-btn active" id="pos-center" onclick="setImgPos('center')">Banner</button>
                      <button type="button" class="seg-btn" id="pos-right"  onclick="setImgPos('right')">Right &#8594;</button>
                    </div>
                  </div>
                </div>
              </div>

              <div class="field-row">
                <div class="field">
                  <label>Audience</label>
                  <?php if ($isBicOnly): ?>
                    <input type="text" value="My Agents" disabled style="background:#f5f5f5;color:#888">
                    <input type="hidden" id="ann-audience" value="bic">
                  <?php elseif ($isMcOnly): ?>
                    <input type="text" value="My Market Center" disabled style="background:#f5f5f5;color:#888">
                    <input type="hidden" id="ann-audience" value="mc">
                  <?php else: ?>
                    <select id="ann-audience" onchange="onAudienceChange()">
                      <option value="all">Everyone (All Company)</option>
                      <option value="admin">Admin &amp; Staff Only</option>
                      <option value="mc">Specific Market Center</option>
                    </select>
                  <?php endif; ?>
                </div>
                <div class="field">
                  <label>Expires (optional)</label>
                  <input type="date" id="ann-expires">
                </div>
              </div>

              <div class="field-full field" id="mc-target-row">
                <label>Market Center</label>
                <?php if (!empty($myMcSlugs) && !is_admin()): ?>
                  <select id="ann-mc-slug">
                    <?php foreach ($myMcSlugs as $slug): ?>
                      <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars(ucwords(str_replace('-',' ',$slug))) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <input type="text" id="ann-mc-slug" placeholder="e.g. myrtle-beach">
                <?php endif; ?>
              </div>

              <div style="display:flex;align-items:center;gap:14px">
                <?php if (is_admin()): ?>
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
                  <input type="checkbox" id="ann-pinned"> Pin to top
                </label>
                <?php else: ?>
                <input type="hidden" id="ann-pinned" value="0">
                <?php endif; ?>
                <button class="btn-primary" onclick="saveAnn()">Save</button>
                <button class="btn-sm" id="cancel-edit" style="display:none;background:#f0f0f0;color:#333" onclick="cancelEdit()">Cancel</button>
              </div>
            </div>

            <!-- ── Right: live preview ── -->
            <div class="ann-preview-panel">
              <div class="ann-preview-label">Preview</div>
              <div id="ann-preview">
                <div class="ann-preview-empty">Your announcement will appear here as you fill in the form</div>
              </div>
            </div>
          </div>
        </div>

        <table class="ann-table">
          <thead>
            <tr>
              <th>Title</th>
              <th>Audience</th>
              <th>Created</th>
              <th style="text-align:right">Actions</th>
            </tr>
          </thead>
          <tbody id="ann-tbody"><tr><td colspan="4" class="empty-note">Loading…</td></tr></tbody>
        </table>
      </div>
    </main>
  </div>
</div>
<script>
const IS_ADMIN = <?= is_admin()     ? 'true' : 'false' ?>;
const MY_MCS   = <?= json_encode($myMcSlugs) ?>;

// ── Rich text editor ──────────────────────────────────────────────────────────
// ── Custom toolbar dropdown (Style) ────────────────────────────────────────
function toggleDropdown(name){
  const cdd = document.getElementById('cdd-' + name);
  const wasOpen = cdd.classList.contains('open');
  closeDropdowns();
  if (!wasOpen) cdd.classList.add('open');
}

function closeDropdowns(){
  document.querySelectorAll('.cdd.open').forEach(el => el.classList.remove('open'));
}

document.addEventListener('mousedown', (e) => {
  if (!e.target.closest('.cdd')) closeDropdowns();
});

function rteCmd(cmd){document.execCommand(cmd,false,null);}

function rteFormat(val){
  if(!val) return;
  document.execCommand('formatBlock',false,'<'+(val||'p')+'>');
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
    document.getElementById('ann-body').querySelectorAll('a[href="'+safeUrl+'"]')
      .forEach(el=>{el.target='_blank';el.rel='noopener noreferrer';});
  }
  document.getElementById('ann-body').focus();
}

function getBodyValue(){
  const h=(document.getElementById('ann-body').innerHTML||'').trim();
  return (h==='<br>'||h==='') ? '' : h;
}
function setBodyValue(html){document.getElementById('ann-body').innerHTML=html||'';}

// ── Image state ───────────────────────────────────────────────────────────────
let _removeCurImage=false, _imgPosition='center', _imgSize='standard';

function setImgPos(pos){
  _imgPosition=pos;
  ['left','center','right'].forEach(p=>document.getElementById('pos-'+p)?.classList.toggle('active',p===pos));
  updatePreview();
}
function setImgSize(size){
  _imgSize=size;
  ['compact','standard','large'].forEach(s=>document.getElementById('size-'+s)?.classList.toggle('active',s===size));
  updatePreview();
}
function showImgCtrls(){document.getElementById('img-ctrl-section').style.display='';}
function hideImgCtrls(){document.getElementById('img-ctrl-section').style.display='none';}

function previewNewImage(file){
  if(!file) return;
  const reader=new FileReader();
  reader.onload=e=>{
    document.getElementById('new-img-thumb').src=e.target.result;
    document.getElementById('new-img-wrap').style.display='';
    document.getElementById('cur-img-wrap').style.display='none';
    showImgCtrls(); updatePreview();
  };
  reader.readAsDataURL(file);
}
function handleImgDrop(e){
  e.preventDefault();
  document.getElementById('img-drop-zone').classList.remove('drag-over');
  const file=e.dataTransfer.files[0];
  if(file&&file.type.startsWith('image/')){
    const dt=new DataTransfer(); dt.items.add(file);
    document.getElementById('ann-image').files=dt.files;
    previewNewImage(file);
  }
}
function clearNewImage(){
  document.getElementById('ann-image').value='';
  document.getElementById('new-img-wrap').style.display='none';
  if(document.getElementById('cur-img-wrap').style.display==='none') hideImgCtrls();
  updatePreview();
}
function removeCurImage(){
  _removeCurImage=true;
  document.getElementById('cur-img-wrap').style.display='none';
  if(document.getElementById('new-img-wrap').style.display==='none') hideImgCtrls();
  updatePreview();
}

// ── Live preview ──────────────────────────────────────────────────────────────
function esc(s){return String(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
const PREV_H={compact:'130px',standard:'220px',large:'370px'};
const PREV_P={left:'15% center',center:'center',right:'85% center'};

function updatePreview(){
  const title=document.getElementById('ann-title').value.trim();
  const body=getBodyValue();
  const bodyText=body.replace(/<[^>]*>/g,'').trim();
  const newWrap=document.getElementById('new-img-wrap');
  const curWrap=document.getElementById('cur-img-wrap');
  let imgSrc='';
  if(newWrap.style.display!=='none') imgSrc=document.getElementById('new-img-thumb').src;
  else if(curWrap.style.display!=='none'&&!_removeCurImage) imgSrc=document.getElementById('cur-img-thumb').src;
  const el=document.getElementById('ann-preview');
  if(!title&&!bodyText&&!imgSrc){
    el.innerHTML='<div class="ann-preview-empty">Your announcement will appear here as you fill in the form</div>';
    return;
  }
  const h=PREV_H[_imgSize]||'220px';
  const sideW={compact:'90px',standard:'130px',large:'170px'}[_imgSize]||'130px';
  if(imgSrc && (_imgPosition==='left'||_imgPosition==='right')){
    const rL=_imgPosition==='left'?'10px 0 0 10px':'0 10px 10px 0';
    const imgEl=`<img src="${imgSrc}" style="width:${sideW};object-fit:cover;display:block;flex-shrink:0;border-radius:${rL}" alt="">`;
    const txtEl=`<div style="padding:12px 14px;flex:1;min-width:0">
      <div style="font-size:14px;font-weight:700;color:#111;margin-bottom:5px">${esc(title)||'<span style="color:#ccc;font-style:italic">Add a title…</span>'}</div>
      <div style="font-size:12px;color:#555;line-height:1.5;margin-bottom:5px">${body||'<span style="color:#ccc;font-style:italic">Write your message…</span>'}</div>
      <div style="font-size:10px;color:#bbb">Preview</div>
    </div>`;
    el.innerHTML=`<div class="ann-preview-card" style="display:flex;align-items:stretch">
      ${_imgPosition==='left'?imgEl+txtEl:txtEl+imgEl}
    </div>`;
  } else if(imgSrc){
    el.innerHTML=`<div class="ann-preview-card">
      <div style="position:relative;overflow:hidden;border-radius:10px 10px 0 0">
        <img class="ann-preview-img" src="${imgSrc}" style="height:${h}" alt="">
        <div style="position:absolute;bottom:0;left:0;right:0;padding:28px 12px 11px;background:linear-gradient(0deg,rgba(0,0,0,.68),transparent)">
          <div style="font-size:13px;font-weight:800;color:#fff;line-height:1.3;text-shadow:0 1px 3px rgba(0,0,0,.35)">${esc(title)||'<span style="opacity:.5;font-weight:400;font-style:italic">Add a title…</span>'}</div>
        </div>
      </div>
      <div class="ann-preview-body">
        <div class="ann-preview-text">${body||'<span style="color:#ccc;font-style:italic">Write your message…</span>'}</div>
        <div class="ann-preview-meta">Preview</div>
      </div>
    </div>`;
  } else {
    el.innerHTML=`<div class="ann-preview-card">
      <div class="ann-preview-body ann-preview-no-img">
        <div class="ann-preview-title">${esc(title)||'<span style="color:#ccc;font-style:italic">Add a title…</span>'}</div>
        <div class="ann-preview-text">${body||'<span style="color:#ccc;font-style:italic">Write your message…</span>'}</div>
        <div class="ann-preview-meta">Preview</div>
      </div>
    </div>`;
  }
}

document.getElementById('ann-title').addEventListener('input',updatePreview);
document.getElementById('ann-body').addEventListener('input',updatePreview);

// ── Utilities ─────────────────────────────────────────────────────────────────
function fmtDate(s){if(!s)return'—';return new Date(s).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}
function audLabel(a,mc){
  if(a==='all')   return '<span class="aud-badge aud-all">All Company</span>';
  if(a==='admin') return '<span class="aud-badge aud-admin">Admin &amp; Staff</span>';
  if(a==='mc')    return '<span class="aud-badge aud-mc">MC: '+esc(mc||'?')+'</span>';
  if(a==='bic')   return '<span class="aud-badge aud-bic">My Agents (BIC)</span>';
  return esc(a);
}
function onAudienceChange(){
  document.getElementById('mc-target-row').style.display=document.getElementById('ann-audience').value==='mc'?'':'none';
}
<?php if($isMcOnly): ?>document.getElementById('mc-target-row').style.display='';<?php endif; ?>

// ── Data ──────────────────────────────────────────────────────────────────────
let items=[];
function load(){
  fetch('api/announcements.php',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{items=d.items||[];render();});
}
function render(){
  const tb=document.getElementById('ann-tbody');
  if(!items.length){tb.innerHTML='<tr><td colspan="4" class="empty-note">No announcements yet.</td></tr>';return;}
  tb.innerHTML=items.map(a=>{
    const canEdit=IS_ADMIN||a.author===<?= json_encode($agent['email']) ?>;
    const thumb=a.image_key?`<img class="ann-thumb" src="api/announcements.php?action=image&key=${encodeURIComponent(a.image_key)}" alt="">`:'' ;
    return `<tr>
      <td>${thumb}<strong>${esc(a.title)}</strong>${a.pinned?'<span class="pin-badge">Pinned</span>':''}
        <div class="ann-body-preview">${esc(a.body.replace(/<[^>]*>/g,''))}</div></td>
      <td>${audLabel(a.audience,a.target_mc_slug)}</td>
      <td>${fmtDate(a.created_at)}${a.expires_at?'<br><span style="font-size:11px;color:#888">Expires '+fmtDate(a.expires_at)+'</span>':''}</td>
      <td style="text-align:right;white-space:nowrap;display:flex;gap:6px;justify-content:flex-end">
        ${IS_ADMIN?`<button class="btn-sm btn-pin" onclick="togglePin(${a.id},${a.pinned?0:1})">${a.pinned?'Unpin':'Pin'}</button>`:''}
        ${canEdit?`<button class="btn-sm" style="background:#f0f0f0;color:#333" onclick="editAnn(${a.id})">Edit</button>`:''}
        ${canEdit?`<button class="btn-sm btn-delete" onclick="delAnn(${a.id})">Delete</button>`:''}
      </td></tr>`;
  }).join('');
}

// ── CRUD ──────────────────────────────────────────────────────────────────────
function getAudience(){return document.getElementById('ann-audience').value;}
function getPinnedVal(){const el=document.getElementById('ann-pinned');return el.type==='checkbox'?(el.checked?1:0):0;}

function saveAnn(){
  const id=document.getElementById('edit-id').value;
  const audience=getAudience();
  const mcSlugEl=document.getElementById('ann-mc-slug');
  const title=document.getElementById('ann-title').value.trim();
  const body=getBodyValue();
  if(!title||!body.replace(/<[^>]*>/g,'').trim()){alert('Title and message are required.');return;}
  if(audience==='mc'&&!(mcSlugEl?mcSlugEl.value.trim():'')){alert('Select a Market Center.');return;}
  const imageFile=document.getElementById('ann-image').files[0];
  const useFormData=!!imageFile||_removeCurImage;
  const fields={
    action:id?'update':'create', title, body, audience,
    pinned:getPinnedVal(),
    expires_at:document.getElementById('ann-expires').value||'',
    target_mc_slug:audience==='mc'?(mcSlugEl?mcSlugEl.value.trim():''):'',
    image_position:_imgPosition, image_size:_imgSize,
  };
  if(id) fields.id=id;
  if(_removeCurImage) fields.remove_image='1';
  let fetchBody,fetchHeaders;
  if(useFormData){
    const fd=new FormData();
    Object.entries(fields).forEach(([k,v])=>{if(v!==null&&v!==undefined)fd.append(k,v);});
    if(imageFile) fd.append('image',imageFile);
    fetchBody=fd; fetchHeaders={};
  } else {
    fetchBody=JSON.stringify(fields); fetchHeaders={'Content-Type':'application/json'};
  }
  fetch('api/announcements.php',{method:'POST',credentials:'same-origin',headers:fetchHeaders,body:fetchBody})
    .then(r=>r.json()).then(d=>{if(d.ok){clearForm();load();}else alert(d.error||'Error');});
}

function editAnn(id){
  const a=items.find(x=>x.id===id); if(!a) return;
  document.getElementById('edit-id').value=a.id;
  document.getElementById('ann-title').value=a.title;
  setBodyValue(a.body);
  document.getElementById('ann-expires').value=(a.expires_at||'').slice(0,10);
  const audEl=document.getElementById('ann-audience');
  if(audEl.tagName==='SELECT'){audEl.value=a.audience; onAudienceChange();}
  const pinEl=document.getElementById('ann-pinned');
  if(pinEl.type==='checkbox') pinEl.checked=!!a.pinned;
  const mcEl=document.getElementById('ann-mc-slug');
  if(mcEl) mcEl.value=a.target_mc_slug||'';
  _removeCurImage=false;
  clearNewImage();
  if(a.image_key){
    document.getElementById('cur-img-thumb').src='api/announcements.php?action=image&key='+encodeURIComponent(a.image_key);
    document.getElementById('cur-img-wrap').style.display='';
    setImgPos(a.image_position||'center');
    setImgSize(a.image_size||'standard');
    showImgCtrls();
  } else {
    document.getElementById('cur-img-wrap').style.display='none';
    hideImgCtrls();
  }
  document.getElementById('form-heading').textContent='Edit Announcement';
  document.getElementById('cancel-edit').style.display='';
  updatePreview();
  window.scrollTo({top:0,behavior:'smooth'});
}

function cancelEdit(){clearForm();}

function clearForm(){
  ['ann-title','ann-expires'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
  setBodyValue('');
  const audEl=document.getElementById('ann-audience');
  if(audEl.tagName==='SELECT'){audEl.value='all'; onAudienceChange();}
  const pinEl=document.getElementById('ann-pinned');
  if(pinEl.type==='checkbox') pinEl.checked=false;
  const mcEl=document.getElementById('ann-mc-slug');
  if(mcEl) mcEl.value='';
  document.getElementById('edit-id').value='';
  document.getElementById('form-heading').textContent='New Announcement';
  document.getElementById('cancel-edit').style.display='none';
  _removeCurImage=false; _imgPosition='center'; _imgSize='standard';
  setImgPos('center'); setImgSize('standard');
  clearNewImage();
  document.getElementById('cur-img-wrap').style.display='none';
  hideImgCtrls();
  updatePreview();
}

function togglePin(id,val){
  fetch('api/announcements.php',{method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'pin',id,pinned:val})}).then(()=>load());
}
function delAnn(id){
  const a=items.find(x=>x.id===id);
  if(!confirm('Delete "'+a.title+'"?')) return;
  fetch('api/announcements.php',{method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',id})}).then(()=>load());
}

load();
</script>
</body>
</html>
