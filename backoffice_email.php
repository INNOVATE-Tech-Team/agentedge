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
.rte-wrap{border:1px solid #ccc;border-radius:8px;background:#fff}
.rte-wrap:focus-within{outline:2px solid #82C112;border-color:#82C112}
.rte-toolbar{display:flex;align-items:center;gap:2px;padding:7px 10px;background:#f7f7f7;border-bottom:1px solid #e0e0e0;flex-wrap:wrap;row-gap:6px;border-radius:7px 7px 0 0}
.rte-group{display:flex;align-items:center;gap:2px}
.rte-group+.rte-group{margin-left:6px;padding-left:8px;border-left:1px solid #dcdcdc}
.rte-btn{display:inline-flex;align-items:center;justify-content:center;padding:0;border:1px solid transparent;background:none;border-radius:5px;cursor:pointer;font-size:13px;font-weight:600;color:#333;line-height:1;width:30px;height:30px;flex-shrink:0}
.rte-btn:hover{background:#fff;border-color:#ddd}
.rte-btn:active,.rte-btn.rte-active{background:#eef5e8;border-color:#c7e2a3;color:#5b8e0d}
.rte-btn svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round}
/* Custom dropdown — plain divs, not a native <select>, so the box always
   sizes to fit its own label text exactly (no OS/browser-dependent native
   arrow-width or vertical-centering quirks to fight). */
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
.img-toolbar{display:none;align-items:center;gap:8px;padding:6px 10px;background:#eef5e8;border-bottom:1px solid #d4edab;font-size:12px}
.img-toolbar.show{display:flex}
.img-toolbar-label{font-weight:700;color:#5b8e0d}
.img-size-btn{padding:5px 10px;border:1px solid #ccc;border-radius:5px;background:#fff;font-size:12px;cursor:pointer}
.img-size-btn:hover{background:#f0f5e8}
.img-size-btn.active{background:#82C112;color:#000;border-color:#82C112;font-weight:700}
.img-remove-btn{padding:5px 10px;border:1px solid #f3c6c6;border-radius:5px;background:#fff;font-size:12px;cursor:pointer;color:#c0392b;margin-left:auto}
.img-remove-btn:hover{background:#fee2e2}
.rte-color-swatch{display:flex;flex-direction:column;align-items:center;gap:2px;width:30px;height:30px;padding:3px 0 0;border:1px solid transparent;border-radius:5px;cursor:pointer;background:none;flex-shrink:0}
.rte-color-swatch:hover{background:#fff;border-color:#ddd}
.rte-color-swatch span.glyph{font-size:12px;font-weight:800;line-height:1;color:#444}
.rte-color-swatch input[type=color]{-webkit-appearance:none;appearance:none;width:20px;height:6px;padding:0;border:1px solid #bbb;border-radius:2px;cursor:pointer;background:none}
.rte-color-swatch input[type=color]::-webkit-color-swatch-wrapper{padding:0}
.rte-color-swatch input[type=color]::-webkit-color-swatch{border:none;border-radius:2px}
.rte-body{min-height:160px;padding:10px 12px;font-size:13px;line-height:1.6;outline:none;background:#fff;cursor:text;border-radius:0 0 7px 7px}
.rte-body:empty:before{content:attr(data-placeholder);color:#aaa;pointer-events:none;display:block}
.rte-body h2{font-size:18px;font-weight:800;color:#111;margin:0 0 6px;line-height:1.3}
.rte-body h3{font-size:15px;font-weight:700;color:#333;margin:0 0 4px;line-height:1.3}
.rte-body p{margin:0 0 6px}
.rte-body ul,.rte-body ol{margin:0 0 6px;padding-left:20px}
.rte-body a{color:#5b8e0d;text-decoration:underline}
.rte-body blockquote{margin:0 0 6px;padding:6px 12px;border-left:3px solid #82C112;background:#f9fdf5;font-style:italic;color:#555}
.rte-body table{border-collapse:collapse}
.reach-note{font-size:12px;color:var(--faint);margin:-4px 0 14px}
.aud-checks{display:flex;flex-wrap:wrap;gap:10px 18px;padding-top:4px}
.aud-check{display:flex;align-items:center;gap:6px;font-size:13px;font-weight:400;text-transform:none;letter-spacing:normal;color:#333;cursor:pointer}
.mc-check-list{display:flex;flex-direction:column;gap:5px;max-height:170px;overflow-y:auto;border:1px solid #ccc;border-radius:6px;padding:10px 12px;background:#fff}
.mc-check{display:flex;align-items:center;gap:6px;font-size:13px;font-weight:400;text-transform:none;letter-spacing:normal;color:#333;cursor:pointer}
.form-actions{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.btn-primary{padding:9px 20px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer}
.btn-primary:hover{background:#5b8e0d;color:#fff}
.btn-primary:disabled{opacity:.5;cursor:default}
.btn-secondary{padding:9px 20px;background:#fff;color:#333;border:1px solid #ccc;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer}
.btn-secondary:hover{background:#f5f5f5;border-color:#aaa}
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
.aud-chip.mc_leader{background:#fce8f0;color:#c41a6a}
.aud-chip.bic{background:#fde8e0;color:#c46a1a}
.aud-chip.launch{background:#eef5e8;color:#3a6b1a}
.empty-note{color:var(--faint);font-style:italic;text-align:center;padding:20px}
.btn-cancel-sched{padding:4px 10px;background:#fee2e2;color:#c00;border:none;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer}
.btn-cancel-sched:hover{background:#fecaca}
.section-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint);margin:0 0 8px}

/* Preview modal */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;z-index:1000;padding:20px}
.modal-box{background:#fff;border-radius:10px;max-width:680px;width:100%;max-height:88vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,.3)}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border)}
.modal-header strong{font-size:14px}
.modal-close{background:none;border:none;font-size:22px;line-height:1;cursor:pointer;color:#888;padding:0 4px}
.modal-close:hover{color:#333}
.modal-subject{padding:12px 18px;font-size:14px;font-weight:700;color:#111;border-bottom:1px solid #eee;background:#fafafa;word-break:break-word}
#preview-frame{width:100%;flex:1;min-height:420px;border:none}
.modal-note{padding:10px 18px;font-size:11px;color:var(--faint);border-top:1px solid #eee;margin:0}
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

      <div class="field-full field">
        <label>Audience <span style="font-weight:400;text-transform:none;letter-spacing:normal;color:#aaa">(pick one or more)</span></label>
        <div class="aud-checks">
          <?php if (is_admin()): ?>
          <label class="aud-check"><input type="checkbox" class="em-aud" value="all" onchange="onAudienceChange()"> Entire Company</label>
          <label class="aud-check"><input type="checkbox" class="em-aud" value="admin" onchange="onAudienceChange()"> Admin &amp; Staff Only</label>
          <label class="aud-check"><input type="checkbox" class="em-aud" value="mc_leader" onchange="onAudienceChange()"> Market Center Leaders</label>
          <label class="aud-check"><input type="checkbox" class="em-aud" value="bic" onchange="onAudienceChange()"> BICs</label>
          <?php endif; ?>
          <?php if (is_admin() || $isMcOnly || $isBicOnly): ?>
          <label class="aud-check"><input type="checkbox" class="em-aud" value="mc" onchange="onAudienceChange()">
            <?= $isBicOnly ? "Market Center(s) I'm BIC Over" : ($isMcOnly ? "Market Center(s) I Lead" : "Specific Market Center(s)") ?>
          </label>
          <?php endif; ?>
          <?php if (can_manage_cohorts()): ?>
          <label class="aud-check"><input type="checkbox" class="em-aud" value="launch_agents" onchange="onAudienceChange()"> LAUNCH Agents</label>
          <label class="aud-check"><input type="checkbox" class="em-aud" value="launch_coaches" onchange="onAudienceChange()"> LAUNCH Coaches</label>
          <?php endif; ?>
          <label class="aud-check"><input type="checkbox" class="em-aud" value="person" onchange="onAudienceChange()"> Specific Person</label>
        </div>
      </div>

      <div class="field-row">
        <div class="field" id="mc-target-row" style="display:none">
          <label>Market Center(s)</label>
          <?php if (is_admin()): ?>
            <div class="mc-check-list" id="mc-check-list">
              <?php foreach ($mcOptsAll as $opt): ?>
              <label class="mc-check">
                <input type="checkbox" class="em-mc" value="<?= htmlspecialchars($opt['slug']) ?>" onchange="onAudienceChange()">
                <?= htmlspecialchars(($opt['state_code'] ? $opt['state_code'] . ' - ' : '') . $opt['name']) ?>
              </label>
              <?php endforeach; ?>
            </div>
          <?php elseif (count($myMcSlugs) > 0): ?>
            <div class="mc-check-list" id="mc-check-list">
              <?php foreach ($myMcSlugs as $slug): ?>
              <label class="mc-check">
                <input type="checkbox" class="em-mc" value="<?= htmlspecialchars($slug) ?>" checked
                  <?= count($myMcSlugs) === 1 ? 'disabled' : '' ?> onchange="onAudienceChange()">
                <?= htmlspecialchars($mcNameMap[$slug] ?? $slug) ?>
              </label>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="field" id="person-target-row" style="display:none">
          <label>Recipient</label>
          <input type="text" id="em-person" list="em-person-list" placeholder="Type a name or email…" autocomplete="off">
          <datalist id="em-person-list"></datalist>
        </div>
      </div>

      <p class="reach-note" id="reach-note">Pick at least one audience above.</p>

      <div class="field-full field">
        <label>Templates</label>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <select id="tpl-select" onchange="onTemplateSelect()" style="min-width:220px;width:auto;padding:7px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px">
            <option value="">— Load a saved template —</option>
          </select>
          <button type="button" class="btn-secondary" onclick="loadTemplate()" style="padding:7px 14px">Load</button>
          <button type="button" class="btn-secondary" onclick="saveAsTemplate()" style="padding:7px 14px">Save as Template</button>
          <button type="button" class="btn-secondary" id="btn-delete-tpl" onclick="deleteTemplate()" style="padding:7px 14px;display:none;color:#c0392b;border-color:#f3c6c6">Delete</button>
        </div>
      </div>

      <div class="field-full field">
        <label>Subject</label>
        <input type="text" id="em-subject" maxlength="150" placeholder="Subject line">
      </div>

      <div class="field-full field">
        <label>Message</label>
        <div class="rte-wrap">
          <div class="rte-toolbar">
            <div class="rte-group">
              <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteCmd('undo')" title="Undo">
                <svg viewBox="0 0 20 20"><path d="M4.5 8h7.5a4 4 0 1 1 0 8H9"/><path d="M7.5 5 4.5 8l3 3"/></svg>
              </button>
              <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteCmd('redo')" title="Redo">
                <svg viewBox="0 0 20 20"><path d="M15.5 8H8a4 4 0 1 0 0 8h2.5"/><path d="M12.5 5l3 3-3 3"/></svg>
              </button>
            </div>
            <div class="rte-group">
              <div class="cdd" id="cdd-style">
                <button type="button" class="cdd-toggle" onmousedown="event.preventDefault();toggleDropdown('style')" title="Paragraph style">
                  <span>Style</span><span class="cdd-arrow">&#9662;</span>
                </button>
                <div class="cdd-menu">
                  <div class="cdd-item" onmousedown="event.preventDefault();rteFormat('p');closeDropdowns();focusBody()">Paragraph</div>
                  <div class="cdd-item" onmousedown="event.preventDefault();rteFormat('h2');closeDropdowns();focusBody()">Heading 1</div>
                  <div class="cdd-item" onmousedown="event.preventDefault();rteFormat('h3');closeDropdowns();focusBody()">Heading 2</div>
                  <div class="cdd-item" onmousedown="event.preventDefault();rteFormat('blockquote');closeDropdowns();focusBody()">Quote</div>
                </div>
              </div>
            </div>
            <div class="rte-group">
              <div class="cdd" id="cdd-font">
                <button type="button" class="cdd-toggle" onmousedown="event.preventDefault();toggleDropdown('font')" title="Font family">
                  <span>Font</span><span class="cdd-arrow">&#9662;</span>
                </button>
                <div class="cdd-menu">
                  <div class="cdd-item" onmousedown="event.preventDefault();rteCmd('fontName','Arial');closeDropdowns();focusBody()">Arial</div>
                  <div class="cdd-item" onmousedown="event.preventDefault();rteCmd('fontName','Georgia');closeDropdowns();focusBody()">Georgia</div>
                  <div class="cdd-item" onmousedown="event.preventDefault();rteCmd('fontName','Verdana');closeDropdowns();focusBody()">Verdana</div>
                  <div class="cdd-item" onmousedown="event.preventDefault();rteCmd('fontName',&quot;'Courier New'&quot;);closeDropdowns();focusBody()">Courier New</div>
                  <div class="cdd-item" onmousedown="event.preventDefault();rteCmd('fontName',&quot;'Times New Roman'&quot;);closeDropdowns();focusBody()">Times New Roman</div>
                </div>
              </div>
              <div class="cdd" id="cdd-size">
                <button type="button" class="cdd-toggle" onmousedown="event.preventDefault();toggleDropdown('size')" title="Font size">
                  <span>Size</span><span class="cdd-arrow">&#9662;</span>
                </button>
                <div class="cdd-menu">
                  <div class="cdd-item" onmousedown="event.preventDefault();rteFontSize('12');closeDropdowns();focusBody()">12px</div>
                  <div class="cdd-item" onmousedown="event.preventDefault();rteFontSize('14');closeDropdowns();focusBody()">14px</div>
                  <div class="cdd-item" onmousedown="event.preventDefault();rteFontSize('16');closeDropdowns();focusBody()">16px</div>
                  <div class="cdd-item" onmousedown="event.preventDefault();rteFontSize('18');closeDropdowns();focusBody()">18px</div>
                  <div class="cdd-item" onmousedown="event.preventDefault();rteFontSize('24');closeDropdowns();focusBody()">24px</div>
                  <div class="cdd-item" onmousedown="event.preventDefault();rteFontSize('32');closeDropdowns();focusBody()">32px</div>
                </div>
              </div>
            </div>
            <div class="rte-group">
              <button type="button" class="rte-btn" data-cmd="bold" onmousedown="event.preventDefault();rteCmd('bold')" title="Bold" style="font-size:15px"><b>B</b></button>
              <button type="button" class="rte-btn" data-cmd="italic" onmousedown="event.preventDefault();rteCmd('italic')" title="Italic" style="font-size:15px"><i>I</i></button>
              <button type="button" class="rte-btn" data-cmd="underline" onmousedown="event.preventDefault();rteCmd('underline')" title="Underline" style="font-size:15px"><u>U</u></button>
            </div>
            <div class="rte-group">
              <button type="button" class="rte-btn" data-cmd="justifyLeft" onmousedown="event.preventDefault();rteCmd('justifyLeft')" title="Align left">
                <svg viewBox="0 0 20 20"><line x1="3" y1="5" x2="17" y2="5"/><line x1="3" y1="9" x2="12" y2="9"/><line x1="3" y1="13" x2="17" y2="13"/><line x1="3" y1="17" x2="12" y2="17"/></svg>
              </button>
              <button type="button" class="rte-btn" data-cmd="justifyCenter" onmousedown="event.preventDefault();rteCmd('justifyCenter')" title="Align center">
                <svg viewBox="0 0 20 20"><line x1="3" y1="5" x2="17" y2="5"/><line x1="6" y1="9" x2="14" y2="9"/><line x1="3" y1="13" x2="17" y2="13"/><line x1="6" y1="17" x2="14" y2="17"/></svg>
              </button>
              <button type="button" class="rte-btn" data-cmd="justifyRight" onmousedown="event.preventDefault();rteCmd('justifyRight')" title="Align right">
                <svg viewBox="0 0 20 20"><line x1="3" y1="5" x2="17" y2="5"/><line x1="8" y1="9" x2="17" y2="9"/><line x1="3" y1="13" x2="17" y2="13"/><line x1="8" y1="17" x2="17" y2="17"/></svg>
              </button>
              <button type="button" class="rte-btn" data-cmd="justifyFull" onmousedown="event.preventDefault();rteCmd('justifyFull')" title="Justify">
                <svg viewBox="0 0 20 20"><line x1="3" y1="5" x2="17" y2="5"/><line x1="3" y1="9" x2="17" y2="9"/><line x1="3" y1="13" x2="17" y2="13"/><line x1="3" y1="17" x2="17" y2="17"/></svg>
              </button>
            </div>
            <div class="rte-group">
              <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteCmd('outdent')" title="Decrease indent">
                <svg viewBox="0 0 20 20"><line x1="8" y1="4" x2="17" y2="4"/><line x1="8" y1="10" x2="17" y2="10"/><line x1="8" y1="16" x2="17" y2="16"/><path d="M5.5 7 3 10l2.5 3"/></svg>
              </button>
              <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteCmd('indent')" title="Increase indent">
                <svg viewBox="0 0 20 20"><line x1="8" y1="4" x2="17" y2="4"/><line x1="8" y1="10" x2="17" y2="10"/><line x1="8" y1="16" x2="17" y2="16"/><path d="M3 7l2.5 3L3 13"/></svg>
              </button>
            </div>
            <div class="rte-group">
              <button type="button" class="rte-btn" data-cmd="insertUnorderedList" onmousedown="event.preventDefault();rteCmd('insertUnorderedList')" title="Bullet list">
                <svg viewBox="0 0 20 20"><circle cx="4" cy="5" r="1.3" fill="currentColor" stroke="none"/><line x1="8" y1="5" x2="17" y2="5"/><circle cx="4" cy="10" r="1.3" fill="currentColor" stroke="none"/><line x1="8" y1="10" x2="17" y2="10"/><circle cx="4" cy="15" r="1.3" fill="currentColor" stroke="none"/><line x1="8" y1="15" x2="17" y2="15"/></svg>
              </button>
              <button type="button" class="rte-btn" data-cmd="insertOrderedList" onmousedown="event.preventDefault();rteCmd('insertOrderedList')" title="Numbered list">
                <svg viewBox="0 0 20 20"><text x="1.5" y="6.5" font-size="6" font-weight="700" fill="currentColor" stroke="none">1</text><line x1="8" y1="5" x2="17" y2="5"/><text x="1.5" y="11.5" font-size="6" font-weight="700" fill="currentColor" stroke="none">2</text><line x1="8" y1="10" x2="17" y2="10"/><text x="1.5" y="16.5" font-size="6" font-weight="700" fill="currentColor" stroke="none">3</text><line x1="8" y1="15" x2="17" y2="15"/></svg>
              </button>
            </div>
            <div class="rte-group">
              <span class="rte-color-swatch" title="Text color">
                <span class="glyph">A</span>
                <input type="color" value="#000000" onchange="rteCmd('foreColor', this.value);focusBody()">
              </span>
              <span class="rte-color-swatch" title="Highlight color">
                <span class="glyph">H</span>
                <input type="color" value="#ffff00" onchange="rteHighlight(this.value);focusBody()">
              </span>
            </div>
            <div class="rte-group">
              <button type="button" class="rte-btn" onmousedown="event.preventDefault()" onclick="document.getElementById('em-img-file').click()" title="Insert image">
                <svg viewBox="0 0 20 20"><rect x="3" y="4" width="14" height="12" rx="1.5"/><circle cx="7" cy="8" r="1.2" fill="currentColor" stroke="none"/><path d="M4 14l4-4 3 3 3-4 3 5"/></svg>
              </button>
              <input type="file" id="em-img-file" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none" onchange="uploadEmailImage(this.files[0])">
              <button type="button" class="rte-btn" onmousedown="event.preventDefault();insertTable()" title="Insert table">
                <svg viewBox="0 0 20 20"><rect x="3" y="3" width="14" height="14" rx="1"/><line x1="3" y1="8" x2="17" y2="8"/><line x1="3" y1="13" x2="17" y2="13"/><line x1="8.3" y1="3" x2="8.3" y2="17"/><line x1="12.7" y1="3" x2="12.7" y2="17"/></svg>
              </button>
              <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteInsertLink()" title="Insert link">
                <svg viewBox="0 0 20 20"><path d="M8.7 12.3l2.6-2.6"/><path d="M9.3 6.3H8a3 3 0 0 0 0 6h1.3"/><path d="M10.7 13.7H12a3 3 0 0 0 0-6h-1.3"/></svg>
              </button>
            </div>
            <div class="rte-group">
              <div class="cdd" id="cdd-var">
                <button type="button" class="cdd-toggle" onmousedown="event.preventDefault();toggleDropdown('var')" title="Insert merge variable">
                  <span>Insert variable</span><span class="cdd-arrow">&#9662;</span>
                </button>
                <div class="cdd-menu">
                  <div class="cdd-item" onmousedown="event.preventDefault();insertVariable('{{first_name}}');closeDropdowns()">First Name</div>
                  <div class="cdd-item" onmousedown="event.preventDefault();insertVariable('{{full_name}}');closeDropdowns()">Full Name</div>
                  <div class="cdd-item" onmousedown="event.preventDefault();insertVariable('{{market_center}}');closeDropdowns()">Market Center</div>
                  <div class="cdd-item" onmousedown="event.preventDefault();insertVariable('{{brokerage}}');closeDropdowns()">Brokerage</div>
                  <div class="cdd-item" onmousedown="event.preventDefault();insertVariable('{{phone}}');closeDropdowns()">Phone</div>
                  <div class="cdd-item" onmousedown="event.preventDefault();insertVariable('{{license_number}}');closeDropdowns()">License #</div>
                  <div class="cdd-item" onmousedown="event.preventDefault();insertVariable('{{license_state}}');closeDropdowns()">License State</div>
                  <div class="cdd-item" onmousedown="event.preventDefault();insertVariable('{{office}}');closeDropdowns()">Office Location</div>
                </div>
              </div>
            </div>
            <div class="rte-group">
              <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteCmd('removeFormat');rteFormat('p')" title="Clear formatting" style="color:#999">
                <svg viewBox="0 0 20 20"><path d="M5 4h7l3 12H8z"/><line x1="7" y1="4" x2="10" y2="16"/><line x1="4" y1="17" x2="16" y2="17" stroke-width="2"/></svg>
              </button>
            </div>
          </div>
          <div class="img-toolbar" id="img-toolbar">
            <span class="img-toolbar-label">Image</span>
            <button type="button" class="img-size-btn" data-pct="25" onmousedown="event.preventDefault();resizeSelectedImage(25)">Small</button>
            <button type="button" class="img-size-btn" data-pct="50" onmousedown="event.preventDefault();resizeSelectedImage(50)">Medium</button>
            <button type="button" class="img-size-btn" data-pct="75" onmousedown="event.preventDefault();resizeSelectedImage(75)">Large</button>
            <button type="button" class="img-size-btn" data-pct="100" onmousedown="event.preventDefault();resizeSelectedImage(100)">Full width</button>
            <button type="button" class="img-remove-btn" onmousedown="event.preventDefault();removeSelectedImage()">Remove</button>
          </div>
          <div id="em-body" class="rte-body" contenteditable="true" data-placeholder="Write your message…"></div>
        </div>
      </div>

      <div class="field-full field">
        <label>Attachments</label>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <button type="button" class="btn-secondary" onclick="document.getElementById('em-attach-file').click()" style="padding:7px 14px">+ Add File</button>
          <input type="file" id="em-attach-file" style="display:none" onchange="uploadAttachment(this.files[0])"
                 accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.jpg,.jpeg,.png,.gif">
          <span id="attach-status" style="font-size:12px;color:var(--faint)"></span>
        </div>
        <div id="attach-list" style="display:flex;flex-direction:column;gap:6px;margin-top:8px"></div>
      </div>

      <div class="form-actions">
        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;font-weight:600;color:#555">
          <input type="checkbox" id="em-schedule-toggle" onchange="toggleSchedule()"> Schedule for later
        </label>
        <input type="datetime-local" id="em-send-at" style="display:none;padding:7px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px">
        <button type="button" class="btn-secondary" onclick="previewEmail()">Preview</button>
        <button class="btn-primary" id="btn-send" onclick="sendEmail()">Send</button>
        <span class="send-status" id="send-status"></span>
      </div>

      <div class="sig-panel">
        <button type="button" class="sig-toggle" onclick="toggleSigPanel()">&#9998; My Signature <span id="sig-arrow">&#9660;</span></button>
        <div class="sig-fields" id="sig-fields" style="display:none">
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#333;cursor:pointer;margin-bottom:12px">
            <input type="checkbox" id="sig-use-custom" onchange="toggleSigMode()"> Write a custom signature instead
          </label>

          <div id="sig-simple-mode">
            <div class="field-row">
              <div class="field"><label>Title</label><input type="text" id="sig-title" placeholder="e.g. Co-Founder"></div>
              <div class="field"><label>Phone</label><input type="text" id="sig-phone" placeholder="e.g. 843-267-4627"></div>
            </div>
            <div class="field-row">
              <div class="field"><label>Calendar Link</label><input type="text" id="sig-cal" placeholder="https://calendly.com/..."></div>
              <div class="field"><label>Website Link</label><input type="text" id="sig-web" placeholder="https://..."></div>
            </div>
            <p style="font-size:11px;color:var(--faint);margin:4px 0 0">
              Your headshot (if uploaded via the Intake Form) and phone (if not set here) are pulled in automatically.
              Leave everything blank to just sign off with your name.
            </p>
          </div>

          <div id="sig-custom-mode" style="display:none">
            <div class="rte-wrap">
              <div class="rte-toolbar">
                <div class="rte-group">
                  <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteCmd('bold')" title="Bold" style="font-size:15px"><b>B</b></button>
                  <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteCmd('italic')" title="Italic" style="font-size:15px"><i>I</i></button>
                  <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteCmd('underline')" title="Underline" style="font-size:15px"><u>U</u></button>
                </div>
                <div class="rte-group">
                  <span class="rte-color-swatch" title="Text color">
                    <span class="glyph">A</span>
                    <input type="color" value="#000000" onchange="rteCmd('foreColor', this.value);document.getElementById('sig-custom-body').focus()">
                  </span>
                </div>
                <div class="rte-group">
                  <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteInsertLink('sig-custom-body')" title="Insert link">
                    <svg viewBox="0 0 20 20"><path d="M8.7 12.3l2.6-2.6"/><path d="M9.3 6.3H8a3 3 0 0 0 0 6h1.3"/><path d="M10.7 13.7H12a3 3 0 0 0 0-6h-1.3"/></svg>
                  </button>
                  <button type="button" class="rte-btn" onmousedown="event.preventDefault();rteCmd('removeFormat')" title="Clear formatting" style="color:#999">
                    <svg viewBox="0 0 20 20"><path d="M5 4h7l3 12H8z"/><line x1="7" y1="4" x2="10" y2="16"/><line x1="4" y1="17" x2="16" y2="17" stroke-width="2"/></svg>
                  </button>
                </div>
              </div>
              <div id="sig-custom-body" class="rte-body" contenteditable="true" data-placeholder="Write your signature…" style="min-height:90px"></div>
            </div>
            <p style="font-size:11px;color:var(--faint);margin:8px 0 0">
              This replaces the automatic signature entirely — the headshot, phone, and links above won't be added.
            </p>
          </div>

          <button type="button" class="btn-sm-save" onclick="saveSignature()" style="margin-top:12px">Save Signature</button>
          <span id="sig-status" style="font-size:12px;margin-left:8px;font-weight:700"></span>
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

<div id="preview-modal" class="modal-overlay" style="display:none" onclick="if(event.target===this) closePreview()">
  <div class="modal-box">
    <div class="modal-header">
      <strong>Email Preview</strong>
      <button type="button" class="modal-close" onclick="closePreview()">&times;</button>
    </div>
    <div class="modal-subject" id="preview-subject-line"></div>
    <iframe id="preview-frame" title="Email preview"></iframe>
    <p class="modal-note">Personalized using your own info as a stand-in for the recipient's name, Market Center, etc. — the real send fills these in per recipient.</p>
  </div>
</div>

<script>
const IS_ADMIN     = <?= is_admin() ? 'true' : 'false' ?>;
const ME_EMAIL     = <?= json_encode(strtolower(trim($agent['email'] ?? ''))) ?>;
const MC_NAME_MAP  = <?= json_encode($mcNameMap) ?>;
let PERSON_LIST_LOADED = false;

function focusBody(){ document.getElementById('em-body').focus(); }

const AUD_LABELS = {
  all: 'Entire Company', admin: 'Admin & Staff', mc_leader: 'Market Center Leaders',
  bic: 'BICs', person: 'Specific Person', launch_agents: 'LAUNCH Agents', launch_coaches: 'LAUNCH Coaches',
};

function selectedAudiences() {
  return Array.from(document.querySelectorAll('.em-aud:checked')).map(el => el.value);
}

function selectedMcSlugs() {
  return Array.from(document.querySelectorAll('.em-mc:checked')).map(el => el.value);
}

function onAudienceChange() {
  const auds = selectedAudiences();
  document.getElementById('mc-target-row').style.display     = auds.includes('mc')     ? '' : 'none';
  document.getElementById('person-target-row').style.display = auds.includes('person') ? '' : 'none';
  if (auds.includes('person') && !PERSON_LIST_LOADED) loadPersonList();

  const note = document.getElementById('reach-note');
  if (!auds.length) { note.textContent = 'Pick at least one audience above.'; return; }
  const parts = auds.map(a => {
    if (a === 'mc') {
      const names = selectedMcSlugs().map(s => MC_NAME_MAP[s] || s);
      return names.length ? names.join(', ') : 'Market Center(s) — none selected yet';
    }
    return AUD_LABELS[a] || a;
  });
  note.innerHTML = 'Sends to: ' + parts.map(escapeHtml).join('; ') + '.';
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

// audience/mcSlug are CSV-joined when a send targeted more than one audience
// and/or more than one Market Center — render one chip per component.
function audLabel(audience, mcSlug, leaderTypes) {
  const auds = String(audience || '').split(',').filter(Boolean);
  if (!auds.length) return '—';
  return auds.map(a => singleAudChip(a, mcSlug, leaderTypes)).join(' ');
}

function singleAudChip(audience, mcSlug, leaderTypes) {
  if (audience === 'all')       return '<span class="aud-chip all">Entire Company</span>';
  if (audience === 'admin')     return '<span class="aud-chip admin">Admin &amp; Staff</span>';
  if (audience === 'mc_leader') return '<span class="aud-chip mc_leader">Market Center Leaders</span>';
  if (audience === 'bic')       return '<span class="aud-chip bic">BICs</span>';
  if (audience === 'leaders') {
    // Legacy combined audience — no longer produced by new sends, kept for history rows sent before this split.
    const types = (leaderTypes || 'mc_leader,bic').split(',').filter(Boolean);
    const label = types.length === 2 ? 'Leaders &amp; BICs'
                : types.includes('mc_leader') ? 'Leaders Only'
                : types.includes('bic') ? 'BICs Only' : 'Leaders &amp; BICs';
    return '<span class="aud-chip leaders">' + label + '</span>';
  }
  if (audience === 'person') return '<span class="aud-chip person">1 Person</span>';
  if (audience === 'launch_agents')  return '<span class="aud-chip launch">LAUNCH Agents</span>';
  if (audience === 'launch_coaches') return '<span class="aud-chip launch">LAUNCH Coaches</span>';
  if (audience === 'mc') {
    const names = String(mcSlug || '').split(',').filter(Boolean).map(s => MC_NAME_MAP[s] || s);
    return '<span class="aud-chip mc">' + escapeHtml(names.join(', ') || '—') + '</span>';
  }
  return '<span class="aud-chip mc">' + escapeHtml(audience) + '</span>';
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
}

// ── Custom toolbar dropdowns (Style/Font/Size/Insert variable) ────────────────
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

// ── Rich text editor ──────────────────────────────────────────────────────────
function rteCmd(cmd, val){ document.execCommand(cmd, false, val ?? null); syncToolbarState(); }

function syncToolbarState(){
  document.querySelectorAll('.rte-btn[data-cmd]').forEach(btn => {
    let active = false;
    try { active = document.queryCommandState(btn.dataset.cmd); } catch (e) {}
    btn.classList.toggle('rte-active', active);
  });
}

(function(){
  const body = document.getElementById('em-body');
  body.addEventListener('keyup', syncToolbarState);
  body.addEventListener('mouseup', syncToolbarState);
  document.addEventListener('selectionchange', () => {
    if (document.activeElement === body) syncToolbarState();
  });
})();

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

function rteInsertLink(bodyId){
  bodyId = bodyId || 'em-body';
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
    document.getElementById(bodyId).querySelectorAll('a[href="'+safeUrl+'"]')
      .forEach(el=>{el.target='_blank';el.rel='noopener noreferrer';});
  }
  document.getElementById(bodyId).focus();
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
      document.execCommand('insertHTML', false, '<img src="'+d.url+'" style="width:100%;max-width:100%;display:block;margin:8px 0">');
      focusBody();
    })
    .catch(() => alert('Network error uploading image.'));
  document.getElementById('em-img-file').value = '';
}

// ── Resize/remove an inserted image ─────────────────────────────────────────
let SELECTED_IMG = null;

document.getElementById('em-body').addEventListener('click', (e) => {
  if (e.target.tagName === 'IMG') {
    SELECTED_IMG = e.target;
    showImageToolbar();
  } else {
    SELECTED_IMG = null;
    hideImageToolbar();
  }
});

document.addEventListener('mousedown', (e) => {
  if (!e.target.closest('#em-body') && !e.target.closest('#img-toolbar')) {
    SELECTED_IMG = null;
    hideImageToolbar();
  }
});

function showImageToolbar(){
  document.getElementById('img-toolbar').classList.add('show');
  syncImageSizeButtons();
}

function hideImageToolbar(){
  document.getElementById('img-toolbar').classList.remove('show');
}

function syncImageSizeButtons(){
  if (!SELECTED_IMG) return;
  const pct = parseInt(SELECTED_IMG.style.width, 10) || 100;
  document.querySelectorAll('.img-size-btn').forEach(b => {
    b.classList.toggle('active', parseInt(b.dataset.pct, 10) === pct);
  });
}

function resizeSelectedImage(pct){
  if (!SELECTED_IMG) return;
  SELECTED_IMG.style.width = pct + '%';
  SELECTED_IMG.style.maxWidth = '100%';
  SELECTED_IMG.style.height = 'auto';
  syncImageSizeButtons();
}

function removeSelectedImage(){
  if (!SELECTED_IMG) return;
  SELECTED_IMG.remove();
  SELECTED_IMG = null;
  hideImageToolbar();
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

function toggleSigMode(){
  const custom = document.getElementById('sig-use-custom').checked;
  document.getElementById('sig-simple-mode').style.display = custom ? 'none' : '';
  document.getElementById('sig-custom-mode').style.display = custom ? '' : 'none';
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
    document.getElementById('sig-use-custom').checked = !!d.use_custom;
    document.getElementById('sig-custom-body').innerHTML = d.custom_html || '';
    toggleSigMode();
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
      use_custom: document.getElementById('sig-use-custom').checked,
      custom_html: document.getElementById('sig-custom-body').innerHTML.trim(),
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
        <td>${audLabel(r.audience, r.target_mc_slug, r.leader_types)}</td>
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
        <td>${audLabel(r.audience, r.target_mc_slug, r.leader_types)}</td>
        <td>${r.recipient_count}</td>
        <td>${escapeHtml(r.sender_email)}</td>
      </tr>`).join('');
  })
  .catch(() => { document.getElementById('email-tbody').innerHTML = '<tr><td colspan="5" class="empty-note">Failed to load.</td></tr>'; });
}

// ── Attachments ────────────────────────────────────────────────────────────────
let ATTACHMENTS = [];

function uploadAttachment(file){
  if (!file) return;
  const status = document.getElementById('attach-status');
  status.textContent = 'Uploading ' + file.name + '…';
  const fd = new FormData();
  fd.append('file', file);
  fetch('api/email_attachment.php', { method:'POST', credentials:'same-origin', body: fd })
    .then(r => r.json())
    .then(d => {
      status.textContent = '';
      if (!d.ok) { alert('Attachment failed: ' + (d.error || 'Unknown')); return; }
      ATTACHMENTS.push({ token: d.token, name: d.name, size: d.size });
      renderAttachments();
    })
    .catch(() => { status.textContent = ''; alert('Network error uploading attachment.'); });
  document.getElementById('em-attach-file').value = '';
}

function fmtSize(bytes){
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024*1024) return (bytes/1024).toFixed(1) + ' KB';
  return (bytes/1024/1024).toFixed(1) + ' MB';
}

function renderAttachments(){
  const wrap = document.getElementById('attach-list');
  wrap.innerHTML = ATTACHMENTS.map((a, i) => `
    <div style="display:flex;align-items:center;gap:8px;font-size:12px;background:#f7f7f7;border:1px solid #e0e0e0;border-radius:6px;padding:6px 10px">
      <span style="flex:1">${escapeHtml(a.name)} <span style="color:#999">(${fmtSize(a.size)})</span></span>
      <button type="button" onclick="removeAttachment(${i})" style="background:none;border:none;color:#c0392b;cursor:pointer;font-weight:700;font-size:14px;line-height:1">&times;</button>
    </div>`).join('');
}

function removeAttachment(i){
  const a = ATTACHMENTS[i];
  fetch('api/email_attachment.php', {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'delete', token: a.token})
  }).catch(() => {});
  ATTACHMENTS.splice(i, 1);
  renderAttachments();
}

// ── Templates ──────────────────────────────────────────────────────────────────
let TEMPLATES = [];

function loadTemplateList(){
  fetch('api/company_email_action.php', {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'template_list'})
  })
  .then(r => r.json())
  .then(d => {
    if (!d.ok) return;
    TEMPLATES = d.templates;
    const sel = document.getElementById('tpl-select');
    const prev = sel.value;
    sel.innerHTML = '<option value="">— Load a saved template —</option>' +
      TEMPLATES.map(t => `<option value="${t.id}">${escapeHtml(t.name)}${t.is_shared && t.owner_email !== ME_EMAIL ? ' (shared)' : ''}</option>`).join('');
    sel.value = prev;
    onTemplateSelect();
  });
}

function onTemplateSelect(){
  document.getElementById('btn-delete-tpl').style.display = document.getElementById('tpl-select').value ? '' : 'none';
}

function loadTemplate(){
  const id = document.getElementById('tpl-select').value;
  if (!id) { alert('Pick a template first.'); return; }
  const tpl = TEMPLATES.find(t => String(t.id) === id);
  if (!tpl) return;
  const bodyEl = document.getElementById('em-body');
  const hasContent = document.getElementById('em-subject').value.trim() || bodyEl.textContent.trim();
  if (hasContent && !confirm('Load this template? It will replace your current subject and message.')) return;
  document.getElementById('em-subject').value = tpl.subject;
  bodyEl.innerHTML = tpl.body_html;
}

function saveAsTemplate(){
  const subject  = document.getElementById('em-subject').value.trim();
  const bodyHtml = document.getElementById('em-body').innerHTML.trim();
  const hasText  = document.getElementById('em-body').textContent.trim() !== '';
  if (!subject || !hasText) { alert('Write a subject and message first.'); return; }
  const name = prompt('Template name:');
  if (!name || !name.trim()) return;
  const shareAll = confirm('Share this template with everyone who has Company Email access?\n\nOK = share with everyone. Cancel = keep it just for me.');

  fetch('api/company_email_action.php', {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'template_save', name: name.trim(), subject, body_html: bodyHtml, is_shared: shareAll})
  })
  .then(r => r.json())
  .then(d => {
    if (!d.ok) { alert('Save failed: ' + (d.error || 'Unknown')); return; }
    loadTemplateList();
  })
  .catch(() => alert('Network error saving template.'));
}

function deleteTemplate(){
  const id = document.getElementById('tpl-select').value;
  if (!id) return;
  if (!confirm('Delete this template? This cannot be undone.')) return;
  fetch('api/company_email_action.php', {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'template_delete', id: parseInt(id, 10)})
  })
  .then(r => r.json())
  .then(d => {
    if (!d.ok) { alert('Delete failed: ' + (d.error || 'Unknown')); return; }
    loadTemplateList();
  });
}

// ── Preview ────────────────────────────────────────────────────────────────────
function previewEmail() {
  const subject  = document.getElementById('em-subject').value.trim();
  const bodyEl   = document.getElementById('em-body');
  const bodyHtml = bodyEl.innerHTML.trim();
  const hasText  = bodyEl.textContent.trim() !== '';
  if (!subject || !hasText) { alert('Write a subject and message first.'); return; }

  fetch('api/company_email_action.php', {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'preview', subject, body: bodyHtml})
  })
  .then(r => r.json())
  .then(d => {
    if (!d.ok) { alert('Preview failed: ' + (d.error || 'Unknown')); return; }
    document.getElementById('preview-subject-line').textContent = 'Subject: ' + d.subject;
    document.getElementById('preview-frame').srcdoc =
      '<div style="font-family:Arial,sans-serif;font-size:14px;color:#222;line-height:1.6;padding:16px">' + d.html + '</div>';
    document.getElementById('preview-modal').style.display = 'flex';
  })
  .catch(() => alert('Network error generating preview.'));
}

function closePreview() {
  document.getElementById('preview-modal').style.display = 'none';
}

function sendEmail() {
  const audiences = selectedAudiences();
  const mcSlugs    = audiences.includes('mc') ? selectedMcSlugs() : [];

  let targetEmail = '';
  if (audiences.includes('person')) {
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
  if (!audiences.length) { status.textContent = 'Pick at least one audience.'; status.className = 'send-status err'; return; }
  if (audiences.includes('mc') && !mcSlugs.length) { status.textContent = 'Pick at least one Market Center.'; status.className = 'send-status err'; return; }
  if (audiences.includes('person') && !targetEmail) { status.textContent = 'Pick a recipient.'; status.className = 'send-status err'; return; }

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
      audience: audiences, target_mc_slug: mcSlugs, target_email: targetEmail,
      subject, body: bodyHtml, send_at: sendAtIso,
      attachment_tokens: ATTACHMENTS.map(a => a.token),
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
    ATTACHMENTS = [];
    renderAttachments();
    if (isSchedule) {
      document.getElementById('em-schedule-toggle').checked = false;
      toggleSchedule();
      loadScheduled();
    }
    loadHistory();
  })
  .catch(() => { btn.disabled = false; btn.textContent = isSchedule ? 'Schedule' : 'Send'; status.textContent = 'Network error.'; status.className = 'send-status err'; });
}

onAudienceChange();
loadSignature();
loadTemplateList();
loadScheduled();
loadHistory();
</script>
</body>
</html>
