<?php
// Back Office > Events > Create Event: one place to start any kind of event,
// and a list of the events that have been created. Each card opens the
// existing creation form for that event type (nothing new is stored here), so
// this page only decides who may see which card and which events.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/lib/google_calendar.php';

function ce_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES); }

$agent   = require_login();
$isAdmin = is_admin();
$canMc   = can_manage_mc_events();
if (!$isAdmin && !$canMc) { header('Location: index.php'); exit; }

$cards = [];
if ($isAdmin) {
    $cards[] = [
        'title' => 'Training event',
        'desc'  => 'Trainings, classes and workshops. Includes registration, capacity and waitlist, and can create a Zoom meeting with the join link on the same calendar event.',
        'href'  => 'calendar.php?tab=training&add=training',
        'cta'   => 'Create training event',
    ];
    $cards[] = [
        'title' => 'Company event',
        'desc'  => 'Company-wide events that appear on the Events calendar for every agent, with registration and capacity.',
        'href'  => 'calendar.php?tab=events&add=events',
        'cta'   => 'Create company event',
    ];
}
if ($canMc) {
    $cards[] = [
        'title' => 'Market Center event',
        'desc'  => 'An event for one or more Market Centers. Check as many Market Centers as you need on the form, or use Select all to cover everyone.',
        'href'  => 'mc_events.php',
        'cta'   => 'Create Market Center event',
    ];
}
if ($isAdmin) {
    $cards[] = [
        'title' => 'Industry event',
        'desc'  => 'Conferences and outside industry events shown on the Industry Events page.',
        'href'  => 'backoffice_industry_events.php',
        'cta'   => 'Add industry event',
    ];
}

// ── Created events ────────────────────────────────────────────────────────────
// Training + Company events live in Google Calendar; Market Center and
// Industry events are local rows. Birthdays/anniversaries are deliberately not
// included. Each source is isolated so one failing never hides the others.
$rows     = [];
$warnings = [];
$today    = date('Y-m-d');
$from     = $today; // upcoming only; events that have already happened are not listed
$to       = date('Y-m-d', strtotime('+365 days'));

function ce_gcal_rows(string $calId, string $type, string $label, string $scope, array $zoomById, array $rsvpCounts, string $from, string $to): array {
    $c = cfg();
    $tok = gcal_access_token($c['gcal_key_file'] ?? (__DIR__ . '/agentedge-calendar-key.json'));
    if (!$tok) throw new RuntimeException('calendar sign-in failed');
    $items = gcal_events($calId, $tok, date('c', strtotime($from)), date('c', strtotime($to . ' 23:59:59')));
    $out = [];
    foreach ($items as $it) {
        if (($it['status'] ?? '') === 'cancelled') continue;
        $startRaw = $it['start']['dateTime'] ?? $it['start']['date'] ?? '';
        if ($startRaw === '') continue;
        $id   = $it['id'] ?? '';
        $date = substr($startRaw, 0, 10);
        $time = isset($it['start']['dateTime']) ? date('g:i a', strtotime($startRaw)) : 'All day';
        $loc  = (string)($it['location'] ?? '');
        $out[] = [
            'date'    => $date,
            'time'    => $time,
            'title'   => $it['summary'] ?? '(untitled)',
            'type'    => $type,
            'label'   => $label,
            'where'   => str_contains($loc, 'zoom.us') ? 'Zoom' : $loc,
            'zoom'    => isset($zoomById[$id]) || str_contains($loc, 'zoom.us'),
            'rsvps'   => $rsvpCounts[$id] ?? null,
            'by'      => $it['creator']['email'] ?? '',
            'created' => substr((string)($it['created'] ?? ''), 0, 10),
            'link'    => 'calendar.php?' . http_build_query(['event' => $id, 'scope' => $scope, 'date' => $date]),
        ];
    }
    return $out;
}

