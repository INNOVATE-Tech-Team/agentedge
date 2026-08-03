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

// Notify a request's owner when another agent responds to it.
function notify_referral_request_response(
    string $requesterEmail,
    string $metroLabel,
    string $responderName,
    string $responderEmail,
    string $message
): void {
    $subject = "Someone can help with your referral request in {$metroLabel}";
    $body = implode("\n", [
        "{$responderName} ({$responderEmail}) responded to your Referral Network request for {$metroLabel}.",
        "",
        'Their message:',
        $message !== '' ? $message : '(no message included)',
        "",
        "Reply to them directly at {$responderEmail} to connect.",
        "",
        "View your requests:",
        "https://agents.innovateonline.com/referral_network.php?tab=requests",
        "",
        "— AgentEdge",
    ]);
    queue_email_to([$requesterEmail], $subject, $body, $responderEmail, $responderName);
}
