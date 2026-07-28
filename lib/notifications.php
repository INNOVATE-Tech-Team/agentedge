<?php
// Announcement notification helpers.
// Queues outbound email + SMS for a new announcement, then sends them.
//
// Usage after announcement create:
//   require __DIR__ . '/../lib/notifications.php';
//   queue_announcement_notifications($id, $title, $body, $audience, $mcSlug, $bicEmail);
//   dispatch_notification_queue();   // closes HTTP response first, then sends

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/company_email.php';
require_once __DIR__ . '/crypto.php';

// ── Audience resolution ───────────────────────────────────────────────────────

// Returns [['email'=>..., 'notify_email'=>1, 'notify_sms'=>0|1, 'sms_phone'=>...], ...].
// Email reach mirrors Company Email's model: 'all'/'mc' are sourced from the
// full active innovate_roster (not notification_prefs, which only ever has a
// row for an agent who's visited notification settings — nearly nobody, so an
// INNER JOIN against it silently reached ~4% of the company). Every agent is
// opted in to email by default; notification_prefs is now only consulted for
// an explicit opt-out (notify_email=0) or SMS opt-in (a phone number an agent
// entered themselves, which stays opt-in since there's nothing to fall back to).
function resolve_notification_recipients(string $audience, string $targetMcSlug, string $targetBicEmail): array {
    $db = local_db();

    $optOut = array_flip(array_map(
        fn($e) => strtolower(trim($e)),
        $db->query("SELECT email FROM notification_prefs WHERE notify_email=0")->fetchAll(PDO::FETCH_COLUMN)
    ));
    $smsByEmail = [];
    foreach ($db->query("SELECT email, sms_phone FROM notification_prefs WHERE notify_sms=1 AND sms_phone<>''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $smsByEmail[strtolower(trim($r['email']))] = $r['sms_phone'];
    }

    $emails = [];
    switch ($audience) {
        case 'all':
            foreach (ce_fetch_crm_roster() as $a) {
                $e = strtolower(trim($a['email'] ?? ''));
                if ($e && filter_var($e, FILTER_VALIDATE_EMAIL)) $emails[$e] = true;
            }
            break;

        case 'admin':
            foreach ($db->query("SELECT email FROM agent_roles WHERE role IN ('super_admin','staff')")->fetchAll(PDO::FETCH_COLUMN) as $e) {
                $e = strtolower(trim($e));
                if ($e) $emails[$e] = true;
            }
            break;

        case 'mc':
            foreach (ce_fetch_crm_roster() as $a) {
                $e  = strtolower(trim($a['email'] ?? ''));
                $mc = $a['marketCenter'] ?? '';
                if ($e && $mc !== '' && slugify_mc($mc) === $targetMcSlug) $emails[$e] = true;
            }
            break;

        case 'bic':
            $stmt = $db->prepare("SELECT email FROM agent_roles WHERE bic_email = ?");
            $stmt->execute([$targetBicEmail]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $e) {
                $e = strtolower(trim($e));
                if ($e) $emails[$e] = true;
            }
            break;

        default:
            return [];
    }

    $out = [];
    foreach (array_keys($emails) as $e) {
        if (isset($optOut[$e])) continue;
        $out[] = [
            'email'        => $e,
            'notify_email' => 1,
            'notify_sms'   => isset($smsByEmail[$e]) ? 1 : 0,
            'sms_phone'    => $smsByEmail[$e] ?? '',
        ];
    }
    return $out;
}

// ── Queue builder ─────────────────────────────────────────────────────────────

function queue_announcement_notifications(
    int    $annId,
    string $title,
    string $body,
    string $audience,
    string $targetMcSlug,
    string $targetBicEmail,
    string $fromEmail = '',
    string $fromName = ''
): int {
    $recipients = resolve_notification_recipients($audience, $targetMcSlug, $targetBicEmail);
    if (!$recipients) return 0;

    $db      = local_db();
    $ins     = $db->prepare(
        "INSERT INTO notification_queue (recipient, channel, subject, body, phone, is_html, from_email, from_name)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $subject   = 'New Announcement: ' . $title;
    $emailBody = notification_email_html(
        '<h2 style="margin:0 0 16px;color:#1a1a1a;font-size:20px;font-weight:800">' . htmlspecialchars($title, ENT_QUOTES) . '</h2>'
        . '<div style="color:#444;font-size:15px;line-height:1.7">' . $body . '</div>'
        . '<div style="margin-top:24px">'
        . '<a href="https://agentedge.innovateonline.com" style="display:inline-block;padding:12px 26px;background:#82C112;color:#1a1a1a;text-decoration:none;font-weight:700;border-radius:7px;font-size:14px">Log in to AgentEdge &rarr;</a>'
        . '</div>'
        . sender_signature_html($fromEmail, $fromName)
    );
    $smsBody   = 'INNOVATE: ' . $title . ' — ' . mb_substr(strip_tags($body), 0, 120);
    if (mb_strlen($smsBody) > 155) $smsBody = mb_substr($smsBody, 0, 152) . '…';

    $queued = 0;
    foreach ($recipients as $r) {
        if ($r['notify_email']) {
            $ins->execute([$r['email'], 'email', $subject, $emailBody, '', 1, $fromEmail, $fromName]);
            $queued++;
        }
        if ($r['notify_sms'] && $r['sms_phone'] !== '') {
            $ins->execute([$r['email'], 'sms', '', $smsBody, $r['sms_phone'], 0, '', '']);
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
        "SELECT id, recipient, channel, subject, body, phone, is_html, attachment_ids, from_email, from_name, reply_to
         FROM notification_queue
         WHERE status='pending' AND attempts < 3
         ORDER BY id
         LIMIT ?"
    );
    $rows->execute([$limit]);
    $items = $rows->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) return;

    $markSent = $db->prepare("UPDATE notification_queue SET status='sent', sent_at=datetime('now'), attempts=attempts+1 WHERE id=?");
    // A failed send only becomes permanently 'failed' once it's used up all 3
    // attempts — otherwise it goes back to 'pending' so the next queue drain
    // (cron runs every 5 min) retries it. Previously this always set
    // status='failed' on the very first failure, so the attempts<3 column
    // and its retry intent were dead code — a transient/rate-limited failure
    // had no chance to recover and just sat there forever.
    $markFailed = $db->prepare(
        "UPDATE notification_queue
            SET status = CASE WHEN attempts + 1 >= 3 THEN 'failed' ELSE 'pending' END,
                attempts = attempts + 1
          WHERE id = ?"
    );

    // A whole-company send queues one row per recipient with identical
    // attachment_ids — cache the resolved (base64-encoded) attachments per
    // distinct id-list so a 300-recipient blast reads each file once, not 300 times.
    $attachCache = [];

    // dispatch_notification_queue() calls this synchronously, in-request,
    // right before the response is sent — its "let PHP keep running after
    // the response is flushed" comment assumes fastcgi_finish_request(),
    // which doesn't exist under this container's Apache/mod_php setup. Without
    // it, a backlog (each failed send costing up to send_email_sendgrid's own
    // 15s curl timeout) can block the whole HTTP request for minutes, which
    // showed up as "Network error" on ticket replies. cron/process_email_queue.php
    // already drains this queue every 5 minutes regardless, so bail out after
    // a few seconds of work here and let the cron finish whatever's left —
    // bounds the worst case instead of processing the full $limit no matter how long it takes.
    $deadline = microtime(true) + 3.0;

    foreach ($items as $item) {
        if (microtime(true) > $deadline) break;
        try {
            $ok = false;
            if ($item['channel'] === 'email') {
                $attIds = trim($item['attachment_ids'] ?? '');
                if ($attIds === '') {
                    $attachments = [];
                } else {
                    if (!isset($attachCache[$attIds])) $attachCache[$attIds] = resolve_email_attachments($attIds);
                    $attachments = $attachCache[$attIds];
                }
                $ok = send_email_sendgrid($item['recipient'], $item['subject'], $item['body'], $c, (bool)($item['is_html'] ?? false), $attachments, $item['from_email'] ?? '', $item['from_name'] ?? '', trim($item['reply_to'] ?? ''));
            } elseif ($item['channel'] === 'sms') {
                $ok = send_sms_twilio($item['phone'], $item['body'], $c);
            }
            ($ok ? $markSent : $markFailed)->execute([$item['id']]);
        } catch (\Throwable $e) {
            $markFailed->execute([$item['id']]);
        }
    }
}

// Resolves a comma-separated email_attachments.id list into SendGrid-ready
// attachment objects (base64 content + filename + mime type). Missing files
// on disk are silently skipped rather than failing the whole send.
function resolve_email_attachments(string $attachmentIds): array {
    $ids = array_values(array_filter(array_map('intval', explode(',', $attachmentIds))));
    if (!$ids) return [];

    $db = local_db();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = $db->prepare("SELECT orig_name, mime_type, storage_key FROM email_attachments WHERE id IN ($placeholders)");
    $rows->execute($ids);

    $c   = cfg();
    $dir = ($c['local_db_dir'] ?? (__DIR__ . '/../data')) . '/email_attachments';

    $out = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $path = $dir . '/' . $r['storage_key'];
        if (!is_file($path)) continue;
        $out[] = [
            'content'     => base64_encode(file_get_contents($path)),
            'filename'    => $r['orig_name'] ?: $r['storage_key'],
            'type'        => $r['mime_type'] ?: 'application/octet-stream',
            'disposition' => 'attachment',
        ];
    }
    return $out;
}

// ── Onboarding / Offboarding direct notifications ─────────────────────────────

// Queue an email to the adding admin + any configured CC addresses when a new
// agent enters the onboarding queue. Callers must call dispatch_notification_queue()
// after flushing the HTTP response.
function notify_onboard_added(
    string $agentName,
    string $agentEmail,
    string $mc,
    string $startDate,
    string $sponsor,
    string $role,
    string $addedBy,
    string $addedByName = ''
): void {
    // $addedBy is usually the acting admin's own email, but a webhook caller
    // (e.g. onboard_push.php from Advantage CRM) can pass a non-email label —
    // only use it as the From address when it's actually a real address.
    $fromEmail = filter_var($addedBy, FILTER_VALIDATE_EMAIL) ? $addedBy : '';
    $c       = cfg();
    $subject = "New Agent Onboarding: {$agentName}";
    $body    = implode("\n", [
        "A new agent has been added to the onboarding queue in AgentEdge.",
        "",
        "Name:           {$agentName}",
        "Email:          {$agentEmail}",
        "Market Center:  " . ($mc        ?: '—'),
        "Start Date:     " . ($startDate ?: '—'),
        "Sponsor:        " . ($sponsor   ?: '—'),
        "Role:           " . ucwords(str_replace('_', ' ', $role)),
        "",
        "View the onboarding queue:",
        "https://agentedge.innovateonline.com/onboarding.php",
        "",
        "— AgentEdge",
    ]);

    $db  = local_db();
    $ins = $db->prepare(
        "INSERT INTO notification_queue (recipient, channel, subject, body, phone, from_email, from_name) VALUES (?,?,?,?,?,?,?)"
    );

    // $addedBy is only a real notification recipient when it's an actual
    // email — a webhook caller (advantage-crm) passing a non-email label
    // would otherwise queue a row that can never be delivered to anyone.
    if (filter_var($addedBy, FILTER_VALIDATE_EMAIL)) {
        $ins->execute([$addedBy, 'email', $subject, $body, '', $fromEmail, $addedByName]);
    }

    $ccEmails = $c['onboard_notify_emails'] ?? [];
    if (is_string($ccEmails)) {
        $ccEmails = array_filter(array_map('trim', explode(',', $ccEmails)));
    }
    foreach ((array)$ccEmails as $cc) {
        if ($cc && $cc !== $addedBy && filter_var($cc, FILTER_VALIDATE_EMAIL)) {
            $ins->execute([$cc, 'email', $subject, $body, '', $fromEmail, $addedByName]);
        }
    }
}

// Queue an email when an agent enters the offboarding queue.
function notify_offboard_added(
    string $agentName,
    string $agentEmail,
    string $mc,
    string $lastDay,
    string $reason,
    string $reasonNotes,
    string $addedBy,
    string $addedByName = ''
): void {
    $fromEmail = filter_var($addedBy, FILTER_VALIDATE_EMAIL) ? $addedBy : '';
    $c          = cfg();
    $reasonLabel = match ($reason) {
        'voluntary'   => 'Voluntary Resignation',
        'termination' => 'Termination',
        'transfer'    => 'Transfer to Another Brokerage',
        default       => 'Other',
    };
    $subject = "Agent Offboarding Started: {$agentName}";
    $body    = implode("\n", [
        "An agent has been added to the offboarding queue in AgentEdge.",
        "",
        "Name:           {$agentName}",
        "Email:          {$agentEmail}",
        "Market Center:  " . ($mc        ?: '—'),
        "Last Day:       " . ($lastDay   ?: '—'),
        "Reason:         {$reasonLabel}",
        "Notes:          " . ($reasonNotes ?: '—'),
        "",
        "View the offboarding queue:",
        "https://agentedge.innovateonline.com/offboarding.php",
        "",
        "— AgentEdge",
    ]);

    $db  = local_db();
    $ins = $db->prepare(
        "INSERT INTO notification_queue (recipient, channel, subject, body, phone, from_email, from_name) VALUES (?,?,?,?,?,?,?)"
    );

    // Same guard as notify_onboard_added() — $addedBy can be a non-email
    // webhook label, which must never be queued as a recipient.
    if (filter_var($addedBy, FILTER_VALIDATE_EMAIL)) {
        $ins->execute([$addedBy, 'email', $subject, $body, '', $fromEmail, $addedByName]);
    }

    $ccEmails = $c['onboard_notify_emails'] ?? [];
    if (is_string($ccEmails)) {
        $ccEmails = array_filter(array_map('trim', explode(',', $ccEmails)));
    }
    foreach ((array)$ccEmails as $cc) {
        if ($cc && $cc !== $addedBy && filter_var($cc, FILTER_VALIDATE_EMAIL)) {
            $ins->execute([$cc, 'email', $subject, $body, '', $fromEmail, $addedByName]);
        }
    }
}

// Marks a single offboarding step done and notifies the next actionable
// step's assignees. Shared by the admin mark_done action and the exit
// interview self-service submit path, so both go through the same
// update+notify pair.
function complete_offboard_step(PDO $pdo, int $queueId, string $toolKey, string $doneBy, string $doneByName = ''): void {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare(
        "UPDATE offboard_steps SET status='done', done_by=?, done_at=? WHERE queue_id=? AND tool_key=?"
    )->execute([$doneBy, $now, $queueId, $toolKey]);
    maybe_notify_next_actionable_step($pdo, 'offboard', $queueId, $doneBy, $doneByName);
}

// ── Per-user email signature ───────────────────────────────────────────────────

// Renders an HTML signature block from the sender's personal email_signatures row
// (managed via settings_signature.php). Falls back to name + "INNOVATE Real Estate".
function sender_signature_html(string $senderEmail, string $senderName = ''): string {
    if ($senderEmail === '') {
        return '<p style="color:#888;font-size:13px;margin-top:20px">— INNOVATE Real Estate</p>';
    }
    try {
        $st = local_db()->prepare(
            "SELECT title, phone, calendar_url, website_url, use_custom, custom_html, photo_key FROM email_signatures WHERE email=?"
        );
        $st->execute([strtolower($senderEmail)]);
        $sig = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) { $sig = null; }

    if ($sig && !empty($sig['use_custom']) && trim($sig['custom_html'] ?? '') !== '') {
        return '<div style="margin-top:24px;border-top:1px solid #ddd;padding-top:14px">' . $sig['custom_html'] . '</div>';
    }

    $displayName = $senderName ?: $senderEmail;
    $photoKey    = $sig['photo_key'] ?? '';
    $photoUrl    = ($photoKey !== '') ? 'https://agentedge.innovateonline.com/api/email_image.php?key=' . rawurlencode($photoKey) : '';

    $info  = '<div style="font-weight:700;color:#111;font-size:14px">' . htmlspecialchars($displayName, ENT_QUOTES) . '</div>';
    if ($sig) {
        if (!empty($sig['title'])) $info .= '<div style="font-size:12px;color:#666;margin-top:3px">' . htmlspecialchars($sig['title'], ENT_QUOTES) . '</div>';
        if (!empty($sig['phone'])) $info .= '<div style="font-size:12px;color:#666;margin-top:3px">' . htmlspecialchars($sig['phone'], ENT_QUOTES) . '</div>';
        $links = [];
        if (!empty($sig['calendar_url'])) $links[] = '<a href="' . htmlspecialchars($sig['calendar_url'], ENT_QUOTES) . '" style="color:#5b8e0d;text-decoration:underline">Schedule a meeting</a>';
        if (!empty($sig['website_url']))  $links[] = '<a href="' . htmlspecialchars($sig['website_url'], ENT_QUOTES) . '" style="color:#5b8e0d;text-decoration:underline">' . htmlspecialchars(preg_replace('#^https?://#', '', $sig['website_url']), ENT_QUOTES) . '</a>';
        if ($links) $info .= '<div style="font-size:12px;margin-top:5px">' . implode(' &nbsp;|&nbsp; ', $links) . '</div>';
    }
    $info .= '<div style="font-size:12px;color:#aaa;margin-top:3px">INNOVATE Real Estate</div>';

    if ($photoUrl !== '') {
        // Signature image is the entire signature — show it alone.
        $inner = '<img src="' . htmlspecialchars($photoUrl, ENT_QUOTES) . '"'
               . ' style="max-width:500px;width:100%;display:block;border:0" alt="">';
    } else {
        $inner = $info;
    }

    return '<div style="margin-top:24px;border-top:1px solid #ddd;padding-top:14px">' . $inner . '</div>';
}

// Wraps an HTML body in the standard branded email shell.
function notification_email_html(string $contentHtml): string {
    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"></head>'
         . '<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif">'
         . '<div style="max-width:600px;margin:0 auto;padding:24px 16px">'
         . '<div style="background:#82C112;padding:18px 28px;border-radius:8px 8px 0 0">'
         . '<span style="color:#1a1a1a;font-size:18px;font-weight:800;letter-spacing:-0.3px">INNOVATE Real Estate</span>'
         . '</div>'
         . '<div style="background:#ffffff;padding:28px;border-radius:0 0 8px 8px;border:1px solid #e0e0e0;border-top:none">'
         . $contentHtml
         . '</div>'
         . '<p style="text-align:center;font-size:11px;color:#bbb;margin-top:14px">Sent via AgentEdge</p>'
         . '</div></body></html>';
}

// ── Onboarding completion notifications ───────────────────────────────────────

// Queue a welcome email to the agent when their onboarding is marked complete.
function notify_onboard_completed(string $agentName, string $agentEmail, string $fromEmail = '', string $fromName = ''): void {
    // Whitney Beadling is the designated sender for all welcome emails.
    $senderEmail = 'whitney@innovateonline.com';
    $senderName  = 'Whitney Beadling';
    $firstName = htmlspecialchars(explode(' ', trim($agentName))[0], ENT_QUOTES);
    $subject   = 'Welcome to Innovate Real Estate, ' . $agentName . '!';
    $sig       = sender_signature_html($senderEmail, $senderName);
    $p         = 'style="color:#444;font-size:15px;line-height:1.75;margin:0 0 16px"';
    $li        = 'style="color:#444;font-size:15px;line-height:1.75;margin:0 0 8px"';
    $body      = notification_email_html(
        '<p ' . $p . '>Hi ' . $firstName . ',</p>'
        . '<p ' . $p . '>Congratulations, and welcome to Innovate Real Estate!</p>'
        . '<p ' . $p . '>You\'ve officially completed your onboarding, and we\'re excited to have you as part of the Innovate family.</p>'
        . '<p ' . $p . '>While onboarding may be complete, your journey is just getting started. Over the coming weeks you\'ll begin building relationships, developing your business, and taking advantage of the coaching, training, technology, and support designed to help you succeed.</p>'
        . '<p style="color:#1a1a1a;font-size:15px;font-weight:700;margin:0 0 10px">Here\'s what\'s next:</p>'
        . '<ul style="margin:0 0 20px;padding-left:20px">'
        . '<li ' . $li . '>Watch your inbox for upcoming training opportunities and company updates.</li>'
        . '<li ' . $li . '>If you\'re enrolled in L.A.U.N.C.H., your facilitator will be reaching out with details on your first session.</li>'
        . '<li ' . $li . '>Log into AgentEdge regularly to access resources, training, and important announcements.</li>'
        . '<li ' . $li . '>Connect with your Market Leader and fellow agents. The relationships you build here will become one of your greatest assets.</li>'
        . '</ul>'
        . '<p ' . $p . '>Most importantly, remember this:</p>'
        . '<p style="color:#1a1a1a;font-size:16px;font-weight:700;font-style:italic;margin:0 0 16px;padding:14px 20px;border-left:4px solid #82C112;background:#f9fdf5">You are never expected to figure this business out alone.</p>'
        . '<p ' . $p . '>Whether you have a question about contracts, technology, marketing, lead generation, negotiations, or simply need someone to bounce an idea off of, we\'re here to help.</p>'
        . '<p ' . $p . '>We\'re thrilled you\'ve chosen to build your business with Innovate, and we can\'t wait to see what you accomplish.</p>'
        . '<p style="color:#444;font-size:15px;line-height:1.75;margin:0 0 24px">Welcome aboard.</p>'
        . '<a href="https://agentedge.innovateonline.com" style="display:inline-block;padding:12px 26px;background:#82C112;color:#1a1a1a;text-decoration:none;font-weight:700;border-radius:7px;font-size:14px">Log in to AgentEdge &rarr;</a>'
        . $sig
    );

    local_db()->prepare(
        "INSERT INTO notification_queue (recipient, channel, subject, body, phone, is_html, from_email, from_name) VALUES (?,?,?,?,?,1,?,?)"
    )->execute([$agentEmail, 'email', $subject, $body, '', $senderEmail, $senderName]);
}

// Queue an email to the Director of Coaching + Launch Facilitator(s) to assign
// a Launch Coach and LAUNCH class. Only called for new agents (no prior
// brokerage affiliation on their intake form) — experienced transfers skip this.
function notify_coach_assignment_needed(string $agentName, string $agentEmail, string $fromEmail = '', string $fromName = ''): void {
    $db   = local_db();
    $st   = $db->prepare("SELECT email FROM agent_roles WHERE role IN ('director_of_coaching','launch_facilitator')");
    $st->execute();
    $emails = array_values(array_unique(array_filter(array_map('trim', $st->fetchAll(PDO::FETCH_COLUMN)))));
    if (!$emails) return;

    $subject = 'New Agent — Assign Launch Coach & LAUNCH Class: ' . $agentName;
    $eName   = htmlspecialchars($agentName, ENT_QUOTES);
    $eEmail  = htmlspecialchars($agentEmail, ENT_QUOTES);
    $body    = notification_email_html(
        '<h2 style="margin:0 0 14px;color:#1a1a1a;font-size:20px;font-weight:800">Coach Assignment Needed</h2>'
        . '<p style="color:#444;font-size:15px;line-height:1.65;margin:0 0 14px"><strong>' . $eName . '</strong> (' . $eEmail . ') is a new agent who just completed onboarding.</p>'
        . '<p style="color:#444;font-size:15px;line-height:1.65;margin:0 0 20px">Please assign a Launch Coach and enroll them in the next LAUNCH class.</p>'
        . '<a href="https://agentedge.innovateonline.com/onboarding.php" style="display:inline-block;padding:12px 26px;background:#82C112;color:#1a1a1a;text-decoration:none;font-weight:700;border-radius:7px;font-size:14px">View Onboarding Queue &rarr;</a>'
        . sender_signature_html($fromEmail, $fromName)
    );

    $ins = $db->prepare(
        "INSERT INTO notification_queue (recipient, channel, subject, body, phone, is_html, from_email, from_name) VALUES (?,?,?,?,?,1,?,?)"
    );
    foreach ($emails as $email) {
        $ins->execute([$email, 'email', $subject, $body, '', $fromEmail, $fromName]);
    }
}

// Queue a completion email to the agent's BIC and Market Center Leader,
// looked up from market_centers by matching the agent's market center name.
// A non-matching/blank market center is a no-op, not an error — shouldn't
// block onboarding completion over a mismatched free-text field.
function notify_bic_ml_onboard_complete(string $agentName, string $agentEmail, string $marketCenter, string $fromEmail = '', string $fromName = ''): void {
    $marketCenter = trim($marketCenter);
    if ($marketCenter === '') return;

    $db = local_db();
    $st = $db->prepare("SELECT bic_email, mc_leader_email FROM market_centers WHERE LOWER(name) = LOWER(?)");
    $st->execute([$marketCenter]);
    $mc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$mc) return;

    $emails = array_values(array_unique(array_filter([trim($mc['bic_email'] ?? ''), trim($mc['mc_leader_email'] ?? '')])));
    if (!$emails) return;

    $subject = 'Onboarding Complete: ' . $agentName;
    $eName   = htmlspecialchars($agentName, ENT_QUOTES);
    $eMC     = htmlspecialchars($marketCenter, ENT_QUOTES);
    $body    = notification_email_html(
        '<h2 style="margin:0 0 14px;color:#1a1a1a;font-size:20px;font-weight:800">Onboarding Complete</h2>'
        . '<p style="color:#444;font-size:15px;line-height:1.65;margin:0 0 14px"><strong>' . $eName . '</strong> (' . htmlspecialchars($agentEmail, ENT_QUOTES) . ') has completed onboarding at ' . $eMC . '.</p>'
        . '<p style="color:#444;font-size:15px;line-height:1.65;margin:0">They are now active on the roster.</p>'
        . sender_signature_html($fromEmail, $fromName)
    );

    $ins = $db->prepare(
        "INSERT INTO notification_queue (recipient, channel, subject, body, phone, is_html, from_email, from_name) VALUES (?,?,?,?,?,1,?,?)"
    );
    foreach ($emails as $email) {
        $ins->execute([$email, 'email', $subject, $body, '', $fromEmail, $fromName]);
    }
}

