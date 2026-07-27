<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/onboard_tools.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }

$agent = require_login();
$isAdmin  = is_admin();
$isLeader = $isAdmin || is_mc_leader() || is_bic();
if (!$isLeader) { header('Location: index.php'); exit; }

$tools = onboard_tools();
// Build a map keyed by tool key for JS
$toolsJson = json_encode(array_values($tools));

// Market Center picker — sourced from the canonical master list (same as
// roster.php/admin_roles.php), not free text. This used to be a plain text
// input with no validation at all, which let typos/blanks ride straight
// through to innovate_roster (see normalize_market_center() in lib/roster.php).
$mcOpts     = local_db()->query("SELECT name, state_code FROM market_centers WHERE enabled=1 ORDER BY state_code, sort_ord, name")->fetchAll(PDO::FETCH_ASSOC);
$mcOptsJson = json_encode($mcOpts);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Onboarding — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
  <div class="layout">
    <?php render_sidebar('onboarding', $agent); ?>
    <div class="content">
      <header class="content-top">
        <div>
          <div class="content-title">Onboarding Queue</div>
          <div class="content-hello">Track every new agent through their provisioning checklist</div>
        </div>
      </header>
      <main class="wrap">

        <!-- Filter Tabs -->
        <div class="ob-tabs" id="ob-tabs">
          <button class="ob-tab active" data-filter="active"    onclick="switchTab(this,'active')">Active</button>
          <button class="ob-tab"        data-filter="completed" onclick="switchTab(this,'completed')">Completed</button>
          <button class="ob-tab"        data-filter="all"       onclick="switchTab(this,'all')">All</button>
        </div>

        <!-- Queue list -->
        <div id="ob-queue">
          <div class="ob-empty">Loading queue…</div>
        </div>

      </main>
    </div>
  </div>

  <script>
    window.ONBOARD_TOOLS   = <?= $toolsJson ?>;
    window.ONBOARD_MC_OPTS = <?= $mcOptsJson ?>;
    window.ONBOARD_OPEN_ID = <?= (int)($_GET['open'] ?? 0) ?>;
    window.IS_ADMIN         = <?= $isAdmin ? 'true' : 'false' ?>;
  </script>
  <script src="assets/onboard.js"></script>
</body>
</html>
