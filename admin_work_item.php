<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/admin_work_items.php';
require_once __DIR__ . '/lib/feature_flags.php';

$agent = require_login();
require_admin_page();
if (!feature_enabled_for_current_user('admin_work_os')) { header('Location: index.php'); exit; }

$me = strtolower(trim($agent['email'] ?? ''));
$id = (int)($_GET['id'] ?? 0);

$db   = local_db();
$stmt = $db->prepare("SELECT * FROM admin_work_items WHERE id = ? AND LOWER(owner_email) = LOWER(?) AND deleted_at IS NULL");
$stmt->execute([$id, $me]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

// Same denial for "doesn't exist" and "not yours" -- no signal either way.
if (!$item) { header('Location: admin_work_os.php'); exit; }

// Activity — read-only, newest first. actor_email is intentionally not
// shown: in this owner-scoped, no-delegation world it's always the task's
// own owner, so displaying it would be noise, not information.
$eventStmt = $db->prepare("SELECT event_type, detail, created_at FROM admin_work_item_events WHERE item_id = ? ORDER BY id DESC");
$eventStmt->execute([$id]);
$events = $eventStmt->fetchAll(PDO::FETCH_ASSOC);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($item['title']) ?> — Admin Work OS — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .awi-back{font-size:12px;color:var(--faint);text-decoration:none}
    .awi-back:hover{text-decoration:underline}
    .awi-panel{background:#fff;border:1px solid var(--border);border-radius:12px;padding:20px;margin-top:14px;max-width:640px}
    .awi-field{margin-bottom:16px}
    .awi-field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);margin-bottom:5px}
    .awi-field input[type=text],.awi-field input[type=date],.awi-field select,.awi-field textarea{
      width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:14px;font-family:inherit;box-sizing:border-box}
    .awi-field textarea{min-height:90px;resize:vertical}
    .awi-actions{display:flex;align-items:center;gap:10px;margin-top:18px}
    .awi-save{padding:8px 18px;border:none;border-radius:6px;background:#111;color:#fff;font-size:13px;font-weight:700;cursor:pointer}
    .awi-save:disabled{opacity:.5;cursor:default}
    .awi-msg{font-size:12px}
    .awi-msg.ok{color:#3a6b1a}
    .awi-msg.err{color:#c00}
    .awi-status-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding-top:16px;margin-top:4px;border-top:1px solid var(--border)}
    .awi-status-row select{padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px}
    .awi-done-btn{padding:7px 16px;border:1px solid #2e7d32;border-radius:6px;background:#e8f5e9;color:#2e7d32;font-size:13px;font-weight:700;cursor:pointer}
    .awi-reopen-btn{padding:7px 16px;border:1px solid var(--border);border-radius:6px;background:#fff;color:#333;font-size:13px;font-weight:700;cursor:pointer}
    .awi-done-banner{display:flex;align-items:center;justify-content:space-between;gap:10px;padding-top:16px;margin-top:4px;border-top:1px solid var(--border)}
    .awi-done-badge{font-size:13px;font-weight:700;color:#2e7d32}
    .awi-done-meta{font-size:12px;color:var(--faint);margin-top:2px}
    .awi-waiting-fields{padding-top:16px;margin-top:4px;border-top:1px solid var(--border)}
    .awi-activity-panel{background:#fff;border:1px solid var(--border);border-radius:12px;margin-top:20px;max-width:640px;overflow:hidden}
    .awi-activity-head{padding:12px 18px;border-bottom:1px solid var(--border);font-size:13px;font-weight:700}
    .awi-event{padding:10px 18px;border-top:1px solid var(--border)}
    .awi-event:first-of-type{border-top:none}
    .awi-event-detail{font-size:13px}
    .awi-event-time{font-size:11px;color:var(--faint);margin-top:2px}
    .awi-danger-zone{border-top:1px solid var(--border);padding-top:14px;margin-top:16px}
    .awi-delete-btn{padding:7px 14px;border:1px solid #f5c6c0;border-radius:6px;background:#fff;color:var(--red);font-size:12px;font-weight:700;cursor:pointer}
    .awi-delete-btn:hover{background:#fdecea}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('admin_work_os', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)">Admin OS</div>
        <div class="content-title"><a class="awi-back" href="admin_work_os.php">&larr; Work Dashboard</a></div>
      </div>
    </header>
    <main class="wrap">
      <div class="awi-panel">
        <form id="awi-form" onsubmit="return false;">
          <div class="awi-field">
            <label for="awi-title">Title</label>
            <input type="text" id="awi-title" value="<?= h($item['title']) ?>">
          </div>
          <div class="awi-field">
            <label for="awi-desc">Description</label>
            <textarea id="awi-desc"><?= h($item['description'] ?? '') ?></textarea>
          </div>
          <div class="awi-field">
            <label for="awi-category">Category</label>
            <select id="awi-category">
              <?php foreach (ADMIN_WORK_CATEGORIES as $c): ?>
              <option value="<?= h($c) ?>" <?= $c === $item['category'] ? 'selected' : '' ?>><?= h(awos_category_label($c)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="awi-field">
            <label for="awi-due">Due date</label>
            <input type="date" id="awi-due" value="<?= h($item['due_date'] ?? '') ?>">
          </div>
          <?php if ($item['status'] === 'waiting'): ?>
          <div class="awi-waiting-fields">
            <div class="awi-field">
              <label for="awi-waiting-on">Waiting on</label>
              <input type="text" id="awi-waiting-on" value="<?= h($item['waiting_on'] ?? '') ?>">
            </div>
            <div class="awi-field" style="margin-bottom:0">
              <label for="awi-followup">Follow up</label>
              <input type="date" id="awi-followup" value="<?= h($item['follow_up_date'] ?? '') ?>">
            </div>
          </div>
          <?php endif; ?>
          <div class="awi-actions">
            <button type="button" class="awi-save" id="awi-save-btn" onclick="awiSaveDetails()">Save Details</button>
            <span class="awi-msg" id="awi-save-msg"></span>
          </div>
        </form>

        <?php if ($item['status'] === 'done'): ?>
        <div class="awi-done-banner">
          <div>
            <div class="awi-done-badge">&#10003; Done</div>
            <div class="awi-done-meta">Completed <?= h(fmt_dt_et($item['completed_at'])) ?></div>
          </div>
          <div>
            <button type="button" class="awi-reopen-btn" onclick="awiReopen()">Reopen</button>
            <span class="awi-msg" id="awi-status-msg"></span>
          </div>
        </div>
        <?php else: ?>
        <div class="awi-status-row">
          <label for="awi-status" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint)">Status</label>
          <select id="awi-status" onchange="awiChangeStatus(this.value)">
            <?php foreach (['inbox', 'next', 'waiting'] as $s): ?>
            <option value="<?= h($s) ?>" <?= $s === $item['status'] ? 'selected' : '' ?>><?= h(awos_status_label($s)) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="button" class="awi-done-btn" onclick="awiChangeStatus('done')">Mark Done</button>
          <span class="awi-msg" id="awi-status-msg"></span>
        </div>
        <?php endif; ?>

        <div class="awi-danger-zone">
          <button type="button" class="awi-delete-btn" id="awi-delete-btn" onclick="awiDelete()">Delete Task</button>
          <span class="awi-msg" id="awi-delete-msg"></span>
        </div>
      </div>

      <div class="awi-activity-panel">
        <div class="awi-activity-head">Activity</div>
        <?php foreach ($events as $e): ?>
        <div class="awi-event">
          <div class="awi-event-detail"><?= h($e['detail']) ?></div>
          <div class="awi-event-time"><?= h(fmt_dt_et($e['created_at'], 'M j, Y · g:i A')) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </main>
  </div>
</div>
<script>
const AWI_ID = <?= (int)$item['id'] ?>;

function awiPost(action, extra) {
  return fetch('api/admin_work_item_action.php', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(Object.assign({ action: action, id: AWI_ID, csrf: window.AE_CSRF || '' }, extra)),
  }).then(r => r.json());
}

function awiSaveDetails() {
  const btn = document.getElementById('awi-save-btn');
  const msg = document.getElementById('awi-save-msg');
  btn.disabled = true;
  msg.textContent = 'Saving…'; msg.className = 'awi-msg';

  const payload = {
    title: document.getElementById('awi-title').value,
    description: document.getElementById('awi-desc').value,
    category: document.getElementById('awi-category').value,
    due_date: document.getElementById('awi-due').value,
  };
  // Waiting on / Follow up only exist in the DOM while status is Waiting --
  // include them only when present, rather than assuming they're always there.
  const waitingOnEl = document.getElementById('awi-waiting-on');
  const followupEl  = document.getElementById('awi-followup');
  if (waitingOnEl) payload.waiting_on = waitingOnEl.value;
  if (followupEl)  payload.follow_up_date = followupEl.value;

  awiPost('update', payload).then(d => {
    if (d.ok) {
      msg.textContent = 'Saved'; msg.className = 'awi-msg ok';
      setTimeout(() => location.reload(), 500);
    } else {
      msg.textContent = d.error || 'Save failed'; msg.className = 'awi-msg err';
      btn.disabled = false;
    }
  }).catch(() => {
    msg.textContent = 'Network error'; msg.className = 'awi-msg err';
    btn.disabled = false;
  });
}

function awiChangeStatus(newStatus) {
  const msg = document.getElementById('awi-status-msg');
  msg.textContent = 'Updating…'; msg.className = 'awi-msg';
  awiPost('status', { status: newStatus }).then(d => {
    if (d.ok) {
      location.reload();
    } else {
      msg.textContent = d.error || 'Update failed'; msg.className = 'awi-msg err';
    }
  }).catch(() => {
    msg.textContent = 'Network error'; msg.className = 'awi-msg err';
  });
}

function awiReopen() { awiChangeStatus('next'); }

function awiDelete() {
  if (!confirm('Delete this task?\n\nThis will remove it from your Work OS. Recurring routines will continue on their future schedule.')) return;
  const btn = document.getElementById('awi-delete-btn');
  const msg = document.getElementById('awi-delete-msg');
  btn.disabled = true;
  msg.textContent = 'Deleting…'; msg.className = 'awi-msg';
  awiPost('delete', {}).then(d => {
    if (d.ok) {
      location.href = 'admin_work_os.php';
    } else {
      msg.textContent = d.error || 'Delete failed'; msg.className = 'awi-msg err';
      btn.disabled = false;
    }
  }).catch(() => {
    msg.textContent = 'Network error'; msg.className = 'awi-msg err';
    btn.disabled = false;
  });
}
</script>
</body>
</html>
