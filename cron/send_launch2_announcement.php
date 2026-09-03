<?php
// One-time send: the LAUNCH 2.0 Beta Accelerator company-wide announcement.
// Scheduled via `at` for 2026-08-06 09:00 America/New_York (not a recurring
// cron — this is a single campaign, not a periodic job). Sends from
// noreply@innovateonline.com with no signature footer, per Darren's review
// of the test sends on 2026-08-05.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/notifications.php';
require_once __DIR__ . '/../lib/company_email.php';

const LAUNCH2_FROM_EMAIL = 'noreply@innovateonline.com';
const LAUNCH2_FROM_NAME  = 'INNOVATE Real Estate';
const LAUNCH2_SUBJECT    = "Introducing LAUNCH 2.0, Beta Accelerator Starts Monday, Aug 10 (\$200)";
const LAUNCH2_HOST       = 'agents.innovateonline.com';

$db = local_db();

// Idempotency guard — this script is meant to fire exactly once (scheduled
// via `at`, not a repeating cron), but if it's ever re-run by hand, never
// send this exact campaign twice.
$already = $db->prepare("SELECT 1 FROM company_emails WHERE subject=? AND audience='all'");
$already->execute([LAUNCH2_SUBJECT]);
if ($already->fetchColumn()) {
    echo "[" . date('Y-m-d H:i:s') . "] LAUNCH 2.0 announcement already sent, skipping\n";
    exit;
}

$html = <<<'HTML'
<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#222;max-width:600px">

<p style="font-size:20px;font-weight:800;color:#111;margin:0 0 4px">Introducing LAUNCH 2.0</p>
<p style="font-size:15px;color:#5b8e0d;font-weight:700;margin:0 0 20px;text-transform:uppercase;letter-spacing:.03em">Beta Accelerator Class, Starts Monday, August 10</p>

<p>Hi {{first_name}},</p>

<p>We're rolling out the next evolution of LAUNCH, INNOVATE's agent development program, and you're getting the first chance to be part of it.</p>

<p><strong>LAUNCH exists for one reason:</strong> passing your licensing exam qualifies you to practice real estate, but it doesn't teach you how to run your business. LAUNCH is where that happens, it's the difference between having a license and building an actual business. Every session is built around one goal: turning you into the CEO of your own real estate business, not just someone with a lockbox app and a business card.</p>

<p>That includes the piece most agents never get taught: <strong>time management.</strong> LAUNCH gives you the systems and daily structure to actually run your calendar instead of letting your calendar run you, the single biggest difference between agents who build a real business and agents who stay busy without ever getting anywhere.</p>

<h3 style="font-size:16px;color:#111;margin:28px 0 10px;border-top:1px solid #eee;padding-top:20px">LAUNCH 2.0, What's Different</h3>
<ul style="padding-left:20px;margin:0 0 20px">
  <li style="margin-bottom:8px"><strong>Format:</strong> 4 weeks, 2 sessions a week, the full LAUNCH curriculum, delivered faster.</li>
  <li style="margin-bottom:8px"><strong>Where:</strong> Live in-person in Pennsylvania, with a live Zoom option for every other market.</li>
  <li style="margin-bottom:8px"><strong>Hosted by:</strong> Mike Blum.</li>
  <li style="margin-bottom:8px"><strong>Starts:</strong> Monday, August 10.</li>
  <li style="margin-bottom:8px"><strong>Price:</strong> <span style="color:#5b8e0d;font-weight:800">$200 per person</span>, a one-time discounted beta price for this class only.</li>
</ul>

<p>Seats are limited for this beta round.</p>

<p style="text-align:center;margin:26px 0">
  <a href="https://agents.innovateonline.com/register.php?s=launch-2-0" style="display:inline-block;background:#5b8e0d;color:#fff;font-weight:700;text-decoration:none;padding:12px 28px;border-radius:6px;font-size:15px">RSVP for LAUNCH 2.0</a>
</p>

</div>
HTML;

$recipients = ce_resolve_recipients(['all'], [], '');
if (!$recipients) { fwrite(STDERR, "no recipients resolved for audience 'all'\n"); exit(1); }

$ins = $db->prepare("INSERT INTO notification_queue (recipient, channel, subject, body, phone, is_html, attachment_ids, from_email, from_name) VALUES (?, 'email', ?, ?, '', 1, '', ?, ?)");
foreach ($recipients as $r) {
    $personalized = ce_apply_merge_vars($db, LAUNCH2_HOST, $html, $r);
    $ins->execute([$r['email'], LAUNCH2_SUBJECT, $personalized, LAUNCH2_FROM_EMAIL, LAUNCH2_FROM_NAME]);
}

$db->prepare(
    "INSERT INTO company_emails (sender_email, sender_role, audience, target_mc_slug, subject, body, recipient_count, attachment_ids)
     VALUES (?, 'super_admin', 'all', '', ?, ?, ?, '')"
)->execute([LAUNCH2_FROM_EMAIL, LAUNCH2_SUBJECT, $html, count($recipients)]);
ce_log_to_agent_records($recipients, LAUNCH2_SUBJECT, $html, LAUNCH2_FROM_EMAIL, (int)$db->lastInsertId());

echo "[" . date('Y-m-d H:i:s') . "] LAUNCH 2.0 announcement queued for " . count($recipients) . " recipients\n";
process_notification_queue();
echo "[" . date('Y-m-d H:i:s') . "] Queue processed.\n";
