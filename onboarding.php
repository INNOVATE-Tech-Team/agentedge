<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/nav.php';
$agent = require_login();
require_admin_page(); // super_admin / retention_admin only
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
        <div class="content-title">Onboard a New Agent</div>
        <div class="content-hello">Add an agent to the roster and kick off their checklist</div>
      </header>
      <main class="wrap">
        <div class="grid2">
          <section class="card">
            <h2>New Agent</h2>
            <div id="onb-note" class="banner" hidden></div>
            <form id="onb-form">
              <div class="form-grid">
                <div class="field full"><label>Full Name *</label><input id="o-full_name" type="text" required></div>
                <div class="field"><label>Email</label><input id="o-email" type="email"></div>
                <div class="field"><label>Phone</label><input id="o-phone" type="tel"></div>
                <div class="field"><label>Market Center</label>
                  <select id="o-market_center_id"><option value="">— Select —</option></select></div>
                <div class="field"><label>Role</label>
                  <select id="o-role">
                    <option value="agent">Agent</option>
                    <option value="mc_leader">Market Center Leader</option>
                    <option value="broker_in_charge">Broker in Charge</option>
                    <option value="recruiter">Recruiter</option>
                    <option value="retention_admin">Retention Admin</option>
                  </select></div>
                <div class="field"><label>Sponsor (recruited by)</label>
                  <input id="o-sponsor" list="sponsors" placeholder="Type a name…" autocomplete="off">
                  <datalist id="sponsors"></datalist></div>
                <div class="field"><label>Start Date</label><input id="o-start_date" type="date"></div>
                <div class="field full"><label>Notes</label><input id="o-notes" type="text" placeholder="Anything the office should know…"></div>
              </div>
              <div class="form-actions">
                <button type="submit" class="btn-save" id="onb-btn">Create agent</button>
                <span class="form-msg" id="onb-msg"></span>
              </div>
            </form>
          </section>

          <section class="card">
            <h2>Onboarding Checklist</h2>
            <p class="form-sub">The standard steps to fully bring an agent online. Creating the
              agent above completes the first two automatically.</p>
            <ul class="checklist">
              <li>Create agent record &amp; add to roster</li>
              <li>Assign Market Center &amp; sponsor</li>
              <li>Create their login (Perfex)</li>
              <li>Send welcome email &amp; portal invite</li>
              <li>Collect license, W-9 &amp; ICA paperwork</li>
              <li>Add to Darwin (cap &amp; commissions)</li>
              <li>Enroll in new-agent training</li>
              <li>Set up email, signature &amp; business cards</li>
              <li>Introduce in team channel</li>
            </ul>
            <p class="src-note">Checklist tracking (per-agent progress) is coming next.</p>
          </section>
        </div>
      </main>
    </div>
  </div>
  <script src="assets/onboard.js"></script>
</body>
</html>
