<?php
// Public (no-login) exit-interview API — authenticated via HMAC token in ?qid=&t=
// instead of a session cookie. Mirrors api/exit_interview.php's logic.
// GET  → load this agent's saved exit-interview data (so a returning visitor
//         sees their in-progress answers)
// POST → upsert; submitted=true marks the offboarding step done
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/notifications.php';

function pub_ei_json(array $d, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($d);
    exit;
}

$qid   = (int)($_GET['qid'] ?? 0);
$token = (string)($_GET['t'] ?? '');

if ($qid <= 0 || $token === '' || !hash_equals(exit_interview_link_token($qid), $token)) {
    pub_ei_json(['ok'=>false,'error'=>'Invalid or expired link'], 403);
}

$pdo = local_db();

$st = $pdo->prepare("SELECT id, agent_email, agent_name, status FROM offboard_queue WHERE id=?");
$st->execute([$qid]);
$entry = $st->fetch(PDO::FETCH_ASSOC);
if (!$entry) {
    pub_ei_json(['ok'=>false,'error'=>'Queue entry not found'], 404);
}

$email = strtolower(trim($entry['agent_email']));

// ── GET: return current saved data ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $st = $pdo->prepare("SELECT * FROM agent_exit_interview WHERE email=?");
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    pub_ei_json(['ok'=>true,'exit_interview'=>$row]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pub_ei_json(['error'=>'POST required'], 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];

$fv = fn($k) => trim((string)($body[$k] ?? ''));

$rating     = $fv('satisfaction_rating');
$wantSubmit = !empty($body['submitted']);
$now        = date('Y-m-d H:i:s');

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
    $email, $qid, $rating !== '' ? (int)$rating : null,
    $fv('feedback_management'), $fv('feedback_support'), $fv('feedback_training'),
    $fv('next_destination'), $fv('would_recommend'), $fv('suggestions'),
    $isSubmitted ? 1 : 0, ($isSubmitted && !$wasSubmitted) ? $now : null, $now,
]);

if ($isSubmitted && !$wasSubmitted) {
    try {
        complete_offboard_step($pdo, $qid, 'exit_interview', $email);
    } catch (\Throwable $e) {}
}

$st = $pdo->prepare("SELECT submitted_at FROM agent_exit_interview WHERE email=?");
$st->execute([$email]);
$submittedAt = $st->fetchColumn();

pub_ei_json(['ok'=>true,'submitted'=>$isSubmitted,'submitted_at'=>$submittedAt ?: null]);
