<?php
// Exit interview API — self-service, mirrors api/intake.php's pattern.
// GET (no action)            → load own (or admin: any agent's) exit interview data
// POST action=save (default) → upsert exit interview data; submitted=true marks
//                               the offboarding 'exit_interview' step done
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

function ei_json_out(array $d, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($d);
    exit;
}

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$pdo     = local_db();
$myEmail = strtolower(trim($agent['email'] ?? ''));
$isAdmin = is_admin();

// Most recent active offboard_queue row for an email — offboard_queue has no
// unique constraint on agent_email, so this picks the same row exit_interview.php did.
function ei_active_queue_id(PDO $pdo, string $email): ?int {
    $st = $pdo->prepare(
        "SELECT id FROM offboard_queue WHERE LOWER(agent_email)=? AND status='active' ORDER BY added_at DESC LIMIT 1"
    );
    $st->execute([$email]);
    $id = $st->fetchColumn();
    return $id !== false ? (int)$id : null;
}

// ── GET: load a single agent's exit interview data ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    $email = $myEmail;
    if (!empty($_GET['email'])) {
        $requested = strtolower(trim($_GET['email']));
        if (!$isAdmin && $requested !== $myEmail) ei_json_out(['error' => 'forbidden'], 403);
        $email = $requested;
    }
    $st = $pdo->prepare("SELECT * FROM agent_exit_interview WHERE email=?");
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    ei_json_out(['ok' => true, 'exit_interview' => $row]);
}

// ── All remaining actions require POST ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    ei_json_out(['error' => 'POST required'], 405);
}
header('Content-Type: application/json');

$body = json_decode(file_get_contents('php://input'), true) ?: [];
if (!$body) { foreach ($_POST as $k => $v) $body[$k] = $v; }

$email = $myEmail;
if ($isAdmin && !empty($body['email'])) $email = strtolower(trim($body['email']));

$queueId = ei_active_queue_id($pdo, $email);
if (!$queueId) ei_json_out(['ok' => false, 'error' => 'No active offboarding case found for this agent'], 400);

$fv = fn($k) => trim((string)($body[$k] ?? ''));

$rating       = $fv('satisfaction_rating');
$wantSubmit   = !empty($body['submitted']);
$now          = date('Y-m-d H:i:s');

$prev = $pdo->prepare("SELECT submitted FROM agent_exit_interview WHERE email=?");
$prev->execute([$email]);
$pr           = $prev->fetch(PDO::FETCH_ASSOC);
$wasSubmitted = !empty($pr['submitted']);
$isSubmitted  = $wantSubmit || $wasSubmitted;

$pdo->prepare(
    "INSERT INTO agent_exit_interview
        (email, queue_id, satisfaction_rating, feedback_management, feedback_support,
         feedback_training, next_destination, would_recommend, suggestions,
         submitted, submitted_at, updated_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
     ON CONFLICT(email) DO UPDATE SET
        queue_id             = excluded.queue_id,
        satisfaction_rating  = excluded.satisfaction_rating,
        feedback_management  = excluded.feedback_management,
        feedback_support     = excluded.feedback_support,
        feedback_training    = excluded.feedback_training,
        next_destination     = excluded.next_destination,
        would_recommend      = excluded.would_recommend,
        suggestions          = excluded.suggestions,
        submitted            = excluded.submitted,
        submitted_at         = COALESCE(agent_exit_interview.submitted_at, excluded.submitted_at),
        updated_at           = excluded.updated_at"
)->execute([
    $email, $queueId, $rating !== '' ? (int)$rating : null,
    $fv('feedback_management'), $fv('feedback_support'), $fv('feedback_training'),
    $fv('next_destination'), $fv('would_recommend'), $fv('suggestions'),
    $isSubmitted ? 1 : 0, ($isSubmitted && !$wasSubmitted) ? $now : null, $now,
]);

if ($isSubmitted && !$wasSubmitted) {
    try {
        require_once __DIR__ . '/../lib/notifications.php';
        complete_offboard_step($pdo, $queueId, 'exit_interview', $email);
    } catch (\Throwable $e) {}
}

$st = $pdo->prepare("SELECT submitted_at FROM agent_exit_interview WHERE email=?");
$st->execute([$email]);
$submittedAt = $st->fetchColumn();

ei_json_out(['ok' => true, 'submitted' => $isSubmitted, 'submitted_at' => $submittedAt ?: null]);
