<?php
// Referral Network — shared helpers. Schema + metro seed live in local_db.php
// (referral_metros/referral_partners/referral_leads/referral_requests/
// referral_request_responses); this file only has lookup/notification helpers
// used by referral_network.php and api/referral_network.php.

require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/notifications.php';

function referral_metro_label(PDO $db, int $metroId): string {
    $st = $db->prepare("SELECT metro_name, state_code FROM referral_metros WHERE id=?");
    $st->execute([$metroId]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    return $m ? $m['metro_name'] . ', ' . $m['state_code'] : '';
}

// Notify a request's owner when another agent responds to it. $sharedPartner,
// when given, is ['name','company','phone','email','specialty'] for the
// contact the responder chose to share.
function notify_referral_request_response(
    string $requesterEmail,
    string $metroLabel,
    string $responderName,
    string $responderEmail,
    string $message,
    ?array $sharedPartner = null
): void {
    $subject = "Someone can help with your referral request in {$metroLabel}";
    $lines = [
        "{$responderName} ({$responderEmail}) responded to your Referral Network request for {$metroLabel}.",
        "",
    ];
    if ($sharedPartner) {
        $lines[] = 'Shared contact:';
        $lines[] = '  Name:      ' . $sharedPartner['name'];
        if ($sharedPartner['company'])   $lines[] = '  Company:   ' . $sharedPartner['company'];
        if ($sharedPartner['phone'])     $lines[] = '  Phone:     ' . $sharedPartner['phone'];
        if ($sharedPartner['email'])     $lines[] = '  Email:     ' . $sharedPartner['email'];
        if ($sharedPartner['specialty']) $lines[] = '  Specialty: ' . $sharedPartner['specialty'];
        $lines[] = '';
    }
    $lines[] = 'Their message:';
    $lines[] = $message !== '' ? $message : '(no message included)';
    $lines[] = '';
    $lines[] = "Reply to them directly at {$responderEmail} to connect.";
    $lines[] = '';
    $lines[] = 'View your requests:';
    $lines[] = 'https://agents.innovateonline.com/referral_network.php?tab=requests';
    $lines[] = '';
    $lines[] = '— AgentEdge';

    queue_email_to([$requesterEmail], $subject, implode("\n", $lines), $responderEmail, $responderName);
}

// Nudge a requester when their cross-posted Facebook post gets a comment.
function notify_referral_fb_comment(string $requesterEmail, string $metroLabel, string $commenterName, string $commentText, string $fbPostId): void {
    $subject = "New Facebook comment on your referral post for {$metroLabel}";
    $body = implode("\n", [
        "{$commenterName} commented on your Referral Network Facebook post for {$metroLabel}:",
        "",
        '"' . $commentText . '"',
        "",
        "View it on Facebook:",
        "https://www.facebook.com/" . $fbPostId,
        "",
        "View your requests:",
        "https://agents.innovateonline.com/referral_network.php?tab=requests",
        "",
        "— AgentEdge",
    ]);
    queue_email_to([$requesterEmail], $subject, $body);
}
