<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/offboard_tools.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }

$agent = require_login();
require_admin_page();

$tools     = offboard_tools();
$toolsJson = json_encode(array_values($tools));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Offboarding — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
  <div class="layout">
    <?php render_sidebar('offboarding', $agent); ?>
    <div class="content">
      <header class="content-top">
        <div>
          <div class="content-title">Offboarding Queue</div>
          <div class="content-hello">Track every departing agent through their deprovisioning checklist</div>
        </div>
        <button class="btn-save" id="btn-add-agent" onclick="toggleAddPanel()">+ Start Offboarding</button>
      </header>
      <main class="wrap">

        <!-- Add Agent Panel (hidden by default) -->
        <div class="ob-add-panel" id="ob-add-panel">
          <h2 style="margin:0 0 16px;font-size:15px">Add Agent to Offboarding Queue</h2>

          <!-- CRM Search -->
          <div class="form-grid" style="margin-bottom:16px">
            <div class="field full">
              <label>Search CRM Roster</label>
              <div class="search-wrap">
                <input type="text" id="crm-search" class="field input" placeholder="Type name or email to pre-fill from CRM…" autocomplete="off"
                       style="padding:10px 12px;border:1px solid #E6E7E8;border-radius:8px;font-size:14px;background:#fafafa;width:100%">
                <div class="crm-results" id="crm-results" style="display:none"></div>
              </div>
            </div>
          </div>

          <form id="ob-add-form">
            <div class="form-grid">
              <div class="field">
                <label>Full Name *</label>
                <input type="text" id="ob-name" required placeholder="Jane Smith">
              </div>
              <div class="field">
                <label>Email *</label>
                <input type="email" id="ob-email" required placeholder="jane@example.com">
              </div>
              <div class="field">
                <label>Market Center</label>
                <input type="text" id="ob-mc" placeholder="Myrtle Beach">
              </div>
              <div class="field">
                <label>Last Day</label>
                <input type="date" id="ob-last-day">
              </div>
              <div class="field">
                <label>Departure Reason</label>
                <select id="ob-reason">
                  <option value="voluntary">Voluntary Resignation</option>
                  <option value="termination">Termination</option>
                  <option value="transfer">Transfer to Another Brokerage</option>
                  <option value="other">Other</option>
                </select>
              </div>
              <div class="field">
                <label>Book of Business To</label>
                <input type="text" id="ob-book-of-biz" placeholder="Who receives their leads / clients?">
              </div>
              <div class="field full">
                <label>Notes</label>
                <input type="text" id="ob-reason-notes" placeholder="Any additional context…">
              </div>
            </div>
            <div class="form-actions">
              <button type="submit" class="btn-save" id="ob-add-btn">Add to Queue</button>
              <button type="button" class="btn-save" style="background:#f0f0f0;color:#333" onclick="toggleAddPanel()">Cancel</button>
              <span class="form-msg" id="ob-add-msg"></span>
            </div>
          </form>
        </div>

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
    window.OFFBOARD_TOOLS   = <?= $toolsJson ?>;
    window.OFFBOARD_OPEN_ID = <?= (int)($_GET['open'] ?? 0) ?>;
  </script>
  <script src="assets/offboard.js"></script>
</body>
</html>
