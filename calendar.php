<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
$agent    = require_login();
$is_admin = is_admin();
$is_leader = is_leader();
$cal_id   = cfg()['gcal_calendar_id'] ?? 'training@innovateonline.com';
$events_cal_id = cfg()['gcal_events_calendar_id'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Calendar — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
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
              <button class="cal-tab" data-filter="training">Training <span class="cal-tab-count"></span></button>
              <button class="cal-tab" data-filter="events">Events <span class="cal-tab-count"></span></button>
              <?php if ($is_leader): ?>
              <button class="cal-tab" data-filter="bic">Birthdays &amp; Anniversaries <span class="cal-tab-count"></span></button>
              <?php endif; ?>
              <button class="cal-tab" data-filter="mycal">My Calendar <span class="cal-tab-count"></span></button>
            </div>
            <div class="cal-nav">
              <button class="btn-cal-nav" id="cal-prev">&#8592;</button>
              <strong class="cal-month-label" id="cal-month-label"></strong>
              <button class="btn-cal-nav" id="cal-next">&#8594;</button>
            </div>
          </div>
          <!-- Personal calendar sync bar — shown when "My Calendar" tab active; opens the connect modal -->
          <div id="cal-personal-bar" style="display:none;align-items:center;gap:8px;margin-bottom:14px;flex-wrap:wrap">
            <span style="font-size:12px;color:#888" id="cal-personal-status">Loading…</span>
            <button class="cal-rsvp-btn" onclick="openPersonalCalModal()" style="margin-left:auto">&#128197; Sync Calendar URL</button>
          </div>
          <!-- My Calendar tab — inbound: connect a personal ICS feed; outbound: subscribe to company calendar -->
          <div id="cal-mycal-bar" style="display:none;flex-direction:column;gap:8px;margin-bottom:14px;padding:12px 14px;background:#f8f8f8;border:1px solid #eee;border-radius:8px">
            <div id="cal-mycal-connected" style="display:none">
              <div style="font-size:13px;color:#444;margin-bottom:8px"><strong>Calendar connected.</strong> Your personal events appear in teal below.</div>
              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <button id="cal-mycal-change-btn" class="cal-rsvp-btn" style="font-size:12px">Change URL</button>
                <button id="cal-mycal-remove-btn" style="font-size:12px;padding:5px 10px;border:1px solid #fcc;background:white;border-radius:4px;cursor:pointer;color:#c00">Disconnect</button>
              </div>
            </div>
            <div id="cal-mycal-setup">
              <div style="font-size:13px;font-weight:700;color:#333;margin-bottom:4px">Connect your personal calendar</div>
              <div style="font-size:12px;color:#666;margin-bottom:10px">Paste your Google Calendar, Apple Calendar, or Outlook <strong>ICS link</strong>.<br><span style="color:#999">Google: Settings &rarr; [Your calendar] &rarr; "Secret address in iCal format"</span></div>
              <div style="display:flex;gap:8px;align-items:center">
                <input type="url" id="cal-mycal-url" placeholder="https://calendar.google.com/calendar/ical/&hellip;" style="flex:1;padding:8px 12px;border:1px solid #ccc;border-radius:6px;font-size:13px;min-width:0">
                <button id="cal-mycal-save-btn" class="cal-rsvp-btn cal-rsvp-active" style="white-space:nowrap">Connect</button>
              </div>
              <div id="cal-mycal-msg" style="font-size:12px;margin-top:6px"></div>
            </div>
            <div id="cal-mycal-export" style="border-top:1px solid #e8e8e8;padding-top:10px;margin-top:2px">
              <div style="font-size:13px;font-weight:700;color:#333;margin-bottom:3px">Add company events to your calendar</div>
              <div style="font-size:12px;color:#666;margin-bottom:8px">
                Copy this URL and subscribe in Google Calendar, Apple Calendar, or Outlook.<br>
                <span style="color:#999">Google: Other calendars (+) &rarr; "From URL" &nbsp;|&nbsp; Apple: File &rarr; New Calendar Subscription &nbsp;|&nbsp; Outlook: Add calendar &rarr; From internet</span>
              </div>
              <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px">
                <input type="url" id="cal-feed-url" readonly placeholder="Loading…"
                       style="flex:1;padding:7px 10px;border:1px solid #ccc;border-radius:6px;font-size:12px;background:#f5f5f5;min-width:0;color:#555">
                <button id="cal-feed-copy-btn" class="cal-rsvp-btn" style="white-space:nowrap">Copy</button>
              </div>
              <div>
                <button id="cal-feed-regen-btn" style="font-size:11px;padding:4px 8px;border:1px solid #ccc;background:white;border-radius:4px;cursor:pointer;color:#666">Regenerate URL</button>
                <span id="cal-feed-msg" style="font-size:11px;color:#888;margin-left:8px"></span>
              </div>
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
          <!-- Events tab actions — shown/hidden by JS -->
          <div id="cal-events-bar" style="display:none;align-items:center;gap:8px;margin-bottom:14px;flex-wrap:wrap">
            <?php if ($events_cal_id !== ''): ?>
            <a id="cal-events-subscribe-btn" class="cal-rsvp-btn"
               href="https://calendar.google.com/calendar/r?cid=<?= urlencode($events_cal_id) ?>"
               target="_blank" rel="noopener" style="text-decoration:none">&#128197; Subscribe to Events Calendar</a>
            <?php else: ?>
            <span style="font-size:12px;color:#888">Events calendar not configured yet.</span>
            <?php endif; ?>
            <?php if ($is_admin): ?>
            <button id="cal-add-events-btn" class="cal-rsvp-btn cal-rsvp-active">+ Add Event</button>
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
        <label class="cal-field-label">Capacity <span style="color:#888;font-weight:400">(optional — extra RSVPs are waitlisted)</span>
          <input type="number" min="0" id="cal-ev-capacity" class="cal-field-input" placeholder="No limit">
        </label>
        <div id="cal-modal-err" class="cal-modal-err" style="display:none"></div>
        <div style="margin-top:14px;border-top:1px solid #eee;padding-top:10px">
          <div class="cal-field-label" style="margin-bottom:6px">Attendees</div>
          <div id="cal-ev-attendees" style="max-height:160px;overflow-y:auto"></div>
        </div>
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

  <!-- Company event modal (admin only) — mirrors the training modal above but
       posts to event_action.php / a separate calendar+RSVP pool. -->
  <?php if ($is_admin): ?>
  <div id="cal-events-modal-overlay" class="cal-modal-overlay" style="display:none">
    <div class="cal-modal">
      <div class="cal-modal-header">
        <span id="cal-ev2-modal-title">Add Event</span>
        <button id="cal-ev2-modal-close" class="cal-modal-close">&times;</button>
      </div>
      <div class="cal-modal-body">
        <input type="hidden" id="cal-ev2-id">
        <label class="cal-field-label">Title <span style="color:#c0392b">*</span>
          <input type="text" id="cal-ev2-title" class="cal-field-input" placeholder="e.g. Second Quarter Sales Meeting">
        </label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <label class="cal-field-label">Start Date <span style="color:#c0392b">*</span>
            <input type="date" id="cal-ev2-date" class="cal-field-input">
          </label>
          <label class="cal-field-label">End Date <span style="color:#888;font-weight:400">(optional)</span>
            <input type="date" id="cal-ev2-end-date" class="cal-field-input">
          </label>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <label class="cal-field-label">Start Time <span style="color:#888;font-weight:400">(optional)</span>
            <input type="time" id="cal-ev2-start-time" class="cal-field-input">
          </label>
          <label class="cal-field-label">End Time <span style="color:#888;font-weight:400">(optional)</span>
            <input type="time" id="cal-ev2-end-time" class="cal-field-input">
          </label>
        </div>
        <label class="cal-field-label">Location
          <input type="text" id="cal-ev2-location" class="cal-field-input" placeholder="e.g. Zoom / 1309 Professional Dr">
        </label>
        <label class="cal-field-label">Description
          <textarea id="cal-ev2-description" class="cal-field-input" rows="3" placeholder="Details, links, what to bring…"></textarea>
        </label>
        <label class="cal-field-label">Capacity <span style="color:#888;font-weight:400">(optional — extra RSVPs are waitlisted)</span>
          <input type="number" min="0" id="cal-ev2-capacity" class="cal-field-input" placeholder="No limit">
        </label>
        <div id="cal-ev2-modal-err" class="cal-modal-err" style="display:none"></div>
        <div style="margin-top:14px;border-top:1px solid #eee;padding-top:10px">
          <div class="cal-field-label" style="margin-bottom:6px">Attendees</div>
          <div id="cal-ev2-attendees" style="max-height:160px;overflow-y:auto"></div>
        </div>
      </div>
      <div class="cal-modal-footer">
        <button id="cal-ev2-delete" class="cal-ev-delete-btn" style="display:none">Delete Event</button>
        <div style="display:flex;gap:8px;margin-left:auto">
          <button id="cal-ev2-modal-cancel" class="cal-modal-cancel-btn">Cancel</button>
          <button id="cal-ev2-save" class="cal-modal-save-btn">Save Event</button>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Personal calendar sync modal -->
  <div id="pc-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:10px;width:min(480px,95vw);padding:26px;position:relative">
      <button onclick="closePersonalCalModal()" style="position:absolute;top:14px;right:14px;background:none;border:none;font-size:20px;cursor:pointer;color:#888">&times;</button>
      <h3 style="margin:0 0 6px;font-size:16px;font-weight:800">Sync Your Personal Calendar</h3>
      <p style="margin:0 0 16px;font-size:13px;color:#666">Paste your calendar's secret ICS link below. Your personal events will appear on the <strong>My Calendar</strong> tab.</p>
      <div style="background:#f8fdf2;border:1px solid #d4edab;border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:12px;color:#555;line-height:1.6">
        <strong style="color:#3a6b1a">How to get your ICS URL:</strong><br>
        <strong>Google Calendar:</strong> Settings (⚙) → click a calendar → Integrate calendar → copy <em>Secret address in iCal format</em><br>
        <strong>Apple Calendar:</strong> Right-click a calendar → Share Calendar → Public Calendar → copy the webcal:// URL (change webcal:// to https://)<br>
        <strong>Outlook:</strong> Calendar settings → Shared calendars → Publish a calendar → copy the ICS link
      </div>
      <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:5px">ICS Calendar URL</label>
      <input id="pc-url-input" type="url" placeholder="https://calendar.google.com/calendar/ical/…/basic.ics"
        style="width:100%;padding:9px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;box-sizing:border-box;margin-bottom:6px">
      <p id="pc-url-note" style="margin:0 0 14px;font-size:11px;color:#aaa">Leave blank to remove the sync.</p>
      <div id="pc-modal-err" style="display:none;color:#c0392b;font-size:12px;margin-bottom:10px"></div>
      <div style="display:flex;gap:8px">
        <button onclick="savePersonalCalUrl()" style="padding:9px 22px;background:#82C112;color:#000;border:none;border-radius:6px;font-weight:800;font-size:13px;cursor:pointer">Save</button>
        <button onclick="closePersonalCalModal()" style="padding:9px 14px;border:1px solid #ccc;background:#fff;color:#555;font-size:13px;border-radius:6px;cursor:pointer">Cancel</button>
        <span id="pc-modal-msg" style="font-size:12px;color:#5b8e0d;align-self:center;margin-left:6px"></span>
      </div>
    </div>
  </div>
  <script>const CAL_IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;</script>
  <script src="assets/calendar.js"></script>
  <script>
  function openPersonalCalModal(){
    fetch('api/personal_cal.php?month='+new Date().getFullYear()+'-01',{credentials:'same-origin'})
      .then(r=>r.json()).then(d=>{
        document.getElementById('pc-url-input').value=d.has_url?'(saved — paste a new URL to change or leave blank to remove)':'';
        document.getElementById('pc-modal-err').style.display='none';
        document.getElementById('pc-modal-msg').textContent='';
      }).catch(()=>{});
    document.getElementById('pc-modal-overlay').style.display='flex';
  }
  function closePersonalCalModal(){
    document.getElementById('pc-modal-overlay').style.display='none';
  }
  function savePersonalCalUrl(){
    const url=document.getElementById('pc-url-input').value.trim();
    const err=document.getElementById('pc-modal-err');
    const msg=document.getElementById('pc-modal-msg');
    if(url&&!url.startsWith('https://')){
      err.textContent='URL must start with https://';err.style.display='';return;
    }
    const saveUrl=url.startsWith('(saved')?'':url;
    fetch('api/personal_cal.php',{
      method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({url:saveUrl}),
    }).then(r=>r.json()).then(d=>{
      if(d.ok){
        msg.textContent='Saved!';
        setTimeout(()=>{
          closePersonalCalModal();
          if(typeof calDraw==='function') calDraw();
        },800);
      } else { err.textContent=d.error||'Save failed'; err.style.display=''; }
    }).catch(()=>{err.textContent='Network error';err.style.display='';});
  }
  document.getElementById('pc-modal-overlay').addEventListener('click',function(e){
    if(e.target===this)closePersonalCalModal();
  });
  </script>
</body>
</html>
