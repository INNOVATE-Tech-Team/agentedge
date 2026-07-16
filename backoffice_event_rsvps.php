<?php
// Admin dashboard aggregating RSVPs across Training and Company "Events" calendar
// items — one place to see registrants/waitlists instead of opening each event's
// edit modal on the Company Calendar one at a time.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
$agent = require_login();
require_admin_page();

$rows = local_db()->query("
    SELECT id, event_id, event_title, event_date, agent_name, agent_email, status, rsvped_at, 'training' AS scope
    FROM training_rsvps
    UNION ALL
    SELECT id, event_id, event_title, event_date, agent_name, agent_email, status, rsvped_at, 'events' AS scope
    FROM events_rsvps
    ORDER BY event_date, event_title, status, rsvped_at
")->fetchAll(PDO::FETCH_ASSOC);

$events = [];
foreach ($rows as $r) {
    $key = $r['scope'] . '|' . $r['event_id'];
    if (!isset($events[$key])) {
        $events[$key] = [
            'scope'      => $r['scope'],
            'event_id'   => $r['event_id'],
            'title'      => $r['event_title'],
            'date'       => $r['event_date'],
            'registered' => 0,
            'waitlisted' => 0,
            'attendees'  => [],
        ];
    }
    if ($r['status'] === 'registered') $events[$key]['registered']++;
    else                                $events[$key]['waitlisted']++;
    $events[$key]['attendees'][] = [
        'id'         => (int)$r['id'],
        'name'       => $r['agent_name'],
        'email'      => $r['agent_email'],
        'status'     => $r['status'],
        'rsvped_at'  => $r['rsvped_at'],
    ];
}
$events = array_values($events);
usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));

