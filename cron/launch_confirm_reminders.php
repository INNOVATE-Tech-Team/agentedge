<?php
// LAUNCH invoicing reminder — two days before each LAUNCH class start date,
// emails Michele Chalk the full list of agents marked "Confirmed" for that
// date, so invoices can go out ahead of the class. Meant to run once daily:
//   0 7 * * * docker exec agentedge php /var/www/html/cron/launch_confirm_reminders.php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/launch_roster.php';

const LAUNCH_CONFIRM_REMINDER_RECIPIENT = 'michele@innovateonline.com';

$db = local_db();
$targetDate = (new DateTime('now'))->modify('+2 days')->format('Y-m-d');

$already = $db->prepare("SELECT 1 FROM launch_confirm_reminders_sent WHERE start_date=?");
$already->execute([$targetDate]);
if ($already->fetchColumn()) {
    echo "[" . date('Y-m-d H:i:s') . "] launch_confirm_reminders: {$targetDate} already sent, skipping\n";
    exit;
}

$st = $db->prepare("SELECT * FROM launch_roster WHERE status='confirmed' AND start_date=? ORDER BY agent_name");
$st->execute([$targetDate]);
$confirmed = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$confirmed) {
    echo "[" . date('Y-m-d H:i:s') . "] launch_confirm_reminders: no confirmed agents for {$targetDate}, nothing to send\n";
    exit;
}

$directory = launch_roster_build_directory($db);
$classDateLabel = (new DateTime($targetDate))->format('F j, Y');

$lines = [];
foreach ($confirmed as $r) {
    $resolved = launch_roster_resolve_agent($directory, $r['agent_name'], $r['state']);
    $mc    = $resolved['matched'] ? $resolved['market_center'] : $r['office'];
    $phone = $resolved['phone'];
    $lines[] = $r['agent_name'] . ' — ' . ($mc ?: 'no market center on file') . ($phone ? " — {$phone}" : '');
}

$count   = count($confirmed);
$subject = "LAUNCH Class {$classDateLabel} — {$count} Confirmed Agent" . ($count === 1 ? '' : 's') . " for Invoicing";
$body    = "The following agents are confirmed for the LAUNCH class starting {$classDateLabel} (in 2 days) and are ready to be invoiced:\n\n"
         . implode("\n", $lines)
         . "\n\nThis is an automated reminder from AgentEdge's Launch Coaching tracker.";

$db->prepare("INSERT INTO notification_queue (recipient, channel, subject, body) VALUES (?, 'email', ?, ?)")
   ->execute([LAUNCH_CONFIRM_REMINDER_RECIPIENT, $subject, $body]);

$db->prepare("INSERT INTO launch_confirm_reminders_sent (start_date, recipient_count) VALUES (?, ?)")
   ->execute([$targetDate, $count]);

echo "[" . date('Y-m-d H:i:s') . "] launch_confirm_reminders: queued email for {$targetDate}, {$count} confirmed agent(s)\n";
