<?php
// Facebook Page webhook — verification handshake (GET) + comment events (POST).
// Configure in Meta App Dashboard → Webhooks → Page → Subscribe → Callback URL:
//   https://agents.innovateonline.com/api/facebook_webhook.php
// Verify Token must match cfg()['fb_webhook_verify_token']. Subscribe to the
// "feed" field so new-comment events are delivered here.
//
// No login here — Facebook calls this directly, not a signed-in agent.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/referral_network.php';
require_once __DIR__ . '/../lib/facebook.php';

// ── GET: verification handshake ─────────────────────────────────────────────
// Facebook sends hub.mode / hub.verify_token / hub.challenge as query params;
// PHP folds the dots into underscores in $_GET automatically.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode'] ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';
    $expected  = cfg()['fb_webhook_verify_token'] ?? '';
    if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }
    http_response_code(403);
    exit('Verification failed');
}

// ── POST: comment events ────────────────────────────────────────────────────
// Always responds 200 once the signature check passes, even if a given entry
// turns out to be unrelated/malformed — a non-200 makes Facebook retry
// indefinitely and can eventually suspend the subscription. Processing here
// is a handful of indexed queries at most, so there's no need for the
// early-ack-then-keep-working pattern dispatch_notification_queue() uses
// elsewhere — just do the work, then respond once at the end.
$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if (!fb_verify_webhook_signature($raw, $sig)) {
    http_response_code(403);
    exit('Bad signature');
}

try {
    $payload = json_decode($raw, true) ?: [];
    if (($payload['object'] ?? '') !== 'page') { http_response_code(200); exit('ok'); }

    $db = local_db();
    foreach ($payload['entry'] ?? [] as $entry) {
        foreach ($entry['changes'] ?? [] as $change) {
            if (($change['field'] ?? '') !== 'feed') continue;
            $value = $change['value'] ?? [];
            if (($value['item'] ?? '') !== 'comment' || ($value['verb'] ?? '') !== 'add') continue;

            $postId    = $value['post_id']    ?? '';
            $commentId = $value['comment_id'] ?? '';
            $message   = trim($value['message'] ?? '');
            $fromName  = trim($value['from']['name'] ?? 'A Facebook user');
            if ($postId === '' || $commentId === '') continue;

            // Skip comments the Page itself made (e.g. an admin replying) —
            // only agent-facing external comments should nudge the requester.
            $pageId = cfg()['fb_page_id'] ?? '';
            if (($value['from']['id'] ?? '') === $pageId) continue;

            $req = $db->prepare(
                "SELECT r.id, r.agent_email, m.metro_name, m.state_code
                 FROM referral_requests r JOIN referral_metros m ON m.id = r.metro_id
                 WHERE r.fb_post_id = ?"
            );
            $req->execute([$postId]);
            $request = $req->fetch(PDO::FETCH_ASSOC);
            if (!$request) continue;

            // Dedup — Facebook redelivers on anything but a 200, so this
            // exact comment may already be recorded from a prior attempt.
            $dupe = $db->prepare("SELECT 1 FROM referral_request_responses WHERE fb_comment_id = ?");
            $dupe->execute([$commentId]);
            if ($dupe->fetchColumn()) continue;

            $db->prepare(
                "INSERT INTO referral_request_responses (request_id, responder_email, message, source, fb_comment_id, fb_commenter_name)
                 VALUES (?, '', ?, 'facebook', ?, ?)"
            )->execute([$request['id'], $message, $commentId, $fromName]);

            $metroLabel = $request['metro_name'] . ', ' . $request['state_code'];
            notify_referral_fb_comment($request['agent_email'], $metroLabel, $fromName, $message, $postId);
        }
    }
} catch (\Throwable $e) {
    // Fall through to the 200 below regardless — a malformed payload is not
    // something Facebook should keep retrying over.
}
http_response_code(200);
echo 'ok';