// ── CSV export for one event ────────────────────────────────────────────────
if (isset($_GET['export_csv'])) {
    $scope    = trim($_GET['scope']    ?? '');
    $event_id = trim($_GET['event_id'] ?? '');
    $match    = null;
    foreach ($events as $e) {
        if ($e['scope'] === $scope && $e['event_id'] === $event_id) { $match = $e; break; }
    }
    if (!$match) { http_response_code(404); exit('Event not found.'); }

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9\-]+/i', '-', $match['title']) . '-rsvps.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Email', 'Status', 'RSVP\'d At']);
    foreach ($match['attendees'] as $a) {
        fputcsv($out, [$a['name'], $a['email'], $a['status'], $a['rsvped_at']]);
    }
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Event RSVPs — Back Office — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .rsvp-tabs { display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap }
    .rsvp-tab { padding:6px 14px; border:1px solid var(--border); border-radius:20px; background:#fff; font-size:12px; font-weight:700; cursor:pointer; color:#555 }
    .rsvp-tab.active { background:#111; color:#fff; border-color:#111 }
    .rsvp-row { border-bottom:1px solid var(--border) }
    .rsvp-row:last-child { border-bottom:0 }
    .rsvp-row-head { display:flex; align-items:center; gap:12px; padding:12px 4px; cursor:pointer }
    .rsvp-row-head:hover { background:#fafafa }
    .rsvp-date { font-size:11px; font-weight:700; color:var(--faint); text-transform:uppercase; letter-spacing:.4px; width:110px; flex:none }
    .rsvp-title { flex:1; font-size:14px; font-weight:700; min-width:0 }
    .rsvp-scope-badge { font-size:10px; font-weight:800; border-radius:12px; padding:3px 9px; white-space:nowrap; flex:none }
    .rsvp-scope-badge.training { background:#82C112; color:#111 }
    .rsvp-scope-badge.events   { background:#7c3aed; color:#fff }
    .rsvp-counts { font-size:12px; color:#888; flex:none; white-space:nowrap }
    .rsvp-caret { flex:none; font-size:10px; color:#aaa; transition:transform .15s }
    .rsvp-row.open .rsvp-caret { transform:rotate(90deg) }
    .rsvp-attendees { display:none; padding:0 4px 16px 122px }
    .rsvp-row.open .rsvp-attendees { display:block }
    .rsvp-attendee-table { width:100%; border-collapse:collapse; font-size:12px }
    .rsvp-attendee-table th { text-align:left; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#aaa; padding:5px 8px; border-bottom:1px solid #eee }
    .rsvp-attendee-table td { padding:6px 8px; border-bottom:1px solid #f5f5f5 }
    .rsvp-status-badge { font-size:10px; font-weight:800; border-radius:10px; padding:2px 8px }
    .rsvp-status-badge.registered { background:#eef5e8; color:#5b8e0d }
    .rsvp-status-badge.waitlisted { background:#fff4e0; color:#a06000 }
    .rsvp-remove-btn { font-size:11px; font-weight:700; padding:3px 9px; border:none; border-radius:4px; background:#fee2e2; color:#c00; cursor:pointer }
    .rsvp-remove-btn:hover { background:#fdc9c9 }
    .rsvp-export-link { font-size:11px; font-weight:700; color:#82C112; text-decoration:none; display:inline-block; margin-top:8px }
    .rsvp-empty { padding:24px; text-align:center; color:#bbb; font-size:13px }
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('bo_event_rsvps', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Event RSVPs</div>
    </header>
    <main class="wrap">
      <section class="card" style="padding:20px 24px">
        <div class="rsvp-tabs">
          <button class="rsvp-tab" data-scope="all">All</button>
          <button class="rsvp-tab" data-scope="training">Training</button>
          <button class="rsvp-tab" data-scope="events">Events</button>
          <span style="flex:1"></span>
          <button class="rsvp-tab" data-when="upcoming">Upcoming</button>
          <button class="rsvp-tab" data-when="past">Past</button>
          <button class="rsvp-tab" data-when="all">All Dates</button>
        </div>
        <div id="rsvp-list"></div>
      </section>
    </main>
  </div>
</div>

<script>
var EVENTS = <?= json_encode($events) ?>;
var TODAY  = <?= json_encode(date('Y-m-d')) ?>;
var scopeFilter = 'all';
var whenFilter  = 'upcoming';

function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c]; });
}

function fmtDate(iso) {
  var p = iso.split('-');
  var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  return MONTHS[+p[1]-1] + ' ' + (+p[2]) + ', ' + p[0];
}

function scopeLabel(s) { return s === 'training' ? 'Training' : 'Events'; }

function visibleEvents() {
  return EVENTS.filter(function (e) {
    if (scopeFilter !== 'all' && e.scope !== scopeFilter) return false;
    if (whenFilter === 'upcoming' && e.date < TODAY) return false;
    if (whenFilter === 'past'     && e.date >= TODAY) return false;
    return true;
  });
}

function render() {
  var vis = visibleEvents();
  var list = document.getElementById('rsvp-list');
  if (!vis.length) {
    list.innerHTML = '<div class="rsvp-empty">No events match this filter.</div>';
    return;
  }
  list.innerHTML = vis.map(function (e, i) {
    var attendeeRows = e.attendees.map(function (a) {
      return '<tr>'
        + '<td>' + esc(a.name || '—') + '</td>'
        + '<td>' + esc(a.email) + '</td>'
        + '<td><span class="rsvp-status-badge ' + a.status + '">' + (a.status === 'registered' ? 'Registered' : 'Waitlisted') + '</span></td>'
        + '<td>' + esc(a.rsvped_at || '') + '</td>'
        + '<td style="text-align:right"><button class="rsvp-remove-btn" onclick="removeAttendee(event,\'' + e.scope + '\',' + a.id + ')">Remove</button></td>'
        + '</tr>';
    }).join('');
    var exportUrl = '?export_csv=1&scope=' + encodeURIComponent(e.scope) + '&event_id=' + encodeURIComponent(e.event_id);
    return '<div class="rsvp-row" data-idx="' + i + '">'
      + '<div class="rsvp-row-head" onclick="toggleRow(this)">'
      +   '<span class="rsvp-caret">&#9654;</span>'
      +   '<span class="rsvp-date">' + fmtDate(e.date) + '</span>'
      +   '<span class="rsvp-title">' + esc(e.title) + '</span>'
      +   '<span class="rsvp-scope-badge ' + e.scope + '">' + scopeLabel(e.scope) + '</span>'
      +   '<span class="rsvp-counts">' + e.registered + ' registered' + (e.waitlisted ? ', ' + e.waitlisted + ' waitlisted' : '') + '</span>'
      + '</div>'
      + '<div class="rsvp-attendees">'
      +   (e.attendees.length
            ? '<table class="rsvp-attendee-table"><thead><tr><th>Name</th><th>Email</th><th>Status</th><th>RSVP\'d At</th><th></th></tr></thead><tbody>' + attendeeRows + '</tbody></table>'
            : '<div style="color:#bbb;font-size:12px;padding:8px 0">No RSVPs yet.</div>')
      +   '<a class="rsvp-export-link" href="' + exportUrl + '">&#8595; Export CSV</a>'
      + '</div>'
      + '</div>';
  }).join('');
}

function toggleRow(head) {
  head.parentElement.classList.toggle('open');
}

function removeAttendee(evt, scope, id) {
  evt.stopPropagation();
  if (!confirm('Remove this registrant? If they were registered, the next waitlisted agent (if any) will be promoted and notified.')) return;
  fetch('api/event_rsvp_admin_action.php', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ scope: scope, id: id }),
  }).then(function (r) { return r.json(); }).then(function (d) {
    if (!d.ok) { alert(d.error || 'Could not remove registrant.'); return; }
    location.reload();
  });
}

document.querySelectorAll('.rsvp-tab[data-scope]').forEach(function (t) {
  t.addEventListener('click', function () {
    document.querySelectorAll('.rsvp-tab[data-scope]').forEach(function (x) { x.classList.remove('active'); });
    t.classList.add('active');
    scopeFilter = t.dataset.scope;
    render();
  });
});
document.querySelectorAll('.rsvp-tab[data-when]').forEach(function (t) {
  t.addEventListener('click', function () {
    document.querySelectorAll('.rsvp-tab[data-when]').forEach(function (x) { x.classList.remove('active'); });
    t.classList.add('active');
    whenFilter = t.dataset.when;
    render();
  });
});
document.querySelector('.rsvp-tab[data-scope="all"]').classList.add('active');
document.querySelector('.rsvp-tab[data-when="upcoming"]').classList.add('active');
render();
</script>
</body>
</html>
