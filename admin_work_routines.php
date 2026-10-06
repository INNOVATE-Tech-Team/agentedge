<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/admin_work_items.php';
require_once __DIR__ . '/lib/admin_work_routines.php';
require_once __DIR__ . '/lib/feature_flags.php';

$agent = require_login();
require_admin_page();
if (!feature_enabled_for_current_user('admin_work_os')) { header('Location: index.php'); exit; }

$me = strtolower(trim($agent['email'] ?? ''));
$db = local_db();

// V1D-A only: definitions live here. Nothing on this page ever creates a
// work item -- that's V1D-B, and even then only admin_work_os.php triggers it.
$stmt = $db->prepare("SELECT * FROM admin_work_routines WHERE LOWER(owner_email)=? ORDER BY created_at ASC");
$stmt->execute([$me]);
$routines = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

// Shared field markup for both the blank "Add Routine" form and each
// existing routine's expanded edit form -- $prefix makes every element id
// unique per instance ('new', or the routine's numeric id). Category/Area/
// Frequency sit in one responsive row, as do the schedule fields, so the
// form reads left-to-right on desktop and stacks cleanly on narrow screens.
function render_routine_fields(string $prefix, array $r): void {
    ?>
    <div class="awi-field">
      <label for="rt-title-<?= h($prefix) ?>">Task / Routine name</label>
      <input type="text" id="rt-title-<?= h($prefix) ?>" value="<?= h($r['title']) ?>">
    </div>

    <div class="rt-row">
      <div class="awi-field">
        <label for="rt-category-<?= h($prefix) ?>">Category</label>
        <select id="rt-category-<?= h($prefix) ?>">
          <?php foreach (ADMIN_WORK_CATEGORIES as $c): ?>
          <option value="<?= h($c) ?>" <?= $c === $r['category'] ? 'selected' : '' ?>><?= h(awos_category_label($c)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="awi-field">
        <label for="rt-area-<?= h($prefix) ?>">Workflow Area</label>
        <select id="rt-area-<?= h($prefix) ?>">
          <?php foreach (ADMIN_WORK_ROUTINE_AREAS as $a): ?>
          <option value="<?= h($a) ?>" <?= $a === $r['routine_area'] ? 'selected' : '' ?>><?= h(awos_routine_area_option_label($a)) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="awi-hint">Controls where this recurring task appears.</div>
      </div>
      <div class="awi-field">
        <label for="rt-freq-<?= h($prefix) ?>">Frequency</label>
        <select id="rt-freq-<?= h($prefix) ?>" onchange="rtUpdateScheduleFields('<?= h($prefix) ?>')">
          <?php foreach (ADMIN_WORK_ROUTINE_FREQUENCIES as $f): ?>
          <option value="<?= h($f) ?>" <?= $f === $r['frequency'] ? 'selected' : '' ?>><?= h(awos_routine_frequency_label($f)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="rt-row">
      <div class="awi-field" id="rt-field-weekday-<?= h($prefix) ?>">
        <label for="rt-weekday-<?= h($prefix) ?>">Day of week</label>
        <select id="rt-weekday-<?= h($prefix) ?>">
          <?php foreach (ADMIN_WORK_ROUTINE_WEEKDAY_NAMES as $n => $name): ?>
          <option value="<?= $n ?>" <?= $n === (int)($r['schedule_weekday'] ?? 0) ? 'selected' : '' ?>><?= h($name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="awi-field" id="rt-field-anchor-<?= h($prefix) ?>">
        <label for="rt-anchor-<?= h($prefix) ?>">Starting</label>
        <input type="date" id="rt-anchor-<?= h($prefix) ?>" value="<?= h((string)($r['schedule_anchor_date'] ?? '')) ?>">
      </div>
      <div class="awi-field" id="rt-field-monthday-<?= h($prefix) ?>">
        <label for="rt-monthday-<?= h($prefix) ?>">Day of month</label>
        <select id="rt-monthday-<?= h($prefix) ?>">
          <?php for ($d = 1; $d <= 31; $d++): ?>
          <option value="<?= $d ?>" <?= $d === (int)($r['schedule_day_of_month'] ?? 0) ? 'selected' : '' ?>><?= h(awos_ordinal($d)) ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="awi-field" id="rt-field-month-<?= h($prefix) ?>">
        <label for="rt-month-<?= h($prefix) ?>">Month</label>
        <select id="rt-month-<?= h($prefix) ?>">
          <?php foreach (ADMIN_WORK_ROUTINE_MONTH_NAMES as $n => $name): ?>
          <option value="<?= $n ?>" <?= $n === (int)($r['schedule_month'] ?? 0) ? 'selected' : '' ?>><?= h($name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="awi-field rt-customwd-field" id="rt-field-customwd-<?= h($prefix) ?>">
      <label>Days of week</label>
      <div class="rt-weekday-checks">
        <?php $selectedWeekdays = awos_decode_weekdays($r['schedule_weekdays'] ?? null); ?>
        <?php foreach (ADMIN_WORK_ROUTINE_WEEKDAY_NAMES as $n => $name): ?>
        <label class="rt-weekday-check">
          <input type="checkbox" id="rt-wd-<?= $n ?>-<?= h($prefix) ?>" value="<?= $n ?>" <?= in_array($n, $selectedWeekdays, true) ? 'checked' : '' ?>>
          <?= h(substr($name, 0, 3)) ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="awi-field">
      <label for="rt-desc-<?= h($prefix) ?>">Description (optional)</label>
      <textarea id="rt-desc-<?= h($prefix) ?>"><?= h($r['description'] ?? '') ?></textarea>
    </div>

    <div class="awi-field rt-enabled-field">
      <input type="checkbox" id="rt-enabled-<?= h($prefix) ?>" <?= !empty($r['enabled']) ? 'checked' : '' ?>>
      <label for="rt-enabled-<?= h($prefix) ?>" class="rt-enabled-label">Enabled</label>
    </div>
    <?php
}

$blank = [
    'title' => '', 'description' => '', 'category' => 'admin', 'routine_area' => 'general',
    'frequency' => 'daily', 'schedule_weekday' => 1, 'schedule_day_of_month' => 1, 'schedule_month' => 1,
    'schedule_weekdays' => null, 'schedule_anchor_date' => '',
    'enabled' => 1,
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Routines — Admin Work OS — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    /* This page benefits from more room than the shared 1100px .wrap gives
       most pages -- a settings/config list with repeated cards, not a
       single-record form -- so it's widened here only (a more specific
       selector in this page's own <style>, not a change to assets/app.css).
       width:100% is the actual fix, not max-width: .content is a column
       flex container with no explicit align-items, and .wrap's own
       margin:0 auto (auto cross-axis margins) overrides flex stretch --
       confirmed in a real headless-browser check, where .wrap rendered at
       ~736px (a content-driven shrink-to-fit width) with ~236px resolved
       auto-margins on each side, never approaching the max-width ceiling
       at all. Without an explicit width, raising max-width alone changes
       nothing, because the element was never trying to grow that big. */
    main.wrap{width:100%;max-width:1300px}
    .awos-subtitle{font-size:14px;color:var(--faint);margin:-6px 0 20px}
    .awos-section-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint);margin:28px 0 10px}
    .awi-panel{background:#fff;border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:14px;max-width:1300px}
    .awi-field{margin-bottom:16px}
    .awi-field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);margin-bottom:5px}
    .awi-field input[type=text],.awi-field select,.awi-field textarea{
      width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:14px;font-family:inherit;box-sizing:border-box}
    .awi-field textarea{min-height:60px;resize:vertical}
    .awi-hint{font-size:11px;color:var(--faint);margin-top:5px}
    .rt-row{display:flex;gap:14px;flex-wrap:wrap}
    .rt-row > .awi-field{flex:1 1 200px;min-width:0}
    .rt-enabled-field{display:flex;align-items:center;gap:8px;margin-bottom:8px}
    .rt-enabled-field input[type=checkbox]{width:auto}
    .rt-enabled-label{margin:0;text-transform:none;font-weight:600;font-size:13px;color:#333}
    .awi-actions{display:flex;align-items:center;gap:10px;margin-top:8px;flex-wrap:wrap}
    .awi-save{padding:8px 18px;border:none;border-radius:6px;background:#111;color:#fff;font-size:13px;font-weight:700;cursor:pointer}
    .awi-save:disabled{opacity:.5;cursor:default}
    .rt-toggle-btn,.rt-edit-btn,.rt-cancel-btn{padding:7px 16px;border:1px solid var(--border);border-radius:6px;background:#fff;color:#333;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap}
    .awi-msg{font-size:12px}
    .awi-msg.ok{color:#3a6b1a}
    .awi-msg.err{color:#c00}
    .rt-panel-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px}
    .rt-title-row{font-size:15px;font-weight:700}
    .rt-summary{font-size:12px;color:var(--faint);margin-top:2px}
    .rt-badge{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;padding:2px 8px;border-radius:10px;margin-left:8px;vertical-align:middle}
    .rt-badge-on{color:#2e7d32;background:#e8f5e9}
    .rt-badge-off{color:#999;background:#f0f0f0}
    .awos-empty{padding:24px 18px;text-align:center;color:var(--faint);font-size:13px}
    /* Collapsed routine tile */
    .rt-tile{background:#fff;border:1px solid var(--border);border-radius:12px;padding:16px 20px;margin-bottom:12px;max-width:1300px}
    .rt-tile-row{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
    .rt-tile-desc{font-size:13px;color:#555;margin-top:6px;max-width:820px}
    .rt-tile-actions{display:flex;gap:8px;flex-shrink:0}
    .rt-edit-form{margin-top:18px;padding-top:18px;border-top:1px solid var(--border)}
    .rt-edit-form[hidden]{display:none}
    .rt-add-toggle{padding:9px 18px;border:none;border-radius:8px;background:var(--green);color:#111;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap}
    .rt-add-toggle:hover,.rt-add-toggle:focus-visible{background:var(--green-d);color:#fff}
    .rt-weekday-checks{display:flex;gap:14px;flex-wrap:wrap}
    .rt-weekday-check{display:flex;align-items:center;gap:5px;font-size:13px;font-weight:600;color:#333;text-transform:none}
    .rt-weekday-check input[type=checkbox]{width:auto}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('admin_work_routines', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)">Admin OS</div>
        <div class="content-title">Routines</div>
      </div>
      <button type="button" class="rt-add-toggle" id="rt-add-toggle-btn" onclick="rtToggleAddForm()">+ Add Routine</button>
    </header>
    <main class="wrap">
      <div class="awos-subtitle">Set the work that should come back automatically.</div>

      <div class="awi-panel" id="rt-add-panel" hidden>
        <div class="rt-panel-head"><div class="rt-title-row">Add Routine</div></div>
        <form onsubmit="return false;">
          <?php render_routine_fields('new', $blank); ?>
          <div class="awi-actions">
            <button type="button" class="awi-save" id="rt-add-btn" onclick="rtCreate()">Add Routine</button>
            <span class="awi-msg" id="rt-msg-new"></span>
          </div>
        </form>
      </div>

      <div class="awos-section-label">Your Routines</div>

      <?php if (empty($routines)): ?>
      <div class="awos-empty">No routines yet — add one above.</div>
      <?php else: foreach ($routines as $r): $prefix = (string)$r['id']; ?>
      <div class="rt-tile" id="rt-tile-<?= h($prefix) ?>">
        <div class="rt-tile-row">
          <div>
            <div class="rt-title-row">
              <?= h($r['title']) ?>
              <?php if (!empty($r['enabled'])): ?>
              <span class="rt-badge rt-badge-on">Enabled</span>
              <?php else: ?>
              <span class="rt-badge rt-badge-off">Disabled</span>
              <?php endif; ?>
            </div>
            <div class="rt-summary"><?= h(awos_routine_schedule_summary($r)) ?> · <?= h(awos_routine_area_label($r['routine_area'])) ?> · <?= h(awos_category_label($r['category'])) ?></div>
            <?php if (!empty($r['description'])): ?>
            <div class="rt-tile-desc"><?= h($r['description']) ?></div>
            <?php endif; ?>
          </div>
          <div class="rt-tile-actions">
            <button type="button" class="rt-edit-btn" id="rt-edit-toggle-<?= h($prefix) ?>" onclick="rtToggleEdit(<?= (int)$r['id'] ?>)">Edit</button>
            <?php if (!empty($r['enabled'])): ?>
            <button type="button" class="rt-toggle-btn" onclick="rtToggle(<?= (int)$r['id'] ?>, false)">Disable</button>
            <?php else: ?>
            <button type="button" class="rt-toggle-btn" onclick="rtToggle(<?= (int)$r['id'] ?>, true)">Enable</button>
            <?php endif; ?>
          </div>
        </div>
        <div class="rt-edit-form" id="rt-edit-<?= h($prefix) ?>" hidden>
          <form onsubmit="return false;">
            <?php render_routine_fields($prefix, $r); ?>
            <div class="awi-actions">
              <button type="button" class="awi-save" id="rt-save-btn-<?= h($prefix) ?>" onclick="rtSave(<?= (int)$r['id'] ?>)">Save Changes</button>
              <button type="button" class="rt-cancel-btn" onclick="rtCancelEdit(<?= (int)$r['id'] ?>)">Cancel</button>
              <span class="awi-msg" id="rt-msg-<?= h($prefix) ?>"></span>
            </div>
          </form>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </main>
  </div>
</div>
<script>
const RT_SCHEDULE_FIELDS = {
  daily: [], weekdays: [], weekly: ['weekday'], biweekly: ['weekday', 'anchor'],
  custom_weekdays: ['customwd'], monthly: ['monthday'],
  semiannual: ['monthday', 'month'], annual: ['monthday', 'month'],
};

const RT_BLANK_PAYLOAD = {
  title: '', description: '', category: 'admin', routine_area: 'general', frequency: 'daily',
  schedule_weekday: '1', schedule_day_of_month: '1', schedule_month: '1',
  schedule_weekdays: [], schedule_anchor_date: '', enabled: true,
};

function rtUpdateScheduleFields(prefix) {
  const freqEl = document.getElementById('rt-freq-' + prefix);
  if (!freqEl) return;
  const relevant = RT_SCHEDULE_FIELDS[freqEl.value] || [];
  ['weekday', 'monthday', 'month', 'anchor', 'customwd'].forEach(function (f) {
    const el = document.getElementById('rt-field-' + f + '-' + prefix);
    if (el) el.style.display = relevant.indexOf(f) === -1 ? 'none' : '';
  });
}

function rtCollectPayload(prefix) {
  const weekdays = [];
  for (let n = 1; n <= 7; n++) {
    const cb = document.getElementById('rt-wd-' + n + '-' + prefix);
    if (cb && cb.checked) weekdays.push(n);
  }
  return {
    title: document.getElementById('rt-title-' + prefix).value,
    description: document.getElementById('rt-desc-' + prefix).value,
    category: document.getElementById('rt-category-' + prefix).value,
    routine_area: document.getElementById('rt-area-' + prefix).value,
    frequency: document.getElementById('rt-freq-' + prefix).value,
    schedule_weekday: document.getElementById('rt-weekday-' + prefix).value,
    schedule_day_of_month: document.getElementById('rt-monthday-' + prefix).value,
    schedule_month: document.getElementById('rt-month-' + prefix).value,
    schedule_weekdays: weekdays,
    schedule_anchor_date: document.getElementById('rt-anchor-' + prefix).value,
    enabled: document.getElementById('rt-enabled-' + prefix).checked,
    csrf: window.AE_CSRF || '',
  };
}

function rtApplyPayload(prefix, v) {
  document.getElementById('rt-title-' + prefix).value = v.title;
  document.getElementById('rt-desc-' + prefix).value = v.description;
  document.getElementById('rt-category-' + prefix).value = v.category;
  document.getElementById('rt-area-' + prefix).value = v.routine_area;
  document.getElementById('rt-freq-' + prefix).value = v.frequency;
  document.getElementById('rt-weekday-' + prefix).value = v.schedule_weekday;
  document.getElementById('rt-monthday-' + prefix).value = v.schedule_day_of_month;
  document.getElementById('rt-month-' + prefix).value = v.schedule_month;
  document.getElementById('rt-anchor-' + prefix).value = v.schedule_anchor_date || '';
  const selectedWeekdays = (v.schedule_weekdays || []).map(String);
  for (let n = 1; n <= 7; n++) {
    const cb = document.getElementById('rt-wd-' + n + '-' + prefix);
    if (cb) cb.checked = selectedWeekdays.indexOf(String(n)) !== -1;
  }
  document.getElementById('rt-enabled-' + prefix).checked = v.enabled;
  rtUpdateScheduleFields(prefix);
}

function rtResetAddForm() {
  rtApplyPayload('new', RT_BLANK_PAYLOAD);
  document.getElementById('rt-msg-new').textContent = '';
}

function rtToggleAddForm() {
  const panel = document.getElementById('rt-add-panel');
  const btn = document.getElementById('rt-add-toggle-btn');
  const opening = panel.hidden;
  if (!opening) rtResetAddForm(); // closing via Cancel -- clear before hiding, never send on close
  panel.hidden = !opening;
  btn.textContent = opening ? 'Cancel' : '+ Add Routine';
}

function rtPost(action, payload) {
  return fetch('api/admin_work_routine_action.php', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(Object.assign({ action: action }, payload)),
  }).then(function (r) { return r.json(); });
}

function rtCreate() {
  const btn = document.getElementById('rt-add-btn');
  const msg = document.getElementById('rt-msg-new');
  btn.disabled = true;
  msg.textContent = 'Adding…'; msg.className = 'awi-msg';
  rtPost('create', rtCollectPayload('new')).then(function (d) {
    if (d.ok) {
      msg.textContent = 'Added'; msg.className = 'awi-msg ok';
      location.reload();
    } else {
      msg.textContent = d.error || 'Could not add'; msg.className = 'awi-msg err';
      btn.disabled = false;
    }
  }).catch(function () {
    msg.textContent = 'Network error'; msg.className = 'awi-msg err';
    btn.disabled = false;
  });
}

function rtSave(id) {
  const prefix = String(id);
  const btn = document.getElementById('rt-save-btn-' + prefix);
  const msg = document.getElementById('rt-msg-' + prefix);
  btn.disabled = true;
  msg.textContent = 'Saving…'; msg.className = 'awi-msg';
  const payload = rtCollectPayload(prefix);
  payload.id = id;
  rtPost('update', payload).then(function (d) {
    if (d.ok) {
      msg.textContent = 'Saved'; msg.className = 'awi-msg ok';
      location.reload();
    } else {
      msg.textContent = d.error || 'Save failed'; msg.className = 'awi-msg err';
      btn.disabled = false;
    }
  }).catch(function () {
    msg.textContent = 'Network error'; msg.className = 'awi-msg err';
    btn.disabled = false;
  });
}

function rtToggle(id, enabled) {
  rtPost('toggle', { id: id, enabled: enabled, csrf: window.AE_CSRF || '' }).then(function (d) {
    if (d.ok) location.reload();
  });
}

// Only one routine's edit form open at a time. Original field values are
// captured the moment a tile expands, so Cancel (or switching to edit a
// different routine) can restore them without a reload -- nothing is ever
// sent to the server unless Save Changes is actually clicked.
let rtExpandedId = null;
const rtOriginalValues = {};

function rtCollapse(id) {
  const prefix = String(id);
  if (rtOriginalValues[prefix]) rtApplyPayload(prefix, rtOriginalValues[prefix]);
  const formEl = document.getElementById('rt-edit-' + prefix);
  if (formEl) formEl.hidden = true;
  const btn = document.getElementById('rt-edit-toggle-' + prefix);
  if (btn) btn.textContent = 'Edit';
  if (rtExpandedId === prefix) rtExpandedId = null;
}

function rtToggleEdit(id) {
  const prefix = String(id);
  if (rtExpandedId === prefix) { rtCollapse(id); return; }
  if (rtExpandedId !== null) rtCollapse(rtExpandedId);
  rtOriginalValues[prefix] = rtCollectPayload(prefix);
  document.getElementById('rt-edit-' + prefix).hidden = false;
  document.getElementById('rt-edit-toggle-' + prefix).textContent = 'Editing…';
  rtExpandedId = prefix;
}

function rtCancelEdit(id) {
  rtCollapse(id);
}

document.querySelectorAll('[id^="rt-freq-"]').forEach(function (el) {
  rtUpdateScheduleFields(el.id.replace('rt-freq-', ''));
});
</script>
</body>
</html>
