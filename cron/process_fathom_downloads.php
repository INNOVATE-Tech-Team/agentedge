<?php
/**
 * Fathom.video auto-ingestion — async transcript/video pull
 *
 * Installed in the box's crontab (every 2 minutes) — same docker-exec
 * pattern as this repo's other agentedge cron jobs, not a direct host-php
 * call against the bind-mounted path:
 *   *\/2 * * * * docker exec agentedge php /var/www/html/cron/process_fathom_downloads.php >> /home/ec2-user/cron-fathom-downloads.log 2>&1
 *
 * Picks up draft lessons created by api/fathom_webhook.php
 * (fathom_status='pending') and finishes attaching the transcript + video
 * file, then queues a review email to Darren. See lib/fathom.php for the API
 * client and the holding-course helper; see that file's header comment for
 * the one part of the download flow that still needs verifying against
 * Fathom's live API.
 */

define('AGENTEDGE_CRON', true);
chdir(dirname(__DIR__));
require_once 'db.php';
require_once 'local_db.php';
require_once 'lib/fathom.php';
require_once 'lib/notifications.php';

const FATHOM_MAX_ATTEMPTS = 5;

function fathom_mark_attempt_or_fail(PDO $db, array $lesson, string $error): void {
    $attempts = (int)$lesson['fathom_attempts'] + 1;
    if ($attempts >= FATHOM_MAX_ATTEMPTS) {
        $db->prepare("UPDATE uni_lessons SET fathom_status='failed', fathom_attempts=? WHERE id=?")
           ->execute([$attempts, $lesson['id']]);
        queue_email_to(
            ['darren@innovateonline.com'],
            'Fathom ingestion failed: ' . $lesson['title'],
            notification_email_html(
                '<h2 style="margin:0 0 14px;color:#c62828;font-size:20px;font-weight:800">Fathom Ingestion Failed</h2>'
                . '<p style="color:#444;font-size:15px;line-height:1.65;margin:0">"<strong>' . htmlspecialchars($lesson['title'], ENT_QUOTES) . '</strong>" failed after ' . $attempts . ' attempts: ' . htmlspecialchars($error, ENT_QUOTES) . '. It will need to be added to University manually.</p>'
                . sender_signature_html('', '')
            )
        );
    } else {
        $db->prepare("UPDATE uni_lessons SET fathom_attempts=? WHERE id=?")->execute([$attempts, $lesson['id']]);
    }
}

$now = date('Y-m-d H:i:s');
echo "[{$now}] Fathom download sync starting\n";

$db        = local_db();
$uniDir    = __DIR__ . '/../data/uni/';
$reviewUrl = 'https://agents.innovateonline.com/admin_university_course.php?id=' . fathom_get_or_create_holding_course();

// ── Stage 1: pending → fetch transcript, kick off video download ──────────
$pending = $db->query("SELECT * FROM uni_lessons WHERE fathom_status='pending'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($pending as $lesson) {
    try {
        $meeting = fathom_get_meeting($lesson['fathom_meeting_id']);
        if (!$meeting['ok']) throw new \Exception("meeting fetch failed: HTTP {$meeting['code']}");

        $transcript     = $meeting['data']['transcript'] ?? $meeting['data']['transcript_text'] ?? '';
        $transcriptHtml = $transcript !== ''
            ? '<pre style="white-space:pre-wrap;font-family:inherit">' . htmlspecialchars($transcript) . '</pre>'
            : $lesson['content_html'];

        $recordingId = $meeting['data']['recording_id'] ?? $lesson['fathom_meeting_id'];
        $dl          = fathom_request_recording_download($recordingId);
        if (!$dl['ok']) throw new \Exception("download request failed: HTTP {$dl['code']}");
        $downloadId = $dl['data']['download_id'] ?? null;
        if (!$downloadId) throw new \Exception('no download_id in response');

        $db->prepare("UPDATE uni_lessons SET content_html=?, fathom_download_id=?, fathom_status='downloading' WHERE id=?")
           ->execute([$transcriptHtml, $downloadId, $lesson['id']]);
        echo "  lesson #{$lesson['id']}: transcript attached, download requested ({$downloadId})\n";
    } catch (\Throwable $e) {
        fathom_mark_attempt_or_fail($db, $lesson, $e->getMessage());
        echo "  lesson #{$lesson['id']}: ERROR {$e->getMessage()}\n";
    }
}

// ── Stage 2: downloading → poll, attach file when ready ───────────────────
$downloading = $db->query("SELECT * FROM uni_lessons WHERE fathom_status='downloading'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($downloading as $lesson) {
    try {
        $status = fathom_get_download_status($lesson['fathom_download_id']);
        if (!$status['ok']) throw new \Exception("status check failed: HTTP {$status['code']}");
        $state = $status['data']['status'] ?? '';
        if ($state !== 'completed') { echo "  lesson #{$lesson['id']}: still {$state}\n"; continue; }

        $url = $status['data']['url'] ?? $status['data']['download_url'] ?? null;
        if (!$url) throw new \Exception('no signed url in completed response');

        $bytes = @file_get_contents($url);
        if ($bytes === false) throw new \Exception('failed to fetch signed video url');

        $key = bin2hex(random_bytes(16)) . '.mp4';
        file_put_contents($uniDir . $key, $bytes);

        $db->prepare("UPDATE uni_lessons SET file_key=?, fathom_status=NULL WHERE id=?")
           ->execute([$key, $lesson['id']]);

        queue_email_to(
            ['darren@innovateonline.com'],
            'New training video ready for review: ' . $lesson['title'],
            notification_email_html(
                '<h2 style="margin:0 0 14px;color:#1a1a1a;font-size:20px;font-weight:800">New Training Video Ready</h2>'
                . '<p style="color:#444;font-size:15px;line-height:1.65;margin:0 0 14px"><strong>' . htmlspecialchars($lesson['title'], ENT_QUOTES) . '</strong> finished processing and is staged as a draft in University, hidden from agents until you review and publish it.</p>'
                . '<p style="margin:0"><a href="' . htmlspecialchars($reviewUrl, ENT_QUOTES) . '" style="color:#2255cc">Review it in admin →</a></p>'
                . sender_signature_html('', '')
            )
        );
        echo "  lesson #{$lesson['id']}: video attached, review email queued\n";
    } catch (\Throwable $e) {
        fathom_mark_attempt_or_fail($db, $lesson, $e->getMessage());
        echo "  lesson #{$lesson['id']}: ERROR {$e->getMessage()}\n";
    }
}

// Cron is not an HTTP request, so dispatch_notification_queue()'s
// fastcgi_finish_request/flush trick doesn't apply — send queued
// notifications directly before exiting.
process_notification_queue();

$done = date('Y-m-d H:i:s');
echo "[{$done}] Fathom download sync done\n";