// Queue an email to the departing agent with a link to fill out their exit
// interview. Sent when an admin clicks "Send Exit Interview" — the agent's
// AgentEdge login is still active at this point (account inactivation is a
// later offboarding step), so this is a plain login link, not a public/token link.
function notify_exit_interview_sent(string $agentName, string $agentEmail, string $fromEmail = '', string $fromName = ''): void {
    $subject = "Please complete your exit interview — AgentEdge";
    $body    = implode("\n", [
        "Hi {$agentName},",
        "",
        "As part of your offboarding, please take a few minutes to complete a short exit interview.",
        "",
        "Log in to AgentEdge and fill it out here:",
        "https://agentedge.innovateonline.com/exit_interview.php",
        "",
        "Thank you,",
        "— AgentEdge",
    ]);

    $db  = local_db();
    $db->prepare(
        "INSERT INTO notification_queue (recipient, channel, subject, body, phone, from_email, from_name) VALUES (?,?,?,?,?,?,?)"
    )->execute([$agentEmail, 'email', $subject, $body, '', $fromEmail, $fromName]);
}

// ── Onboarding / Offboarding per-step notifications ───────────────────────────

// Staff emails assigned to a specific onboarding/offboarding step (see
// step_notify_staff, configured on admin_step_notify.php).
function step_assignees(string $process, string $stepKey): array {
    $s = local_db()->prepare("SELECT email FROM step_notify_staff WHERE process=? AND step_key=?");
    $s->execute([$process, $stepKey]);
    return array_values(array_unique(array_filter(array_map('trim', $s->fetchAll(PDO::FETCH_COLUMN)))));
}

