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
$uniDir = __DIR__ . '/../data/uni/';

// Learner file submission for 'upload'-type lessons (multipart POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['file']) && ($_POST['action'] ?? '') === 'submit_learner_upload') {
    $lessonId = (int)($_POST['lesson_id'] ?? 0);
    $file     = $_FILES['file'];
    if (!$lessonId) { http_response_code(400); echo json_encode(['error'=>'lesson_id required']); exit; }
    if ($file['error'] !== UPLOAD_ERR_OK) { http_response_code(400); echo json_encode(['error'=>'upload error ' . $file['error']]); exit; }

    $ls = $db->prepare("SELECT l.*, c.published FROM uni_lessons l JOIN uni_courses c ON c.id=l.course_id WHERE l.id=?");
    $ls->execute([$lessonId]);
    $lesson = $ls->fetch(PDO::FETCH_ASSOC);
    if (!$lesson || (!$lesson['published'] && !is_admin())) { http_response_code(404); echo json_encode(['error'=>'not found']); exit; }
    if ($lesson['type'] !== 'upload') { http_response_code(400); echo json_encode(['error'=>'not an upload lesson']); exit; }

    $old = $db->prepare("SELECT file_key FROM uni_learner_uploads WHERE lesson_id=? AND agent_email=?");
    $old->execute([$lessonId, $email]); $oldKey = $old->fetchColumn();
    if ($oldKey && file_exists($uniDir . $oldKey)) @unlink($uniDir . $oldKey);

    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $key  = uniqid('', true) . ($ext ? ".$ext" : '');
    if (!move_uploaded_file($file['tmp_name'], $uniDir . $key)) { http_response_code(500); echo json_encode(['error'=>'save failed']); exit; }

    $db->prepare("INSERT INTO uni_learner_uploads (lesson_id,agent_email,file_key,original_name,submitted_at) VALUES (?,?,?,?,datetime('now'))
                  ON CONFLICT(lesson_id,agent_email) DO UPDATE SET file_key=excluded.file_key,original_name=excluded.original_name,submitted_at=datetime('now')")
       ->execute([$lessonId, $email, $key, $file['name']]);
    $db->prepare("INSERT OR IGNORE INTO uni_progress (agent_email,lesson_id,completed_at,score,attempts) VALUES (?,?,datetime('now'),NULL,1)")
       ->execute([$email, $lessonId]);

    $cert = maybe_issue_cert($db, $email, (int)$lesson['course_id']);
    echo json_encode(['ok'=>true,'name'=>$file['name'],'cert'=>$cert]); exit;
}

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

    $ls = $db->prepare("SELECT l.*, c.published, c.sequencing_mode FROM uni_lessons l JOIN uni_courses c ON c.id=l.course_id WHERE l.id=?");
    $ls->execute([$lessonId]);
    $lesson = $ls->fetch(PDO::FETCH_ASSOC);
    if (!$lesson || (!$lesson['published'] && !is_admin())) { http_response_code(404); echo json_encode(['error'=>'not found']); exit; }
    if (in_array($lesson['type'], ['quiz','upload','placeholder'])) { http_response_code(400); echo json_encode(['error'=>'not a completable lesson via this action']); exit; }

    if ($block = sequencing_block($db, $email, $lesson)) {
        http_response_code(403);
        echo json_encode(['error'=>'locked','message'=>$block['message'],'blocking_lesson_id'=>$block['lesson_id']]);
        exit;
    }

    $db->prepare("INSERT OR IGNORE INTO uni_progress (agent_email,lesson_id,completed_at,score,attempts) VALUES (?,?,datetime('now'),NULL,1)")
       ->execute([$email, $lessonId]);

    $cert = maybe_issue_cert($db, $email, (int)$lesson['course_id']);
    echo json_encode(['ok'=>true,'cert'=>$cert]);
    exit;
}

