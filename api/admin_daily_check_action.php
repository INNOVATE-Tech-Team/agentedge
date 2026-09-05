<?php
// Admin Work OS — Daily Check state mutations + fresh load (V1F-A-3/4/5). Owns
// admin_daily_checks only -- work-item status changes still go through the
// existing api/admin_work_item_action.php unchanged; this file never reads
// or writes admin_work_items directly except via daily_check_data()'s
// read-only query. Same owner-scoped/CSRF-protected/admin-only shape as
// that file.
//
// Masquerade is blocked outright (not just "no bypass"): Daily Check is
// never rendered during masquerade (index.php's is_admin() && !is_masquerading()
// gate), so there is no legitimate client path to this endpoint while
// masquerading -- a request that arrives anyway is a crafted one, and
// there's no "correct" identity (real admin's or the masqueraded agent's)
// to act on behalf of, so it's rejected rather than guessed at.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/admin_work_routines.php';
require_once __DIR__ . '/../lib/admin_daily_checks.php';
// 'load' (V1F-A-5) calls daily_check_data() with its default
// includeSchedule=true, which -- for check_type=morning -- reaches
// pcal_get_today_schedule(). sync_completion/dismiss never needed this
// (sync_completion always passes includeSchedule=false), so it was never
// required here before.
require_once __DIR__ . '/../lib/personal_calendar.php';
require_once __DIR__ . '/../lib/feature_flags.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me || !is_admin() || is_masquerading()) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }
if (!feature_enabled_for_current_user('admin_work_os')) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }

$in = json_decode(file_get_contents('php://input'), true) ?: [];

if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($in['csrf'] ?? ''))) {
    http_response_code(403); echo json_encode(['error' => 'invalid csrf token']); exit;
}

$checkType = $in['check_type'] ?? '';
if (!in_array($checkType, ['morning', 'closing'], true)) {
    http_response_code(400); echo json_encode(['error' => 'invalid check_type']); exit;
}

$myEmail = strtolower(trim($me['email'] ?? ''));
$db      = local_db();
$action  = $in['action'] ?? '';

// Always the server's own America/New_York "today" (db.php sets this
// app-wide default) -- the client selects morning/closing but never a
// date, so there is no request shape that can write a historical or
// future check_date row.
$today = date('Y-m-d');

// Ensures a (owner, today, check_type) row exists without disturbing any
// column it doesn't own -- every action below needs the row present before
// its own targeted UPDATE.
function dc_ensure_row(PDO $db, string $owner, string $today, string $type): void {
    $db->prepare(
        "INSERT INTO admin_daily_checks (owner_email, check_date, check_type, created_at, updated_at)
         VALUES (?, ?, ?, datetime('now'), datetime('now'))
         ON CONFLICT(owner_email, check_date, check_type) DO UPDATE SET updated_at = excluded.updated_at"
    )->execute([$owner, $today, $type]);
}

// ── Done for Now / X -- dismiss only, never touches completion state ────────
if ($action === 'dismiss') {
    dc_ensure_row($db, $myEmail, $today, $checkType);
    $db->prepare(
        "UPDATE admin_daily_checks SET dismissed_at = datetime('now'), updated_at = datetime('now')
         WHERE owner_email = ? AND check_date = ? AND check_type = ?"
    )->execute([$myEmail, $today, $checkType]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Recompute completed_at from real work-item statuses (server truth) ──────
if ($action === 'sync_completion') {
    dc_ensure_row($db, $myEmail, $today, $checkType);

    // includeSchedule=false -- this call only needs total/completed, not a
    // calendar re-fetch on every single checkbox click.
    $data       = daily_check_data($db, $myEmail, $today, $checkType, false);
    $total      = $data['total'];
    $completed  = $data['completed'];
    $isComplete = $total > 0 && $completed === $total;

    $stmt = $db->prepare("SELECT completed_at FROM admin_daily_checks WHERE owner_email=? AND check_date=? AND check_type=?");
    $stmt->execute([$myEmail, $today, $checkType]);
    $existingCompletedAt = $stmt->fetchColumn();

    if ($isComplete && !$existingCompletedAt) {
        $db->prepare(
            "UPDATE admin_daily_checks SET completed_at = datetime('now'), updated_at = datetime('now')
             WHERE owner_email=? AND check_date=? AND check_type=?"
        )->execute([$myEmail, $today, $checkType]);
    } elseif (!$isComplete && $existingCompletedAt) {
        // Reopened after being complete -- clear completed_at, but
        // dismissed_at (if set) is deliberately left alone: a checklist the
        // admin already dismissed for the day doesn't need to reappear just
        // because a task was reopened.
        $db->prepare(
            "UPDATE admin_daily_checks SET completed_at = NULL, updated_at = datetime('now')
             WHERE owner_email=? AND check_date=? AND check_type=?"
        )->execute([$myEmail, $today, $checkType]);
    }

    echo json_encode(['ok' => true, 'total' => $total, 'completed' => $completed]);
    exit;
}

// ── Fresh load for automatic opening (V1F-A-5) -- server-truth eligibility +
// (only when eligible) the same data shape the manual preview renders from.
// Never trusts the client's idea of "is it time": eligibility is entirely
// recomputed here from admin_daily_checks state, real work-item status, and
// the server clock (America/New_York, db.php's app-wide default tz). The
// client's only job is deciding when it's reasonable to ask -- this action
// decides the real answer.
if ($action === 'load') {
    $data = daily_check_data($db, $myEmail, $today, $checkType);

    $stmt = $db->prepare("SELECT dismissed_at, completed_at FROM admin_daily_checks WHERE owner_email=? AND check_date=? AND check_type=?");
    $stmt->execute([$myEmail, $today, $checkType]);
    $state = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['dismissed_at' => null, 'completed_at' => null];

    $eligible = daily_check_auto_eligible($checkType, $data, $state);

    $out = ['ok' => true, 'check_type' => $checkType, 'eligible' => $eligible];
    if ($eligible) {
        // Only shipped when actually eligible -- an ineligible auto-check
        // attempt has no reason to hand a client-side script today's task
        // titles/schedule/follow-up count.
        $out['total']           = $data['total'];
        $out['completed_count'] = $data['completed'];
        $out['items']           = array_map(
            fn($it) => ['id' => (int)$it['id'], 'title' => $it['title'], 'status' => $it['status']],
            $data['items']
        );
        $out['schedule']        = $data['schedule']; // morning only; always null for closing
        $out['followup_count']  = $data['followup_count'];
    }
    echo json_encode($out);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown action']);
