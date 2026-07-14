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
        "SELECT id, recipient, channel, subject, body, phone, is_html
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
                $ok = send_email_sendgrid($item['recipient'], $item['subject'], $item['body'], $c, (bool)($item['is_html'] ?? false));
            } elseif ($item['channel'] === 'sms') {
                $ok = send_sms_twilio($item['phone'], $item['body'], $c);
            }
            ($ok ? $markSent : $markFailed)->execute([$item['id']]);
        } catch (\Throwable $e) {
            $markFailed->execute([$item['id']]);
        }
    }
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
    string $addedBy
): void {
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
        "INSERT INTO notification_queue (recipient, channel, subject, body, phone) VALUES (?,?,?,?,?)"
    );

    $ins->execute([$addedBy, 'email', $subject, $body, '']);

    $ccEmails = $c['onboard_notify_emails'] ?? [];
    if (is_string($ccEmails)) {
        $ccEmails = array_filter(array_map('trim', explode(',', $ccEmails)));
    }
    foreach ((array)$ccEmails as $cc) {
        if ($cc && $cc !== $addedBy && filter_var($cc, FILTER_VALIDATE_EMAIL)) {
            $ins->execute([$cc, 'email', $subject, $body, '']);
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
    string $addedBy
): void {
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
        "INSERT INTO notification_queue (recipient, channel, subject, body, phone) VALUES (?,?,?,?,?)"
    );

    $ins->execute([$addedBy, 'email', $subject, $body, '']);

    $ccEmails = $c['onboard_notify_emails'] ?? [];
    if (is_string($ccEmails)) {
        $ccEmails = array_filter(array_map('trim', explode(',', $ccEmails)));
    }
    foreach ((array)$ccEmails as $cc) {
        if ($cc && $cc !== $addedBy && filter_var($cc, FILTER_VALIDATE_EMAIL)) {
            $ins->execute([$cc, 'email', $subject, $body, '']);
        }
    }
}

// Marks a single offboarding step done and notifies the next actionable
// step's assignees. Shared by the admin mark_done action and the exit
// interview self-service submit path, so both go through the same
// update+notify pair.
function complete_offboard_step(PDO $pdo, int $queueId, string $toolKey, string $doneBy): void {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare(
        "UPDATE offboard_steps SET status='done', done_by=?, done_at=? WHERE queue_id=? AND tool_key=?"
    )->execute([$doneBy, $now, $queueId, $toolKey]);
    maybe_notify_next_actionable_step($pdo, 'offboard', $queueId);
}

// Queue a short confirmation email to the agent when their onboarding is
// marked complete (api/onboard_action.php's complete_onboarding action).
function notify_onboard_completed(string $agentName, string $agentEmail): void {
    $subject = "Your onboarding is complete — welcome aboard!";
    $body    = implode("\n", [
        "Hi {$agentName},",
        "",
        "Your onboarding is complete. Welcome aboard!",
        "",
        "— AgentEdge",
    ]);

    $db  = local_db();
    $db->prepare(
        "INSERT INTO notification_queue (recipient, channel, subject, body, phone) VALUES (?,?,?,?,?)"
    )->execute([$agentEmail, 'email', $subject, $body, '']);
}

// Queue an email to the Director of Coaching + Launch Facilitator(s) to assign
// a Launch Coach and LAUNCH class. Only called for new agents (no prior
// brokerage affiliation on their intake form) — experienced transfers skip this.
function notify_coach_assignment_needed(string $agentName, string $agentEmail): void {
    $db   = local_db();
    $st   = $db->prepare("SELECT email FROM agent_roles WHERE role IN ('director_of_coaching','launch_facilitator')");
    $st->execute();
    $emails = array_values(array_unique(array_filter(array_map('trim', $st->fetchAll(PDO::FETCH_COLUMN)))));
    if (!$emails) return;

    $subject = "New Agent — Assign Launch Coach & LAUNCH Class: {$agentName}";
    $body    = implode("\n", [
        "{$agentName} ({$agentEmail}) is a new agent who just completed onboarding.",
        "",
        "Please assign a Launch Coach and enroll them in the next LAUNCH class.",
        "",
        "— AgentEdge",
    ]);

    $ins = $db->prepare(
        "INSERT INTO notification_queue (recipient, channel, subject, body, phone) VALUES (?,?,?,?,?)"
    );
    foreach ($emails as $email) {
        $ins->execute([$email, 'email', $subject, $body, '']);
    }
}

