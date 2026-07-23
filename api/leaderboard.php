<?php
// Gamification data for leaderboard.php — live leaderboard on one KPI at a
// time, cohort-vs-cohort framing, per-agent streaks, and a recent-wins feed
// pulled straight from the milestones table. Read-only, visible to any
// signed-in agent (motivational surface, not an admin tool) — no write path
// here, all writes still go through api/weekly_activity.php.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

$agentSession = current_agent();
if (!$agentSession) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'not signed in']); exit; }

$pdo = local_db();

function current_week_start(): string {
    $d = new DateTime('now');
    $d->modify('monday this week');
    return $d->format('Y-m-d');
}

// Consecutive weeks (walking back from $weekStart) where this agent logged a
// value >= target for this KPI. Stops at the first missing/under-target week.
// Capped at 104 (two years) as a sanity backstop, not an expected real value.
function compute_streak(array $history, int $target, string $weekStart): int {
    $week = new DateTime($weekStart);
    $streak = 0;
    for ($i = 0; $i < 104; $i++) {
        $key = $week->format('Y-m-d');
        if (!isset($history[$key]) || (int)$history[$key] < $target) break;
        $streak++;
        $week->modify('-7 days');
    }
    return $streak;
}

$program  = preg_replace('/[^a-z_]/', '', $_GET['program'] ?? 'launch') ?: 'launch';
$weekStart = current_week_start();

$kpiSt = $pdo->prepare("SELECT kpi_key, label, weekly_target FROM kpi_definitions WHERE program=? AND active=1 ORDER BY sort_ord");
$kpiSt->execute([$program]);
$kpis = $kpiSt->fetchAll(PDO::FETCH_ASSOC);

$kpiKey = preg_replace('/[^a-z_]/', '', $_GET['kpi_key'] ?? '');
$kpiByKey = array_column($kpis, null, 'kpi_key');
if ($kpiKey === '' || !isset($kpiByKey[$kpiKey])) {
    $kpiKey = $kpis[0]['kpi_key'] ?? '';
}
$target = (int)($kpiByKey[$kpiKey]['weekly_target'] ?? 0);

// Active members + which cohort they're in, for this program.
$members = $pdo->prepare(
    "SELECT cm.agent_email, cm.cohort_id, c.name AS cohort_name
     FROM cohort_members cm JOIN cohorts c ON c.id = cm.cohort_id
     WHERE cm.status='active' AND c.program=?"
);
$members->execute([$program]);
$members = $members->fetchAll(PDO::FETCH_ASSOC);

$histSt = $pdo->prepare("SELECT week_start, value FROM weekly_activity WHERE agent_email=? AND kpi_key=?");

$agentLeaderboard = [];
$cohortAgg = []; // cohort_id => ['name'=>, 'total'=>, 'hit'=>, 'count'=>]

foreach ($members as $m) {
    $histSt->execute([$m['agent_email'], $kpiKey]);
    $history = array_column($histSt->fetchAll(PDO::FETCH_ASSOC), 'value', 'week_start');
    $value  = (int)($history[$weekStart] ?? 0);
    $streak = $kpiKey !== '' ? compute_streak($history, $target, $weekStart) : 0;

    $agentLeaderboard[] = [
        'agent_email' => $m['agent_email'],
        'cohort_name' => $m['cohort_name'],
        'value'       => $value,
        'target'      => $target,
        'streak'      => $streak,
    ];

    $cid = $m['cohort_id'];
    if (!isset($cohortAgg[$cid])) $cohortAgg[$cid] = ['cohort_id' => $cid, 'cohort_name' => $m['cohort_name'], 'total' => 0, 'hit' => 0, 'count' => 0];
    $cohortAgg[$cid]['total'] += $value;
    $cohortAgg[$cid]['count']++;
    if ($value >= $target) $cohortAgg[$cid]['hit']++;
}

usort($agentLeaderboard, fn($a, $b) => $b['value'] <=> $a['value']);

$cohortLeaderboard = array_values($cohortAgg);
foreach ($cohortLeaderboard as &$c) {
    $c['avg_value']      = $c['count'] > 0 ? round($c['total'] / $c['count'], 1) : 0;
    $c['pct_hit_target'] = $c['count'] > 0 ? round(100 * $c['hit'] / $c['count']) : 0;
}
unset($c);
usort($cohortLeaderboard, fn($a, $b) => $b['pct_hit_target'] <=> $a['pct_hit_target'] ?: $b['avg_value'] <=> $a['avg_value']);

// Recent wins feed — every milestone type, most recent first, across this program's cohorts.
$msSt = $pdo->prepare(
    "SELECT m.agent_email, m.milestone_key, m.label, m.achieved_at, c.name AS cohort_name
     FROM milestones m LEFT JOIN cohorts c ON c.id = m.cohort_id
     WHERE m.cohort_id IN (SELECT id FROM cohorts WHERE program=?) OR m.cohort_id IS NULL
     ORDER BY m.achieved_at DESC LIMIT 15"
);
$msSt->execute([$program]);

echo json_encode([
    'ok'                 => true,
    'program'            => $program,
    'kpi_key'            => $kpiKey,
    'week_start'         => $weekStart,
    'kpis'               => $kpis,
    'agent_leaderboard'  => $agentLeaderboard,
    'cohort_leaderboard' => $cohortLeaderboard,
    'recent_milestones'  => $msSt->fetchAll(PDO::FETCH_ASSOC),
]);
