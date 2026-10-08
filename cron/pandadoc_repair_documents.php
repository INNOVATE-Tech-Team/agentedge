<?php
/**
 * PandaDoc signed-document repair — finds onboarding agreements that were
 * signed in PandaDoc but never attached to the agent's profile
 * (agent_documents) and attaches them. Safe to run repeatedly: storage goes
 * through pandadoc_store_completed_document(), which is idempotent per PandaDoc
 * document id (source='pandadoc' + external_ref) -- the ONLY duplicate check.
 * A different document id for the same agent (e.g. a re-sent agreement) is
 * stored as its own document, and a manually attached copy never blocks it.
 * On a true first-time store it sends the same two follow-ups as the webhook
 * (Whitney/Michele notice + AgentEdge setup link, via
 * notify_signed_document_stored()); it does NOT send the BIC/Market Center
 * email or the next-step notification.
 *
 * Installed in the box's crontab (every 15 minutes):
 *   every 15 minutes: docker exec agentedge php /var/www/html/cron/pandadoc_repair_documents.php >> /home/ec2-user/cron-pandadoc-repair.log 2>&1
 *
 *   php cron/pandadoc_repair_documents.php --dry-run   # list what it WOULD repair, change nothing
 *
 * Candidates: onboard_steps 'doc_signing' rows with a PandaDoc document id and
 * no agent_documents row for that id, from the last 90 days.
 *   - step 'done'  -> the webhook already saw the signature; just fetch the PDF.
 *   - step 'sent'  -> ask PandaDoc; if the document is completed (the webhook
 *                     may never have arrived), fetch the PDF. The onboarding
 *                     step itself is NOT changed here — only the document.
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }
define('AGENTEDGE_CRON', true);
chdir(dirname(__DIR__));
require_once 'db.php';
require_once 'local_db.php';
require_once 'lib/pandadoc.php';
require_once 'lib/notifications.php';

const PD_REPAIR_MAX_PER_RUN = 10;
const PD_REPAIR_WINDOW_DAYS = 90;

$dry = in_array('--dry-run', $argv ?? [], true);
$pdo = local_db();

$rows = $pdo->query(
    "SELECT s.pandadoc_document_id AS doc_id, s.status AS step_status, s.queue_id, q.agent_email, q.agent_name
       FROM onboard_steps s
       JOIN onboard_queue q ON q.id = s.queue_id
      WHERE s.tool_key = 'doc_signing'
        AND COALESCE(s.pandadoc_document_id, '') != ''
        AND s.status IN ('done', 'sent')
        AND q.added_at >= datetime('now', '-" . PD_REPAIR_WINDOW_DAYS . " days')
        AND NOT EXISTS (SELECT 1 FROM agent_documents d WHERE d.source = 'pandadoc' AND d.external_ref = s.pandadoc_document_id)
      ORDER BY q.added_at"
)->fetchAll(PDO::FETCH_ASSOC);

$stored = $failed = $skipped = $pending = 0;

foreach ($rows as $r) {
    if ($stored + $failed >= PD_REPAIR_MAX_PER_RUN) break;
    $doc = $r['doc_id']; $email = strtolower(trim($r['agent_email']));
    $tag = "{$r['agent_name']} <{$email}> doc={$doc} step={$r['step_status']}";

    if ($r['step_status'] === 'sent') {
        $st = pandadoc_request('GET', "/public/v1/documents/{$doc}");
        if (!$st['ok']) { $failed++; pandadoc_log('repair: status check failed', ['doc'=>$doc,'http'=>$st['code']]); echo gmdate('c') . " FAIL status check HTTP {$st['code']}: {$tag}\n"; continue; }
        if (($st['data']['status'] ?? '') !== 'document.completed') { $pending++; continue; }  // not signed yet — normal
    }

    if ($dry) { echo gmdate('c') . " would repair: {$tag}\n"; continue; }
    $res = pandadoc_store_completed_document($pdo, $doc, $email, 'Onboarding Agreement', 'pandadoc-repair');
    if (!$res['ok']) { $failed++; echo gmdate('c') . " FAIL {$res['error']}: {$tag}\n"; continue; }
    if ($res['status'] !== 'stored') { $skipped++; echo gmdate('c') . " {$res['status']}: {$tag}\n"; continue; }  // stored meanwhile (webhook/another run)
    $stored++; echo gmdate('c') . " stored: {$tag}\n";
    // First-time store only (never 'already_stored'), so a later run can't resend these.
    try { notify_signed_document_stored($pdo, (string)$r['agent_name'], $email, 'Onboarding Agreement'); }
    catch (\Throwable $e) { pandadoc_log('repair: follow-up notices failed', ['doc'=>$doc,'error'=>$e->getMessage()]); echo gmdate('c') . " WARN document stored but follow-up notices failed: {$tag}\n"; }
}
echo gmdate('c') . " pandadoc-repair done: candidates=" . count($rows) . " stored={$stored} failed={$failed} skipped={$skipped} awaiting_signature={$pending}" . ($dry ? ' (dry-run)' : '') . "\n";
exit($failed > 0 ? 1 : 0);
