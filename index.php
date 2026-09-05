<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/lib/feature_flags.php';
$agent = require_login();

// Team members (agents on a Team Leader's roster) get the "Your Production"
// card instead of the cap wheel — a team leader's own team_dashboard.php
// already covers team-wide cap/production context for them, and not every
// commission plan has a cap anyway (see the 100% Plan case). Everyone else
// keeps the cap wheel; Your Production shows for both.
$isTeamMember = false;
try {
    $tm = local_db()->prepare("SELECT 1 FROM team_members WHERE agent_email = ?");
    $tm->execute([strtolower(trim($agent['email'] ?? ''))]);
    $isTeamMember = (bool)$tm->fetchColumn();
} catch (\Throwable $e) {}

// Daily Check (V1F-A) — manual preview (?daily_check=morning|closing) AND,
// as of V1F-A-5, automatic Morning Check / Before You Leave on this page
// only. Gated inline ($dcIsAdmin below, never a page-level
// require_admin_page()) so a regular agent gets zero Daily Check
// markup/JS -- this page stays the shared landing page for every role.
// Suppressed entirely during masquerade (locked product decision): a
// support/debug session must never expose or mutate another admin's Daily
// Check, even read-only, and must never auto-open one on their behalf.
// Also requires the admin_work_os Staff Feature Access flag — staff and
// super_admin alike, no bypass. Anyone with the feature OFF gets the exact
// same "zero Daily Check" treatment as a non-admin, including the explicit
// ?daily_check= preview request below, which never bypasses this.
$dailyCheckPreview = null;
$dcIsAdmin = is_admin() && !is_masquerading() && feature_enabled_for_current_user('admin_work_os');
// Hoisted out of the manual-preview branch below (V1F-A-5) -- the automatic
// open needs the same greeting name/date even when no ?daily_check= request
// is present, so its client-side template can match the manual one exactly.
// Cheap string ops only, no DB -- safe to compute for every admin page load.
$dcFirstName = '';
$dcFullDateToday = '';
// One-shot 4:30pm boundary, in ms from now, computed from the SERVER clock
// (America/New_York, db.php's app-wide default tz) -- never the browser's.
// null on weekends/masquerade/non-admin (no Closing timer needed) and once
// today's 4:30 has already passed (the page-load eligibility check already
// covers that moment; no timer needed to catch up on it again).
$dcClosingBoundaryMs = null;
$dcIsWeekday = false;
if ($dcIsAdmin) {
    require_once __DIR__ . '/lib/admin_work_routines.php';
    require_once __DIR__ . '/lib/admin_daily_checks.php';
    require_once __DIR__ . '/lib/personal_calendar.php';

    $dcNameParts = preg_split('/\s+/', trim($agent['name'] ?? ''));
    $dcFirstName = $dcNameParts[0] ?? '';
    if ($dcFirstName === '' || strpos($dcFirstName, '@') !== false) {
        $dcFirstName = ucfirst(strtolower(explode('@', $agent['email'] ?? '')[0] ?: 'there'));
    }
    $dcFullDateToday = date('l, F j, Y');

    $dcIsWeekday = ((int)date('N')) <= 5;
    if ($dcIsWeekday) {
        $dcNowTs      = time();
        $dcBoundaryTs = strtotime(date('Y-m-d') . ' 16:30:00');
        if ($dcNowTs < $dcBoundaryTs) {
            $dcClosingBoundaryMs = ($dcBoundaryTs - $dcNowTs) * 1000;
        }
    }

    $dcRequestedType = $_GET['daily_check'] ?? '';
    if (in_array($dcRequestedType, ['morning', 'closing'], true)) {
        $dcMeEmail = strtolower(trim($agent['email'] ?? ''));
        $dcToday   = date('Y-m-d'); // plain calendar date, America/New_York per db.php
        $dailyCheckPreview = [
            'type' => $dcRequestedType,
            'data' => daily_check_data(local_db(), $dcMeEmail, $dcToday, $dcRequestedType),
        ];
    }
}
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <?php if (!$isTeamMember): ?>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <?php endif; ?>
  <style>
    .ann-panel{margin-bottom:20px}
    .ann-panel h2{margin:0 0 10px;font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px}
    .ann-panel h2 a{font-size:11px;font-weight:700;color:#5b8e0d;text-decoration:none;margin-left:auto}
    .ann-panel h2 a:hover{text-decoration:underline}
    .ann-card{background:#fff;border-radius:10px;box-shadow:0 1px 5px rgba(0,0,0,.08);margin-bottom:12px;overflow:hidden;border:1px solid #eee}
    .ann-card.pinned{box-shadow:0 1px 5px rgba(0,0,0,.08),inset 0 3px 0 #f59e0b}
    .ann-card-img-wrap{position:relative;overflow:hidden;border-radius:10px 10px 0 0}
    .ann-card-img{width:100%;display:block;object-fit:cover}
    .ann-card-overlay{position:absolute;bottom:0;left:0;right:0;padding:32px 15px 13px;background:linear-gradient(0deg,rgba(0,0,0,.68) 0%,transparent 100%)}
    .ann-card-overlay-pin{font-size:10px;font-weight:700;color:rgba(255,210,70,1);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px}
    .ann-card-overlay-title{font-size:15px;font-weight:800;color:#fff;line-height:1.3;text-shadow:0 1px 4px rgba(0,0,0,.35)}
    .ann-card-body{padding:11px 15px 12px}
    .ann-card-no-img{border-left:3px solid #82C112;border-radius:0 0 10px 10px}
    .ann-card-no-img.pinned{border-left-color:#f59e0b}
    .ann-card-pin{font-size:10px;font-weight:700;color:#a06000;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
    .ann-card-title{font-size:14px;font-weight:700;color:#111;margin-bottom:4px}
    .ann-card-text{font-size:12px;color:#555;line-height:1.5;margin-bottom:5px;display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}
    .ann-card-text.expanded{-webkit-line-clamp:unset;overflow:visible}
    .ann-card-text h2{font-size:14px;font-weight:800;margin:0 0 3px}
    .ann-card-text h3{font-size:13px;font-weight:700;margin:0 0 2px}
    .ann-card-text p{margin:0 0 3px}
    .ann-card-text ul,.ann-card-text ol{margin:0 0 3px;padding-left:14px}
    .ann-card-text a{color:#5b8e0d}
    .ann-read-more{display:none;font-size:11px;font-weight:700;color:#5b8e0d;cursor:pointer;margin:0 0 6px}
    .ann-read-more:hover{text-decoration:underline}
    .ann-card-meta{font-size:10px;color:#bbb}
    .ann-card-side{display:flex;align-items:stretch}
    .ann-card-side-img{object-fit:cover;display:block;flex-shrink:0}

    /* Daily Check (V1F-A) — manual preview only for this slice. Same
       fixed-inset overlay pattern as #profile-reminder-overlay above, just
       a wider card (fits 5-8 checklist rows without feeling like a second
       application) and its own restrained black/white/green styling. */
    .dc-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:none;align-items:center;justify-content:center;padding:20px}
    .dc-overlay.dc-open{display:flex}
    .dc-card{background:#fff;border-radius:12px;width:min(480px,100%);max-height:88vh;overflow-y:auto;padding:26px 28px;position:relative;box-shadow:0 8px 32px rgba(0,0,0,.18)}
    .dc-close-x{position:absolute;top:14px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:#888;line-height:1}
    .dc-close-x:hover{color:#333}
    .dc-greeting{font-size:19px;font-weight:800;color:#111}
    .dc-date{font-size:12px;color:#888;margin-top:2px}
    .dc-sub{font-size:13px;color:#555;margin-top:8px}
    .dc-section-head{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--faint,#999);margin:20px 0 8px}
    .dc-checklist{border:1px solid var(--border,#e6e7e8);border-radius:8px;overflow:hidden}
    .dc-check-row{display:flex;align-items:flex-start;gap:10px;padding:10px 14px;font-size:13px;color:#222;border-top:1px solid var(--border,#e6e7e8);cursor:default}
    .dc-check-row:first-child{border-top:none}
    .dc-check-row input{margin-top:2px;width:15px;height:15px;flex-shrink:0;accent-color:#82C112;cursor:pointer}
    .dc-check-row input:disabled{cursor:default;opacity:.6}
    .dc-check-row.dc-check-done span{color:#888;text-decoration:line-through}
    .dc-empty{font-size:13px;color:#888;padding:14px 4px}
    .dc-empty-sm{font-size:12px;color:#999;padding:4px}
    .dc-divider{border-top:1px solid var(--border,#e6e7e8);margin-top:20px}
    .dc-schedule{display:flex;flex-direction:column;gap:6px}
    .dc-sched-row{display:flex;gap:10px;font-size:13px;align-items:baseline}
    .dc-sched-time{font-weight:700;color:#5b8e0d;font-size:12px;white-space:nowrap;flex-shrink:0;min-width:64px}
    .dc-sched-title{color:#222}
    .dc-followup{font-size:12px;color:#a06000;margin-top:16px;font-weight:600}
    .dc-followup-clear{color:#888;font-weight:400}
    .dc-sched-more{font-size:12px;color:#888;margin-top:8px}
    .dc-sched-link{font-size:12px;color:#5b8e0d;font-weight:700;text-decoration:none;display:inline-block;margin-top:2px}
    .dc-sched-link:hover{text-decoration:underline}
    .dc-progress{margin-top:18px}
    .dc-progress-label{font-size:12px;font-weight:700;color:#333;margin-bottom:6px}
    .dc-progress-bar{height:6px;background:#eee;border-radius:4px;overflow:hidden}
    .dc-progress-fill{height:100%;background:#82C112;border-radius:4px}
    .dc-all-set{margin-top:14px;padding:12px 14px;border:1px solid var(--border,#e6e7e8);border-radius:8px;background:#f7faf3}
    .dc-all-set-title{font-size:13px;font-weight:800;color:#3a6b1a}
    .dc-all-set-sub{font-size:12px;color:#666;margin-top:2px}
    .dc-error{font-size:12px;color:#c00;margin-top:10px}
    .dc-actions{display:flex;align-items:center;gap:10px;margin-top:22px}
    .dc-btn-primary{text-decoration:none;padding:9px 18px;background:#82C112;color:#111;border-radius:6px;font-weight:800;font-size:13px}
    .dc-btn-secondary{padding:9px 16px;border:1px solid #ccc;background:#fff;color:#555;font-size:13px;font-weight:700;border-radius:6px;cursor:pointer}
  </style>
</head>
<body>
  <div class="layout">
    <?php render_sidebar('dashboard', $agent); ?>

    <!-- Main content -->
    <div class="content">
      <header class="content-top">
        <div class="content-title">Dashboard</div>
        <div class="content-hello">Welcome back, <?= htmlspecialchars(explode(' ', $agent['name'] ?: 'Agent')[0]) ?></div>
      </header>

      <main class="wrap">
        <div id="sample-banner" class="banner" hidden></div>

        <div id="profile-reminder-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center">
          <div style="background:#fff;border-radius:12px;width:min(440px,95vw);padding:26px;position:relative">
            <button onclick="dismissProfileReminder()" style="position:absolute;top:14px;right:14px;background:none;border:none;font-size:20px;cursor:pointer;color:#888">&times;</button>
            <h3 style="margin:0 0 6px;font-size:16px;font-weight:800">Finish setting up your profile</h3>
            <p style="margin:0 0 14px;font-size:13px;color:#666">A few required fields are still missing:</p>
            <ul id="profile-reminder-list" style="margin:0 0 18px;padding-left:20px;font-size:13px;color:#444;line-height:1.7"></ul>
            <div style="display:flex;gap:8px">
              <a href="intake.php" class="btn-cal-nav" style="text-decoration:none;padding:9px 18px;background:#82C112;color:#111;border-radius:6px;font-weight:800;font-size:13px">Complete Profile →</a>
              <button onclick="dismissProfileReminder()" style="padding:9px 14px;border:1px solid #ccc;background:#fff;color:#555;font-size:13px;border-radius:6px;cursor:pointer">Remind me later</button>
            </div>
          </div>
        </div>

        <?php if ($dailyCheckPreview):
          $dc     = $dailyCheckPreview['data'];
          $dcType = $dailyCheckPreview['type'];
        ?>
        <?php $dcAllComplete = $dc['total'] > 0 && $dc['completed'] === $dc['total']; ?>
        <div id="daily-check-overlay" class="dc-overlay dc-open" data-check-type="<?= h($dcType) ?>">
          <div class="dc-card">
            <button class="dc-close-x" aria-label="Close">&times;</button>
            <?php if ($dcType === 'morning'): ?>
            <div class="dc-greeting">Good morning, <?= h($dcFirstName) ?></div>
            <div class="dc-date"><?= h($dcFullDateToday) ?></div>
            <div class="dc-sub">Let's get the office ready for the day.</div>
            <?php else: ?>
            <div class="dc-greeting">Before You Leave</div>
            <div class="dc-date"><?= h($dcFullDateToday) ?></div>
            <div class="dc-sub">Let's wrap up the office for today.</div>
            <?php endif; ?>

            <div class="dc-section-head"><?= $dcType === 'morning' ? "Morning Office Check" : "Before You Leave" ?></div>
            <?php if (empty($dc['items'])): ?>
            <div class="dc-empty">No <?= $dcType === 'morning' ? 'opening' : 'closing' ?> routines are due today.</div>
            <?php else: ?>
            <div class="dc-checklist">
              <?php foreach ($dc['items'] as $it): $dcDone = $it['status'] === 'done'; ?>
              <label class="dc-check-row<?= $dcDone ? ' dc-check-done' : '' ?>">
                <input type="checkbox" data-item-id="<?= (int)$it['id'] ?>" <?= $dcDone ? 'checked' : '' ?>>
                <span><?= h($it['title']) ?></span>
              </label>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($dcType === 'morning' && $dc['schedule'] !== null): $dcSched = $dc['schedule']; ?>
            <div class="dc-divider"></div>
            <div class="dc-section-head">What's On Today</div>
            <?php if ($dcSched['state'] === 'not_connected'): ?>
            <div class="dc-empty-sm">Calendar not connected.</div>
            <?php elseif ($dcSched['state'] === 'error'): ?>
            <div class="dc-empty-sm">Couldn't load your calendar right now.</div>
            <?php elseif (empty($dcSched['events'])): ?>
            <div class="dc-empty-sm">Nothing scheduled today.</div>
            <?php else:
              // Morning Check stays a quick office-readiness glance, not a
              // second calendar app -- cap the visible rows and hand off
              // the rest to the real Calendar page rather than growing the
              // popup to fit a full day's schedule.
              $dcVisibleEvents = array_slice($dcSched['events'], 0, 5);
              $dcMoreCount     = count($dcSched['events']) - count($dcVisibleEvents);
            ?>
            <div class="dc-schedule">
              <?php foreach ($dcVisibleEvents as $dcEv): ?>
              <div class="dc-sched-row">
                <span class="dc-sched-time"><?= $dcEv['all_day'] ? 'ALL DAY' : h((string)$dcEv['start_label']) ?></span>
                <span class="dc-sched-title"><?= h($dcEv['title']) ?></span>
              </div>
              <?php endforeach; ?>
            </div>
            <?php if ($dcMoreCount > 0): ?>
            <div class="dc-sched-more">+ <?= (int)$dcMoreCount ?> more on your schedule</div>
            <a class="dc-sched-link" href="calendar.php">View full schedule &rarr;</a>
            <?php endif; ?>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($dc['followup_count'] > 0): ?>
            <div class="dc-followup"><?= (int)$dc['followup_count'] ?> follow-up<?= $dc['followup_count'] === 1 ? '' : 's' ?> need attention today</div>
            <?php else: ?>
            <div class="dc-followup dc-followup-clear">No follow-ups due today</div>
            <?php endif; ?>

            <?php if ($dc['total'] > 0): ?>
            <div class="dc-progress" id="dc-progress">
              <div class="dc-progress-label" id="dc-progress-label"><?= (int)$dc['completed'] ?> of <?= (int)$dc['total'] ?> complete</div>
              <div class="dc-progress-bar"><div class="dc-progress-fill" id="dc-progress-fill" style="width:<?= round($dc['completed'] / $dc['total'] * 100) ?>%"></div></div>
            </div>
            <div class="dc-all-set" id="dc-all-set" <?= $dcAllComplete ? '' : 'hidden' ?>>
              <div class="dc-all-set-title">All set.</div>
              <div class="dc-all-set-sub" id="dc-all-set-sub"><?= (int)$dc['completed'] ?> of <?= (int)$dc['total'] ?> complete</div>
            </div>
            <?php endif; ?>

            <div class="dc-error" id="dc-error" hidden></div>

            <div class="dc-actions">
              <a class="dc-btn-primary" href="admin_work_os.php">Open Admin OS</a>
              <button type="button" class="dc-btn-secondary" id="dc-close-btn"><?= $dcAllComplete ? 'Done' : 'Done for Now' ?></button>
            </div>
          </div>
        </div>
        <?php endif; ?>
        <?php if ($dcIsAdmin && !$dailyCheckPreview): ?>
        <!-- V1F-A-5 -- empty until/unless an automatic Morning/Closing check
             becomes eligible; the auto-open script below fills this in with
             the same .dc-overlay markup the manual preview renders above. -->
        <div id="daily-check-auto-mount"></div>
        <?php endif; ?>

        <section class="tiles">
          <div class="tile tile-blue"><div class="tile-val" id="t-volume">—</div><div class="tile-lbl">Sales Volume</div></div>
          <div class="tile tile-green"><div class="tile-val" id="t-closed">—</div><div class="tile-lbl">Closed Deals</div></div>
          <div class="tile tile-amber"><div class="tile-val" id="t-residual">—</div><div class="tile-lbl">Residual Income</div></div>
          <div class="tile tile-red"><div class="tile-val" id="t-recruits">—</div><div class="tile-lbl">Agents In Growth Network</div></div>
        </section>

        <div id="ann-panel" class="card ann-panel" style="display:none">
          <h2>Announcements <a href="backoffice_announcements.php" id="ann-manage-link" style="display:none">Manage →</a></h2>
          <div id="ann-list"></div>
        </div>

        <div class="grid-dash">
          <?php if (!$isTeamMember): ?>
          <section class="card">
            <h2>Cap Progress</h2>
            <div class="cap-wrap">
              <canvas id="capWheel" width="220" height="220"></canvas>
              <div class="cap-center"><span id="cap-pct">0%</span></div>
            </div>
            <dl class="cap-legend">
              <div><dt>Cap</dt><dd id="cap-amount">—</dd></div>
              <div><dt>Paid</dt><dd id="cap-paid">—</dd></div>
              <div><dt>Remaining</dt><dd id="cap-remaining">—</dd></div>
            </dl>
            <p class="src-note" id="cap-note"></p>
          </section>
          <?php endif; ?>

          <section class="card">
            <h2>Your Production</h2>
            <div class="residual-head">
              <span class="residual-amt" id="prod-volume">—</span>
              <span class="residual-lbl">YTD sales volume</span>
            </div>
            <dl class="cap-legend">
              <div><dt>Deals</dt><dd id="prod-deals">—</dd></div>
              <div><dt>Avg Sale</dt><dd id="prod-avg">—</dd></div>
            </dl>
            <p class="src-note" id="prod-rank"></p>
          </section>

          <section class="card">
            <h2>Your Network &amp; Residual Income</h2>
            <div class="residual-head">
              <span class="residual-amt" id="residual-amt">—</span>
              <span class="residual-lbl">residual income earned</span>
            </div>
            <table class="tx" id="network-table" hidden>
              <thead><tr><th>Recruit</th><th class="num">Volume</th><th class="num">Deals</th></tr></thead>
              <tbody id="network-body"></tbody>
            </table>
            <div id="network-empty" class="network-empty">No recruits in your network yet.</div>
          </section>
        </div>

        <!-- Closing Calendar -->
        <section class="card" id="cc-card" style="margin-top:16px">
          <h2 style="margin:0 0 14px;font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px">
            Closing Calendar
            <span id="cc-nav" style="display:flex;align-items:center;gap:6px;margin-left:auto">
              <button onclick="ccPrev()" style="all:unset;cursor:pointer;font-size:16px;padding:0 4px;color:#888">&#8592;</button>
              <span id="cc-month-lbl" style="font-size:12px;font-weight:700;color:#555"></span>
              <button onclick="ccNext()" style="all:unset;cursor:pointer;font-size:16px;padding:0 4px;color:#888">&#8594;</button>
            </span>
          </h2>
          <div id="cc-body"></div>
        </section>

      </main>
    </div>
  </div>

  <script src="assets/app.js?v=<?= @filemtime(__DIR__ . '/assets/app.js') ?: time() ?>"></script>
  <script>
  (function(){
    function dismiss(){ document.getElementById('profile-reminder-overlay').style.display = 'none'; }
    window.dismissProfileReminder = dismiss;
    <?php if (!$dailyCheckPreview): ?>
    // Suppressed for an explicit manual Daily Check preview request only
    // (?daily_check=morning|closing) -- that overlay must take visual
    // priority over this one for that request. Plain index.php loads are
    // untouched: same fetch, same behavior as before, PLUS (V1F-A-5) this is
    // also the priority gate for automatic Daily Check -- window.__dcAfterProfileCheck
    // (defined below, if this admin qualifies for auto-open at all) only
    // ever runs once we know whether the profile reminder took the overlay
    // this load. If it did, Daily Check simply never asks -- no stacking, no
    // z-index tricks, just "don't ask this time".
    fetch('api/profile_completeness.php', { credentials: 'same-origin' })
      .then(r => r.json())
      .then(d => {
        const needed = !(d.complete || !d.missing || !d.missing.length);
        if (needed) {
          const list = document.getElementById('profile-reminder-list');
          list.innerHTML = d.missing.map(f => '<li>' + f.label.replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])) + '</li>').join('');
          document.getElementById('profile-reminder-overlay').style.display = 'flex';
        }
        if (window.__dcAfterProfileCheck) window.__dcAfterProfileCheck(needed);
      })
      .catch(() => { if (window.__dcAfterProfileCheck) window.__dcAfterProfileCheck(false); });
    <?php endif; ?>
    document.getElementById('profile-reminder-overlay').addEventListener('click', e => {
      if (e.target === e.currentTarget) dismiss();
    });
  })();
  <?php if ($dcIsAdmin): ?>
  // V1F-A-3/4/5 -- shared overlay wiring, used by BOTH the manual preview
  // (server-rendered overlay, below) and the automatic open (client-built
  // overlay, further below). One implementation of checkbox sync/progress/
  // Done-for-Now so the two paths can never drift into different behavior.
  // Scoped with overlay.querySelector(...) rather than document.getElementById(...)
  // so it works on either overlay -- harmless either way since the two paths
  // are mutually exclusive (an explicit ?daily_check= request never also
  // runs the auto-open module, see that module's own guard below).
  window.dcWireOverlay = function(overlay, checkType, onClose){
    const errorBox  = overlay.querySelector('#dc-error');
    const closeBtn  = overlay.querySelector('#dc-close-btn');
    const closeX    = overlay.querySelector('.dc-close-x');
    const progressLabel = overlay.querySelector('#dc-progress-label');
    const progressFill  = overlay.querySelector('#dc-progress-fill');
    const allSetBox = overlay.querySelector('#dc-all-set');
    const allSetSub = overlay.querySelector('#dc-all-set-sub');

    function showError(msg){
      if (!errorBox) return;
      errorBox.textContent = msg;
      errorBox.hidden = false;
    }
    function clearError(){
      if (errorBox) errorBox.hidden = true;
    }

    // Immediate local paint straight after a toggle succeeds, from the DOM
    // itself -- keeps the bar from looking stale during the brief window
    // before syncCompletion()'s server-truth response lands and repaints
    // it again (see toggleItem()).
    function localCounts(){
      const rows = overlay.querySelectorAll('.dc-checklist .dc-check-row');
      let completed = 0;
      rows.forEach(r => { if (r.classList.contains('dc-check-done')) completed++; });
      return { total: rows.length, completed: completed };
    }

    function paintProgress(total, completed){
      if (progressLabel) progressLabel.textContent = completed + ' of ' + total + ' complete';
      if (progressFill) progressFill.style.width = (total > 0 ? Math.round(completed / total * 100) : 0) + '%';
    }

    function paintAllSet(total, completed){
      const isComplete = total > 0 && completed === total;
      if (allSetBox) allSetBox.hidden = !isComplete;
      if (allSetSub) allSetSub.textContent = completed + ' of ' + total + ' complete';
      if (closeBtn) closeBtn.textContent = isComplete ? 'Done' : 'Done for Now';
    }

    // Recomputes completed_at from real work-item statuses server-side
    // (api/admin_daily_check_action.php) and repaints progress/All Set from
    // that response -- never from a client-side tally, per "server truth"
    // requirement. Best-effort: a failure here leaves the just-saved work
    // item status intact (Work OS already reflects it); only this
    // secondary bookkeeping is skipped, silently, until the next toggle.
    function syncCompletion(){
      return fetch('api/admin_daily_check_action.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'sync_completion', check_type: checkType, csrf: window.AE_CSRF || '' })
      }).then(r => r.json()).then(d => {
        if (d && d.ok) paintAllSet(d.total, d.completed);
      }).catch(() => {});
    }

    function toggleItem(checkbox){
      const row = checkbox.closest('.dc-check-row');
      const id  = parseInt(checkbox.dataset.itemId, 10);
      if (!id || checkbox.disabled) return;

      const wantDone    = checkbox.checked; // state the click just produced
      const prevChecked = !wantDone;

      checkbox.disabled = true;
      clearError();

      fetch('api/admin_work_item_action.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'status', id: id, status: wantDone ? 'done' : 'next', csrf: window.AE_CSRF || '' })
      }).then(r => r.json()).then(d => {
        if (!d || !d.ok) throw new Error((d && d.error) || 'save failed');
        row.classList.toggle('dc-check-done', wantDone);
        const c = localCounts();
        paintProgress(c.total, c.completed);
        return syncCompletion();
      }).catch(() => {
        checkbox.checked = prevChecked;
        showError("Couldn't save that change. Please try again.");
      }).finally(() => {
        checkbox.disabled = false;
      });
    }

    overlay.querySelectorAll('.dc-checklist input[type=checkbox]').forEach(cb => {
      cb.addEventListener('change', () => toggleItem(cb));
    });

    // Done for Now / X / backdrop click -- one shared, always-persisting
    // close path (no silent close once persistence exists). Never marks
    // tasks complete or touches routine templates -- dismissed_at only.
    function closeDc(){
      fetch('api/admin_daily_check_action.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'dismiss', check_type: checkType, csrf: window.AE_CSRF || '' })
      }).catch(() => {}).finally(() => {
        overlay.classList.remove('dc-open');
        if (onClose) onClose();
      });
    }
    if (closeBtn) closeBtn.addEventListener('click', closeDc);
    if (closeX) closeX.addEventListener('click', closeDc);
    overlay.addEventListener('click', e => {
      if (e.target === e.currentTarget) closeDc();
    });
    return closeDc;
  };
  <?php endif; ?>
  <?php if ($dailyCheckPreview): ?>
  (function(){
    // Manual preview (?daily_check=morning|closing) -- wire the overlay
    // that's already server-rendered into the page. No auto-popup, no
    // timer: an explicit request always opens regardless of time/dismissed/
    // completed state (see index.php's PHP block above, which never applies
    // any of that eligibility to this path).
    const overlay = document.getElementById('daily-check-overlay');
    window.dcWireOverlay(overlay, overlay.dataset.checkType);
  })();
  <?php endif; ?>
  <?php if ($dcIsAdmin && !$dailyCheckPreview): ?>
  (function(){
    // V1F-A-5 -- automatic Morning Check / Before You Leave on plain
    // index.php only (this whole block is absent from the page for a
    // regular agent, for masquerade, and for any ?daily_check= request --
    // see the three-way PHP guard above). The SERVER is the eligibility
    // authority throughout: this module only ever decides "is this a
    // reasonable moment to ask", never "should this actually open" -- that
    // answer comes back from api/admin_daily_check_action.php's `load`
    // action every single time, fresh, including at the 4:30 timer boundary
    // and on wake/focus, so a completion/dismissal from another tab or
    // computer is always respected.
    const firstName  = <?= json_encode($dcFirstName) ?>;
    const todayLabel = <?= json_encode($dcFullDateToday) ?>;
    const isWeekday  = <?= $dcIsWeekday ? 'true' : 'false' ?>;
    // ms until today's 4:30pm America/New_York, computed server-side above
    // -- null if it's a weekend or that boundary has already passed today.
    const closingBoundaryMs = <?= $dcClosingBoundaryMs !== null ? (int)$dcClosingBoundaryMs : 'null' ?>;

    const mount = document.getElementById('daily-check-auto-mount');
    let dcCurrentlyOpen = false; // an auto overlay is open right now -- block a second one
    // Every attemptAuto() call is chained onto this instead of a plain
    // in-flight boolean -- a boolean would SILENTLY DROP a trigger that
    // lands while another request is still resolving (e.g. the 4:30 timer
    // firing a heartbeat after the page-load sequence's own morning/closing
    // requests are still in flight), which would then wait for the next
    // focus/visibility event to ever ask again. Chaining means every
    // trigger still gets one real, fresh request -- just serialized so
    // there's never more than one in flight at once.
    let dcRequestChain = Promise.resolve();
    // Set once, by window.__dcAfterProfileCheck below, from this load's one
    // profile_completeness.php result -- see attemptAuto()'s priority gate.
    // dcProfileCheckDone resolves the instant that result is known, and
    // every attemptAuto() call awaits it first -- a plain boolean flag alone
    // would default to "not blocking" for any trigger that lands before the
    // profile check's own fetch has resolved (a real risk for the 4:30 timer
    // if the tab was opened right at the boundary, and for a focus/visibility
    // recheck landing in that same brief window).
    let dcProfileReminderShown = false;
    let dcResolveProfileCheckDone;
    const dcProfileCheckDone = new Promise(res => { dcResolveProfileCheckDone = res; });
    // check_types with a request already queued-or-in-flight -- de-dupes
    // near-simultaneous triggers for the SAME type (e.g. visibilitychange
    // and focus both firing for one tab resume) without blocking a
    // different type's request.
    const dcPending = new Set();
    // True only once we know "today, and past 4:30" is a real state worth
    // reacting to on focus/wake -- stays false all weekend so a refocused
    // Saturday tab never asks the server at all (see dcMaybeRecheckOnResume).
    let closingBoundaryPassed = isWeekday && closingBoundaryMs === null;

    function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

    // Mirrors the PHP template further up this file exactly (same classes,
    // same copy, same structure) -- this is the one other place that shape
    // has to be authored, because this render happens after an async fetch,
    // which PHP can't do. dcWireOverlay() below is what keeps the *behavior*
    // single-sourced between the two.
    function buildOverlayHtml(type, d){
      const rows = d.items.map(it => {
        const done = it.status === 'done';
        return '<label class="dc-check-row' + (done ? ' dc-check-done' : '') + '">' +
          '<input type="checkbox" data-item-id="' + (it.id | 0) + '"' + (done ? ' checked' : '') + '>' +
          '<span>' + esc(it.title) + '</span></label>';
      }).join('');
      const checklistHtml = d.items.length
        ? '<div class="dc-checklist">' + rows + '</div>'
        : '<div class="dc-empty">No ' + (type === 'morning' ? 'opening' : 'closing') + ' routines are due today.</div>';

      let scheduleHtml = '';
      if (type === 'morning' && d.schedule) {
        const s = d.schedule;
        scheduleHtml += '<div class="dc-divider"></div><div class="dc-section-head">What\'s On Today</div>';
        if (s.state === 'not_connected') {
          scheduleHtml += '<div class="dc-empty-sm">Calendar not connected.</div>';
        } else if (s.state === 'error') {
          scheduleHtml += '<div class="dc-empty-sm">Couldn’t load your calendar right now.</div>';
        } else if (!s.events || !s.events.length) {
          scheduleHtml += '<div class="dc-empty-sm">Nothing scheduled today.</div>';
        } else {
          const visible = s.events.slice(0, 5);
          const more = s.events.length - visible.length;
          scheduleHtml += '<div class="dc-schedule">' + visible.map(ev =>
            '<div class="dc-sched-row"><span class="dc-sched-time">' + (ev.all_day ? 'ALL DAY' : esc(String(ev.start_label))) + '</span>' +
            '<span class="dc-sched-title">' + esc(ev.title) + '</span></div>'
          ).join('') + '</div>';
          if (more > 0) {
            scheduleHtml += '<div class="dc-sched-more">+ ' + more + ' more on your schedule</div>' +
              '<a class="dc-sched-link" href="calendar.php">View full schedule &rarr;</a>';
          }
        }
      }

      const followupHtml = d.followup_count > 0
        ? '<div class="dc-followup">' + d.followup_count + ' follow-up' + (d.followup_count === 1 ? '' : 's') + ' need attention today</div>'
        : '<div class="dc-followup dc-followup-clear">No follow-ups due today</div>';

      const allComplete = d.total > 0 && d.completed_count === d.total;
      const progressHtml = d.total > 0
        ? '<div class="dc-progress" id="dc-progress">' +
            '<div class="dc-progress-label" id="dc-progress-label">' + d.completed_count + ' of ' + d.total + ' complete</div>' +
            '<div class="dc-progress-bar"><div class="dc-progress-fill" id="dc-progress-fill" style="width:' + Math.round(d.completed_count / d.total * 100) + '%"></div></div>' +
          '</div>' +
          '<div class="dc-all-set" id="dc-all-set"' + (allComplete ? '' : ' hidden') + '>' +
            '<div class="dc-all-set-title">All set.</div>' +
            '<div class="dc-all-set-sub" id="dc-all-set-sub">' + d.completed_count + ' of ' + d.total + ' complete</div>' +
          '</div>'
        : '';

      const greetingHtml = type === 'morning'
        ? '<div class="dc-greeting">Good morning, ' + esc(firstName) + '</div><div class="dc-date">' + esc(todayLabel) + '</div><div class="dc-sub">Let’s get the office ready for the day.</div>'
        : '<div class="dc-greeting">Before You Leave</div><div class="dc-date">' + esc(todayLabel) + '</div><div class="dc-sub">Let’s wrap up the office for today.</div>';

      return '<div id="daily-check-overlay" class="dc-overlay" data-check-type="' + type + '">' +
          '<div class="dc-card">' +
            '<button class="dc-close-x" aria-label="Close">&times;</button>' +
            greetingHtml +
            '<div class="dc-section-head">' + (type === 'morning' ? 'Morning Office Check' : 'Before You Leave') + '</div>' +
            checklistHtml + scheduleHtml + followupHtml + progressHtml +
            '<div class="dc-error" id="dc-error" hidden></div>' +
            '<div class="dc-actions">' +
              '<a class="dc-btn-primary" href="admin_work_os.php">Open Admin OS</a>' +
              '<button type="button" class="dc-btn-secondary" id="dc-close-btn">' + (allComplete ? 'Done' : 'Done for Now') + '</button>' +
            '</div>' +
          '</div>' +
        '</div>';
    }

    function requestLoad(checkType){
      return fetch('api/admin_daily_check_action.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'load', check_type: checkType, csrf: window.AE_CSRF || '' })
      }).then(r => r.json()).catch(() => null);
    }

    function openAuto(checkType, data){
      if (!mount) return;
      mount.innerHTML = buildOverlayHtml(checkType, data);
      const overlay = mount.firstElementChild;
      dcCurrentlyOpen = true;
      window.dcWireOverlay(overlay, checkType, function(){
        dcCurrentlyOpen = false;
        mount.innerHTML = '';
      });
      // Two rAFs so the class change (which drives the CSS transition-free
      // show via display:flex) applies after the browser has painted the
      // freshly-inserted, still-hidden overlay -- avoids a same-frame
      // insert+show that some browsers coalesce into no visible change.
      requestAnimationFrame(() => requestAnimationFrame(() => overlay.classList.add('dc-open')));
    }

    function attemptAuto(checkType){
      if (dcCurrentlyOpen) return Promise.resolve(false);
      if (dcPending.has(checkType)) return Promise.resolve(false); // already queued/in-flight for this type
      dcPending.add(checkType);
      const run = dcRequestChain.then(() => dcProfileCheckDone).then(() => {
        // Priority gate (section 5) applies to EVERY auto-open trigger for
        // this page load, not just the first one -- if the profile reminder
        // was shown when this load's one profile_completeness.php check
        // resolved, the 4:30 timer and any later focus/visibility recheck
        // must also stand down for the rest of this load's lifetime, or a
        // reminder still open at 4:30 would get Before You Leave stacked on
        // top of it. Nothing in this flow can make the reminder newly
        // required again without a page reload, so "once per load" is
        // correct (not just "once at the very first attempt"). Waiting on
        // dcProfileCheckDone (rather than reading dcProfileReminderShown
        // directly) closes the window where a trigger lands before that
        // fetch has resolved -- a plain boolean would default to "not
        // blocking" in that gap.
        if (dcProfileReminderShown) return false;
        if (dcCurrentlyOpen) return false; // could have opened while this was queued
        return requestLoad(checkType).then(res => {
          if (res && res.ok && res.eligible) {
            openAuto(checkType, res);
            return true;
          }
          return false;
        });
      }).catch(() => false).finally(() => { dcPending.delete(checkType); });
      dcRequestChain = run.catch(() => {}); // keep the chain alive even if a step above rejected
      return run;
    }

    // First-visit sequence -- Morning first (covers the normal 5am-1pm
    // case), Closing second (covers loading index.php fresh already past
    // 4:30, e.g. the "opens index.php at 4:45" case -- no need to wait for
    // the boundary timer if we're already past it at load time).
    window.__dcAfterProfileCheck = function(profileReminderShown){
      dcProfileReminderShown = profileReminderShown; // sticky for this load -- see attemptAuto()
      dcResolveProfileCheckDone();
      if (profileReminderShown) return; // priority: profile reminder wins this load, Daily Check doesn't ask
      attemptAuto('morning').then(opened => { if (!opened) attemptAuto('closing'); });
    };

    // ONE-SHOT 4:30 boundary timer (section 7/8) -- fires at most once,
    // recomputed server-side on every page load rather than a client
    // interval. Fresh eligibility is re-asked at fire time (attemptAuto),
    // never assumed from what was true at page load.
    if (closingBoundaryMs !== null && closingBoundaryMs > 0) {
      setTimeout(function(){
        closingBoundaryPassed = true;
        attemptAuto('closing');
      }, closingBoundaryMs);
    }

    // Laptop sleep/wake across 4:30 (section 9) -- no polling, no interval:
    // just one fresh check when the tab becomes visible/focused again,
    // and only once we're actually past the boundary (closingBoundaryPassed).
    // dcPending inside attemptAuto() is the guard against visibilitychange
    // and focus both firing for the same resume -- the second one is a no-op.
    function dcMaybeRecheckOnResume(){
      if (!closingBoundaryPassed) return;
      attemptAuto('closing');
    }
    document.addEventListener('visibilitychange', function(){
      if (document.visibilityState === 'visible') dcMaybeRecheckOnResume();
    });
    window.addEventListener('focus', dcMaybeRecheckOnResume);
  })();
  <?php endif; ?>
  (function(){
    fetch('api/announcements.php',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      const items=d.items||[];
      if(!items.length)return;
      const panel=document.getElementById('ann-panel');
      const list=document.getElementById('ann-list');
      panel.style.display='';
      const esc=s=>String(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
      const sizeH={compact:'130px',standard:'220px',large:'370px'};
      const sideW={compact:'90px',standard:'130px',large:'170px'};
      list.innerHTML=items.slice(0,5).map((a,i)=>{
        const hasImg=!!a.image_key;
        const dt=new Date(a.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
        const imgUrl=`api/announcements.php?action=image&key=${encodeURIComponent(a.image_key)}`;
        const textBlock=`<div class="ann-card-text" data-idx="${i}">${a.body}</div>
              <div class="ann-read-more" data-idx="${i}">Read more</div>`;
        if(hasImg && (a.image_position==='left'||a.image_position==='right')){
          const w=sideW[a.image_size]||'130px';
          const rL=a.image_position==='left'?'10px 0 0 10px':'0 10px 10px 0';
          const imgEl=`<img class="ann-card-side-img" src="${imgUrl}" style="width:${w};border-radius:${rL}" alt="">`;
          const txtEl=`<div class="ann-card-body" style="flex:1;min-width:0">
              ${a.pinned?'<div class="ann-card-pin">Pinned</div>':''}
              <div class="ann-card-title">${esc(a.title)}</div>
              ${textBlock}
              <div class="ann-card-meta">${dt}</div>
            </div>`;
          return `<div class="ann-card ann-card-side${a.pinned?' pinned':''}">
            ${a.image_position==='left'?imgEl+txtEl:txtEl+imgEl}
          </div>`;
        }
        if(hasImg){
          const h=sizeH[a.image_size]||'220px';
          return `<div class="ann-card${a.pinned?' pinned':''}">
            <div class="ann-card-img-wrap">
              <img class="ann-card-img" src="${imgUrl}" style="height:${h}" alt="">
              <div class="ann-card-overlay">
                ${a.pinned?'<div class="ann-card-overlay-pin">Pinned</div>':''}
                <div class="ann-card-overlay-title">${esc(a.title)}</div>
              </div>
            </div>
            <div class="ann-card-body">
              ${textBlock}
              <div class="ann-card-meta">${dt}</div>
            </div>
          </div>`;
        }
        return `<div class="ann-card${a.pinned?' pinned':''}">
          <div class="ann-card-body ann-card-no-img${a.pinned?' pinned':''}">
            ${a.pinned?'<div class="ann-card-pin">Pinned</div>':''}
            <div class="ann-card-title">${esc(a.title)}</div>
            ${textBlock}
            <div class="ann-card-meta">${dt}</div>
          </div>
        </div>`;
      }).join('');
      list.querySelectorAll('.ann-card-text').forEach(el=>{
        if(el.scrollHeight>el.clientHeight+1){
          const btn=list.querySelector(`.ann-read-more[data-idx="${el.getAttribute('data-idx')}"]`);
          if(btn)btn.style.display='block';
        }
      });
      list.addEventListener('click',e=>{
        const btn=e.target.closest('.ann-read-more');
        if(!btn)return;
        const idx=btn.getAttribute('data-idx');
        const txt=list.querySelector(`.ann-card-text[data-idx="${idx}"]`);
        if(!txt)return;
        const expanded=txt.classList.toggle('expanded');
        btn.textContent=expanded?'Show less':'Read more';
      });
    }).catch(()=>{});
    <?php if (can_post_announcements()): ?>
    document.getElementById('ann-manage-link').style.display='';
    <?php endif; ?>
  })();
  </script>
  <style>
    .cc-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;margin-bottom:14px}
    .cc-day-name{text-align:center;font-size:10px;font-weight:700;color:#aaa;text-transform:uppercase;padding:3px 0}
    .cc-cell{min-height:36px;border-radius:5px;padding:3px 4px;position:relative;background:#fafafa}
    .cc-cell.cc-today{background:#eef5e8;font-weight:800}
    .cc-cell.cc-blank{background:transparent}
    .cc-cell-num{font-size:11px;font-weight:600;color:#555;line-height:1}
    .cc-dot{width:6px;height:6px;border-radius:50%;margin-top:2px}
    .cc-dot.closing{background:#82C112}
    .cc-dot.under_contract{background:#2C9CC9}
    .cc-dot.target{background:#f59e0b}
    .cc-list{border-top:1px solid #f0f0f0;padding-top:12px}
    .cc-ev{display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid #fafafa}
    .cc-ev:last-child{border-bottom:0}
    .cc-ev-dot{width:8px;height:8px;border-radius:50%;flex:none}
    .cc-ev-date{font-size:11px;font-weight:700;color:#888;min-width:80px;white-space:nowrap}
    .cc-ev-title{font-size:13px;color:#222;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .cc-ev-type{font-size:10px;font-weight:700;padding:1px 6px;border-radius:3px;flex:none}
  </style>
  <script>
  (function(){
    const MONTHS=['January','February','March','April','May','June','July','August','September','October','November','December'];
    const DAYS=['Su','Mo','Tu','We','Th','Fr','Sa'];
    let ccY=new Date().getFullYear(), ccM=new Date().getMonth(), ccCache={};

    function ccKey(){return ccY+'-'+String(ccM+1).padStart(2,'0');}

    function ccLoad(key){
      if(ccCache[key]) return Promise.resolve(ccCache[key]);
      return fetch('api/dotloop_cal.php?month='+encodeURIComponent(key),{credentials:'same-origin'})
        .then(r=>r.json()).then(d=>{ccCache[key]=d;return d;}).catch(()=>({events:[],connected:false}));
    }

    function ccDraw(){
      const key=ccKey();
      document.getElementById('cc-month-lbl').textContent=MONTHS[ccM]+' '+ccY;
      document.getElementById('cc-body').innerHTML='<div style="padding:20px;text-align:center;color:#bbb;font-size:12px">Loading…</div>';
      ccLoad(key).then(function(d){
        if(!d.connected){
          document.getElementById('cc-body').innerHTML=
            '<div style="padding:16px;text-align:center;color:#888;font-size:13px">'+
            'DotLoop transactions haven\'t synced yet — check back soon.</div>';
          return;
        }
        const evs=d.events||[];
        // Build a map: day → [events]
        const byDay={};
        evs.forEach(function(e){
          const day=parseInt(e.date.split('-')[2],10);
          (byDay[day]=byDay[day]||[]).push(e);
        });
        const today=new Date(), isNow=(today.getFullYear()===ccY&&today.getMonth()===ccM);
        const firstDay=new Date(ccY,ccM,1).getDay(), daysInMo=new Date(ccY,ccM+1,0).getDate();
        let html='<div class="cc-grid">';
        DAYS.forEach(function(d){html+='<div class="cc-day-name">'+d+'</div>';});
        for(let i=0;i<firstDay;i++) html+='<div class="cc-cell cc-blank"></div>';
        for(let d=1;d<=daysInMo;d++){
          const dayEvs=byDay[d]||[], isToday=isNow&&today.getDate()===d;
          html+='<div class="cc-cell'+(isToday?' cc-today':'')+'"><div class="cc-cell-num">'+d+'</div>';
          dayEvs.slice(0,2).forEach(function(e){
            html+='<div class="cc-dot '+(e.type||'closing')+'" title="'+e.title+'"></div>';
          });
          html+='</div>';
        }
        html+='</div>';
        // Upcoming list
        const typeLabel={closing:'Closing',under_contract:'Under Contract',target:'Target Date'};
        const typeColor={closing:'#82C112',under_contract:'#2C9CC9',target:'#f59e0b'};
        if(evs.length){
          html+='<div class="cc-list">';
          evs.forEach(function(e){
            const dt=new Date(e.date+'T12:00:00');
            const lbl=dt.toLocaleDateString('en-US',{month:'short',day:'numeric'});
            const type=e.type||'closing', col=typeColor[type]||'#82C112';
            html+='<div class="cc-ev">'+
              '<div class="cc-ev-dot" style="background:'+col+'"></div>'+
              '<div class="cc-ev-date">'+lbl+'</div>'+
              '<div class="cc-ev-title">'+String(e.title||'').replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];})+'</div>'+
              '<div class="cc-ev-type" style="background:'+col+'22;color:'+col+'">'+(typeLabel[type]||type)+'</div>'+
              '</div>';
          });
          html+='</div>';
        } else {
          html+='<p style="color:#bbb;font-size:12px;margin:6px 0 0;text-align:center">No closings or target dates this month.</p>';
        }
        document.getElementById('cc-body').innerHTML=html;
      });
    }

    window.ccPrev=function(){if(--ccM<0){ccM=11;ccY--;}ccDraw();};
    window.ccNext=function(){if(++ccM>11){ccM=0;ccY++;}ccDraw();};
    ccDraw();
  })();
  </script>
</body>
</html>
