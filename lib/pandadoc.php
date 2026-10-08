<?php
// PandaDoc e-signature API — document creation, sending, and webhook
// verification for the onboarding "Document Signing" step (steps 5-6 of the
// onboarding workflow: send for signature, get notified once signed).
// API key/default template configured in config.php as 'pandadoc_api_key' /
// 'pandadoc_template_id'. Per-state template overrides live in the
// pandadoc_state_templates DB table (see pandadoc_template_for_state() in
// local_db.php), managed via admin_pandadoc_templates.php.
// Webhook shared key (Developer Dashboard > API Dashboard > Configuration) as 'pandadoc_webhook_key'.
// 'pandadoc_notify_cc' (config.php, array of emails) — staff CC'd on every
// onboarding document so they get PandaDoc's native completion email too.
// Docs: https://developers.pandadoc.com

require_once __DIR__ . '/../local_db.php';

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

// Pulls a human-readable message out of a failed pandadoc_request() result.
// PandaDoc uses at least two different error shapes: a flat {"message"|
// "error"|"info_message": "..."} on some endpoints, and {"type":
// "validation_error", "detail": {"field_name": ["reason", ...]}} on others
// (confirmed 2026-08-14 -- a stale template_uuid came back as the latter,
// and every one of the flat-shape checks missed it, so callers only ever
// saw "Create failed: HTTP 400" instead of "template_uuid: Template is not
// available."). Falls back to the bare HTTP code if neither shape matches.
function pandadoc_error_message(array $result): string {
    $data = $result['data'] ?? null;
    if (is_array($data)) {
        foreach (['message', 'error', 'info_message'] as $key) {
            if (!empty($data[$key])) return (string)$data[$key];
        }
        if (!empty($data['detail'])) {
            if (is_string($data['detail'])) return $data['detail'];
            if (is_array($data['detail'])) {
                $parts = [];
                foreach ($data['detail'] as $field => $reasons) {
                    $reasons = is_array($reasons) ? implode(', ', $reasons) : $reasons;
                    $parts[] = is_string($field) ? "{$field}: {$reasons}" : $reasons;
                }
                if ($parts) return implode('; ', $parts);
            }
        }
    }
    return "HTTP {$result['code']}";
}

// Statuses a document can already be in if a prior attempt got far enough to
// send it before this function (or the request handling it) was interrupted.
const PANDADOC_ALREADY_SENT_STATUSES = [
    'document.sent', 'document.viewed', 'document.waiting_approval',
    'document.approved', 'document.completed', 'document.waiting_pay', 'document.paid',
];

// Creates a document from the onboarding template and sends it to the agent
// for signature. Pass $stateCode to use that state's PandaDoc template (see
// pandadoc_template_for_state()) — falls back to the global
// 'pandadoc_template_id' config value if that state has no template set up
// yet, or if $stateCode is blank/unknown. Pass $existingDocId to resume a
// document that was already created by a prior (failed) attempt instead of
// creating a duplicate. Pass $onCreated to get the new document id as soon
// as it exists — the create/poll/send round trip below can take several
// seconds, and if the caller's request gets interrupted partway (e.g. PHP
// aborts on client disconnect), the document already exists/may already be
// sent on PandaDoc's side, so the id needs to be persisted immediately
// rather than only on full success — otherwise nothing (including the
// completion webhook) can ever be reconciled back to it.
function pandadoc_send_document(string $agentName, string $agentEmail, ?string $existingDocId = null, ?callable $onCreated = null, ?string $stateCode = null): array {
    $c      = cfg();
    $apiKey = $c['pandadoc_api_key'] ?? '';
    if ($apiKey === '') return ['ok'=>false,'error'=>'PandaDoc API key not configured'];

    $templateId = ($stateCode ? pandadoc_template_for_state($stateCode) : null) ?? ($c['pandadoc_template_id'] ?? '');
    if (!$existingDocId && $templateId === '') {
        return ['ok'=>false,'error'=>'PandaDoc template not configured'];
    }

    $parts = explode(' ', trim($agentName), 2);
    $first = $parts[0];
    $last  = $parts[1] ?? '';

    $docId  = $existingDocId;
    $status = '';

    if ($docId) {
        // Resuming a document from a prior attempt — check where it actually
        // is before assuming it still needs to be sent.
        $check  = pandadoc_request('GET', "/public/v1/documents/{$docId}/details");
        $status = $check['data']['status'] ?? '';
        if (in_array($status, PANDADOC_ALREADY_SENT_STATUSES, true)) {
            return ['ok'=>true,'document_id'=>$docId];
        }
    } else {
        // Staff in 'pandadoc_notify_cc' (config.php) are copied as CC recipients
        // so they get PandaDoc's own completion email — PandaDoc only emails the
        // document's creator (this API key's owner) by default, so anyone else
        // who needs to know when an agent signs has to be added here.
        $recipients = [[
            'email'      => $agentEmail,
            'first_name' => $first,
            'last_name'  => $last,
            'role'       => 'Agent',
        ]];
        foreach ($c['pandadoc_notify_cc'] ?? [] as $ccEmail) {
            $ccEmail = trim($ccEmail);
            if ($ccEmail === '') continue;
            $recipients[] = ['email' => $ccEmail, 'recipient_type' => 'CC'];
        }

        $create = pandadoc_request('POST', '/public/v1/documents', [
            'name'          => "Onboarding Agreement - {$agentName}",
            'template_uuid' => $templateId,
            'recipients'    => $recipients,
        ]);
        if (!$create['ok']) {
            return ['ok'=>false,'error'=>"Create failed: " . pandadoc_error_message($create)];
        }
        $docId  = $create['data']['id'] ?? null;
        $status = $create['data']['status'] ?? '';
        if (!$docId) return ['ok'=>false,'error'=>'PandaDoc did not return a document id'];
    }

    if ($onCreated) $onCreated($docId);

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
        return ['ok'=>false,'error'=>"Send failed: " . pandadoc_error_message($send),'document_id'=>$docId];
    }

    return ['ok'=>true,'document_id'=>$docId];
}