// Submit quiz answers, grade server-side, mark complete if passed (course's quiz_pass_score, default 70%)
if ($action === 'submit_quiz') {
    $lessonId = (int)($in['lesson_id'] ?? 0);
    $answers  = $in['answers'] ?? [];
    if (!$lessonId || !is_array($answers)) { http_response_code(400); echo json_encode(['error'=>'lesson_id and answers array required']); exit; }

    $ls = $db->prepare("SELECT l.*, c.published, c.sequencing_mode, c.quiz_pass_score, c.quiz_retake_policy, c.quiz_max_attempts
                        FROM uni_lessons l JOIN uni_courses c ON c.id=l.course_id WHERE l.id=?");
    $ls->execute([$lessonId]);
    $lesson = $ls->fetch(PDO::FETCH_ASSOC);
    if (!$lesson || (!$lesson['published'] && !is_admin())) { http_response_code(404); echo json_encode(['error'=>'not found']); exit; }
    if ($lesson['type'] !== 'quiz') { http_response_code(400); echo json_encode(['error'=>'not a quiz lesson']); exit; }

    if ($block = sequencing_block($db, $email, $lesson)) {
        http_response_code(403);
        echo json_encode(['error'=>'locked','message'=>$block['message'],'blocking_lesson_id'=>$block['lesson_id']]);
        exit;
    }

    // Track attempt count up front -- a capped-out learner is blocked entirely,
    // before any grading or answer-recording happens (not just before the final upsert).
    $existingQ = $db->prepare("SELECT attempts FROM uni_progress WHERE agent_email=? AND lesson_id=?");
    $existingQ->execute([$email, $lessonId]);
    $existing = $existingQ->fetch(PDO::FETCH_ASSOC);
    $priorAttempts = $existing ? (int)$existing['attempts'] : 0;

    if (($lesson['quiz_retake_policy'] ?? 'unlimited') === 'limited') {
        $maxAttempts = (int)($lesson['quiz_max_attempts'] ?? 0);
        if ($maxAttempts > 0 && $priorAttempts >= $maxAttempts) {
            http_response_code(403);
            echo json_encode(['error'=>'max_attempts','message'=>'You have used all allowed attempts for this quiz.']);
            exit;
        }
    }

    $qs = $db->prepare("SELECT id, qtype, correct_indexes, correct_index FROM uni_questions WHERE lesson_id=? ORDER BY sort_ord,id");
    $qs->execute([$lessonId]);
    $questions = $qs->fetchAll(PDO::FETCH_ASSOC);
    if (!$questions) { http_response_code(400); echo json_encode(['error'=>'no questions in this quiz']); exit; }

    // Clear any prior recorded answers for this learner/lesson (re-attempt overwrites)
    $db->prepare("DELETE FROM uni_quiz_answers WHERE lesson_id=? AND agent_email=?")->execute([$lessonId, $email]);
    $insAns = $db->prepare("INSERT INTO uni_quiz_answers (lesson_id,agent_email,question_id,answer_text,selected_indexes) VALUES (?,?,?,?,?)");

    $correct  = 0;
    $gradable = 0;
    foreach ($questions as $i => $q) {
        $qtype = $q['qtype'] ?: 'single';
        $given = $answers[$i] ?? null;

        if ($qtype === 'text') {
            $insAns->execute([$lessonId, $email, $q['id'], is_string($given) ? trim($given) : '', '[]']);
            continue;
        }

        $gradable++;
        $expected = json_decode($q['correct_indexes'] ?: '[]', true) ?: [(int)$q['correct_index']];
        sort($expected);
        if ($qtype === 'multiple') {
            $selected = is_array($given) ? array_values(array_unique(array_map('intval', $given))) : [];
            sort($selected);
            if ($selected === $expected) $correct++;
        } else { // single
            $selected = [is_numeric($given) ? (int)$given : -1];
            if ($selected === $expected) $correct++;
        }
        $insAns->execute([$lessonId, $email, $q['id'], '', json_encode($selected)]);
    }
    $score  = $gradable > 0 ? (int)round($correct / $gradable * 100) : 100;
    $passed = $score >= (int)($lesson['quiz_pass_score'] ?? 70);
    $attempts = $priorAttempts + 1;

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
        'total'   => $gradable,
        'cert'    => $cert,
    ]);
    exit;
}

