<?php
// Weekly accountability check — for every active cohort member, looks at the
// week that just closed and flags any KPI missed 2+ weeks running, routing a
// notification to their assigned coach. Meant to run once a week, shortly
// after Monday rolls over:
//   5 0 * * MON /usr/bin/php /path/to/agentedge/cron/flag_missed_targets.php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';

$db = local_db();

$lastWeekStart = (new DateTime('now'))->modify('monday this week')->modify('-1 week')->format('Y-m-d');

$members = $db->query(
    "SELECT cm.id, cm.agent_email, cm.coach_email, cm.cohort_id, c.program
     FROM cohort_members cm JOIN cohorts c ON c.id = cm.cohort_id
     WHERE cm.status='active'"
)->fetchAll(PDO::FETCH_ASSOC);

$kpiCache = [];
$valueSt  = $db->prepare("SELECT value FROM weekly_activity WHERE agent_email=? AND kpi_key=? AND week_start=?");
$flagSt   = $db->prepare("SELECT * FROM activity_flags WHERE agent_email=? AND kpi_key=? AND resolved=0");
$notifyIns = $db->prepare("INSERT INTO notification_queue (recipient, channel, subject, body) VALUES (?, 'email', ?, ?)");

$flaggedCount = 0;
$resolvedCount = 0;

foreach ($members as $m) {
    if (!isset($kpiCache[$m['program']])) {
        $kSt = $db->prepare("SELECT kpi_key, label, weekly_target FROM kpi_definitions WHERE program=? AND active=1");
        $kSt->execute([$m['program']]);
        $kpiCache[$m['program']] = $kSt->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($kpiCache[$m['program']] as $kpi) {
        $valueSt->execute([$m['agent_email'], $kpi['kpi_key'], $lastWeekStart]);
        $value = (int)($valueSt->fetchColumn() ?: 0);
        $met   = $value >= (int)$kpi['weekly_target'];

        $flagSt->execute([$m['agent_email'], $kpi['kpi_key']]);
        $flag = $flagSt->fetch(PDO::FETCH_ASSOC);

        if ($met) {
            if ($flag) {
                $db->prepare("UPDATE activity_flags SET resolved=1, resolved_at=datetime('now') WHERE id=?")->execute([$flag['id']]);
                $resolvedCount++;
            }
            continue;
        }

        // Missed target for $lastWeekStart.
        if ($flag && $flag['week_start'] === $lastWeekStart) {
            // Already processed this week for this streak (cron re-run) — skip.
            continue;
        }

        if ($flag) {
            $misses = (int)$flag['consecutive_misses'] + 1;
            $db->prepare("UPDATE activity_flags SET consecutive_misses=?, week_start=?, flagged_at=datetime('now') WHERE id=?")
               ->execute([$misses, $lastWeekStart, $flag['id']]);
        } else {
            $misses = 1;
            $db->prepare(
                "INSERT INTO activity_flags (agent_email, cohort_id, kpi_key, week_start, consecutive_misses, coach_email) VALUES (?, ?, ?, ?, 1, ?)"
            )->execute([$m['agent_email'], $m['cohort_id'], $kpi['kpi_key'], $lastWeekStart, $m['coach_email']]);
        }

        if ($misses >= 2 && $m['coach_email'] !== '') {
            $subject = "{$m['agent_email']} has missed {$kpi['label']} target {$misses} weeks running";
            $body    = "{$m['agent_email']} logged below the weekly target for {$kpi['label']} for {$misses} consecutive weeks (most recent week: {$lastWeekStart}). No action needed from you beyond a check-in — this is just the heads up.";
            $notifyIns->execute([$m['coach_email'], $subject, $body]);
            $flaggedCount++;
        }
    }
}

echo "[" . date('Y-m-d H:i:s') . "] flag_missed_targets: week={$lastWeekStart} flagged={$flaggedCount} resolved={$resolvedCount}\n";
