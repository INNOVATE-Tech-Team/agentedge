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
//   php cron/send_birthday_emails.php --test-email=someone@example.com [--name="First"] [--market-center="Name"]
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/event_notifications.php';
require_once __DIR__ . '/../lib/company_email.php';

// The hero image is read from the web root by recipients' mail clients, so it
// must be reachable without logging in.
const BIRTHDAY_CARD_PATH = '/assets/birthday-card.png';
const BIRTHDAY_LOGO_PATH = '/assets/logo.png';

function birthday_base_url(): string {
    return rtrim((string)(cfg()['app_base_url'] ?? 'https://agentedge.innovateonline.com'), '/');
}

const BIRTHDAY_FROM_EMAIL = 'darren@innovateonline.com';
const BIRTHDAY_FROM_NAME  = 'Darren Woodard';

// Names of the agent's Market Center Leader and BIC (market_centers row for
// their roster market center), for the sign-off. Blank names are skipped.
function birthday_mc_signers(string $marketCenter): array {
    if ($marketCenter === '') return [];
    $st = local_db()->prepare("SELECT mc_leader_email, bic_email FROM market_centers WHERE LOWER(name) = LOWER(?)");
    $st->execute([$marketCenter]);
    $mc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$mc) return [];
    $nameByEmail = [];
    foreach (ce_fetch_crm_roster() as $a) {
        $e = strtolower(trim($a['email'] ?? ''));
        if ($e) $nameByEmail[$e] = trim($a['fullName'] ?? '');
    }
    $out = [];
    foreach ([['mc_leader_email', 'Market Center Leader'], ['bic_email', 'Broker in Charge']] as [$col, $title]) {
        $n = $nameByEmail[strtolower(trim($mc[$col] ?? ''))] ?? '';
        if ($n !== '') $out[] = [$n, $title];
    }
    return $out;
}

// Standalone shell (no green header bar, no "Sent via AgentEdge" footer) —
// the card and logo carry the branding.
function birthday_email_html(string $firstName, string $marketCenter = ''): string {
    $base = birthday_base_url();
    $name = htmlspecialchars($firstName !== '' ? $firstName : 'there', ENT_QUOTES);
    $signers = array_merge([['Darren Woodard', ''], ['Jeff Casterline', '']], birthday_mc_signers($marketCenter));
    $sign = '';
    foreach ($signers as [$n, $title]) {
        $sign .= '<div style="margin-top:6px"><strong>' . htmlspecialchars($n, ENT_QUOTES) . '</strong>'
               . ($title !== '' ? ' <span style="color:#888">- ' . $title . '</span>' : '') . '</div>';
    }
    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"></head>'
         . '<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif">'
         . '<div style="max-width:600px;margin:0 auto;padding:24px 16px">'
         . '<div style="background:#ffffff;padding:28px;border-radius:8px;border:1px solid #e0e0e0">'
         . '<div style="text-align:center;margin:0 0 20px"><img src="' . $base . BIRTHDAY_LOGO_PATH . '" alt="INNOVATE Real Estate" width="220" style="max-width:220px;height:auto;border:0"></div>'
         . '<div style="text-align:center"><img src="' . $base . BIRTHDAY_CARD_PATH . '" alt="Happy Birthday!" width="520" style="max-width:100%;height:auto;border:0;border-radius:6px"></div>'
         . '<p style="color:#444;font-size:16px;line-height:1.7;margin:22px 0 0;text-align:center">Happy Birthday, <strong>' . $name . '</strong>! '
         . 'Everyone at INNOVATE Real Estate is grateful to have you on the team. Wishing you a wonderful day and a great year ahead.</p>'
         . '<div style="color:#444;font-size:14px;line-height:1.5;margin-top:22px;text-align:center">With appreciation,' . $sign . '</div>'
         . '</div></div></body></html>';
}

function queue_birthday_email(string $to, string $subject, string $html): void {
    local_db()->prepare(
        "INSERT INTO notification_queue (recipient, channel, subject, body, phone, is_html, from_email, from_name) VALUES (?,?,?,?,?,1,?,?)"
    )->execute([$to, 'email', $subject, $html, '', BIRTHDAY_FROM_EMAIL, BIRTHDAY_FROM_NAME]);
}

$opts = getopt('', ['test-email:', 'name:', 'market-center:']);

// ── Test mode ──────────────────────────────────────────────────────────────
if (!empty($opts['test-email'])) {
    $to = trim($opts['test-email']);
    queue_birthday_email($to, '[TEST] Happy Birthday from INNOVATE Real Estate!', birthday_email_html(trim($opts['name'] ?? 'Friend'), trim($opts['market-center'] ?? '')));
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
$sql = "SELECT lower(r.email) AS email, MIN(r.agent_name) AS agent_name, MIN(r.market_center) AS market_center
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
    queue_birthday_email($email, 'Happy Birthday from INNOVATE Real Estate!', birthday_email_html($first, (string)$a['market_center']));
    $mark->execute([$email, $year]);
    $sent++;
}

process_notification_queue();
echo "[" . date('Y-m-d H:i:s') . "] Birthday emails queued: {$sent} (of " . count($agents) . " with a birthday today).\n";
