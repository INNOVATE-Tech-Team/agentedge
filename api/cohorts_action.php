<?php
// LAUNCH (and future program) cohort management — launch_cohorts.php.
// GET  ?program=launch          → can_manage_cohorts(): list cohorts for that program
// POST {action:'create'|'update'} → can_manage_cohorts()
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'not signed in']); exit; }
if (!can_manage_cohorts()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'not authorized']); exit; }

$pdo = local_db();

function jok(array $x = []): void { echo json_encode(array_merge(['ok' => true], $x)); exit; }
function jerr(string $m, int $c = 400): void { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $program = preg_replace('/[^a-z_]/', '', $_GET['program'] ?? 'launch') ?: 'launch';
    $st = $pdo->prepare("SELECT * FROM cohorts WHERE program=? ORDER BY start_date DESC, id DESC");
    $st->execute([$program]);
    $cohorts = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cohorts as &$c) {
        $cst = $pdo->prepare("SELECT COUNT(*) FROM cohort_members WHERE cohort_id=? AND status='active'");
        $cst->execute([$c['id']]);
        $c['active_member_count'] = (int)$cst->fetchColumn();
    }
    jok(['cohorts' => $cohorts]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'GET or POST only']); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

if ($action === 'create') {
    $program = preg_replace('/[^a-z_]/', '', $body['program'] ?? 'launch') ?: 'launch';
    $name    = trim($body['name'] ?? '');
    $start   = trim($body['start_date'] ?? '');
    $cadence = max(1, (int)($body['cadence_weeks'] ?? 1));
    if ($name === '') jerr('name required');
    $pdo->prepare(
        "INSERT INTO cohorts (program, name, start_date, cadence_weeks, created_by, created_at) VALUES (?, ?, ?, ?, ?, datetime('now'))"
    )->execute([$program, $name, $start, $cadence, strtolower($agent['email'])]);
    jok(['id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'update') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) jerr('id required');
    $name   = trim($body['name'] ?? '');
    $start  = trim($body['start_date'] ?? '');
    $status = preg_replace('/[^a-z_]/', '', $body['status'] ?? 'active') ?: 'active';
    if (!in_array($status, ['active', 'graduated', 'archived'], true)) $status = 'active';
    if ($name === '') jerr('name required');
    $pdo->prepare("UPDATE cohorts SET name=?, start_date=?, status=? WHERE id=?")->execute([$name, $start, $status, $id]);
    jok();
}

jerr('unknown action');
