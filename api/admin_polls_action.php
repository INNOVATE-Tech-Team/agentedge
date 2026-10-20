<?php
// Back Office Polls API — super admin ONLY (stricter than is_admin(): the
// wider "admin" tier does not get per-person answers).
//
// POST action=set_status {csrf, id, status}  -> draft | open | closed
// GET  action=results    &id=                -> totals, per-person responses, not-yet-voted
// GET  action=csv        &id=                -> CSV download of all responses
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/polls.php';

$agent = current_agent();
if (!$agent) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Please sign in.']); exit; }
if (!is_super_admin()) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Unauthorized']); exit; }

function ap_json(array $d, int $code = 200): void { http_response_code($code); header('Content-Type: application/json'); header('Cache-Control: no-store'); echo json_encode($d); exit; }

$pdo    = local_db();
$in     = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? ($in['action'] ?? '');
$id     = (int)($_GET['id'] ?? ($in['id'] ?? 0));

if ($action === 'set_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($in['csrf'] ?? ''))) ap_json(['ok' => false, 'error' => 'Invalid CSRF token'], 403);
    $status = (string)($in['status'] ?? '');
    if (!in_array($status, POLL_STATUSES, true)) ap_json(['ok' => false, 'error' => 'Invalid status'], 422);
    $st = $pdo->prepare("UPDATE polls SET status = ?, updated_at = datetime('now') WHERE id = ?");
    $st->execute([$status, $id]);
    if ($st->rowCount() === 0) ap_json(['ok' => false, 'error' => 'Poll not found'], 404);
    ap_json(['ok' => true, 'status' => $status]);
}

$poll = $id > 0 ? polls_load_by_id($pdo, $id) : null;
if (!$poll) ap_json(['ok' => false, 'error' => 'Poll not found'], 404);

if ($action === 'results' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $responses = polls_all_responses($pdo, $poll['id']);
    $people = [];
    foreach ($responses as $r) {
        $people[] = [
            'name' => $r['display_name'], 'email' => $r['email'], 'staff' => (bool)$r['is_staff'],
            'at' => $r['created_at'], 'answers' => $r['answers'], 'text' => polls_response_text($poll, $r),
        ];
    }
    ap_json([
        'ok' => true,
        'poll' => ['id' => $poll['id'], 'title' => $poll['title'], 'status' => $poll['status'], 'steps' => array_map(fn($s) => [
            'step_key' => $s['step_key'], 'type' => $s['type'], 'title' => $s['title'],
            'value' => (int)($s['config']['value'] ?? 0),
            'options' => $s['options'],
        ], $poll['steps'])],
        'totals' => polls_totals($pdo, $poll, false),                 // agents only
        'staff_count' => count(array_filter($responses, fn($r) => $r['is_staff'])),
        'people' => $people,
        'not_voted' => polls_not_voted($pdo, $poll['id']),
        'text' => implode("\n\n", array_map(fn($r) => polls_response_text($poll, $r), $responses)),
    ]);
}

if ($action === 'csv' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $csv = polls_csv($poll, polls_all_responses($pdo, $poll['id']));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="poll-' . preg_replace('/[^a-z0-9_-]/i', '', $poll['slug']) . '-' . gmdate('Ymd') . '.csv"');
    header('Cache-Control: no-store');
    echo $csv;
    exit;
}

ap_json(['ok' => false, 'error' => 'Unknown action'], 400);