if ($isAdmin) {
    $cfg = cfg();
    // Training
    try {
        $zoomById = []; $rsvp = [];
        foreach (local_db()->query("SELECT event_id, zoom_meeting_id FROM training_events WHERE zoom_meeting_id IS NOT NULL AND zoom_meeting_id <> ''")->fetchAll(PDO::FETCH_ASSOC) as $r) $zoomById[$r['event_id']] = true;
        foreach (local_db()->query("SELECT event_id, COUNT(*) n FROM training_rsvps WHERE status='registered' GROUP BY event_id")->fetchAll(PDO::FETCH_ASSOC) as $r) $rsvp[$r['event_id']] = (int)$r['n'];
        $rows = array_merge($rows, ce_gcal_rows($cfg['gcal_calendar_id'] ?? 'training@innovateonline.com', 'training', 'Training', 'training', $zoomById, $rsvp, $from, $to));
    } catch (\Throwable $e) { $warnings[] = 'Training events could not be loaded right now.'; }
    // Company (Events calendar)
    try {
        $evCal = $cfg['gcal_events_calendar_id'] ?? '';
        if ($evCal !== '') $rows = array_merge($rows, ce_gcal_rows($evCal, 'company', 'Company', 'events', [], [], $from, $to));
    } catch (\Throwable $e) { $warnings[] = 'Company events could not be loaded right now.'; }
    // Industry
    try {
        $st = local_db()->prepare("SELECT id, name, organizer, start_date, location, created_by, created_at FROM custom_events WHERE start_date >= ? AND start_date <= ? ORDER BY start_date");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = ['date' => $r['start_date'], 'time' => 'All day', 'title' => $r['name'], 'type' => 'industry', 'label' => 'Industry',
                'where' => $r['location'] ?: $r['organizer'], 'zoom' => false, 'rsvps' => null,
                'by' => $r['created_by'], 'created' => substr((string)$r['created_at'], 0, 10), 'link' => 'backoffice_industry_events.php'];
        }
    } catch (\Throwable $e) { $warnings[] = 'Industry events could not be loaded right now.'; }
}
// Market Center (admins: all; MC leaders: only events touching their Market Centers)
try {
    $db = local_db();
    $mcNames = [];
    foreach ($db->query("SELECT slug, name FROM market_centers")->fetchAll(PDO::FETCH_ASSOC) as $m) $mcNames[$m['slug']] = $m['name'];
    $mine = $isAdmin ? null : my_event_mc_slugs();
    // Vendorsgiving: events outside the live (pilot) markets are listed for Super Admins only.
    $vgHidden = [];
    if (is_file(__DIR__ . '/lib/vendorsgiving.php')) { require_once __DIR__ . '/lib/vendorsgiving.php'; $vgHidden = vg_hidden_event_ids($db, is_super_admin()); }
    $st = $db->prepare("SELECT * FROM mc_events WHERE start_date >= ? AND start_date <= ? ORDER BY start_date, start_time");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($vgHidden && in_array((int)$r['id'], $vgHidden, true)) continue;
        $slugs = function_exists('mc_events_slugs_array')
            ? mc_events_slugs_array($r['mc_slugs'] ?? '')
            : array_values(array_filter(array_map('trim', explode(',', (string)($r['mc_slugs'] ?? '')))));
        if ($mine !== null && !array_intersect($slugs, $mine)) continue;
        $names = array_map(fn($s) => $mcNames[$s] ?? $s, $slugs);
        $mcLabel = count($names) > 3 ? count($names) . ' Market Centers' : implode(', ', $names);
        $rows[] = ['date' => $r['start_date'], 'time' => ($r['start_time'] ?? '') !== '' ? date('g:i a', strtotime($r['start_time'])) : 'All day',
            'title' => $r['name'], 'type' => 'mc', 'label' => 'Market Center',
            'where' => $mcLabel, 'zoom' => str_contains((string)($r['location'] ?? '') . (string)($r['url'] ?? ''), 'zoom.us'), 'rsvps' => null,
            'by' => $r['created_by'] ?? '', 'created' => substr((string)($r['created_at'] ?? ''), 0, 10), 'link' => 'mc_events.php'];
    }
} catch (\Throwable $e) { $warnings[] = 'Market Center events could not be loaded right now.'; }

