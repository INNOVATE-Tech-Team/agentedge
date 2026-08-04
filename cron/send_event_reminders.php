<?php
// Sends a 24-hour and a 1-hour reminder email to every registered attendee
// of a Training session or company Event. Meant to run every ~15 minutes via
// crontab:
//   */15 * * * * /usr/bin/php /path/to/agentedge/cron/send_event_reminders.php
// (or `docker exec agentedge php /var/www/html/cron/send_event_reminders.php`
// if invoked from the host crontab against the running container).
//
// Reminder state is tracked per REGISTRANT ROW (reminder_24h_sent/
// reminder_1h_sent on training_rsvps/events_rsvps), not per event — so
// someone who registers inside the 24-hour window still gets the 1-hour
// reminder without the already-fired event-wide send skipping them.
//
// Test mode (sends immediately, ignores timing and never touches the sent
// flags — safe to run against a real event without affecting real
// registrants' reminder state):
//   php cron/send_event_reminders.php --test-email=someone@example.com --event=<gcal_id> --scope=training
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/google_calendar.php';
require_once __DIR__ . '/../lib/event_notifications.php';

function reminder_intro_html(string $which): string {
    $when = $which === '24h' ? 'starts in about 24 hours' : 'starts in about 1 hour';
    return '<p style="color:#444;font-size:15px;line-height:1.7;margin:0 0 16px">Just a reminder — your event <strong>' . $when . '</strong>:</p>';
}

function scope_config(string $scope): array {
    $c = cfg();
    return $scope === 'events'
        ? ['table' => 'events_rsvps', 'cal_id' => $c['gcal_events_calendar_id'] ?? '']
        : ['table' => 'training_rsvps', 'cal_id' => $c['gcal_calendar_id'] ?? 'training@innovateonline.com'];
}

$opts = getopt('', ['test-email:', 'event:', 'scope:']);

// ── Test mode: send both reminder variants immediately, no DB writes ──────────
if (!empty($opts['test-email'])) {
    $testEmail = trim($opts['test-email']);
    $eventId   = trim($opts['event'] ?? '');
    $scope     = ($opts['scope'] ?? '') === 'events' ? 'events' : 'training';
    if ($eventId === '') { echo "Usage: --test-email=you@example.com --event=<gcal_id> [--scope=training|events]\n"; exit(1); }

    $cfgScope = scope_config($scope);
    $key_file = cfg()['gcal_key_file'] ?? (__DIR__ . '/../agentedge-calendar-key.json');
    $token    = gcal_access_token($key_file);
    if (!$token) { echo "Calendar auth failed.\n"; exit(1); }

    $event = gcal_get_event($cfgScope['cal_id'], $token, $eventId);
    if (!$event) { echo "Could not fetch event {$eventId} ({$scope}).\n"; exit(1); }

    $info  = event_display_info($event, $scope, $eventId);
    $title = $event['summary'] ?? 'Event';

    queue_branded_email([$testEmail], "[TEST] Reminder: {$title} (24h)", reminder_intro_html('24h') . event_details_block_html($title, $info));
    queue_branded_email([$testEmail], "[TEST] Reminder: {$title} (1h)",  reminder_intro_html('1h')  . event_details_block_html($title, $info));
    process_notification_queue();
    echo "[" . date('Y-m-d H:i:s') . "] Sent test 24h + 1h reminders for \"{$title}\" to {$testEmail}.\n";
    exit(0);
}

// ── Real run ───────────────────────────────────────────────────────────────
$db  = local_db();
$now = time();

foreach (['training', 'events'] as $scope) {
    $cfgScope = scope_config($scope);
    if ($cfgScope['cal_id'] === '') continue;

    $table    = $cfgScope['table'];
    $key_file = cfg()['gcal_key_file'] ?? (__DIR__ . '/../agentedge-calendar-key.json');
    $token    = gcal_access_token($key_file);
    if (!$token) { echo "[" . date('Y-m-d H:i:s') . "] {$scope}: calendar auth failed, skipping.\n"; continue; }

    $rows = $db->query(
        "SELECT id, event_id, event_title, agent_email, reminder_24h_sent, reminder_1h_sent
         FROM {$table} WHERE status='registered' AND (reminder_24h_sent=0 OR reminder_1h_sent=0)"
    )->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) continue;

    $eventCache = [];
    foreach ($rows as $row) {
        $eventId = $row['event_id'];
        if (!array_key_exists($eventId, $eventCache)) {
            $eventCache[$eventId] = gcal_get_event($cfgScope['cal_id'], $token, $eventId);
        }
        $event = $eventCache[$eventId];
        if (!$event) continue;

        $info = event_display_info($event, $scope, $eventId);
        if ($info['start_ts'] === null) continue;
        $hoursUntil = ($info['start_ts'] - $now) / 3600;

        // Event already started (or long past) — stop tracking it so this
        // query doesn't keep re-fetching it from Google every run forever.
        if ($hoursUntil < -0.5) {
            $db->prepare("UPDATE {$table} SET reminder_24h_sent=1, reminder_1h_sent=1 WHERE id=?")->execute([$row['id']]);
            continue;
        }

        if (!$row['reminder_24h_sent'] && $hoursUntil <= 24 && $hoursUntil > 1.25) {
            queue_branded_email([$row['agent_email']], "Reminder: {$row['event_title']} (tomorrow)",
                reminder_intro_html('24h') . event_details_block_html($row['event_title'], $info));
            $db->prepare("UPDATE {$table} SET reminder_24h_sent=1 WHERE id=?")->execute([$row['id']]);
            echo "[" . date('Y-m-d H:i:s') . "] 24h reminder -> {$row['agent_email']} for \"{$row['event_title']}\"\n";
        }

        if (!$row['reminder_1h_sent'] && $hoursUntil <= 1 && $hoursUntil > -0.5) {
            queue_branded_email([$row['agent_email']], "Reminder: {$row['event_title']} (starting soon)",
                reminder_intro_html('1h') . event_details_block_html($row['event_title'], $info));
            $db->prepare("UPDATE {$table} SET reminder_1h_sent=1 WHERE id=?")->execute([$row['id']]);
            echo "[" . date('Y-m-d H:i:s') . "] 1h reminder -> {$row['agent_email']} for \"{$row['event_title']}\"\n";
        }
    }
}

process_notification_queue();
echo "[" . date('Y-m-d H:i:s') . "] Reminder pass complete.\n";
