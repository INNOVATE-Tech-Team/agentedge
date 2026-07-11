<?php
// Processes due Company Email scheduled sends, then drains notification_queue.
// Meant to run every few minutes via crontab:
//   */5 * * * * /usr/bin/php /path/to/agentedge/cron/process_email_queue.php
// (or `docker exec agentedge php /var/www/html/cron/process_email_queue.php`
// if invoked from the host crontab against the running container).
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../lib/notifications.php';
require_once __DIR__ . '/../lib/company_email.php';

// No $_SERVER['HTTP_HOST'] in a CLI context — this is the one production domain.
const CRON_HOST = 'agentedge.innovateonline.com';

$db  = local_db();
$due = $db->query(
    "SELECT * FROM scheduled_emails WHERE status='pending' AND send_at <= datetime('now') ORDER BY send_at"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($due as $row) {
    try {
        // Re-resolve recipients now, not at compose time — the roster may have changed.
        $recipients = ce_resolve_recipients($row['audience'], $row['target_mc_slug'], $row['target_email']);

        // agent_roles has no display name column — fall back to the email itself,
        // matching what the immediate-send path does when $agent['name'] is empty.
        $sigHtml = ce_signature_html($row['sender_email'], $row['sender_email'], CRON_HOST);
        $ins = $db->prepare("INSERT INTO notification_queue (recipient, channel, subject, body, phone, is_html) VALUES (?, 'email', ?, ?, '', 1)");
        foreach ($recipients as $r) {
            $personalized = ce_apply_merge_vars($row['body'], $r['name']);
            $ins->execute([$r['email'], $row['subject'], $personalized . $sigHtml]);
        }

        $db->prepare(
            "INSERT INTO company_emails (sender_email, sender_role, audience, target_mc_slug, subject, body, recipient_count)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([$row['sender_email'], $row['sender_role'], $row['audience'], $row['target_mc_slug'], $row['subject'], $row['body'], count($recipients)]);

        $db->prepare("UPDATE scheduled_emails SET status='sent', recipient_count=? WHERE id=?")
           ->execute([count($recipients), $row['id']]);

        echo "[" . date('Y-m-d H:i:s') . "] Sent scheduled email #{$row['id']} to " . count($recipients) . " recipients\n";
    } catch (\Throwable $e) {
        $db->prepare("UPDATE scheduled_emails SET status='failed' WHERE id=?")->execute([$row['id']]);
        echo "[" . date('Y-m-d H:i:s') . "] FAILED scheduled email #{$row['id']}: " . $e->getMessage() . "\n";
    }
}

// Drain the queue — covers what was just queued above, plus any stray pending
// rows from an immediate send whose synchronous dispatch didn't complete.
process_notification_queue();
echo "[" . date('Y-m-d H:i:s') . "] Queue processed.\n";
