<?php
require_once __DIR__ . '/launch_roster.php';

// Data for the agent-facing Launch tab (launch.php). The tab is gated to
// is_super_admin() while its design is reviewed — see nav.php.
//
// The Overview section (cohort start date, status, assigned coach, current
// session number) is wired to real data below, from launch_roster — see
// launch_overview_for_agent(). This Week's title/theme_quote are likewise
// real now, from launch_sessions — see launch_this_week_for_session(). So
// is My Progress's deals-logged count (launch_roster_effective_deals(), same
// helper Launch Coaching uses) and its weekly-conversation figure -- though
// that one is goal-only, not target-vs-actual: weekly_activity (the actual
// side) has zero rows for anyone right now, so joining it would render "0 of
// 20" for every agent indistinguishably from one who's really behind. This
// Week's homework checklist, My Progress's university completion, and all of
// My Plan still come straight from the mock scenarios in
// launch_progress_mock_data() until their own real sources are identified
// (homework needs a new per-agent completion table that doesn't exist yet —
// see launch_this_week_for_session() for why content_md isn't it; university
// completion has a real table but is blocked on an access-control change and
// missing curriculum content — see launch.php). Field names there
// deliberately mirror the real tables they'll eventually read from
// (uni_progress, kpi_definitions) so that wiring each section up later is a
// same-shape change, not a page rewrite. Names/emails in the mock scenarios
// are invented — not drawn from the real launch_roster_seed.php roster — so
// mock data can never be mistaken for an actual agent's record.
//
// Three mock scenarios on purpose: one comfortably on track, one visibly
// falling behind (missed homework, behind on activity and deals), and one
// brand new (week 1, nothing logged yet) — the spread this page needs to
// prove it renders more than the happy path.
//
// launch_roster vs. cohorts/cohort_members: AgentEdge has two independent,
// unlinked tables that both track a Launch agent's start date/coach/status
// (launch_roster — Launch Coaching/Launch Schedule; cohorts/cohort_members —
// the live weekly-KPI/leaderboard/coach-escalation system). Confirmed with
// the team on 2026-08-13 that launch_roster is authoritative for the
// Overview section specifically. If a future section needs weekly KPI data,
// that lives in cohort_members/weekly_activity instead — don't assume the
// two tables agree with each other.
function launch_progress_mock_data(): array {
    return [
        'taylor.brooks@example.com' => [
            'agent_name' => 'Taylor Brooks',
            'cohort' => [
                'name'       => 'August 2026 Cohort',
                'start_date' => '2026-08-10',
                'status'     => 'active',
            ],
            'coach' => [
                'name'  => 'Priya Chandra',
                'email' => 'priya.chandra@example.com',
                'phone' => '555-201-4488',
            ],
            'current_session_number' => 3,
            'total_sessions'         => 8,
            'this_week' => [
                'session_number' => 3,
                'homework'       => [
                    ['label' => 'Complete 20 prospecting calls', 'done' => true],
                    ['label' => 'Log calls on the Program Scorecard', 'done' => true],
                    ['label' => 'Submit Big Why worksheet', 'done' => false],
                ],
            ],
            'progress' => [
                'university_pct' => 62,
                'deals_logged'   => 1,
                'deals_goal'     => 3,
                'weekly_activity' => ['target' => 20, 'actual' => 22, 'unit' => 'conversations'],
            ],
            'plan' => [
                'income_goal'                => 75000,
                'income_goal_transactions'   => 15,
                'weekly_conversation_target' => 20,
                'coach_contact' => [
                    'name'  => 'Priya Chandra',
                    'email' => 'priya.chandra@example.com',
                    'phone' => '555-201-4488',
                ],
            ],
        ],

        'jamie.ortiz@example.com' => [
            'agent_name' => 'Jamie Ortiz',
            'cohort' => [
                'name'       => 'June 2026 Cohort',
                'start_date' => '2026-06-15',
                'status'     => 'active',
            ],
            'coach' => [
                'name'  => 'Marcus Webb',
                'email' => 'marcus.webb@example.com',
                'phone' => '555-330-7712',
            ],
            'current_session_number' => 6,
            'total_sessions'         => 8,
            'this_week' => [
                'session_number' => 6,
                'homework'       => [
                    ['label' => 'Complete 20 prospecting calls', 'done' => false],
                    ['label' => 'Log calls on the Program Scorecard', 'done' => false],
                    ['label' => 'Shadow one contract review', 'done' => true],
                ],
            ],
            'progress' => [
                'university_pct' => 28,
                'deals_logged'   => 0,
                'deals_goal'     => 3,
                'weekly_activity' => ['target' => 20, 'actual' => 6, 'unit' => 'conversations'],
            ],
            'plan' => [
                'income_goal'                => 60000,
                'income_goal_transactions'   => 12,
                'weekly_conversation_target' => 20,
                'coach_contact' => [
                    'name'  => 'Marcus Webb',
                    'email' => 'marcus.webb@example.com',
                    'phone' => '555-330-7712',
                ],
            ],
        ],

        'morgan.reyes@example.com' => [
            'agent_name' => 'Morgan Reyes',
            'cohort' => [
                'name'       => 'August 2026 Cohort',
                'start_date' => '2026-08-10',
                'status'     => 'active',
            ],
            'coach' => [
                'name'  => 'Priya Chandra',
                'email' => 'priya.chandra@example.com',
                'phone' => '555-201-4488',
            ],
            'current_session_number' => 1,
            'total_sessions'         => 8,
            'this_week' => [
                'session_number' => 1,
                'homework'       => [
                    ['label' => 'Complete Business Foundations Wheel', 'done' => false],
                    ['label' => 'Sign Agent Pledge & Commitment', 'done' => false],
                ],
            ],
            'progress' => [
                'university_pct' => 4,
                'deals_logged'   => 0,
                'deals_goal'     => 3,
                'weekly_activity' => ['target' => 20, 'actual' => 0, 'unit' => 'conversations'],
            ],
            'plan' => [
                'income_goal'                => 50000,
                'income_goal_transactions'   => 10,
                'weekly_conversation_target' => 20,
                'coach_contact' => [
                    'name'  => 'Priya Chandra',
                    'email' => 'priya.chandra@example.com',
                    'phone' => '555-201-4488',
                ],
            ],
        ],
    ];
}

