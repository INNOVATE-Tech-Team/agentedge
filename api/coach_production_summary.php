<?php
// Coach Dashboard production summary — thin HTTP/JSON layer over
// lib/coach_production.php. No production calculations live here; this file
// only authenticates, parses/validates request params, calls the helper
// library, and shapes JSON. No HTTP calls to Coastline, no Perfex, no
// Pending, no GCI, no fuzzy/name identity matching.
//
// GET params:
//   agent     - roster email (matches coach_dashboard.php's existing
//               lowercase-email selector convention). Omit/empty = "All Agents".
//   roster_id - alternate direct innovate_roster.id, takes precedence over
//               `agent` if both are given. Not used by the current frontend
//               yet (it sends `agent`=email) -- accepted here so the next
//               step can switch to it without another API contract change.
//   period    - ltm | ytd | year | custom | a bare 4-digit year (e.g. "2025",
//               compatibility shim for coach_dashboard.php's current
//               hardcoded period button -- see Step 5 report). Default: ltm.
//   year      - required when period=year.
//   from/to   - required when period=custom (matches coach_dashboard.php's
//               existing param names); `start`/`end` also accepted.
//
// Response envelope: {ok, scope, period, mirror, coverage, company_summary,
// selected_agent, agents}. See Step 5 report for the exact shape rationale.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/coach_production.php';

header('Content-Type: application/json');

function cps_fail(int $code, string $message): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

$viewer = current_agent();
if (!$viewer) cps_fail(401, 'not signed in');

// Same gate as coach_dashboard.php / coach_agent_detail.php: Super Admin or
// Launch Coach. A Launch Coach's access is scoped below to only agents
// assigned to them via agent_admin.coached_by -- never company-wide, never
// another coach's agents -- enforced here server-side (see
// coach_assigned_agents()/coach_can_access_agent() in roles.php), not left
// to the frontend to hide.
$isSuperAdmin = is_super_admin();
if (!$isSuperAdmin && !is_launch_coach()) cps_fail(403, 'not authorized');
$coachScoped = !$isSuperAdmin;

$pdo = local_db();

$viewerEmail = strtolower(trim($viewer['email'] ?? ''));
$assignedEmails = $coachScoped
    ? array_map(fn($r) => strtolower(trim($r['email'])), coach_assigned_agents($pdo, $viewerEmail))
    : [];

// ── Period parsing/validation ────────────────────────────────────────────────

function cps_valid_date(string $s): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return false;
    $d = \DateTime::createFromFormat('Y-m-d', $s);
    return $d !== false && $d->format('Y-m-d') === $s;
}

$periodParam = trim((string)($_GET['period'] ?? 'ltm'));
$periodType = null;
$periodOpts = [];

if (preg_match('/^\d{4}$/', $periodParam)) {
    // Compatibility shim: coach_dashboard.php's period buttons currently
    // hardcode a literal year string (e.g. "2025") rather than a generic
    // "year" type with a `year` param -- see Step 5 report. Accept it
    // directly rather than requiring the frontend to change first.
    $periodType = 'year';
    $periodOpts = ['year' => (int)$periodParam];
} elseif (in_array($periodParam, ['ltm', 'ytd'], true)) {
    $periodType = $periodParam;
} elseif ($periodParam === 'year') {
    if (!isset($_GET['year']) || !preg_match('/^\d{4}$/', (string)$_GET['year'])) {
        cps_fail(400, "period=year requires a 4-digit 'year' parameter");
    }
    $periodType = 'year';
    $periodOpts = ['year' => (int)$_GET['year']];
} elseif ($periodParam === 'custom') {
    $from = (string)($_GET['from'] ?? $_GET['start'] ?? '');
    $to   = (string)($_GET['to']   ?? $_GET['end']   ?? '');
    if (!cps_valid_date($from) || !cps_valid_date($to)) {
        cps_fail(400, "period=custom requires valid 'from' and 'to' dates (YYYY-MM-DD)");
    }
    $periodType = 'custom';
    $periodOpts = ['start' => $from, 'end' => $to];
} else {
    cps_fail(400, "unknown period '{$periodParam}'");
}

try {
    $period = coach_normalize_period($periodType, $periodOpts);
} catch (\InvalidArgumentException $e) {
    cps_fail(400, $e->getMessage());
}

// ── Agent resolution (roster_id takes precedence over agent=email) ──────────

