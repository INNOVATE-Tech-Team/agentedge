<?php
// Intake-form follow-up: for every ACTIVE onboarding-queue agent whose intake
// form still isn't submitted and whose last intake email went out 48+ hours
// ago, send the tokenized intake link again. Repeats every 48h after each
// send, up to INTAKE_REMINDER_MAX automatic reminders per agent, then stops
// (staff can still use "Resend Intake" in the queue by hand). Meant to run
// once daily:
//   0 14 * * * docker exec agentedge php /var/www/html/cron/intake_reminders.php
// Pass --dry-run to list who WOULD be emailed without sending or recording.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/notifications.php';

const INTAKE_REMINDER_AFTER_HOURS = 48;
const INTAKE_REMINDER_MAX         = 3;

$dry = in_array('--dry-run', $argv ?? [], true);
$db  = local_db();
$db->exec("CREATE TABLE IF NOT EXISTS onboard_intake_reminders (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    queue_id INTEGER NOT NULL,
    sent_at  TEXT NOT NULL DEFAULT (datetime('now'))
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_intake_rem_queue ON onboard_intake_reminders(queue_id)");

$rows = $db->query(
    "SELECT q.id, q.agent_name, q.agent_email, q.intake_sent_at,
            (SELECT COUNT(*) FROM onboard_intake_reminders r WHERE r.queue_id = q.id) AS reminders_sent
       FROM onboard_queue q
      WHERE q.status = 'active'
        AND q.intake_sent_at IS NOT NULL
        AND q.intake_sent_at <= datetime('now', '-" . INTAKE_REMINDER_AFTER_HOURS . " hours')
        AND COALESCE((SELECT submitted FROM agent_intake WHERE LOWER(email) = LOWER(q.agent_email)), 0) = 0
      ORDER BY q.id"
)->fetchAll(PDO::FETCH_ASSOC);

$sent = 0;
foreach ($rows as $r) {
    $tag = "#{$r['id']} {$r['agent_name']} <{$r['agent_email']}>";
    if ((int)$r['reminders_sent'] >= INTAKE_REMINDER_MAX) { echo "  skip (max reminders reached) $tag\n"; continue; }
    if (!filter_var($r['agent_email'], FILTER_VALIDATE_EMAIL)) { echo "  skip (no valid email) $tag\n"; continue; }
    if ($dry) { echo "  WOULD SEND reminder " . ((int)$r['reminders_sent'] + 1) . "/" . INTAKE_REMINDER_MAX . " to $tag (last intake email {$r['intake_sent_at']})\n"; continue; }

    notify_intake_reminder($r['agent_name'], $r['agent_email'], (int)$r['id']);
    $db->prepare("INSERT INTO onboard_intake_reminders (queue_id) VALUES (?)")->execute([$r['id']]);
    // Restart the 48h clock; also keeps the queue card's "Intake sent <date>" accurate.
    $db->prepare("UPDATE onboard_queue SET intake_sent_at = datetime('now') WHERE id = ?")->execute([$r['id']]);
    echo "  sent reminder " . ((int)$r['reminders_sent'] + 1) . "/" . INTAKE_REMINDER_MAX . " to $tag\n";
    $sent++;
}

echo "[" . date('Y-m-d H:i:s') . "] intake_reminders" . ($dry ? ' (dry run)' : '') . ": " . count($rows) . " overdue, {$sent} sent\n";
if ($sent > 0) { try { dispatch_notification_queue(); } catch (\Throwable $e) {} }
