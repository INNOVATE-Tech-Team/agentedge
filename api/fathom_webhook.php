<?php
// Fathom.video event webhook — auto-ingests a recorded training call into
// University as a hidden draft lesson (pending_review=1) in the "Recorded
// Training Calls" holding course. cron/process_fathom_downloads.php picks up
// the row afterward to attach the transcript + video file asynchronously —
// this handler only creates the placeholder and returns, it never blocks on
// outbound Fathom calls.
// Register in Fathom: Settings → API Access → Add Webhook, subscribed to
// "new-meeting-content-ready". Signed Svix-style — verified via
// fathom_verify_webhook() in lib/fathom.php.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/fathom.php';

header('Content-Type: application/json');

function json_out(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok'=>false,'error'=>'POST required'], 405);
}

$raw  = file_get_contents('php://input');
$hdrs = [];
foreach (getallheaders() as $k => $v) $hdrs[strtolower($k)] = $v;

if (!fathom_verify_webhook($raw, $hdrs)) {
    json_out(['ok'=>false,'error'=>'Invalid signature'], 403);
}

$evt = json_decode($raw, true) ?: [];
if (!empty($evt['is_test_event'])) {
    json_out(['ok'=>true,'skipped'=>true]); // ack, ignore other event types
}

$data      = $evt['data'] ?? $evt;
$meetingId = (string)($data['recording_id'] ?? '');
if ($meetingId === '') json_out(['ok'=>false,'error'=>'no meeting id in payload'], 400);

$db = local_db();

// Idempotency — Fathom may resend the same event.
$existing = $db->prepare("SELECT id FROM uni_lessons WHERE fathom_meeting_id=?");
$existing->execute([$meetingId]);
if ($existing->fetchColumn()) json_out(['ok'=>true,'already_ingested'=>true]);

$title    = trim($data['title'] ?? $data['meeting_title'] ?? '') ?: ('Training Call — ' . date('M j, Y'));
$courseId = fathom_get_or_create_holding_course();

$mo = $db->prepare("SELECT COALESCE(MAX(sort_ord),0) FROM uni_lessons WHERE course_id=?");
$mo->execute([$courseId]);
$nextOrd = ((int)$mo->fetchColumn()) + 10;

$transcript     = $data['transcript'] ?? '';
$transcriptHtml = $transcript !== ''
    ? '<pre style="white-space:pre-wrap;font-family:inherit">' . htmlspecialchars($transcript) . '</pre>'
    : 'Processing transcript…';

$db->prepare(
    "INSERT INTO uni_lessons
        (course_id, title, sort_ord, type, content_html, tags, learning_objective, difficulty, related_lessons, pending_review, fathom_meeting_id, fathom_status)
     VALUES (?, ?, ?, 'video', ?, ?, ?, 'beginner', '[]', 1, ?, 'pending')"
)->execute([
    $courseId, $title, $nextOrd,
    $transcriptHtml,
    json_encode(['training-call']),
    'Review this recorded training call.',
    $meetingId,
]);

json_out(['ok'=>true, 'lesson_id'=>(int)$db->lastInsertId()]);