$rosterId = null;
if (!empty($_GET['roster_id'])) {
    if (!preg_match('/^\d+$/', (string)$_GET['roster_id'])) cps_fail(400, "roster_id must be a positive integer");
    $rosterId = (int)$_GET['roster_id'];
    $rosterIdRow = coach_roster_row($pdo, $rosterId);
    if (!$rosterIdRow) cps_fail(404, 'agent not found');
    // Re-checked against the coach's actual assigned set (not just whether
    // the row exists) -- a roster_id for someone else's/an unassigned agent
    // must fail here even though it's a perfectly valid roster_id.
    if ($coachScoped && !in_array(strtolower(trim($rosterIdRow['email'] ?? '')), $assignedEmails, true)) {
        cps_fail(403, 'agent not assigned to you');
    }
} elseif (!empty(trim((string)($_GET['agent'] ?? '')))) {
    // Exact, case-insensitive email match only -- same normalization
    // coach_dashboard.php/coach_agent_detail.php already use. Never a
    // fuzzy/name lookup.
    $email = strtolower(trim((string)$_GET['agent']));
    if ($coachScoped && !in_array($email, $assignedEmails, true)) {
        cps_fail(403, 'agent not assigned to you');
    }
    $stmt = $pdo->prepare("SELECT id FROM innovate_roster WHERE LOWER(TRIM(email)) = ?");
    $stmt->execute([$email]);
    $foundId = $stmt->fetchColumn();
    if ($foundId === false) cps_fail(404, 'agent not found');
    $rosterId = (int)$foundId;
}

$scope = $rosterId !== null ? 'agent' : 'all';
// A Launch Coach never gets the company-wide view -- whether that's because
// no agent/roster_id was given at all, or an out-of-scope one was rejected
// above (which already exited with 403 and never reaches this line).
if ($coachScoped && $scope === 'all') {
    cps_fail(403, 'company-wide scope is not available to Launch Coaches');
}

// ── Shared mirror/coverage (independent of scope or selected agent) ─────────

try {
    // Computed exactly once per request and threaded through every helper
    // call below (each accepts an optional $mirrorHealth to reuse) --
    // previously each helper independently re-queried this, which was fixed
    // overhead (not N+1) but unnecessary. See Step 6 report.
    $mirrorHealthFull = coach_mirror_health($pdo);
    $coverage = coach_check_coverage($pdo, $period['start'], $period['end'], $mirrorHealthFull);
} catch (\Throwable $e) {
    error_log('coach_production_summary: mirror/coverage error: ' . $e->getMessage());
    cps_fail(500, 'internal error');
}
$mirror = [
    'healthy'   => $mirrorHealthFull['mirror_status']['healthy'],
    'synced_at' => $mirrorHealthFull['mirror_status']['synced_at'],
];

// ── Selected agent OR company-wide summary ───────────────────────────────────

$selectedAgent = null;
$companySummary = null;