usort($rows, fn($a, $b) => [$a['date'], $a['time']] <=> [$b['date'], $b['time']]);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Create Event — Back Office — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .ce-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:16px; }
    .ce-card { display:flex; flex-direction:column; border:1px solid #e5e5e5; border-radius:10px; padding:20px; background:#fff; }
    .ce-card h3 { margin:0 0 8px; font-size:15px; font-weight:800; }
    .ce-card p { margin:0 0 16px; font-size:13px; line-height:1.5; color:#666; flex:1; }
    .ce-btn { align-self:flex-start; padding:9px 18px; background:#82C112; color:#000; border-radius:6px; font-weight:800; font-size:13px; text-decoration:none; }
    .ce-btn:hover { background:#5b8e0d; color:#fff; }
    .ce-h2 { margin:0; font-size:14px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:#5b8e0d; }
    .ce-filters { display:flex; flex-wrap:wrap; gap:8px; margin:12px 0 14px; }
    .ce-chip { padding:5px 12px; border:1px solid #ddd; border-radius:16px; background:#fff; font-size:12px; font-weight:700; cursor:pointer; }
    .ce-chip.on { background:#111; color:#fff; border-color:#111; }
    .ce-table { width:100%; border-collapse:collapse; font-size:13px; }
    .ce-table th { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#aaa; border-bottom:1px solid #eee; padding:8px 10px; text-align:left; }
    .ce-table td { padding:9px 10px; border-bottom:1px solid #f5f5f5; vertical-align:top; }
    .ce-table tr:hover td { background:#fafff5; }
    .ce-table tr.past td { opacity:.55; }
    .ce-badge { display:inline-block; padding:2px 8px; border-radius:3px; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; white-space:nowrap; }
    .ce-t-training { background:#eef5e8; color:#5b8e0d; }
    .ce-t-company  { background:#f5f3ff; color:#5b21b6; }
    .ce-t-mc       { background:#e8f4fa; color:#1d6f93; }
    .ce-t-industry { background:#f3f4f6; color:#374151; }
    .ce-zoom { margin-left:6px; background:#e8f0fe; color:#1a56db; }
    .ce-muted { color:#888; font-size:12px; }
    .ce-warn { margin:10px 0; padding:8px 12px; background:#fff7ed; border:1px solid #fed7aa; border-radius:6px; font-size:12px; color:#9a3412; }
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('bo_create_event', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Create Event</div>
      <a href="calendar.php" style="font-size:12px;color:#82C112;font-weight:700;text-decoration:none">View calendar ↗</a>
    </header>
    <main class="wrap">
      <div class="card" style="padding:20px 24px">
        <p style="margin:0 0 20px;font-size:13px;color:#666">Pick the kind of event to create. You can edit or delete any of them later from the calendar.</p>
        <div class="ce-grid">
          <?php foreach ($cards as $c): ?>
          <div class="ce-card">
            <h3><?= ce_h($c['title']) ?></h3>
            <p><?= ce_h($c['desc']) ?></p>
            <a class="ce-btn" href="<?= ce_h($c['href']) ?>"><?= ce_h($c['cta']) ?></a>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card" style="padding:20px 24px;margin-top:18px">
        <h2 class="ce-h2">Created events</h2>
        <div class="ce-muted" style="margin-top:4px">Upcoming events for the next 12 months<?= $isAdmin ? '' : ', for your Market Centers' ?>. Past events, birthdays and anniversaries are not included.</div>
        <?php foreach ($warnings as $w): ?><div class="ce-warn"><?= ce_h($w) ?></div><?php endforeach; ?>
        <?php if ($rows): ?>
        <div class="ce-filters" id="ce-filters">
          <button type="button" class="ce-chip on" data-t="all">All <span class="ce-muted"><?= count($rows) ?></span></button>
          <?php foreach (['training' => 'Training', 'company' => 'Company', 'mc' => 'Market Center', 'industry' => 'Industry'] as $k => $lab):
                $n = count(array_filter($rows, fn($r) => $r['type'] === $k)); if (!$n) continue; ?>
          <button type="button" class="ce-chip" data-t="<?= $k ?>"><?= $lab ?> <span class="ce-muted"><?= $n ?></span></button>
          <?php endforeach; ?>
        </div>
        <div style="overflow-x:auto">
        <table class="ce-table">
          <thead><tr><th>Date</th><th>Event</th><th>Type</th><th>Where / who</th><th>RSVPs</th><th>Created</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr class="ce-row<?= $r['date'] < $today ? ' past' : '' ?>" data-t="<?= ce_h($r['type']) ?>">
              <td style="white-space:nowrap"><strong><?= ce_h(date('M j, Y', strtotime($r['date']))) ?></strong><div class="ce-muted"><?= ce_h($r['time']) ?></div></td>
              <td><?= ce_h($r['title']) ?></td>
              <td><span class="ce-badge ce-t-<?= ce_h($r['type']) ?>"><?= ce_h($r['label']) ?></span><?php if ($r['zoom']): ?><span class="ce-badge ce-zoom">Zoom</span><?php endif; ?></td>
              <td><?= ce_h($r['where']) ?></td>
              <td><?= $r['rsvps'] === null ? '<span class="ce-muted">-</span>' : (int)$r['rsvps'] ?></td>
              <td class="ce-muted"><?= ce_h($r['created'] ?: '') ?><?= $r['by'] ? '<div>' . ce_h($r['by']) . '</div>' : '' ?></td>
              <td><a href="<?= ce_h($r['link']) ?>" style="font-size:12px;color:#5b8e0d;font-weight:700;text-decoration:none">Open</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <script>
        document.getElementById('ce-filters').addEventListener('click', function (e) {
          const b = e.target.closest('.ce-chip'); if (!b) return;
          document.querySelectorAll('#ce-filters .ce-chip').forEach(x => x.classList.toggle('on', x === b));
          document.querySelectorAll('.ce-row').forEach(r => { r.style.display = (b.dataset.t === 'all' || r.dataset.t === b.dataset.t) ? '' : 'none'; });
        });
        </script>
        <?php else: ?>
        <div style="padding:24px;text-align:center;color:#aaa;font-size:13px">No events in this window yet.</div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</div>
</body>
</html>
