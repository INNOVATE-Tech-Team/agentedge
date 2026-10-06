<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/admin_work_items.php';
require_once __DIR__ . '/lib/admin_work_routines.php';
require_once __DIR__ . '/lib/personal_calendar.php';
require_once __DIR__ . '/lib/feature_flags.php';

$agent = require_login();
require_admin_page();
if (!feature_enabled_for_current_user('admin_work_os')) { header('Location: index.php'); exit; }

// Greeting — server clock is already forced to America/New_York in db.php,
// same as the rest of the app.
$hour = (int)date('G');
$timeOfDay = $hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening');
$nameParts = preg_split('/\s+/', trim($agent['name'] ?? ''));
$firstName = $nameParts[0] ?? '';
if ($firstName === '' || strpos($firstName, '@') !== false) {
    $firstName = ucfirst(strtolower(explode('@', $agent['email'] ?? '')[0] ?: 'there'));
}
$fullDateToday = date('l, F j, Y');

// Real SQLite data, scoped to the current logged-in admin's own queue only
// (owner_email = me) — no cross-owner visibility here, even for super_admin
// (a cross-owner view is a deliberate later addition, not a default).
$me = strtolower(trim($agent['email'] ?? ''));
$db = local_db();
$today = date('Y-m-d'); // plain calendar date, America/New_York per db.php

// V1D-B: generate today's due routine occurrences for this admin only,
// before any of the dashboard's own queries run, so newly-created items
// are reflected in this same page load. As of V1F-A-1, index.php's Daily
// Check preview (via lib/admin_daily_checks.php's daily_check_data()) is a
// second, independent call site -- both rely on the same idempotent,
// concurrency-safe occurrence-table guard in generate_due_routine_items(),
// not on being the only caller. admin_work_routines.php itself still never
// calls this -- it only edits routine definitions, never generates from them.
generate_due_routine_items($db, $me, $today);

// Small local helper -- the four tiles differ only by WHERE clause, kept
// local to this file (not a shared lib) since nothing else needs it.
function awi_count(PDO $db, string $whereExtra, array $params): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM admin_work_items WHERE LOWER(owner_email)=? AND deleted_at IS NULL $whereExtra");
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

$todayCount   = awi_count($db, "AND due_date=? AND status != 'done'", [$me, $today]);
$inboxCount   = awi_count($db, "AND status='inbox'", [$me]);
$waitingCount = awi_count($db, "AND status='waiting'", [$me]);
// Fourth tile is "Next" (status=next), not "Recurring" — there is no
// recurring-task engine yet (source_type='recurring' only ever means a
// human manually tagged it that way), so a "Recurring" count would imply
// automation that doesn't exist. "Next" instead completes honest coverage
// of all four V1 statuses (Inbox/Next/Waiting/Done) on the dashboard.
$nextCount    = awi_count($db, "AND status='next'", [$me]);

$summaryCards = [
    ['label' => 'Today',      'count' => $todayCount],
    ['label' => 'Inbox',      'count' => $inboxCount],
    ['label' => 'Next',       'count' => $nextCount],
    ['label' => 'Waiting On', 'count' => $waitingCount],
];

// Category tiles — informational only, same "active = not done" definition
// as the status tiles above, just grouped by category instead of status.
// Reuses awi_count() rather than a new query shape.
$categoryCards = [];
foreach (ADMIN_WORK_CATEGORIES as $cat) {
    $categoryCards[] = [
        'label' => awos_category_label($cat),
        'count' => awi_count($db, "AND status != 'done' AND category=?", [$me, $cat]),
    ];
}

$todayStmt = $db->prepare(
    "SELECT id, title, category FROM admin_work_items
     WHERE LOWER(owner_email)=? AND due_date=? AND status != 'done' AND deleted_at IS NULL
     ORDER BY created_at ASC"
);
$todayStmt->execute([$me, $today]);
$todaysTasks = $todayStmt->fetchAll(PDO::FETCH_ASSOC);