// Heads-up email sent once, when a case is created, to everyone assigned to
// any step in it — consolidated into one email per person listing all of
// their steps for this case. $steps is a list of ['key'=>..., 'label'=>...].
function notify_step_assignees_on_create(string $process, string $agentName, string $agentEmail, array $steps, string $fromEmail = '', string $fromName = ''): void {
    $byEmail = [];
    foreach ($steps as $step) {
        foreach (step_assignees($process, $step['key']) as $email) {
            $byEmail[$email][] = $step['label'];
        }
    }
    if (!$byEmail) return;

    $verb = $process === 'onboard' ? 'onboarding' : 'offboarding';
    $page = $process === 'onboard' ? 'onboarding.php' : 'offboarding.php';
    $subject = ucfirst($verb) . " Steps Assigned To You: {$agentName}";

    foreach ($byEmail as $email => $labels) {
        $body = implode("\n", [
            "{$agentName} ({$agentEmail}) has started {$verb} in AgentEdge.",
            "",
            "You are responsible for:",
            "- " . implode("\n- ", $labels),
            "",
            "You'll get a follow-up email when each step is ready for you to act on.",
            "",
            "View the queue:",
            "https://agentedge.innovateonline.com/{$page}",
            "",
            "— AgentEdge",
        ]);
        queue_email_to([$email], $subject, $body, $fromEmail, $fromName);
    }
}

