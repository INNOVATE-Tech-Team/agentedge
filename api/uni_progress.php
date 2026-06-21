<?php
// Track agent progress through university lessons and issue certifications.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error'=>'not signed in']); exit; }
$email = $me['email'];
$db    = local_db();

// GET — progress summary for a course
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $courseId = (int)($_GET['course_id'] ?? 0);
    if (!$courseId) { http_response_code(400); echo json_encode(['error'=>'course_id required']); exit; }

    $ls = $db->prepare("SELECT id FROM uni_lessons WHERE course_id=?");
    $ls->execute([$courseId]);
    $lessonIds = $ls->fetchAll(PDO::FETCH_COLUMN, 0);

    $completed = [];
    if ($lessonIds) {
        $placeholders = implode(',', array_fill(0, count($lessonIds), '?'));
        $prog = $db->prepare("SELECT lesson_id, score, completed_at FROM uni_progress WHERE agent_email=? AND lesson_id IN ($placeholders)");
        $prog->execute(array_merge([$email], $lessonIds));
        foreach ($prog->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $completed[$r['lesson_id']] = ['score' => $r['score'], 'completed_at' => $r['completed_at']];
        }
    }

    $cert = null;
    $cs = $db->prepare("SELECT cert_code, issued_at FROM uni_certs WHERE agent_email=? AND course_id=?");
    $cs->execute([$email, $courseId]);
    $cert = $cs->fetch(PDO::FETCH_ASSOC) ?: null;

    echo json_encode(['ok'=>true,'completed'=>$completed,'total'=>count($lessonIds),'cert'=>$cert]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'method not allowed']); exit; }

$in     = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? '';

// Mark a video or doc lesson complete
if ($action === 'complete') {
    $lessonId = (int)($in['lesson_id'] ?? 0);
    if (!$lessonId) { http_response_code(400); echo json_encode(['error'=>'lesson_id required']); exit; }

    $ls = $db->prepare("SELECT l.*, c.published FROM uni_lessons l JOIN uni_courses c ON c.id=l.course_id WHERE l.id=?");
    $ls->execute([$lessonId]);
    $lesson = $ls->fetch(PDO::FETCH_ASSOC);
    if (!$lesson || (!$lesson['published'] && !is_admin())) { http_response_code(404); echo json_encode(['error'=>'not found']); exit; }
    if ($lesson['type'] === 'quiz') { http_response_code(400); echo json_encode(['error'=>'use submit_quiz for quiz lessons']); exit; }

    $db->prepare("INSERT OR IGNORE INTO uni_progress (agent_email,lesson_id,completed_at,score,attempts) VALUES (?,?,datetime('now'),NULL,1)")
       ->execute([$email, $lessonId]);

    $cert = maybe_issue_cert($db, $email, (int)$lesson['course_id']);
    echo json_encode(['ok'=>true,'cert'=>$cert]);
    exit;
}

// Submit quiz answers, grade server-side, mark complete if passed (>=70%)
if ($action === 'submit_quiz') {
    $lessonId = (int)($in['lesson_id'] ?? 0);
    $answers  = $in['answers'] ?? [];
    if (!$lessonId || !is_array($answers)) { http_response_code(400); echo json_encode(['error'=>'lesson_id and answers array required']); exit; }

    $ls = $db->prepare("SELECT l.*, c.published FROM uni_lessons l JOIN uni_courses c ON c.id=l.course_id WHERE l.id=?");
    $ls->execute([$lessonId]);
    $lesson = $ls->fetch(PDO::FETCH_ASSOC);
    if (!$lesson || (!$lesson['published'] && !is_admin())) { http_response_code(404); echo json_encode(['error'=>'not found']); exit; }
    if ($lesson['type'] !== 'quiz') { http_response_code(400); echo json_encode(['error'=>'not a quiz lesson']); exit; }

    $qs = $db->prepare("SELECT id, correct_index FROM uni_questions WHERE lesson_id=? ORDER BY sort_ord,id");
    $qs->execute([$lessonId]);
    $questions = $qs->fetchAll(PDO::FETCH_ASSOC);
    if (!$questions) { http_response_code(400); echo json_encode(['error'=>'no questions in this quiz']); exit; }

    $correct = 0;
    foreach ($questions as $i => $q) {
        if (isset($answers[$i]) && (int)$answers[$i] === (int)$q['correct_index']) $correct++;
    }
    $score  = count($questions) > 0 ? (int)round($correct / count($questions) * 100) : 0;
    $passed = $score >= 70;

    // Track attempt count
    $existingQ = $db->prepare("SELECT attempts FROM uni_progress WHERE agent_email=? AND lesson_id=?");
    $existingQ->execute([$email, $lessonId]);
    $existing = $existingQ->fetch(PDO::FETCH_ASSOC);
    $attempts = $existing ? ($existing['attempts'] + 1) : 1;

    if ($passed) {
        $db->prepare("INSERT INTO uni_progress (agent_email,lesson_id,completed_at,score,attempts) VALUES (?,?,datetime('now'),?,?)
                      ON CONFLICT(agent_email,lesson_id) DO UPDATE SET completed_at=datetime('now'),score=excluded.score,attempts=excluded.attempts")
           ->execute([$email, $lessonId, $score, $attempts]);
    } elseif ($existing) {
        $db->prepare("UPDATE uni_progress SET attempts=? WHERE agent_email=? AND lesson_id=?")->execute([$attempts, $email, $lessonId]);
    }

    $cert = $passed ? maybe_issue_cert($db, $email, (int)$lesson['course_id']) : null;
    echo json_encode([
        'ok'      => true,
        'score'   => $score,
        'passed'  => $passed,
        'correct' => $correct,
        'total'   => count($questions),
        'cert'    => $cert,
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error'=>'unknown action']);

// Issue a certificate if all lessons in the course are complete. Returns cert row or null.
function maybe_issue_cert(PDO $db, string $email, int $courseId): ?array {
    $ts = $db->prepare("SELECT COUNT(*) FROM uni_lessons WHERE course_id=?");
    $ts->execute([$courseId]);
    $total = (int)$ts->fetchColumn();
    if ($total === 0) return null;

    $ds = $db->prepare("SELECT COUNT(*) FROM uni_progress p JOIN uni_lessons l ON l.id=p.lesson_id WHERE p.agent_email=? AND l.course_id=?");
    $ds->execute([$email, $courseId]);
    if ((int)$ds->fetchColumn() < $total) return null;

    $es = $db->prepare("SELECT cert_code, issued_at FROM uni_certs WHERE agent_email=? AND course_id=?");
    $es->execute([$email, $courseId]);
    $existing = $es->fetch(PDO::FETCH_ASSOC);
    if ($existing) return $existing;

    $code = 'INU-' . strtoupper(bin2hex(random_bytes(6)));
    $db->prepare("INSERT INTO uni_certs (agent_email,course_id,cert_code) VALUES (?,?,?)")->execute([$email, $courseId, $code]);
    return ['cert_code' => $code, 'issued_at' => date('Y-m-d H:i:s')];
}
