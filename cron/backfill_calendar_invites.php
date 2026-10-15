<?php
// One-time backfill: RSVPs made before Google Calendar delegation was enabled
// never got a native invite (the sync was failing with 403
// forbiddenForServiceAccounts). Adds every still-upcoming 'registered' RSVP as
// an attendee on its Calendar event.
//
//   php cron/backfill_calendar_invites.php           # dry run — lists what would be invited
//   php cron/backfill_calendar_invites.php --apply   # sends the invites
//
// Requires 'gcal_impersonate' in config.php. Safe to re-run: agents already on
// the event are skipped.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/google_calendar.php';

$apply = in_array('--apply', $argv, true);
$c     = cfg();
$token = gcal_access_token($c['gcal_key_file'] ?? (__DIR__ . '/../agentedge-calendar-key.json'));
if (!$token) { fwrite(STDERR, "Could not get a Google token (see [gcal] log line).\n"); exit(1); }

$targets = [
    ['training_rsvps', $c['gcal_calendar_id']        ?? 'training@innovateonline.com'],
    ['events_rsvps',   $c['gcal_events_calendar_id'] ?? ''],
];
$db    = local_db();
$today = date('Y-m-d');
$ok = $fail = 0;

foreach ($targets as [$table, $cal_id]) {
    if (!$cal_id) continue;
    $rows = $db->prepare("SELECT event_id, event_title, event_date, agent_email FROM $table WHERE status='registered' AND substr(event_date,1,10) >= ? ORDER BY event_date");
    $rows->execute([$today]);
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo ($apply ? '' : '[dry run] ') . "$table  {$r['event_date']}  {$r['event_title']}  <-  {$r['agent_email']}\n";
        if (!$apply) continue;
        if (gcal_add_attendee($cal_id, $token, $r['event_id'], $r['agent_email'])) $ok++;
        else { $fail++; echo "   FAILED\n"; }
        usleep(300000);
    }
}
echo $apply ? "Done: $ok invited/already on event, $fail failed.\n" : "Dry run only. Re-run with --apply to send invites.\n";
