<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/lib/notifications.php';
$agent = require_login();
if (!is_admin()) { header('Location: index.php'); exit; }

$db  = local_db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'set_enabled') {
        $eventKey = trim($_POST['event_key'] ?? '');
        $enabled  = ($_POST['enabled'] ?? '1') === '1' ? 1 : 0;
        if ($eventKey !== '') {
            $exists = $db->prepare("SELECT 1 FROM notification_triggers WHERE event_key=?");
            $exists->execute([$eventKey]);
            if ($exists->fetch()) {
                $db->prepare("UPDATE notification_triggers SET enabled=? WHERE event_key=?")->execute([$enabled, $eventKey]);
            } else {
                $db->prepare("INSERT INTO notification_triggers (event_key, enabled) VALUES (?,?)")->execute([$eventKey, $enabled]);
            }
            $msg = $enabled ? 'Trigger enabled.' : 'Trigger disabled.';
        }
    } elseif ($action === 'add_recipient') {
        $eventKey = trim($_POST['event_key'] ?? '');
        $recipientType = ($_POST['recipient_type'] ?? 'email') === 'role' ? 'role' : 'email';
        $value = trim($_POST['value'] ?? '');

        if ($eventKey === '') {
            $msg = 'Missing trigger.';
        } elseif ($recipientType === 'role') {
            if ($value !== 'agent' && isset(ROLE_LABELS[$value])) {
                $db->prepare("INSERT OR IGNORE INTO notification_trigger_recipients (event_key, email, recipient_type) VALUES (?,?,'role')")->execute([$eventKey, $value]);
                $msg = 'Added role: ' . ROLE_LABELS[$value] . '.';
            } else {
                $msg = 'Pick a valid role.';
            }
        } else {
            $value = strtolower($value);
            if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $db->prepare("INSERT OR IGNORE INTO notification_trigger_recipients (event_key, email, recipient_type) VALUES (?,?,'email')")->execute([$eventKey, $value]);
                $msg = "Added $value.";
            } else {
                $msg = 'Enter a valid email.';
            }
        }
    } elseif ($action === 'remove_recipient') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM notification_trigger_recipients WHERE id=?")->execute([$id]);
        $msg = 'Recipient removed.';
    } elseif ($action === 'create_custom') {
        $eventKey    = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['event_key'] ?? '')));
        $label       = trim($_POST['label'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $groupLabel  = trim($_POST['group_label'] ?? '') ?: 'Custom';

        $builtinKeys = array_column(builtin_trigger_catalog(), 'event_key');
        if ($eventKey === '' || $label === '') {
            $msg = 'Key and label are required.';
        } elseif (in_array($eventKey, $builtinKeys, true)) {
            $msg = "\"$eventKey\" is already a built-in trigger key.";
        } else {
            $exists = $db->prepare("SELECT 1 FROM notification_triggers WHERE event_key=?");
            $exists->execute([$eventKey]);
            if ($exists->fetch()) {
                $msg = "A trigger with key \"$eventKey\" already exists.";
            } else {
                $db->prepare(
                    "INSERT INTO notification_triggers (event_key, label, description, group_label, is_custom, enabled) VALUES (?,?,?,?,1,1)"
                )->execute([$eventKey, $label, $description, $groupLabel]);
                $msg = "Custom trigger \"$label\" created. Nothing fires yet — a developer needs to call fire_custom_trigger('$eventKey', ...) from the code where this event happens.";
            }
        }
    } elseif ($action === 'delete_custom') {
        $eventKey = trim($_POST['event_key'] ?? '');
        $row = $db->prepare("SELECT label FROM notification_triggers WHERE event_key=? AND is_custom=1");
        $row->execute([$eventKey]);
        $label = $row->fetchColumn();
        if ($label !== false) {
            $db->prepare("DELETE FROM notification_triggers WHERE event_key=?")->execute([$eventKey]);
            $db->prepare("DELETE FROM notification_trigger_recipients WHERE event_key=?")->execute([$eventKey]);
            $msg = "Custom trigger \"$label\" deleted.";
        }
    }
}