// Routine-aware Today's Work: trace each row through the existing
// occurrence -> routine relationship (no new columns on admin_work_items)
// to show "Category · Area · Routine" for routine-generated rows instead
// of "Category · Due today". One extra lookup query, keyed by the ids
// already fetched above.
$routineAreaByItemId = [];
if (!empty($todaysTasks)) {
    $ids = array_column($todaysTasks, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $routineStmt = $db->prepare(
        "SELECT ro.work_item_id, r.routine_area FROM admin_work_routine_occurrences ro
         JOIN admin_work_routines r ON r.id = ro.routine_id
         WHERE ro.work_item_id IN ($placeholders)"
    );
    $routineStmt->execute($ids);
    foreach ($routineStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $routineAreaByItemId[(int)$row['work_item_id']] = $row['routine_area'];
    }
}

// My Schedule (V1E-A): read-only, session-derived owner only -- never a
// cross-admin view. pcal_get_today_schedule() never throws (all failure
// modes, including a dead/slow feed, resolve to state=error internally),
// so a broken personal calendar can never take down the rest of this page.
$mySchedule = pcal_get_today_schedule($me, $today);

// Follow Ups — waiting tasks whose follow-up date has arrived or passed.
// Owner-scoped, no notifications/cron -- this section itself IS the V1C
// reminder. Rendered only when non-empty (see markup below), so a clear
// queue means the section simply isn't there, not an empty state to read past.
$followUpStmt = $db->prepare(
    "SELECT id, title, waiting_on, follow_up_date FROM admin_work_items
     WHERE LOWER(owner_email)=? AND status='waiting' AND deleted_at IS NULL
       AND follow_up_date IS NOT NULL AND follow_up_date != '' AND follow_up_date <= ?
     ORDER BY follow_up_date ASC"
);
$followUpStmt->execute([$me, $today]);
$followUps = $followUpStmt->fetchAll(PDO::FETCH_ASSOC);
$waitingNeedsAttention = !empty($followUps);

function awos_followup_label(string $followUpDate, string $today): string {
    if ($followUpDate === $today) return 'Follow-up due today';
    $days = (new DateTime($today))->diff(new DateTime($followUpDate))->days;
    return 'Follow-up overdue by ' . $days . ' day' . ($days === 1 ? '' : 's');
}

// Active Work — every unfinished task, regardless of due date, so a task
// never becomes unreachable just because it isn't due today (Quick Capture
// and any Next/Waiting task with no due date only ever showed up here and
// nowhere else before this section existed). Not a general filtering system —
// just the minimum persistent "get back to it" list, now grouped by status
// per V1C so Inbox/Next/Waiting read as three distinct, scannable lists
// instead of one interleaved one.
// One query, still ordered status-then-due-date-then-created_at (as in
// V1B); grouping the already-sorted rows by status in PHP below is enough
// to produce the three sections without a second query per group.
$activeStmt = $db->prepare(
    "SELECT id, title, category, status, due_date, waiting_on FROM admin_work_items
     WHERE LOWER(owner_email)=? AND status != 'done' AND deleted_at IS NULL
     ORDER BY
       CASE status WHEN 'inbox' THEN 0 WHEN 'next' THEN 1 WHEN 'waiting' THEN 2 ELSE 3 END,
       CASE WHEN due_date IS NULL OR due_date = '' THEN 1 ELSE 0 END,
       due_date ASC,
       created_at ASC"
);
$activeStmt->execute([$me]);
$activeTasks = $activeStmt->fetchAll(PDO::FETCH_ASSOC);

$activeGroups = ['inbox' => [], 'next' => [], 'waiting' => []];
foreach ($activeTasks as $t) {
    if (isset($activeGroups[$t['status']])) $activeGroups[$t['status']][] = $t;
}
$activeGroupLabels = ['inbox' => 'INBOX', 'next' => 'NEXT', 'waiting' => 'WAITING'];

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

// Status is now implied by the group header, so the per-row meta line only
// needs category + due date; a waiting row's "Waiting on" gets its own line
// (added in the markup) since it's not always present.
function awos_active_row_meta(array $t): string {
    $parts = [awos_category_label($t['category'])];
    if (!empty($t['due_date'])) {
        $parts[] = 'Due ' . date('M j', strtotime($t['due_date']));
    }
    return implode(' · ', $parts);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin Work OS — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .awos-hero{margin-bottom:28px}
    .awos-hero-greeting{font-size:26px;font-weight:800;line-height:1.2;letter-spacing:-.01em}
    .awos-hero-date{font-size:13px;color:var(--faint);margin-top:4px}
    .awos-hero-sub{font-size:13px;color:var(--faint);margin-top:6px}
    .awos-hero-accent{width:44px;height:3px;background:var(--green);border-radius:2px;margin-top:16px}
    /* V1F-A-5 -- human-friendly manual reopen, so an admin doesn't have to
       type ?daily_check= URLs. Deliberately smaller/quieter than Quick Add
       below (compact pill links, not another input row) -- Quick Add stays
       the visually primary action on this page. Always navigate straight to
       index.php?daily_check=..., which opens regardless of time/dismissed/
       completed state (an explicit request, same as today).*/
    .awos-hero-dc{margin-top:12px;display:flex;gap:10px;flex-wrap:wrap}
    .awos-hero-dc a{font-size:12px;font-weight:700;color:var(--faint);text-decoration:none;border:1px solid var(--border);padding:5px 11px;border-radius:20px}
    .awos-hero-dc a:hover,.awos-hero-dc a:focus-visible{color:var(--green-d);border-color:var(--green)}
    .awos-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--green-d);margin-bottom:8px}
    .awos-panel{background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden}
    /* At a Glance -- one unified card: four equal-width primary status
       metrics, then a visually secondary category strip underneath, both
       reusing the same white-panel language as Today's Work/Active Work
       rather than floating as separate tile rows. */
    .awos-glance-primary{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr))}
    .awos-glance-cell{padding:16px 18px;min-width:0}
    .awos-glance-num{font-size:26px;font-weight:800;line-height:1.1;color:var(--green-d)}
    .awos-glance-lbl{font-size:11px;color:var(--faint);font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-top:2px}
    .awos-glance-cell.awos-tile-warn .awos-glance-num{color:#c00}
    .awos-glance-secondary{display:flex;flex-wrap:wrap;gap:30px;padding:14px 18px;border-top:1px solid var(--border)}
    .awos-glance-mini{font-size:13px;color:var(--faint);font-weight:600}
    .awos-glance-mini-num{font-weight:700;font-size:16px;color:var(--green-d);margin-right:5px}
    .awos-panel-head{padding:13px 18px;border-bottom:1px solid var(--border);font-size:13px;font-weight:700;color:var(--green-d)}
    .awos-task{padding:14px 18px;border-top:1px solid var(--border);display:flex;align-items:baseline;justify-content:space-between;gap:12px}
    .awos-task:first-of-type{border-top:none}
    .awos-task > div:first-child{flex:1;min-width:0}
    .awos-task-title{font-size:14px;font-weight:600;line-height:1.4}
    .awos-task-meta{font-size:12px;color:var(--faint);margin-top:2px;line-height:1.4}
    .awos-task-due{font-size:12px;color:var(--faint);white-space:nowrap}
    .awos-empty{padding:24px 18px;text-align:center;color:var(--faint);font-size:13px}
    .awos-task-title a{color:inherit;text-decoration:none}
    .awos-task-title a:hover{text-decoration:underline}
    .awos-group{border-top:1px solid var(--border)}
    .awos-group:first-child{border-top:none}
    .awos-group-head{padding:12px 18px 2px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--faint)}
    .qc-row{display:flex;gap:8px;margin-bottom:8px}
    .qc-input{flex:1;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:14px;font-family:inherit}
    .qc-add{padding:10px 18px;border:none;border-radius:8px;background:var(--green);color:#111;font-size:13px;font-weight:700;cursor:pointer}
    .qc-add:hover,.qc-add:focus-visible{background:var(--green-d);color:#fff}
    .qc-add:disabled{opacity:.5;cursor:default}
    .qc-msg{display:block;margin-top:6px;font-size:12px;min-height:14px}
    .qc-msg.ok{color:#3a6b1a}
    .qc-msg.err{color:#c00}
    /* Today area: Today's Work (larger) + My Schedule (narrower), aligned
       at the top, stacking to one column on narrower screens -- same
       2-column-collapsing-to-1 idiom already used elsewhere in this app
       (assets/app.css's .grid2 / .dr-two-col), scoped here since this
       page already keeps its own layout CSS local (see admin_work_routines.php
       precedent). */
    .awos-today-grid{display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start}
    @media(max-width:860px){.awos-today-grid{grid-template-columns:1fr}}
    .awos-sched-row{padding:10px 18px;border-top:1px solid var(--border);display:flex;gap:10px;align-items:baseline}
    .awos-sched-row:first-of-type{border-top:none}
    .awos-sched-time{font-size:12px;font-weight:700;color:var(--green-d);white-space:nowrap;flex-shrink:0}
    .awos-sched-title{font-size:13px;font-weight:600;line-height:1.4;min-width:0}
    .awos-sched-allday-head{padding:10px 18px 2px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--faint)}
    .awos-sched-connect{display:inline-block;margin-top:10px;padding:8px 16px;border:none;border-radius:8px;background:var(--green);color:#111;font-size:13px;font-weight:700;text-decoration:none}
    .awos-sched-connect:hover,.awos-sched-connect:focus-visible{background:var(--green-d);color:#fff}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('admin_work_os', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)">Admin OS</div>
        <div class="content-title">Admin Work OS</div>
      </div>
    </header>
    <main class="wrap">
      <div class="awos-hero">
        <div class="awos-hero-greeting">Good <?= h($timeOfDay) ?>, <?= h($firstName) ?></div>
        <div class="awos-hero-date"><?= h($fullDateToday) ?></div>
        <div class="awos-hero-sub">Here's what needs your attention today.</div>
        <div class="awos-hero-dc">
          <a href="index.php?daily_check=morning">Morning Check</a>
          <a href="index.php?daily_check=closing">Before You Leave</a>
        </div>
        <div class="awos-hero-accent"></div>
      </div>

      <div class="awos-eyebrow">Quick Add</div>
      <div class="qc-row">
        <input type="text" id="qc-input" class="qc-input" placeholder="What do you need to remember or do?" autocomplete="off">
        <button type="button" class="qc-add" id="qc-add-btn" onclick="qcCapture()">Add</button>
      </div>
      <span class="qc-msg" id="qc-msg"></span>

      <div class="awos-panel" style="margin-top:14px;margin-bottom:20px">
        <div class="awos-panel-head">At a Glance</div>
        <div class="awos-glance-primary">
          <?php foreach ($summaryCards as $c):
            $tileId = $c['label'] === 'Inbox' ? ' id="tile-inbox"' : ($c['label'] === 'Waiting On' ? ' id="tile-waiting"' : '');
            $warnClass = ($c['label'] === 'Waiting On' && $waitingNeedsAttention) ? ' awos-tile-warn' : '';
          ?>
          <div class="awos-glance-cell<?= $warnClass ?>"<?= $tileId ?>>
            <div class="awos-glance-num"><?= (int)$c['count'] ?></div>
            <div class="awos-glance-lbl"><?= h($c['label']) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="awos-glance-secondary">
          <?php foreach ($categoryCards as $c): ?>
          <div class="awos-glance-mini"><span class="awos-glance-mini-num"><?= (int)$c['count'] ?></span> <?= h($c['label']) ?></div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if (!empty($followUps)): ?>
      <div class="awos-panel" style="margin-bottom:20px">
        <div class="awos-panel-head">Follow Ups</div>
        <?php foreach ($followUps as $f): ?>
        <div class="awos-task">
          <div>
            <div class="awos-task-title"><a href="admin_work_item.php?id=<?= (int)$f['id'] ?>"><?= h($f['title']) ?></a></div>
            <?php if (!empty($f['waiting_on'])): ?>
            <div class="awos-task-meta">Waiting on: <?= h($f['waiting_on']) ?></div>
            <?php endif; ?>
          </div>
          <div class="awos-task-due"><?= h(awos_followup_label($f['follow_up_date'], $today)) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="awos-today-grid" style="margin-bottom:20px">
        <div class="awos-panel">
          <div class="awos-panel-head">Today's Work</div>
          <?php if (empty($todaysTasks)): ?>
          <div class="awos-empty">Nothing due today.</div>
          <?php else: foreach ($todaysTasks as $t):
            $routineArea = $routineAreaByItemId[(int)$t['id']] ?? null;
          ?>
          <div class="awos-task">
            <div>
              <div class="awos-task-title"><a href="admin_work_item.php?id=<?= (int)$t['id'] ?>"><?= h($t['title']) ?></a></div>
            </div>
            <div class="awos-task-due">
              <?php if ($routineArea !== null): ?>
              <?= h(awos_category_label($t['category'])) ?> · <?= h(awos_routine_area_label($routineArea)) ?> · Routine
              <?php else: ?>
              <?= h(awos_category_label($t['category'])) ?> · Due today
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; endif; ?>
        </div>

        <div class="awos-panel">
          <div class="awos-panel-head">My Schedule</div>
          <?php if ($mySchedule['state'] === 'not_connected'): ?>
          <div class="awos-empty">
            Connect your calendar to see today's meetings and appointments.<br>
            <a class="awos-sched-connect" href="calendar.php">Connect Calendar</a>
          </div>
          <?php elseif ($mySchedule['state'] === 'error'): ?>
          <div class="awos-empty">Couldn't load your calendar right now.</div>
          <?php elseif (empty($mySchedule['events'])): ?>
          <div class="awos-empty">Nothing scheduled today.</div>
          <?php else:
            $allDayEvents = array_values(array_filter($mySchedule['events'], fn($e) => $e['all_day']));
            $timedEvents  = array_values(array_filter($mySchedule['events'], fn($e) => !$e['all_day']));
          ?>
            <?php if (!empty($allDayEvents)): ?>
            <div class="awos-sched-allday-head">ALL DAY</div>
            <?php foreach ($allDayEvents as $e): ?>
            <div class="awos-sched-row">
              <div class="awos-sched-title"><?= h($e['title']) ?></div>
            </div>
            <?php endforeach; endif; ?>
            <?php foreach ($timedEvents as $e): ?>
            <div class="awos-sched-row">
              <div class="awos-sched-time"><?= h($e['start_label']) ?><?= $e['end_label'] ? ' – ' . h($e['end_label']) : '' ?></div>
              <div class="awos-sched-title"><?= h($e['title']) ?></div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="awos-panel" style="margin-top:20px">
        <div class="awos-panel-head">Active Work</div>
        <div id="active-work-list">
          <?php if (empty($activeTasks)): ?>
          <div class="awos-empty" id="active-work-empty">No active tasks.</div>
          <?php else: foreach ($activeGroupLabels as $statusKey => $groupLabel):
            if (empty($activeGroups[$statusKey])) continue;
          ?>
          <div class="awos-group">
            <div class="awos-group-head"><?= h($groupLabel) ?></div>
            <div id="aw-group-<?= h($statusKey) ?>">
              <?php foreach ($activeGroups[$statusKey] as $t): ?>
              <div class="awos-task">
                <div>
                  <div class="awos-task-title"><a href="admin_work_item.php?id=<?= (int)$t['id'] ?>"><?= h($t['title']) ?></a></div>
                  <div class="awos-task-meta"><?= h(awos_active_row_meta($t)) ?></div>
                  <?php if ($statusKey === 'waiting' && !empty($t['waiting_on'])): ?>
                  <div class="awos-task-meta">Waiting on: <?= h($t['waiting_on']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </main>
  </div>
</div>
<script>
function qcCapture() {
  const input = document.getElementById('qc-input');
  const btn   = document.getElementById('qc-add-btn');
  const msg   = document.getElementById('qc-msg');
  const title = input.value.trim();
  if (!title) { input.focus(); return; }

  btn.disabled = true;
  msg.textContent = 'Adding…'; msg.className = 'qc-msg';

  fetch('api/admin_work_item_action.php', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'create', title: title, csrf: window.AE_CSRF || '' }),
  })
    .then(r => r.json())
    .then(d => {
      btn.disabled = false;
      if (d.ok) {
        const capturedTitle = title;
        input.value = '';
        msg.textContent = 'Added to Inbox'; msg.className = 'qc-msg ok';
        const tile = document.querySelector('#tile-inbox .awos-glance-num');
        if (tile) tile.textContent = (parseInt(tile.textContent, 10) || 0) + 1;

        // Every field of a fresh Quick Capture is a known constant (status
        // inbox, category admin, no due date) -- no need to ask the server
        // for anything beyond the new id, so this stays a small template
        // rather than duplicating admin_work_os.php's row-rendering logic.
        const emptyEl = document.getElementById('active-work-empty');
        if (emptyEl) emptyEl.remove();
        // Prepend into the INBOX group specifically, since Active Work is
        // grouped by status. If that group isn't rendered yet (zero Inbox
        // tasks on page load -- possibly zero active tasks at all), build
        // the same minimal group/header markup the server would have and
        // insert it first, rather than skipping the optimistic update --
        // INBOX is always the first group when present, so there's no
        // ordering ambiguity to resolve against NEXT/WAITING here.
        let inboxGroup = document.getElementById('aw-group-inbox');
        if (!inboxGroup) {
          const groupWrap = document.createElement('div');
          groupWrap.className = 'awos-group';
          groupWrap.innerHTML = '<div class="awos-group-head">INBOX</div><div id="aw-group-inbox"></div>';
          document.getElementById('active-work-list').prepend(groupWrap);
          inboxGroup = groupWrap.querySelector('#aw-group-inbox');
        }
        const row = document.createElement('div');
        row.className = 'awos-task';
        row.innerHTML = '<div><div class="awos-task-title"><a href="admin_work_item.php?id=' + d.id + '"></a></div>'
          + '<div class="awos-task-meta">Administrative</div></div>';
        row.querySelector('a').textContent = capturedTitle; // textContent, not innerHTML -- avoid re-escaping the title
        inboxGroup.prepend(row);
      } else {
        msg.textContent = d.error || 'Could not add — please try again.'; msg.className = 'qc-msg err';
      }
      // Keep focus in the field either way so rapid repeated capture works.
      input.focus();
    })
    .catch(() => {
      btn.disabled = false;
      msg.textContent = 'Network error — please try again.'; msg.className = 'qc-msg err';
      input.focus();
    });
}

document.getElementById('qc-input').addEventListener('keydown', function (e) {
  if (e.key === 'Enter') { e.preventDefault(); qcCapture(); }
});
</script>
</body>
</html>
