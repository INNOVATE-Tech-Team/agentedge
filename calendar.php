<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
$agent    = require_login();
$is_admin = is_admin();
$cal_id   = cfg()['gcal_calendar_id'] ?? 'training@innovateonline.com';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Calendar — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
  <div class="layout">
    <?php render_sidebar('calendar', $agent); ?>
    <div class="content">
      <header class="content-top">
        <div style="display:flex;align-items:center;gap:10px">
          <div class="content-title">Company Calendar</div>
          <span id="cal-mc-badge" class="cal-mc-badge"></span>
        </div>
      </header>
      <main class="wrap">
        <section class="card">
          <div class="cal-toolbar">
            <div class="cal-tabs">
              <button class="cal-tab cal-tab-active" data-filter="all">All <span class="cal-tab-count"></span></button>
              <button class="cal-tab" data-filter="company">Company <span class="cal-tab-count"></span></button>
              <button class="cal-tab" data-filter="mc" id="cal-tab-mc">Market Center <span class="cal-tab-count"></span></button>
              <button class="cal-tab" data-filter="bic">BIC <span class="cal-tab-count"></span></button>
              <button class="cal-tab" data-filter="dotloop" id="cal-tab-tx">My Transactions <span class="cal-tab-count"></span></button>
              <button class="cal-tab" data-filter="training">Training <span class="cal-tab-count"></span></button>
            </div>
            <div class="cal-nav">
              <button class="btn-cal-nav" id="cal-prev">&#8592;</button>
              <strong class="cal-month-label" id="cal-month-label"></strong>
              <button class="btn-cal-nav" id="cal-next">&#8594;</button>
            </div>
          </div>
          <!-- Training tab actions — shown/hidden by JS -->
          <div id="cal-training-bar" style="display:none;align-items:center;gap:8px;margin-bottom:14px;flex-wrap:wrap">
            <a id="cal-subscribe-btn" class="cal-rsvp-btn"
               href="https://calendar.google.com/calendar/r?cid=<?= urlencode($cal_id) ?>"
               target="_blank" rel="noopener" style="text-decoration:none">&#128197; Subscribe to Training Calendar</a>
            <?php if ($is_admin): ?>
            <button id="cal-add-event-btn" class="cal-rsvp-btn cal-rsvp-active">+ Add Event</button>
            <?php endif; ?>
          </div>
          <div class="cal-grid" id="cal-grid"></div>
        </section>
        <section class="card" style="margin-top:16px">
          <div class="cal-list-header">
            <span class="cal-list-title" id="cal-list-title">Events</span>
          </div>
          <div id="cal-event-list-body"></div>
        </section>
      </main>
    </div>
  </div>

  <!-- Training event modal (admin only) -->
  <?php if ($is_admin): ?>
  <div id="cal-modal-overlay" class="cal-modal-overlay" style="display:none">
    <div class="cal-modal">
      <div class="cal-modal-header">
        <span id="cal-modal-title">Add Training Event</span>
        <button id="cal-modal-close" class="cal-modal-close">&times;</button>
      </div>
      <div class="cal-modal-body">
        <input type="hidden" id="cal-ev-id">
        <label class="cal-field-label">Title <span style="color:#c0392b">*</span>
          <input type="text" id="cal-ev-title" class="cal-field-input" placeholder="e.g. TRACK+ Training Session">
        </label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <label class="cal-field-label">Start Date <span style="color:#c0392b">*</span>
            <input type="date" id="cal-ev-date" class="cal-field-input">
          </label>
          <label class="cal-field-label">End Date <span style="color:#888;font-weight:400">(optional)</span>
            <input type="date" id="cal-ev-end-date" class="cal-field-input">
          </label>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <label class="cal-field-label">Start Time <span style="color:#888;font-weight:400">(optional)</span>
            <input type="time" id="cal-ev-start-time" class="cal-field-input">
          </label>
          <label class="cal-field-label">End Time <span style="color:#888;font-weight:400">(optional)</span>
            <input type="time" id="cal-ev-end-time" class="cal-field-input">
          </label>
        </div>
        <label class="cal-field-label">Location
          <input type="text" id="cal-ev-location" class="cal-field-input" placeholder="e.g. Zoom / 1309 Professional Dr">
        </label>
        <label class="cal-field-label">Description
          <textarea id="cal-ev-description" class="cal-field-input" rows="3" placeholder="Details, links, what to bring…"></textarea>
        </label>
        <div id="cal-modal-err" class="cal-modal-err" style="display:none"></div>
      </div>
      <div class="cal-modal-footer">
        <button id="cal-ev-delete" class="cal-ev-delete-btn" style="display:none">Delete Event</button>
        <div style="display:flex;gap:8px;margin-left:auto">
          <button id="cal-modal-cancel" class="cal-modal-cancel-btn">Cancel</button>
          <button id="cal-ev-save" class="cal-modal-save-btn">Save Event</button>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <script>const CAL_IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;</script>
  <script src="assets/calendar.js"></script>
</body>
</html>