// "It's your turn" email sent when a step becomes the next actionable one.
function notify_step_actionable(string $process, string $stepKey, string $stepLabel, string $agentName, string $agentEmail, string $fromEmail = '', string $fromName = ''): void {
    $emails = step_assignees($process, $stepKey);
    if (!$emails) return;

    $verb = $process === 'onboard' ? 'onboarding' : 'offboarding';
    $page = $process === 'onboard' ? 'onboarding.php' : 'offboarding.php';
    $subject = "Action Needed: {$stepLabel} for {$agentName}";
    $body = implode("\n", [
        "The \"{$stepLabel}\" step is now ready for you in {$agentName}'s {$verb} ({$agentEmail}).",
        "",
        "Mark it done here:",
        "https://agentedge.innovateonline.com/{$page}",
        "",
        "— AgentEdge",
    ]);
    queue_email_to($emails, $subject, $body, $fromEmail, $fromName);
}

// Finds the earliest pending step (in tool order) that hasn't been notified
// yet and, if found, emails its assignees and marks it notified. Call this
// right after any step transitions to done/skipped so the next step's
// owners find out as soon as it's their turn. Safe to call unconditionally —
// no-ops if nothing changed. $fromEmail/$fromName identify whoever completed
// the prior step (the action that made this one actionable).
function maybe_notify_next_actionable_step(PDO $pdo, string $process, int $queueId, string $fromEmail = '', string $fromName = ''): void {
    $stepTable  = $process === 'onboard' ? 'onboard_steps' : 'offboard_steps';
    $queueTable = $process === 'onboard' ? 'onboard_queue' : 'offboard_queue';

    $st = $pdo->prepare(
        "SELECT id, tool_key, tool_label FROM {$stepTable}
         WHERE queue_id=? AND status='pending' AND notified_at IS NULL
         ORDER BY id LIMIT 1"
    );
    $st->execute([$queueId]);
    $step = $st->fetch(PDO::FETCH_ASSOC);
    if (!$step) return;

    $q = $pdo->prepare("SELECT agent_name, agent_email FROM {$queueTable} WHERE id=?");
    $q->execute([$queueId]);
    $entry = $q->fetch(PDO::FETCH_ASSOC);
    if (!$entry) return;

    notify_step_actionable($process, $step['tool_key'], $step['tool_label'], $entry['agent_name'], $entry['agent_email'], $fromEmail, $fromName);
    $pdo->prepare("UPDATE {$stepTable} SET notified_at=datetime('now') WHERE id=?")->execute([$step['id']]);
}

