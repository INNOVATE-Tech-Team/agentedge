<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();

$canCreate = is_leader();
$mcRows = local_db()->query("SELECT slug, name FROM market_centers WHERE enabled=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$mcNameMap = [];
foreach ($mcRows as $r) $mcNameMap[$r['slug']] = $r['name'];
$myMcSlugs = array_values(array_unique(array_filter(array_merge(my_mc_slugs(), [my_own_mc_slug()]))));
$isAdmin = is_admin();

// Which MCs this user is allowed to create events for.
$creatableMcs = $isAdmin ? $mcRows : array_values(array_filter($mcRows, fn($r) => in_array($r['slug'], $myMcSlugs, true)));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Event Planner — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .ep-filters { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:22px; }
    .ep-filter {
      padding:6px 16px; border:1.5px solid #e5e7eb; background:#fff; border-radius:20px;
      font-size:12px; font-weight:700; cursor:pointer; color:#555;
      transition:background .12s, border-color .12s, color .12s;
    }
    .ep-filter:hover { background:#f3f4f6; }
    .ep-filter.active { background:#82C112; color:#000; border-color:#82C112; }

    .ep-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:16px; margin-bottom:8px; }
    .ep-card {
      background:#fff; border-radius:8px; border:1px solid #e5e7eb; border-top-width:3px;
      padding:18px; display:flex; flex-direction:column; text-decoration:none; color:inherit;
      transition:box-shadow .15s;
    }
    .ep-card:hover { box-shadow:0 3px 14px rgba(0,0,0,.09); }
    .ep-card.type-conference { border-top-color:#3b82f6; }
    .ep-card.type-mc_award   { border-top-color:#82C112; }
    .ep-card-img { height:130px; margin:-18px -18px 14px; background-size:cover; background-position:center; border-radius:5px 5px 0 0; }

    .ep-head { display:flex; align-items:flex-start; justify-content:space-between; gap:6px; margin-bottom:8px; }
    .ep-badge { display:inline-block; padding:2px 8px; border-radius:3px; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; }
    .ep-badge-conference { background:#eff6ff; color:#1d4ed8; }
    .ep-badge-mc_award   { background:#eef5e8; color:#5b8e0d; }
    .ep-status { font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; padding:2px 8px; border-radius:3px; }
    .ep-status-draft     { background:#f3f4f6; color:#6b7280; }
    .ep-status-published { background:#f0fdf4; color:#15803d; }
    .ep-status-cancelled { background:#fef2f2; color:#b91c1c; }

    .ep-title { font-size:14px; font-weight:800; color:#111; line-height:1.35; margin-bottom:10px; }
    .ep-meta { display:flex; flex-direction:column; gap:5px; margin-bottom:10px; font-size:12px; color:#555; }
    .ep-desc { font-size:12px; color:#666; line-height:1.55; flex:1; margin-bottom:14px; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
    .ep-footer { display:flex; align-items:center; justify-content:space-between; margin-top:auto; gap:8px; font-size:11px; color:#888; }
    .ep-empty { grid-column:1/-1; text-align:center; padding:48px 0; color:#aaa; font-size:13px; }

    .ep-form { background:#f9fdf5; border:1px solid #d4edab; border-radius:10px; padding:20px 24px; margin-bottom:24px; display:none; }
    .ep-form.open { display:block; }
    .ep-form h3 { margin:0 0 14px; font-size:14px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:#5b8e0d; }
    .field-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:12px; }
    .field-row-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; margin-bottom:12px; }
    .field-full { margin-bottom:12px; }
    .field label { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#888; margin-bottom:4px; }
    .field input, .field select, .field textarea { width:100%; padding:8px 10px; border:1px solid #ccc; border-radius:6px; font-size:13px; box-sizing:border-box; }
    .field textarea { min-height:72px; resize:vertical; }
    .btn-primary { padding:9px 20px; background:#82C112; color:#000; border:none; border-radius:6px; font-weight:800; font-size:13px; cursor:pointer; }
    .btn-primary:hover { background:#5b8e0d; color:#fff; }
    .btn-sm { padding:4px 10px; font-size:11px; font-weight:700; border-radius:4px; border:none; cursor:pointer; background:#f0f0f0; color:#333; }
  </style>
</head>
<body>
  <div class="layout">
    <?php render_sidebar('event_planner', $agent); ?>
    <div class="content">
      <header class="content-top">
        <div class="content-title">Event Planner</div>
        <?php if ($canCreate): ?>
          <button class="btn-primary" id="toggle-form-btn" onclick="toggleForm()">+ New Event</button>
        <?php endif; ?>
      </header>

      <main class="wrap">
        <?php if ($canCreate): ?>
        <section class="card ep-form" id="ep-form" style="margin-bottom:20px">
          <h3>New Event</h3>
          <div class="field-full field">
            <label>Title *</label>
            <input type="text" id="ev-title" placeholder="e.g. Q3 Awards Night">
          </div>
          <div class="field-row">
            <div class="field">
              <label>Market Center *</label>
              <select id="ev-mc">
                <?php if ($isAdmin): ?><option value="">Company-wide (conference)</option><?php endif; ?>
                <?php foreach ($creatableMcs as $mc): ?>
                  <option value="<?= htmlspecialchars($mc['slug']) ?>"><?= htmlspecialchars($mc['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>Location</label>
              <input type="text" id="ev-location" placeholder="e.g. Marriott Grande Dunes">
            </div>
          </div>
          <div class="field-row-3">
            <div class="field">
              <label>Start Date *</label>
              <input type="date" id="ev-start">
            </div>
            <div class="field">
              <label>End Date</label>
              <input type="date" id="ev-end">
            </div>
            <div class="field">
              <label>Start Time</label>
              <input type="time" id="ev-time">
            </div>
          </div>
          <div class="field-row">
            <div class="field">
              <label>Capacity (optional)</label>
              <input type="number" min="1" id="ev-capacity" placeholder="Leave blank for unlimited">
            </div>
          </div>
          <div class="field-full field">
            <label>Description</label>
            <textarea id="ev-desc" placeholder="Details shown to attendees…"></textarea>
          </div>
          <div class="field-full field">
            <label>Cover Photo (optional)</label>
            <input type="file" id="ev-image" accept="image/jpeg,image/png,image/gif,image/webp">
            <div style="font-size:11px;color:#999;margin-top:4px">JPG, PNG, GIF, or WebP · max 8MB. Shown as a banner on the event and RSVP pages.</div>
          </div>
          <div style="display:flex; align-items:center; gap:16px">
            <button class="btn-primary" onclick="createEvent()">Create Draft</button>
            <button class="btn-sm" onclick="toggleForm()">Cancel</button>
          </div>
        </section>
        <?php endif; ?>

        <div class="ep-filters" id="ep-filters">
          <button class="ep-filter active" data-f="all">All</button>
          <button class="ep-filter" data-f="company">Company-Wide</button>
          <button class="ep-filter" data-f="mine">My Market Center</button>
        </div>

        <div class="ep-grid" id="ep-grid">
          <div class="ep-empty">Loading events…</div>
        </div>
      </main>
    </div>
  </div>

  <script>
  (function () {
    var MC_NAMES = <?= json_encode($mcNameMap) ?>;
    var MY_SLUGS = <?= json_encode($myMcSlugs) ?>;
    var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var allEvents = [];
    var activeFilter = 'all';

    function esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]);});
    }
    function parseDate(s) { var p = s.split('-'); return new Date(+p[0], +p[1]-1, +p[2]); }
    function formatRange(start, end) {
      var s = parseDate(start);
      var out = MONTHS[s.getMonth()] + ' ' + s.getDate() + ', ' + s.getFullYear();
      if (end && end !== start) {
        var e = parseDate(end);
        out += ' – ' + MONTHS[e.getMonth()] + ' ' + e.getDate() + ', ' + e.getFullYear();
      }
      return out;
    }

    function mcLabel(slug) { return slug === '' ? 'Company-wide' : (MC_NAMES[slug] || slug); }

    function renderCard(ev) {
      var typeLabel = ev.event_type === 'conference' ? 'Conference' : 'MC Award Event';
      var cap = ev.capacity ? (ev.registered + ' / ' + ev.capacity + ' registered') : (ev.registered + ' registered');
      var img = ev.image_url ? '<div class="ep-card-img" style="background-image:url(\'' + esc(ev.image_url) + '\')"></div>' : '';
      return '<a class="ep-card type-' + esc(ev.event_type) + '" href="event_planner_view.php?id=' + ev.id + '">'
        + img
        + '<div class="ep-head">'
        + '<span class="ep-badge ep-badge-' + esc(ev.event_type) + '">' + esc(typeLabel) + '</span>'
        + '<span class="ep-status ep-status-' + esc(ev.status) + '">' + esc(ev.status) + '</span>'
        + '</div>'
        + '<div class="ep-title">' + esc(ev.title) + '</div>'
        + '<div class="ep-meta">'
        + '<div>' + esc(formatRange(ev.start_date, ev.end_date)) + (ev.start_time ? ' · ' + esc(ev.start_time) : '') + '</div>'
        + '<div>' + esc(ev.location || mcLabel(ev.mc_slug)) + '</div>'
        + '<div>' + esc(mcLabel(ev.mc_slug)) + '</div>'
        + '</div>'
        + '<div class="ep-desc">' + esc(ev.description || '') + '</div>'
        + '<div class="ep-footer"><span>' + esc(cap) + '</span>' + (ev.can_manage ? '<span>Manage ›</span>' : '') + '</div>'
        + '</a>';
    }

    function renderGrid() {
      var grid = document.getElementById('ep-grid');
      var filtered = allEvents.filter(function (e) {
        if (activeFilter === 'company') return e.mc_slug === '';
        if (activeFilter === 'mine') return e.mc_slug !== '' && MY_SLUGS.indexOf(e.mc_slug) !== -1;
        return true;
      });
      if (!filtered.length) {
        grid.innerHTML = '<div class="ep-empty">No events found.</div>';
        return;
      }
      grid.innerHTML = filtered.map(renderCard).join('');
    }

    function loadEvents() {
      fetch('api/ep_events.php', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { allEvents = d.events || []; renderGrid(); })
        .catch(function () { document.getElementById('ep-grid').innerHTML = '<div class="ep-empty">Could not load events.</div>'; });
    }

    document.getElementById('ep-filters').addEventListener('click', function (e) {
      var btn = e.target.closest('.ep-filter');
      if (!btn) return;
      document.querySelectorAll('.ep-filter').forEach(function (b) { b.classList.remove('active'); });
      btn.classList.add('active');
      activeFilter = btn.dataset.f;
      renderGrid();
    });

    window.toggleForm = function () {
      var f = document.getElementById('ep-form');
      if (f) f.classList.toggle('open');
    };

    window.createEvent = function () {
      var title = document.getElementById('ev-title').value.trim();
      var start = document.getElementById('ev-start').value;
      if (!title) { alert('Title is required.'); return; }
      if (!start) { alert('Start date is required.'); return; }
      var fields = {
        action: 'create',
        title: title,
        mc_slug: document.getElementById('ev-mc').value,
        location: document.getElementById('ev-location').value.trim(),
        start_date: start,
        end_date: document.getElementById('ev-end').value,
        start_time: document.getElementById('ev-time').value,
        capacity: document.getElementById('ev-capacity').value,
        description: document.getElementById('ev-desc').value.trim(),
      };
      var imageFile = document.getElementById('ev-image').files[0];
      var fetchBody, fetchHeaders;
      if (imageFile) {
        var fd = new FormData();
        Object.entries(fields).forEach(function (kv) { fd.append(kv[0], kv[1]); });
        fd.append('image', imageFile);
        fetchBody = fd; fetchHeaders = {};
      } else {
        fetchBody = JSON.stringify(fields); fetchHeaders = { 'Content-Type': 'application/json' };
      }
      fetch('api/ep_events.php', {
        method: 'POST', credentials: 'same-origin', headers: fetchHeaders, body: fetchBody,
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (d.ok) { window.location.href = 'event_planner_view.php?id=' + d.id; }
        else alert(d.error || 'Error creating event.');
      });
    };

    loadEvents();
  })();
  </script>
</body>
</html>