// Coach-name fallback wherever launch_roster has no coach assigned yet
// (either the row's own 'coach' column is blank, or there's no row at all)
// — shown instead of a blank or broken field.
const LAUNCH_COACH_UNASSIGNED = 'Not yet assigned';

// Sessions-per-week cadence used to derive "current session number" from a
// launch_roster start date. LAUNCH runs at this cadence today (moved from
// 1x/week to 2x/week for the Aug 10, 2026 cohort onward, per
// lib/launch_roster_seed.php's import notes) — change this one constant if
// the cadence changes again. Deliberately doesn't model the older 1x/week
// cadence for agents who started under it; there's no stored per-agent
// cadence to derive that from, only this single current-day value.
const LAUNCH_SESSIONS_PER_WEEK = 2;

// The date LAUNCH's cadence changed to LAUNCH_SESSIONS_PER_WEEK. Anyone who
// started before this ran under the old 1x/week cadence instead.
const LAUNCH_CADENCE_CUTOFF_DATE = '2026-08-10';

// Pure cadence math, no DB access — how far into the curriculum an agent is
// given their start date and the curriculum's total session count. Kept
// separate from launch_overview_for_agent() so this one calculation can be
// adjusted (or swapped for a real per-cohort cadence lookup) in one place.
// Returns null if there's no start date yet, the agent hasn't started (start
// date is in the future), or the agent started before
// LAUNCH_CADENCE_CUTOFF_DATE — for pre-cutoff agents, applying today's
// LAUNCH_SESSIONS_PER_WEEK would silently compute a wrong week number (not
// just a missing one), since there's no stored per-agent cadence for the
// older 1x/week period to compute correctly with. Same honest-null fallback
// as "no launch_roster row at all".
function launch_current_session_number(string $startDate, int $totalSessions): ?int {
    if ($startDate === '') return null;
    $start = strtotime($startDate);
    if ($start === false) return null;
    $today = strtotime('today');
    if ($today < $start) return null;
    if ($start < strtotime(LAUNCH_CADENCE_CUTOFF_DATE)) return null;
    $weeksElapsed  = floor(($today - $start) / (7 * 86400));
    $sessionNumber = (int)floor($weeksElapsed * LAUNCH_SESSIONS_PER_WEEK) + 1;
    return max(1, min($totalSessions, $sessionNumber));
}

// Total session count in the curriculum (currently 8, but read live rather
// than hardcoded so this doesn't go stale if launch_sessions grows).
function launch_total_sessions(PDO $db): int {
    return (int)($db->query("SELECT MAX(session_number) FROM launch_sessions")->fetchColumn() ?: 8);
}

