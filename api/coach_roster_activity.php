<?php
// A coach's roster with this week's KPI numbers — coach_dashboard.php.
// GET ?coach_email=... (admin only override; defaults to the signed-in coach)
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'not signed in']); exit; }
$me = strtolower(trim($agent['email'] ?? ''));

if (!is_launch_coach() && !is_admin()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'not authorized']); exit; }

$pdo = local_db();

$coachEmail = $me;
if (is_admin() && !empty($_GET['coach_email'])) {
    $coachEmail = strtolower(trim($_GET['coach_email']));
}

function current_week_start(): string {
    $d = new DateTime('now');
    $d->modify('monday this week');
    return $d->format('Y-m-d');
}
$week = current_week_start();

$st = $pdo->prepare(
    "SELECT cm.id, cm.agent_email, cm.status, cm.cohort_id, c.name AS cohort_name, c.program
     FROM cohort_members cm JOIN cohorts c ON c.id = cm.cohort_id
     WHERE cm.coach_email=? AND cm.status='active' ORDER BY c.name, cm.agent_email"
);
$st->execute([$coachEmail]);
$members = $st->fetchAll(PDO::FETCH_ASSOC);

$kpiCache = [];
$waSt   = $pdo->prepare("SELECT kpi_key, value FROM weekly_activity WHERE agent_email=? AND week_start=?");
$flagSt = $pdo->prepare("SELECT kpi_key, consecutive_misses FROM activity_flags WHERE agent_email=? AND resolved=0");

foreach ($members as &$m) {
    $program = $m['program'];
    if (!isset($kpiCache[$program])) {
        $kpiSt = $pdo->prepare("SELECT kpi_key, label, weekly_target FROM kpi_definitions WHERE program=? AND active=1 ORDER BY sort_ord");
        $kpiSt->execute([$program]);
        $kpiCache[$program] = $kpiSt->fetchAll(PDO::FETCH_ASSOC);
    }
    $kpis = $kpiCache[$program];

    $waSt->execute([$m['agent_email'], $week]);
    $vals = array_column($waSt->fetchAll(PDO::FETCH_ASSOC), 'value', 'kpi_key');

    $flagSt->execute([$m['agent_email']]);
    $flags = array_column($flagSt->fetchAll(PDO::FETCH_ASSOC), 'consecutive_misses', 'kpi_key');

    $m['this_week'] = [];
    $m['flagged']   = false;
    foreach ($kpis as $k) {
        $miss = isset($flags[$k['kpi_key']]);
        if ($miss) $m['flagged'] = true;
        $m['this_week'][$k['kpi_key']] = [
            'label'              => $k['label'],
            'value'              => (int)($vals[$k['kpi_key']] ?? 0),
            'target'             => (int)$k['weekly_target'],
            'consecutive_misses' => $miss ? (int)$flags[$k['kpi_key']] : 0,
        ];
    }
}
unset($m);

echo json_encode(['ok' => true, 'coach_email' => $coachEmail, 'week_start' => $week, 'members' => $members]);
