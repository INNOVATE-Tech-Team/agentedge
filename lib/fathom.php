<?php
// Fathom.video API — auto-ingests recorded training calls into University.
// Auth: X-Api-Key header (personal API key from Fathom → Settings → API Access).
// Base URL: https://api.fathom.ai/external/v1
// Webhook: registered in Fathom against api/fathom_webhook.php, event
// "new-meeting-content-ready" — verified below (Svix-style signing, see
// fathom_verify_webhook()). Config keys: 'fathom_api_key', 'fathom_webhook_secret'
// (config.php / config.sample.php). Docs: https://developers.fathom.ai
//
// NOTE: fathom_request_recording_download() / fathom_get_download_status()
// below are the least confidently documented part of Fathom's public API as
// of this writing — the docs describe the download flow ("request a
// download" → poll a download_id → get a short-lived signed URL) but didn't
// expose the literal path/verb strings. Verify these two against
// https://developers.fathom.ai (or the response Fathom actually returns)
// once a real API key is live, and adjust the paths/field names here if
// needed — that's the first thing to check if ingestion stalls in the
// 'downloading' state. Everything else (meeting fetch, webhook signing)
// matches Fathom's published quickstart examples directly.

require_once __DIR__ . '/../local_db.php';

function fathom_request(string $method, string $path, ?array $query = null): array {
    $c      = cfg();
    $apiKey = $c['fathom_api_key'] ?? '';
    if ($apiKey === '') return ['ok'=>false,'code'=>0,'data'=>null];

    $url = "https://api.fathom.ai/external/v1{$path}";
    if ($query) $url .= (strpos($path, '?') !== false ? '&' : '?') . http_build_query($query);

    $opts = [
        'method'        => $method,
        'timeout'       => 30,
        'header'        => "X-Api-Key: {$apiKey}\r\nAccept: application/json\r\n",
        'ignore_errors' => true,
    ];
    $ctx  = stream_context_create(['http' => $opts]);
    $raw  = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (!empty($http_response_header[0])) {
        preg_match('#\s(\d{3})\s#', $http_response_header[0], $m) && ($code = (int)$m[1]);
    }
    $d = $raw ? json_decode($raw, true) : null;
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'data' => $d];
}

// Fetches a single meeting with its transcript inlined.
function fathom_get_meeting(string $meetingId): array {
    return fathom_request('GET', "/meetings/{$meetingId}", ['include_transcript' => 'true']);
}

// Kicks off async generation of a downloadable video file for a recording.
// Expected response includes a 'download_id' to poll — see note above.
function fathom_request_recording_download(string $recordingId): array {
    return fathom_request('POST', "/recordings/{$recordingId}/download");
}

// Polls the status of a previously requested download. When status is
// 'completed', the response is expected to include a short-lived signed URL
// for the generated file — see note above.
function fathom_get_download_status(string $downloadId): array {
    return fathom_request('GET', "/recordings/download/{$downloadId}");
}

// Verifies an inbound Fathom webhook — Svix-style signing: HMAC-SHA256 of
// "{webhook-id}.{webhook-timestamp}.{raw body}", keyed by the base64 portion
// of the webhook secret (prefixed 'whsec_'), base64-encoded, and compared
// against each space-separated, version-prefixed signature in
// webhook-signature. $headers keys are expected lowercased.
function fathom_verify_webhook(string $rawBody, array $headers): bool {
    $c      = cfg();
    $secret = $c['fathom_webhook_secret'] ?? '';
    $id     = $headers['webhook-id'] ?? '';
    $ts     = $headers['webhook-timestamp'] ?? '';
    $sigHdr = $headers['webhook-signature'] ?? '';
    if ($secret === '' || $id === '' || $ts === '' || $sigHdr === '') return false;
    if (abs(time() - (int)$ts) > 300) return false; // 5-minute tolerance

    $secretB64 = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
    $key       = base64_decode($secretB64);
    $expected  = base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$rawBody}", $key, true));

    foreach (explode(' ', $sigHdr) as $sig) {
        $sig = strpos($sig, ',') !== false ? substr($sig, strpos($sig, ',') + 1) : $sig;
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}

// All Fathom-ingested lessons land in this one always-published holding
// course. University has no "move lesson to another course" UI today, so
// rather than build one, ingestion always targets this course and
// pending_review gates per-lesson visibility until an admin reviews and
// publishes it — see the "known v1 limitation" note in the project plan.
function fathom_get_or_create_holding_course(): int {
    $db = local_db();
    $id = $db->query("SELECT id FROM uni_courses WHERE title='Recorded Training Calls'")->fetchColumn();
    if ($id) return (int)$id;
    $db->prepare("INSERT INTO uni_courses (title,description,published,created_by) VALUES (?,?,1,?)")
       ->execute(['Recorded Training Calls', 'Training calls recorded via Fathom, added automatically.', 'fathom-webhook']);
    return (int)$db->lastInsertId();
}
