<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/oh_subnav.php';
require_once __DIR__ . '/nav.php';

$agent = require_login();
if (!is_admin()) {
    header('Location: openhouse.php');
    exit;
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }

$db    = local_db();
$admin = true;

// Load current prefs
$prefsQ = $db->query("SELECT key, value FROM oh_prefs")->fetchAll(PDO::FETCH_KEY_PAIR);
$allowOverlap = (int)($prefsQ['allow_overlap'] ?? 0);
$maxPerSlot   = (int)($prefsQ['max_per_slot']  ?? 1);
if ($maxPerSlot < 1 || $maxPerSlot > 20) $maxPerSlot = 1;

$saved = false;
$saveErr = '';

// Handle form submit (falls back to JS-free POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_form_submit'])) {
    $allowOverlap = (int)!empty($_POST['allow_overlap']) ? 1 : 0;
    $maxPerSlot   = max(1, min(20, (int)($_POST['max_per_slot'] ?? 1)));
    $ups = $db->prepare("INSERT INTO oh_prefs (key, value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
    $ups->execute(['allow_overlap', (string)$allowOverlap]);
    $ups->execute(['max_per_slot',  (string)$maxPerSlot]);
    $saved = true;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Open House Preferences — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="layout">
  <?php render_sidebar('openhouse', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Open House Preferences</div>
    </header>
    <main class="wrap">
      <?php render_oh_subnav('prefs', $admin); ?>

      <?php if ($saved): ?>
        <div style="padding:10px 14px;background:#eef5e8;border:1px solid #c3dfa8;border-radius:6px;color:#3a6b1a;font-size:13px;margin-bottom:16px">Preferences saved.</div>
      <?php endif; ?>

      <div class="card" style="max-width:520px">
        <h2 style="font-size:15px;font-weight:800;margin:0 0 6px">Open House Pool Settings</h2>
        <p style="font-size:13px;color:#888;margin:0 0 20px">Configure how open house requests are managed.</p>

        <form method="post" id="prefs-form">
          <input type="hidden" name="_form_submit" value="1">

          <div style="margin-bottom:20px;padding:16px;background:#f9f9f9;border:1px solid #eee;border-radius:8px">
            <label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer">
              <input type="checkbox" name="allow_overlap" value="1" id="pref-overlap"
                     style="margin-top:3px;width:16px;height:16px;flex:none"
                     <?= $allowOverlap ? 'checked' : '' ?>>
              <div>
                <div style="font-size:14px;font-weight:700">Allow Overlap</div>
                <div style="font-size:12px;color:#888;margin-top:3px">
                  When enabled, multiple agents can be approved for the same time slot
                  regardless of the max per slot limit.
                </div>
              </div>
            </label>
          </div>

          <div style="margin-bottom:24px;padding:16px;background:#f9f9f9;border:1px solid #eee;border-radius:8px">
            <label style="display:flex;align-items:center;gap:12px">
              <div style="flex:1">
                <div style="font-size:14px;font-weight:700">Max Agents per Slot</div>
                <div style="font-size:12px;color:#888;margin-top:3px">
                  Maximum number of approved agents for a single time slot (1–20).
                  Ignored if Allow Overlap is enabled.
                </div>
              </div>
              <input type="number" name="max_per_slot" id="pref-max" min="1" max="20"
                     value="<?= h((string)$maxPerSlot) ?>"
                     style="width:70px;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:15px;font-weight:700;text-align:center">
            </label>
          </div>

          <div class="form-actions">
            <button type="submit" class="btn-save" id="save-btn">Save Preferences</button>
            <div id="save-msg" style="font-size:13px"></div>
          </div>
        </form>
      </div>
    </main>
  </div>
</div>
<script>
// Intercept with fetch for a nicer save experience
document.getElementById('prefs-form').addEventListener('submit', function(e) {
  e.preventDefault();
  const btn    = document.getElementById('save-btn');
  const msgEl  = document.getElementById('save-msg');
  const fd = new FormData();
  fd.append('action',        'save_prefs');
  fd.append('allow_overlap', document.getElementById('pref-overlap').checked ? '1' : '0');
  fd.append('max_per_slot',  document.getElementById('pref-max').value);

  btn.disabled = true;
  btn.textContent = 'Saving…';

  fetch('api/oh_action.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      btn.disabled = false;
      btn.textContent = 'Save Preferences';
      if (d.ok) {
        msgEl.textContent = 'Saved!';
        msgEl.style.color = '#3a6b1a';
        setTimeout(() => { msgEl.textContent = ''; }, 2500);
      } else {
        msgEl.textContent = d.error || 'Error saving.';
        msgEl.style.color = '#c00';
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.textContent = 'Save Preferences';
      msgEl.textContent = 'Network error.';
      msgEl.style.color = '#c00';
    });
});
</script>
</body>
</html>
