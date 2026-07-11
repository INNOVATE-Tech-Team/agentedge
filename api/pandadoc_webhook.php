<?php
// PandaDoc event webhook — flips the onboarding "Document Signing" step from
// 'sent' to 'done' once an agent finishes signing (step 6 of the onboarding
// workflow: "Onboarding notified Documents Signed").
// Auth: PandaDoc signs each request with an HMAC-SHA256 of the raw body,
// keyed with 'pandadoc_webhook_key' from config.php, sent as ?signature=.
// Register this URL in PandaDoc: Developer Dashboard > API Dashboard > Webhooks,
// subscribed to the "document_state_changed" event.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/pandadoc.php';

header('Content-Type: application/json');

function json_out(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok'=>false,'error'=>'POST required'], 405);
}

$raw = file_get_contents('php://input');
if (!pandadoc_verify_webhook($raw, $_GET['signature'] ?? '')) {
    json_out(['ok'=>false,'error'=>'Invalid signature'], 403);
}

$events = json_decode($raw, true) ?? [];
if (isset($events['event'])) $events = [$events]; // PandaDoc may send a single object or an array

$pdo = local_db();

foreach ($events as $evt) {
    if (($evt['event'] ?? '') !== 'document_state_changed') continue;
    $docId  = $evt['data']['id']     ?? null;
    $status = $evt['data']['status'] ?? '';
    if (!$docId || $status !== 'document.completed') continue;

    $pdo->prepare(
        "UPDATE onboard_steps SET status='done', done_by='pandadoc-webhook', done_at=datetime('now'), error_msg=NULL
         WHERE tool_key='doc_signing' AND pandadoc_document_id=? AND status != 'done'"
    )->execute([$docId]);

    $q = $pdo->prepare("SELECT queue_id FROM onboard_steps WHERE tool_key='doc_signing' AND pandadoc_document_id=?");
    $q->execute([$docId]);
    $queueId = $q->fetchColumn();
    if ($queueId) {
        $qe = $pdo->prepare("SELECT agent_email FROM onboard_queue WHERE id=?");
        $qe->execute([$queueId]);
        $agentEmail = strtolower(trim($qe->fetchColumn() ?: ''));

        // Only fetch+store the PDF once per document, even if this webhook
        // fires more than once for the same completion.
        $already = $pdo->prepare("SELECT COUNT(*) FROM agent_documents WHERE source='pandadoc' AND external_ref=?");
        $already->execute([$docId]);
        if ($agentEmail !== '' && (int)$already->fetchColumn() === 0) {
            try {
                $dl = pandadoc_download_document($docId);
                if ($dl['ok']) {
                    $cfgDir  = function_exists('cfg') ? (cfg()['local_db_dir'] ?? null) : null;
                    $dataDir = $cfgDir ?: (__DIR__ . '/../data');
                    $key     = bin2hex(random_bytes(16)) . '.pdf';
                    file_put_contents($dataDir . '/agent_documents/' . $key, $dl['bytes']);
                    $pdo->prepare(
                        "INSERT INTO agent_documents (email, name, source, external_ref, mime_type, size_bytes, storage_key, uploaded_by)
                         VALUES (?,?,?,?,?,?,?,?)"
                    )->execute([$agentEmail, 'Onboarding Agreement', 'pandadoc', $docId, 'application/pdf', strlen($dl['bytes']), $key, 'pandadoc-webhook']);
                }
            } catch (\Throwable $e) {}
        }

        try {
            require_once __DIR__ . '/../lib/notifications.php';
            maybe_notify_next_actionable_step($pdo, 'onboard', (int)$queueId);
            dispatch_notification_queue();
        } catch (\Throwable $e) {}
    }
}

json_out(['ok'=>true]);
