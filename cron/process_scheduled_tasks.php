<?php
// Drains the general-purpose scheduling engine (scheduled_tasks) — "do Y at
// future time X." Meant to run daily via crontab:
//   0 9 * * * /usr/bin/php /path/to/agentedge/cron/process_scheduled_tasks.php
// (or `docker exec agentedge php /var/www/html/cron/process_scheduled_tasks.php`
// if invoked from the host crontab against the running container).
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/notifications.php';

$db  = local_db();
$due = $db->query(
    "SELECT * FROM scheduled_tasks WHERE status='pending' AND fire_at <= datetime('now') ORDER BY fire_at"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($due as $row) {
    try {
        $payload = json_decode($row['payload_json'], true) ?: [];

        switch ($row['task_type']) {
            case 'onboard_followup_text':
                run_onboard_followup_text($db, $payload);
                break;
            case 'dotloop_remind':
                run_dotloop_remind($db, $payload);
                break;
            default:
                throw new \Exception("Unknown task_type: {$row['task_type']}");
        }

        $db->prepare("UPDATE scheduled_tasks SET status='sent', executed_at=datetime('now') WHERE id=?")
           ->execute([$row['id']]);
        echo "[" . date('Y-m-d H:i:s') . "] Ran scheduled task #{$row['id']} ({$row['task_type']})\n";
    } catch (\Throwable $e) {
        $db->prepare("UPDATE scheduled_tasks SET status='failed', executed_at=datetime('now') WHERE id=?")
           ->execute([$row['id']]);
        echo "[" . date('Y-m-d H:i:s') . "] FAILED scheduled task #{$row['id']}: " . $e->getMessage() . "\n";
    }
}

// 10-day post-onboarding-completion check-in text — Darren already does this
// manually today and says agents respond well to it.
function run_onboard_followup_text(PDO $db, array $payload): void {
    $email = trim($payload['agent_email'] ?? '');
    $name  = trim($payload['agent_name']  ?? '');
    if ($email === '') return;

    $st = $db->prepare("SELECT phone FROM agent_intake WHERE email = ?");
    $st->execute([$email]);
    $phone = trim($st->fetchColumn() ?: '');
    if ($phone === '') return;

    $firstName = trim(explode(' ', $name)[0] ?? '') ?: 'there';
    $message   = "Hi {$firstName}, it's been a couple weeks since you joined — just checking in! Let us know if you need anything.";

    $c = cfg();
    send_sms_twilio($phone, $message, $c);
}

// Reminder to check DotLoop for an agent whose step was skipped pending
// pending transaction clearance. Emails Whitney (whitneycottle@gmail.com).
function run_dotloop_remind(PDO $db, array $payload): void {
    $agentName  = trim($payload['agent_name']  ?? '');
    $agentEmail = trim($payload['agent_email'] ?? '');
    if ($agentEmail === '') return;

    $subject = "DotLoop Reminder: Check {$agentName}'s account";
    $body    = implode("\n", [
        "Hi Whitney,",
        "",
        "This is your scheduled reminder to check DotLoop for {$agentName} ({$agentEmail}).",
        "",
        "They were offboarded with pending transactions, so their DotLoop account was left active.",
        "Please log in and verify whether all transactions have closed, then remove the agent if appropriate.",
        "",
        "— AgentEdge",
    ]);

    $db->prepare(
        "INSERT INTO notification_queue (recipient, channel, subject, body, phone, from_email, from_name) VALUES (?,?,?,?,?,?,?)"
    )->execute(['whitneycottle@gmail.com', 'email', $subject, $body, '', '', '']);
}

process_notification_queue();
echo "[" . date('Y-m-d H:i:s') . "] Queue processed.\n";
