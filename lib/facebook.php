<?php
// Facebook Page posting + comment webhook support for the Referral Network.
// Requires config.php: fb_page_id, fb_page_access_token, fb_app_secret,
// fb_webhook_verify_token — see config.sample.php for where to get each one.

// Posts a text message (optionally with a link) to the configured Page.
// Returns the new post's Facebook ID (format "{page_id}_{post_id}") on
// success, or null on failure/misconfiguration — callers must treat this as
// best-effort and never let a Facebook outage block the referral request
// itself from being created.
function fb_post_to_page(string $message, string $link = ''): ?string {
    $c        = cfg();
    $pageId   = $c['fb_page_id'] ?? '';
    $token    = $c['fb_page_access_token'] ?? '';
    if ($pageId === '' || $token === '') return null;

    $payload = ['message' => $message, 'access_token' => $token];
    if ($link !== '') $payload['link'] = $link;

    $ch = curl_init("https://graph.facebook.com/v19.0/{$pageId}/feed");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300 || !$raw) return null;

    $res = json_decode($raw, true);
    return $res['id'] ?? null;
}

// Verifies the X-Hub-Signature-256 header Facebook signs every webhook
// delivery with (HMAC-SHA256 of the raw body, keyed by the app secret) —
// without this check anyone who finds the callback URL could forge a
// "new comment" event and trigger a nudge to any agent.
function fb_verify_webhook_signature(string $rawBody, string $signatureHeader): bool {
    $c      = cfg();
    $secret = $c['fb_app_secret'] ?? '';
    if ($secret === '' || $signatureHeader === '') return false;
    if (!str_starts_with($signatureHeader, 'sha256=')) return false;
    $expected = hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($expected, substr($signatureHeader, 7));
}