// ── Self-service profile change notifications ────────────────────────────────

// Queues a heads-up email whenever an agent edits their OWN profile (My
// Profile or the Intake Form) — not staff/back-office edits made on an
// agent's behalf. $changes is [field label => [old, new]]; a no-op save
// (nothing actually different) is silently skipped so this doesn't spam
// Whitney every time someone opens and re-saves the form unchanged.
function notify_profile_changed(string $agentName, string $agentEmail, array $changes): void {
    if (!$changes) return;

    $subject = ($agentName ?: $agentEmail) . " updated their AgentEdge profile";
    $lines   = [];
    foreach ($changes as $label => [$old, $new]) {
        $lines[] = "- {$label}: " . ($old !== '' ? $old : '(blank)') . " -> " . ($new !== '' ? $new : '(blank)');
    }
    $body = implode("\n", array_merge(
        ["{$agentName} ({$agentEmail}) updated their profile in AgentEdge.", "", "Changed:"],
        $lines,
        [
            "",
            "View their profile:",
            "https://agents.innovateonline.com/agent_profile.php?email=" . urlencode($agentEmail),
            "",
            "— AgentEdge",
        ]
    ));
    queue_email_to(['whitney@innovateonline.com'], $subject, $body, $agentEmail, $agentName);
}

// ── Intake form submission notification ─────────────────────────────────────

// Notify the ops team when an agent submits their intake form for the first time.
function notify_intake_submitted(string $agentName, string $agentEmail): void {
    $displayName = $agentName ?: $agentEmail;
    $subject = $displayName . ' completed their intake form';
    $body = implode("\n", [
        $displayName . ' (' . $agentEmail . ') has submitted their AgentEdge intake form.',
        '',
        'View their profile:',
        'https://agentedge.innovateonline.com/agent_profile.php?email=' . urlencode($agentEmail),
        '',
        '— AgentEdge',
    ]);
    $recipients = [
        'lisa@innovateonline.com',
        'dominic@innovateonline.com',
        'darren@innovateonline.com',
        'abril@innovateonline.com',
        'whitney@innovateonline.com',
    ];
    queue_email_to($recipients, $subject, $body, $agentEmail, $agentName);
}

// ── Growth network (upline) notifications ────────────────────────────────────