// Real Overview data for one agent from launch_roster (the authoritative
// source — see the file header), or null if they have no roster record at
// all yet. launch_roster stores the coach as a free-text name only (no
// linked email/phone, unlike cohort_members.coach_email), and has no
// cohort/class *name* concept, only a start date — both left blank/empty
// here rather than invented.
function launch_overview_for_agent(PDO $db, string $email): ?array {
    $email = strtolower(trim($email));
    if ($email === '') return null;
    // Same row shape as launch_roster_fetch_all() (lib/launch_roster.php) --
    // logged_deals/darwin_deals joined in so launch_roster_effective_deals()
    // can resolve the same deals_override > logged > Darwin priority Launch
    // Coaching uses, off this one fetch rather than a second query.
    $st = $db->prepare("
        SELECT lr.*, dsv.ytd_transaction_count AS darwin_deals,
               (SELECT COUNT(*) FROM launch_roster_deals lrd WHERE lrd.roster_id = lr.id) AS logged_deals
        FROM launch_roster lr
        LEFT JOIN darwin_sales_volume dsv ON dsv.agent_person_id = lr.darwin_agent_person_id
        WHERE LOWER(lr.agent_email)=LOWER(?)
        ORDER BY lr.id DESC LIMIT 1
    ");
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    $totalSessions = launch_total_sessions($db);
    return [
        'cohort' => [
            'name'       => '',
            'start_date' => $row['start_date'],
            'status'     => $row['status'],
        ],
        'coach' => [
            'name'  => $row['coach'] !== '' ? $row['coach'] : LAUNCH_COACH_UNASSIGNED,
            'email' => '',
            'phone' => '',
        ],
        'current_session_number' => launch_current_session_number($row['start_date'], $totalSessions),
        'total_sessions'         => $totalSessions,
        'effective_deals'        => launch_roster_effective_deals($row),
    ];
}

// The weekly Launch KPI target for conversations, from kpi_definitions --
// program-wide, not per-agent (see the file header for why weekly_activity,
// the actual side, isn't joined here too).
function launch_weekly_conversation_target(PDO $db): ?int {
    $st = $db->prepare("SELECT weekly_target FROM kpi_definitions WHERE program='launch' AND kpi_key='conversations' AND active=1 LIMIT 1");
    $st->execute();
    $v = $st->fetchColumn();
    return $v !== false ? (int)$v : null;
}

// Real title/theme_quote for a given session number, from launch_sessions
// (the facilitator curriculum table — see launch_curriculum.php). Returns
// empty strings, not null, for "no session number" or "no matching row" so
// callers can treat both the same way Overview treats a missing current
// session (h() them straight through, check for '' to show a fallback).
// Deliberately doesn't touch launch_sessions.content_md for homework text --
// that column is the facilitator/coaching-staff doc (attendance sign-off,
// compliance scripts, internal notes) and isn't meant for agent eyes, so
// there's no safe way to show "the homework" without also parsing out and
// hiding everything else in it. Homework stays mock/placeholder until a
// real per-agent source exists.
function launch_this_week_for_session(PDO $db, ?int $sessionNumber): array {
    if ($sessionNumber === null) return ['title' => '', 'theme_quote' => ''];
    $st = $db->prepare("SELECT title, theme_quote FROM launch_sessions WHERE session_number = ?");
    $st->execute([$sessionNumber]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row
        ? ['title' => $row['title'], 'theme_quote' => $row['theme_quote']]
        : ['title' => '', 'theme_quote' => ''];
}

// "This agent's current Launch progress, or null if there's nothing to show
// at all." This Week / My Progress / My Plan (and agent_name) still come
// from whichever mock scenario matches the email, falling back to the first
// sample so there's always something to render — the tab is currently
// is_super_admin()-gated, so a super admin previewing it is never themself
// an actual mock scenario or a real Launch participant.
//
// Overview (cohort/coach/session fields) is then overlaid with the real
// launch_roster lookup above. A real agent with no roster record yet gets
// honest "not yet" placeholders there instead of borrowing another agent's
// mock Overview — distinct from the mock-fallback behavior for the other
// three sections.
function launch_progress_for_agent(string $email): ?array {
    $all   = launch_progress_mock_data();
    $email = strtolower(trim($email));
    $base  = $all[$email] ?? (reset($all) ?: null);
    if ($base === null) return null;

    $db       = local_db();
    $overview = launch_overview_for_agent($db, $email);
    if ($overview === null) {
        $overview = [
            'cohort' => ['name' => '', 'start_date' => '', 'status' => 'not_enrolled'],
            'coach'  => ['name' => LAUNCH_COACH_UNASSIGNED, 'email' => '', 'phone' => ''],
            'current_session_number' => null,
            'total_sessions'         => launch_total_sessions($db),
            'effective_deals'        => null,
        ];
    }

    // This Week's title/theme_quote are keyed off the agent's *real* current
    // session number, never the mock scenario's own 'session_number' -- $base
    // may be a different agent's mock scenario entirely (the reset($all)
    // fallback above), so its session_number would join to the wrong week.
    $base['this_week'] = array_merge(
        $base['this_week'],
        ['session_number' => $overview['current_session_number']],
        launch_this_week_for_session($db, $overview['current_session_number'])
    );

    // Deals logged (real, toward the hardcoded 3-deal graduation mark -- see
    // lib/launch_roster.php) and the weekly conversation goal (real, from
    // kpi_definitions) overwrite the mock progress numbers. university_pct
    // stays mock -- see the file header for why.
    $base['progress']['deals_logged'] = $overview['effective_deals'];
    $base['progress']['weekly_activity']['target'] = launch_weekly_conversation_target($db) ?? $base['progress']['weekly_activity']['target'];
    unset($overview['effective_deals']); // folded into $base['progress'] above -- not a top-level field

    // array_merge() overwrites by key regardless of value -- 'current_session_number'
    // always exists in $overview (see above), including as an explicit null, so a
    // null here is never masked back to $base's mock number.
    return array_merge($base, $overview);
}
