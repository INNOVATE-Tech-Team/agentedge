<?php
// Shared branded-email helpers for event registration confirmations and
// reminders — used by api/public_rsvp.php and cron/send_event_reminders.php.
// Built on top of notification_email_html() / the notification_queue table
// in lib/notifications.php, not a separate template system.
require_once __DIR__ . '/notifications.php';

// Same cleanup as calendar.js's training_cal.php/events_cal.php/register.php
// feeds — Google auto-fills the description with a Zoom dial-in dump that
// isn't meant for an email; the join link already shows via location.
function event_strip_zoom_boilerplate(string $desc): string {
    if (preg_match('/is inviting you to a scheduled Zoom meeting/i', $desc)) return '';
    return $desc;
}

function event_unwrap_google_redirect(string $text): string {
    return preg_replace_callback('#https?://(?:www\.)?google\.com/url\?q=(\S+)#i', function ($m) {
        $decoded = $m[1];
        for ($i = 0; $i < 3; $i++) {
            $next = urldecode($decoded);
            if ($next === $decoded) break;
            $decoded = $next;
        }
        if (preg_match('#https?://[a-z0-9.-]*zoom\.us/j/\d+(?:\?pwd=[A-Za-z0-9._-]+)?#i', $decoded, $zm)) {
            return $zm[0];
        }
        return preg_match('#^https?://\S+#i', $decoded, $um) ? $um[0] : $m[0];
    }, $text);
}

function event_when_string(string $start, string $end, bool $allDay): string {
    if ($start === '') return '';
    if ($allDay) {
        $ts = strtotime($start);
        return $ts ? date('l, F j, Y', $ts) : $start;
    }
    $sTs = strtotime($start);
    if (!$sTs) return $start;
    $out = date('l, F j, Y', $sTs) . ' at ' . date('g:i A', $sTs) . ' ET';
    $eTs = strtotime($end);
    if ($eTs) $out .= ' - ' . date('g:i A', $eTs) . ' ET';
    return $out;
}

// Pulls together everything an email needs to describe one event: a human
// "when" string, the location/Zoom line, and the description — preferring an
// admin's custom Registration Page Message (see register.php) over the raw
// Calendar description, which is often cluttered with Zoom boilerplate.
function event_display_info(array $event, string $scope, string $event_id): array {
    $is_all_day = isset($event['start']['date']);
    $start_raw  = $event['start']['date'] ?? ($event['start']['dateTime'] ?? '');
    $end_raw    = $event['end']['date']   ?? ($event['end']['dateTime']   ?? '');
    $location   = trim($event['location'] ?? '');

    $capTable = $scope === 'events' ? 'events_calendar' : 'training_events';
    $stmt = local_db()->prepare("SELECT reg_description FROM {$capTable} WHERE event_id=?");
    $stmt->execute([$event_id]);
    $customDesc = trim((string)$stmt->fetchColumn());

    $rawDescription = isset($event['description'])
        ? event_unwrap_google_redirect(event_strip_zoom_boilerplate(strip_tags($event['description'])))
        : '';
    if ($location !== '' && str_ends_with(trim($rawDescription), $location)) {
        $rawDescription = trim(substr($rawDescription, 0, strrpos($rawDescription, $location)));
    }

    return [
        'when'        => event_when_string($start_raw, $end_raw, $is_all_day),
        'location'    => $location,
        'description' => $customDesc !== '' ? $customDesc : $rawDescription,
        'start_ts'    => $start_raw !== '' ? strtotime($start_raw) : null,
    ];
}

// The shared "here's your event" block used inside both the registration
// confirmation and the reminder emails.
function event_details_block_html(string $title, array $info): string {
    $p = 'style="color:#444;font-size:15px;line-height:1.7;margin:0 0 10px"';
    $html = '<h2 style="margin:0 0 14px;color:#1a1a1a;font-size:20px;font-weight:800">' . htmlspecialchars($title, ENT_QUOTES) . '</h2>';
    if ($info['when'] !== '') {
        $html .= '<p style="color:#5b8e0d;font-size:15px;font-weight:700;margin:0 0 10px">&#128197; ' . htmlspecialchars($info['when'], ENT_QUOTES) . '</p>';
    }
    if ($info['location'] !== '') {
        $isUrl = preg_match('#^https?://#i', $info['location']);
        $loc   = $isUrl
            ? '<a href="' . htmlspecialchars($info['location'], ENT_QUOTES) . '" style="color:#2C9CC9">' . htmlspecialchars($info['location'], ENT_QUOTES) . '</a>'
            : htmlspecialchars($info['location'], ENT_QUOTES);
        $html .= '<p ' . $p . '>&#128205; ' . $loc . '</p>';
    }
    if ($info['description'] !== '') {
        $html .= '<p ' . $p . '>' . nl2br(htmlspecialchars($info['description'], ENT_QUOTES)) . '</p>';
    }
    return $html;
}

// Queues a branded HTML email — mirrors the direct notification_queue
// INSERT pattern already used by notify_onboard_completed() etc. in
// notifications.php, since queue_email_to() only supports plain text.
function queue_branded_email(array $emails, string $subject, string $contentHtml, string $fromEmail = '', string $fromName = ''): void {
    $body = notification_email_html($contentHtml);
    $ins  = local_db()->prepare(
        "INSERT INTO notification_queue (recipient, channel, subject, body, phone, is_html, from_email, from_name) VALUES (?,?,?,?,?,1,?,?)"
    );
    foreach (array_unique(array_filter($emails)) as $email) {
        $ins->execute([$email, 'email', $subject, $body, '', $fromEmail, $fromName]);
    }
}