// Autosave one Feedback answer + the learner's current step. Called on every
// selection/date change (immediate), a debounced timer after typing, and on
// Back/Next/blur (flush) -- all client-side pacing, this endpoint itself just
// upserts whatever it's given. Blank/unanswered questions are permitted (V1
// has no required-question validation); N/A is a real answer (is_na=1),
// distinct from a question with no row at all.
if ($action === 'feedback_autosave') {
    $lessonId = (int)($in['lesson_id'] ?? 0);
    if (!$lessonId) { http_response_code(400); echo json_encode(['error'=>'lesson_id required']); exit; }

    $ls = $db->prepare("SELECT l.*, c.published FROM uni_lessons l JOIN uni_courses c ON c.id=l.course_id WHERE l.id=?");
    $ls->execute([$lessonId]);
    $lesson = $ls->fetch(PDO::FETCH_ASSOC);
    if (!$lesson || (!$lesson['published'] && !is_admin())) { http_response_code(404); echo json_encode(['error'=>'not found']); exit; }
    if ($lesson['type'] !== 'feedback') { http_response_code(400); echo json_encode(['error'=>'not a feedback lesson']); exit; }

    $db->prepare("INSERT OR IGNORE INTO uni_feedback_responses (lesson_id,agent_email) VALUES (?,?)")->execute([$lessonId, $email]);
    $respQ = $db->prepare("SELECT * FROM uni_feedback_responses WHERE lesson_id=? AND agent_email=?");
    $respQ->execute([$lessonId, $email]);
    $response = $respQ->fetch(PDO::FETCH_ASSOC);

    // Submitted responses are immutable in V1 -- no resubmission/edit story yet.
    if ($response['status'] === 'submitted') { http_response_code(409); echo json_encode(['error'=>'already submitted']); exit; }

    if (array_key_exists('current_step', $in)) {
        $db->prepare("UPDATE uni_feedback_responses SET current_step=? WHERE id=?")
           ->execute([(int)$in['current_step'], (int)$response['id']]);
    }

    $questionId = (int)($in['question_id'] ?? 0);
    if ($questionId) {
        $valueText   = array_key_exists('value_text', $in) ? (string)$in['value_text'] : null;
        $valueNumber = array_key_exists('value_number', $in) && $in['value_number'] !== null ? (int)$in['value_number'] : null;
        $isNa        = !empty($in['is_na']) ? 1 : 0;
        $db->prepare(
            "INSERT INTO uni_feedback_answers (response_id,question_id,value_text,value_number,is_na,answered_at)
             VALUES (?,?,?,?,?,datetime('now'))
             ON CONFLICT(response_id,question_id) DO UPDATE SET
               value_text=excluded.value_text, value_number=excluded.value_number,
               is_na=excluded.is_na, answered_at=excluded.answered_at"
        )->execute([(int)$response['id'], $questionId, $valueText, $valueNumber, $isNa]);
    }

    echo json_encode(['ok'=>true]); exit;
}

// Final submit -- idempotent (a repeat call against an already-submitted
// response just confirms the existing state rather than erroring), and marks
// the University lesson complete through the same uni_progress + cert
// convention every other lesson type uses.
if ($action === 'feedback_submit') {
    $lessonId = (int)($in['lesson_id'] ?? 0);
    if (!$lessonId) { http_response_code(400); echo json_encode(['error'=>'lesson_id required']); exit; }

    $ls = $db->prepare("SELECT l.*, c.published, c.sequencing_mode FROM uni_lessons l JOIN uni_courses c ON c.id=l.course_id WHERE l.id=?");
    $ls->execute([$lessonId]);
    $lesson = $ls->fetch(PDO::FETCH_ASSOC);
    if (!$lesson || (!$lesson['published'] && !is_admin())) { http_response_code(404); echo json_encode(['error'=>'not found']); exit; }
    if ($lesson['type'] !== 'feedback') { http_response_code(400); echo json_encode(['error'=>'not a feedback lesson']); exit; }

    if ($block = sequencing_block($db, $email, $lesson)) {
        http_response_code(403);
        echo json_encode(['error'=>'locked','message'=>$block['message'],'blocking_lesson_id'=>$block['lesson_id']]);
        exit;
    }

    $db->prepare("INSERT OR IGNORE INTO uni_feedback_responses (lesson_id,agent_email) VALUES (?,?)")->execute([$lessonId, $email]);
    $respQ = $db->prepare("SELECT * FROM uni_feedback_responses WHERE lesson_id=? AND agent_email=?");
    $respQ->execute([$lessonId, $email]);
    $response = $respQ->fetch(PDO::FETCH_ASSOC);

    if ($response['status'] !== 'submitted') {
        $db->prepare("UPDATE uni_feedback_responses SET status='submitted', submitted_at=datetime('now') WHERE id=?")
           ->execute([(int)$response['id']]);
    }

    $db->prepare("INSERT OR IGNORE INTO uni_progress (agent_email,lesson_id,completed_at,score,attempts) VALUES (?,?,datetime('now'),NULL,1)")
       ->execute([$email, $lessonId]);

    $cert = maybe_issue_cert($db, $email, (int)$lesson['course_id']);
    echo json_encode(['ok'=>true,'cert'=>$cert]);
    exit;
}

