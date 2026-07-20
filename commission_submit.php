<?php
// Commission check submission portal — one log entry for every method an
// agent uses to get a commission check to INNOVATE.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/dotloop.php';

$agent     = require_login();
$email     = $agent['email'];
$connected = dotloop_is_connected($email);

$pageTitle = 'Submit Commission Check';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Submit Commission Check – INNOVATE AgentEdge</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
<style>
.cc-wrap        { max-width: 680px; margin: 0 auto; padding: 32px 16px 64px; }
.cc-card        { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 12px; padding: 32px; }
.cc-title       { font-size: 22px; font-weight: 700; margin-bottom: 6px; }
.cc-sub         { color: var(--muted, #6b7280); font-size: 14px; margin-bottom: 28px; line-height: 1.5; }
.cc-field       { margin-bottom: 22px; }
.cc-label       { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
.cc-label span  { color: #dc2626; margin-left: 2px; }
.cc-select,
.cc-file        { width: 100%; padding: 10px 12px; border: 1px solid var(--border, #d1d5db);
                   border-radius: 8px; font-size: 14px; background: var(--bg, #f9fafb);
                   box-sizing: border-box; }
.cc-select      { cursor: pointer; }
.cc-file        { padding: 8px 12px; cursor: pointer; }
.cc-hint        { font-size: 12px; color: var(--muted, #6b7280); margin-top: 4px; }
.cc-methods     { display: grid; gap: 10px; }
.cc-method      { display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px;
                   border: 1px solid var(--border, #d1d5db); border-radius: 8px; cursor: pointer; }
.cc-method input { margin-top: 3px; }
.cc-method strong { display: block; font-size: 14px; }
.cc-method small { color: var(--muted, #6b7280); font-size: 12px; }
.cc-method.cc-selected { border-color: #82C112; background: #f7fdee; }
.cc-conditional { display: none; margin-top: 14px; }
.cc-conditional.cc-show { display: block; }
.cc-btn         { width: 100%; padding: 13px; background: #82C112; color: #fff;
                   border: none; border-radius: 8px; font-size: 15px; font-weight: 700;
                   cursor: pointer; margin-top: 8px; }
.cc-btn:disabled { opacity: .5; cursor: default; }
.cc-notice      { padding: 14px 16px; border-radius: 8px; font-size: 14px; line-height: 1.5; margin-bottom: 20px; }
.cc-notice.warn { background: #fef9c3; border: 1px solid #fde047; }
.cc-notice.info { background: #eff6ff; border: 1px solid #bfdbfe; }
.cc-notice.ok   { background: #f0fdf4; border: 1px solid #86efac; }
.cc-notice.err  { background: #fef2f2; border: 1px solid #fca5a5; }
.cc-loader      { display: none; text-align: center; padding: 16px; color: var(--muted, #6b7280); font-size: 14px; }
.cc-result      { display: none; }
.spinner        { display: inline-block; width: 16px; height: 16px; border: 2px solid #ccc;
                   border-top-color: #82C112; border-radius: 50%; animation: spin .7s linear infinite;
                   vertical-align: middle; margin-right: 8px; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>
</head>
<body>
<?php render_sidebar('commission_submit', $agent); ?>
<main class="main-content">
<div class="cc-wrap">
  <div class="cc-card">
    <div class="cc-title">Submit Commission Check</div>
    <div class="cc-sub">Log how your commission check is getting to INNOVATE for this transaction. If you're uploading a scanned check, it will be added to your DotLoop transaction and Michele will be notified automatically.</div>

    <div class="cc-notice info" id="loops-loading">
      <span class="spinner"></span> Loading your transactions…
    </div>

    <form id="cc-form" enctype="multipart/form-data" style="display:none">
      <div class="cc-field">
        <label class="cc-label" for="loop_id">Transaction <span>*</span></label>
        <select class="cc-select" id="loop_id" name="loop_id" required>
          <option value="">— select transaction —</option>
        </select>
        <input type="hidden" id="loop_name" name="loop_name" value="">
      </div>

      <div class="cc-field">
        <label class="cc-label">How is the check getting to INNOVATE? <span>*</span></label>
        <div class="cc-methods">
          <label class="cc-method" data-method="ach_requested">
            <input type="radio" name="method" value="ach_requested" required>
            <span><strong>Request ACH / Wire (preferred)</strong><small>Title company disburses INNOVATE's commission electronically — no check needed.</small></span>
          </label>
          <label class="cc-method" data-method="wire_requested">
            <input type="radio" name="method" value="wire_requested">
            <span><strong>Wire requested</strong><small>A wire has been requested for this transaction.</small></span>
          </label>
          <label class="cc-method" data-method="dropoff">
            <input type="radio" name="method" value="dropoff">
            <span><strong>Dropped off at an office</strong><small>Physical check delivered to MI, Pro Dr, or NMB.</small></span>
          </label>
          <label class="cc-method" data-method="mail">
            <input type="radio" name="method" value="mail">
            <span><strong>Mailed</strong><small>Check mailed to an INNOVATE office.</small></span>
          </label>
          <label class="cc-method" data-method="upload">
            <input type="radio" name="method" value="upload">
            <span><strong>Uploading a scanned check</strong><small>Scan/photo of the check — will be pushed to DotLoop and Michele notified.</small></span>
          </label>
        </div>
      </div>

      <div class="cc-field cc-conditional" id="cc-office-field">
        <label class="cc-label" for="office_location">Office <span>*</span></label>
        <select class="cc-select" id="office_location" name="office_location">
          <option value="">— select office —</option>
          <option value="MI">MI</option>
          <option value="Pro Dr">Pro Dr</option>
          <option value="NMB">NMB</option>
        </select>
      </div>

      <div class="cc-field cc-conditional" id="cc-upload-field">
        <?php if (!$connected): ?>
        <div class="cc-notice warn">
          <strong>DotLoop not connected.</strong> Connect your DotLoop account first so the check can be added to your transaction.
          <br><a href="dotloop_connect.php" style="color:#92400e;font-weight:600">Connect DotLoop →</a>
        </div>
        <?php endif; ?>
        <label class="cc-label" for="check_file">Check Image <span>*</span></label>
        <input class="cc-file" type="file" id="check_file" name="check_file" accept=".pdf,.jpg,.jpeg,.png">
        <div class="cc-hint">PDF or image, max 20 MB</div>
      </div>

      <div class="cc-loader" id="cc-loader">
        <span class="spinner"></span> Submitting — please wait…
      </div>
      <div class="cc-result" id="cc-result"></div>

      <button class="cc-btn" type="submit" id="cc-btn">Submit</button>
    </form>
  </div>
</div>
</main>

<script>
(function () {
  // Load loops into the dropdown (same endpoint the HUD/Check submission page uses).
  fetch('api/hud_loops.php')
    .then(r => r.json())
    .then(d => {
      document.getElementById('loops-loading').style.display = 'none';
      const sel = document.getElementById('loop_id');
      if (d.error === 'not_connected') {
        document.getElementById('loops-loading').outerHTML =
          '<div class="cc-notice warn"><strong>DotLoop not connected.</strong> Connect your DotLoop account so your transactions can be listed here.'
          + '<br><a href="dotloop_connect.php" style="color:#92400e;font-weight:600">Connect DotLoop →</a></div>';
        return;
      }
      if (!d.ok || !d.loops || d.loops.length === 0) {
        sel.innerHTML = '<option value="">— no active transactions found —</option>';
      } else {
        d.loops.forEach(l => {
          const label = l.name + (l.address ? ' — ' + l.address : '') + ' [' + l.status + ']';
          const opt = new Option(label, l.id);
          opt.dataset.name = l.name;
          sel.appendChild(opt);
        });
      }
      document.getElementById('cc-form').style.display = '';
    })
    .catch(() => {
      document.getElementById('loops-loading').innerHTML =
        '<span style="color:#dc2626">Could not load transactions. Refresh to try again.</span>';
    });

  document.getElementById('loop_id').addEventListener('change', function () {
    const opt = this.options[this.selectedIndex];
    document.getElementById('loop_name').value = opt.dataset.name || opt.text || '';
  });

  const officeField = document.getElementById('cc-office-field');
  const uploadField = document.getElementById('cc-upload-field');
  const officeSelect = document.getElementById('office_location');
  const checkFile     = document.getElementById('check_file');

  document.querySelectorAll('input[name="method"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
      document.querySelectorAll('.cc-method').forEach(function (l) { l.classList.remove('cc-selected'); });
      this.closest('.cc-method').classList.add('cc-selected');

      officeField.classList.remove('cc-show');
      uploadField.classList.remove('cc-show');
      officeSelect.required = false;
      checkFile.required = false;

      if (this.value === 'dropoff') {
        officeField.classList.add('cc-show');
        officeSelect.required = true;
      } else if (this.value === 'upload') {
        uploadField.classList.add('cc-show');
        checkFile.required = true;
      }
    });
  });

  document.getElementById('cc-form').addEventListener('submit', function (e) {
    e.preventDefault();
    const btn    = document.getElementById('cc-btn');
    const loader = document.getElementById('cc-loader');
    const result = document.getElementById('cc-result');

    btn.disabled = true;
    loader.style.display = 'block';
    result.style.display = 'none';

    const fd = new FormData(this);
    fetch('api/commission_action.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(d => {
        loader.style.display = 'none';
        result.style.display = 'block';
        if (d.ok) {
          let msg = '<strong>✅ Submitted successfully!</strong><br>Michele has been notified.';
          if (fd.get('method') === 'upload') {
            msg += d.dotloop_ok
              ? ' Check uploaded to DotLoop.'
              : ' ⚠️ Could not upload to DotLoop automatically. ' + (d.notes || []).join(' ');
          }
          result.innerHTML = '<div class="cc-notice ok">' + msg + '</div>';
          document.getElementById('cc-form').reset();
          document.querySelectorAll('.cc-method').forEach(function (l) { l.classList.remove('cc-selected'); });
          officeField.classList.remove('cc-show');
          uploadField.classList.remove('cc-show');
          btn.disabled = false;
        } else {
          result.innerHTML = '<div class="cc-notice err"><strong>Error:</strong> ' + (d.error || 'Unknown error') + '</div>';
          btn.disabled = false;
        }
      })
      .catch(() => {
        loader.style.display = 'none';
        result.style.display = 'block';
        result.innerHTML = '<div class="cc-notice err">Network error — please try again.</div>';
        btn.disabled = false;
      });
  });
}());
</script>
</body>
</html>
