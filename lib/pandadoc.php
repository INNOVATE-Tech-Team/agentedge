<?php
// PandaDoc e-signature API — document creation, sending, and webhook
// verification for the onboarding "Document Signing" step (steps 5-6 of the
// onboarding workflow: send for signature, get notified once signed).
// API key/template configured in config.php as 'pandadoc_api_key' / 'pandadoc_template_id'.
// Webhook shared key (Developer Dashboard > API Dashboard > Configuration) as 'pandadoc_webhook_key'.
// Docs: https://developers.pandadoc.com

function pandadoc_request(string $method, string $path, ?array $body = null): array {
    $c      = cfg();
    $apiKey = $c['pandadoc_api_key'] ?? '';
    if ($apiKey === '') return ['ok'=>false,'code'=>0,'data'=>null];

    $opts = [
        'method'        => $method,
        'timeout'       => 20,
        'header'        => "Authorization: API-Key {$apiKey}\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
        'ignore_errors' => true,
    ];
    if ($body !== null) $opts['content'] = json_encode($body);

    $ctx  = stream_context_create(['http' => $opts]);
    $raw  = @file_get_contents("https://api.pandadoc.com{$path}", false, $ctx);
    $code = 0;
    if (!empty($http_response_header[0])) {
        preg_match('#\s(\d{3})\s#', $http_response_header[0], $m) && ($code = (int)$m[1]);
    }
    $d = $raw ? json_decode($raw, true) : null;
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'data' => $d];
}

// Creates a document from the configured onboarding template and sends it to
// the agent for signature. Pass $existingDocId to resume a document that was
// already created by a prior (failed) attempt instead of creating a duplicate.
function pandadoc_send_document(string $agentName, string $agentEmail, ?string $existingDocId = null): array {
    $c      = cfg();
    $apiKey = $c['pandadoc_api_key'] ?? '';
    if ($apiKey === '') return ['ok'=>false,'error'=>'PandaDoc API key not configured'];

    $templateId = $c['pandadoc_template_id'] ?? '';
    if (!$existingDocId && $templateId === '') {
        return ['ok'=>false,'error'=>'PandaDoc template not configured'];
    }

    $parts = explode(' ', trim($agentName), 2);
    $first = $parts[0];
    $last  = $parts[1] ?? '';

    $docId = $existingDocId;
    $status = '';

    if (!$docId) {
        $create = pandadoc_request('POST', '/public/v1/documents', [
            'name'          => "Onboarding Agreement - {$agentName}",
            'template_uuid' => $templateId,
            'recipients'    => [[
                'email'      => $agentEmail,
                'first_name' => $first,
                'last_name'  => $last,
                'role'       => 'Agent',
            ]],
        ]);
        if (!$create['ok']) {
            $err = $create['data']['message'] ?? $create['data']['error'] ?? "HTTP {$create['code']}";
            return ['ok'=>false,'error'=>"Create failed: {$err}"];
        }
        $docId  = $create['data']['id'] ?? null;
        $status = $create['data']['status'] ?? '';
        if (!$docId) return ['ok'=>false,'error'=>'PandaDoc did not return a document id'];
    }

    // Newly created documents start in 'document.uploaded' while PandaDoc
    // finishes processing the template and must reach 'document.draft'
    // before they can be sent — poll briefly for that transition.
    for ($i = 0; $i < 5 && $status !== 'document.draft'; $i++) {
        if ($i > 0) sleep(2);
        $check  = pandadoc_request('GET', "/public/v1/documents/{$docId}/details");
        $status = $check['data']['status'] ?? $status;
    }
    if ($status !== 'document.draft') {
        return ['ok'=>false,'error'=>"Document still processing (status: {$status}) — try Provision Now again shortly",'document_id'=>$docId];
    }

    $send = pandadoc_request('POST', "/public/v1/documents/{$docId}/send", [
        'message' => "Hi {$first}, please review and sign your onboarding paperwork.",
        'subject' => 'INNOVATE Onboarding — Signature Required',
        'silent'  => false,
    ]);
    if (!$send['ok']) {
        $err = $send['data']['message'] ?? $send['data']['error'] ?? "HTTP {$send['code']}";
        return ['ok'=>false,'error'=>"Send failed: {$err}",'document_id'=>$docId];
    }

    return ['ok'=>true,'document_id'=>$docId];
}

// Verifies an inbound webhook request came from PandaDoc: the signature
// arrives as a ?signature= query param — an HMAC-SHA256 hex digest of the
// raw request body, keyed with the shared key from the Developer Dashboard.
function pandadoc_verify_webhook(string $rawBody, string $signature): bool {
    $c   = cfg();
    $key = $c['pandadoc_webhook_key'] ?? '';
    if ($key === '' || $signature === '') return false;
    $expected = hash_hmac('sha256', $rawBody, $key);
    return hash_equals($expected, $signature);
}
