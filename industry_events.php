<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Industry Events — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    /* Filter tabs */
    .evt-filters { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:22px; }
    .evt-filter {
      padding:6px 16px;
      border:1.5px solid #e5e7eb;
      background:#fff;
      border-radius:20px;
      font-size:12px;
      font-weight:700;
      cursor:pointer;
      color:#555;
      transition:background .12s, border-color .12s, color .12s;
    }
    .evt-filter:hover { background:#f3f4f6; }
    .evt-filter.active { background:#82C112; color:#000; border-color:#82C112; }

    /* Event card grid */
    .evt-grid {
      display:grid;
      grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));
      gap:16px;
      margin-bottom:8px;
    }
    .evt-card {
      background:#fff;
      border-radius:8px;
      border:1px solid #e5e7eb;
      border-top-width:3px;
      padding:18px;
      display:flex;
      flex-direction:column;
      transition:box-shadow .15s;
    }
    .evt-card:hover { box-shadow:0 3px 14px rgba(0,0,0,.09); }

    /* Category accent colors */
    .evt-cat-nar        { border-top-color:#82C112; }
    .evt-cat-inman      { border-top-color:#3b82f6; }
    .evt-cat-training   { border-top-color:#f59e0b; }
    .evt-cat-technology { border-top-color:#8b5cf6; }
    .evt-cat-industry   { border-top-color:#6b7280; }
    .evt-cat-brokerage  { border-top-color:#06b6d4; }
    .evt-cat-leadership { border-top-color:#ea580c; }
    .evt-cat-finance    { border-top-color:#ca8a04; }

    .evt-head { display:flex; align-items:flex-start; justify-content:space-between; gap:6px; margin-bottom:8px; }
    .evt-badge {
      display:inline-block;
      padding:2px 8px;
      border-radius:3px;
      font-size:10px;
      font-weight:800;
      text-transform:uppercase;
      letter-spacing:.06em;
      white-space:nowrap;
    }
    .evt-badge-nar        { background:#eef5e8; color:#5b8e0d; }
    .evt-badge-inman      { background:#eff6ff; color:#1d4ed8; }
    .evt-badge-training   { background:#fffbeb; color:#92400e; }
    .evt-badge-technology { background:#f5f3ff; color:#5b21b6; }
    .evt-badge-industry   { background:#f3f4f6; color:#374151; }
    .evt-badge-brokerage  { background:#ecfeff; color:#0e7490; }
    .evt-badge-leadership { background:#fff7ed; color:#9a3412; }
    .evt-badge-finance    { background:#fefce8; color:#713f12; }

    .evt-star { font-size:13px; flex-shrink:0; color:#f59e0b; line-height:1; }
    .evt-name { font-size:14px; font-weight:800; color:#111; line-height:1.35; margin-bottom:10px; }
    .evt-meta { display:flex; flex-direction:column; gap:5px; margin-bottom:10px; }
    .evt-meta-row { display:flex; align-items:center; gap:7px; font-size:12px; color:#555; }
    .evt-meta-row svg { flex-shrink:0; color:#82C112; }
    .evt-desc {
      font-size:12px;
      color:#666;
      line-height:1.55;
      flex:1;
      margin-bottom:14px;
      display:-webkit-box;
      -webkit-line-clamp:3;
      -webkit-box-orient:vertical;
      overflow:hidden;
    }
    .evt-footer { display:flex; align-items:center; justify-content:space-between; margin-top:auto; gap:8px; }
    .evt-days {
      font-size:11px;
      font-weight:700;
      color:#5b8e0d;
      background:#f0f9e8;
      padding:3px 9px;
      border-radius:10px;
      white-space:nowrap;
    }
    .evt-days.soon { background:#fff4e0; color:#a07221; }
    .evt-days.very-soon { background:#fef2f2; color:#b91c1c; }
    .evt-link {
      display:inline-flex;
      align-items:center;
      gap:4px;
      padding:7px 14px;
      background:#82C112;
      color:#000;
      font-size:12px;
      font-weight:800;
      border-radius:4px;
      text-decoration:none;
      white-space:nowrap;
      transition:background .12s;
    }
    .evt-link:hover { background:#6ba30d; color:#000; }

    .evt-empty { grid-column:1/-1; text-align:center; padding:48px 0; color:#aaa; font-size:13px; }

    /* News strip */
    .news-section { margin-top:4px; }
    .news-section h2 {
      margin:0 0 12px;
      font-size:13px;
      font-weight:800;
      color:#444;
      display:flex;
      align-items:center;
      gap:8px;
    }
    .news-label { font-size:10px; font-weight:600; color:#aaa; }
    .news-item {
      padding:10px 14px;
      border-left:3px solid #3b82f6;
      background:#eff6ff;
      border-radius:0 6px 6px 0;
      margin-bottom:8px;
    }
    .news-item-title { font-size:13px; font-weight:700; color:#1d4ed8; margin-bottom:2px; }
    .news-item-title a { color:inherit; text-decoration:none; }
    .news-item-title a:hover { text-decoration:underline; }
    .news-item-date { font-size:10px; color:#aaa; margin-top:3px; }

    .hd-right { display:flex; align-items:center; gap:12px; }
    .cached-note { font-size:11px; color:#bbb; }
    .refresh-btn {
      font-size:11px;
      color:#82C112;
      cursor:pointer;
      font-weight:700;
      background:none;
      border:none;
      padding:0;
    }
    .refresh-btn:hover { text-decoration:underline; }
  </style>
</head>
<body>
  <div class="layout">
    <?php render_sidebar('industry_events', $agent); ?>
    <div class="content">
      <header class="content-top">
        <div class="content-title">Industry Events</div>
        <div class="hd-right">
          <span class="cached-note" id="cache-note"></span>
          <button class="refresh-btn" id="refresh-btn" onclick="loadEvents(true)">↺ Refresh</button>
        </div>
      </header>

      <main class="wrap">
        <section class="card" style="margin-bottom:20px">
          <p style="margin:0 0 18px;font-size:13px;color:#666;line-height:1.6">
            Major upcoming conferences, summits, and expos for real estate professionals.
            Auto-refreshes every 6 hours via curated sources + Inman live feed.
          </p>

          <div class="evt-filters" id="evt-filters">
            <button class="evt-filter active" data-cat="">All Events</button>
            <button class="evt-filter" data-cat="nar">NAR</button>
            <button class="evt-filter" data-cat="inman">Inman</button>
            <button class="evt-filter" data-cat="training">Training</button>
            <button class="evt-filter" data-cat="technology">Technology</button>
            <button class="evt-filter" data-cat="industry">Industry</button>
            <button class="evt-filter" data-cat="brokerage">Brokerage</button>
            <button class="evt-filter" data-cat="leadership">Leadership</button>
          </div>

          <div class="evt-grid" id="evt-grid">
            <div class="evt-empty">Loading events…</div>
          </div>
        </section>

        <section class="card news-section" id="news-section" style="display:none">
          <h2>Latest Event News <span class="news-label">via Inman</span></h2>
          <div id="news-list"></div>
        </section>
      </main>
    </div>
  </div>

  <script src="assets/app.js"></script>
  <script>
  (function () {
    var CAT_LABELS = {
      nar: 'NAR', inman: 'Inman', training: 'Training', technology: 'Technology',
      industry: 'Industry', brokerage: 'Brokerage', leadership: 'Leadership', finance: 'Finance'
    };
    var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var allEvents = [];
    var activeFilter = '';

    var ICON_CAL = '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><rect x="2" y="3" width="12" height="11" rx="1.5"/><path d="M5 1v4M11 1v4M2 7h12"/></svg>';
    var ICON_PIN = '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M8 1.5A4.5 4.5 0 0 1 12.5 6c0 3-4.5 8.5-4.5 8.5S3.5 9 3.5 6A4.5 4.5 0 0 1 8 1.5z"/><circle cx="8" cy="6" r="1.5"/></svg>';

    function parseDate(s) {
      var p = s.split('-');
      return new Date(+p[0], +p[1]-1, +p[2]);
    }

    function formatRange(start, end) {
      var s = parseDate(start);
      var sm = MONTHS[s.getMonth()], sd = s.getDate(), sy = s.getFullYear();
      if (!end || start === end) return sm + ' ' + sd + ', ' + sy;
      var e = parseDate(end);
      var em = MONTHS[e.getMonth()], ed = e.getDate(), ey = e.getFullYear();
      if (sm === em && sy === ey) return sm + ' ' + sd + '–' + ed + ', ' + sy;
      return sm + ' ' + sd + ' – ' + em + ' ' + ed + ', ' + ey;
    }

    function daysUntil(start) {
      var today = new Date(); today.setHours(0,0,0,0);
      var d = parseDate(start);
      var diff = Math.round((d - today) / 86400000);
      if (diff < 0) return null;
      if (diff === 0) return 'Today!';
      if (diff === 1) return 'Tomorrow';
      return diff + ' days';
    }

    function esc(s) {
      return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function renderCard(ev) {
      var cat   = ev.category || 'industry';
      var label = CAT_LABELS[cat] || cat.charAt(0).toUpperCase() + cat.slice(1);
      var days  = daysUntil(ev.start_date);
      var daysClass = 'evt-days';
      if (days) {
        var n = parseInt(days);
        if (!isNaN(n) && n <= 7)  daysClass += ' very-soon';
        else if (!isNaN(n) && n <= 30) daysClass += ' soon';
      }
      var daysHtml = days
        ? '<span class="' + daysClass + '">' + esc(days) + '</span>'
        : '<span class="evt-days" style="background:#f3f4f6;color:#9ca3af">Past</span>';
      var star = ev.featured ? '<span class="evt-star" title="Featured event">★</span>' : '';

      return '<div class="evt-card evt-cat-' + esc(cat) + '" data-cat="' + esc(cat) + '">'
        + '<div class="evt-head"><span class="evt-badge evt-badge-' + esc(cat) + '">' + esc(label) + '</span>' + star + '</div>'
        + '<div class="evt-name">' + esc(ev.name) + '</div>'
        + '<div class="evt-meta">'
        + '<div class="evt-meta-row">' + ICON_CAL + '<span>' + esc(formatRange(ev.start_date, ev.end_date)) + '</span></div>'
        + '<div class="evt-meta-row">' + ICON_PIN + '<span>' + esc(ev.location) + '</span></div>'
        + '</div>'
        + '<div class="evt-desc">' + esc(ev.description || '') + '</div>'
        + '<div class="evt-footer">'
        + daysHtml
        + '<a class="evt-link" href="' + esc(ev.url) + '" target="_blank" rel="noopener">Register ↗</a>'
        + '</div>'
        + '</div>';
    }

    function renderGrid() {
      var grid = document.getElementById('evt-grid');
      var filtered = activeFilter
        ? allEvents.filter(function (e) { return e.category === activeFilter; })
        : allEvents;
      if (!filtered.length) {
        var label = activeFilter ? (CAT_LABELS[activeFilter] || activeFilter) : '';
        grid.innerHTML = '<div class="evt-empty">No ' + (label ? esc(label) + ' ' : '') + 'upcoming events found.</div>';
        return;
      }
      grid.innerHTML = filtered.map(renderCard).join('');
    }

    window.loadEvents = function (force) {
      var url = 'api/industry_events.php' + (force ? '?t=' + Date.now() : '');
      document.getElementById('evt-grid').innerHTML = '<div class="evt-empty">Loading events…</div>';
      document.getElementById('cache-note').textContent = '';
      document.getElementById('refresh-btn').disabled = true;
      fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          allEvents = d.events || [];
          renderGrid();

          if (d.cached_at) {
            var at = new Date(d.cached_at);
            document.getElementById('cache-note').textContent =
              'Updated ' + at.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
          }

          var news = d.news || [];
          var sec  = document.getElementById('news-section');
          var list = document.getElementById('news-list');
          if (news.length) {
            sec.style.display = '';
            list.innerHTML = news.map(function (n) {
              return '<div class="news-item">'
                + '<div class="news-item-title"><a href="' + esc(n.url) + '" target="_blank" rel="noopener">' + esc(n.title) + '</a></div>'
                + '<div class="news-item-date">' + esc(n.date) + '</div>'
                + '</div>';
            }).join('');
          } else {
            sec.style.display = 'none';
          }
        })
        .catch(function () {
          document.getElementById('evt-grid').innerHTML =
            '<div class="evt-empty">Could not load events. Please try refreshing.</div>';
        })
        .finally(function () {
          document.getElementById('refresh-btn').disabled = false;
        });
    };

    document.getElementById('evt-filters').addEventListener('click', function (e) {
      var btn = e.target.closest('.evt-filter');
      if (!btn) return;
      document.querySelectorAll('.evt-filter').forEach(function (b) { b.classList.remove('active'); });
      btn.classList.add('active');
      activeFilter = btn.dataset.cat;
      renderGrid();
    });

    loadEvents(false);
  })();
  </script>
</body>
</html>