// Downloads the final PDF for a completed document. Uses the plain /download
// endpoint (not /download-protected) since that one requires a production
// API key and 401s on a sandbox key — this repo's key may be either.
function pandadoc_download_document(string $docId): array {
    $c      = cfg();
    $apiKey = $c['pandadoc_api_key'] ?? '';
    if ($apiKey === '') return ['ok'=>false,'error'=>'PandaDoc API key not configured'];

    $opts = [
        'method'        => 'GET',
        'timeout'       => 30,
        'header'        => "Authorization: API-Key {$apiKey}\r\nAccept: application/pdf\r\n",
        'ignore_errors' => true,
    ];
    $ctx  = stream_context_create(['http' => $opts]);
    $raw  = @file_get_contents("https://api.pandadoc.com/public/v1/documents/{$docId}/download", false, $ctx);
    $code = 0;
    if (!empty($http_response_header[0])) {
        preg_match('#\s(\d{3})\s#', $http_response_header[0], $m) && ($code = (int)$m[1]);
    }
    if ($code < 200 || $code >= 300 || $raw === false) {
        return ['ok'=>false,'error'=>"Download failed: HTTP {$code}"];
    }
    return ['ok'=>true,'bytes'=>$raw];
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

// ── Signed-document storage (shared by the webhook and the repair cron) ──────

// One diagnosable log line per attempt. Goes to PHP's error log (container
// stderr -> `docker logs agentedge`) so a failed store is never silent.
function pandadoc_log(string $msg, array $ctx = []): void {
    error_log('[pandadoc-docs] ' . gmdate('Y-m-d\TH:i:s\Z') . ' ' . $msg
        . ($ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES) : ''));
}

// Like pandadoc_download_document() but also reports the HTTP code, and
// retries when PandaDoc has fired document.completed before the finished PDF
// is downloadable (404/409/5xx, or a body that isn't a PDF). Returns
// ['ok'=>bool,'bytes'=>?string,'code'=>int,'attempts'=>int,'error'=>?string].
function pandadoc_download_with_retry(string $docId, int $attempts = 3, array $delaysSec = [3, 8]): array {
    $c = cfg();
    $apiKey = $c['pandadoc_api_key'] ?? '';
    if ($apiKey === '') return ['ok'=>false,'bytes'=>null,'code'=>0,'attempts'=>0,'error'=>'PandaDoc API key not configured'];
    $code = 0; $err = null;
    for ($i = 1; $i <= $attempts; $i++) {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET', 'timeout' => 30, 'ignore_errors' => true,
            'header' => "Authorization: API-Key {$apiKey}",
        ]]);
        $http_response_header = null;
        $raw  = @file_get_contents("https://api.pandadoc.com/public/v1/documents/{$docId}/download", false, $ctx);
        $code = 0;
        if (!empty($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) $code = (int)$m[1];
        if ($raw !== false && $code >= 200 && $code < 300 && strncmp($raw, '%PDF', 4) === 0) {
            return ['ok'=>true,'bytes'=>$raw,'code'=>$code,'attempts'=>$i,'error'=>null];
        }
        $err = $raw === false ? 'request failed (no response)'
             : ($code >= 200 && $code < 300 ? 'response was not a PDF' : 'HTTP ' . $code);
        pandadoc_log('download attempt failed', ['doc'=>$docId,'attempt'=>$i,'of'=>$attempts,'http'=>$code,'error'=>$err,
            'body_head'=>substr(preg_replace('/\s+/', ' ', (string)$raw), 0, 120)]);
        if ($i < $attempts) sleep($delaysSec[$i - 1] ?? end($delaysSec));
    }
    return ['ok'=>false,'bytes'=>null,'code'=>$code,'attempts'=>$attempts,'error'=>$err];
}

