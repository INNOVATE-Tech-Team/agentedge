<?php
// Cohort membership + coach assignment — launch_cohorts.php.
// GET  ?cohort_id=...        → can_manage_cohorts(): members of one cohort, with
//                               this week's KPI values joined in for a quick glance.
// POST {action:'add'}        → add an agent to a cohort with an assigned coach
// POST {action:'set_status'} → active | graduated | dropped (graduated logs a milestone)
// POST {action:'set_coach'}  → reassign a member's coach
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

// Monday of the current ISO week, e.g. '2026-07-13'.
function current_week_start(): string {
    $d = new DateTime('now');
    $d->modify('monday this week');
    return $d->format('Y-m-d');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    if (!$cohortId) jerr('cohort_id required');

    $st = $pdo->prepare("SELECT * FROM cohort_members WHERE cohort_id=? ORDER BY status, agent_email");
    $st->execute([$cohortId]);
    $members = $st->fetchAll(PDO::FETCH_ASSOC);

    $cohort = $pdo->prepare("SELECT program FROM cohorts WHERE id=?");
    $cohort->execute([$cohortId]);
    $program = $cohort->fetchColumn() ?: 'launch';

    $week = current_week_start();
    $kpiSt = $pdo->prepare("SELECT kpi_key, label, weekly_target FROM kpi_definitions WHERE program=? AND active=1 ORDER BY sort_ord");
    $kpiSt->execute([$program]);
    $kpis = $kpiSt->fetchAll(PDO::FETCH_ASSOC);

    $waSt = $pdo->prepare("SELECT kpi_key, value FROM weekly_activity WHERE agent_email=? AND week_start=?");
    foreach ($members as &$m) {
        $waSt->execute([$m['agent_email'], $week]);
        $vals = array_column($waSt->fetchAll(PDO::FETCH_ASSOC), 'value', 'kpi_key');
        $m['this_week'] = [];
        foreach ($kpis as $k) {
            $m['this_week'][$k['kpi_key']] = ['value' => (int)($vals[$k['kpi_key']] ?? 0), 'target' => (int)$k['weekly_target']];
        }
    }

    jok(['members' => $members, 'kpis' => $kpis, 'week_start' => $week]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'GET or POST only']); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

if ($action === 'add') {
    $cohortId = (int)($body['cohort_id'] ?? 0);
    $email    = strtolower(trim($body['agent_email'] ?? ''));
    $coach    = strtolower(trim($body['coach_email'] ?? ''));
    if (!$cohortId || $email === '') jerr('cohort_id and agent_email required');
    try {
        $pdo->prepare(
            "INSERT INTO cohort_members (cohort_id, agent_email, coach_email, joined_at, updated_at) VALUES (?, ?, ?, datetime('now'), datetime('now'))"
        )->execute([$cohortId, $email, $coach]);
    } catch (\Exception $e) { jerr('agent is already a member of this cohort'); }
    jok(['id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'set_coach') {
    $id    = (int)($body['id'] ?? 0);
    $coach = strtolower(trim($body['coach_email'] ?? ''));
    if (!$id) jerr('id required');
    $pdo->prepare("UPDATE cohort_members SET coach_email=?, updated_at=datetime('now') WHERE id=?")->execute([$coach, $id]);
    jok();
}

if ($action === 'set_status') {
    $id     = (int)($body['id'] ?? 0);
    $status = preg_replace('/[^a-z_]/', '', $body['status'] ?? '');
    if (!$id || !in_array($status, ['active', 'graduated', 'dropped'], true)) jerr('id and a valid status required');

    $st = $pdo->prepare("SELECT cohort_id, agent_email, status FROM cohort_members WHERE id=?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) jerr('member not found', 404);

    $pdo->prepare("UPDATE cohort_members SET status=?, updated_at=datetime('now') WHERE id=?")->execute([$status, $id]);

    if ($status === 'graduated' && $row['status'] !== 'graduated') {
        $pdo->prepare(
            "INSERT INTO milestones (cohort_id, agent_email, milestone_key, label, achieved_at) VALUES (?, ?, 'graduated', 'Graduated LAUNCH into the coaching phase', datetime('now'))"
        )->execute([$row['cohort_id'], $row['agent_email']]);
    }
    jok();
}

jerr('unknown action');
