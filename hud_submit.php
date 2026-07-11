<?php
// HUD & Check document submission portal.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/dotloop.php';

$agent     = require_login();
$email     = $agent['email'];
$connected = dotloop_is_connected($email);

$pageTitle = 'Submit HUD &amp; Check';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Submit HUD &amp; Check – INNOVATE AgentEdge</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
<style>
.hud-wrap        { max-width: 680px; margin: 0 auto; padding: 32px 16px 64px; }
.hud-card        { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 12px; padding: 32px; }
.hud-title       { font-size: 22px; font-weight: 700; margin-bottom: 6px; }
.hud-sub         { color: var(--muted, #6b7280); font-size: 14px; margin-bottom: 28px; line-height: 1.5; }
.hud-field       { margin-bottom: 22px; }
.hud-label       { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
.hud-label span  { color: #dc2626; margin-left: 2px; }
.hud-select,
.hud-file        { width: 100%; padding: 10px 12px; border: 1px solid var(--border, #d1d5db);
                   border-radius: 8px; font-size: 14px; background: var(--bg, #f9fafb);
                   box-sizing: border-box; }
.hud-select      { cursor: pointer; }
.hud-file        { padding: 8px 12px; cursor: pointer; }
.hud-hint        { font-size: 12px; color: var(--muted, #6b7280); margin-top: 4px; }
.hud-btn         { width: 100%; padding: 13px; background: #82C112; color: #fff;
                   border: none; border-radius: 8px; font-size: 15px; font-weight: 700;
                   cursor: pointer; margin-top: 8px; }
.hud-btn:disabled { opacity: .5; cursor: default; }
.hud-notice      { padding: 14px 16px; border-radius: 8px; font-size: 14px; line-height: 1.5; margin-bottom: 20px; }
.hud-notice.warn { background: #fef9c3; border: 1px solid #fde047; }
.hud-notice.info { background: #eff6ff; border: 1px solid #bfdbfe; }
.hud-notice.ok   { background: #f0fdf4; border: 1px solid #86efac; }
.hud-notice.err  { background: #fef2f2; border: 1px solid #fca5a5; }
.hud-loader      { display: none; text-align: center; padding: 16px; color: var(--muted, #6b7280); font-size: 14px; }
.hud-result      { display: none; }
.spinner         { display: inline-block; width: 16px; height: 16px; border: 2px solid #ccc;
                   border-top-color: #82C112; border-radius: 50%; animation: spin .7s linear infinite;
                   vertical-align: middle; margin-right: 8px; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>
</head>
<body>
<?php render_sidebar('hud_submit', $agent); ?>
<main class="main-content">
<div class="hud-wrap">
  <div class="hud-card">
    <div class="hud-title">Submit HUD &amp; Check</div>
    <div class="hud-sub">Upload your settlement statement (HUD) and check image for a transaction. Files will be sent to your DotLoop loop and Michele will be notified.</div>

    <?php if (!$connected): ?>
    <div class="hud-notice warn">
      <strong>DotLoop not connected.</strong> Connect your DotLoop account first so files can be added to your transaction.
      <br><a href="dotloop_connect.php" style="color:#92400e;font-weight:600">Connect DotLoop →</a>
    </div>
    <?php else: ?>
    <div class="hud-notice info" id="loops-loading">
      <span class="spinner"></span> Loading your transactions…
    </div>
    <?php endif; ?>

    <form id="hud-form" enctype="multipart/form-data" <?php if (!$connected) echo 'style="display:none"'; ?>>
      <div class="hud-field">
        <label class="hud-label" for="loop_id">Transaction <span>*</span></label>
        <select class="hud-select" id="loop_id" name="loop_id" required>
          <option value="">— select transaction —</option>
        </select>
        <input type="hidden" id="loop_name" name="loop_name" value="">
      </div>

      <div class="hud-field">
        <label class="hud-label" for="hud_file">Settlement Statement (HUD) <span>*</span></label>
        <input class="hud-file" type="file" id="hud_file" name="hud_file" accept=".pdf,.jpg,.jpeg,.png" required>
        <div class="hud-hint">PDF or image, max 20 MB</div>
      </div>

      <div class="hud-field">
        <label class="hud-label" for="check_file">Check / Earnest Money <span>*</span></label>
        <input class="hud-file" type="file" id="check_file" name="check_file" accept=".pdf,.jpg,.jpeg,.png" required>
        <div class="hud-hint">PDF or image, max 20 MB</div>
      </div>

      <div class="hud-loader" id="hud-loader">
        <span class="spinner"></span> Uploading and sending — please wait…
      </div>
      <div class="hud-result" id="hud-result"></div>

      <button class="hud-btn" type="submit" id="hud-btn"<?php if (!$connected) echo ' disabled'; ?>>Submit Documents</button>
    </form>
  </div>
</div>
</main>

<script>
(function () {
  <?php if ($connected): ?>
  // Load loops into the dropdown
  fetch('api/hud_loops.php')
    .then(r => r.json())
    .then(d => {
      document.getElementById('loops-loading').style.display = 'none';
      const sel = document.getElementById('loop_id');
      if (!d.ok || !d.loops || d.loops.length === 0) {
        sel.innerHTML = '<option value="">— no active transactions found —</option>';
        return;
      }
      d.loops.forEach(l => {
        const label = l.name + (l.address ? ' — ' + l.address : '') + ' [' + l.status + ']';
        const opt = new Option(label, l.id);
        opt.dataset.name = l.name;
        sel.appendChild(opt);
      });
      document.getElementById('hud-form').style.display = '';
      document.getElementById('hud-btn').disabled = false;
    })
    .catch(() => {
      document.getElementById('loops-loading').innerHTML =
        '<span style="color:#dc2626">Could not load transactions. Refresh to try again.</span>';
    });

  document.getElementById('loop_id').addEventListener('change', function () {
    const opt = this.options[this.selectedIndex];
    document.getElementById('loop_name').value = opt.dataset.name || opt.text || '';
  });
  <?php endif; ?>

  document.getElementById('hud-form').addEventListener('submit', function (e) {
    e.preventDefault();
    const btn    = document.getElementById('hud-btn');
    const loader = document.getElementById('hud-loader');
    const result = document.getElementById('hud-result');

    btn.disabled = true;
    loader.style.display = 'block';
    result.style.display = 'none';

    const fd = new FormData(this);
    fetch('api/hud_action.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(d => {
        loader.style.display = 'none';
        result.style.display = 'block';
        if (d.ok) {
          result.innerHTML = '<div class="hud-notice ok">'
            + '<strong>✅ Submitted successfully!</strong><br>'
            + (d.dotloop_ok
                ? 'Documents uploaded to DotLoop and Michele has been notified.'
                : '⚠️ Documents saved but could not upload to DotLoop automatically. Michele has still been notified. ' + (d.notes || []).join(' '))
            + '</div>';
          document.getElementById('hud-form').reset();
          btn.disabled = false;
        } else {
          result.innerHTML = '<div class="hud-notice err"><strong>Error:</strong> ' + (d.error || 'Unknown error') + '</div>';
          btn.disabled = false;
        }
      })
      .catch(() => {
        loader.style.display = 'none';
        result.style.display = 'block';
        result.innerHTML = '<div class="hud-notice err">Network error — please try again.</div>';
        btn.disabled = false;
      });
  });
}());
</script>
</body>
</html>
