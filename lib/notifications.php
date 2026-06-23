<?php
// Announcement notification helpers.
// Queues outbound email + SMS for a new announcement, then sends them.
//
// Usage after announcement create:
//   require __DIR__ . '/../lib/notifications.php';
//   queue_announcement_notifications($id, $title, $body, $audience, $mcSlug, $bicEmail);
//   dispatch_notification_queue();   // closes HTTP response first, then sends

require_once __DIR__ . '/../local_db.php';

// ── Audience resolution ───────────────────────────────────────────────────────

// Returns [['email'=>..., 'sms_phone'=>...], ...] for opted-in recipients
// matching the given announcement audience.
function resolve_notification_recipients(string $audience, string $targetMcSlug, string $targetBicEmail): array {
    $db = local_db();

    // Start from notification_prefs — only opted-in agents.
    // We LEFT JOIN agent_roles so we can filter by placement for mc/bic audiences.
    $base = "SELECT np.email, np.notify_email, np.notify_sms, np.sms_phone,
                     COALESCE(ar.role,'agent') AS role,
                     COALESCE(ar.own_mc_slug,'')   AS own_mc_slug,
                     COALESCE(ar.bic_email,'')      AS bic_email
              FROM   notification_prefs np
              LEFT JOIN agent_roles ar ON LOWER(ar.email) = LOWER(np.email)
              WHERE  (np.notify_email = 1 OR np.notify_sms = 1)";

    switch ($audience) {
        case 'all':
            $stmt = $db->query($base);
            break;

        case 'admin':
            $stmt = $db->prepare($base . " AND ar.role IN ('super_admin','staff')");
            $stmt->execute();
            break;

        case 'mc':
            $stmt = $db->prepare($base . " AND ar.own_mc_slug = ?");
            $stmt->execute([$targetMcSlug]);
            break;

        case 'bic':
            $stmt = $db->prepare($base . " AND ar.bic_email = ?");
            $stmt->execute([$targetBicEmail]);
            break;

        default:
            return [];
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Queue builder ─────────────────────────────────────────────────────────────

function queue_announcement_notifications(
    int    $annId,
    string $title,
    string $body,
    string $audience,
    string $targetMcSlug,
    string $targetBicEmail
): int {
    $recipients = resolve_notification_recipients($audience, $targetMcSlug, $targetBicEmail);
    if (!$recipients) return 0;

    $db      = local_db();
    $ins     = $db->prepare(
        "INSERT INTO notification_queue (recipient, channel, subject, body, phone)
         VALUES (?, ?, ?, ?, ?)"
    );
    $subject  = 'New Announcement: ' . $title;
    $emailBody = $title . "\n\n" . $body . "\n\n— INNOVATE Real Estate\nLog in to AgentEdge: https://agentedge.innovateonline.com";
    $smsBody   = 'INNOVATE: ' . $title . ' — ' . mb_substr(strip_tags($body), 0, 120);
    if (mb_strlen($smsBody) > 155) $smsBody = mb_substr($smsBody, 0, 152) . '…';

    $queued = 0;
    foreach ($recipients as $r) {
        if ($r['notify_email']) {
            $ins->execute([$r['email'], 'email', $subject, $emailBody, '']);
            $queued++;
        }
        if ($r['notify_sms'] && $r['sms_phone'] !== '') {
            $ins->execute([$r['email'], 'sms', '', $smsBody, $r['sms_phone']]);
            $queued++;
        }
    }
    return $queued;
}

// ── Queue processor ───────────────────────────────────────────────────────────

// Closes the HTTP response if possible, then drains pending queue items.
// Call this after echoing your JSON response.
function dispatch_notification_queue(): void {
    // Let PHP keep running after the HTTP response is sent.
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        // Flush output buffers for non-FPM setups.
        if (ob_get_level()) ob_end_flush();
        flush();
    }
    process_notification_queue();
}

function process_notification_queue(int $limit = 100): void {
    $c = cfg();
    if (empty($c['sendgrid_key']) && empty($c['twilio_sid'])) return;

    $db   = local_db();
    $rows = $db->prepare(
        "SELECT id, recipient, channel, subject, body, phone
         FROM notification_queue
         WHERE status='pending' AND attempts < 3
         ORDER BY id
         LIMIT ?"
    );
    $rows->execute([$limit]);
    $items = $rows->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) return;

    $markSent   = $db->prepare("UPDATE notification_queue SET status='sent',   sent_at=datetime('now'), attempts=attempts+1 WHERE id=?");
    $markFailed = $db->prepare("UPDATE notification_queue SET status='failed', attempts=attempts+1 WHERE id=?");

    foreach ($items as $item) {
        try {
            $ok = false;
            if ($item['channel'] === 'email') {
                $ok = send_email_sendgrid($item['recipient'], $item['subject'], $item['body'], $c);
            } elseif ($item['channel'] === 'sms') {
                $ok = send_sms_twilio($item['phone'], $item['body'], $c);
            }
            ($ok ? $markSent : $markFailed)->execute([$item['id']]);
        } catch (\Throwable $e) {
            $markFailed->execute([$item['id']]);
        }
    }
}

// ── SendGrid email ────────────────────────────────────────────────────────────

function send_email_sendgrid(string $to, string $subject, string $body, array $c): bool {
    $key  = $c['sendgrid_key']  ?? '';
    $from = $c['sendgrid_from'] ?? 'noreply@innovateonline.com';
    $name = $c['sendgrid_name'] ?? 'INNOVATE Real Estate';
    if (!$key || !$to) return false;

    $payload = json_encode([
        'personalizations' => [['to' => [['email' => $to]]]],
        'from'    => ['email' => $from, 'name' => $name],
        'subject' => $subject,
        'content' => [
            ['type' => 'text/plain', 'value' => $body],
            ['type' => 'text/html',  'value' => nl2br(htmlspecialchars($body, ENT_QUOTES))],
        ],
    ]);

    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

// ── Twilio SMS ────────────────────────────────────────────────────────────────

function send_sms_twilio(string $to, string $message, array $c): bool {
    $sid   = $c['twilio_sid']   ?? '';
    $token = $c['twilio_token'] ?? '';
    $from  = $c['twilio_from']  ?? '';
    if (!$sid || !$token || !$from || !$to) return false;

    // Normalize to E.164 — strip non-digits and prepend +1 if needed.
    $digits = preg_replace('/\D/', '', $to);
    if (strlen($digits) === 10) $digits = '1' . $digits;
    $e164 = '+' . $digits;

    $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => "{$sid}:{$token}",
        CURLOPT_POSTFIELDS     => http_build_query(['From' => $from, 'To' => $e164, 'Body' => $message]),
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}
