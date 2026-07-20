<?php
// Self-report + read weekly activity numbers — my_activity.php (self) and
// coach_dashboard.php / agent_profile.php (viewing an assigned agent).
// GET  ?agent_email=...  → self, that agent's assigned coach, or admin
// POST                   → self-report only: the signed-in agent's own numbers
//                          for one ISO week (defaults to the current week).
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'not signed in']); exit; }
$me = strtolower(trim($agent['email'] ?? ''));

$pdo = local_db();

function jok(array $x = []): void { echo json_encode(array_merge(['ok' => true], $x)); exit; }
function jerr(string $m, int $c = 400): void { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }

function current_week_start(): string {
    $d = new DateTime('now');
    $d->modify('monday this week');
    return $d->format('Y-m-d');
}

// The agent's most recent active cohort membership, if any.
function active_membership(PDO $pdo, string $email): ?array {
    $st = $pdo->prepare(
        "SELECT cm.*, c.program FROM cohort_members cm JOIN cohorts c ON c.id = cm.cohort_id
         WHERE cm.agent_email=? AND cm.status='active' ORDER BY cm.joined_at DESC LIMIT 1"
    );
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $target = strtolower(trim($_GET['agent_email'] ?? $me));

    if ($target !== $me && !is_admin()) {
        $coachSt = $pdo->prepare("SELECT 1 FROM cohort_members WHERE agent_email=? AND coach_email=? LIMIT 1");
        $coachSt->execute([$target, $me]);
        if (!$coachSt->fetchColumn()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'not authorized to view this agent']); exit; }
    }

    $membership = active_membership($pdo, $target);
    $program = $membership['program'] ?? 'launch';

    $kpiSt = $pdo->prepare("SELECT kpi_key, label, unit, weekly_target FROM kpi_definitions WHERE program=? AND active=1 ORDER BY sort_ord");
    $kpiSt->execute([$program]);
    $kpis = $kpiSt->fetchAll(PDO::FETCH_ASSOC);

    $histSt = $pdo->prepare("SELECT kpi_key, week_start, value FROM weekly_activity WHERE agent_email=? ORDER BY week_start DESC LIMIT 200");
    $histSt->execute([$target]);
    $history = $histSt->fetchAll(PDO::FETCH_ASSOC);

    $msSt = $pdo->prepare("SELECT milestone_key, label, achieved_at FROM milestones WHERE agent_email=? ORDER BY achieved_at DESC");
    $msSt->execute([$target]);

    jok([
        'agent_email'   => $target,
        'in_cohort'     => $membership !== null,
        'cohort_id'     => $membership['cohort_id'] ?? null,
        'kpis'          => $kpis,
        'week_start'    => current_week_start(),
        'history'       => $history,
        'milestones'    => $msSt->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'GET or POST only']); exit; }

$membership = active_membership($pdo, $me);
if (!$membership) jerr('you are not an active member of any cohort');
$program = $membership['program'];

$kpiSt = $pdo->prepare("SELECT kpi_key FROM kpi_definitions WHERE program=? AND active=1");
$kpiSt->execute([$program]);
$validKeys = $kpiSt->fetchAll(PDO::FETCH_COLUMN);

$body      = json_decode(file_get_contents('php://input'), true) ?: [];
$weekStart = trim($body['week_start'] ?? '') ?: current_week_start();
$values    = is_array($body['values'] ?? null) ? $body['values'] : [];
if (!$values) jerr('values required');

$upsert = $pdo->prepare(
    "INSERT INTO weekly_activity (agent_email, cohort_id, kpi_key, week_start, value, source, logged_by, logged_at)
     VALUES (?, ?, ?, ?, ?, 'self', ?, datetime('now'))
     ON CONFLICT(agent_email, kpi_key, week_start) DO UPDATE SET
       value=excluded.value, logged_by=excluded.logged_by, logged_at=excluded.logged_at"
);

$hadFirstSignedBefore = null;
foreach ($values as $kpiKey => $value) {
    if (!in_array($kpiKey, $validKeys, true)) continue;
    $value = max(0, (int)$value);
    $upsert->execute([$me, $membership['cohort_id'], $kpiKey, $weekStart, $value, $me]);

    if ($kpiKey === 'signed_agreements' && $value > 0) {
        if ($hadFirstSignedBefore === null) {
            $chk = $pdo->prepare("SELECT 1 FROM milestones WHERE agent_email=? AND milestone_key='first_signed_agreement' LIMIT 1");
            $chk->execute([$me]);
            $hadFirstSignedBefore = (bool)$chk->fetchColumn();
        }
        if (!$hadFirstSignedBefore) {
            $pdo->prepare(
                "INSERT INTO milestones (cohort_id, agent_email, milestone_key, label, achieved_at) VALUES (?, ?, 'first_signed_agreement', 'First Signed Agreement', datetime('now'))"
            )->execute([$membership['cohort_id'], $me]);
            $hadFirstSignedBefore = true;
        }
    }
}

// Week-complete milestone: every active KPI for this program has a logged row
// (any value, including 0) for this week. One row per agent per week — the
// week itself is stashed in `note` since milestones has no week_start column.
if (count($validKeys) > 0) {
    $countSt = $pdo->prepare("SELECT COUNT(DISTINCT kpi_key) FROM weekly_activity WHERE agent_email=? AND week_start=? AND kpi_key IN (" . implode(',', array_fill(0, count($validKeys), '?')) . ")");
    $countSt->execute(array_merge([$me, $weekStart], $validKeys));
    if ((int)$countSt->fetchColumn() >= count($validKeys)) {
        $dup = $pdo->prepare("SELECT 1 FROM milestones WHERE agent_email=? AND milestone_key='week_complete' AND note=? LIMIT 1");
        $dup->execute([$me, $weekStart]);
        if (!$dup->fetchColumn()) {
            $pdo->prepare(
                "INSERT INTO milestones (cohort_id, agent_email, milestone_key, label, achieved_at, note) VALUES (?, ?, 'week_complete', 'Finished a LAUNCH week', datetime('now'), ?)"
            )->execute([$membership['cohort_id'], $me, $weekStart]);
        }
    }
}

jok(['week_start' => $weekStart]);