// Queue a completion email to the agent's BIC and Market Center Leader,
// looked up from market_centers by matching the agent's market center name.
// A non-matching/blank market center is a no-op, not an error — shouldn't
// block onboarding completion over a mismatched free-text field.
function notify_bic_ml_onboard_complete(string $agentName, string $agentEmail, string $marketCenter): void {
    $marketCenter = trim($marketCenter);
    if ($marketCenter === '') return;

    $db = local_db();
    $st = $db->prepare("SELECT bic_email, mc_leader_email FROM market_centers WHERE LOWER(name) = LOWER(?)");
    $st->execute([$marketCenter]);
    $mc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$mc) return;

    $emails = array_values(array_unique(array_filter([trim($mc['bic_email'] ?? ''), trim($mc['mc_leader_email'] ?? '')])));
    if (!$emails) return;

    $subject = "Onboarding Complete: {$agentName}";
    $body    = implode("\n", [
        "{$agentName} ({$agentEmail}) has completed onboarding at {$marketCenter}.",
        "",
        "— AgentEdge",
    ]);

    $ins = $db->prepare(
        "INSERT INTO notification_queue (recipient, channel, subject, body, phone) VALUES (?,?,?,?,?)"
    );
    foreach ($emails as $email) {
        $ins->execute([$email, 'email', $subject, $body, '']);
    }
}

// Queue an email to the departing agent with a link to fill out their exit
// interview. Sent when an admin clicks "Send Exit Interview" — the agent's
// AgentEdge login is still active at this point (account inactivation is a
// later offboarding step), so this is a plain login link, not a public/token link.
function notify_exit_interview_sent(string $agentName, string $agentEmail): void {
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
        "INSERT INTO notification_queue (recipient, channel, subject, body, phone) VALUES (?,?,?,?,?)"
    )->execute([$agentEmail, 'email', $subject, $body, '']);
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
function notify_step_assignees_on_create(string $process, string $agentName, string $agentEmail, array $steps): void {
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
        queue_email_to([$email], $subject, $body);
    }
}

// "It's your turn" email sent when a step becomes the next actionable one.
function notify_step_actionable(string $process, string $stepKey, string $stepLabel, string $agentName, string $agentEmail): void {
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
    queue_email_to($emails, $subject, $body);
}

// Finds the earliest pending step (in tool order) that hasn't been notified
// yet and, if found, emails its assignees and marks it notified. Call this
// right after any step transitions to done/skipped so the next step's
// owners find out as soon as it's their turn. Safe to call unconditionally —
// no-ops if nothing changed.
function maybe_notify_next_actionable_step(PDO $pdo, string $process, int $queueId): void {
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

    notify_step_actionable($process, $step['tool_key'], $step['tool_label'], $entry['agent_name'], $entry['agent_email']);
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
    queue_email_to(['whitney@innovateonline.com'], $subject, $body);
}

// ── Support ticket notifications ─────────────────────────────────────────────

// Email addresses of every super_admin. Ticket/suggestion notifications go
// only to super admins, regardless of department staff routing.
function super_admin_emails(): array {
    $emails = local_db()->query("SELECT email FROM agent_roles WHERE role = 'super_admin'")->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_unique(array_filter(array_map('strtolower', array_map('trim', $emails)))));
}