// Returns this agent's sponsor chain — same "sponsored by" relationship shown
// on the My Network page (network.php/api/network_tree.php) — nearest first:
// [['name'=>..,'email'=>..,'terminated'=>bool], ...]. Walks the recruit-source
// parent pointer upward (agent_admin.recruit_source_email override wins when
// set, same as the Network page) until it runs out or hits a cycle. Uses the
// *_safe DB helpers so a CRM hiccup degrades to "no upline" instead of taking
// down whatever triggered this (e.g. an intake form submission).
function upline_chain(string $agentEmail): array {
    $root = db_one_safe("SELECT staffid FROM tblstaff WHERE email = ? LIMIT 1", [$agentEmail]);
    if (!$root) return [];
    $rootId = (string)$root['staffid'];

    $rows = db_query_safe(
        "SELECT t.agent_id, t.recruit_source_agent_id, s.firstname, s.lastname, s.email AS agent_email
         FROM tblre_transaction_agents t
         LEFT JOIN tblstaff s ON s.staffid = t.agent_id"
    );
    if (!$rows) return [];

    $nodes = []; $parents = [];
    foreach ($rows as $row) {
        $id    = (string)$row['agent_id'];
        $email = $row['agent_email'] ?? '';
        $nodes[$id] = [
            'name'  => trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')) ?: 'Agent',
            'email' => $email,
        ];
        $parent = (string)($row['recruit_source_agent_id'] ?? '');
        if ($parent !== '' && $parent !== '0') $parents[$id] = $parent;
    }

    $emailToId = [];
    foreach ($nodes as $id => $n) {
        if (!empty($n['email'])) $emailToId[strtolower($n['email'])] = $id;
    }
    try {
        $overrides = local_db()->query(
            "SELECT email, recruit_source_email FROM agent_admin WHERE recruit_source_email <> ''"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($overrides as $o) {
            $childId  = $emailToId[strtolower(trim($o['email']))] ?? null;
            $sourceId = $emailToId[strtolower(trim($o['recruit_source_email']))] ?? null;
            if ($childId === null || $sourceId === null || $childId === $sourceId) continue;
            $parents[$childId] = $sourceId;
        }
    } catch (\Exception $e) {}

    $terminated = [];
    try {
        $termRows = local_db()->query("SELECT email FROM agent_admin WHERE terminated_date <> ''")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($termRows as $r) {
            $tid = $emailToId[strtolower(trim($r['email']))] ?? null;
            if ($tid !== null) $terminated[$tid] = true;
        }
    } catch (\Exception $e) {}

    $chain = [];
    $seen  = [$rootId => true]; // guard against a cycle
    $cur   = $parents[$rootId] ?? null;
    while ($cur !== null && isset($nodes[$cur]) && empty($seen[$cur])) {
        $seen[$cur] = true;
        $chain[] = [
            'name'       => $nodes[$cur]['name'],
            'email'      => $nodes[$cur]['email'],
            'terminated' => !empty($terminated[$cur]),
        ];
        $cur = $parents[$cur] ?? null;
    }
    return $chain;
}

// Congratulates everyone in a newly-submitted agent's upline (their sponsor,
// their sponsor's sponsor, etc.) when the agent completes their intake form
// for the first time. Terminated sponsors are skipped as recipients (their
// inbox likely isn't monitored anymore) but don't break the chain — whoever
// sponsored THEM still gets notified, same as the Network page keeps showing
// the recruits below a departed sponsor.
function notify_upline_intake_submitted(string $agentName, string $agentEmail, string $marketCenter): int {
    $chain = upline_chain($agentEmail);
    if (!$chain) return 0;

    $recipients = [];
    foreach ($chain as $a) {
        if ($a['terminated']) continue;
        $e = strtolower(trim($a['email'] ?? ''));
        if ($e && filter_var($e, FILTER_VALIDATE_EMAIL)) $recipients[$e] = true;
    }
    if (!$recipients) return 0;

    $subject = "New Agent in Your Growth Network: {$agentName}";
    $body    = notification_email_html(
        '<h2 style="margin:0 0 16px;color:#1a1a1a;font-size:20px;font-weight:800">Congratulations!</h2>'
        . '<p style="color:#444;font-size:15px;line-height:1.7;margin:0 0 10px">Another agent has been added to your growth network!</p>'
        . '<p style="color:#444;font-size:15px;line-height:1.7;margin:0"><strong>' . htmlspecialchars($agentName, ENT_QUOTES) . '</strong>'
        . ' out of ' . htmlspecialchars($marketCenter !== '' ? $marketCenter : 'their Market Center', ENT_QUOTES) . '.</p>'
    );

    $ins = local_db()->prepare(
        "INSERT INTO notification_queue (recipient, channel, subject, body, phone, is_html, from_email, from_name) VALUES (?, 'email', ?, ?, '', 1, '', '')"
    );
    foreach (array_keys($recipients) as $email) {
        $ins->execute([$email, $subject, $body]);
    }
    return count($recipients);
}

// Market Center Leader(s) for a comma-separated office_location value (an
// agent can join more than one office). Resolves the leader's display name
// against the CRM roster, same source Company Email uses, so this never
// touches the legacy Perfex tables. Returns [['office'=>..,'name'=>..,'email'=>..], ...].
function office_market_leaders(string $officeLocationCsv): array {
    $offices = array_values(array_filter(array_map('trim', explode(',', $officeLocationCsv))));
    if (!$offices) return [];

    $nameByEmail = [];
    foreach (ce_fetch_crm_roster() as $a) {
        $e = strtolower(trim($a['email'] ?? ''));
        if ($e) $nameByEmail[$e] = $a['fullName'] ?? '';
    }

    $db  = local_db();
    $out = [];
    foreach ($offices as $office) {
        $st = $db->prepare("SELECT mc_leader_email FROM market_centers WHERE LOWER(name) = LOWER(?)");
        $st->execute([$office]);
        $leaderEmail = strtolower(trim($st->fetchColumn() ?: ''));
        if ($leaderEmail === '') continue;
        $out[] = ['office' => $office, 'email' => $leaderEmail, 'name' => $nameByEmail[$leaderEmail] ?? ''];
    }
    return $out;
}

// Full intake-summary email to ops (dominic@/darren@) when an agent submits
// their intake form for the first time. Tax ID is shown as last-4-only
// (tax_id_last4(), same masked-display convention used elsewhere in the app)
// rather than the full decrypted SSN/EIN — a plaintext email is not an
// appropriate place for the full number. "Corporation Name" has no backing
// field anywhere in the intake form today, so it's omitted rather than
// guessed at; "Referring Source" and "Anniversary Date" are intentional
// duplicates of Recruited By / Start Date, not separate data.
function notify_intake_summary_admins(string $agentEmail): int {
    $st = local_db()->prepare("SELECT * FROM agent_intake WHERE email = ?");
    $st->execute([strtolower(trim($agentEmail))]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return 0;

    $addrParts = array_filter(array_map('trim', [
        $row['address_line1'] ?? '',
        $row['address_line2'] ?? '',
        $row['city'] ?? '',
        trim(($row['state'] ?? '') . ' ' . ($row['zip'] ?? '')),
        (($row['country'] ?? '') !== 'United States') ? ($row['country'] ?? '') : '',
    ]), fn($p) => $p !== '');
    $address = $addrParts ? implode(', ', $addrParts) : '—';

    $personalLast4 = tax_id_last4($row['personal_tax_id_enc'] ?? '');
    $corpLast4     = tax_id_last4($row['corporate_tax_id_enc'] ?? '');
    if ($corpLast4 !== '' && $personalLast4 !== '') {
        $taxKind = 'Personal & Corporate';
        $taxId   = "Personal ending {$personalLast4}, Corporate ending {$corpLast4}";
    } elseif ($corpLast4 !== '') {
        $taxKind = 'Corporate';
        $taxId   = "Ending in {$corpLast4}";
    } elseif ($personalLast4 !== '') {
        $taxKind = 'Personal';
        $taxId   = "Ending in {$personalLast4}";
    } else {
        $taxKind = '—';
        $taxId   = '—';
    }

    $leaders = office_market_leaders($row['office_location'] ?? '');
    if (!$leaders) {
        $marketLeader = '—';
    } else {
        $parts = array_map(function ($l) use ($leaders) {
            $label = $l['name'] !== '' ? "{$l['name']} ({$l['email']})" : $l['email'];
            return count($leaders) > 1 ? "{$l['office']}: {$label}" : $label;
        }, $leaders);
        $marketLeader = implode('; ', $parts);
    }

    $recruitedBy = $row['referring_agent'] !== '' ? $row['referring_agent'] : '—';
    $startDate   = $row['career_start'] !== '' ? $row['career_start'] : '—';
    $name        = $row['full_name'] ?: $agentEmail;

    $rowHtml = function (string $label, string $value): string {
        return '<tr><td style="padding:6px 14px 6px 0;color:#888;font-size:13px;font-weight:700;white-space:nowrap;vertical-align:top">' . htmlspecialchars($label, ENT_QUOTES) . '</td>'
             . '<td style="padding:6px 0;color:#222;font-size:14px;vertical-align:top">' . htmlspecialchars($value !== '' ? $value : '—', ENT_QUOTES) . '</td></tr>';
    };

    $subject = "Intake Form Submitted: {$name}";
    $body    = notification_email_html(
        '<h2 style="margin:0 0 16px;color:#1a1a1a;font-size:20px;font-weight:800">Intake Form Submitted</h2>'
        . '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse">'
        . $rowHtml('Name:', $name)
        . $rowHtml('Office:', $row['office_location'] ?? '')
        . $rowHtml('Recruited By:', $recruitedBy)
        . $rowHtml('Email:', $row['email'] ?? $agentEmail)
        . $rowHtml('Phone Number:', $row['phone'] ?? '')
        . $rowHtml('Address:', $address)
        . $rowHtml('Tax ID Number:', $taxId)
        . $rowHtml('Personal or Corporate:', $taxKind)
        . $rowHtml('Start Date:', $startDate)
        . $rowHtml('Anniversary Date:', $startDate)
        . $rowHtml('Birthday:', $row['birthday'] ?? '')
        . $rowHtml('Market Leader:', $marketLeader)
        . $rowHtml('Referring Source:', $recruitedBy)
        . '</table>'
        . '<div style="margin-top:20px">'
        . '<a href="https://agentedge.innovateonline.com/agent_profile.php?email=' . urlencode($agentEmail) . '" style="display:inline-block;padding:12px 26px;background:#82C112;color:#1a1a1a;text-decoration:none;font-weight:700;border-radius:7px;font-size:14px">View Profile &rarr;</a>'
        . '</div>'
    );

    $ins = local_db()->prepare(
        "INSERT INTO notification_queue (recipient, channel, subject, body, phone, is_html, from_email, from_name) VALUES (?, 'email', ?, ?, '', 1, '', '')"
    );
    foreach (['dominic@innovateonline.com', 'darren@innovateonline.com'] as $recipient) {
        $ins->execute([$recipient, $subject, $body]);
    }
    return 2;
}

// ── Support ticket notifications ─────────────────────────────────────────────

// Email addresses of every super_admin. Ticket/suggestion notifications go
// only to super admins, regardless of department staff routing.
function super_admin_emails(): array {
    $emails = local_db()->query("SELECT email FROM agent_roles WHERE role = 'super_admin'")->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_unique(array_filter(array_map('strtolower', array_map('trim', $emails)))));
}

// Ticket notifications, narrowed for now: instead of blasting every
// super_admin, only Darren sees them (plus the ticket's own agent/CCs,
// handled separately). Revert to super_admin_emails() here to go back to
// notifying the whole admin roster.
function ticket_notify_admin_emails(): array {
    return ['darren@innovateonline.com'];
}

// CC'd staff emails for a ticket.
function support_ticket_cc_emails(int $ticketId): array {
    $s = local_db()->prepare("SELECT email FROM support_ticket_cc WHERE ticket_id=?");
    $s->execute([$ticketId]);
    return $s->fetchAll(PDO::FETCH_COLUMN);
}

// Queue a plain-text email to a list of recipients (deduped, empty entries dropped).
// $fromEmail/$fromName identify the AgentEdge user whose action triggered this
// send — blank means no specific actor, falls back to the system default sender.
// $replyTo, when set, becomes the Reply-To header — used by ticket notifications
// so a reply typed in the recipient's mail client routes back into the thread.
function queue_email_to(array $emails, string $subject, string $body, string $fromEmail = '', string $fromName = '', string $replyTo = ''): int {
    $ins = local_db()->prepare(
        "INSERT INTO notification_queue (recipient, channel, subject, body, phone, from_email, from_name, reply_to) VALUES (?, 'email', ?, ?, '', ?, ?, ?)"
    );
    $sent = 0;
    foreach (array_unique(array_filter(array_map('trim', $emails))) as $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $ins->execute([$email, $subject, $body, $fromEmail, $fromName, $replyTo]);
        $sent++;
    }
    return $sent;
}

// ── Ticket reply-by-email ─────────────────────────────────────────────────────

// Short HMAC token gating a ticket's reply address — without it, guessing a
// ticket id would let anyone post into that thread by email. Derived from
// sendgrid_key when ticket_reply_secret isn't set, so no extra provisioning
// is required to turn the feature on.
function ticket_reply_token(int $ticketId): string {
    $c      = cfg();
    $secret = ($c['ticket_reply_secret'] ?? '') ?: (($c['sendgrid_key'] ?? '') ?: 'agentedge-ticket-reply');
    return substr(hash_hmac('sha256', (string)$ticketId, $secret), 0, 12);
}

// The per-ticket Reply-To address, e.g. reply+142-a1b2c3d4e5f6@reply.innovateonline.com.
// Requires SendGrid Inbound Parse routed at api/ticket_email_inbound.php — see
// config.sample.php for the one-time DNS/dashboard setup.
function ticket_reply_address(int $ticketId): string {
    $domain = (cfg()['ticket_reply_domain'] ?? '') ?: 'reply.innovateonline.com';
    return "reply+{$ticketId}-" . ticket_reply_token($ticketId) . "@{$domain}";
}

// Full message history for a ticket (original body + every reply), oldest
// first, formatted for plain-text email so recipients see the whole
// conversation instead of just the newest message.
function build_ticket_thread_text(PDO $db, int $ticketId): string {
    $t = $db->prepare("SELECT agent_name FROM support_tickets WHERE id=?");
    $t->execute([$ticketId]);
    $agentName = $t->fetchColumn() ?: '';

    $rows = $db->prepare(
        "SELECT author, is_staff, body, created_at FROM support_ticket_messages
         WHERE ticket_id = ? ORDER BY id ASC"
    );
    $rows->execute([$ticketId]);
    $lines = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $when = fmt_dt_et($m['created_at'], 'M j, Y g:i A');
        $who  = $m['is_staff'] ? 'Support Staff (' . $m['author'] . ')' : ($agentName ?: $m['author']);
        $lines[] = "[{$when}] {$who}:";
        $lines[] = $m['body'];
        $lines[] = '';
    }
    return rtrim(implode("\n", $lines));
}