try {
    if ($scope === 'agent') {
        $primary = coach_selected_agent_production($pdo, $rosterId, $periodType, $periodOpts, null, $mirrorHealthFull);

        // LTM is shown alongside whatever period is selected -- reuse the
        // primary call's own result if the requested period IS ltm, so we
        // never issue the same lookup twice.
        $ltmResult = $periodType === 'ltm' ? $primary : coach_selected_agent_production($pdo, $rosterId, 'ltm', [], null, $mirrorHealthFull);

        $mtd = coach_mtd_comparison($pdo, $rosterId, null, $mirrorHealthFull);
        $chart = coach_agent_volume_chart($pdo, $rosterId);

        $ltmClosed = $ltmResult['closed'] ?? ['transactions' => null, 'volume' => null, 'sides' => null, 'average_sale_price' => null];
        $ltmExtra  = $ltmResult['ltm_extra'] ?? [];

        $selectedAgent = [
            'roster_id'          => $primary['roster_id'],
            'agent_name'         => $primary['agent_name'],
            'identity_status'    => $primary['identity_status'],
            'production_status'  => $primary['production_status'],
            'closed'             => $primary['closed'],
            'ltm'                => [
                'transactions'      => $ltmClosed['transactions'] ?? null,
                'volume'            => $ltmClosed['volume'] ?? null,
                'sides'             => $ltmClosed['sides'] ?? null,
                'list_sides'        => $ltmExtra['list_side_12mo'] ?? null,
                'buy_sides'         => $ltmExtra['buy_side_12mo'] ?? null,
                'avg_sale_price'    => $ltmClosed['average_sale_price'] ?? null,
                'volume_prior_12mo' => $ltmExtra['volume_prior_12mo'] ?? null,
                'sides_prior_12mo'  => $ltmExtra['sides_prior_12mo'] ?? null,
                'trend_pct'         => $ltmExtra['trend_pct_12mo'] ?? null,
                'last_close_date'   => $ltmExtra['last_close_date'] ?? null,
            ],
            'active_listings'    => $primary['active_listings'],
            'mtd'                => ['current' => $mtd['current'], 'prior_year' => $mtd['prior_year']],
            'chart'              => ['current_12mo' => $chart['current_12mo'], 'prior_12mo' => $chart['prior_12mo']],
            'coverage'           => $primary['coverage'],
            'mirror'             => $mirror,
        ];
    } else {
        // IMPORTANT, PERMANENT: this represents the whole Coastline
        // brokerage/team-member production mirror -- NOT a sum of the
        // `agents` rows below. See coach_company_period_closed()'s own
        // header comment in lib/coach_production.php for the full
        // rationale. `reconciliation` (added below, once $bulk is known)
        // explains the gap without exposing any IDs: some canonical agents
        // with real Coastline production aren't reconciled to an AgentEdge
        // roster row yet, so they contribute to this total but not to any
        // row in `agents`.
        $companyClosed = coach_company_period_closed($pdo, $period['start'], $period['end']);
        $companyMtd    = coach_company_mtd_comparison($pdo);
        $companyChart  = coach_company_volume_chart($pdo);

        $companySummary = [
            'closed' => $companyClosed,
            'mtd'    => ['current' => $companyMtd['current'], 'prior_year' => $companyMtd['prior_year']],
            // Only agent-level active-listing aggregates are mirrored today
            // (coastline_agent_production_stats.active_listing_count/volume),
            // not row-level active-listing records -- summing them company-wide
            // could double-count a co-listed active listing. Until a
            // deduplicated company-level source exists, this is honestly
            // reported as unavailable rather than guessed.
            'active_listings' => [
                'count'  => null,
                'volume' => null,
                'reason' => 'company_active_listing_dedup_unavailable',
            ],
            'chart' => ['current_12mo' => $companyChart['current_12mo'], 'prior_12mo' => $companyChart['prior_12mo']],
        ];
    }

    $bulk = coach_bulk_roster_production($pdo, $mirrorHealthFull);

    if ($coachScoped) {
        // agents[] must never include anyone outside this coach's assigned
        // roster -- filtered here server-side, not merely hidden by the
        // frontend. Roster-size counts recomputed from the filtered set so
        // they don't leak a brokerage-wide number to a Launch Coach either.
        $bulk['agents'] = array_values(array_filter(
            $bulk['agents'],
            fn($a) => in_array(strtolower(trim($a['email'] ?? '')), $assignedEmails, true)
        ));
        $bulk['active_roster_count']  = count($bulk['agents']);
        $bulk['matched_roster_count'] = count(array_filter(
            $bulk['agents'],
            fn($a) => in_array($a['production_status'], ['matched_with_production', 'matched_no_production'], true)
        ));
        $bulk['unmatched_roster_count'] = $bulk['active_roster_count'] - $bulk['matched_roster_count'];
    }

    if ($companySummary !== null) {
        $companySummary['reconciliation'] = [
            'active_roster_count'    => $bulk['active_roster_count'],
            'matched_roster_count'   => $bulk['matched_roster_count'],
            'unmatched_roster_count' => $bulk['unmatched_roster_count'],
        ];
    }

    // Never expose canonical_agent_id to the client -- internal cross-system
    // identifier only, not needed by any current or planned frontend use.
    $publicAgents = array_map(function (array $a): array {
        unset($a['canonical_agent_id']);
        return $a;
    }, $bulk['agents']);
} catch (\Throwable $e) {
    error_log('coach_production_summary: ' . $e->getMessage());
    cps_fail(500, 'internal error');
}

echo json_encode([
    'ok'              => true,
    'scope'           => $scope,
    'period'          => $period,
    'mirror'          => $mirror,
    'coverage'        => $coverage,
    'company_summary' => $companySummary,
    'selected_agent'  => $selectedAgent,
    'agents'          => $publicAgents,
]);
