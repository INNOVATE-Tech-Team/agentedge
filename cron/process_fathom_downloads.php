<?php
/**
 * Fathom.video auto-ingestion — async transcript/video pull
 *
 * Installed in the box's crontab (every 2 minutes):
 *   * every 2 minutes: docker exec agentedge php /var/www/html/cron/process_fathom_downloads.php >> /home/ec2-user/cron-fathom-downloads.log 2>&1
 *
 * Picks up draft lessons created by api/fathom_webhook.php
 * (fathom_status='pending') and attaches the AI summary + video file in one
 * pass, then queues a review email. Verified against Fathom's live API:
 * the recording-download endpoint (POST /recordings/{id}/download) returns
 * the completed video synchronously, with a signed URL — there is no
 * separate polling/status endpoint, despite what earlier comments in this
 * file assumed. See lib/fathom.php for the API client and holding-course
 * helper.
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
            ['abril@innovateonline.com'],
            'Fathom ingestion failed: ' . $lesson['title'],
            notification_email_html(
                '<h2 style="margin:0 0 14px;color:#c62828;font-size:20px;font-weight:800">Fathom Ingestion Failed</h2>'
                . '<p style="color:#444;font-size:15px;line-height:1.65;margin:0">"<strong>' . htmlspecialchars($lesson['title'], ENT_QUOTES) . '</strong>" failed after ' . $attempts . ' attempts: ' . htmlspecialchars($error, ENT_QUOTES) . '. It will need to be added to University manually.</p>'
                . sender_signature_html('', '')
            ),
            '', '', '', true
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

// ── pending → fetch summary, download video, done in one pass ─────────────
$pending = $db->query("SELECT * FROM uni_lessons WHERE fathom_status='pending'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($pending as $lesson) {
    try {
        $sum = fathom_get_summary($lesson['fathom_meeting_id']);
        if (!$sum['ok']) throw new \Exception("summary fetch failed: HTTP {$sum['code']}");
        $md = $sum['data']['summary']['markdown_formatted'] ?? '';
        $summaryHtml = $md !== ''
            ? fathom_markdown_to_html($md)
            : $lesson['content_html'];

        $dl = fathom_request_recording_download($lesson['fathom_meeting_id']);
        if (!$dl['ok']) throw new \Exception("download request failed: HTTP {$dl['code']}");
        $state = $dl['data']['status'] ?? '';
        if ($state !== 'completed') throw new \Exception("unexpected download status: {$state}");

        $file = $dl['data']['video'] ?? $dl['data']['audio'] ?? null;
        $url  = $file['url'] ?? null;
        if (!$url) throw new \Exception('no signed url in download response');

        $key = bin2hex(random_bytes(16)) . '.mp4';
        $ok = @copy($url, $uniDir . $key);
        if (!$ok) throw new \Exception('failed to fetch signed video url');

        // Replaces the 'training-call' / 'Review this recorded training call.'
        // placeholders api/fathom_webhook.php set at ingestion time, now that
        // an actual summary is in hand. Falls back to whatever's already on
        // the lesson (i.e. those placeholders) if the summary doesn't match
        // Fathom's usual template — never blocks the video/summary attachment
        // above on this.
        $meta       = fathom_extract_lesson_metadata($summaryHtml);
        $tags       = $meta['tags'] ?? json_decode($lesson['tags'], true);
        $objective  = $meta['learning_objective'] ?? $lesson['learning_objective'];
        $difficulty = $meta['difficulty'] ?? $lesson['difficulty'];

        $db->prepare("UPDATE uni_lessons SET content_html=?, file_key=?, tags=?, learning_objective=?, difficulty=?, fathom_status=NULL WHERE id=?")
           ->execute([$summaryHtml, $key, json_encode($tags), $objective, $difficulty, $lesson['id']]);

        $blurb = strip_tags($summaryHtml);
        $blurb = trim(preg_replace('/\s+/', ' ', $blurb));
        if (strlen($blurb) > 220) $blurb = substr($blurb, 0, 220) . '…';

        queue_email_to(
            ['abril@innovateonline.com'],
            'New training video ready for review: ' . $lesson['title'],
            notification_email_html(
                '<h2 style="margin:0 0 14px;color:#1a1a1a;font-size:20px;font-weight:800">New Training Video Ready</h2>'
                . '<p style="color:#444;font-size:15px;line-height:1.65;margin:0 0 14px"><strong>' . htmlspecialchars($lesson['title'], ENT_QUOTES) . '</strong> finished processing and is staged as a draft in University, hidden from agents until you review and publish it.</p>'
                . ($blurb !== '' ? '<p style="color:#666;font-size:14px;line-height:1.6;margin:0 0 14px;padding:12px 14px;background:#f7f7f7;border-radius:6px">' . htmlspecialchars($blurb, ENT_QUOTES) . '</p>' : '')
                . '<p style="margin:0"><a href="' . htmlspecialchars($reviewUrl, ENT_QUOTES) . '" style="color:#2255cc">Review it in admin →</a></p>'
                . sender_signature_html('', '')
            ),
            '', '', '', true
        );
        echo "  lesson #{$lesson['id']}: summary + video attached, review email queued\n";
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