// A new ticket was created — notify all super admins.
function notify_ticket_created(int $ticketId, string $title, string $body, string $deptSlug, string $deptName, string $agentName, string $agentEmail): int {
    $emails  = ticket_notify_admin_emails();
    $subject = "New Support Ticket #{$ticketId}: {$title}";
    $msg     = implode("\n", [
        "A new support ticket was submitted in AgentEdge.",
        "",
        "Department:  " . ($deptName ?: '—'),
        "From:        {$agentName} <{$agentEmail}>",
        "",
        $body,
        "",
        "Reply to this email to respond, or view it online:",
        "https://agents.innovateonline.com/backoffice_tickets.php?id={$ticketId}",
        "",
        "— AgentEdge",
    ]);
    return queue_email_to($emails, $subject, $msg, $agentEmail, $agentName, ticket_reply_address($ticketId));
}

// A reply was posted — notify the other side of the conversation (the agent
// when staff replies, or all super admins when the agent replies) plus
// anyone CC'd on the ticket. $fromEmail/$fromName are the actual replier
// (staff member or agent), not necessarily $agentEmail (the ticket owner).
// Includes the full ticket thread so recipients don't have to log in to see
// prior notes.
function notify_ticket_reply(int $ticketId, string $title, string $replyBody, bool $isStaffReply, string $deptSlug, string $agentEmail, string $fromEmail = '', string $fromName = ''): int {
    $recipients = $isStaffReply ? [$agentEmail] : ticket_notify_admin_emails();
    $recipients = array_merge($recipients, support_ticket_cc_emails($ticketId));

    $subject = "Re: Support Ticket #{$ticketId}: {$title}";
    $who     = $isStaffReply ? 'Support staff replied' : 'The agent replied';
    $thread  = build_ticket_thread_text(local_db(), $ticketId);
    $msg     = implode("\n", [
        "{$who} on ticket #{$ticketId}: {$title}",
        "",
        "──────────────────────────────",
        "FULL TICKET THREAD (oldest to newest)",
        "──────────────────────────────",
        "",
        $thread,
        "",
        "──────────────────────────────",
        "",
        "Reply to this email to respond, or view the full thread online:",
        "https://agents.innovateonline.com/" . ($isStaffReply ? 'tickets.php' : "backoffice_tickets.php?id={$ticketId}"),
        "",
        "— AgentEdge",
    ]);
    return queue_email_to($recipients, $subject, $msg, $fromEmail, $fromName, ticket_reply_address($ticketId));
}

