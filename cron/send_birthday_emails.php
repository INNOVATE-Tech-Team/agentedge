<?php
// Sends a branded Happy Birthday email (logo + birthday graphic) to every
// active agent whose birthday is today. Meant to run once a day via crontab:
//   0 8 * * * /usr/bin/php /path/to/agentedge/cron/send_birthday_emails.php
// (or `docker exec agentedge php /var/www/html/cron/send_birthday_emails.php`
// if invoked from the host crontab against the running container).
//
// Birthday source: agent_extra.birthday (MM-DD override), falling back to the
// full DOB on agent_intake.birthday — same precedence as api/agent_extra.php.
// Feb 29 birthdays are sent on Feb 28 in non-leap years. Per-year dedupe is
// tracked in birthday_emails_sent, so re-running the cron the same day is safe.
// Agents who unsubscribed (notification_prefs.notify_email=0) are skipped.
//
// Test mode (sends immediately, never touches the sent log):
//   php cron/send_birthday_emails.php --test-email=someone@example.com [--name="First Last"]
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/event_notifications.php';

// The hero image is read from the web root by recipients' mail clients, so it
// must be reachable without logging in.
const BIRTHDAY_CARD_PATH = '/assets/birthday-card.png';
const BIRTHDAY_LOGO_PATH = '/assets/logo.png';

function birthday_base_url(): string {
    return rtrim((string)(cfg()['app_base_url'] ?? 'https://agentedge.innovateonline.com'), '/');
}

function birthday_email_html(string $firstName): string {
    $base = birthday_base_url();
    $name = htmlspecialchars($firstName !== '' ? $firstName : 'there', ENT_QUOTES);
    return '<div style="text-align:center;margin:0 0 20px"><img src="' . $base . BIRTHDAY_LOGO_PATH . '" alt="INNOVATE Real Estate" width="220" style="max-width:220px;height:auto;border:0"></div>'
         . '<div style="text-align:center"><img src="' . $base . BIRTHDAY_CARD_PATH . '" alt="Happy Birthday!" width="520" style="max-width:100%;height:auto;border:0;border-radius:6px"></div>'
         . '<p style="color:#444;font-size:16px;line-height:1.7;margin:22px 0 0;text-align:center">Happy Birthday, <strong>' . $name . '</strong>! '
         . 'Everyone at INNOVATE Real Estate is grateful to have you on the team. Wishing you a wonderful day and a great year ahead.</p>';
}

$opts = getopt('', ['test-email:', 'name:']);

// ── Test mode ──────────────────────────────────────────────────────────────
if (!empty($opts['test-email'])) {
    $to = trim($opts['test-email']);
    queue_branded_email([$to], '[TEST] Happy Birthday from INNOVATE Real Estate!', birthday_email_html(trim($opts['name'] ?? 'Friend')));
    process_notification_queue();
    echo "[" . date('Y-m-d H:i:s') . "] Sent test birthday email to {$to}.\n";
    exit(0);
}

// ── Real run ───────────────────────────────────────────────────────────────
$db = local_db();
$db->exec("CREATE TABLE IF NOT EXISTS birthday_emails_sent (
    email   TEXT NOT NULL,
    year    INTEGER NOT NULL,
    sent_at TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (email, year)
)");

$year  = (int)date('Y');
$today = date('m-d');
$dates = [$today];
// Leap-day birthdays: celebrate on Feb 28 when Feb 29 doesn't exist this year.
if ($today === '02-28' && date('L') !== '1') $dates[] = '02-29';
$in = implode(',', array_fill(0, count($dates), '?'));

// Active roster agents only (an email can have several roster rows — GROUP BY
// collapses them). The birthday is the agent_extra override when set, else
// MM-DD derived from the intake form's YYYY-MM-DD.
$sql = "SELECT lower(r.email) AS email, MIN(r.agent_name) AS agent_name
        FROM innovate_roster r
        LEFT JOIN agent_extra  x ON lower(x.email) = lower(r.email)
        LEFT JOIN agent_intake i ON lower(i.email) = lower(r.email)
        WHERE r.active = 1 AND r.email != ''
          AND COALESCE(NULLIF(x.birthday, ''), substr(i.birthday, 6, 5)) IN ($in)
        GROUP BY lower(r.email)";
$stmt = $db->prepare($sql);
$stmt->execute($dates);
$agents = $stmt->fetchAll(PDO::FETCH_ASSOC);

$optOut = array_map('strtolower', $db->query("SELECT email FROM notification_prefs WHERE notify_email=0")->fetchAll(PDO::FETCH_COLUMN));
$already = $db->prepare("SELECT 1 FROM birthday_emails_sent WHERE email=? AND year=?");
$mark    = $db->prepare("INSERT OR IGNORE INTO birthday_emails_sent (email, year) VALUES (?, ?)");

$sent = 0;
foreach ($agents as $a) {
    $email = $a['email'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || in_array($email, $optOut, true)) continue;
    $already->execute([$email, $year]);
    if ($already->fetchColumn()) continue;

    $first = trim(explode(' ', trim((string)$a['agent_name']))[0] ?? '');
    queue_branded_email([$email], 'Happy Birthday from INNOVATE Real Estate!', birthday_email_html($first));
    $mark->execute([$email, $year]);
    $sent++;
}

process_notification_queue();
echo "[" . date('Y-m-d H:i:s') . "] Birthday emails queued: {$sent} (of " . count($agents) . " with a birthday today).\n";