// Downloads a completed PandaDoc document and attaches it to the agent's
// profile. Idempotent: if a row for this PandaDoc id already exists it does
// nothing, so webhook retries, duplicate events and the repair cron can all
// call it safely. Success is only reported once the PDF is on disk AND the
// agent_documents row is committed. Returns
// ['ok'=>bool,'status'=>'stored'|'already_stored'|'failed','error'=>?string,'doc_row_id'=>?int].
function pandadoc_store_completed_document(PDO $pdo, string $docId, string $agentEmail, string $name = 'Onboarding Agreement', string $by = 'pandadoc-webhook'): array {
    $agentEmail = strtolower(trim($agentEmail));
    $log = ['doc'=>$docId,'agent'=>$agentEmail,'by'=>$by];
    if ($docId === '' || $agentEmail === '') {
        pandadoc_log('store skipped: missing doc id or agent email', $log);
        return ['ok'=>false,'status'=>'failed','error'=>'missing doc id or agent email','doc_row_id'=>null];
    }
    $exists = $pdo->prepare("SELECT id FROM agent_documents WHERE source='pandadoc' AND external_ref=?");
    $exists->execute([$docId]);
    if (($id = $exists->fetchColumn()) !== false) {
        return ['ok'=>true,'status'=>'already_stored','error'=>null,'doc_row_id'=>(int)$id];
    }

    $dl = pandadoc_download_with_retry($docId);
    if (!$dl['ok']) {
        pandadoc_log('STORE FAILED: download', $log + ['http'=>$dl['code'],'attempts'=>$dl['attempts'],'error'=>$dl['error']]);
        return ['ok'=>false,'status'=>'failed','error'=>'download: ' . $dl['error'],'doc_row_id'=>null];
    }

    $cfgDir = cfg()['local_db_dir'] ?? null;
    $dir    = ($cfgDir ?: (__DIR__ . '/../data')) . '/agent_documents';
    $key    = bin2hex(random_bytes(16)) . '.pdf';
    $path   = $dir . '/' . $key;
    try {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new \RuntimeException("cannot create {$dir}");
        if (file_put_contents($path, $dl['bytes']) !== strlen($dl['bytes'])) throw new \RuntimeException("short write to {$path}");

        // Re-check inside a write lock so two concurrent callers can't both insert.
        $pdo->exec('BEGIN IMMEDIATE');
        $exists->execute([$docId]);
        if (($id = $exists->fetchColumn()) !== false) {
            $pdo->exec('COMMIT'); @unlink($path);
            return ['ok'=>true,'status'=>'already_stored','error'=>null,'doc_row_id'=>(int)$id];
        }
        $pdo->prepare(
            "INSERT INTO agent_documents (email, name, source, category, external_ref, mime_type, size_bytes, storage_key, uploaded_by)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute([$agentEmail, $name, 'pandadoc', 'onboarding', $docId, 'application/pdf', strlen($dl['bytes']), $key, $by]);
        $rowId = (int)$pdo->lastInsertId();
        $pdo->exec('COMMIT');
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { try { $pdo->exec('ROLLBACK'); } catch (\Throwable $e2) {} }
        @unlink($path);
        pandadoc_log('STORE FAILED: save', $log + ['error'=>get_class($e) . ': ' . $e->getMessage()]);
        return ['ok'=>false,'status'=>'failed','error'=>'save: ' . $e->getMessage(),'doc_row_id'=>null];
    }
    pandadoc_log('stored', $log + ['row'=>$rowId,'bytes'=>strlen($dl['bytes']),'attempts'=>$dl['attempts']]);
    return ['ok'=>true,'status'=>'stored','error'=>null,'doc_row_id'=>$rowId];
}