// CC'd staff emails for a ticket.
function support_ticket_cc_emails(int $ticketId): array {
    $s = local_db()->prepare("SELECT email FROM support_ticket_cc WHERE ticket_id=?");
    $s->execute([$ticketId]);
    return $s->fetchAll(PDO::FETCH_COLUMN);
}

// Queue a plain-text email to a list of recipients (deduped, empty entries dropped).
function queue_email_to(array $emails, string $subject, string $body): int {
    $ins = local_db()->prepare(
        "INSERT INTO notification_queue (recipient, channel, subject, body, phone) VALUES (?, 'email', ?, ?, '')"
    );
    $sent = 0;
    foreach (array_unique(array_filter(array_map('trim', $emails))) as $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $ins->execute([$email, $subject, $body]);
        $sent++;
    }
    return $sent;
}

// A new ticket was created — notify all super admins.
function notify_ticket_created(int $ticketId, string $title, string $body, string $deptSlug, string $deptName, string $agentName, string $agentEmail): int {
    $emails  = super_admin_emails();
    $subject = "New Support Ticket #{$ticketId}: {$title}";
    $msg     = implode("\n", [
        "A new support ticket was submitted in AgentEdge.",
        "",
        "Department:  " . ($deptName ?: '—'),
        "From:        {$agentName} <{$agentEmail}>",
        "",
        $body,
        "",
        "Respond in the ticket thread:",
        "https://agentedge.innovateonline.com/backoffice_tickets.php?id={$ticketId}",
        "",
        "— AgentEdge",
    ]);
    return queue_email_to($emails, $subject, $msg);
}

// A reply was posted — notify the other side of the conversation (the agent
// when staff replies, or all super admins when the agent replies) plus
// anyone CC'd on the ticket.
function notify_ticket_reply(int $ticketId, string $title, string $replyBody, bool $isStaffReply, string $deptSlug, string $agentEmail): int {
    $recipients = $isStaffReply ? [$agentEmail] : super_admin_emails();
    $recipients = array_merge($recipients, support_ticket_cc_emails($ticketId));

    $subject = "Re: Support Ticket #{$ticketId}: {$title}";
    $who     = $isStaffReply ? 'Support staff replied' : 'The agent replied';
    $msg     = implode("\n", [
        "{$who} on ticket #{$ticketId}.",
        "",
        $replyBody,
        "",
        "View the full thread:",
        "https://agentedge.innovateonline.com/" . ($isStaffReply ? 'tickets.php' : "backoffice_tickets.php?id={$ticketId}"),
        "",
        "— AgentEdge",
    ]);
    return queue_email_to($recipients, $subject, $msg);
}

// A staff member was CC'd on a ticket.
function notify_ticket_cc_added(int $ticketId, string $title, string $ccEmail): void {
    $subject = "You were CC'd on Support Ticket #{$ticketId}: {$title}";
    $msg     = implode("\n", [
        "You've been added as a CC on a support ticket in AgentEdge.",
        "",
        "View the ticket thread:",
        "https://agentedge.innovateonline.com/backoffice_tickets.php?id={$ticketId}",
        "",
        "— AgentEdge",
    ]);
    queue_email_to([$ccEmail], $subject, $msg);
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
    return queue_email_to(super_admin_emails(), $subject, $msg);
}

// ── SendGrid email ────────────────────────────────────────────────────────────

function send_email_sendgrid(string $to, string $subject, string $body, array $c, bool $isHtml = false): bool {
    $key  = $c['sendgrid_key']  ?? '';
    $from = $c['sendgrid_from'] ?? 'noreply@innovateonline.com';
    $name = $c['sendgrid_name'] ?? 'INNOVATE Real Estate';
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

    $payload = json_encode([
        'personalizations' => [['to' => [['email' => $to]]]],
        'from'    => ['email' => $from, 'name' => $name],
        'subject' => $subject,
        'content' => [
            ['type' => 'text/plain', 'value' => $plainText],
            ['type' => 'text/html',  'value' => $htmlBody],
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
