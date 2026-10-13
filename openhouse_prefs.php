<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/oh_subnav.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/notifications.php';

$agent = require_login();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }

$db      = local_db();
$admin   = is_admin();
$myEmail = strtolower(trim($agent['email']));

// Load current admin-only prefs
$prefsQ = $db->query("SELECT key, value FROM oh_prefs")->fetchAll(PDO::FETCH_KEY_PAIR);
$allowOverlap = (int)($prefsQ['allow_overlap'] ?? 0);
$maxPerSlot   = (int)($prefsQ['max_per_slot']  ?? 1);
if ($maxPerSlot < 1 || $maxPerSlot > 20) $maxPerSlot = 1;

// Load this agent's own notification toggles
$notifyPrefs = oh_notify_prefs_get($myEmail);

$saved = false;
$notifySaved = false;

// Handle form submits (falls back to JS-free POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_form_submit']) && $admin) {
    $allowOverlap = (int)!empty($_POST['allow_overlap']) ? 1 : 0;
    $maxPerSlot   = max(1, min(20, (int)($_POST['max_per_slot'] ?? 1)));
    $ups = $db->prepare("INSERT INTO oh_prefs (key, value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
    $ups->execute(['allow_overlap', (string)$allowOverlap]);
    $ups->execute(['max_per_slot',  (string)$maxPerSlot]);
    $saved = true;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_notify_form_submit'])) {
    $notifyPrefs = [
        'notify_requested'      => !empty($_POST['notify_requested'])      ? 1 : 0,
        'notify_approved'       => !empty($_POST['notify_approved'])       ? 1 : 0,
        'notify_cancelled'      => !empty($_POST['notify_cancelled'])      ? 1 : 0,
        'auto_feedback_request' => !empty($_POST['auto_feedback_request']) ? 1 : 0,
    ];
    oh_notify_prefs_save(
        $myEmail,
        (bool)$notifyPrefs['notify_requested'],
        (bool)$notifyPrefs['notify_approved'],
        (bool)$notifyPrefs['notify_cancelled'],
        (bool)$notifyPrefs['auto_feedback_request']
    );
    $notifySaved = true;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Open House Preferences — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
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
      <?php if ($notifySaved): ?>
        <div style="padding:10px 14px;background:#eef5e8;border:1px solid #c3dfa8;border-radius:6px;color:#3a6b1a;font-size:13px;margin-bottom:16px">Notification settings saved.</div>
      <?php endif; ?>

      <div class="card" style="max-width:520px;margin-bottom:20px">
        <h2 style="font-size:15px;font-weight:800;margin:0 0 6px">Notification Settings</h2>
        <p style="font-size:13px;color:#888;margin:0 0 20px">Choose which Open House emails you receive.</p>

        <form method="post" id="notify-form">
          <input type="hidden" name="_notify_form_submit" value="1">

          <div style="margin-bottom:14px;padding:16px;background:#f9f9f9;border:1px solid #eee;border-radius:8px">
            <label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer">
              <input type="checkbox" name="notify_requested" value="1" id="notify-requested"
                     style="margin-top:3px;width:16px;height:16px;flex:none"
                     <?= $notifyPrefs['notify_requested'] ? 'checked' : '' ?>>
              <div>
                <div style="font-size:14px;font-weight:700">Someone requests my listing</div>
                <div style="font-size:12px;color:#888;margin-top:3px">
                  Email me when another agent requests to hold an open house on one of my listings.
                </div>
              </div>
            </label>
          </div>

          <div style="margin-bottom:14px;padding:16px;background:#f9f9f9;border:1px solid #eee;border-radius:8px">
            <label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer">
              <input type="checkbox" name="notify_approved" value="1" id="notify-approved"
                     style="margin-top:3px;width:16px;height:16px;flex:none"
                     <?= $notifyPrefs['notify_approved'] ? 'checked' : '' ?>>
              <div>
                <div style="font-size:14px;font-weight:700">My request is approved</div>
                <div style="font-size:12px;color:#888;margin-top:3px">
                  Email me when a listing agent approves a request I made.
                </div>
              </div>
            </label>
          </div>

          <div style="margin-bottom:14px;padding:16px;background:#f9f9f9;border:1px solid #eee;border-radius:8px">
            <label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer">
              <input type="checkbox" name="notify_cancelled" value="1" id="notify-cancelled"
                     style="margin-top:3px;width:16px;height:16px;flex:none"
                     <?= $notifyPrefs['notify_cancelled'] ? 'checked' : '' ?>>
              <div>
                <div style="font-size:14px;font-weight:700">A request on my listing is cancelled</div>
                <div style="font-size:12px;color:#888;margin-top:3px">
                  Email me when an agent cancels their pending request on one of my listings.
                </div>
              </div>
            </label>
          </div>

          <div style="margin-bottom:24px;padding:16px;background:#f9f9f9;border:1px solid #eee;border-radius:8px">
            <label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer">
              <input type="checkbox" name="auto_feedback_request" value="1" id="auto-feedback-request"
                     style="margin-top:3px;width:16px;height:16px;flex:none"
                     <?= $notifyPrefs['auto_feedback_request'] ? 'checked' : '' ?>>
              <div>
                <div style="font-size:14px;font-weight:700">Send feedback request after open house time</div>
                <div style="font-size:12px;color:#888;margin-top:3px">
                  Automatically email the hosting agent asking for feedback once an approved open house's
                  scheduled time has passed, instead of asking for it yourself from My Listings.
                </div>
              </div>
            </label>
          </div>

          <div class="form-actions">
            <button type="submit" class="btn-save" id="notify-save-btn">Save Notification Settings</button>
            <div id="notify-save-msg" style="font-size:13px"></div>
          </div>
        </form>
      </div>

      <?php if ($admin): ?>
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
      <?php endif; ?>
    </main>
  </div>
</div>
<script>
document.getElementById('notify-form').addEventListener('submit', function(e) {
  e.preventDefault();
  const btn   = document.getElementById('notify-save-btn');
  const msgEl = document.getElementById('notify-save-msg');
  const fd = new FormData();
  fd.append('action',                'save_oh_notify_prefs');
  fd.append('notify_requested',      document.getElementById('notify-requested').checked ? '1' : '0');
  fd.append('notify_approved',       document.getElementById('notify-approved').checked  ? '1' : '0');
  fd.append('notify_cancelled',      document.getElementById('notify-cancelled').checked ? '1' : '0');
  fd.append('auto_feedback_request', document.getElementById('auto-feedback-request').checked ? '1' : '0');

  btn.disabled = true;
  btn.textContent = 'Saving…';

  fetch('api/oh_action.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      btn.disabled = false;
      btn.textContent = 'Save Notification Settings';
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
      btn.textContent = 'Save Notification Settings';
      msgEl.textContent = 'Network error.';
      msgEl.style.color = '#c00';
    });
});

<?php if ($admin): ?>
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
<?php endif; ?>
</script>
</body>
</html>
