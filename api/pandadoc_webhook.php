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

        // Fetch + store the signed PDF via the shared idempotent helper
        // (retries if PandaDoc's PDF isn't ready yet; logs every failure to
        // the PHP error log). The signing step above stays "done" regardless —
        // that means "the agent signed". If storage fails here, the repair
        // cron (cron/pandadoc_repair_documents.php) retries it. $documentAdded
        // is true only when THIS request actually stored the PDF, so the
        // Whitney/Michele "added to profile" ping and the password-setup email
        // below fire exactly once per document (same rule in the repair cron).
        $documentAdded = false;
        if ($agentEmail !== '') {
            $res = pandadoc_store_completed_document($pdo, $docId, $agentEmail, 'Onboarding Agreement', 'pandadoc-webhook');
            $documentAdded = ($res['status'] === 'stored');
        } else {
            pandadoc_log('STORE FAILED: no agent email on onboard_queue row', ['doc'=>$docId,'queue_id'=>$queueId]);
        }

        try {
            require_once __DIR__ . '/../lib/notifications.php';
            maybe_notify_next_actionable_step($pdo, 'onboard', (int)$queueId);
            $qr = $pdo->prepare("SELECT agent_name, market_center FROM onboard_queue WHERE id=?");
            $qr->execute([$queueId]);
            $qr = $qr->fetch(PDO::FETCH_ASSOC);
            if ($documentAdded) {
                // Shared with the repair cron (see notify_signed_document_stored()).
                notify_signed_document_stored($pdo, $qr['agent_name'] ?? '', $agentEmail, 'Onboarding Agreement');
            }
            if ($qr && trim($qr['market_center'] ?? '') !== '') {
                notify_bic_ml_docs_signed($qr['agent_name'], $agentEmail, $qr['market_center']);
            }
            dispatch_notification_queue();
        } catch (\Throwable $e) {
            pandadoc_log('post-sign notifications failed', ['doc'=>$docId,'error'=>$e->getMessage()]);
        }
    }
}

json_out(['ok'=>true]);