// Merge built-in catalog + custom triggers into one list, grouped for display.
$triggers = array_merge(builtin_trigger_catalog(), array_map(fn($c) => $c + ['is_custom' => true], custom_trigger_catalog()));

$stateByKey = [];
foreach ($db->query("SELECT event_key, enabled FROM notification_triggers")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $stateByKey[$r['event_key']] = (bool)$r['enabled'];
}

$recipientsByKey = [];
foreach ($db->query("SELECT id, event_key, email, recipient_type FROM notification_trigger_recipients ORDER BY recipient_type, email")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $recipientsByKey[$r['event_key']][] = $r;
}

$grouped = [];
foreach ($triggers as $t) {
    $grouped[$t['group']][] = $t;
}

// Role options for the "add by role" picker — 'agent' excluded since a role
// recipient notifying literally every agent company-wide isn't a realistic
// use case for these ops/admin trigger emails.
$roleOptions = array_filter(ROLE_LABELS, fn($k) => $k !== 'agent', ARRAY_FILTER_USE_KEY);

// Name-search roster for the "add email" input — same pattern as
// admin_roles.php's ROSTER: CRM agent roster first, then any agent_roles
// staff (accountants, etc.) who aren't real estate agents and so never show
// up in innovate_roster.
$rosterByEmail = [];
foreach (ce_fetch_crm_roster() as $a) {
    $e = strtolower(trim($a['email'] ?? ''));
    if ($e) $rosterByEmail[$e] = ['name' => $a['fullName'] ?: $e, 'email' => $e];
}
foreach ($db->query("SELECT DISTINCT email FROM agent_roles")->fetchAll(PDO::FETCH_COLUMN) as $e) {
    $e = strtolower(trim($e));
    if ($e && !isset($rosterByEmail[$e])) $rosterByEmail[$e] = ['name' => $e, 'email' => $e];
}
$roster = array_values($rosterByEmail);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Notification Triggers — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .vd-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:20px}
    .vd-card h3{margin:0 0 4px;font-size:15px;font-weight:700}
    .vd-card h4{margin:18px 0 8px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#888}
    .vd-card .vd-sub{margin:0 0 14px;font-size:12px;color:#888}
    .btn{padding:7px 14px;border-radius:5px;border:1px solid #ccc;background:#fff;cursor:pointer;font-size:13px}
    .btn-green{background:#82C112;border-color:#5b8e0d;color:#fff;font-weight:600}
    .btn-danger{border-color:#e74c3c;color:#e74c3c}
    .btn-danger:hover{background:#e74c3c;color:#fff}
    .btn-sm{padding:3px 9px;font-size:11px}
    .msg{background:#f0fde8;border:1px solid #82C112;color:#3a6b00;border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:13px}
    .recip-chip{display:inline-flex;align-items:center;gap:6px;background:#e8f5d0;color:#3a6b00;border-radius:4px;padding:2px 4px 2px 8px;font-size:11px;font-weight:600;margin:2px}
    .recip-chip button{border:none;background:none;color:#3a6b00;cursor:pointer;font-size:13px;line-height:1;padding:2px 4px}
    .recip-chip-role{background:#eef2ff;color:#3949ab}
    .recip-chip-role button{color:#3949ab}
    .custom-badge{display:inline-block;background:#eef2ff;color:#3949ab;border-radius:4px;padding:2px 7px;font-size:11px;font-weight:600;margin-left:6px}
    .trigger-row{border-bottom:1px solid #f3f4f6;padding:12px 4px}
    .trigger-row:last-child{border-bottom:none}
    .trigger-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
    .trigger-label{font-weight:700;font-size:13.5px;color:#1a1a1a}
    .trigger-desc{font-size:12px;color:#888;margin-top:2px;max-width:640px}
    .toggle-form{display:flex;align-items:center;gap:6px;white-space:nowrap}
    .add-recip-row{display:flex;gap:16px;margin-top:8px;flex-wrap:wrap;align-items:flex-start}
    .add-recip-form{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
    .add-recip-form input,.add-recip-form select{padding:5px 9px;border:1px solid #ccc;border-radius:5px;font-size:12px;font-family:inherit}
    .add-recip-form input[type=text]{min-width:220px}
    .recip-search-wrap{position:relative}
    .recip-results{position:absolute;top:100%;left:0;z-index:20;background:#fff;border:1px solid #ddd;border-radius:5px;max-height:190px;overflow-y:auto;display:none;min-width:260px;box-shadow:0 4px 10px rgba(0,0,0,.1)}
    .recip-result-row{padding:6px 10px;font-size:12px;cursor:pointer}
    .recip-result-row:hover{background:#f9fdf5}
    .rr-email{color:#888;margin-left:6px}
    .form-row{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
    .form-row select,.form-row input{padding:6px 10px;border:1px solid #ccc;border-radius:5px;font-size:13px;font-family:inherit}
    label.fl{font-size:11px;font-weight:700;display:block;margin-bottom:3px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('admin_notification_triggers', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Notification Triggers</div>
      <div class="content-hello">Enable/disable AgentEdge's automated emails and add extra recipients to each</div>
    </header>
    <main class="wrap">
      <?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

      <div class="vd-card">
        <h3>How this works</h3>
        <p class="vd-sub" style="margin-bottom:0">
          Every row below is a real automated email already wired into AgentEdge (onboarding, offboarding, tickets, etc.).
          Turning one off stops that email entirely. Recipients you add here are <strong>additional</strong> —
          they're CC'd on top of whoever the trigger already notifies (a BIC, a Market Center Leader, the agent
          themself, etc.), never a replacement for it. Add a specific person by email (start typing a name to
          search), or add an entire role (e.g. every Broker in Charge) — role recipients always reflect who
          currently holds that role, so <a href="admin_roles.php">Role Assignments</a> changes apply automatically
          with nothing to update here. Onboarding/offboarding <em>step</em> assignments have their own dedicated
          page — see <a href="admin_step_notify.php">Step Notifications</a>.
        </p>
      </div>

      <?php foreach ($grouped as $groupName => $groupTriggers): ?>
        <div class="vd-card">
          <h3><?= htmlspecialchars($groupName) ?></h3>
          <?php foreach ($groupTriggers as $t): ?>
            <?php
              $key      = $t['event_key'];
              $enabled  = $stateByKey[$key] ?? true;
              $isCustom = !empty($t['is_custom']);
              $recips   = $recipientsByKey[$key] ?? [];
            ?>
            <div class="trigger-row">
              <div class="trigger-head">
                <div>
                  <span class="trigger-label"><?= htmlspecialchars($t['label']) ?></span>
                  <?php if ($isCustom): ?><span class="custom-badge">Custom — no code hook yet</span><?php endif; ?>
                  <div class="trigger-desc"><?= htmlspecialchars($t['description']) ?></div>
                </div>
                <div style="display:flex;align-items:center;gap:10px">
                  <form class="toggle-form" method="post">
                    <input type="hidden" name="action" value="set_enabled">
                    <input type="hidden" name="event_key" value="<?= htmlspecialchars($key) ?>">
                    <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
                    <button class="btn <?= $enabled ? '' : 'btn-danger' ?>" type="submit">
                      <?= $enabled ? 'Enabled — click to disable' : 'Disabled — click to enable' ?>
                    </button>
                  </form>
                  <?php if ($isCustom): ?>
                    <form method="post" onsubmit="return confirm('Delete this custom trigger and its recipients?')">
                      <input type="hidden" name="action" value="delete_custom">
                      <input type="hidden" name="event_key" value="<?= htmlspecialchars($key) ?>">
                      <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>

              <div style="margin-top:8px">
                <?php foreach ($recips as $r): ?>
                  <?php $isRole = ($r['recipient_type'] ?? 'email') === 'role'; ?>
                  <span class="recip-chip<?= $isRole ? ' recip-chip-role' : '' ?>">
                    <?= $isRole ? 'Role: ' . htmlspecialchars(ROLE_LABELS[$r['email']] ?? $r['email']) : htmlspecialchars($r['email']) ?>
                    <form method="post" style="display:inline">
                      <input type="hidden" name="action" value="remove_recipient">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button type="submit" title="Remove">&times;</button>
                    </form>
                  </span>
                <?php endforeach; ?>
                <?php if (!$recips): ?><span style="color:#aaa;font-size:12px">No extra recipients configured.</span><?php endif; ?>
              </div>

              <div class="add-recip-row">
                <form class="add-recip-form" method="post" autocomplete="off">
                  <input type="hidden" name="action" value="add_recipient">
                  <input type="hidden" name="event_key" value="<?= htmlspecialchars($key) ?>">
                  <input type="hidden" name="recipient_type" value="email">
                  <div class="recip-search-wrap">
                    <input type="text" name="value" placeholder="add email or search by name…" required
                           oninput="recipSearch(this)" onblur="setTimeout(()=>recipHideResults(this),150)">
                    <div class="recip-results"></div>
                  </div>
                  <button class="btn btn-sm" type="submit">Add</button>
                </form>
                <form class="add-recip-form" method="post">
                  <input type="hidden" name="action" value="add_recipient">
                  <input type="hidden" name="event_key" value="<?= htmlspecialchars($key) ?>">
                  <input type="hidden" name="recipient_type" value="role">
                  <select name="value" required>
                    <option value="">add by role…</option>
                    <?php foreach ($roleOptions as $rk => $rl): ?>
                      <option value="<?= htmlspecialchars($rk) ?>"><?= htmlspecialchars($rl) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-sm" type="submit">Add Role</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>

      <div class="vd-card">
        <h3>Create a Custom Trigger</h3>
        <p class="vd-sub">
          Defines a new named trigger (recipients only, enabled by default) with no built-in code hook.
          A developer wires it up later by calling <code>fire_custom_trigger('your_key', $subject, $body)</code>
          from wherever the real event happens in the app — creating it here does not make anything send.
        </p>
        <form method="post">
          <input type="hidden" name="action" value="create_custom">
          <div class="form-row">
            <div><label class="fl">Key (lowercase, no spaces)</label>
              <input name="event_key" required placeholder="e.g. deal_closed" style="width:200px"></div>
            <div><label class="fl">Label</label>
              <input name="label" required placeholder="e.g. Deal Closed" style="width:220px"></div>
            <div><label class="fl">Group</label>
              <input name="group_label" placeholder="e.g. Production" style="width:160px"></div>
            <div><label class="fl">Description</label>
              <input name="description" placeholder="What fires this, and who it's for" style="width:320px"></div>
            <button class="btn btn-green" type="submit">Create Trigger</button>
          </div>
        </form>
      </div>

    </main>
  </div>
</div>
<script>
const ROSTER = <?= json_encode($roster) ?>;

function recipSearch(input) {
  const wrap    = input.closest('.recip-search-wrap');
  const results = wrap.querySelector('.recip-results');
  const q = input.value.trim().toLowerCase();
  if (!q) { results.style.display = 'none'; results.innerHTML = ''; return; }
  const hits = ROSTER.filter(a => a.name.toLowerCase().includes(q) || a.email.includes(q)).slice(0, 8);
  if (!hits.length) { results.style.display = 'none'; results.innerHTML = ''; return; }
  results.innerHTML = hits.map(a =>
    `<div class="recip-result-row" data-email="${encodeURIComponent(a.email)}" onmousedown="recipSelect(this)">`
    + `${escRecip(a.name)}<span class="rr-email">${escRecip(a.email)}</span></div>`
  ).join('');
  results.style.display = 'block';
}

function recipSelect(el) {
  const wrap = el.closest('.recip-search-wrap');
  wrap.querySelector('input[type=text]').value = decodeURIComponent(el.dataset.email);
  wrap.querySelector('.recip-results').style.display = 'none';
}

function recipHideResults(input) {
  input.closest('.recip-search-wrap').querySelector('.recip-results').style.display = 'none';
}

function escRecip(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>
</body>
</html>
