<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/launch_progress_mock.php';

$agent = require_login();
// Temporary: the whole Launch tab is super-admin-only while its design is
// being reviewed (see the 'launch' nav item in nav.php). Widen this to
// launch_agent / all agents once the design is approved. The page below
// already reads its data through launch_progress_for_agent() rather than
// inline arrays, so pointing that function at a real query later is the
// only change needed — this gate and the page markup don't move.
if (!is_super_admin()) { header('Location: index.php'); exit; }

// Preview-only: lets a reviewer flip between the mock scenarios via
// ?preview=<key> since a super admin isn't themself a Launch participant.
// With no ?preview= at all, $progress already comes straight from
// launch_progress_for_agent($agent['email']) below -- no mock override in
// effect. $activeKey deliberately does NOT fall back to a mock key in that
// case (it used to, which wrongly highlighted the Taylor Brooks pill as
// "active" on every real-path load even though the data wasn't hers) -- ''
// means "real data", matched by the dedicated pill below instead.
// Drop this whole block (and the pill row it feeds) once the tab is real and
// every visitor is just seeing their own record with no admin testing UI.
$mockScenarios = launch_progress_mock_data();
$previewKey    = $_GET['preview'] ?? '';
if (isset($mockScenarios[$previewKey])) {
    // This Week's title/theme_quote come from launch_sessions even when
    // previewing a mock scenario -- see launch_this_week_for_session() --
    // so a reviewer previews the real curriculum text for that session
    // number, not an invented one.
    $progress = $mockScenarios[$previewKey];
    $progress['this_week'] = array_merge(
        $progress['this_week'],
        launch_this_week_for_session(local_db(), $progress['current_session_number'])
    );
    $activeKey = $previewKey;
} else {
    $progress  = launch_progress_for_agent($agent['email']);
    $activeKey = '';
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Launch — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .launch-hero{background:linear-gradient(135deg,#1a1a1a 0%,#2d3a1e 100%);border-radius:12px;padding:28px 32px;color:#fff;margin-bottom:20px;display:flex;align-items:center;gap:20px}
    .launch-hero-icon{font-size:48px;line-height:1}
    .launch-hero-title{font-size:22px;font-weight:900;margin:0 0 4px}
    .launch-hero-sub{font-size:13px;color:rgba(255,255,255,.7);margin:0}
    .launch-preview-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}
    .launch-preview-pill{padding:6px 14px;border-radius:20px;font-size:12px;font-weight:700;border:1.5px solid #e0e0e0;background:#fff;color:#555;text-decoration:none}
    .launch-preview-pill.active{background:var(--green);border-color:var(--green);color:#000}
    .launch-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px}
    .launch-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:20px 24px}
    .launch-card h3{margin:0 0 14px;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
    .launch-card .placeholder{font-size:13px;color:var(--faint);font-style:italic}
    .launch-title-upper{text-transform:uppercase}
    .launch-kv{display:flex;flex-direction:column;gap:8px;margin:0}
    .launch-kv > div{display:flex;justify-content:space-between;gap:12px;font-size:13px}
    .launch-kv dt{margin:0;color:var(--faint)}
    .launch-kv dd{margin:0;font-weight:700;color:var(--ink)}
    .launch-kv dd.launch-muted{font-weight:400;font-style:italic;color:var(--faint)}
    .launch-week-title{font-size:16px;font-weight:800;color:var(--ink);margin-bottom:4px}
    .launch-week-quote{font-size:13px;font-style:italic;color:var(--faint);margin-bottom:16px}
    .launch-homework-head{font-size:12px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px;display:flex;align-items:center;gap:8px}
    .launch-homework-tag{font-size:10px;font-weight:700;text-transform:none;letter-spacing:0;color:#999;background:#f0f0f0;border:1px solid #e0e0e0;border-radius:10px;padding:2px 8px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('launch', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title launch-title-upper">Launch</div>
    </header>
    <main class="wrap">

      <div class="launch-hero">
        <div class="launch-hero-icon">🚀</div>
        <div>
          <div class="launch-hero-title launch-title-upper">Launch</div>
          <div class="launch-hero-sub">Your cohort, this week's session, and your progress toward graduation — all in one place.</div>
        </div>
      </div>

      <div class="launch-preview-row">
        <a class="launch-preview-pill<?= $activeKey === '' ? ' active' : '' ?>" href="launch.php">
          View: My Real Data
        </a>
        <?php foreach ($mockScenarios as $key => $sample): ?>
        <a class="launch-preview-pill<?= $key === $activeKey ? ' active' : '' ?>" href="launch.php?preview=<?= urlencode($key) ?>">
          Preview: <?= h($sample['agent_name']) ?>
        </a>
        <?php endforeach; ?>
      </div>

      <?php if (!$progress): ?>
      <div class="launch-card">
        <p class="placeholder">No Launch cohort assigned yet.</p>
      </div>
      <?php else: ?>
      <div class="launch-grid">

        <div class="launch-card">
          <h3>Overview</h3>
          <?php
            // Cohort/coach/session fields are real (launch_roster) for a logged-in
            // agent; This Week/My Progress/My Plan below are still mock-only.
            // 'Not available' covers both: no launch_roster row at all (cohort
            // status 'not_enrolled'), and a valid row whose session number can't
            // be computed yet (not started, or started before the cadence cutoff
            // — see launch_current_session_number()) — never blank or 'Week null'.
            $startDateDisplay = $progress['cohort']['start_date'] !== '' ? $progress['cohort']['start_date'] : 'Not yet scheduled';
            $statusDisplay    = ucwords(str_replace('_', ' ', $progress['cohort']['status']));
            $sessionDisplay   = $progress['current_session_number'] !== null
              ? 'Week ' . $progress['current_session_number'] . ' of ' . $progress['total_sessions']
              : 'Not available';
          ?>
          <dl class="launch-kv">
            <div><dt>Cohort Start Date</dt><dd><?= h($startDateDisplay) ?></dd></div>
            <div><dt>Status</dt><dd><?= h($statusDisplay) ?></dd></div>
            <div><dt>Coach</dt><dd><?= h($progress['coach']['name']) ?></dd></div>
            <div><dt>Current Session</dt><dd><?= h($sessionDisplay) ?></dd></div>
          </dl>
        </div>

        <div class="launch-card">
          <h3>This Week</h3>
          <?php $thisWeek = $progress['this_week']; ?>
          <?php if ($thisWeek['title'] !== ''): ?>
          <div class="launch-week-title"><?= h($thisWeek['title']) ?></div>
          <?php if ($thisWeek['theme_quote'] !== ''): ?>
          <div class="launch-week-quote">&ldquo;<?= h($thisWeek['theme_quote']) ?>&rdquo;</div>
          <?php endif; ?>
          <?php else: ?>
          <p class="placeholder">Not available</p>
          <?php endif; ?>
          <div class="launch-homework">
            <div class="launch-homework-head">
              Homework <span class="launch-homework-tag">Not yet tracked</span>
            </div>
          </div>
        </div>

        <div class="launch-card">
          <h3>My Progress</h3>
          <?php
            $dealsLogged   = $progress['progress']['deals_logged'] ?? null;
            $dealsDisplay  = $dealsLogged !== null ? $dealsLogged . ' of 3' : 'Not available';
            $weeklyTarget  = $progress['progress']['weekly_activity']['target'] ?? null;
            // A weekly goal is a Launch-program constant, not agent-specific --
            // showing it to someone with no current session (not enrolled, or
            // pre-cadence-cutoff -- see launch_current_session_number()) is
            // noise, not signal, so the row is omitted rather than shown as
            // "Not available" like the other two.
            $showWeeklyGoal = $weeklyTarget !== null && $progress['current_session_number'] !== null;
          ?>
          <dl class="launch-kv">
            <div><dt>University Completion</dt><dd class="launch-muted">Not yet tracked</dd></div>
            <div><dt>Deals Logged</dt><dd><?= h($dealsDisplay) ?></dd></div>
            <?php if ($showWeeklyGoal): ?>
            <div><dt>Weekly Goal</dt><dd><?= h($weeklyTarget . ' conversations') ?></dd></div>
            <?php endif; ?>
          </dl>
        </div>

        <div class="launch-card">
          <h3>My Plan</h3>
          <p class="placeholder">Income goal broken into transactions, weekly conversation targets, coach contact info — mock data only for now.</p>
        </div>

      </div>
      <?php endif; ?>

    </main>
  </div>
</div>
</body>
</html>