// A staff member was CC'd on a ticket.
function notify_ticket_cc_added(int $ticketId, string $title, string $ccEmail, string $fromEmail = '', string $fromName = ''): void {
    $subject = "You were CC'd on Support Ticket #{$ticketId}: {$title}";
    $msg     = implode("\n", [
        "You've been added as a CC on a support ticket in AgentEdge.",
        "",
        "Reply to this email to respond, or view the ticket thread online:",
        "https://agents.innovateonline.com/backoffice_tickets.php?id={$ticketId}",
        "",
        "— AgentEdge",
    ]);
    queue_email_to([$ccEmail], $subject, $msg, $fromEmail, $fromName, ticket_reply_address($ticketId));
}

// Records a reply on a ticket — shared by the logged-in reply action
// (api/ticket_action.php) and the inbound-email handler
// (api/ticket_email_inbound.php) so both paths apply the same status
// transition, event log, and notification. Returns the new message id.
function record_ticket_reply(PDO $db, array $tkt, string $authorEmail, bool $isStaff, string $body, string $authorName = ''): int {
    $ticketId = (int)$tkt['id'];
    $db->prepare("INSERT INTO support_ticket_messages (ticket_id,author,is_staff,body) VALUES (?,?,?,?)")
       ->execute([$ticketId, $authorEmail, $isStaff ? 1 : 0, $body]);
    $messageId = (int)$db->lastInsertId();

    // Staff replying moves the ticket to "answered" (agent's turn); the agent
    // replying moves it back to "open" (needs staff attention) — unless the
    // ticket is on hold or closed, which only an explicit status change lifts.
    $newStatus = $tkt['status'];
    if (!in_array($tkt['status'], ['on_hold', 'closed'], true)) {
        $newStatus = $isStaff ? 'answered' : 'open';
    }
    $db->prepare("UPDATE support_tickets SET status=?,updated_at=datetime('now') WHERE id=?")->execute([$newStatus, $ticketId]);
    if ($newStatus !== $tkt['status']) {
        $db->prepare("INSERT INTO support_ticket_events (ticket_id,event_type,detail,actor_email) VALUES (?,?,?,?)")
           ->execute([$ticketId, 'status_change', "{$tkt['status']} -> {$newStatus}", $authorEmail]);
    }

    notify_ticket_reply($ticketId, $tkt['title'], $body, $isStaff, $tkt['dept_slug'] ?? '', $tkt['agent_email'], $authorEmail, $authorName);
    return $messageId;
}

// Is this email a staff/admin account (vs. a plain agent)? Session-free —
// used by the inbound-email handler, which has no logged-in user.
function email_is_staff(string $email): bool {
    $s = local_db()->prepare("SELECT role FROM agent_roles WHERE LOWER(email) = LOWER(?)");
    $s->execute([$email]);
    return in_array($s->fetchColumn(), ['super_admin', 'staff'], true);
}

// Strips quoted history from an inbound email reply so we store just the new
// text, not the whole thread the mail client quoted back at us. Cuts at the
// first line matching common client boilerplate ("On ... wrote:", Outlook's
// "-----Original Message-----", or a run of "> " quoted lines).
function strip_email_quote(string $text): string {
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $cut   = count($lines);
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*On .+ wrote:\s*$/i', $line)
            || preg_match('/^-{2,}\s*Original Message\s*-{2,}/i', $line)
            || preg_match('/^\s*>/', $line)) {
            $cut = $i;
            break;
        }
    }
    return trim(implode("\n", array_slice($lines, 0, $cut)));
}

// ── Suggestion notifications ─────────────────────────────────────────────────

// A new suggestion was submitted — notify all super admins.
function notify_suggestion_created(int $suggestionId, string $title, string $body, string $category, string $submitterName, string $submitterEmail): int {
    $subject = "New Suggestion: {$title}";
    $msg     = implode("\n", [
        "A new suggestion was submitted in AgentEdge.",
        "",
        "Category:  " . ($category ?: '—'),
        "From:      {$submitterName} <{$submitterEmail}>",
        "",
        $body,
        "",
        "View it:",
        "https://agentedge.innovateonline.com/suggestions.php",
        "",
        "— AgentEdge",
    ]);
    return queue_email_to(super_admin_emails(), $subject, $msg, $submitterEmail, $submitterName);
}

// ── SendGrid email ────────────────────────────────────────────────────────────

function send_email_sendgrid(string $to, string $subject, string $body, array $c, bool $isHtml = false, array $attachments = [], string $fromEmail = '', string $fromName = '', string $replyTo = ''): bool {
    $key  = $c['sendgrid_key']  ?? '';
    // A specific AgentEdge user triggered this email (e.g. replied to a
    // ticket, sent a Company Email) — send from them; otherwise fall back
    // to the system default. Only innovateonline.com is domain-authenticated
    // with SendGrid, so a fromEmail on any other domain (e.g. a ticket
    // submitted by an agent using their own brokerage's email address) gets
    // rejected outright if used as the literal From — fall back to the
    // system sender in that case but keep the actor's name for attribution.
    $actorDomainOk = $fromEmail !== '' && preg_match('/@innovateonline\.com$/i', $fromEmail);
    $from = $actorDomainOk ? $fromEmail : ($c['sendgrid_from'] ?? 'noreply@innovateonline.com');
    $name = $fromName !== '' ? $fromName : ($actorDomainOk ? $fromEmail : ($c['sendgrid_name'] ?? 'INNOVATE Real Estate'));
    if (!$key || !$to) return false;

    if ($isHtml) {
        // $body is already-formatted HTML (e.g. from a rich-text editor) — derive
        // a plain-text fallback rather than nl2br/escaping it, which would show
        // literal tags in plain-text mail clients.
        $plainSrc  = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6])\s*\/?>/i', "\n", $body);
        $plainText = trim(html_entity_decode(strip_tags($plainSrc), ENT_QUOTES));
        $htmlBody  = $body;
    } else {
        $plainText = $body;
        $htmlBody  = nl2br(htmlspecialchars($body, ENT_QUOTES));
    }

    $payloadArr = [
        'personalizations' => [['to' => [['email' => $to]]]],
        'from'    => ['email' => $from, 'name' => $name],
        'subject' => $subject,
        'content' => [
            ['type' => 'text/plain', 'value' => $plainText],
            ['type' => 'text/html',  'value' => $htmlBody],
        ],
    ];
    if ($attachments) $payloadArr['attachments'] = $attachments;
    if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $payloadArr['reply_to'] = ['email' => $replyTo];
    $payload = json_encode($payloadArr);

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
