<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/onboard_tools.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }

$agent = require_login();
require_admin_page();

$tools = onboard_tools();
// Build a map keyed by tool key for JS
$toolsJson = json_encode(array_values($tools));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Onboarding — AgentEdge</title>
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
        <button class="btn-save" id="btn-add-agent" onclick="toggleAddPanel()">+ Add Agent</button>
      </header>
      <main class="wrap">

        <!-- Add Agent Panel (hidden by default) -->
        <div class="ob-add-panel" id="ob-add-panel">
          <h2 style="margin:0 0 16px;font-size:15px">Add Agent to Onboarding Queue</h2>

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
                <label>License State</label>
                <select id="ob-state">
                  <option value="">Select state…</option>
                  <?php foreach (['FL','GA','SC','NC','TN','VA','MD','DE','NJ','PA','OH','MA','RI','NH'] as $st): ?>
                    <option value="<?= h($st) ?>"><?= h($st) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label>Role</label>
                <select id="ob-role">
                  <option value="agent">Agent</option>
                  <option value="mc_leader">Market Center Leader</option>
                  <option value="broker_in_charge">Broker in Charge</option>
                  <option value="recruiter">Recruiter</option>
                  <option value="retention_admin">Retention Admin</option>
                </select>
              </div>
              <div class="field">
                <label>Start Date</label>
                <input type="date" id="ob-start">
              </div>
              <div class="field">
                <label>Sponsor / Recruited By</label>
                <input type="text" id="ob-sponsor" placeholder="Who recruited them?">
              </div>
              <div class="field full">
                <label>Notes</label>
                <input type="text" id="ob-notes" placeholder="Anything the office should know…">
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
    window.ONBOARD_TOOLS = <?= $toolsJson ?>;
  </script>
  <script src="assets/onboard.js"></script>
</body>
</html>