http_response_code(400);
echo json_encode(['error'=>'unknown action']);

// Issue a certificate if all lessons in the course are complete. Returns cert row or null.
function maybe_issue_cert(PDO $db, string $email, int $courseId): ?array {
    $cc = $db->prepare("SELECT cert_enabled, cert_expiry_months FROM uni_courses WHERE id=?");
    $cc->execute([$courseId]);
    $courseCfg = $cc->fetch(PDO::FETCH_ASSOC);
    if (!$courseCfg || (int)$courseCfg['cert_enabled'] === 0) return null;

    $ts = $db->prepare("SELECT COUNT(*) FROM uni_lessons WHERE course_id=? AND type!='placeholder'");
    $ts->execute([$courseId]);
    $total = (int)$ts->fetchColumn();
    if ($total === 0) return null;

    $ds = $db->prepare("SELECT COUNT(*) FROM uni_progress p JOIN uni_lessons l ON l.id=p.lesson_id WHERE p.agent_email=? AND l.course_id=?");
    $ds->execute([$email, $courseId]);
    if ((int)$ds->fetchColumn() < $total) return null;

    $es = $db->prepare("SELECT cert_code, issued_at, expires_at FROM uni_certs WHERE agent_email=? AND course_id=?");
    $es->execute([$email, $courseId]);
    $existing = $es->fetch(PDO::FETCH_ASSOC);
    if ($existing) return $existing;

    $expiryMonths = (int)($courseCfg['cert_expiry_months'] ?? 0);
    $expiresAt = $expiryMonths > 0 ? date('Y-m-d H:i:s', strtotime("+{$expiryMonths} months")) : null;

    $code = 'INU-' . strtoupper(bin2hex(random_bytes(6)));
    $db->prepare("INSERT INTO uni_certs (agent_email,course_id,cert_code,expires_at) VALUES (?,?,?,?)")->execute([$email, $courseId, $code, $expiresAt]);
    return ['cert_code' => $code, 'issued_at' => date('Y-m-d H:i:s'), 'expires_at' => $expiresAt];
}

// Sequencing gate for 'in_order' courses: null when unlocked, or details about the
// earliest incomplete earlier lesson blocking this one. Message distinguishes "never
// attempted" from "attempted a quiz and didn't pass it yet" (quiz_block_on_fail's only
// real effect today -- a failed quiz already withholds its own uni_progress row, so the
// gate below blocks on it automatically; this just makes the reason legible).
function sequencing_block(PDO $db, string $email, array $lesson): ?array {
    if (($lesson['sequencing_mode'] ?? 'free') !== 'in_order') return null;

    $earlier = $db->prepare("SELECT id, type FROM uni_lessons
                              WHERE course_id=? AND type!='placeholder'
                              AND (sort_ord < ? OR (sort_ord = ? AND id < ?))
                              ORDER BY sort_ord, id");
    $earlier->execute([(int)$lesson['course_id'], $lesson['sort_ord'], $lesson['sort_ord'], $lesson['id']]);
    $earlierRows = $earlier->fetchAll(PDO::FETCH_ASSOC);
    if (!$earlierRows) return null;

    $ids = array_column($earlierRows, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $done = $db->prepare("SELECT lesson_id FROM uni_progress WHERE agent_email=? AND lesson_id IN ($ph)");
    $done->execute(array_merge([$email], $ids));
    $doneIds = array_flip($done->fetchAll(PDO::FETCH_COLUMN, 0));

    foreach ($earlierRows as $row) {
        if (!isset($doneIds[$row['id']])) {
            $message = $row['type'] === 'quiz'
                ? 'You must pass this quiz to continue.'
                : 'Complete earlier lessons first.';
            return ['lesson_id' => (int)$row['id'], 'message' => $message];
        }
    }
    return null;
}
